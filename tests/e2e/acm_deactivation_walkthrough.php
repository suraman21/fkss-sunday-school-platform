<?php
/**
 * ACM S8 — deactivation walkthrough against a real MariaDB.
 * ----------------------------------------------------------
 * E2E through the real services: deactivate kills ALL devices
 * FIRST (families cascade each, revoked_by = the super admin),
 * then ALL sessions (linked AND legacy unlinked), then flips
 * link + bit + history in one transaction; the per-request gate
 * (S7 subprocess probe, REAL function) and refresh rotation
 * (REAL call) both refuse; double-deactivate/pending/staff/
 * office-actor/bad-reason/no-revokers all fail honestly with
 * nothing burned; reactivate-after-revoke refuses (terminal);
 * the staff guards execute for real (subprocess probes through
 * the REAL user-toggle/user-save/user-delete files); a FRESH
 * activation ticket re-provisions the person (new users row,
 * suffixed username, pending link); the reviewer report
 * surfaces acm.account.deactivated; S7 suspend/reactivate still
 * round-trips (shared-helper regression).
 *
 * COMMAND LINE ONLY, and destructive: it seeds officers,
 * members, persons, tickets, users, devices, sessions and audit
 * rows, then deletes them again. Disposable database only
 * (destructive_guard interlock), never production.
 *
 * Usage (dev machine, migrations through 090 applied):
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/acm_deactivation_walkthrough.php
 *
 * NOT run in CI (no database in the sandbox); the sandbox pins
 * the same behaviors statically
 * (tests/security/test_acm_s8_deactivation.py). S8 adds no
 * PHPUnit file: every path is DB-backed (stated, not hidden).
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

use App\Services\MysqliCredentialRepository;
use App\Services\RefreshTokenService;
use App\Services\SecurityAuditService;
use SSMS\Acm\Accounts\AccountProvisioner;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Device\DeviceService;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Office\AcmOfficeService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'acm_deactivation_walkthrough');

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
    $pdo->query("SELECT 1 FROM acm_account_suspensions WHERE action = 'deactivated' LIMIT 1");
} catch (PDOException $e) {
    fwrite(STDERR, "migration 090 is not applied to {$dbName}\n");
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
        if ($e->getMessage() === $message) {
            echo "  ok    {$label}\n";
            return;
        }
        $failures[] = "{$label}\n      expected: " . json_encode($message)
            . "\n      actual:   " . json_encode($e->getMessage());
        echo "  FAIL  {$label}\n";
        return;
    } catch (Throwable $e) {
        $failures[] = "{$label}\n      expected InvalidArgumentException("
            . json_encode($message) . ")\n      actual: " . get_class($e) . ': ' . $e->getMessage();
        echo "  FAIL  {$label}\n";
        return;
    }
    $failures[] = "{$label}\n      expected InvalidArgumentException, nothing thrown";
    echo "  FAIL  {$label}\n";
}

/** Assert $fn throws a fail-closed RuntimeException (message asserted by caller). */
function expectRuntime(string $label, string $message, callable $fn): void
{
    global $failures, $checks;
    $checks++;
    try {
        $fn();
    } catch (RuntimeException $e) {
        if ($e->getMessage() === $message) {
            echo "  ok    {$label}\n";
            return;
        }
        $failures[] = "{$label}\n      expected: " . json_encode($message)
            . "\n      actual:   " . json_encode($e->getMessage());
        echo "  FAIL  {$label}\n";
        return;
    } catch (Throwable $e) {
        $failures[] = "{$label}\n      expected RuntimeException\n      actual: " . get_class($e) . ': ' . $e->getMessage();
        echo "  FAIL  {$label}\n";
        return;
    }
    $failures[] = "{$label}\n      expected RuntimeException, nothing thrown";
    echo "  FAIL  {$label}\n";
}

/** The S7 gate probe is generic (dbname + userId): reuse, don't fork. */
function gateProbe(string $dbName, int $userId): string
{
    $cmd = sprintf(
        'php %s %s %d 2>&1',
        escapeshellarg(__DIR__ . '/acm_lifecycle_gate_probe.php'),
        escapeshellarg($dbName),
        $userId
    );
    $out = [];
    exec($cmd, $out);
    return implode("\n", $out);
}

