<?php
/**
 * School API v1 — ACM activation ceremony (S3) + devices (S4, design §D4.1)
 * + credential recovery (S6).
 * POST /acm/activate/verify      {selector, secret, purpose, installation_id?}
 * POST /acm/activate/credentials {grant, password, idempotency_key}
 * POST /acm/reset/verify      {selector, secret, purpose='reset'}
 * POST /acm/reset/credentials {grant, new_password, confirm_password, idempotency_key}
 * POST /acm/devices/register/begin   {grant?, installation_id, pubkey, name?}
 * POST /acm/devices/register/complete {challenge_id, challenge, signature}
 * GET  /acm/devices
 * POST /acm/devices/revoke           {device_uuid, reason?}
 *
 * Pre-account endpoints: no session auth. Protection comes from
 * high-entropy code material, generic failures (no oracle), the
 * three-layer D4.5 throttle stack (coarse IP gate here + pair and
 * ticket-velocity caps in the service), and the office-network
 * redemption gate. Strict mode: unknown JSON fields are rejected.
 * Failures carry stable machine codes in `code` (never message-only).
 * S4 device endpoints are mixed-auth: grant-xor-session begin
 * (grant wins when both are present), possession-authed complete
 * (the activation path has no session by design), session-authed
 * list + self-revoke.
 */

use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationException;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\CodePurpose;
use SSMS\Acm\Contracts\RequestMeta;
use SSMS\Acm\Device\DeviceCrypto;
use SSMS\Acm\Device\DeviceService;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\OfficeNetwork\OfficeNetworkSignal;

require_once __DIR__ . '/../../../acm/autoload.php';
require_once __DIR__ . '/../../../admin/backend/services/PasswordPolicy.php';
require_once __DIR__ . '/../../../admin/backend/services/RefreshTokenService.php';
// S6 recovery: the shared administrator password primitive +
// the security-audit writer (users.php requires the same set;
// require_once keeps repeated loads free).
require_once __DIR__ . '/../../../admin/backend/services/ProfileService.php';
require_once __DIR__ . '/../../../admin/backend/services/AccountCredentialService.php';
require_once __DIR__ . '/../../../admin/backend/services/SecurityAuditService.php';

if (!function_exists('acmWireError')) {
    /**
     * Translate a ceremony failure to the wire. The route owns the
     * wire contract outright: only the machine code + HTTP status
     * cross from the exception — messages come from this allowlist,
     * never from the exception object (fail-closed against future
     * diagnostic text). Unknown codes fall back to generic.
     * weak_password details are recomputed deterministically from
     * the submitted password (same pure function the service used).
     */
    function acmWireError(ActivationException $e, string $password = ''): void
    {
        $wire = [
            'code_invalid' => 'The code is invalid or expired.',
            'grant_invalid' => 'The activation session is invalid.',
            'grant_expired' => 'The activation session expired. Ask the office to reissue a code.',
            'grant_consumed' => 'The activation session was already used.',
            'grant_revoked' => 'The activation session was revoked. Ask the office to reissue a code.',
            'account_exists' => 'An account already exists for this person. The ticket is queued for officer review.',
            'rate_limited' => 'Too many attempts. Wait a while and try again.',
            'idempotent_conflict' => 'This idempotency key was already used with a different session.',
            'network_denied' => 'Activation is not available from this network.',
            'ticket_state' => 'The ticket is not in the right state for this action.',
            'invalid_request' => 'The request is invalid.',
            // S4 device vocabulary (same route-owns-wire rule: only
            // code + status cross from the exception).
            'challenge_failed' => 'The device proof is invalid or expired.',
            'device_limit' => 'A device registration limit was reached.',
            'device_revoked' => 'This device registration was revoked.',
            'device_conflict' => 'This installation is already registered with a different device key. Revoke the old registration first.',
        ];
        $code = $e->machineCode();
        if ($code === 'weak_password') {
            $details = \App\Services\PasswordPolicy::errors($password);
            $message = $details === []
                ? 'The password does not meet the policy.'
                : implode(' ', $details);
            err($message, 422, ['code' => 'weak_password']);
        }
        err($wire[$code] ?? 'The service could not complete the request.', $e->httpStatus(), ['code' => $code]);
    }
}

