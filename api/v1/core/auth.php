<?php
/**
 * School API v1 — JWT Authentication
 * Token-based auth for mobile app and API clients
 */

require_once __DIR__ . '/../../../admin/backend/services/ProfileService.php';
require_once __DIR__ . '/../../../admin/backend/services/RefreshTokenService.php';

define('API_TOKEN_SECRET', defined('JWT_SECRET') ? JWT_SECRET : EXPORT_PREFIX . '_api_v1_' . DB_NAME . '_' . md5(DB_PASS));
define('API_TOKEN_EXPIRY', 900);              // 15-minute access token
define('API_REFRESH_EXPIRY', 86400 * 90);     // 90-day rotating refresh session
define('API_LEGACY_TOKEN_EXPIRY', 86400 * 30);
define('API_ROTATION_CLIENT_BUILD', 16);
define('API_LEGACY_CLIENT_COMPAT_UNTIL', strtotime('2026-09-24 23:59:59'));

// Authorization-scope revalidation is additive for build 24+, while installed
// build 23 remains on its former token-window behavior during the controlled
// rollout. Deployment overrides the timestamp in the private env file and sets
// it to a past value only after build 24 is the enforced minimum. The build
// header is therefore temporary compatibility metadata, never a permanent
// security boundary.
if (!defined('API_AUTHZ_SCOPE_CLIENT_BUILD')) {
    define('API_AUTHZ_SCOPE_CLIENT_BUILD', 24);
}
if (!defined('API_AUTHZ_LEGACY_COMPAT_UNTIL')) {
    define('API_AUTHZ_LEGACY_COMPAT_UNTIL', PHP_INT_MAX);
}

/**
 * Temporary compatibility adapter for already-installed app builds that can
 * race refresh requests. Build 16+ is single-flight and receives short access
 * tokens immediately; older builds get their former lifetime only until the
 * explicit migration deadline.
 */
function apiAccessTokenExpiryForClient(): int {
    $build = (int)($_SERVER['HTTP_X_APP_BUILD'] ?? 0);
    if ($build >= API_ROTATION_CLIENT_BUILD || time() > API_LEGACY_CLIENT_COMPAT_UNTIL) {
        return API_TOKEN_EXPIRY;
    }
    return API_LEGACY_TOKEN_EXPIRY;
}

/**
 * Create a JWT token
 */
