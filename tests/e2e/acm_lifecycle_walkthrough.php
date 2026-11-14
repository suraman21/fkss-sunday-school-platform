<?php
/**
 * ACM S7 — suspension/reactivation walkthrough against a real MariaDB.
 * ------------------------------------------------------------------
 * E2E through the real services: suspend kills sessions FIRST then
 * flips the bit + link + history in one transaction; the per-request
 * gate (subprocess probe, REAL function) and refresh rotation (REAL
 * call) both refuse; double-suspend/staff-target/bad-reason/no-
 * revoker all fail honestly with nothing burned; reactivate
 * restores from suspended AND drifted states; S6 recovery refuses
 * while suspended and works after reactivation; the reviewer
 * report surfaces both acm.account.* actions.
 *
 * COMMAND LINE ONLY, and destructive: it seeds officers, members,
 * persons, tickets, users, sessions and audit rows, then deletes
 * them again. Disposable database only (destructive_guard
 * interlock), never production.
 *
 * Usage (dev machine, migrations through 089 applied):
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/acm_lifecycle_walkthrough.php
 *
 * NOT run in CI (no database in the sandbox); the sandbox pins the
 * same behaviors statically (tests/security/test_acm_s7_lifecycle.py).
 * S7 adds no PHPUnit file: every path is DB-backed (stated, not hidden).
 * The console cases' wiring (policy pre-check + service + audit) is
 * pinned statically; this walkthrough covers the service contract,
 * the REAL gates, and reviewer-report visibility of the action
 * strings the console writes.
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
require_once $root . '/admin/backend/services/SecurityAuditService.php';
require_once $root . '/admin/backend/services/RefreshTokenService.php';
require_once $root . '/acm/autoload.php';

use App\Services\AccountCredentialService;
use App\Services\AuthorizedAdministratorIdentity;
use App\Services\MysqliCredentialRepository;
use App\Services\RefreshTokenService;
use App\Services\SecurityAuditService;
use SSMS\Acm\Accounts\AccountProvisioner;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationException;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\CodePurpose;
use SSMS\Acm\Contracts\RequestMeta;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Office\AcmOfficeService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'acm_lifecycle_walkthrough');

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
    $pdo->query('SELECT 1 FROM acm_account_suspensions LIMIT 1');
} catch (PDOException $e) {
    fwrite(STDERR, "migration 089 is not applied to {$dbName}\n");
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

/** Assert $fn throws InvalidArgumentException with message $message. */
function expectInvalid(string $label, string $message, callable $fn): void
{
    global $failures, $checks;
    $checks++;
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() !== $message) {
            $failures[] = "{$label}\n      expected message: {$message}\n      actual message:   {$e->getMessage()}";
            echo "  FAIL  {$label}\n";
        } else {
            echo "  ok    {$label}\n";
        }
        return;
    } catch (Throwable $e) {
        $failures[] = "{$label}\n      expected InvalidArgumentException\n      actual throw:  " . get_class($e) . ': ' . $e->getMessage();
        echo "  FAIL  {$label}\n";
        return;
    }
    $failures[] = "{$label}\n      expected InvalidArgumentException\n      actual: no exception";
    echo "  FAIL  {$label}\n";
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

/** Run the REAL per-request gate in a subprocess (it exits on refusal). */
function gateProbe(string $dbName, int $userId): string
{
    $cmd = sprintf(
        'php %s %s %d 2>/dev/null',
        escapeshellarg(__DIR__ . '/acm_lifecycle_gate_probe.php'),
        escapeshellarg($dbName),
        $userId
    );
    $out = [];
    exec($cmd, $out);
    return implode("\n", $out);
}

$persons = new AcmPersonService($pdo);
$tickets = new AcmTicketService($pdo, $persons);
$office = new AcmOfficeService($pdo);

// Session revoker with an order spy: at revoke time the users bit
// must STILL be 1 (revoke-first), which the spy records.
$revokeObservations = [];
$revoker = function (int $userId) use ($mysqli, $pdo, &$revokeObservations): void {
    $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :u LIMIT 1');
    $stmt->execute([':u' => $userId]);
    $revokeObservations[$userId] = (int)$stmt->fetchColumn();
    (new MysqliCredentialRepository($mysqli))->revokeAllRefreshSessions($userId);
};
$lifecycle = new AccountProvisioner($pdo, $revoker);
$noRevoker = new AccountProvisioner($pdo);

// S6 ceremony (composition) with the route-shaped resetter.
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

function seedPerson(): array
{
    global $pdo, $persons, $officer1, $memberStmt, $guardianStmt,
        $tag, $personNo, $seedMemberIds, $seedGuardianIds, $seedPersonIds;
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
    return ['person' => $person, 'member_id' => $memberId];
}

/** Post-S4 account state: live users row + ACTIVE person link. */
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