if (!function_exists('acmStrictBody')) {
    /**
     * Parse JSON body in strict mode: unknown fields rejected (D4.2),
     * values length-capped. Returns the picked fields.
     */
    function acmStrictBody(array $allowed, int $maxLen = 512): array
    {
        $input = getBody();
        if (!is_array($input)) {
            err('Invalid request body.', 400, ['code' => 'invalid_request']);
        }
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowed, true)) {
                err('Unknown field: ' . $key . '.', 400, ['code' => 'invalid_request']);
            }
        }
        $out = [];
        foreach ($allowed as $key) {
            $value = $input[$key] ?? null;
            if (!is_string($value)) {
                $out[$key] = '';
                continue;
            }
            if (strlen($value) > $maxLen) {
                err('Field too long: ' . $key . '.', 400, ['code' => 'invalid_request']);
            }
            $out[$key] = $value;
        }
        return $out;
    }
}

if (!($pdo instanceof PDO)) {
    err('Service unavailable.', 503);
}

$acmAction = ($ROUTE['id'] ?? '') . '/' . ($ROUTE['sub'] ?? '');
$acmPersons = new AcmPersonService($pdo);
// S6 credential recovery. The password resetter is mysqli-backed
// (the ACM package stays PDO-only); without a live mysqli handle
// recovery fails closed inside the service (grant never consumed).
$acmResetter = null;
if (isset($conn) && $conn instanceof \mysqli) {
    $acmResetter = static function (int $officerId, int $userId, string $newPassword) use ($conn): void {
        $credentials = new \App\Services\AccountCredentialService(
            new \App\Services\MysqliCredentialRepository($conn)
        );
        // Confirmation was already matched server-side in the
        // service; the administrator primitive re-validates
        // policy (+ match) and revokes ALL refresh sessions in
        // the same transaction as the new hash.
        $credentials->resetPasswordByAdministrator(
            \App\Services\AuthorizedAdministratorIdentity::fromTrustedAuthorization($officerId),
            $userId,
            $newPassword,
            $newPassword
        );
    };
}
$ceremony = new ActivationService($pdo, new AcmTicketService($pdo, $acmPersons), $acmPersons, $acmResetter);
// S4 device registry. The family revoker is mysqli-backed (the ACM
// package stays PDO-only); without a live mysqli handle revoke
// fails closed inside the service (never silently unlinked).
$acmRevoker = null;
if (isset($conn) && $conn instanceof \mysqli) {
    $acmRevoker = static function (int $deviceId) use ($conn): int {
        static $refreshForRevoke = null;
        if ($refreshForRevoke === null) {
            $refreshForRevoke = new \App\Services\RefreshTokenService(
                $conn,
                static function (): string {
                    // Unreachable on the revoke path (revocation never
                    // issues); throws loudly if it ever fires.
                    throw new \RuntimeException('Refresh issuance is unavailable here.');
                },
                3600 // Unused on the revoke path (TTL applies to issuance only).
            );
        }
        return $refreshForRevoke->revokeDeviceFamilies($deviceId);
    };
}
$devices = new DeviceService($pdo, new AcmTicketService($pdo, $acmPersons), $acmRevoker);
// Three-level device paths (D4.1) match on the full segment list;
// the S3 two-level actions above keep matching on id/sub.
$acmParts = array_slice($ROUTE['parts'] ?? [], 1);
$acmPath = implode('/', $acmParts);

