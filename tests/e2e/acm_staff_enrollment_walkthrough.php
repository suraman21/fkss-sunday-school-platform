<?php
/**
 * ACM S10 — staff enrollment walkthrough against a real MariaDB.
 * ------------------------------------------------------------
 * Request-approve E2E through the real services: staffer files a
 * request (T1: self-request ok, dupes/unknown/deactivated refused,
 * expiry flips); an officer decides it (T2: no self-approval,
 * approve mints a `staff_enroll` grant with 0-sentinels, reject/
 * cancel/double-decide); the grant resolves + registers (T3: user
 * resolved, ticket_id 0, begin accepts; malformed ticket links
 * refused both ways); the full ceremony completes (T4: real ECDSA
 * sign -> device active, request + grant consumed, replay fails;
 * family activation regression green); the resolver fails closed
 * (T5: post-approval cancel/deactivate/revoke kill the grant);
 * #38 closure through the REAL staff endpoints (T6: toggle/save
 * 1→0 revokes devices + sessions + requests + live grants,
 * neighbors survive, 0→1 revives nothing, delete closes all);
 * lifecycle refusals unchanged (T7: S6/S7/S8 refuse staff exactly
 * as today; session-path re-register after revoke-all works).
 *
 * COMMAND LINE ONLY, and destructive: it seeds officers, staff,
 * members, persons, tickets, requests, devices and refresh
 * sessions, then deletes them again. Disposable database only
 * (destructive_guard interlock), never production.
 *
 * Usage (dev machine, migrations through 091 applied):
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/acm_staff_enrollment_walkthrough.php
 *
 * NOT run in CI (no database in the sandbox); the sandbox pins the
 * same behaviors statically
 * (tests/security/test_acm_s10_staff_enrollment.py).
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
use SSMS\Acm\Enrollment\StaffEnrollmentRequestService;
use SSMS\Acm\Identity\AcmPersonService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'acm_staff_enrollment_walkthrough');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "mysqli connect failed\n");
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

/** Execute a REAL staff endpoint file in a subprocess (S8 probe). */
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
$ceremony = new ActivationService($pdo, $tickets, $persons);
$requests = new StaffEnrollmentRequestService($pdo);

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
$tag = 'e2es' . substr(md5(microtime(true) . getmypid()), 0, 8);
$seedUserIds = [];
$seedPersonIds = [];
$seedTicketIds = [];
$seedMemberIds = [];
$seedGuardianIds = [];
$seedSelectors = [];
$seedRequestIds = [];

function seedUser(PDO $pdo, string $username, string $role, bool $active = true): int
{
    global $seedUserIds;
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, full_name, role, password_hash, is_active)'
        . ' VALUES (:u, :fn, :r, :ph, :a)'
    );
    $stmt->execute([
        ':u' => $username, ':fn' => 'E2E ' . $username, ':r' => $role,
        ':ph' => password_hash('e2e-unused-' . $username, PASSWORD_DEFAULT),
        ':a' => $active ? 1 : 0,
    ]);
    $id = (int)$pdo->lastInsertId();
    $seedUserIds[] = $id;
    return $id;
}

$officer1 = seedUser($pdo, $tag . '_off1', 'accounts_office');
$officer2 = seedUser($pdo, $tag . '_off2', 'accounts_office');
$officerCtx = new AccountContext($officer1, 'accounts_office');
// Guard probes run the REAL staff endpoints: toggle/save/delete
// all require super_admin powers for these targets.
$boss = seedUser($pdo, $tag . '_boss', 'super_admin');
$staffer = seedUser($pdo, $tag . '_staff', 'edu_dept');
$staffCtx = new AccountContext($staffer, 'edu_dept');
$neighbor = seedUser($pdo, $tag . '_nbr', 'info_dept');

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
        'phone' => '0922' . str_pad((string)(200000 + $personNo), 6, '0', STR_PAD_LEFT),
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

function trackRequest(array $row): array
{
    global $seedRequestIds;
    $seedRequestIds[] = (int)$row['id'];
    return $row;
}

$seedGrantIds = [];
$seedGrantTokens = [];

function trackGrantId(int $id): void
{
    global $seedGrantIds;
    if ($id > 0) {
        $seedGrantIds[] = $id;
    }
}

/** Family grants surface as tokens only: fingerprint-lookup at cleanup. */
function trackGrantToken(string $token): void
{
    global $seedGrantTokens;
    if ($token !== '') {
        $seedGrantTokens[] = $token;
    }
}

