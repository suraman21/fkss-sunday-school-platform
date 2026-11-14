<?php
/**
 * ACM S4 — device walkthrough against a real MariaDB.
 * ------------------------------------------------------------
 * Two-party E2E (officer console actions + stub app client with a
 * REAL ECDSA signer) through the real services: activation-path
 * register (grant -> challenge -> signature -> active login),
 * device_add path, failure/burn/re-begin, the five revocation
 * cases (D8.3-9), legacy refresh regression (D8.3-10), both caps,
 * conflict rules, and the login-assertion seam. Expiry is
 * clock-simulated (UPDATE expires_at to the past).
 *
 * COMMAND LINE ONLY, and destructive: it seeds officers, members,
 * persons, tickets, devices and refresh sessions, then deletes
 * them again. Disposable database only (destructive_guard
 * interlock), never production.
 *
 * Usage (dev machine, migrations through 084 applied):
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/acm_devices_walkthrough.php
 *
 * NOT run in CI (no database in the sandbox); the sandbox pins the
 * same behaviors statically (tests/security/test_acm_s4_devices.py)
 * and the pure crypto units run in PHPUnit (DeviceCryptoTest).
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
require_once $root . '/admin/backend/services/RefreshTokenService.php';
require_once $root . '/acm/autoload.php';

use App\Services\RefreshTokenService;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationException;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\CodePurpose;
use SSMS\Acm\Contracts\IssuedCode;
use SSMS\Acm\Contracts\RequestMeta;
use SSMS\Acm\Device\DeviceCrypto;
use SSMS\Acm\Device\DeviceService;
use SSMS\Acm\Identity\AcmPersonService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'acm_devices_walkthrough');

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
    $pdo->query('SELECT 1 FROM acm_devices LIMIT 1');
    $pdo->query('SELECT 1 FROM acm_activation_codes LIMIT 1');
} catch (PDOException $e) {
    fwrite(STDERR, "migrations 082/084 are not applied to {$dbName}\n");
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

/** Assert $fn throws InvalidArgumentException (client bug, 400/404). */
function expectInvalid(string $label, callable $fn): void
{
    global $failures, $checks;
    $checks++;
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        echo "  ok    {$label}\n";
        return;
    } catch (Throwable $e) {
        $failures[] = "{$label}\n      expected InvalidArgumentException\n      actual throw:  " . get_class($e) . ': ' . $e->getMessage();
        echo "  FAIL  {$label}\n";
        return;
    }
    $failures[] = "{$label}\n      expected InvalidArgumentException\n      actual: no exception";
    echo "  FAIL  {$label}\n";
}

$persons = new AcmPersonService($pdo);
$tickets = new AcmTicketService($pdo, $persons);
$ceremony = new ActivationService($pdo, $tickets, $persons);

// Refresh service with a capturing test issuer: opaque tokens, and
// every issuance records the payload rotate() needs (jti/fid/uid).
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
$devices = new DeviceService(
    $pdo,
    $tickets,
    function (int $deviceId) use ($refresh): int {
        return $refresh->revokeDeviceFamilies($deviceId);
    }
);

// ── Seeded world ────────────────────────────────────────────────────
$tag = 'e2ed' . substr(md5(microtime(true) . getmypid()), 0, 8);
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
        'phone' => '0922' . str_pad((string)(100000 + $personNo), 6, '0', STR_PAD_LEFT),
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

function userIsActive(int $id): int
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :u LIMIT 1');
    $stmt->execute([':u' => $id]);
    return (int)$stmt->fetchColumn();
}

