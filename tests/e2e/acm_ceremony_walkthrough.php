<?php
/**
 * ACM S3 — ceremony walkthrough against a real MariaDB.
 * ------------------------------------------------------------
 * Two-party E2E (officer console actions + stub app client) through
 * the REAL services: approve → issue → verify → credentials, plus
 * every failure path (reuse, reissue, cross-purpose, grant replay,
 * burn, expiry, account_exists, cancel, idempotency conflict,
 * network gate). Expiry is clock-simulated (UPDATE expires_at to
 * the past) — the lazy-expiry branches are real, only the wait is
 * skipped.
 *
 * COMMAND LINE ONLY, and destructive: it seeds officers, members,
 * persons and tickets, then deletes them again. Disposable database
 * only (destructive_guard interlock), never production.
 *
 * Usage (dev machine, migrations through 082 applied):
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/acm_ceremony_walkthrough.php
 *
 * NOT run in CI (no database in the sandbox); the sandbox pins the
 * same behaviors statically (tests/security/test_acm_s3_ceremony.py)
 * and the pure units run in PHPUnit (acm/tests/CeremonyTest.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$envFile = $root . '/.fkss_env.php';
if (!is_file($envFile)) {
    fwrite(STDERR, "missing .fkss_env.php\n");
    exit(3);
}
require $envFile;
require __DIR__ . '/destructive_guard.php';
require_once $root . '/acm/autoload.php';

use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationException;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\CodePurpose;
use SSMS\Acm\Contracts\IssuedCode;
use SSMS\Acm\Contracts\RequestMeta;
use SSMS\Acm\Identity\AcmPersonService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'acm_ceremony_walkthrough');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

try {
    $pdo->query('SELECT 1 FROM acm_activation_codes LIMIT 1');
} catch (PDOException $e) {
    fwrite(STDERR, "migration 082 is not applied to {$dbName}\n");
    exit(3);
}

$failures = [];
$checks = 0;

function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected !== $actual) {
        $failures[] = sprintf(
            "%s\n      expected: %s\n      actual:   %s",
            $label,
            json_encode($expected),
            json_encode($actual)
        );
        echo "  FAIL  {$label}\n";
    } else {
        echo "  ok    {$label}\n";
    }
}

/** Assert $fn throws ActivationException with machine code $code. */
function expectCode(string $label, string $code, callable $fn): void
{
    global $failures, $checks;
    $checks++;
    try {
        $fn();
    } catch (ActivationException $e) {
        if ($e->machineCode() !== $code) {
            $failures[] = "{$label}\n      expected code: {$code}\n      actual code:   {$e->machineCode()}";
            echo "  FAIL  {$label}\n";
        } else {
            echo "  ok    {$label}\n";
        }
        return;
    } catch (Throwable $e) {
        $failures[] = "{$label}\n      expected code: {$code}\n      actual throw:  " . get_class($e) . ': ' . $e->getMessage();
        echo "  FAIL  {$label}\n";
        return;
    }
    $failures[] = "{$label}\n      expected code: {$code}\n      actual: no exception";
    echo "  FAIL  {$label}\n";
}

$persons = new AcmPersonService($pdo);
$tickets = new AcmTicketService($pdo, $persons);
$ceremony = new ActivationService($pdo, $tickets, $persons);

// ── Seeded world ────────────────────────────────────────────────────
$tag = 'e2e' . substr(md5(microtime(true) . getmypid()), 0, 8);
$seedUserIds = [];
$seedPersonIds = [];
$seedTicketIds = [];
$seedMemberIds = [];
$seedGuardianIds = [];
$seedSelectors = [];

function seedUser(PDO $pdo, string $username, string $role): int
{
    global $seedUserIds;
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, full_name, role, password_hash, is_active)'
        . ' VALUES (:u, :fn, :r, :ph, 1)'
    );
    $stmt->execute([
        ':u' => $username, ':fn' => 'E2E ' . $username, ':r' => $role,
        ':ph' => password_hash('e2e-unused-' . $username, PASSWORD_DEFAULT),
    ]);
    $id = (int)$pdo->lastInsertId();
    $seedUserIds[] = $id;
    return $id;
}

$officer1 = seedUser($pdo, $tag . '_off1', 'accounts_office');
$officer2 = seedUser($pdo, $tag . '_off2', 'accounts_office');
$officerCtx = new AccountContext($officer1, 'accounts_office');

