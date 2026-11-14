<?php
/**
 * ACM S6 — credential-recovery walkthrough against a real MariaDB.
 * ---------------------------------------------------------------
 * E2E through the real services: officer-issued R-code, reset
 * verify, grant + new password + idempotency key, then proof that
 * the password changed, ALL sessions died, the ticket closed with
 * a `recovered` event, weak/mismatched passwords never burn a
 * grant, double-submits converge, wrong-operation grants refuse
 * in BOTH directions, pending/deactivated accounts refuse, and
 * crash-continuation (consumed grant + idem row, no event, grant
 * even expired) still completes -- then replays.
 *
 * COMMAND LINE ONLY, and destructive: it seeds officers, members,
 * persons, tickets, users and refresh sessions, then deletes them
 * again. Disposable database only (destructive_guard interlock),
 * never production.
 *
 * Usage (dev machine, migrations through 088 applied):
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/acm_recovery_walkthrough.php
 *
 * NOT run in CI (no database in the sandbox); the sandbox pins the
 * same behaviors statically (tests/security/test_acm_s6_recovery.py).
 * S6 adds no PHPUnit file: every path is DB-backed (stated, not hidden).
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
require_once $root . '/admin/backend/services/ProfileService.php';
require_once $root . '/admin/backend/services/AccountCredentialService.php';
require_once $root . '/admin/backend/services/RefreshTokenService.php';
require_once $root . '/acm/autoload.php';

use App\Services\AccountCredentialService;
use App\Services\AuthorizedAdministratorIdentity;
use App\Services\MysqliCredentialRepository;
use App\Services\RefreshTokenService;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationException;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\CodePurpose;
use SSMS\Acm\Contracts\IssuedCode;
use SSMS\Acm\Contracts\RequestMeta;
use SSMS\Acm\Identity\AcmPersonService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'acm_recovery_walkthrough');

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
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "mysqli connect failed: {$mysqli->connect_error}\n");
    exit(3);
}

try {
    $eventType = (string)$pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acm_ticket_events'"
        . " AND COLUMN_NAME = 'event' LIMIT 1"
    )->fetchColumn();
} catch (PDOException $e) {
    fwrite(STDERR, "ticket events table is missing in {$dbName}\n");
    exit(3);
}
if (strpos($eventType, "'recovered'") === false) {
    fwrite(STDERR, "migration 088 is not applied to {$dbName}\n");
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

/** Assert $fn throws a RuntimeException (fail-closed, never half-done). */
function expectRuntime(string $label, callable $fn): void
{
    global $failures, $checks;
    $checks++;
    try {
        $fn();
    } catch (RuntimeException $e) {
        echo "  ok    {$label}\n";
        return;
    } catch (Throwable $e) {
        $failures[] = "{$label}\n      expected RuntimeException\n      actual throw:  " . get_class($e) . ': ' . $e->getMessage();
        echo "  FAIL  {$label}\n";
        return;
    }
    $failures[] = "{$label}\n      expected RuntimeException\n      actual: no exception";
    echo "  FAIL  {$label}\n";
}

$persons = new AcmPersonService($pdo);
$tickets = new AcmTicketService($pdo, $persons);

// The route's resetter, verbatim shape: shared administrator
// primitive, officer identity from the grant chain.
$resetter = static function (int $officerId, int $userId, string $newPassword) use ($mysqli): void {
    $credentials = new AccountCredentialService(new MysqliCredentialRepository($mysqli));
    $credentials->resetPasswordByAdministrator(
        AuthorizedAdministratorIdentity::fromTrustedAuthorization($officerId),
        $userId,
        $newPassword,
        $newPassword
    );
};
$ceremony = new ActivationService($pdo, $tickets, $persons, $resetter);
$noResetter = new ActivationService($pdo, $tickets, $persons);

// Refresh issuer with a capturing test signer (S5 shape).
$capturedPayloads = [];
$refresh = new RefreshTokenService(
    $mysqli,
    function (
        int $uid, string $username, string $role, string $fullName,
        int $authzVersion, string $sessionId, string $familyId, int $expiresAt
    ) use (&$capturedPayloads): string {
        $token = 'e2e-' . bin2hex(random_bytes(16));
        $capturedPayloads[$token] = ['jti' => $sessionId, 'fid' => $familyId, 'uid' => $uid];
        return $token;
    },
    3600
);

