<?php
/**
 * ACM S9 — per-request access-proof walkthrough against a real MariaDB.
 * ----------------------------------------------------------------------
 * E2E through the real services with a stub openssl signer (S5
 * precedent): the request verifier accepts a fresh proof and
 * consumes its jti exactly once (replay collapses to invalid on
 * the wire per #33); wrong method/path/token-hash/stale clock/
 * unknown-or-revoked device all refuse with no oracle; the
 * sampled sweep drains expired claims; the session+binding read
 * returns liveness + bound + device in one lookup; the REAL
 * apiRequireAuth() (subprocess probe) demands proofs iff the
 * family is bound (missing -> ACCESS_PROOF_REQUIRED, bad ->
 * ACCESS_PROOF_INVALID), ignores proofs for unbound families
 * (byte-identical bearer), checks the session BEFORE the proof
 * (suspended + valid proof -> SESSION_REVOKED); S5 rotation is
 * unchanged (bound rotation with an S5 proof, no per-request
 * proof); S7 suspend/reactivate round-trips.
 *
 * COMMAND LINE ONLY, and destructive: it seeds officers,
 * members, persons, users, devices, sessions, jtis and audit
 * rows, then deletes them again. Disposable database only
 * (destructive_guard interlock), never production.
 *
 * Usage (dev machine, migrations through 090 applied):
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/acm_access_proof_walkthrough.php
 *
 * NOT run in CI (no database in the sandbox); the sandbox pins
 * the same behaviors statically
 * (tests/security/test_acm_s9_access_proofs.py) and the pure
 * claim vectors run in PHPUnit (acm/tests/AccessProofTest.php).
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
require_once $root . '/api/v1/core/response.php';
require_once $root . '/api/v1/core/auth.php';

use App\Services\MysqliCredentialRepository;
use App\Services\RefreshTokenService;
use SSMS\Acm\Accounts\AccountProvisioner;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Device\DeviceCrypto;
use SSMS\Acm\Device\DeviceRefreshProofs;
use SSMS\Acm\Identity\AcmPersonService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'acm_access_proof_walkthrough');

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
    $pdo->query('SELECT 1 FROM acm_refresh_proof_jtis LIMIT 1');
} catch (PDOException $e) {
    fwrite(STDERR, "migration 086 is not applied to {$dbName}\n");
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

/**
 * Run the REAL apiRequireAuth() in a subprocess. Returns
 * ['ok', uid] or ['code', WIRE_CODE] (parsed from the gate's
 * own JSON -- err() always exits 0, so the exit code means
 * nothing and stdout is the verdict).
 */
function proofProbe(string $dbName, string $token, ?string $dpop, string $method, string $uri): array
{
    $cmd = sprintf(
        'php %s %s %s %s %s %s',
        escapeshellarg(__DIR__ . '/acm_access_proof_probe.php'),
        escapeshellarg($dbName),
        escapeshellarg($token),
        escapeshellarg($dpop ?? '-'),
        escapeshellarg($method),
        escapeshellarg($uri)
    );
    $out = [];
    exec($cmd, $out);
    $text = implode("\n", $out);
    if (preg_match('/PROBE_OK uid=(\d+)/', $text, $m)) {
        return ['ok', (int)$m[1]];
    }
    foreach ($out as $line) {
        $line = trim($line);
        if (str_starts_with($line, '{')) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return ['code', (string)($decoded['code'] ?? $decoded['message'] ?? 'unparseable')];
            }
        }
    }
    return ['unparseable', substr($text, -200)];
}

// Refresh issuer with a capturing test signer (prod shape: the
// route's signer mints REAL refresh JWTs, and boundSession feeds
// them to apiRefreshAccessBinding exactly like login does -- an
// opaque stand-in token cannot parse and fatals the run).
$capturedPayloads = [];
$refresh = new RefreshTokenService(
    $mysqli,
    function (
        int $uid, string $username, string $role, string $fullName,
        int $authzVersion, string $sessionId, string $familyId, int $expiresAt
    ) use (&$capturedPayloads): string {
        $token = createRefreshToken(
            $uid,
            $username,
            $role,
            $fullName,
            $authzVersion,
            $sessionId,
            $familyId,
            $expiresAt
        );
        $capturedPayloads[$token] = ['jti' => $sessionId, 'fid' => $familyId, 'uid' => $uid];
        return $token;
    },
    3600
);

