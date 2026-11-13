<?php
/**
 * School API v1 — ACM activation ceremony (S3, design §D4.1).
 * POST /acm/activate/verify      {selector, secret, purpose, installation_id?}
 * POST /acm/activate/credentials {grant, password, idempotency_key}
 *
 * Pre-account endpoints: no session auth. Protection comes from
 * high-entropy code material, generic failures (no oracle), the
 * three-layer D4.5 throttle stack (coarse IP gate here + pair and
 * ticket-velocity caps in the service), and the office-network
 * redemption gate. Strict mode: unknown JSON fields are rejected.
 * Failures carry stable machine codes in `code` (never message-only).
 */

use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationException;
use SSMS\Acm\Activation\ActivationService;
use SSMS\Acm\Contracts\CodePurpose;
use SSMS\Acm\Contracts\RequestMeta;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\OfficeNetwork\OfficeNetworkSignal;

require_once __DIR__ . '/../../../acm/autoload.php';
require_once __DIR__ . '/../../../admin/backend/services/PasswordPolicy.php';

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
$ceremony = new ActivationService($pdo, new AcmTicketService($pdo, $acmPersons), $acmPersons);

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
    ok([
        'message' => 'Account created. Complete device registration to sign in.',
        'user_id' => $account->userId,
        'username' => $account->username,
        'account_type' => $account->accountType,
        'account_status' => 'account_pending_device',
        // S3 resume path: S4 device proof picks the ceremony up here.
        'resume' => 'devices/register/begin',
    ]);
}