function requestStatus(int $id): string
{
    global $requests;
    return (string)$requests->get($id)['status'];
}

function grantRow(int $id): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM acm_activation_grants WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function deviceRows(int $userId): array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT uuid, status FROM acm_devices WHERE user_id = :u');
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
}

function liveSessions(int $userId): int
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM api_refresh_sessions WHERE user_id = :u AND revoked_at IS NULL'
    );
    $stmt->execute([':u' => $userId]);
    return (int)$stmt->fetchColumn();
}

function seedSession(int $userId, string $role = 'edu_dept'): void
{
    global $refresh, $tag;
    $refresh->issue(
        ['id' => $userId, 'username' => $tag . '_u' . $userId, 'role' => $role, 'full_name' => 'E2E session'],
        '127.0.0.1',
        'e2e'
    );
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

$minter = static function (array $req) use ($ceremony): array {
    return $ceremony->mintStaffEnrollGrant($req);
};
$meta = new RequestMeta('127.0.0.1');
$password = 'Violet River 7 Kettle Drum!';

// ── T1: request ─────────────────────────────────────────────────────
echo "T1 request\n";
$r1 = trackRequest($requests->request($staffer, $staffer));
check('T1: self-request lands on requested', 'requested', $r1['status']);
check('T1: expiry ~48h out', true, strtotime($r1['expires_at']) - time() > 47 * 3600);
expectInvalid('T1: dupe open request refused', function () use ($requests, $staffer) {
    $requests->request($staffer, $staffer);
});
expectInvalid('T1: unknown user refused', function () use ($requests, $staffer) {
    $requests->request(999999999, $staffer);
});
$inactive = seedUser($pdo, $tag . '_inact', 'edu_dept', false);
expectInvalid('T1: deactivated staff refused', function () use ($requests, $inactive, $officer1) {
    $requests->request($inactive, $officer1);
});
expectInvalid('T1: unknown operation refused', function () use ($requests, $neighbor, $officer1) {
    $requests->request($neighbor, $officer1, 'reset');
});
// Expiry flip: age the request, decide must refuse + mark expired.
$rx = trackRequest($requests->request($neighbor, $officer1));
$pdo->prepare("UPDATE acm_staff_enrollment_requests SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = :id")
    ->execute([':id' => $rx['id']]);
try {
    $requests->decide((int)$rx['id'], $officer2, 'approved', '', $minter);
    check('T1: expired decide throws', true, false);
} catch (InvalidArgumentException $e) {
    check('T1: expired decide message', 'This request has expired.', $e->getMessage());
}
check('T1: expired request flipped', 'expired', requestStatus((int)$rx['id']));

// ── T2: decide ──────────────────────────────────────────────────────
echo "T2 decide\n";
expectInvalid('T2: self-approval refused', function () use ($requests, $r1, $staffer, $minter) {
    $requests->decide((int)$r1['id'], $staffer, 'approved', '', $minter);
});
check('T2: self-approval leaves requested', 'requested', requestStatus((int)$r1['id']));
$out = $requests->decide((int)$r1['id'], $officer1, 'approved', 'verified in person', $minter);
check('T2: approve lands on approved', 'approved', $out['request']['status']);
check('T2: grant linked', true, (int)$out['request']['grant_id'] > 0);
$g = grantRow((int)$out['request']['grant_id']);
check('T2: grant operation is staff_enroll', 'staff_enroll', (string)$g['operation']);
check('T2: grant code sentinel 0', 0, (int)$g['code_id']);
check('T2: grant ticket sentinel 0', 0, (int)$g['ticket_id']);
check('T2: grant person sentinel 0', 0, (int)$g['person_id']);
check('T2: grant TTL ~15m', true, strtotime($g['expires_at']) - time() > 14 * 60);
check('T2: token returned to console', true, is_string($out['grant']['token']) && strlen($out['grant']['token']) > 20);
$staffToken = (string)$out['grant']['token'];
trackGrantId((int)$out['request']['grant_id']);
expectInvalid('T2: double decide refused', function () use ($requests, $r1, $officer2, $minter) {
    $requests->decide((int)$r1['id'], $officer2, 'rejected', '', $minter);
});
$r2 = trackRequest($requests->request($neighbor, $officer1));
$rej = $requests->decide((int)$r2['id'], $officer2, 'rejected', 'not today', $minter);
check('T2: reject lands on rejected', 'rejected', $rej['request']['status']);
check('T2: reject mints no grant', null, $rej['grant']);
check('T2: reject stores no grant_id', null, $rej['request']['grant_id']);
$r3 = trackRequest($requests->request($neighbor, $officer1, 'device_add'));
$cancelled = $requests->cancel((int)$r3['id'], $officer1);
check('T2: cancel lands on canceled', 'canceled', $cancelled['status']);

// ── T3: resolve + begin ─────────────────────────────────────────────
echo "T3 resolve\n";
$grow = $ceremony->resolveRegistrationGrant($staffToken);
check('T3: grant resolves to staffer', $staffer, $grow['user_id']);
check('T3: ticket_id is 0', 0, $grow['ticket_id']);
check('T3: operation is staff_enroll', 'staff_enroll', $grow['operation']);
$keyS = stubKey();
$installS = stubInstall();
$chS = $devices->beginFull($staffCtx, $installS, $keyS['spkiB64'], 'E2E staff phone', $grow);
check('T3: challenge id is a uuid', true, DeviceCrypto::isValidUuid($chS->challengeId));
expectCode('T3: unknown token is generic', 'grant_invalid', function () use ($ceremony) {
    $ceremony->resolveRegistrationGrant('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
});
expectInvalid('T3: staff op + nonzero ticket refused', function () use ($devices, $staffCtx, $grow) {
    $bad = $grow;
    $bad['ticket_id'] = 123;
    $devices->beginFull($staffCtx, stubInstall(), stubKey()['spkiB64'], null, $bad);
});
expectInvalid('T3: family op + zero ticket refused', function () use ($devices, $staffCtx, $grow) {
    $bad = $grow;
    $bad['operation'] = 'device_add';
    $devices->beginFull($staffCtx, stubInstall(), stubKey()['spkiB64'], null, $bad);
});

// ── T4: complete + family regression ────────────────────────────────
echo "T4 complete\n";
$devS = $devices->completeFull($staffCtx, $chS->challengeId, $chS->challenge, stubSign($keyS['priv'], $chS->challenge));
check('T4: staff device active', 'active', $devS->status);
check('T4: fingerprint is the SPKI hash', $keyS['fp'], $devS->fingerprint);
check('T4: request consumed', 'consumed', requestStatus((int)$r1['id']));
$gAfter = grantRow((int)$out['request']['grant_id']);
check('T4: grant consumed', true, $gAfter['consumed_at'] !== null);
$replay = $devices->completeFull($staffCtx, $chS->challengeId, $chS->challenge, stubSign($keyS['priv'], $chS->challenge));
check('T4: replay echoes same device (idempotent)', $devS->deviceUuid, $replay->deviceUuid);
check('T4: replay echoes active', 'active', $replay->status);
// Family device_add regression (S4 shape, compact). NOTE: the
// ACTIVATION leg is skipped deliberately — current main never
// flips the ceremony-created pending link to active, so the
// chained activation grant cannot resolve (pre-existing S4
// walkthrough D1 failure, reproduced 2026-11-14, out of S10
// scope). device_add exercises the same begin/complete code
// S10 touched (ticket rule, operation branches, grants).
$t1 = seedTicket('T4');
$famUser = seedUser($pdo, $tag . '_fam', 'member');
$pdo->prepare(
    'INSERT INTO acm_person_accounts (person_id, user_id, account_type, status, linked_by, linked_at)'
    . " VALUES (:p, :u, 'member', 'active', :b, NOW())"
)->execute([':p' => (int)$t1['person_id'], ':u' => $famUser, ':b' => $officer1]);
$c1 = trackCode($ceremony->issueCode($officerCtx, (string)$t1['id'], CodePurpose::DeviceAdd));
$g1 = $ceremony->verifyCode($c1->selector, $c1->secret, CodePurpose::DeviceAdd, $meta);
trackGrantToken($g1->token);
$userCtx = new AccountContext($famUser, 'member');
$grant1 = $ceremony->resolveRegistrationGrant($g1->token);
check('T4: family grant resolves', (int)$t1['id'], $grant1['ticket_id']);
$keyA = stubKey();
$chA = $devices->beginFull($userCtx, stubInstall(), $keyA['spkiB64'], null, $grant1);
$devA = $devices->completeFull($userCtx, $chA->challengeId, $chA->challenge, stubSign($keyA['priv'], $chA->challenge));
check('T4: family device_add still completes', 'active', $devA->status);
check('T4: family ticket device-registered', 'active', (string)$tickets->get((int)$t1['id'])['status']);

// ── T5: resolver fails closed ───────────────────────────────────────
echo "T5 fail-closed\n";
$rc = trackRequest($requests->request($neighbor, $officer1));
$oc = $requests->decide((int)$rc['id'], $officer2, 'approved', '', $minter);
$tokC = (string)$oc['grant']['token'];
trackGrantId((int)$oc['request']['grant_id']);
$pdo->prepare("UPDATE acm_staff_enrollment_requests SET status = 'canceled' WHERE id = :id")
    ->execute([':id' => $rc['id']]);
expectCode('T5: canceled-then-resolve is generic', 'grant_invalid', function () use ($ceremony, $tokC) {
    $ceremony->resolveRegistrationGrant($tokC);
});
$rd = trackRequest($requests->request($neighbor, $officer1));
$od = $requests->decide((int)$rd['id'], $officer2, 'approved', '', $minter);
$tokD = (string)$od['grant']['token'];
trackGrantId((int)$od['request']['grant_id']);
$pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :u')->execute([':u' => $neighbor]);
expectCode('T5: deactivated-then-resolve is generic', 'grant_invalid', function () use ($ceremony, $tokD) {
    $ceremony->resolveRegistrationGrant($tokD);
});
$pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :u')->execute([':u' => $neighbor]);
$re = trackRequest($requests->request($neighbor, $officer2));
$oe = $requests->decide((int)$re['id'], $officer1, 'approved', '', $minter);
$tokE = (string)$oe['grant']['token'];
trackGrantId((int)$oe['request']['grant_id']);
$pdo->prepare("UPDATE acm_activation_grants SET revoked_at = NOW() WHERE id = :id")
    ->execute([':id' => $oe['request']['grant_id']]);
expectCode('T5: revoked grant begin fails', 'grant_revoked', function () use ($ceremony, $tokE) {
    $ceremony->resolveRegistrationGrant($tokE);
});

// ── T6: #38 closure via REAL endpoints ──────────────────────────────
echo "T6 closure\n";
$victim = seedUser($pdo, $tag . '_victim', 'edu_dept');
// Victim: one active device + one session + one open request + one live grant.
$vk = stubKey();
$pdo->prepare(
    'INSERT INTO acm_devices (uuid, user_id, installation_id, name, pubkey, fingerprint, alg, status)'
    . ' VALUES (:uuid, :u, :inst, :n, :pk, :fp, :alg, :st)'
)->execute([
    ':uuid' => AcmPersonService::uuid4(), ':u' => $victim, ':inst' => stubInstall(),
    ':n' => 'E2E victim phone', ':pk' => $vk['spkiB64'], ':fp' => $vk['fp'],
    ':alg' => 'ES256', ':st' => 'active',
]);
seedSession($victim);
$rv = trackRequest($requests->request($victim, $officer1));
$ov = $requests->decide((int)$rv['id'], $officer2, 'approved', '', $minter);
trackGrantId((int)$ov['request']['grant_id']);
$rv2 = trackRequest($requests->request($victim, $officer1));
// Neighbor: same shape, must survive.
$nk = stubKey();
$pdo->prepare(
    'INSERT INTO acm_devices (uuid, user_id, installation_id, name, pubkey, fingerprint, alg, status)'
    . ' VALUES (:uuid, :u, :inst, :n, :pk, :fp, :alg, :st)'
)->execute([
    ':uuid' => AcmPersonService::uuid4(), ':u' => $neighbor, ':inst' => stubInstall(),
    ':n' => 'E2E neighbor phone', ':pk' => $nk['spkiB64'], ':fp' => $nk['fp'],
    ':alg' => 'ES256', ':st' => 'active',
]);
seedSession($neighbor);
check('T6: victim session live before', 1, liveSessions($victim));
[$code, $toggleOut] = guardProbe($dbName, 'toggle0', $victim, $boss);
check('T6: toggle probe exits 0', 0, $code);
check('T6: toggle reports success', true, strpos($toggleOut, '"success"') !== false);
$stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :u LIMIT 1');
$stmt->execute([':u' => $victim]);
check('T6: victim bit flipped to 0', 0, (int)$stmt->fetchColumn());
check('T6: victim device revoked', 'revoked', (string)deviceRows($victim)[0]['status']);
check('T6: victim sessions dead', 0, liveSessions($victim));
check('T6: victim open request canceled', 'canceled', requestStatus((int)$rv2['id']));
$gv = grantRow((int)$ov['request']['grant_id']);
check('T6: victim live grant revoked', true, $gv['revoked_at'] !== null);
check('T6: neighbor device survives', 'active', (string)deviceRows($neighbor)[0]['status']);
check('T6: neighbor session survives', 1, liveSessions($neighbor));
[$code, $reactOut] = guardProbe($dbName, 'toggle', $victim, $boss);
check('T6: re-activate exits 0', 0, $code);
check('T6: re-activate reports success', true, strpos($reactOut, '"success"') !== false);
$stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :u LIMIT 1');
$stmt->execute([':u' => $victim]);
check('T6: victim bit back to 1', 1, (int)$stmt->fetchColumn());
check('T6: re-activate revives no device', 'revoked', (string)deviceRows($victim)[0]['status']);
check('T6: re-activate revives no session', 0, liveSessions($victim));
// Save-path 1→0: fresh device + session, then the edit form flips the bit.
$pdo->prepare(
    'INSERT INTO acm_devices (uuid, user_id, installation_id, name, pubkey, fingerprint, alg, status)'
    . ' VALUES (:uuid, :u, :inst, :n, :pk, :fp, :alg, :st)'
)->execute([
    ':uuid' => AcmPersonService::uuid4(), ':u' => $victim, ':inst' => stubInstall(),
    ':n' => 'E2E victim phone 2', ':pk' => stubKey()['spkiB64'], ':fp' => hash('sha256', $tag . 'v2'),
    ':alg' => 'ES256', ':st' => 'active',
]);
seedSession($victim);
[$code, $saveOut] = guardProbe($dbName, 'save0', $victim, $boss);
check('T6: save probe exits 0', 0, $code);
check('T6: save reports success', true, strpos($saveOut, '"success"') !== false);
$stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :u LIMIT 1');
$stmt->execute([':u' => $victim]);
check('T6: save flipped bit to 0', 0, (int)$stmt->fetchColumn());
$statuses = array_column(deviceRows($victim), 'status');
check('T6: save 1→0 revokes all victim devices', ['revoked', 'revoked'], $statuses);
check('T6: save 1→0 kills sessions', 0, liveSessions($victim));
// Delete-path: fresh rows again, then real delete.
$pdo->prepare(
    'INSERT INTO acm_devices (uuid, user_id, installation_id, name, pubkey, fingerprint, alg, status)'
    . ' VALUES (:uuid, :u, :inst, :n, :pk, :fp, :alg, :st)'
)->execute([
    ':uuid' => AcmPersonService::uuid4(), ':u' => $victim, ':inst' => stubInstall(),
    ':n' => 'E2E victim phone 3', ':pk' => stubKey()['spkiB64'], ':fp' => hash('sha256', $tag . 'v3'),
    ':alg' => 'ES256', ':st' => 'active',
]);
seedSession($victim);
$adminPass = 'e2e-admin-' . $tag;
$pdo->prepare('UPDATE users SET password_hash = :ph WHERE id = :u')
    ->execute([':ph' => password_hash($adminPass, PASSWORD_DEFAULT), ':u' => $boss]);
[$code] = guardProbe($dbName, 'delete', $victim, $boss, $adminPass);
check('T6: delete probe exits 0', 0, $code);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :u');
$stmt->execute([':u' => $victim]);
check('T6: victim user gone', 0, (int)$stmt->fetchColumn());
check('T6: victim devices all revoked', true, array_unique(array_column(deviceRows($victim), 'status')) === ['revoked']);
check('T6: victim sessions all dead', 0, liveSessions($victim));
check('T6: neighbor still intact', 'active', (string)deviceRows($neighbor)[0]['status']);

// ── T7: lifecycle refusals + session-path re-register ───────────────
echo "T7 refusals\n";
// S6 recovery is ticket-linked: staff have no tickets.
expectCode('T7: staff recovery verify is generic', 'code_invalid', function () use ($ceremony, $meta) {
    $ceremony->verifyCode('S10E2E01', 'nope-nope-nope-nope', CodePurpose::Reset, $meta);
});
// S7/S8 are member/parent-only: suspend + deactivate refuse staff.
// (Revokers injected so the fail-closed revoker check passes and
// the probe reaches the staff-role guard — which must refuse
// BEFORE any revoker runs.)
$provCalls = [];
$prov = new SSMS\Acm\Accounts\AccountProvisioner(
    $pdo,
    function (int $u) use (&$provCalls): void { $provCalls[] = "session:$u"; },
    function (int $u, string $r) use (&$provCalls): int { $provCalls[] = "device:$u"; return 0; }
);
expectInvalid('T7: suspend refuses staff', function () use ($prov, $staffer, $officer1) {
    $prov->suspend($staffer, 'e2e probe', new AccountContext($officer1, 'super_admin'));
});
expectInvalid('T7: deactivate refuses staff', function () use ($prov, $staffer, $officer1) {
    $prov->deactivate($staffer, 'e2e probe', new AccountContext($officer1, 'super_admin'));
});
check('T7: refusal ran no revoker', [], $provCalls);
// Locked-out staffer: revoke-all, password login still works
// (is_active untouched), re-register via the session path.
$devices->revokeWithReport($staffCtx, $devS->deviceUuid, 'e2e lockout');
check('T7: lockout leaves login bit on', 1, (int)$pdo->query('SELECT is_active FROM users WHERE id = ' . $staffer)->fetchColumn());
$keyR = stubKey();
$chR = $devices->beginFull($staffCtx, stubInstall(), $keyR['spkiB64'], null, null);
$devR = $devices->completeFull($staffCtx, $chR->challengeId, $chR->challenge, stubSign($keyR['priv'], $chR->challenge));
check('T7: session-path re-register works', 'active', $devR->status);

// ── Cleanup ─────────────────────────────────────────────────────────
echo "cleanup\n";
foreach ($seedRequestIds as $id) {
    $pdo->prepare('DELETE FROM acm_staff_enrollment_requests WHERE id = :id')->execute([':id' => $id]);
}
foreach ($seedUserIds as $id) {
    $pdo->prepare('DELETE FROM acm_devices WHERE user_id = :u')->execute([':u' => $id]);
    $pdo->prepare('DELETE FROM acm_device_challenges WHERE user_id = :u')->execute([':u' => $id]);
    $pdo->prepare('DELETE FROM api_refresh_sessions WHERE user_id = :u')->execute([':u' => $id]);
    $pdo->prepare('DELETE FROM acm_person_accounts WHERE user_id = :u')->execute([':u' => $id]);
}
foreach ($seedSelectors as $sel) {
    $pdo->prepare('DELETE FROM acm_activation_codes WHERE selector = :s')->execute([':s' => $sel]);
}
foreach ($seedGrantIds as $id) {
    $pdo->prepare('DELETE FROM acm_activation_grants WHERE id = :id')->execute([':id' => $id]);
}
foreach ($seedGrantTokens as $token) {
    $pdo->prepare('DELETE FROM acm_activation_grants WHERE fingerprint = :fp')
        ->execute([':fp' => substr(hash('sha256', $token), 0, 12)]);
}
foreach ($seedTicketIds as $id) {
    $pdo->prepare('DELETE FROM acm_ticket_events WHERE ticket_id = :t')->execute([':t' => $id]);
    $pdo->prepare('DELETE FROM acm_activation_tickets WHERE id = :t')->execute([':t' => $id]);
}
foreach ($seedPersonIds as $id) {
    $pdo->prepare('DELETE FROM acm_persons WHERE id = :p')->execute([':p' => $id]);
}
foreach ($seedMemberIds as $id) {
    $pdo->prepare('DELETE FROM members WHERE id = :m')->execute([':m' => $id]);
}
foreach ($seedGuardianIds as $id) {
    $pdo->prepare('DELETE FROM guardians WHERE id = :g')->execute([':g' => $id]);
}
foreach ($seedUserIds as $id) {
    $pdo->prepare('DELETE FROM users WHERE id = :u')->execute([':u' => $id]);
}
$left = (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE username LIKE '" . $tag . "%'"
)->fetchColumn();
check('cleanup: no seeded users left', 0, $left);
$leftR = (int)$pdo->query('SELECT COUNT(*) FROM acm_staff_enrollment_requests')->fetchColumn();
check('cleanup: no requests left', 0, $leftR);

echo "\n{$checks} checks, " . count($failures) . " failures\n";
if (count($failures) > 0) {
    echo implode("\n---\n", $failures) . "\n";
    exit(1);
}
echo "GREEN\n";