// ============================================================
// POST /acm/activate/verify
// ============================================================
if ($acmAction === 'activate/verify' && $method === 'POST') {
    // Coarse IP gate (D4.5 layer 0); the service adds pair + ticket caps.
    if (isApiRateLimited('acm_verify', 30)) {
        err('Too many attempts. Wait a while and try again.', 429, ['code' => 'rate_limited']);
    }
    $body = acmStrictBody(['selector', 'secret', 'purpose', 'installation_id']);
    try {
        $purpose = CodePurpose::from($body['purpose']);
    } catch (\ValueError $e) {
        err('The code is invalid or expired.', 400, ['code' => 'code_invalid']);
    }
    $installationId = $body['installation_id'];
    if ($installationId !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $installationId)) {
        err('The code is invalid or expired.', 400, ['code' => 'code_invalid']);
    }
    // Proxy-safe client-IP resolution (D3.7): agreed header only,
    // trusted peer only, else unknown (fail-closed downstream when
    // enforcement is on).
    $signal = $ceremony->networkSignal();
    $clientIp = OfficeNetworkSignal::resolveClientIp($_SERVER, $signal->trustedProxies());
    try {
        $grant = $ceremony->verifyCode(
            $body['selector'], $body['secret'], $purpose,
            new RequestMeta($clientIp, $installationId !== '' ? $installationId : null)
        );
    } catch (ActivationException $e) {
        acmWireError($e);
    } catch (InvalidArgumentException $e) {
        err('The request is invalid.', 400, ['code' => 'invalid_request']);
    }
    ok([
        'grant' => $grant->token,
        'operation' => $grant->operation->value,
        'expires_at' => $grant->expiresAt,
    ]);
}

// ============================================================
// POST /acm/activate/credentials
// ============================================================
if ($acmAction === 'activate/credentials' && $method === 'POST') {
    if (isApiRateLimited('acm_credentials', 10)) {
        err('Too many attempts. Wait a while and try again.', 429, ['code' => 'rate_limited']);
    }
    // One password value over HTTPS (D3.4) — confirmation is app-UI-only.
    $body = acmStrictBody(['grant', 'password', 'idempotency_key'], 4096);
    try {
        $account = $ceremony->establishCredentials(
            $body['grant'], $body['password'], $body['idempotency_key']
        );
    } catch (ActivationException $e) {
        acmWireError($e, $body['password']);
    } catch (InvalidArgumentException $e) {
        err('The request is invalid.', 400, ['code' => 'invalid_request']);
    }
    $credentialsResponse = [
        'message' => 'Account created. Complete device registration to sign in.',
        'user_id' => $account->userId,
        'username' => $account->username,
        'account_type' => $account->accountType,
        'account_status' => 'account_pending_device',
        // S3 resume path: S4 device proof picks the ceremony up here.
        'resume' => 'devices/register/begin',
    ];
    // S4: chained device grant, show-once (hash-only server-side).
    // Null on idempotent replay ⟺ a device is already active.
    if ($account->deviceGrant !== null) {
        $credentialsResponse['device_grant'] = $account->deviceGrant;
    } else {
        $credentialsResponse['device_registered'] = true;
    }
    ok($credentialsResponse);
}

// ============================================================
// POST /acm/reset/verify
// R-code redemption: identical machinery to activate/verify --
// only the purpose differs (minted grant carries operation =
// reset). No installation_id: recovery needs no device context.
// ============================================================
if ($acmAction === 'reset/verify' && $method === 'POST') {
    if (isApiRateLimited('acm_reset_verify', 30)) {
        err('Too many attempts. Wait a while and try again.', 429, ['code' => 'rate_limited']);
    }
    $body = acmStrictBody(['selector', 'secret', 'purpose']);
    if ($body['purpose'] !== CodePurpose::Reset->value) {
        err('The code is invalid or expired.', 400, ['code' => 'code_invalid']);
    }
    $signal = $ceremony->networkSignal();
    $clientIp = OfficeNetworkSignal::resolveClientIp($_SERVER, $signal->trustedProxies());
    try {
        $grant = $ceremony->verifyCode(
            $body['selector'], $body['secret'], CodePurpose::Reset,
            new RequestMeta($clientIp, null)
        );
    } catch (ActivationException $e) {
        acmWireError($e);
    } catch (InvalidArgumentException $e) {
        err('The request is invalid.', 400, ['code' => 'invalid_request']);
    }
    ok([
        'grant' => $grant->token,
        'operation' => $grant->operation->value,
        'expires_at' => $grant->expiresAt,
    ]);
}