function linkStatus(int $userId): ?string
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT status FROM acm_person_accounts WHERE user_id = :u LIMIT 1');
    $stmt->execute([':u' => $userId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
}

function userBit(int $userId): int
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :u LIMIT 1');
    $stmt->execute([':u' => $userId]);
    return (int)$stmt->fetchColumn();
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

function historyRows(int $userId): array
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT action, reason, actor_id FROM acm_account_suspensions'
        . ' WHERE user_id = :u ORDER BY id ASC'
    );
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
}

// ── L1: suspend kills sessions first, then flips atomically ─────────
echo "L1 suspend\n";
$seed1 = seedPerson();
$u1 = seedActiveAccount($tag . '_u1', (int)$seed1['person']['id'], $officer1);
$tok1 = $refresh->issue(
    ['id' => $u1, 'username' => $tag . '_u1', 'role' => 'member', 'full_name' => 'E2E'],
    '127.0.0.1', 'e2e'
);
$pay1 = $capturedPayloads[$tok1];
check('L1: session live before', true, liveSessions($u1) > 0);
check('L1: gate passes before', 'GATE_OK', gateProbe($dbName, $u1));
$r1 = $lifecycle->suspend($u1, 'L1 test suspension', $officerCtx);
check('L1: returns user', $u1, $r1['user_id']);
check('L1: returns username', $tag . '_u1', $r1['username']);
check('L1: revoke saw the bit still set (revoke-first)', 1, $revokeObservations[$u1]);
check('L1: sessions revoked', 0, liveSessions($u1));
check('L1: bit flipped', 0, userBit($u1));
check('L1: link suspended', 'suspended', linkStatus($u1));
$h1 = historyRows($u1);
check('L1: one history row', 1, count($h1));
check('L1: history action', 'suspended', $h1[0]['action']);
check('L1: history reason', 'L1 test suspension', $h1[0]['reason']);
check('L1: history actor', $officer1, (int)$h1[0]['actor_id']);
$rot1 = $refresh->rotate($tok1, $pay1, '127.0.0.1', 'e2e');
check('L1: rotation refused', 'revoked', $rot1['state']);
check(
    'L1: per-request gate refuses',
    true,
    strpos(gateProbe($dbName, $u1), 'ACCOUNT_DISABLED') !== false
);

// ── L2: honest refusals, nothing burned ─────────────────────────────
echo "L2 refusals\n";
expectInvalid('L2: double-suspend refused', 'Account is not active.', function () use (
    $lifecycle, $u1, $officerCtx
) {
    $lifecycle->suspend($u1, 'again', $officerCtx);
});
check('L2: still one history row', 1, count(historyRows($u1)));
// Staff/officer negatives need link rows: the target guard reads
// users.role THROUGH the link (unlinked users refuse earlier with
// 'Unknown account.' -- asserted separately below).
$seedStaff = seedPerson();
$linkStmt = $pdo->prepare(
    'INSERT INTO acm_person_accounts (person_id, user_id, account_type, status, linked_by, linked_at)'
    . " VALUES (:p, :u, 'staff', 'active', :b, NOW())"
);
$teacher = seedUser($pdo, $tag . '_teacher', 'teacher');
foreach ([$officer2, $teacher] as $staffUser) {
    $linkStmt->execute([
        ':p' => (int)$seedStaff['person']['id'], ':u' => $staffUser, ':b' => $officer1,
    ]);
}
expectInvalid('L2: officer target refused',
    'Staff and officer accounts are managed in user administration.', function () use (
        $lifecycle, $officer2, $officerCtx
    ) {
        $lifecycle->suspend($officer2, 'nope', $officerCtx);
    });
expectInvalid('L2: staff target refused',
    'Staff and officer accounts are managed in user administration.', function () use (
        $lifecycle, $teacher, $officerCtx
    ) {
        $lifecycle->suspend($teacher, 'nope', $officerCtx);
    });
expectInvalid('L2: unknown account refused', 'Unknown account.', function () use (
    $lifecycle, $officerCtx
) {
    $lifecycle->suspend(2147483647, 'nope', $officerCtx);
});
$seed2 = seedPerson();
$u2 = seedActiveAccount($tag . '_u2', (int)$seed2['person']['id'], $officer1);
expectInvalid('L2: empty reason refused', 'Reason is required.', function () use (
    $lifecycle, $u2, $officerCtx
) {
    $lifecycle->suspend($u2, '   ', $officerCtx);
});
expectInvalid('L2: long reason refused', 'Reason is too long (2000 characters maximum).', function () use (
    $lifecycle, $u2, $officerCtx
) {
    $lifecycle->suspend($u2, str_repeat('x', 2001), $officerCtx);
});
check('L2: u2 untouched (bit)', 1, userBit($u2));
check('L2: u2 untouched (link)', 'active', linkStatus($u2));
expectRuntime('L2: no revoker fails closed', function () use ($noRevoker, $u2, $officerCtx) {
    $noRevoker->suspend($u2, 'no writer', $officerCtx);
});
check('L2: u2 still untouched', 1, userBit($u2));
expectInvalid('L2: reactivate of active refused', 'Account is not suspended.', function () use (
    $lifecycle, $u2, $officerCtx
) {
    $lifecycle->reactivate($u2, 'not suspended', $officerCtx);
});