/** Execute a REAL staff endpoint file in a subprocess. Returns [exitCode, stdout]. */
function guardProbe(string $dbName, string $endpoint, int $target, int $admin, string $password = ''): array
{
    $cmd = sprintf(
        'php %s %s %s %d %d %s',
        escapeshellarg(__DIR__ . '/acm_deactivation_guard_probe.php'),
        escapeshellarg($dbName),
        escapeshellarg($endpoint),
        $target,
        $admin,
        $password === '' ? '' : escapeshellarg($password)
    );
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

$persons = new AcmPersonService($pdo);
$tickets = new AcmTicketService($pdo, $persons);
$office = new AcmOfficeService($pdo);

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

// Real S4 device service (family cascade via the live refresh service).
$devices = new DeviceService(
    $pdo,
    $tickets,
    static function (int $deviceId) use ($refresh): int {
        return $refresh->revokeDeviceFamilies($deviceId);
    }
);

// Order spies: at device-revoke time the link must STILL be live
// (devices-first, before the transaction); at session-revoke time
// the bit must STILL be 1 (revoke before the flips).
$deviceObservations = [];
$revokeObservations = [];
$sessionRevoker = function (int $userId) use ($mysqli, $pdo, &$revokeObservations): void {
    $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :u LIMIT 1');
    $stmt->execute([':u' => $userId]);
    $revokeObservations[$userId] = (int)$stmt->fetchColumn();
    (new MysqliCredentialRepository($mysqli))->revokeAllRefreshSessions($userId);
};

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

$superPass = 'E2E Super ' . $tag;
$super = seedUser($pdo, $tag . '_super', 'super_admin');
// The delete probe must pass the password step-up: set a KNOWN password.
$pdo->prepare('UPDATE users SET password_hash = :ph WHERE id = :u')->execute([
    ':ph' => password_hash($superPass, PASSWORD_DEFAULT), ':u' => $super,
]);
$superCtx = new AccountContext($super, 'super_admin');
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

/** Live S4 device row. Returns [rowId, uuid]. */
function seedDevice(int $userId): array
{
    global $pdo;
    $uuid = AcmPersonService::uuid4();
    $stmt = $pdo->prepare(
        'INSERT INTO acm_devices (uuid, user_id, installation_id, pubkey, fingerprint, alg, status)'
        . " VALUES (:uuid, :u, :inst, :pk, :fp, 'ES256', 'active')"
    );
    $stmt->execute([
        ':uuid' => $uuid, ':u' => $userId,
        ':inst' => 'e2e-inst-' . substr(md5($uuid), 0, 16),
        ':pk' => base64_encode('e2e-device-key-' . $uuid),
        ':fp' => hash('sha256', 'e2e-fp-' . $uuid),
    ]);
    return [(int)$pdo->lastInsertId(), $uuid];
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

function deviceRows(int $userId): array
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT id, uuid, status, revoked_by, revoke_reason FROM acm_devices'
        . ' WHERE user_id = :u ORDER BY id ASC'
    );
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
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

function userExists(int $userId): bool
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :u LIMIT 1');
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchColumn() !== false;
}

// Console-shaped device closure over the REAL service (this is the
// exact loop api_acm.php runs; the closure is pinned statically,
// the loop's EFFECT is proven here on live rows).
$deviceRevoker = function (int $userId, string $reason) use (
    $devices, $superCtx, $pdo, &$deviceObservations
): int {
    $stmt = $pdo->prepare('SELECT status FROM acm_person_accounts WHERE user_id = :u LIMIT 1');
    $stmt->execute([':u' => $userId]);
    $deviceObservations[$userId] = (string)$stmt->fetchColumn();
    $processed = 0;
    foreach ($devices->listForUser($userId) as $device) {
        $devices->revokeWithReport($superCtx, (string)$device['uuid'], $reason);
        $processed++;
    }
    return $processed;
};
$lifecycle = new AccountProvisioner($pdo, $sessionRevoker, $deviceRevoker);
$noRevokers = new AccountProvisioner($pdo);
$noDevices = new AccountProvisioner($pdo, $sessionRevoker);