// ============================================================
// POST /acm/reset/credentials
// Grant + new password (+ server-matched confirmation) +
// idempotency key. Audits PASSWORD_RECOVERED once per recovery
// (replays never re-audit); the issuing officer is the actor.
// Best-effort audit AFTER the commits -- identical precedent to
// PASSWORD_CHANGED in users.php (the durable trail is the
// ticket's `recovered` event, committed before we return).
// ============================================================
if ($acmAction === 'reset/credentials' && $method === 'POST') {
    if (isApiRateLimited('acm_reset_credentials', 10)) {
        err('Too many attempts. Wait a while and try again.', 429, ['code' => 'rate_limited']);
    }
    $body = acmStrictBody(['grant', 'new_password', 'confirm_password', 'idempotency_key'], 4096);
    try {
        $recovered = $ceremony->completeRecovery(
            $body['grant'], $body['new_password'], $body['confirm_password'], $body['idempotency_key']
        );
    } catch (ActivationException $e) {
        acmWireError($e, $body['new_password']);
    } catch (InvalidArgumentException $e) {
        err('The request is invalid.', 400, ['code' => 'invalid_request']);
    }
    if (!$recovered['replayed'] && isset($conn) && $conn instanceof \mysqli) {
        $recoveryActor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
            (int)$recovered['officer_id'],
            (string)$recovered['officer_username'],
            'api',
            $_SERVER
        );
        if (!\App\Services\SecurityAuditService::recordTrusted(
            $conn,
            $recoveryActor,
            'PASSWORD_RECOVERED',
            [
                'operation' => 'acm_credential_recovery',
                'ticket_id' => (int)$recovered['ticket_id'],
                'grant_fp' => substr(hash('sha256', (string)$body['grant']), 0, 6) . '...',
            ],
            'user',
            (int)$recovered['user_id']
        )) {
            error_log('Credential-recovery audit event could not be recorded.');
        }
    }
    ok([
        'recovered' => true,
        'ticket_id' => (int)$recovered['ticket_id'],
    ]);
}

// ============================================================
// POST /acm/devices/register/begin
// Grant-xor-session auth: a grant wins when both are present
// (the grant determines the user; the session is ignored).
// ============================================================
if ($acmPath === 'devices/register/begin' && $method === 'POST') {
    if (isApiRateLimited('acm_dev_begin', 30)) {
        err('Too many attempts. Wait a while and try again.', 429, ['code' => 'rate_limited']);
    }
    $body = acmStrictBody(['grant', 'installation_id', 'pubkey', 'name'], 8192);
    $grantRow = null;
    if ($body['grant'] !== '') {
        try {
            $grantRow = $ceremony->resolveRegistrationGrant($body['grant']);
        } catch (ActivationException $e) {
            acmWireError($e);
        } catch (InvalidArgumentException $e) {
            err('The request is invalid.', 400, ['code' => 'invalid_request']);
        }
        $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :u LIMIT 1');
        $roleStmt->execute([':u' => $grantRow['user_id']]);
        $beginCtx = new AccountContext($grantRow['user_id'], (string)$roleStmt->fetchColumn());
    } else {
        $auth = apiRequireAuth();
        $beginCtx = new AccountContext(
            (int)($auth['uid'] ?? 0),
            (string)($auth['rol'] ?? $auth['role'] ?? '')
        );
    }
    try {
        $challenge = $devices->beginFull(
            $beginCtx,
            $body['installation_id'],
            $body['pubkey'],
            $body['name'] !== '' ? $body['name'] : null,
            $grantRow
        );
    } catch (ActivationException $e) {
        acmWireError($e);
    } catch (InvalidArgumentException $e) {
        err('The request is invalid.', 400, ['code' => 'invalid_request']);
    }
    ok([
        'challenge_id' => $challenge->challengeId,
        'challenge' => $challenge->challenge,
        'expires_at' => $challenge->expiresAt,
    ]);
}