// ── Seeded world ────────────────────────────────────────────────────
$tag = 'e2e' . substr(md5(microtime(true) . getmypid()), 0, 8);
$seedUserIds = [];
$seedPersonIds = [];
$seedMemberIds = [];
$seedGuardianIds = [];
$seedDeviceIds = [];

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
$officerCtx = new AccountContext($officer1, 'accounts_office');

$memberStmt = $pdo->prepare(
    'INSERT INTO members (full_name_am, student_name, father_name) VALUES (:am, :st, :fa)'
);
$guardianStmt = $pdo->prepare('INSERT INTO guardians (display_name) VALUES (:n)');
$personNo = 0;
$persons = new AcmPersonService($pdo);

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

/** Stub P-256 keypair (S5 shape): private handle + SPKI + fingerprint. */
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

/** Live S4 device row carrying the stub key. Returns the row id. */
function seedDevice(int $userId, array $stub): int
{
    global $pdo, $seedDeviceIds;
    $uuid = AcmPersonService::uuid4();
    $stmt = $pdo->prepare(
        'INSERT INTO acm_devices (uuid, user_id, installation_id, pubkey, fingerprint, alg, status)'
        . " VALUES (:uuid, :u, :inst, :pk, :fp, 'ES256', 'active')"
    );
    $stmt->execute([
        ':uuid' => $uuid, ':u' => $userId,
        ':inst' => 'e2e-inst-' . substr(md5($uuid), 0, 16),
        ':pk' => $stub['spkiB64'], ':fp' => $stub['fp'],
    ]);
    $id = (int)$pdo->lastInsertId();
    $seedDeviceIds[] = $id;
    return $id;
}