// ── L3: reactivate restores; drifted converges ──────────────────────
echo "L3 reactivate\n";
$r3 = $lifecycle->reactivate($u1, 'L3 test reactivation', $officerCtx);
check('L3: returns user', $u1, $r3['user_id']);
check('L3: bit restored', 1, userBit($u1));
check('L3: link active', 'active', linkStatus($u1));
check('L3: gate passes again', 'GATE_OK', gateProbe($dbName, $u1));
$h3 = historyRows($u1);
check('L3: two history rows', 2, count($h3));
check('L3: second is reactivation', 'reactivated', $h3[1]['action']);
// Drifted: staff flipped the bit directly (active link + gated bit).
$seed3 = seedPerson();
$u3 = seedActiveAccount($tag . '_u3', (int)$seed3['person']['id'], $officer1);
$pdo->exec('UPDATE users SET is_active = 0 WHERE id = ' . $u3);
check('L3: drifted link still active', 'active', linkStatus($u3));
$lifecycle->reactivate($u3, 'L3 converge drifted', $officerCtx);
check('L3: drifted bit restored', 1, userBit($u3));
check('L3: drifted history recorded', 'reactivated', historyRows($u3)[0]['action']);

// ── L4: S6 composition + reviewer visibility ────────────────────────
echo "L4 composition\n";
$seed4 = seedPerson();
$u4 = seedActiveAccount($tag . '_u4', (int)$seed4['person']['id'], $officer1);
$ticket4 = $tickets->create([
    'person_id' => (int)$seed4['person']['id'],
    'account_type' => 'member',
    'member_id' => (int)$seed4['member_id'],
], $officer1);
$seedTicketIds[] = (int)$ticket4['id'];
$tickets->decide(
    (int)$ticket4['id'], $officer2, 'approve', '', false,
    ['mode' => 'separated', 'active_officers' => 2]
);
$lifecycle->suspend($u4, 'L4 composition suspension', $officerCtx);
$code4 = $ceremony->issueCode($officerCtx, (string)$ticket4['id'], CodePurpose::Reset);
$grant4 = $ceremony->verifyCode(
    $code4->selector, $code4->secret, CodePurpose::Reset, new RequestMeta('127.0.0.1')
);
expectCode('L4: recovery refused while suspended', 'ticket_state', function () use (
    $ceremony, $grant4
) {
    $ceremony->completeRecovery(
        $grant4->token, 'Juniper Field 9 Harbor Light!', 'Juniper Field 9 Harbor Light!',
        AcmPersonService::uuid4()
    );
});
$lifecycle->reactivate($u4, 'L4 composition reactivation', $officerCtx);
// The refused attempt consumed-then-rolled-back inside S6's PDO-A,
// so the grant is still live: a fresh key completes after reactivation.
$done4 = $ceremony->completeRecovery(
    $grant4->token, 'Juniper Field 9 Harbor Light!', 'Juniper Field 9 Harbor Light!',
    AcmPersonService::uuid4()
);
check('L4: recovery works after reactivate', $u4, $done4['user_id']);
// Reviewer visibility: the console writes these exact action
// strings via acm_audit() (statically pinned); the report must
// surface LIKE 'acm.%' rows to the #4 reviewer.
$_SESSION['admin_id'] = $officer1;
$_SESSION['admin_username'] = $tag . '_off1';
SecurityAuditService::record(
    $pdo, 'acm.account.suspended',
    ['username' => $tag . '_u4', 'reason' => 'L4 composition suspension'],
    'user', $u4
);
SecurityAuditService::record(
    $pdo, 'acm.account.reactivated',
    ['username' => $tag . '_u4', 'reason' => 'L4 composition reactivation'],
    'user', $u4
);
$report = $office->auditReport(date('Y-m'));
$actions = [];
foreach (($report['summary']['by_action'] ?? []) as $action => $count) {
    $actions[$action] = ($actions[$action] ?? 0) + $count;
}
check('L4: report sees suspended', true, ($actions['acm.account.suspended'] ?? 0) > 0);
check('L4: report sees reactivated', true, ($actions['acm.account.reactivated'] ?? 0) > 0);

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
    if ($seedUserIds !== []) {
        $uIds = implode(',', array_map('intval', $seedUserIds));
        $pdo->exec("DELETE FROM acm_account_suspensions WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM acm_idempotency_keys WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM acm_person_accounts WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM api_refresh_sessions WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM activity_logs WHERE entity_type = 'user'"
            . " AND entity_id IN ({$uIds}) AND action LIKE 'acm.account.%'");
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