// ── Seeded world ────────────────────────────────────────────────────
$tag = 'e2e' . substr(md5(microtime(true) . getmypid()), 0, 8);
$seedUserIds = [];
$seedPersonIds = [];
$seedTicketIds = [];
$seedMemberIds = [];
$seedGuardianIds = [];
$seedSelectors = [];

function seedUser(PDO $pdo, string $username, string $role, bool $active = true): int
{
    global $seedUserIds;
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, full_name, role, password_hash, is_active)'
        . ' VALUES (:u, :fn, :r, :ph, :a)'
    );
    $stmt->execute([
        ':u' => $username, ':fn' => 'E2E ' . $username, ':r' => $role,
        ':ph' => password_hash('e2e-old-password', PASSWORD_DEFAULT),
        ':a' => $active ? 1 : 0,
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

/** Approved ticket for a fresh person (S3 seedTicket shape). */
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
    $decided = $tickets->decide(
        (int)$ticket['id'], $officer2, 'approve', '', false,
        ['mode' => 'separated', 'active_officers' => 2]
    );
    check($label . ': approve lands on approved', 'approved', $decided['status']);
    return $decided;
}

/**
 * Post-S4 account state: live users row + ACTIVE person link.
 * (S6's precondition is an active account; running the full S4
 * device ceremony here would test S4, not S6.)
 */
function seedActiveAccount(string $username, int $personId, int $linkedBy): int
{
    global $pdo;
    $userId = seedUser($pdo, $username, 'member', true);
    $stmt = $pdo->prepare(
        'INSERT INTO acm_person_accounts (person_id, user_id, account_type, status, linked_by, linked_at)'
        . " VALUES (:p, :u, 'member', 'active', :b, NOW())"
    );
    $stmt->execute([':p' => $personId, ':u' => $userId, ':b' => $linkedBy]);
    return $userId;
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

function grantRow(string $token): ?array
{
    global $pdo;
    $fp = substr(hash('sha256', $token), 0, 12);
    $stmt = $pdo->prepare('SELECT * FROM acm_activation_grants WHERE fingerprint = :f LIMIT 1');
    $stmt->execute([':f' => $fp]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function recoveredEvents(int $ticketId): array
{
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT meta FROM acm_ticket_events WHERE ticket_id = :t AND event = 'recovered'"
    );
    $stmt->execute([':t' => $ticketId]);
    return $stmt->fetchAll();
}

function liveSessions(int $userId): int
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM api_refresh_sessions'
        . ' WHERE user_id = :u AND revoked_at IS NULL AND consumed_at IS NULL AND expires_at > NOW()'
    );
    $stmt->execute([':u' => $userId]);
    return (int)$stmt->fetchColumn();
}

$meta = new RequestMeta('127.0.0.1');
$newPassword = 'Juniper Field 9 Harbor Light!';

// ── R1: full recovery ───────────────────────────────────────────────
echo "R1 full recovery\n";
$t1 = seedTicket('R1');
$u1 = seedActiveAccount($tag . '_u1', (int)$t1['person_id'], $officer1);
$userRow1 = ['id' => $u1, 'username' => $tag . '_u1', 'role' => 'member', 'full_name' => 'E2E'];
$tok1 = $refresh->issue($userRow1, '127.0.0.1', 'e2e');
$pay1 = $capturedPayloads[$tok1];
check('R1: session live before recovery', true, liveSessions($u1) > 0);
$c1 = trackCode($ceremony->issueCode($officerCtx, (string)$t1['id'], CodePurpose::Reset));
check('R1: R-code display prefix', 'R-' . $c1->selector . '-' . $c1->secret, $c1->display('R'));
$g1 = $ceremony->verifyCode($c1->selector, $c1->secret, CodePurpose::Reset, $meta);
check('R1: grant operation is reset', 'reset', $g1->operation->value);
check('R1: verify moves ticket', 'grant_verified', ticketStatus((int)$t1['id']));
$key1 = AcmPersonService::uuid4();
$r1 = $ceremony->completeRecovery($g1->token, $newPassword, $newPassword, $key1);
check('R1: returns user', $u1, $r1['user_id']);
check('R1: returns ticket', (int)$t1['id'], $r1['ticket_id']);
check('R1: officer is the issuer', $officer1, $r1['officer_id']);
check('R1: not a replay', false, $r1['replayed']);
$hash1 = (string)$pdo->query('SELECT password_hash FROM users WHERE id = ' . $u1)->fetchColumn();
check('R1: new password verifies', true, password_verify($newPassword, $hash1));
check('R1: old password dead', false, password_verify('e2e-old-password', $hash1));
check('R1: all sessions revoked', 0, liveSessions($u1));
$rot1 = $refresh->rotate($tok1, $pay1, '127.0.0.1', 'e2e');
check('R1: old refresh token refused', 'revoked', $rot1['state']);
check('R1: ticket back to active', 'active', ticketStatus((int)$t1['id']));
$ev1 = recoveredEvents((int)$t1['id']);
check('R1: exactly one recovered event', 1, count($ev1));
check(
    'R1: event carries the grant fingerprint',
    true,
    strpos((string)$ev1[0]['meta'], substr(hash('sha256', $g1->token), 0, 6) . '...') !== false
);
// Same key + same grant replays identically (no second audit, no second event).
$r1b = $ceremony->completeRecovery($g1->token, $newPassword, $newPassword, $key1);
check('R1: replay returns same user', $u1, $r1b['user_id']);
check('R1: replay is flagged', true, $r1b['replayed']);
check('R1: still one recovered event', 1, count(recoveredEvents((int)$t1['id'])));

// ── R2: weak password + mismatch never burn the grant ───────────────
echo "R2 grant survives bad passwords\n";
$t2 = seedTicket('R2');
$u2 = seedActiveAccount($tag . '_u2', (int)$t2['person_id'], $officer1);
$c2 = trackCode($ceremony->issueCode($officerCtx, (string)$t2['id'], CodePurpose::Reset));
$g2 = $ceremony->verifyCode($c2->selector, $c2->secret, CodePurpose::Reset, $meta);
$key2 = AcmPersonService::uuid4();
expectCode('R2: weak password refused', 'weak_password', function () use ($ceremony, $g2, $key2) {
    $ceremony->completeRecovery($g2->token, 'abc', 'abc', $key2);
});
expectCode('R2: mismatch refused', 'invalid_request', function () use (
    $ceremony, $g2, $key2, $newPassword
) {
    $ceremony->completeRecovery($g2->token, $newPassword, $newPassword . 'x', $key2);
});
check('R2: grant unconsumed after both', null, grantRow($g2->token)['consumed_at']);
$r2 = $ceremony->completeRecovery($g2->token, $newPassword, $newPassword, $key2);
check('R2: retry with strong password succeeds', $u2, $r2['user_id']);
check('R2: ticket closed', 'active', ticketStatus((int)$t2['id']));

// ── R3: expired / consumed / revoked grants refuse ──────────────────
echo "R3 dead grants refuse\n";
$t3 = seedTicket('R3');
$u3 = seedActiveAccount($tag . '_u3', (int)$t3['person_id'], $officer1);
$c3 = trackCode($ceremony->issueCode($officerCtx, (string)$t3['id'], CodePurpose::Reset));
$g3 = $ceremony->verifyCode($c3->selector, $c3->secret, CodePurpose::Reset, $meta);
$pdo->exec(
    'UPDATE acm_activation_grants SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
    . " WHERE fingerprint = '" . substr(hash('sha256', $g3->token), 0, 12) . "'"
);
expectCode('R3: expired grant refused', 'grant_expired', function () use (
    $ceremony, $g3, $newPassword
) {
    $ceremony->completeRecovery($g3->token, $newPassword, $newPassword, AcmPersonService::uuid4());
});
$t3b = seedTicket('R3b');
seedActiveAccount($tag . '_u3b', (int)$t3b['person_id'], $officer1);
$c3b = trackCode($ceremony->issueCode($officerCtx, (string)$t3b['id'], CodePurpose::Reset));
$g3b = $ceremony->verifyCode($c3b->selector, $c3b->secret, CodePurpose::Reset, $meta);
$ceremony->completeRecovery($g3b->token, $newPassword, $newPassword, AcmPersonService::uuid4());
expectCode('R3: consumed grant + fresh key refused', 'grant_consumed', function () use (
    $ceremony, $g3b, $newPassword
) {
    $ceremony->completeRecovery($g3b->token, $newPassword, $newPassword, AcmPersonService::uuid4());
});
$t3c = seedTicket('R3c');
seedActiveAccount($tag . '_u3c', (int)$t3c['person_id'], $officer1);
$c3c = trackCode($ceremony->issueCode($officerCtx, (string)$t3c['id'], CodePurpose::Reset));
$g3c = $ceremony->verifyCode($c3c->selector, $c3c->secret, CodePurpose::Reset, $meta);
$pdo->exec(
    'UPDATE acm_activation_grants SET revoked_at = NOW()'
    . " WHERE fingerprint = '" . substr(hash('sha256', $g3c->token), 0, 12) . "'"
);
expectCode('R3: revoked grant refused', 'grant_revoked', function () use (
    $ceremony, $g3c, $newPassword
) {
    $ceremony->completeRecovery($g3c->token, $newPassword, $newPassword, AcmPersonService::uuid4());
});

// ── R4: operation binding, both directions ──────────────────────────
echo "R4 operation binding\n";
$t4 = seedTicket('R4');
seedActiveAccount($tag . '_u4', (int)$t4['person_id'], $officer1);
$c4 = trackCode($ceremony->issueCode($officerCtx, (string)$t4['id'], CodePurpose::Activation));
$g4 = $ceremony->verifyCode($c4->selector, $c4->secret, CodePurpose::Activation, $meta);
expectCode('R4: activation grant at recovery refused', 'grant_invalid', function () use (
    $ceremony, $g4, $newPassword
) {
    $ceremony->completeRecovery($g4->token, $newPassword, $newPassword, AcmPersonService::uuid4());
});
$t4b = seedTicket('R4b');
seedActiveAccount($tag . '_u4b', (int)$t4b['person_id'], $officer1);
$c4b = trackCode($ceremony->issueCode($officerCtx, (string)$t4b['id'], CodePurpose::Reset));
$g4b = $ceremony->verifyCode($c4b->selector, $c4b->secret, CodePurpose::Reset, $meta);
expectCode('R4: reset grant at activation refused', 'grant_invalid', function () use (
    $ceremony, $g4b, $newPassword
) {
    $ceremony->establishCredentials($g4b->token, $newPassword, AcmPersonService::uuid4());
});

// ── R5: pending / deactivated accounts refuse ───────────────────────
echo "R5 active accounts only\n";
$t5 = seedTicket('R5'); // person with NO account link at all
$c5 = trackCode($ceremony->issueCode($officerCtx, (string)$t5['id'], CodePurpose::Reset));
$g5 = $ceremony->verifyCode($c5->selector, $c5->secret, CodePurpose::Reset, $meta);
expectCode('R5: account-less ticket refused', 'ticket_state', function () use (
    $ceremony, $g5, $newPassword
) {
    $ceremony->completeRecovery($g5->token, $newPassword, $newPassword, AcmPersonService::uuid4());
});
$t5b = seedTicket('R5b');
$u5b = seedUser($pdo, $tag . '_u5b', 'member', false); // deactivated users row + ACTIVE link
$pdo->prepare(
    'INSERT INTO acm_person_accounts (person_id, user_id, account_type, status, linked_by, linked_at)'
    . ' VALUES (:p, :u, \'member\', \'active\', :b, NOW())'
)->execute([':p' => (int)$t5b['person_id'], ':u' => $u5b, ':b' => $officer1]);
$c5b = trackCode($ceremony->issueCode($officerCtx, (string)$t5b['id'], CodePurpose::Reset));
$g5b = $ceremony->verifyCode($c5b->selector, $c5b->secret, CodePurpose::Reset, $meta);
expectCode('R5: deactivated account refused', 'ticket_state', function () use (
    $ceremony, $g5b, $newPassword
) {
    $ceremony->completeRecovery($g5b->token, $newPassword, $newPassword, AcmPersonService::uuid4());
});

// ── R6: crash continuation converges, then replays ──────────────────
echo "R6 crash continuation\n";
$t6 = seedTicket('R6');
$u6 = seedActiveAccount($tag . '_u6', (int)$t6['person_id'], $officer1);
$c6 = trackCode($ceremony->issueCode($officerCtx, (string)$t6['id'], CodePurpose::Reset));
$g6 = $ceremony->verifyCode($c6->selector, $c6->secret, CodePurpose::Reset, $meta);
$fp6 = substr(hash('sha256', $g6->token), 0, 12);
$key6 = AcmPersonService::uuid4();
// Craft the post-PDO-A crash state: grant consumed AND expired,
// idempotency row saved, ticket still open, password untouched.
$pdo->exec(
    'UPDATE acm_activation_grants SET consumed_at = NOW(), expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
    . " WHERE fingerprint = '{$fp6}'"
);
$pdo->prepare(
    'INSERT INTO acm_idempotency_keys (idem_key, grant_fingerprint, user_id, username, account_type, expires_at)'
    . ' VALUES (:k, :fp, :u, :un, \'member\', DATE_ADD(NOW(), INTERVAL 48 HOUR))'
)->execute([':k' => $key6, ':fp' => $fp6, ':u' => $u6, ':un' => $tag . '_u6']);
check('R6: ticket still open', 'grant_verified', ticketStatus((int)$t6['id']));
$r6 = $ceremony->completeRecovery($g6->token, $newPassword, $newPassword, $key6);
check('R6: crashed attempt completes', $u6, $r6['user_id']);
check('R6: completion is not a replay', false, $r6['replayed']);
check(
    'R6: password set',
    true,
    password_verify(
        $newPassword,
        (string)$pdo->query('SELECT password_hash FROM users WHERE id = ' . $u6)->fetchColumn()
    )
);
check('R6: ticket closed', 'active', ticketStatus((int)$t6['id']));
check('R6: one recovered event', 1, count(recoveredEvents((int)$t6['id'])));
$r6b = $ceremony->completeRecovery($g6->token, $newPassword, $newPassword, $key6);
check('R6: retry replays', true, $r6b['replayed']);
check('R6: still one recovered event', 1, count(recoveredEvents((int)$t6['id'])));

// ── R7: no resetter fails closed, grant untouched ───────────────────
echo "R7 fail closed\n";
$t7 = seedTicket('R7');
seedActiveAccount($tag . '_u7', (int)$t7['person_id'], $officer1);
$c7 = trackCode($noResetter->issueCode($officerCtx, (string)$t7['id'], CodePurpose::Reset));
$g7 = $noResetter->verifyCode($c7->selector, $c7->secret, CodePurpose::Reset, $meta);
expectRuntime('R7: recovery without writer throws', function () use (
    $noResetter, $g7, $newPassword
) {
    $noResetter->completeRecovery(
        $g7->token, $newPassword, $newPassword, AcmPersonService::uuid4()
    );
});
check('R7: grant unconsumed', null, grantRow($g7->token)['consumed_at']);
check('R7: ticket untouched', 'grant_verified', ticketStatus((int)$t7['id']));

// ── cleanup ─────────────────────────────────────────────────────────
echo "cleanup\n";
try {
    if ($seedTicketIds !== []) {
        $tIds = implode(',', array_map('intval', $seedTicketIds));
        $pdo->exec("DELETE FROM acm_activation_grants WHERE ticket_id IN ({$tIds})");
        $pdo->exec("DELETE FROM acm_activation_codes WHERE ticket_id IN ({$tIds})");
        $pdo->exec("DELETE FROM acm_ticket_events WHERE ticket_id IN ({$tIds})");
        $pdo->exec("DELETE FROM acm_activation_tickets WHERE id IN ({$tIds})");
    }
    if ($seedSelectors !== []) {
        $sel = implode(',', array_map(
            static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'",
            $seedSelectors
        ));
        $pdo->exec("DELETE FROM acm_activation_attempts WHERE selector IN ({$sel})");
    }
    if ($seedUserIds !== []) {
        $uIds = implode(',', array_map('intval', $seedUserIds));
        $pdo->exec("DELETE FROM acm_idempotency_keys WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM acm_person_accounts WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM api_refresh_sessions WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM users WHERE id IN ({$uIds})");
    }
    if ($seedPersonIds !== []) {
        $pIds = implode(',', array_map('intval', $seedPersonIds));
        $pdo->exec("DELETE FROM acm_persons WHERE id IN ({$pIds})");
    }
    if ($seedMemberIds !== []) {
        $pdo->exec('DELETE FROM members WHERE id IN (' . implode(',', array_map('intval', $seedMemberIds)) . ')');
    }
    if ($seedGuardianIds !== []) {
        $pdo->exec('DELETE FROM guardians WHERE id IN (' . implode(',', array_map('intval', $seedGuardianIds)) . ')');
    }
    echo "  ok    cleanup\n";
} catch (Throwable $e) {
    $failures[] = 'cleanup: ' . $e->getMessage();
    echo "  FAIL  cleanup: {$e->getMessage()}\n";
}

echo "\n{$checks} checks, " . count($failures) . " failures\n";
if ($failures !== []) {
    fwrite(STDERR, "WALKTHROUGH RED\n" . implode("\n---\n", $failures) . "\n");
    exit(1);
}
echo "WALKTHROUGH GREEN\n";