$memberStmt = $pdo->prepare(
    'INSERT INTO members (full_name_am, student_name, father_name) VALUES (:am, :st, :fa)'
);
$guardianStmt = $pdo->prepare('INSERT INTO guardians (display_name) VALUES (:n)');
$personNo = 0;

function seedTicket(string $label): array
{
    global $pdo, $persons, $tickets, $officer1, $officer2, $memberStmt,
        $guardianStmt, $tag, $personNo, $seedMemberIds, $seedGuardianIds,
        $seedPersonIds, $seedTicketIds;
    $personNo++;
    $memberStmt->execute([
        ':am' => 'E2E ሙከራ ' . $tag . ' ' . $personNo,
        ':st' => 'E2E Test ' . $tag . ' ' . $personNo,
        ':fa' => 'E2E Father',
    ]);
    $memberId = (int)$pdo->lastInsertId();
    $seedMemberIds[] = $memberId;
    $guardianStmt->execute([':n' => 'E2E Guardian ' . $tag . ' ' . $personNo]);
    $guardianId = (int)$pdo->lastInsertId();
    $seedGuardianIds[] = $guardianId;
    $person = $persons->create([
        'full_name_am' => 'E2E ሙከራ ' . $tag . ' ' . $personNo,
        'phone' => '0911' . str_pad((string)(100000 + $personNo), 6, '0', STR_PAD_LEFT),
        'member_id' => $memberId,
        'guardian_id' => $guardianId,
    ], $officer1);
    $seedPersonIds[] = (int)$person['id'];
    $ticket = $tickets->create([
        'person_id' => (int)$person['id'],
        'account_type' => 'member',
        'member_id' => $memberId,
    ], $officer1);
    $seedTicketIds[] = (int)$ticket['id'];
    // Separated mode, two officers, decider differs from submitter.
    $decided = $tickets->decide(
        (int)$ticket['id'], $officer2, 'approve', '', false,
        ['mode' => 'separated', 'active_officers' => 2]
    );
    check($label . ': approve lands on approved', 'approved', $decided['status']);
    return $decided;
}

function trackCode(IssuedCode $code): IssuedCode
{
    global $seedSelectors;
    $seedSelectors[] = $code->selector;
    return $code;
}

function ticketStatus(int $id): string
{
    global $tickets;
    return (string)$tickets->get($id)['status'];
}

