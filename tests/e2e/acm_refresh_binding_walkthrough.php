<?php
/**
 * ACM S5 — refresh-binding walkthrough against a real MariaDB.
 * ------------------------------------------------------------
 * E2E through the real services with a stub app client carrying
 * a REAL ECDSA signer: bound issue at login-shape, DPoP proof
 * rotation + inheritance, proof_required/invalid WITHOUT family
 * death, replay rejection, proof non-consumption on failed
 * rotations, unbound + legacy-exchange regression, the
 * bound-requires-link invariant, console counts, key-loss
 * convergence, and revoke scoping. RS256 is covered in PHPUnit
 * (RefreshProofTest); this walkthrough is ES256 like S4's.
 *
 * COMMAND LINE ONLY, and destructive: it seeds a user, devices
 * and refresh sessions, then deletes them again. Disposable
 * database only (destructive_guard interlock), never production.
 *
 * Usage (dev machine, migrations through 086 applied):
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/acm_refresh_binding_walkthrough.php
 *
 * NOT run in CI (no database in the sandbox); the sandbox pins the
 * same behaviors statically (tests/security/test_acm_s5_enforcement.py)
 * and the pure crypto units run in PHPUnit (RefreshProofTest).
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
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Device\DeviceCrypto;
use SSMS\Acm\Device\DeviceRefreshProofs;
use SSMS\Acm\Device\DeviceService;
use SSMS\Acm\Identity\AcmPersonService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'acm_refresh_binding_walkthrough');

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
    $pdo->query('SELECT 1 FROM acm_refresh_proof_jtis LIMIT 1');
    $pdo->query('SELECT acm_bound FROM api_refresh_sessions LIMIT 1');
} catch (PDOException $e) {
    fwrite(STDERR, "migrations 084/086 are not applied to {$dbName}\n");
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

// Refresh service with a capturing test issuer (same shape as S4's).
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
$verifier = DeviceRefreshProofs::rotationVerifier($mysqli);

$tag = 'e2eb' . substr(md5(microtime(true) . getmypid()), 0, 8);
$seedUserIds = [];

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

function b64u(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** Build a compact DPoP proof exactly like the app (DER ECDSA). */
function stubDpop($priv, string $htu, int $iat, ?string $jti = null, array $header = [], array $payload = []): string
{
    $details = openssl_pkey_get_details($priv);
    $jwk = [
        'kty' => 'EC', 'crv' => 'P-256',
        'x' => b64u(str_pad((string)$details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
        'y' => b64u(str_pad((string)$details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
    ];
    $h = array_merge(['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $jwk], $header);
    $p = array_merge(
        ['htm' => 'POST', 'htu' => $htu, 'iat' => $iat, 'jti' => $jti ?? bin2hex(random_bytes(16))],
        $payload
    );
    $input = b64u((string)json_encode($h)) . '.' . b64u((string)json_encode($p));
    if (!openssl_sign($input, $sig, $priv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Stub DPoP signing failed.');
    }
    return $input . '.' . b64u($sig);
}

function deviceIdByUuid(PDO $pdo, string $uuid): int
{
    return (int)$pdo->query('SELECT id FROM acm_devices WHERE uuid = ' . $pdo->quote($uuid) . ' LIMIT 1')->fetchColumn();
}

function sessionRow(PDO $pdo, string $sessionId): ?array
{
    $row = $pdo->query(
        'SELECT acm_device_id, acm_bound FROM api_refresh_sessions WHERE session_id = '
        . $pdo->quote($sessionId) . ' LIMIT 1'
    )->fetch();
    return $row === false ? null : $row;
}

function jtiClaimed(PDO $pdo, string $jti): bool
{
    return (int)$pdo->query(
        'SELECT COUNT(*) FROM acm_refresh_proof_jtis WHERE jti = ' . $pdo->quote($jti)
    )->fetchColumn() === 1;
}

// ── Seeded world ────────────────────────────────────────────────────
$userId = seedUser($pdo, $tag . '_u', 'member');
$userCtx = new AccountContext($userId, 'member');
$userRow = ['id' => $userId, 'username' => $tag . '_u', 'role' => 'member', 'full_name' => 'E2E'];
$keyA = stubKey();
$chA = $devices->beginFull($userCtx, stubInstall(), $keyA['spkiB64'], null, null);
$devA = $devices->completeFull($userCtx, $chA->challengeId, $chA->challenge, stubSign($keyA['priv'], $chA->challenge));
$deviceAId = deviceIdByUuid($pdo, $devA->deviceUuid);
$HTU = 'https://e2e.test/api/v1/auth/refresh-token';

// ── E0: 086 seeds ───────────────────────────────────────────────────
echo "E0 seeds\n";
check(
    'E0: skew seed is 300',
    '300',
    (string)$pdo->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'acm.refresh_proof_skew_seconds' LIMIT 1"
    )->fetchColumn()
);
check(
    'E0: jti TTL seed is 900',
    '900',
    (string)$pdo->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'acm.refresh_proof_jti_ttl_seconds' LIMIT 1"
    )->fetchColumn()
);

// ── E1: bound issue + proof rotation + inheritance ──────────────────
echo "E1 bind and rotate\n";
check(
    'E1: fresh device last_seen null',
    null,
    $pdo->query('SELECT last_seen FROM acm_devices WHERE id = ' . $deviceAId . ' LIMIT 1')->fetchColumn()
);
$tokB = $refresh->issue($userRow, '127.0.0.1', 'e2e', $deviceAId, true);
$payB = $capturedPayloads[$tokB];
$rowB = sessionRow($pdo, $payB['jti']);
check('E1: bound issue records the link', $deviceAId, (int)$rowB['acm_device_id']);
check('E1: bound issue sets the flag', 1, (int)$rowB['acm_bound']);
$proofB = stubDpop($keyA['priv'], $HTU, time());
$rB = $refresh->rotate($tokB, $payB, '127.0.0.1', 'e2e', $proofB, $verifier);
check('E1: bound rotation with valid proof', 'rotated', $rB['state']);
$rowB2 = sessionRow($pdo, $capturedPayloads[$rB['token']]['jti']);
check('E1: rotation inherits the link', $deviceAId, (int)$rowB2['acm_device_id']);
check('E1: rotation inherits the flag', 1, (int)$rowB2['acm_bound']);
check(
    'E1: bound rotation touches last_seen',
    true,
    $pdo->query('SELECT last_seen FROM acm_devices WHERE id = ' . $deviceAId . ' LIMIT 1')->fetchColumn() !== null
);
$tokLive = $rB['token'];
$payLive = $capturedPayloads[$tokLive];

// ── E2: missing proof refuses, family survives ──────────────────────
echo "E2 proof required\n";
check(
    'E2: bound rotation without proof',
    'proof_required',
    $refresh->rotate($tokLive, $payLive, '127.0.0.1', 'e2e')['state']
);
$rLive = $refresh->rotate($tokLive, $payLive, '127.0.0.1', 'e2e', stubDpop($keyA['priv'], $HTU, time()), $verifier);
check('E2: same token rotates after the refusal', 'rotated', $rLive['state']);
$tokLive = $rLive['token'];
$payLive = $capturedPayloads[$tokLive];

// ── E3: invalid proofs refuse, family survives ──────────────────────
echo "E3 proof invalid\n";
$keyWrong = stubKey();
$now = time();
$badProofs = [
    'wrong key' => stubDpop($keyWrong['priv'], $HTU, $now),
    'stale iat' => stubDpop($keyA['priv'], $HTU, $now - 600),
    'future iat' => stubDpop($keyA['priv'], $HTU, $now + 600),
    'wrong route' => stubDpop($keyA['priv'], 'https://e2e.test/api/v1/auth/login', $now),
    'malformed' => 'not-a-proof',
];
foreach ($badProofs as $label => $bad) {
    check(
        "E3: {$label} proof refuses",
        'proof_invalid',
        $refresh->rotate($tokLive, $payLive, '127.0.0.1', 'e2e', $bad, $verifier)['state']
    );
}
$rLive = $refresh->rotate($tokLive, $payLive, '127.0.0.1', 'e2e', stubDpop($keyA['priv'], $HTU, time()), $verifier);
check('E3: family alive through every refusal', 'rotated', $rLive['state']);
$tokLive = $rLive['token'];
$payLive = $capturedPayloads[$tokLive];

// ── E4: replay + non-consumption ────────────────────────────────────
echo "E4 replay\n";
$proofP = stubDpop($keyA['priv'], $HTU, time());
check(
    'E4: wrong token fails before the proof burns',
    'invalid',
    $refresh->rotate('e2e-wrong-token', $payLive, '127.0.0.1', 'e2e', $proofP, $verifier)['state']
);
$rP = $refresh->rotate($tokLive, $payLive, '127.0.0.1', 'e2e', $proofP, $verifier);
check('E4: same proof works after the failed rotation', 'rotated', $rP['state']);
$tokLive = $rP['token'];
$payLive = $capturedPayloads[$tokLive];
check(
    'E4: replayed proof refuses',
    'proof_invalid',
    $refresh->rotate($tokLive, $payLive, '127.0.0.1', 'e2e', $proofP, $verifier)['state']
);
$rLive = $refresh->rotate($tokLive, $payLive, '127.0.0.1', 'e2e', stubDpop($keyA['priv'], $HTU, time()), $verifier);
check('E4: fresh proof rotates after the replay', 'rotated', $rLive['state']);
$tokLive = $rLive['token'];
$payLive = $capturedPayloads[$tokLive];

// ── E5: unbound + legacy regression ─────────────────────────────────
echo "E5 unbound regression\n";
$tokU = $refresh->issue($userRow, '127.0.0.1', 'e2e');
check(
    'E5: unlinked rotates proof-free',
    'rotated',
    $refresh->rotate($tokU, $capturedPayloads[$tokU], '127.0.0.1', 'e2e')['state']
);
$tokL = $refresh->issue($userRow, '127.0.0.1', 'e2e', $deviceAId, false);
$payL = $capturedPayloads[$tokL];
check(
    'E5: linked-unbound rotates proof-free',
    'rotated',
    $refresh->rotate($tokL, $payL, '127.0.0.1', 'e2e')['state']
);
$tokL2 = $refresh->issue($userRow, '127.0.0.1', 'e2e', $deviceAId, false);
$ignoredJti = 'e2e-ignored-' . $tag;
$rIgnored = $refresh->rotate(
    $tokL2, $capturedPayloads[$tokL2], '127.0.0.1', 'e2e',
    stubDpop($keyA['priv'], $HTU, time(), $ignoredJti), $verifier
);
check('E5: unbound ignores a presented proof', 'rotated', $rIgnored['state']);
check('E5: ignored proof claims no jti', false, jtiClaimed($pdo, $ignoredJti));
check(
    'E5: legacy exchange rotates once',
    'rotated',
    $refresh->rotate('e2e-legacy-' . $tag, ['uid' => $userId], '127.0.0.1', 'e2e')['state']
);
check(
    'E5: legacy exchange replays to reused',
    'reused',
    $refresh->rotate('e2e-legacy-' . $tag, ['uid' => $userId], '127.0.0.1', 'e2e')['state']
);

// ── E7: bound-requires-link invariant ───────────────────────────────
echo "E7 invariant\n";
expectInvalid('E7: bound without link throws', function () use ($refresh, $userRow) {
    $refresh->issue($userRow, '127.0.0.1', 'e2e', null, true);
});

// ── E8: console counts ──────────────────────────────────────────────
echo "E8 console counts\n";
$counts = $devices->boundFamilyCounts($userId);
check('E8: one live bound family on device A', 1, (int)($counts[$devA->deviceUuid] ?? -1));

// ── E9: key-loss convergence (no server recovery flow) ──────────────
echo "E9 key loss\n";
$keyB = stubKey();
$chB = $devices->beginFull($userCtx, stubInstall(), $keyB['spkiB64'], null, null);
$devB = $devices->completeFull($userCtx, $chB->challengeId, $chB->challenge, stubSign($keyB['priv'], $chB->challenge));
$deviceBId = deviceIdByUuid($pdo, $devB->deviceUuid);
$tokN = $refresh->issue($userRow, '127.0.0.1', 'e2e', $deviceBId, true);
$payN = $capturedPayloads[$tokN];
check(
    'E9: old-key proof on the new family refuses',
    'proof_invalid',
    $refresh->rotate($tokN, $payN, '127.0.0.1', 'e2e', stubDpop($keyA['priv'], $HTU, time()), $verifier)['state']
);
check(
    'E9: new-key proof rotates the new family',
    'rotated',
    $refresh->rotate($tokN, $payN, '127.0.0.1', 'e2e', stubDpop($keyB['priv'], $HTU, time()), $verifier)['state']
);

// ── E6: revoke scoping (last: kills device A families) ─────────────
echo "E6 revoke\n";
$report = $devices->revokeWithReport($userCtx, $devA->deviceUuid, 'E2E S5 revoke');
check('E6: revoke cascades all three device A families', 3, $report['families']);
check(
    'E6: bound family rotates to revoked even with proof',
    'revoked',
    $refresh->rotate($tokLive, $payLive, '127.0.0.1', 'e2e', stubDpop($keyA['priv'], $HTU, time()), $verifier)['state']
);
check(
    'E6: access session fails',
    false,
    RefreshTokenService::accessSessionIsActive($mysqli, $userId, $payLive['fid'], $payLive['jti'])
);
$tokN2 = $refresh->issue($userRow, '127.0.0.1', 'e2e', $deviceBId, true);
check(
    'E6: device B family survives and rotates',
    'rotated',
    $refresh->rotate($tokN2, $capturedPayloads[$tokN2], '127.0.0.1', 'e2e', stubDpop($keyB['priv'], $HTU, time()), $verifier)['state']
);
$countsAfter = $devices->boundFamilyCounts($userId);
check('E6: device A counts drop to zero', 0, (int)($countsAfter[$devA->deviceUuid] ?? -1));
check('E6: device B still counts bound', true, (int)($countsAfter[$devB->deviceUuid] ?? 0) >= 1);

// ── Cleanup (best-effort, children first) ───────────────────────────
echo "cleanup\n";
try {
    $uIds = implode(',', array_map('intval', $seedUserIds));
    if ($uIds !== '') {
        $pdo->exec("DELETE FROM acm_device_challenges WHERE user_id IN ({$uIds})");
        $pdo->exec(
            'DELETE FROM acm_refresh_proof_jtis WHERE device_id IN'
            . " (SELECT id FROM acm_devices WHERE user_id IN ({$uIds}))"
        );
        $pdo->exec("DELETE FROM acm_devices WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM api_refresh_sessions WHERE user_id IN ({$uIds})");
        $pdo->exec("DELETE FROM users WHERE id IN ({$uIds})");
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