// ── D1: deactivate kills devices, sessions, then flips atomically ──
echo "D1 deactivate\n";
$seed1 = seedPerson();
$u1 = seedActiveAccount($tag . '_u1', (int)$seed1['person']['id'], $officer1);
[$devRow1] = seedDevice($u1);
[$devRow2] = seedDevice($u1);
// One linked family (device-bound) + one legacy unlinked session.
$tokLinked = $refresh->issue(
    ['id' => $u1, 'username' => $tag . '_u1', 'role' => 'member', 'full_name' => 'E2E'],
    '127.0.0.1', 'e2e', $devRow1
);
$payLinked = $capturedPayloads[$tokLinked];
$tokLegacy = $refresh->issue(
    ['id' => $u1, 'username' => $tag . '_u1', 'role' => 'member', 'full_name' => 'E2E'],
    '127.0.0.2', 'e2e'
);
check('D1: sessions live before', 2, liveSessions($u1));
check('D1: gate passes before', 'GATE_OK', gateProbe($dbName, $u1));
$r1 = $lifecycle->deactivate($u1, 'D1 test deactivation', $superCtx);
check('D1: returns user', $u1, $r1['user_id']);
check('D1: returns username', $tag . '_u1', $r1['username']);
check('D1: devices processed', 2, $r1['devices']);
check('D1: link still live at device-revoke (devices-first)', 'active', $deviceObservations[$u1]);
check('D1: revoke saw the bit still set (revoke-first)', 1, $revokeObservations[$u1]);
$devs1 = deviceRows($u1);
check('D1: both devices revoked', ['revoked', 'revoked'], [$devs1[0]['status'], $devs1[1]['status']]);
check('D1: revoked_by is the super admin', $super, (int)$devs1[0]['revoked_by']);
check('D1: revoke reason carried', 'D1 test deactivation', (string)$devs1[0]['revoke_reason']);
check('D1: all sessions dead (linked + legacy)', 0, liveSessions($u1));
check('D1: bit flipped', 0, userBit($u1));
check('D1: link revoked', 'revoked', linkStatus($u1));
$h1 = historyRows($u1);
check('D1: one history row', 1, count($h1));
check('D1: history action', 'deactivated', $h1[0]['action']);
check('D1: history reason', 'D1 test deactivation', $h1[0]['reason']);
check('D1: history actor', $super, (int)$h1[0]['actor_id']);
$rot1 = $refresh->rotate($tokLinked, $payLinked, '127.0.0.1', 'e2e');
check('D1: linked rotation refused', true, ($rot1['state'] ?? 'ok') !== 'ok');
check(
    'D1: per-request gate refuses',
    true,
    strpos(gateProbe($dbName, $u1), 'ACCOUNT_DISABLED') !== false
);

// ── D2: honest refusals, nothing burned ─────────────────────────────
echo "D2 refusals\n";
expectInvalid('D2: double-deactivate refused', 'Account is already deactivated.', function () use (
    $lifecycle, $u1, $superCtx
) {
    $lifecycle->deactivate($u1, 'again', $superCtx);
});
check('D2: still one history row', 1, count(historyRows($u1)));
// Pending link: a person mid-ceremony (link pending, bit gated).
$seedPend = seedPerson();
$uPend = seedUser($pdo, $tag . '_upend', 'member', false);
$pdo->prepare(
    'INSERT INTO acm_person_accounts (person_id, user_id, account_type, status, linked_by, linked_at)'
    . " VALUES (:p, :u, 'member', 'pending', :b, NOW())"
)->execute([':p' => (int)$seedPend['person']['id'], ':u' => $uPend, ':b' => $officer1]);
expectInvalid('D2: pending refused', 'Pending accounts finish activation or cancel first.', function () use (
    $lifecycle, $uPend, $superCtx
) {
    $lifecycle->deactivate($uPend, 'nope', $superCtx);
});
// Staff/officer negatives need link rows: the target guard reads
// users.role THROUGH the link (S7 precedent).
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
expectInvalid('D2: officer target refused',
    'Staff and officer accounts are managed in user administration.', function () use (
        $lifecycle, $officer2, $superCtx
    ) {
        $lifecycle->deactivate($officer2, 'nope', $superCtx);
    });
expectInvalid('D2: staff target refused',
    'Staff and officer accounts are managed in user administration.', function () use (
        $lifecycle, $teacher, $superCtx
    ) {
        $lifecycle->deactivate($teacher, 'nope', $superCtx);
    });