function codeRow(string $selector): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM acm_activation_codes WHERE selector = :s LIMIT 1');
    $stmt->execute([':s' => $selector]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

$meta = new RequestMeta('127.0.0.1');
$password = 'Violet River 7 Kettle Drum!';

// ── S1: happy path + idempotency replay/conflict ────────────────────
echo "S1 happy path\n";
$t1 = seedTicket('S1');
$c1 = trackCode($ceremony->issueCode($officerCtx, (string)$t1['id'], CodePurpose::Activation));
check('S1: issue moves ticket', 'code_issued', ticketStatus((int)$t1['id']));
check('S1: display shows once', 'A-' . $c1->selector . '-' . $c1->secret, $c1->display('A'));
$g1 = $ceremony->verifyCode($c1->selector, $c1->secret, CodePurpose::Activation, $meta);
check('S1: verify moves ticket', 'grant_verified', ticketStatus((int)$t1['id']));
$key1 = AcmPersonService::uuid4();
$a1 = $ceremony->establishCredentials($g1->token, $password, $key1);
check('S1: credentials move ticket', 'account_pending_device', ticketStatus((int)$t1['id']));
$seedUserIds[] = $a1->userId;
$userRow = $pdo->query('SELECT is_active FROM users WHERE id = ' . $a1->userId)->fetch();
check('S1: account inactive until S4', 0, (int)$userRow['is_active']);
// Same key + same grant replays without a duplicate account.
$a1b = $ceremony->establishCredentials($g1->token, $password, $key1);
check('S1: replay returns same user', $a1->userId, $a1b->userId);
check('S1: replay returns same name', $a1->username, $a1b->username);
// Ticket is at account_pending_device: reissue must refuse (S5 covers
// the cross-grant key conflict on a dedicated ticket instead).
expectCode('S1: reissue after credentials refused', 'ticket_state', function () use (
    $ceremony, $officerCtx, $t1
) {
    $ceremony->issueCode($officerCtx, (string)$t1['id'], CodePurpose::Activation);
});

// ── S2: code reuse fails generic ────────────────────────────────────
echo "S2 reuse\n";
$t2 = seedTicket('S2');
$c2 = trackCode($ceremony->issueCode($officerCtx, (string)$t2['id'], CodePurpose::Activation));
$ceremony->verifyCode($c2->selector, $c2->secret, CodePurpose::Activation, $meta);
expectCode('S2: second redemption is generic', 'code_invalid', function () use (
    $ceremony, $c2, $meta
) {
    $ceremony->verifyCode($c2->selector, $c2->secret, CodePurpose::Activation, $meta);
});
check('S2: ticket stays put', 'grant_verified', ticketStatus((int)$t2['id']));

// ── S3: reissue kills the old code ──────────────────────────────────
echo "S3 reissue\n";
$t3 = seedTicket('S3');
$c3a = trackCode($ceremony->issueCode($officerCtx, (string)$t3['id'], CodePurpose::Activation));
$c3b = trackCode($ceremony->issueCode($officerCtx, (string)$t3['id'], CodePurpose::Activation));
check('S3: old code revoked', 'revoked', (string)codeRow($c3a->selector)['status']);
expectCode('S3: old code dead', 'code_invalid', function () use ($ceremony, $c3a, $meta) {
    $ceremony->verifyCode($c3a->selector, $c3a->secret, CodePurpose::Activation, $meta);
});
$g3 = $ceremony->verifyCode($c3b->selector, $c3b->secret, CodePurpose::Activation, $meta);
check('S3: new code works', 'grant_verified', ticketStatus((int)$t3['id']));
// Same idempotency key, different grant → conflict, grant unconsumed.
$key3 = AcmPersonService::uuid4();
$a3 = $ceremony->establishCredentials($g3->token, $password, $key3);
$seedUserIds[] = $a3->userId;
$t3b = seedTicket('S3b');
$c3c = trackCode($ceremony->issueCode($officerCtx, (string)$t3b['id'], CodePurpose::Activation));
$g3c = $ceremony->verifyCode($c3c->selector, $c3c->secret, CodePurpose::Activation, $meta);
expectCode('S3: key reuse across grants conflicts', 'idempotent_conflict', function () use (
    $ceremony, $g3c, $password, $key3
) {
    $ceremony->establishCredentials($g3c->token, $password, $key3);
});
$fp3c = substr(hash('sha256', $g3c->token), 0, 12);
$consumed = $pdo->query(
    "SELECT consumed_at FROM acm_activation_grants WHERE fingerprint = '{$fp3c}' LIMIT 1"
)->fetch();
check('S3: conflicted grant unconsumed', null, $consumed['consumed_at']);

// ── S4: cross-purpose fails generic ─────────────────────────────────
echo "S4 purpose isolation\n";
expectCode('S4: activation code rejects device_add', 'code_invalid', function () use (
    $ceremony, $c3c, $meta
) {
    $ceremony->verifyCode($c3c->selector, $c3c->secret, CodePurpose::DeviceAdd, $meta);
});

// ── S5: grant replay ────────────────────────────────────────────────
echo "S5 grant replay\n";
expectCode('S5: consumed grant replays as consumed', 'grant_consumed', function () use (
    $ceremony, $g3, $password
) {
    $ceremony->establishCredentials($g3->token, $password, AcmPersonService::uuid4());
});

// ── S6: burn budget + per-pair throttle ─────────────────────────────
echo "S6 burn\n";
$t6 = seedTicket('S6');
$c6 = trackCode($ceremony->issueCode($officerCtx, (string)$t6['id'], CodePurpose::Activation));
$wrong = str_repeat('A', 8) === $c6->secret ? 'BBBBBBBB' : 'AAAAAAAA';
// Distributed failures (rotating IPs) spend the code's burn budget;
// a single IP is throttled before it can burn a victim's code (D4.5),
// so each attempt arrives from its own address here.
for ($i = 1; $i <= 5; $i++) {
    $n = $i;
    expectCode("S6: wrong secret #{$n} is generic", 'code_invalid', function () use (
        $ceremony, $c6, $wrong, $n
    ) {
        $ceremony->verifyCode(
            $c6->selector, $wrong, CodePurpose::Activation, new RequestMeta('10.6.0.' . $n)
        );
    });
}
check('S6: code burned', 'revoked', (string)codeRow($c6->selector)['status']);
check('S6: ticket stalled at expired', 'expired', ticketStatus((int)$t6['id']));
expectCode('S6: right secret after burn fails', 'code_invalid', function () use (
    $ceremony, $c6, $meta
) {
    $ceremony->verifyCode($c6->selector, $c6->secret, CodePurpose::Activation, $meta);
});
// Officer reissue resumes the ceremony.
$c6b = trackCode($ceremony->issueCode($officerCtx, (string)$t6['id'], CodePurpose::Activation));
$ceremony->verifyCode($c6b->selector, $c6b->secret, CodePurpose::Activation, $meta);
check('S6: reissue resumes', 'grant_verified', ticketStatus((int)$t6['id']));
// One address hammering one code is throttled before the burn budget.
$t6p = seedTicket('S6p');
$c6p = trackCode($ceremony->issueCode($officerCtx, (string)$t6p['id'], CodePurpose::Activation));
$wrongP = str_repeat('A', 8) === $c6p->secret ? 'BBBBBBBB' : 'AAAAAAAA';
$pairMeta = new RequestMeta('10.6.9.9');
for ($i = 1; $i <= 3; $i++) {
    $n = $i;
    expectCode("S6: pair failure #{$n} is generic", 'code_invalid', function () use (
        $ceremony, $c6p, $wrongP, $pairMeta
    ) {
        $ceremony->verifyCode($c6p->selector, $wrongP, CodePurpose::Activation, $pairMeta);
    });
}
expectCode('S6: pair throttled before burn', 'rate_limited', function () use (
    $ceremony, $c6p, $wrongP, $pairMeta
) {
    $ceremony->verifyCode($c6p->selector, $wrongP, CodePurpose::Activation, $pairMeta);
});
check('S6: throttled code still alive', 'active', (string)codeRow($c6p->selector)['status']);

// ── S7: existing account collides without merge ─────────────────────
echo "S7 account_exists\n";
$t7 = $tickets->create([
    'person_id' => (int)$t1['person_id'],
    'account_type' => 'member',
    'member_id' => (int)$t1['member_id'],
], $officer1);
$seedTicketIds[] = (int)$t7['id'];
$tickets->decide((int)$t7['id'], $officer2, 'approve', '', true,
    ['mode' => 'separated', 'active_officers' => 2]);
$c7 = trackCode($ceremony->issueCode($officerCtx, (string)$t7['id'], CodePurpose::Activation));
$g7 = $ceremony->verifyCode($c7->selector, $c7->secret, CodePurpose::Activation, $meta);
expectCode('S7: duplicate provision blocked', 'account_exists', function () use (
    $ceremony, $g7, $password
) {
    $ceremony->establishCredentials($g7->token, $password, AcmPersonService::uuid4());
});
check('S7: ticket queued for review', 'activation_failed', ticketStatus((int)$t7['id']));
$dupes = (int)$pdo->query(
    'SELECT COUNT(*) FROM acm_person_accounts WHERE person_id = ' . (int)$t1['person_id']
    . " AND account_type = 'member' AND status IN ('pending','active')"
)->fetchColumn();
check('S7: no duplicate account', 1, $dupes);

// ── S8: cancel cascades ─────────────────────────────────────────────
echo "S8 cancel\n";
$t8 = seedTicket('S8');
$c8 = trackCode($ceremony->issueCode($officerCtx, (string)$t8['id'], CodePurpose::Activation));
$tickets->cancel((int)$t8['id'], $officer1, 'E2E: applicant withdrew in person.');
check('S8: ticket canceled', 'canceled', ticketStatus((int)$t8['id']));
check('S8: code revoked', 'revoked', (string)codeRow($c8->selector)['status']);
expectCode('S8: canceled code dead', 'code_invalid', function () use ($ceremony, $c8, $meta) {
    $ceremony->verifyCode($c8->selector, $c8->secret, CodePurpose::Activation, $meta);
});
expectCode('S8: double cancel refused', 'ticket_state', function () use ($tickets, $t8, $officer1) {
    $tickets->cancel((int)$t8['id'], $officer1, 'E2E: second attempt.');
});

// ── S9: clock-simulated expiry ──────────────────────────────────────
echo "S9 expiry\n";
$t9 = seedTicket('S9');
$c9 = trackCode($ceremony->issueCode($officerCtx, (string)$t9['id'], CodePurpose::Activation));
$pdo->prepare('UPDATE acm_activation_codes SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE selector = :s')
    ->execute([':s' => $c9->selector]);
expectCode('S9: expired code is generic', 'code_invalid', function () use ($ceremony, $c9, $meta) {
    $ceremony->verifyCode($c9->selector, $c9->secret, CodePurpose::Activation, $meta);
});
check('S9: code row flipped', 'expired', (string)codeRow($c9->selector)['status']);
check('S9: ticket stalled', 'expired', ticketStatus((int)$t9['id']));
$c9b = trackCode($ceremony->issueCode($officerCtx, (string)$t9['id'], CodePurpose::Activation));
$g9 = $ceremony->verifyCode($c9b->selector, $c9b->secret, CodePurpose::Activation, $meta);
$fp9 = substr(hash('sha256', $g9->token), 0, 12);
$pdo->prepare('UPDATE acm_activation_grants SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE fingerprint = :fp')
    ->execute([':fp' => $fp9]);
expectCode('S9: expired grant is specific', 'grant_expired', function () use (
    $ceremony, $g9, $password
) {
    $ceremony->establishCredentials($g9->token, $password, AcmPersonService::uuid4());
});

// ── S10: office-network gate ────────────────────────────────────────
echo "S10 network gate\n";
$t10 = seedTicket('S10');
$c10 = trackCode($ceremony->issueCode($officerCtx, (string)$t10['id'], CodePurpose::Activation));
$flagBefore = $pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'acm.office_network_enforced' LIMIT 1"
)->fetchColumn();
try {
    $pdo->exec("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'acm.office_network_enforced'");
    expectCode('S10: unknown network denied when enforced', 'network_denied', function () use (
        $ceremony, $c10
    ) {
        // No allowlist rows + unlisted IP: fail closed.
        $ceremony->verifyCode($c10->selector, $c10->secret, CodePurpose::Activation, new RequestMeta('203.0.113.9'));
    });
} finally {
    $pdo->prepare('UPDATE system_settings SET setting_value = :v WHERE setting_key = :k')
        ->execute([':v' => $flagBefore === false ? '0' : (string)$flagBefore, ':k' => 'acm.office_network_enforced']);
}
$g10 = $ceremony->verifyCode($c10->selector, $c10->secret, CodePurpose::Activation, $meta);
check('S10: gate off again, code works', 'grant_verified', ticketStatus((int)$t10['id']));