function createToken(
    $userId,
    $username,
    $role,
    $fullName,
    $expiry = null,
    $authorizationVersion = 1,
    $refreshBinding = null
) {
    $exp = $expiry ?? API_TOKEN_EXPIRY;
    $payload = [
        'uid' => (int)$userId,
        'usr' => $username,
        'rol' => $role,
        'nam' => $fullName,
        'av' => max(1, (int)$authorizationVersion),
        'iat' => time(),
        'exp' => time() + $exp,
        'typ' => 'access',
        'jti' => bin2hex(random_bytes(16))
    ];
    // Additive signed claim: old six-argument callers remain unbound and do
    // not qualify for a future family-bound preparation grant.
    if ($refreshBinding !== null) {
        if (!is_array($refreshBinding) || count($refreshBinding) !== 2
            || !is_string($refreshBinding['family_id'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $refreshBinding['family_id'])
            || !is_string($refreshBinding['session_id'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $refreshBinding['session_id'])) {
            throw new InvalidArgumentException('Invalid access refresh-family binding.');
        }
        $payload['fid'] = $refreshBinding['family_id'];
        $payload['rsid'] = $refreshBinding['session_id'];
    }
    $b64 = base64_encode(json_encode($payload));
    return $b64 . '.' . hash_hmac('sha256', $b64, API_TOKEN_SECRET);
}

/** Extract binding only from this server's valid, owner-matching refresh. */
function apiRefreshAccessBinding(string $refreshToken, int $userId): array {
    $payload = verifyToken($refreshToken);
    $family = $payload['fid'] ?? null;
    if (!is_array($payload) || ($payload['typ'] ?? '') !== 'refresh'
        || (int)($payload['uid'] ?? 0) !== $userId || $userId <= 0
        || !is_string($family) || !preg_match('/^[a-f0-9]{64}$/D', $family)
        || !is_string($payload['jti'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $payload['jti'])) {
        throw new RuntimeException('Refresh-family binding is unavailable.');
    }
    return ['family_id'=>$family, 'session_id'=>$payload['jti']];
}

/**
 * Create a refresh token bound to a persistent one-time session.
 */
function createRefreshToken(
    $userId,
    $username,
    $role,
    $fullName,
    $authorizationVersion,
    $sessionId,
    $familyId,
    $expiresAt
) {
    $payload = [
        'uid' => (int)$userId,
        'usr' => $username,
        'rol' => $role,
        'nam' => $fullName,
        'av' => max(1, (int)$authorizationVersion),
        'iat' => time(),
        'exp' => (int)$expiresAt,
        'typ' => 'refresh',
        'jti' => (string)$sessionId,
        'fid' => (string)$familyId,
    ];
    $b64 = base64_encode(json_encode($payload));
    return $b64 . '.' . hash_hmac('sha256', $b64, API_TOKEN_SECRET);
}

/**
 * Verify signature and required claims while preserving an explicit expiry
 * state for refresh-token responses. Expired signed payloads are never exposed
 * to callers; only the machine-readable state is retained.
 *
 * @return array{state:string,payload?:array<string,mixed>,token_type?:string}
 */
function verifyTokenState($token): array {
    if (!is_string($token) || $token === '' || strlen($token) > 8192 || strpos($token, '.') === false) {
        return ['state' => 'invalid'];
    }
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
        return ['state' => 'invalid'];
    }
    if (!hash_equals(hash_hmac('sha256', $parts[0], API_TOKEN_SECRET), $parts[1])) {
        return ['state' => 'invalid'];
    }
    $decoded = base64_decode($parts[0], true);
    if ($decoded === false) {
        return ['state' => 'invalid'];
    }
    $payload = json_decode($decoded, true);
    $now = time();
    if (!is_array($payload)
        || !isset($payload['uid'], $payload['exp'], $payload['iat'], $payload['typ'])
        || (int)$payload['uid'] <= 0
        || (int)$payload['iat'] > ($now + 60)) {
        return ['state' => 'invalid'];
    }
    if ((int)$payload['exp'] < $now) {
        return ['state' => 'expired', 'token_type' => (string)$payload['typ']];
    }
    return ['state' => 'valid', 'payload' => $payload];
}

/**
 * Verify a token and return payload, or null if invalid/expired.
 */
function verifyToken($token) {
    $result = verifyTokenState($token);
    return ($result['state'] ?? '') === 'valid' ? $result['payload'] : null;
}

/**
 * Extract token from request (Authorization header or query param)
 */
function getTokenFromRequest() {
    $header = '';
    
    // Try multiple ways (Apache strips Authorization header)
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $header = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    
    // Bearer header only — never accept ?token= (it leaks into access logs).
    if (strpos($header, 'Bearer ') === 0) {
        return substr($header, 7);
    }

    return '';
}

/**
 * Whether this request is in the live authorization-scope cohort.
 *
 * Build 24+ is always checked. Older/unknown clients are checked after the
 * deployment-controlled compatibility deadline. Once the minimum build is 24,
 * operations set the deadline to the past so a spoofed old build cannot bypass
 * revalidation.
 */
function apiAuthorizationScopeEnforcedForClient(): bool {
    $build = max(0, (int)($_SERVER['HTTP_X_APP_BUILD'] ?? 0));
    return $build >= API_AUTHZ_SCOPE_CLIENT_BUILD
        || time() > (int)API_AUTHZ_LEGACY_COMPAT_UNTIL;
}

/**
 * Revalidate the signed scope against the authoritative current user row.
 *
 * Do not overwrite token claims and continue the original request when the
 * scope changed. A capable client must refresh/reconcile first; a missing or
 * unavailable authority fails closed with a typed response.
 *
 * @param array<string,mixed> $payload verified access-token payload
 * @param bool $requireCurrentScope server-only opt-in; never relaxes authority
 * @return array<string,mixed>
 */
function apiRevalidateAuthorizationScope(array $payload, bool $requireCurrentScope = false): array {
    if (!$requireCurrentScope && !apiAuthorizationScopeEnforcedForClient()) {
        return $payload;
    }

    global $conn;
    $statement = null;
    try {
        if (!isset($conn) || !($conn instanceof mysqli)) {
            throw new RuntimeException('API database connection is unavailable.');
        }
        $authVersionAvailable = \App\Services\MysqliProfileRepository::authorizationVersionColumnAvailable($conn);
        $sql = $authVersionAvailable
            ? 'SELECT role, is_active, authorization_version, username, full_name
               FROM users WHERE id=? LIMIT 1'
            : 'SELECT role, is_active, 1 AS authorization_version, username, full_name
               FROM users WHERE id=? LIMIT 1';
        $statement = $conn->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('Could not prepare authorization revalidation.');
        }
        $userId = (int)($payload['uid'] ?? 0);
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not execute authorization revalidation.');
        }
        $result = $statement->get_result();
        $current = $result ? $result->fetch_assoc() : null;
        $statement->close();
        $statement = null;
    } catch (Throwable $error) {
        if ($statement instanceof mysqli_stmt) {
            $statement->close();
        }
        error_log('API authorization scope revalidation unavailable.');
        err(
            'Authorization could not be revalidated. Please try again.',
            503,
            ['code' => 'AUTH_REVALIDATION_UNAVAILABLE']
        );
    }

    if (!$current) {
        err('This account no longer exists. Please sign in again.', 401,
            ['code' => 'ACCOUNT_REMOVED']);
    }
    if ((int)$current['is_active'] !== 1) {
        err('This account is disabled. Contact an administrator.', 401,
            ['code' => 'ACCOUNT_DISABLED']);
    }
    if (!array_key_exists('av', $payload) || (int)$payload['av'] <= 0) {
        err('Authorization scope must be refreshed. Please try again.', 401,
            ['code' => 'AUTH_SCOPE_REFRESH_REQUIRED']);
    }

    $currentRole = (string)$current['role'];
    $currentVersion = max(1, (int)$current['authorization_version']);
    if (!hash_equals($currentRole, (string)($payload['rol'] ?? ''))
        || $currentVersion !== (int)$payload['av']) {
        err('Your access changed. Refresh your session before continuing.', 401,
            ['code' => 'AUTH_SCOPE_CHANGED']);
    }

    // Profile identity claims are display metadata, not authorization. Capable
    // clients receive a non-authorization conflict and rotate through the
    // existing refresh-session mechanism. Legacy clients outside the rollout
    // cohort retain their established token-window behavior above.
    $currentUsername = (string)($current['username'] ?? '');
    $currentFullName = (string)($current['full_name'] ?? '');
    if (!hash_equals($currentUsername, (string)($payload['usr'] ?? ''))
        || !hash_equals($currentFullName, (string)($payload['nam'] ?? ''))) {
        err('Your profile changed. Refresh your session before continuing.', 409, [
            'code' => 'PROFILE_CLAIMS_CHANGED',
            'claims_refresh_required' => true,
        ]);
    }

    return $payload;
}