$seed2 = seedPerson();
$u2 = seedActiveAccount($tag . '_u2', (int)$seed2['person']['id'], $officer1);
expectInvalid('D2: office actor refused', 'Access denied.', function () use (
    $lifecycle, $u2, $officerCtx
) {
    $lifecycle->deactivate($u2, 'office cannot', $officerCtx);
});
expectInvalid('D2: unknown account refused', 'Unknown account.', function () use (
    $lifecycle, $superCtx
) {
    $lifecycle->deactivate(2147483647, 'nope', $superCtx);
});
expectInvalid('D2: empty reason refused', 'Reason is required.', function () use (
    $lifecycle, $u2, $superCtx
) {
    $lifecycle->deactivate($u2, '   ', $superCtx);
});
expectInvalid('D2: long reason refused', 'Reason is too long (2000 characters maximum).', function () use (
    $lifecycle, $u2, $superCtx
) {
    $lifecycle->deactivate($u2, str_repeat('x', 2001), $superCtx);
});
expectRuntime('D2: no revokers fails closed', 'Account deactivation is unavailable.', function () use (
    $noRevokers, $u2, $superCtx
) {
    $noRevokers->deactivate($u2, 'no writers', $superCtx);
});
expectRuntime('D2: session-only fails closed', 'Account deactivation is unavailable.', function () use (
    $noDevices, $u2, $superCtx
) {
    $noDevices->deactivate($u2, 'no device writer', $superCtx);
});
check('D2: u2 untouched (bit)', 1, userBit($u2));
check('D2: u2 untouched (link)', 'active', linkStatus($u2));
check('D2: u2 untouched (history)', 0, count(historyRows($u2)));
expectInvalid('D2: reactivate-after-revoke refused', 'Account is not suspended.', function () use (
    $lifecycle, $u1, $superCtx
) {
    // Terminal means terminal: super_admin holds BOTH verbs, so
    // this refusal comes from state, never from policy.
    $lifecycle->reactivate($u1, 'no revive', $superCtx);
});

// ── D3: staff guards execute for real ───────────────────────────────
echo "D3 staff guards\n";
// Toggle outer wall: member targets are un-toggleable by role.
[$codeT1, $outT1] = guardProbe($dbName, 'toggle', $u2, $super);
$jsonT1 = json_decode($outT1, true);
check('D3: toggle probe ran', 0, $codeT1);
check('D3: member untoggleable (outer wall)', 'You do not have permission to modify this user', (string)($jsonT1['message'] ?? ''));
// Toggle S8 guard: synthetic revoked link on an office-role user
// (the guard reads THROUGH the link; the row is synthetic, the
// executed code path -- guard SQL + refusal + exit -- is real).
$uTog = seedUser($pdo, $tag . '_utog', 'accounts_office', false);
$pdo->prepare(
    'INSERT INTO acm_person_accounts (person_id, user_id, account_type, status, linked_by, linked_at)'
    . " VALUES (:p, :u, 'staff', 'revoked', :b, NOW())"
)->execute([':p' => (int)$seedStaff['person']['id'], ':u' => $uTog, ':b' => $super]);
[$codeT2, $outT2] = guardProbe($dbName, 'toggle', $uTog, $super);
$jsonT2 = json_decode($outT2, true);
check('D3: toggle guard probe ran', 0, $codeT2);
check('D3: toggle guard refuses revive',
    'This account was deactivated and cannot be re-enabled; start a fresh activation.',
    (string)($jsonT2['message'] ?? ''));
check('D3: toggle guard left the bit gated', 0, userBit($uTog));
// Toggle positive control: the same endpoint still activates a
// non-ACM office user (the guard narrows nothing else).
$uTogOk = seedUser($pdo, $tag . '_utogok', 'accounts_office', false);
[$codeT3, $outT3] = guardProbe($dbName, 'toggle', $uTogOk, $super);
$jsonT3 = json_decode($outT3, true);
check('D3: toggle still activates non-ACM users', 'success', (string)($jsonT3['status'] ?? ''));
check('D3: positive-control bit flipped', 1, userBit($uTogOk));
// Save outer wall: member/parent are not valid staff roles.
[$codeS1, $outS1] = guardProbe($dbName, 'save', $u2, $super);
$jsonS1 = json_decode($outS1, true);
check('D3: save probe ran', 0, $codeS1);
check('D3: member unsavable (outer wall)',
    'You do not have permission to create this type of user account.',
    (string)($jsonS1['message'] ?? ''));
// Save S8 guard: synthetic revoked link; resubmits the live row
// with a 0->1 flip (the probe reads the row first).
$uSav = seedUser($pdo, $tag . '_usav', 'accounts_office', false);
$pdo->prepare(
    'INSERT INTO acm_person_accounts (person_id, user_id, account_type, status, linked_by, linked_at)'
    . " VALUES (:p, :u, 'staff', 'revoked', :b, NOW())"
)->execute([':p' => (int)$seedStaff['person']['id'], ':u' => $uSav, ':b' => $super]);
[$codeS2, $outS2] = guardProbe($dbName, 'save', $uSav, $super);
$jsonS2 = json_decode($outS2, true);
check('D3: save guard probe ran', 0, $codeS2);
check('D3: save guard refuses revive',
    'This account was deactivated and cannot be re-enabled; start a fresh activation.',
    (string)($jsonS2['message'] ?? ''));