function b64u(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** Build a per-request DPoP proof exactly like the app (DER ECDSA). */
function stubRequestProof($priv, string $token, string $htm, string $htu, int $iat, array $payload = []): string
{
    $details = openssl_pkey_get_details($priv);
    $jwk = [
        'kty' => 'EC', 'crv' => 'P-256',
        'x' => b64u(str_pad((string)$details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
        'y' => b64u(str_pad((string)$details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
    ];
    $h = ['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $jwk];
    $p = array_merge(
        [
            'htm' => $htm, 'htu' => $htu, 'iat' => $iat,
            'jti' => bin2hex(random_bytes(16)),
            'ath' => DeviceCrypto::base64UrlEncode(hash('sha256', $token, true)),
        ],
        $payload
    );
    $input = b64u((string)json_encode($h)) . '.' . b64u((string)json_encode($p));
    if (!openssl_sign($input, $sig, $priv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Stub DPoP signing failed.');
    }
    return $input . '.' . b64u($sig);
}

/** S5 rotation-shaped proof (no ath) for the refresh regression. */
function stubRotationProof($priv, string $htu, int $iat): string
{
    $details = openssl_pkey_get_details($priv);
    $jwk = [
        'kty' => 'EC', 'crv' => 'P-256',
        'x' => b64u(str_pad((string)$details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
        'y' => b64u(str_pad((string)$details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
    ];
    $h = ['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $jwk];
    $p = ['htm' => 'POST', 'htu' => $htu, 'iat' => $iat, 'jti' => bin2hex(random_bytes(16))];
    $input = b64u((string)json_encode($h)) . '.' . b64u((string)json_encode($p));
    if (!openssl_sign($input, $sig, $priv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Stub rotation signing failed.');
    }
    return $input . '.' . b64u($sig);
}

/** Bound family session + bound access token (the login shape). */
function boundSession(RefreshTokenService $refresh, int $userId, string $username, int $deviceId): array
{
    global $capturedPayloads, $pdo;
    $tok = $refresh->issue(
        ['id' => $userId, 'username' => $username, 'role' => 'member', 'full_name' => 'E2E'],
        '127.0.0.1', 'e2e', $deviceId, true
    );
    $av = (int)($pdo->query('SELECT authorization_version FROM users WHERE id = ' . $userId)->fetchColumn() ?: 1);
    $access = createToken(
        $userId, $username, 'member', 'E2E', API_TOKEN_EXPIRY, $av,
        apiRefreshAccessBinding($tok, $userId)
    );
    return [$tok, $capturedPayloads[$tok], $access];
}

/** Legacy unbound session + access token (no device, no flag). */
function unboundSession(RefreshTokenService $refresh, int $userId, string $username): array
{
    global $capturedPayloads, $pdo;
    $tok = $refresh->issue(
        ['id' => $userId, 'username' => $username, 'role' => 'member', 'full_name' => 'E2E'],
        '127.0.0.3', 'e2e'
    );
    $av = (int)($pdo->query('SELECT authorization_version FROM users WHERE id = ' . $userId)->fetchColumn() ?: 1);
    $access = createToken(
        $userId, $username, 'member', 'E2E', API_TOKEN_EXPIRY, $av,
        apiRefreshAccessBinding($tok, $userId)
    );
    return [$tok, $capturedPayloads[$tok], $access];
}

function jtiCount(int $deviceId): int
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM acm_refresh_proof_jtis WHERE device_id = :d');
    $stmt->execute([':d' => $deviceId]);
    return (int)$stmt->fetchColumn();
}

function userExists(int $userId): bool
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :u LIMIT 1');
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchColumn() !== false;
}

$seedA = seedPerson();
$u1 = seedActiveAccount($tag . '_u1', (int)$seedA['person']['id'], $officer1);
$keyA = stubKey();
$devA = seedDevice($u1, $keyA);
[$tokB1, $payB1, $accessB1] = boundSession($refresh, $u1, $tag . '_u1', $devA);

$seedB = seedPerson();
$u2 = seedActiveAccount($tag . '_u2', (int)$seedB['person']['id'], $officer1);
$keyB = stubKey();
$devB = seedDevice($u2, $keyB);
[$tokB2, $payB2, $accessB2] = boundSession($refresh, $u2, $tag . '_u2', $devB);

$seedC = seedPerson();
$u3 = seedActiveAccount($tag . '_u3', (int)$seedC['person']['id'], $officer1);
[$tokU3, $payU3, $accessU3] = unboundSession($refresh, $u3, $tag . '_u3');

$URI = '/api/v1/grades/summary';
$HTU = 'https://localhost' . $URI;
$verifyRequest = DeviceRefreshProofs::requestVerifier($mysqli);

// ── P1: verifier matrix (in-process, REAL verifier) ─────────────────
echo "P1 verifier\n";
$now = time();
$pValid = stubRequestProof($keyA['priv'], $accessB1, 'GET', $HTU, $now);
check('P1: fresh proof verifies',
    'ok', $verifyRequest($mysqli, $devA, $pValid, $accessB1, 'GET', $URI));
check('P1: jti claimed exactly once', 1, jtiCount($devA));
check('P1: same proof replays',
    'replay', $verifyRequest($mysqli, $devA, $pValid, $accessB1, 'GET', $URI));
check('P1: replay claims nothing new', 1, jtiCount($devA));
check('P1: wrong method refuses', 'invalid', $verifyRequest(
    $mysqli, $devA, stubRequestProof($keyA['priv'], $accessB1, 'POST', $HTU, $now),
    $accessB1, 'GET', $URI));
check('P1: wrong path refuses', 'invalid', $verifyRequest(
    $mysqli, $devA, stubRequestProof($keyA['priv'], $accessB1, 'GET', 'https://localhost/api/v1/other', $now),
    $accessB1, 'GET', $URI));
check('P1: another token ath refuses', 'invalid', $verifyRequest(
    $mysqli, $devA, stubRequestProof($keyA['priv'], $accessB2, 'GET', $HTU, $now),
    $accessB1, 'GET', $URI));
check('P1: stale clock refuses', 'invalid', $verifyRequest(
    $mysqli, $devA, stubRequestProof($keyA['priv'], $accessB1, 'GET', $HTU, $now - 3601),
    $accessB1, 'GET', $URI));
check('P1: future clock refuses', 'invalid', $verifyRequest(
    $mysqli, $devA, stubRequestProof($keyA['priv'], $accessB1, 'GET', $HTU, $now + 3601),
    $accessB1, 'GET', $URI));
check('P1: unknown device refuses', 'invalid', $verifyRequest(
    $mysqli, 999999999, stubRequestProof($keyA['priv'], $accessB1, 'GET', $HTU, $now),
    $accessB1, 'GET', $URI));
check('P1: other-device key refuses', 'invalid', $verifyRequest(
    $mysqli, $devA, stubRequestProof($keyB['priv'], $accessB1, 'GET', $HTU, $now),
    $accessB1, 'GET', $URI));
$pdo->exec('UPDATE acm_devices SET status = \'revoked\' WHERE id = ' . $devB);
check('P1: revoked device refuses', 'invalid', $verifyRequest(
    $mysqli, $devB, stubRequestProof($keyB['priv'], $accessB2, 'GET', $HTU, $now),
    $accessB2, 'GET', $URI));
$pdo->exec('UPDATE acm_devices SET status = \'active\' WHERE id = ' . $devB);
check('P1: empty proof refuses', 'invalid',
    $verifyRequest($mysqli, $devA, '', $accessB1, 'GET', $URI));
check('P1: garbage proof refuses', 'invalid',
    $verifyRequest($mysqli, $devA, 'garbage!!', $accessB1, 'GET', $URI));
// Sweep: expired claims drain under real verified traffic (bounded
// loop: 1/100 sampling makes 2000 consecutive misses ~2e-9).
$pdo->exec(
    "INSERT INTO acm_refresh_proof_jtis (jti, device_id, expires_at) VALUES "
    . "('e2e-expired-1-{$tag}', {$devA}, DATE_SUB(NOW(), INTERVAL 1 HOUR)),"
    . "('e2e-expired-2-{$tag}', {$devA}, DATE_SUB(NOW(), INTERVAL 1 HOUR)),"
    . "('e2e-expired-3-{$tag}', {$devA}, DATE_SUB(NOW(), INTERVAL 1 HOUR))"
);
$drained = false;
for ($i = 0; $i < 2000; $i++) {
    $sweepProof = stubRequestProof($keyA['priv'], $accessB1, 'GET', $HTU, time());
    $verifyRequest($mysqli, $devA, $sweepProof, $accessB1, 'GET', $URI);
    $left = (int)$pdo->query(
        "SELECT COUNT(*) FROM acm_refresh_proof_jtis WHERE jti LIKE 'e2e-expired-%-{$tag}'"
    )->fetchColumn();
    if ($left === 0) {
        $drained = true;
        break;
    }
}
check('P1: sweep drains expired claims', true, $drained);

// ── P2: session+binding read (in-process, REAL lookup) ──────────────
echo "P2 binding read\n";
$bindB = RefreshTokenService::accessSessionProofBinding($mysqli, $u1, $payB1['fid'], $payB1['jti']);
check('P2: bound row reports bound', true, ($bindB['bound'] ?? null) === true);
check('P2: bound row reports device', $devA, (int)($bindB['device_id'] ?? 0));
$bindU = RefreshTokenService::accessSessionProofBinding($mysqli, $u3, $payU3['fid'], $payU3['jti']);
check('P2: unbound row reports unbound', false, ($bindU['bound'] ?? null) === true);
$u4 = seedUser($pdo, $tag . '_u4', 'member', true);
[$tokU4, $payU4] = unboundSession($refresh, $u4, $tag . '_u4');
(new MysqliCredentialRepository($mysqli))->revokeAllRefreshSessions($u4);
check('P2: revoked session reads inactive', null,
    RefreshTokenService::accessSessionProofBinding($mysqli, $u4, $payU4['fid'], $payU4['jti']));
check('P2: malformed ids read inactive', null,
    RefreshTokenService::accessSessionProofBinding($mysqli, $u1, 'zz', $payB1['jti']));
check('P2: wrong owner reads inactive', null,
    RefreshTokenService::accessSessionProofBinding($mysqli, $u2, $payB1['fid'], $payB1['jti']));

// ── P3: bound enforcement through the REAL gate ─────────────────────
echo "P3 gate bound\n";
[$st, $uid] = proofProbe($dbName, $accessB1,
    stubRequestProof($keyA['priv'], $accessB1, 'GET', $HTU, time()), 'GET', $URI);
check('P3: bound + valid proof passes', ['ok', $u1], [$st, $uid]);
$pReplayGate = stubRequestProof($keyA['priv'], $accessB1, 'GET', $HTU, time());
proofProbe($dbName, $accessB1, $pReplayGate, 'GET', $URI);
check('P3: bound + replayed proof collapses to INVALID',
    ['code', 'ACCESS_PROOF_INVALID'], proofProbe($dbName, $accessB1, $pReplayGate, 'GET', $URI));
check('P3: bound + missing proof needs proof',
    ['code', 'ACCESS_PROOF_REQUIRED'], proofProbe($dbName, $accessB1, null, 'GET', $URI));
check('P3: bound + wrong-key proof refuses',
    ['code', 'ACCESS_PROOF_INVALID'], proofProbe($dbName, $accessB1,
        stubRequestProof($keyB['priv'], $accessB1, 'GET', $HTU, time()), 'GET', $URI));
check('P3: bound + wrong-path proof refuses',
    ['code', 'ACCESS_PROOF_INVALID'], proofProbe($dbName, $accessB1,
        stubRequestProof($keyA['priv'], $accessB1, 'GET', 'https://localhost/api/v1/other', time()),
        'GET', $URI));
check('P3: bound + cross-token proof refuses',
    ['code', 'ACCESS_PROOF_INVALID'], proofProbe($dbName, $accessB1,
        stubRequestProof($keyA['priv'], $accessB2, 'GET', $HTU, time()), 'GET', $URI));
// Verb matrix: a POST proof passes a POST call.
$URI_SAVE = '/api/v1/grades/save';
check('P3: POST proof passes POST call', ['ok', $u1], proofProbe($dbName, $accessB1,
    stubRequestProof($keyA['priv'], $accessB1, 'POST', 'https://localhost' . $URI_SAVE, time()),
    'POST', $URI_SAVE));

// ── P4: unbound bearer is byte-identical ────────────────────────────
echo "P4 gate unbound\n";
check('P4: unbound + no proof passes', ['ok', $u3],
    proofProbe($dbName, $accessU3, null, 'GET', $URI));
check('P4: unbound + garbage proof ignored', ['ok', $u3],
    proofProbe($dbName, $accessU3, 'garbage!!', 'GET', $URI));

// ── P5: session-before-proof order + refresh bypass ─────────────────
echo "P5 order + refresh\n";
$sessionRevoker = static function (int $userId) use ($mysqli): void {
    (new MysqliCredentialRepository($mysqli))->revokeAllRefreshSessions($userId);
};
$lifecycle = new AccountProvisioner($pdo, $sessionRevoker);
$lifecycle->suspend($u1, 'P5 order suspension', $officerCtx);
check('P5: suspended + valid proof still SESSION_REVOKED',
    ['code', 'SESSION_REVOKED'], proofProbe($dbName, $accessB1,
        stubRequestProof($keyA['priv'], $accessB1, 'GET', $HTU, time()), 'GET', $URI));
// Refresh of a bound family needs only its S5 proof (the refresh
// route never calls the gate -- no per-request proof involved).
$rotationVerifier = DeviceRefreshProofs::rotationVerifier($mysqli);
$rotProof = stubRotationProof(
    $keyB['priv'], 'https://localhost/api/v1/auth/refresh-token', time());
$rotated = $refresh->rotate($tokB2, $payB2, '127.0.0.1', 'e2e', $rotProof, $rotationVerifier);
check('P5: bound rotation with S5 proof only', 'rotated', $rotated['state']);

// ── P6: S5 regression + full circle ─────────────────────────────────
echo "P6 regression\n";
$rotCheck = $rotationVerifier(
    $mysqli, $devB,
    stubRotationProof($keyB['priv'], 'https://localhost/api/v1/auth/refresh-token', time()));
check('P6: rotation verifier still accepts S5 proofs', 'ok', $rotCheck);
$lifecycle->reactivate($u1, 'P6 full circle', $officerCtx);
[$tokB1b, $payB1b, $accessB1b] = boundSession($refresh, $u1, $tag . '_u1', $devA);
check('P6: reactivated + fresh proof passes', ['ok', $u1], proofProbe($dbName, $accessB1b,
    stubRequestProof($keyA['priv'], $accessB1b, 'GET', $HTU, time()), 'GET', $URI));

// ── cleanup ─────────────────────────────────────────────────────────
echo "cleanup\n";
try {
    if ($seedDeviceIds !== []) {
        $dIds = implode(',', array_map('intval', $seedDeviceIds));
        $pdo->exec("DELETE FROM acm_refresh_proof_jtis WHERE device_id IN ({$dIds})");
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