/**
 * Authenticate the current request — returns user payload or calls err()
 */
function apiRequireAuth() {
    $token = getTokenFromRequest();
    if (!$token) err('Authentication required. Provide Bearer token.', 401);
    
    $payload = verifyToken($token);
    if (!$payload) err('Invalid or expired token. Please login again.', 401);
    
    if (($payload['typ'] ?? 'access') !== 'access') {
        err('Invalid token type. Use access token, not refresh token.', 401);
    }
    if (time() > API_LEGACY_CLIENT_COMPAT_UNTIL
        && ((int)$payload['exp'] - (int)$payload['iat']) > (API_TOKEN_EXPIRY + 60)) {
        err('Access token must be refreshed. Please try again.', 401);
    }
    // A signed bound token cannot escape this guard by changing a build
    // header or choosing a legacy route. Keep old unbound compatibility tokens
    // explicitly separate; they do not prove family/device revocation.
    if (array_key_exists('fid', $payload) || array_key_exists('rsid', $payload)) {
        global $conn;
        try {
            if (!isset($conn) || !($conn instanceof mysqli)) {
                throw new RuntimeException('API session database unavailable.');
            }
            // S9: one bounded read feeds liveness AND proof binding
            // (no TOCTOU, no second query). Null = inactive (the same
            // verdict set the bool produced: malformed, missing,
            // revoked, and expired rows all land on SESSION_REVOKED).
            $sessionBinding = is_string($payload['fid'] ?? null) && is_string($payload['rsid'] ?? null)
                ? \App\Services\RefreshTokenService::accessSessionProofBinding(
                    $conn, (int)$payload['uid'], $payload['fid'], $payload['rsid']
                )
                : null;
        } catch (Throwable $error) {
            error_log('API refresh-family revalidation unavailable.');
            err('Session could not be revalidated. Please try again.', 503,
                ['code' => 'AUTH_SESSION_REVALIDATION_UNAVAILABLE']);
        }
        if ($sessionBinding === null) {
            err('This session is no longer active. Please sign in again.', 401,
                ['code' => 'SESSION_REVOKED']);
        }
        // S9: bound families prove possession of the registered
        // device key on EVERY call -- the token alone is useless
        // without it (closes the S5 §9 residual). Unbound rows
        // fall through EXACTLY as before (bearer + session check).
        // S9: the proof header is read unconditionally (a $_SERVER
        // lookup -- free, and observably nothing for unbound rows);
        // enforcement below is iff-bound.
        $dpopProof = isset($_SERVER['HTTP_DPOP']) && is_string($_SERVER['HTTP_DPOP'])
            ? trim($_SERVER['HTTP_DPOP']) : '';
        if ($sessionBinding['bound']) {
            require_once __DIR__ . '/../../../acm/autoload.php';
            if ($dpopProof === '') {
                err('This session requires a device proof. Please login again.', 401,
                    ['code' => 'ACCESS_PROOF_REQUIRED']);
            }
            try {
                $requestVerifier = \SSMS\Acm\Device\DeviceRefreshProofs::requestVerifier($conn);
                $htuPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
                $proofVerdict = $requestVerifier(
                    $conn,
                    (int)$sessionBinding['device_id'],
                    $dpopProof,
                    $token,
                    // Wire method (tunneled PUT/DELETE/PATCH sign the
                    // POST on the wire): the proof binds to what is
                    // actually transported; override routing is
                    // app-level and replay-safe via the jti anyway.
                    strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')),
                    $htuPath === '' ? '/' : $htuPath
                );
            } catch (Throwable $error) {
                error_log('API access proof verification unavailable.');
                err('Authentication service is temporarily unavailable.', 503,
                    ['code' => 'AUTH_SERVICE_UNAVAILABLE']);
            }
            if ($proofVerdict !== 'ok') {
                // Invalid and replay collapse to ONE generic failure
                // (S5 no-oracle precedent, #33); the internal split
                // is logged, never exposed.
                error_log('API access proof rejected: ' . $proofVerdict);
                err('Invalid device proof. Please login again.', 401,
                    ['code' => 'ACCESS_PROOF_INVALID']);
            }
        }
    }
    return apiRevalidateAuthorizationScope($payload);
}

/**
 * Require specific role(s) — call after requireAuth()
 */
function apiRequireRole($auth, $allowedRoles) {
    $allowedRoles = (array)$allowedRoles;
    if (!in_array($auth['rol'], $allowedRoles)) {
        err('Access denied. Required role: ' . implode(' or ', $allowedRoles), 403);
    }
}