check('D3: save guard left the bit gated', 0, userBit($uSav));
// Delete S8 guard: an ACM-linked member survives the REAL delete
// file (phrase + password + everything); side effects are the
// verdict (delete redirects, so stdout carries no JSON).
check('D3: u1 has sessions+devices before delete probe', true, liveSessions($u1) === 0 && count(deviceRows($u1)) === 2);
[$codeD1] = guardProbe($dbName, 'delete', $u1, $super, $superPass);
check('D3: delete probe ran', 0, $codeD1);
check('D3: ACM-linked user survives delete', true, userExists($u1));
check('D3: link survives delete', 'revoked', linkStatus($u1));
check('D3: devices survive delete', 2, count(deviceRows($u1)));
check('D3: history survives delete', 1, count(historyRows($u1)));
// Delete positive control: a non-ACM teacher is REALLY deleted.
$uDel = seedUser($pdo, $tag . '_udel', 'teacher');
[$codeD2] = guardProbe($dbName, 'delete', $uDel, $super, $superPass);
check('D3: delete positive ran', 0, $codeD2);
check('D3: non-ACM user really deleted', false, userExists($uDel));

// ── D4: fresh activation re-provisions the person ───────────────────
echo "D4 reprovision\n";
check('D4: revoked link leaves room', false,
    (new AccountProvisioner($pdo))->hasAccount((int)$seed1['person']['id'], 'member'));
$ticketNew = $tickets->create([
    'person_id' => (int)$seed1['person']['id'],
    'account_type' => 'member',
    'member_id' => (int)$seed1['member_id'],
], $officer1);
$seedTicketIds[] = (int)$ticketNew['id'];
$tickets->decide(
    (int)$ticketNew['id'], $officer2, 'approve', '', false,
    ['mode' => 'separated', 'active_officers' => 2]
);
// The real caller owns the transaction + duplicate check: mirror it.
$pdo->beginTransaction();
$canProvision = !(new AccountProvisioner($pdo))->hasAccount((int)$seed1['person']['id'], 'member');
$u1new = null;
if ($canProvision) {
    $u1new = (new AccountProvisioner($pdo))->provision(
        $ticketNew,
        $persons->find((int)$seed1['person']['id']),
        password_hash('e2e-fresh-password', PASSWORD_DEFAULT),
        $officer1
    );
    $seedUserIds[] = $u1new;
}
$pdo->commit();
check('D4: duplicate check passes', true, $canProvision);
check('D4: new users row', true, $u1new !== null && $u1new !== $u1);
$oldName = (string)$pdo->query('SELECT username FROM users WHERE id = ' . $u1)->fetchColumn();
$newName = (string)$pdo->query('SELECT username FROM users WHERE id = ' . (int)$u1new)->fetchColumn();
check('D4: username re-allocated (D3.4)', true, $newName !== '' && $newName !== $oldName);
check('D4: new link pending', 'pending', linkStatus((int)$u1new));
check('D4: new user gated until S4', 0, userBit((int)$u1new));
check('D4: old link still revoked', 'revoked', linkStatus($u1));
check('D4: person holds an account again', true,
    (new AccountProvisioner($pdo))->hasAccount((int)$seed1['person']['id'], 'member'));

// ── D5: reviewer visibility + S7 regression ─────────────────────────
echo "D5 report + regression\n";
// The console writes this exact action string via acm_audit()
// (statically pinned); the report must surface LIKE 'acm.%'
// rows to the #4 reviewer.
$_SESSION['admin_id'] = $super;
$_SESSION['admin_username'] = $tag . '_super';
SecurityAuditService::record(
    $pdo, 'acm.account.deactivated',
    ['username' => $tag . '_u1', 'reason' => 'D1 test deactivation', 'devices' => 2],
    'user', $u1
);
$report = $office->auditReport(date('Y-m'));
$actions = [];
foreach (($report['summary']['by_action'] ?? []) as $action => $count) {
    $actions[$action] = ($actions[$action] ?? 0) + $count;
}
check('D5: report sees deactivated', true, ($actions['acm.account.deactivated'] ?? 0) > 0);
// S7 regression: suspend/reactivate round-trips on shared helpers.
$seed5 = seedPerson();
$u5 = seedActiveAccount($tag . '_u5', (int)$seed5['person']['id'], $officer1);
$lifecycle->suspend($u5, 'D5 regression suspension', $officerCtx);
check('D5: suspend still works', 'suspended', linkStatus($u5));
$lifecycle->reactivate($u5, 'D5 regression reactivation', $officerCtx);
check('D5: reactivate still works', 'active', linkStatus($u5));
check('D5: regression bit restored', 1, userBit($u5));

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
        $pdo->exec("DELETE FROM acm_devices WHERE user_id IN ({$uIds})");
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