// ── Cleanup (best-effort, children first) ───────────────────────────
echo "cleanup\n";
try {
    $tIds = implode(',', array_map('intval', $seedTicketIds));
    $uIds = implode(',', array_map('intval', $seedUserIds));
    $pIds = implode(',', array_map('intval', $seedPersonIds));
    if ($seedSelectors !== []) {
        $sel = implode(',', array_map(function ($s) use ($pdo) {
            return $pdo->quote($s);
        }, $seedSelectors));
        $pdo->exec("DELETE FROM acm_activation_attempts WHERE selector IN ({$sel})");
    }
    if ($tIds !== '') {
        $pdo->exec("DELETE FROM acm_activation_grants WHERE ticket_id IN ({$tIds})");
        $pdo->exec("DELETE FROM acm_activation_codes WHERE ticket_id IN ({$tIds})");
        $pdo->exec("DELETE FROM acm_ticket_events WHERE ticket_id IN ({$tIds})");
        $pdo->exec("DELETE FROM acm_activation_tickets WHERE id IN ({$tIds})");
    }
    if ($uIds !== '') {
        $pdo->exec("DELETE FROM acm_idempotency_keys WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM acm_person_accounts WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM users WHERE id IN ({$uIds})");
    }
    if ($pIds !== '') {
        $pdo->exec("DELETE FROM acm_persons WHERE id IN ({$pIds})");
    }
    if ($seedMemberIds !== []) {
        $pdo->exec('DELETE FROM members WHERE id IN (' . implode(',', array_map('intval', $seedMemberIds)) . ')');
    }
    if ($seedGuardianIds !== []) {
        $pdo->exec('DELETE FROM guardians WHERE id IN (' . implode(',', array_map('intval', $seedGuardianIds)) . ')');
    }
    echo "  ok    seeded rows removed\n";
} catch (Throwable $e) {
    $failures[] = 'cleanup: ' . $e->getMessage();
    echo "  FAIL  cleanup: {$e->getMessage()}\n";
}

echo "\n{$checks} checks, " . count($failures) . " failures\n";
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . "\n");
    }
    exit(1);
}
echo "WALKTHROUGH GREEN\n";