// ============================================================
// POST /acm/devices/register/complete
// Possession-authed: the signature over our challenge IS the auth
// (the activation path has no session by design). The route
// pre-reads the challenge owner for the service context; unknown
// challenges fail generically (no oracle).
// ============================================================
if ($acmPath === 'devices/register/complete' && $method === 'POST') {
    if (isApiRateLimited('acm_dev_complete', 30)) {
        err('Too many attempts. Wait a while and try again.', 429, ['code' => 'rate_limited']);
    }
    $body = acmStrictBody(['challenge_id', 'challenge', 'signature'], 8192);
    $ownerStmt = $pdo->prepare(
        'SELECT user_id, grant_id FROM acm_device_challenges WHERE uuid = :uuid LIMIT 1'
    );
    $ownerStmt->execute([':uuid' => $body['challenge_id']]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($owner)) {
        err('The device proof is invalid or expired.', 401, ['code' => 'challenge_failed']);
    }
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :u LIMIT 1');
    $roleStmt->execute([':u' => (int)$owner['user_id']]);
    $completeCtx = new AccountContext((int)$owner['user_id'], (string)$roleStmt->fetchColumn());
    try {
        $device = $devices->completeFull(
            $completeCtx, $body['challenge_id'], $body['challenge'], $body['signature']
        );
    } catch (ActivationException $e) {
        if ($e->machineCode() === 'rate_limited') {
            // Named-lock contention (5s window): honest retry hint.
            header('Retry-After: 5');
        }
        acmWireError($e);
    } catch (InvalidArgumentException $e) {
        err('The request is invalid.', 400, ['code' => 'invalid_request']);
    }
    if ($owner['grant_id'] === null) {
        // Session-authed (ticketless) registration: API audit (grant
        // paths audit via ticket transitions instead).
        $userStmt = $pdo->prepare('SELECT username FROM users WHERE id = :u LIMIT 1');
        $userStmt->execute([':u' => $completeCtx->userId]);
        logApiAction(
            $completeCtx->userId,
            (string)$userStmt->fetchColumn(),
            'ACM Device registered',
            'fp ' . DeviceCrypto::shortFingerprint($device->fingerprint)
        );
    }
    ok([
        'device_uuid' => $device->deviceUuid,
        'fingerprint' => $device->fingerprint,
        'status' => $device->status,
    ]);
}

// ============================================================
// GET /acm/devices — own devices (short fingerprints on the wire).
// ============================================================
if ($acmPath === 'devices' && $method === 'GET') {
    $auth = apiRequireAuth();
    try {
        $rows = $devices->listForUser((int)($auth['uid'] ?? 0));
    } catch (InvalidArgumentException $e) {
        err('The request is invalid.', 400, ['code' => 'invalid_request']);
    }
    ok([
        'devices' => array_map(static function (array $row): array {
            return [
                'device_uuid' => (string)$row['uuid'],
                'name' => $row['name'] !== null ? (string)$row['name'] : null,
                'fingerprint' => DeviceCrypto::shortFingerprint((string)$row['fingerprint']),
                'alg' => (string)$row['alg'],
                'status' => (string)$row['status'],
                'first_seen' => (string)$row['first_seen'],
                'last_seen' => $row['last_seen'] !== null ? (string)$row['last_seen'] : null,
                'installation' => substr((string)$row['installation_id'], 0, 8) . '...',
            ];
        }, $rows),
    ]);
}

// ============================================================
// POST /acm/devices/revoke — self-service (own devices).
// Idempotent: repeat calls succeed without re-revoking.
// ============================================================
if ($acmPath === 'devices/revoke' && $method === 'POST') {
    if (isApiRateLimited('acm_dev_revoke', 30)) {
        err('Too many attempts. Wait a while and try again.', 429, ['code' => 'rate_limited']);
    }
    $auth = apiRequireAuth();
    $body = acmStrictBody(['device_uuid', 'reason'], 2048);
    $revokeCtx = new AccountContext(
        (int)($auth['uid'] ?? 0),
        (string)($auth['rol'] ?? $auth['role'] ?? '')
    );
    try {
        $report = $devices->revokeWithReport(
            $revokeCtx, $body['device_uuid'], $body['reason']
        );
    } catch (ActivationException $e) {
        acmWireError($e);
    } catch (InvalidArgumentException $e) {
        err('The request is invalid.', 400, ['code' => 'invalid_request']);
    }
    $userStmt = $pdo->prepare('SELECT username FROM users WHERE id = :u LIMIT 1');
    $userStmt->execute([':u' => $revokeCtx->userId]);
    logApiAction(
        $revokeCtx->userId,
        (string)$userStmt->fetchColumn(),
        'ACM Device revoked',
        'families ' . $report['families'] . ($report['already'] ? ' (already revoked)' : '')
    );
    ok(['revoked' => true, 'families' => $report['families']]);
}