function deviceRowByUuid(string $uuid): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM acm_devices WHERE uuid = :u LIMIT 1');
    $stmt->execute([':u' => $uuid]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function challengeRowByUuid(string $uuid): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM acm_device_challenges WHERE uuid = :u LIMIT 1');
    $stmt->execute([':u' => $uuid]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

// ── Stub app signer (real ECDSA, like Android Keystore) ─────────────
function stubKey(): array
{
    $priv = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($priv === false) {
        throw new RuntimeException('openssl P-256 keygen failed.');
    }
    $details = openssl_pkey_get_details($priv);
    $pem = (string)$details['key'];
    $der = base64_decode(str_replace(
        ["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r", ' '],
        '',
        $pem
    ), true);
    return ['priv' => $priv, 'spkiB64' => base64_encode($der), 'fp' => hash('sha256', $der)];
}

/** Sign the challenge echo exactly like the app: DER ECDSA, base64. */
function stubSign($priv, string $challengeB64u): string
{
    $bytes = DeviceCrypto::base64UrlDecode($challengeB64u);
    if ($bytes === null) {
        throw new RuntimeException('Challenge echo is malformed.');
    }
    if (!openssl_sign($bytes, $sig, $priv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Stub signing failed.');
    }
    return base64_encode($sig);
}

function stubInstall(): string
{
    return sprintf(
        '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff), mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

$meta = new RequestMeta('127.0.0.1');
$password = 'Violet River 7 Kettle Drum!';

// ── D1: activation leg (D8.2 extended) ──────────────────────────────
echo "D1 activation leg\n";
$t1 = seedTicket('D1');
$c1 = trackCode($ceremony->issueCode($officerCtx, (string)$t1['id'], CodePurpose::Activation));
$g1 = $ceremony->verifyCode($c1->selector, $c1->secret, CodePurpose::Activation, $meta);
$a1 = $ceremony->establishCredentials($g1->token, $password, AcmPersonService::uuid4());
check('D1: credentials stall at pending-device', 'account_pending_device', ticketStatus((int)$t1['id']));
check('D1: account still inactive', 0, userIsActive($a1->userId));
check('D1: chained device grant issued', true, is_string($a1->deviceGrant) && $a1->deviceGrant !== '');
$seedUserIds[] = $a1->userId;
$userCtx = new AccountContext($a1->userId, 'member');
$grant1 = $ceremony->resolveRegistrationGrant((string)$a1->deviceGrant);
check('D1: grant resolves to the stalled ticket', (int)$t1['id'], $grant1['ticket_id']);
$keyA = stubKey();
$installA = stubInstall();
$ch1 = $devices->beginFull($userCtx, $installA, $keyA['spkiB64'], 'E2E phone A', $grant1);
check('D1: challenge id is a uuid', true, DeviceCrypto::isValidUuid($ch1->challengeId));
$dev1 = $devices->completeFull($userCtx, $ch1->challengeId, $ch1->challenge, stubSign($keyA['priv'], $ch1->challenge));
check('D1: device active', 'active', $dev1->status);
check('D1: fingerprint is the SPKI hash', $keyA['fp'], $dev1->fingerprint);
check('D1: ticket activated', 'active', ticketStatus((int)$t1['id']));
check('D1: account flipped active', 1, userIsActive($a1->userId));
// Pending-link fix: the provision-time pending link converges to
// active in the same transaction (userIdFor + lifecycle guards
// require it; before the fix the chained grant above could never
// resolve and D1 fataled).
$linkStmt = $pdo->prepare('SELECT status FROM acm_person_accounts WHERE user_id = :u LIMIT 1');
$linkStmt->execute([':u' => $a1->userId]);
check('D1: link flipped active', 'active', (string)$linkStmt->fetchColumn());
$rowA = deviceRowByUuid($dev1->deviceUuid);
check('D1: stored alg is ES256', 'ES256', (string)$rowA['alg']);
check('D1: stored name kept', 'E2E phone A', (string)$rowA['name']);
$deviceAId = (int)$rowA['id'];

// ── D2: failures, burn, re-begin, cross-user ────────────────────────
echo "D2 failures\n";
$t2 = seedTicket('D2');
$c2 = trackCode($ceremony->issueCode($officerCtx, (string)$t2['id'], CodePurpose::Activation));
$g2 = $ceremony->verifyCode($c2->selector, $c2->secret, CodePurpose::Activation, $meta);
$a2 = $ceremony->establishCredentials($g2->token, $password, AcmPersonService::uuid4());
$seedUserIds[] = $a2->userId;
$u2 = new AccountContext($a2->userId, 'member');
$grant2 = $ceremony->resolveRegistrationGrant((string)$a2->deviceGrant);
$keyB = stubKey();
$installB = stubInstall();
$ch2 = $devices->beginFull($u2, $installB, $keyB['spkiB64'], null, $grant2);
$wrongSig = base64_encode(str_repeat("\x30", 70));
expectCode('D2: invalid signature is generic', 'challenge_failed', function () use (
    $devices, $u2, $ch2, $wrongSig
) {
    $devices->completeFull($u2, $ch2->challengeId, $ch2->challenge, $wrongSig);
});
$pendRow = challengeRowByUuid($ch2->challengeId);
check('D2: one attempt spent', 1, (int)$pendRow['attempts']);
check('D2: device row still pending', 'pending', (string)deviceRowByUuid(
    (string)$pdo->query(
        'SELECT uuid FROM acm_devices WHERE id = ' . (int)$pendRow['device_id'] . ' LIMIT 1'
    )->fetchColumn()
)['status']);
// Burn the rest of the budget (max_attempts = 5).
for ($n = 2; $n <= 5; $n++) {
    try {
        $devices->completeFull($u2, $ch2->challengeId, $ch2->challenge, $wrongSig);
    } catch (ActivationException $e) {
        // Expected generic failure.
    }
}
check('D2: challenge burned', 5, (int)challengeRowByUuid($ch2->challengeId)['attempts']);
expectCode('D2: right proof after burn fails', 'challenge_failed', function () use (
    $devices, $u2, $ch2, $keyB
) {
    $devices->completeFull($u2, $ch2->challengeId, $ch2->challenge, stubSign($keyB['priv'], $ch2->challenge));
});
// Re-begin on the same grant works (the grant was never consumed).
$ch2b = $devices->beginFull($u2, $installB, $keyB['spkiB64'], null, $grant2);
$dev2 = $devices->completeFull($u2, $ch2b->challengeId, $ch2b->challenge, stubSign($keyB['priv'], $ch2b->challenge));
check('D2: re-begin completes', 'active', $dev2->status);
check('D2: ticket activated after re-begin', 'active', ticketStatus((int)$t2['id']));
// Cross-user probing burns nothing (anti-DoS).
$ch2c = $devices->beginFull($u2, stubInstall(), stubKey()['spkiB64'], null, null);
expectCode('D2: cross-user complete is generic', 'challenge_failed', function () use (
    $devices, $userCtx, $ch2c, $keyB
) {
    $devices->completeFull($userCtx, $ch2c->challengeId, $ch2c->challenge, stubSign($keyB['priv'], $ch2c->challenge));
});
check('D2: cross-user spent no attempt', 0, (int)challengeRowByUuid($ch2c->challengeId)['attempts']);

// ── D3: device_add path ─────────────────────────────────────────────
echo "D3 device_add\n";
// Same person as D1 (active account): a fresh ticket is a new row
// because create() dedups open states only.
$person1 = (int)$pdo->query(
    'SELECT person_id FROM acm_activation_tickets WHERE id = ' . (int)$t1['id'] . ' LIMIT 1'
)->fetchColumn();
$member1 = (int)$pdo->query(
    'SELECT member_id FROM acm_activation_tickets WHERE id = ' . (int)$t1['id'] . ' LIMIT 1'
)->fetchColumn();
$t3 = $tickets->create(
    ['person_id' => $person1, 'account_type' => 'member', 'member_id' => $member1],
    $officer1
);
$seedTicketIds[] = (int)$t3['id'];
$tickets->decide((int)$t3['id'], $officer2, 'approve', '', false, ['mode' => 'separated', 'active_officers' => 2]);
$c3 = trackCode($ceremony->issueCode($officerCtx, (string)$t3['id'], CodePurpose::DeviceAdd));
check('D3: device_add issue moves ticket', 'code_issued', ticketStatus((int)$t3['id']));
$g3 = $ceremony->verifyCode($c3->selector, $c3->secret, CodePurpose::DeviceAdd, $meta);
$grant3 = $ceremony->resolveRegistrationGrant($g3->token);
$keyC = stubKey();
$installC = stubInstall();
$ch3 = $devices->beginFull($userCtx, $installC, $keyC['spkiB64'], 'E2E phone C', $grant3);
$dev3 = $devices->completeFull($userCtx, $ch3->challengeId, $ch3->challenge, stubSign($keyC['priv'], $ch3->challenge));
check('D3: second device active', 'active', $dev3->status);
check('D3: ticket closed via device_registered', 'active', ticketStatus((int)$t3['id']));
check('D3: account stays active', 1, userIsActive($a1->userId));
$deviceCId = (int)deviceRowByUuid($dev3->deviceUuid)['id'];

// ── D4: the five revocation cases (D8.3-9) ──────────────────────────
echo "D4 revocation\n";
$userRow = ['id' => $a1->userId, 'username' => $a1->username, 'role' => 'member', 'full_name' => 'E2E'];
$tokA = $refresh->issue($userRow, '127.0.0.1', 'e2e', $deviceAId);
$tokC = $refresh->issue($userRow, '127.0.0.1', 'e2e', $deviceCId);
$tokLegacy = $refresh->issue($userRow, '127.0.0.1', 'e2e');  // unlinked
$payA = $capturedPayloads[$tokA];
$payC = $capturedPayloads[$tokC];
$payLegacy = $capturedPayloads[$tokLegacy];
check(
    'D4: access session live before revoke',
    true,
    RefreshTokenService::accessSessionIsActive($mysqli, $a1->userId, $payA['fid'], $payA['jti'])
);
$report = $devices->revokeWithReport($userCtx, $dev1->deviceUuid, '');
check('D4: revoke reports one family', 1, $report['families']);
check('D4: device row revoked', 'revoked', (string)deviceRowByUuid($dev1->deviceUuid)['status']);
// Case 1: A's family is dead (refresh + access fail).
check(
    'D4.1: revoked family rotate refused',
    'revoked',
    $refresh->rotate($tokA, $payA, '127.0.0.1', 'e2e')['state']
);
check(
    'D4.1: revoked access session fails',
    false,
    RefreshTokenService::accessSessionIsActive($mysqli, $a1->userId, $payA['fid'], $payA['jti'])
);
// Case 2: B (device C) is unaffected.
check(
    'D4.2: sibling family rotates',
    'rotated',
    $refresh->rotate($tokC, $payC, '127.0.0.1', 'e2e')['state']
);
// Case 3: legacy family is unaffected.
check(
    'D4.3: legacy family rotates',
    'rotated',
    $refresh->rotate($tokLegacy, $payLegacy, '127.0.0.1', 'e2e')['state']
);
// Case 4: replay on the consumed challenge echoes CURRENT status.
$replay = $devices->completeFull($userCtx, $ch1->challengeId, $ch1->challenge, stubSign($keyA['priv'], $ch1->challenge));
check('D4.4: replay echoes revoked', 'revoked', $replay->status);
// Case 5: repeat revoke is a 200 no-op.
$again = $devices->revokeWithReport($userCtx, $dev1->deviceUuid, '');
check('D4.5: repeat revoke already=true', true, $again['already']);
check('D4.5: repeat revoke cascades nothing', 0, $again['families']);

// ── D5: legacy regression + rotation inheritance (D8.3-10) ──────────
echo "D5 refresh link\n";
$tokL2 = $refresh->issue($userRow, '127.0.0.1', 'e2e');
$r1 = $refresh->rotate($tokL2, $capturedPayloads[$tokL2], '127.0.0.1', 'e2e');
check('D5: unlinked rotates', 'rotated', $r1['state']);
$r2 = $refresh->rotate($r1['token'], $capturedPayloads[$r1['token']], '127.0.0.1', 'e2e');
check('D5: unlinked chain rotates again', 'rotated', $r2['state']);
$linkNull = $pdo->query(
    'SELECT COUNT(*) FROM api_refresh_sessions WHERE user_id = ' . $a1->userId
    . ' AND acm_device_id IS NULL'
)->fetchColumn();
check('D5: legacy sessions stay unlinked', true, (int)$linkNull >= 3);
$tokC2 = $refresh->issue($userRow, '127.0.0.1', 'e2e', $deviceCId);
$r3 = $refresh->rotate($tokC2, $capturedPayloads[$tokC2], '127.0.0.1', 'e2e');
check('D5: linked rotates', 'rotated', $r3['state']);
$inherited = $pdo->query(
    'SELECT acm_device_id FROM api_refresh_sessions WHERE session_id = '
    . $pdo->quote($capturedPayloads[$r3['token']]['jti']) . ' LIMIT 1'
)->fetchColumn();
check('D5: rotation inherits the link', $deviceCId, (int)$inherited);

// ── D6: both caps (session path, no tickets needed) ─────────────────
echo "D6 caps\n";
$capUser = seedUser($pdo, $tag . '_cap', 'member');
$capCtx = new AccountContext($capUser, 'member');
$capKeys = [];
for ($n = 0; $n < 5; $n++) {
    $k = stubKey();
    $ch = $devices->beginFull($capCtx, stubInstall(), $k['spkiB64'], null, null);
    $d = $devices->completeFull($capCtx, $ch->challengeId, $ch->challenge, stubSign($k['priv'], $ch->challenge));
    $capKeys[] = $k;
    check("D6: device {$n} active", 'active', $d->status);
}
$k6 = stubKey();
$ch6 = $devices->beginFull($capCtx, stubInstall(), $k6['spkiB64'], null, null);
expectCode('D6: 6th device on one account refused', 'device_limit', function () use (
    $devices, $capCtx, $ch6, $k6
) {
    $devices->completeFull($capCtx, $ch6->challengeId, $ch6->challenge, stubSign($k6['priv'], $ch6->challenge));
});
$sharedInstall = stubInstall();
for ($n = 0; $n < 5; $n++) {
    $uid = seedUser($pdo, $tag . "_sh{$n}", 'member');
    $uctx = new AccountContext($uid, 'member');
    $k = stubKey();
    $ch = $devices->beginFull($uctx, $sharedInstall, $k['spkiB64'], null, null);
    $devices->completeFull($uctx, $ch->challengeId, $ch->challenge, stubSign($k['priv'], $ch->challenge));
}
check(
    'D6: five accounts share one installation',
    5,
    (int)$pdo->query(
        'SELECT COUNT(*) FROM acm_devices WHERE installation_id = '
        . $pdo->quote($sharedInstall) . " AND status = 'active'"
    )->fetchColumn()
);
$uid6 = seedUser($pdo, $tag . '_sh6', 'member');
$u6 = new AccountContext($uid6, 'member');
$k66 = stubKey();
$ch66 = $devices->beginFull($u6, $sharedInstall, $k66['spkiB64'], null, null);
expectCode('D6: 6th account on one installation refused', 'device_limit', function () use (
    $devices, $u6, $ch66, $k66
) {
    $devices->completeFull($u6, $ch66->challengeId, $ch66->challenge, stubSign($k66['priv'], $ch66->challenge));
});

// ── D7: conflict rules ──────────────────────────────────────────────
echo "D7 conflicts\n";
$conUser = seedUser($pdo, $tag . '_con', 'member');
$conCtx = new AccountContext($conUser, 'member');
$conInstall = stubInstall();
$keyK1 = stubKey();
$chK1 = $devices->beginFull($conCtx, $conInstall, $keyK1['spkiB64'], null, null);
$devices->completeFull($conCtx, $chK1->challengeId, $chK1->challenge, stubSign($keyK1['priv'], $chK1->challenge));
$keyK2 = stubKey();
expectCode('D7: changed key on live installation conflicts', 'device_conflict', function () use (
    $devices, $conCtx, $conInstall, $keyK2
) {
    $devices->beginFull($conCtx, $conInstall, $keyK2['spkiB64'], null, null);
});
expectInvalid('D7: same-key re-begin after success is a clean 400', function () use (
    $devices, $conCtx, $conInstall, $keyK1
) {
    $devices->beginFull($conCtx, $conInstall, $keyK1['spkiB64'], null, null);
});
// Lost-begin-response recovery: re-begin supersedes pending, then completes.
$recInstall = stubInstall();
$keyR = stubKey();
$devices->beginFull($conCtx, $recInstall, $keyR['spkiB64'], null, null);  // response "lost"
$chR = $devices->beginFull($conCtx, $recInstall, $keyR['spkiB64'], null, null);
$dR = $devices->completeFull($conCtx, $chR->challengeId, $chR->challenge, stubSign($keyR['priv'], $chR->challenge));
check('D7: re-begin after lost response completes', 'active', $dR->status);
// Idempotent complete replay (lost complete response): same device back.
$dR2 = $devices->completeFull($conCtx, $chR->challengeId, $chR->challenge, stubSign($keyR['priv'], $chR->challenge));
check('D7: complete replay echoes device', $dR->deviceUuid, $dR2->deviceUuid);

// ── D8: login-assertion seam ────────────────────────────────────────
echo "D8 assertion seam\n";
check(
    'D8: valid assertion resolves the device',
    $deviceCId,
    $devices->findActiveForAssertion($a1->userId, $installC, $keyC['fp'])
);
check(
    'D8: wrong fingerprint resolves nothing',
    null,
    $devices->findActiveForAssertion($a1->userId, $installC, str_repeat('0', 64))
);
check(
    'D8: revoked device resolves nothing',
    null,
    $devices->findActiveForAssertion($a1->userId, $installA, $keyA['fp'])
);
$devices->touchLastSeen($deviceCId);
check(
    'D8: touch records last_seen',
    true,
    deviceRowByUuid($dev3->deviceUuid)['last_seen'] !== null
);

// ── Cleanup (best-effort, children first) ───────────────────────────
echo "cleanup\n";
try {
    $tIds = implode(',', array_map('intval', $seedTicketIds));
    $uIds = implode(',', array_map('intval', $seedUserIds));
    $pIds = implode(',', array_map('intval', $seedPersonIds));
    if ($uIds !== '') {
        $pdo->exec("DELETE FROM acm_device_challenges WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM acm_devices WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM api_refresh_sessions WHERE user_id IN ({$uIds})");
    }
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
