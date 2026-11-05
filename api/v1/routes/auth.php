<?php
/**
 * School API v1 — Auth Routes
 * POST /auth/login          — Login with username + password, get tokens
 * POST /auth/refresh-token  — Rotate refresh token and get a new token pair
 * POST /auth/logout         — Revoke the presented refresh-token family
 * GET  /auth/verify         — Verify current token is valid
 */

require_once __DIR__ . '/../../../admin/backend/services/RefreshTokenService.php';
require_once __DIR__ . '/../../../admin/backend/services/ProfileService.php';

$action = $ROUTE['id'] ?? '';
$refreshService = new \App\Services\RefreshTokenService(
    $conn,
    static function (
        $userId,
        $username,
        $role,
        $fullName,
        $authorizationVersion,
        $sessionId,
        $familyId,
        $expiresAt
    ) {
        return createRefreshToken(
            $userId,
            $username,
            $role,
            $fullName,
            $authorizationVersion,
            $sessionId,
            $familyId,
            $expiresAt
        );
    },
    API_REFRESH_EXPIRY
);
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$accessTokenExpiry = apiAccessTokenExpiryForClient();

// ============================================================
// POST /auth/login
// ============================================================
if ($action === 'login' && $method === 'POST') {
    // Rate limit: 10 login attempts per minute per IP
    if (isApiRateLimited('auth_login', 10)) {
        err('Too many login attempts. Please wait a minute.', 429);
    }
    
    $input = getBody();
    $username = trim($input['username'] ?? '');
    $password = (string)($input['password'] ?? '');
    
    if ($username === '' || $password === '') {
        err('Username and password are required.');
    }
    if (strlen($password) > 4096) {
        err('Invalid username or password.', 401);
    }
    
    $profileImageColumnExists = \App\Services\MysqliProfileRepository::profileImageColumnAvailable($conn);
    $authVersionExists = \App\Services\MysqliProfileRepository::authorizationVersionColumnAvailable($conn);
    $selectSql = "SELECT id, username, email, full_name, role, password_hash, is_active, authorization_version FROM users WHERE (username = ? OR email = ?) LIMIT 1";
    $stmt = $conn->prepare($selectSql);
    if (!$stmt) err('Database error', 500);
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        err('Invalid username or password.', 401);
    }
    
    if ((int)$user['is_active'] !== 1) {
        err('Your account is inactive. Contact an administrator.', 403);
    }
    
    try {
        $refreshToken = $refreshService->issue($user, $clientIp, $userAgent);
        $accessFamily = apiRefreshAccessBinding($refreshToken, (int)$user['id']);
    } catch (Throwable $error) {
        error_log('API refresh session creation failed. Apply migrations 010 and 048.');
        err('Authentication service is temporarily unavailable.', 503,
            ['code' => 'AUTH_SERVICE_UNAVAILABLE']);
    }

    $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . (int)$user['id']);
    logApiAction($user['id'], $user['username'], 'API Login', 'REST API v1');
    
    $hasImage = !empty($user['profile_image_path']);
    $profileVersion = \App\Services\ProfileService::profileVersion($user);
    $imageUrl = $hasImage
        ? (function_exists('ssms_app_url') ? ssms_app_url('api/v1/users/me/profile-image') : '/api/v1/users/me/profile-image')
        : null;

    ok([
        'token' => createToken(
            $user['id'],
            $user['username'],
            $user['role'],
            $user['full_name'],
            $accessTokenExpiry,
            max(1, (int)($user['authorization_version'] ?? 1)),
            $accessFamily
        ),
        'refresh_token' => $refreshToken,
        'expires_in' => $accessTokenExpiry,
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'email' => isset($user['email']) ? (string)$user['email'] : null,
            'role' => $user['role'],
            'is_active' => (int)($user['is_active'] ?? 0) === 1,
            'profile_image' => [
                'present' => $hasImage,
                'version' => $hasImage ? hash('sha256', (string)$user['profile_image_path']) : null,
                'url' => $imageUrl,
            ],
            'profile_version' => $profileVersion,
            'authorization_version' => max(1, (int)($user['authorization_version'] ?? 1))
        ]
    ]);
}

// ============================================================
// POST /auth/refresh-token — atomically rotate a one-time refresh session
// ============================================================
if ($action === 'refresh-token' && $method === 'POST') {
    if (isApiRateLimited('auth_refresh', 30)) {
        err('Too many refresh attempts. Please wait a minute.', 429);
    }

    $input = getBody();
    $refreshToken = (string)($input['refresh_token'] ?? '');
    if ($refreshToken === '') {
        err('Refresh token is required.', 401, ['code' => 'INVALID_REFRESH_TOKEN']);
    }

    $verification = verifyTokenState($refreshToken);
    if (($verification['state'] ?? '') === 'expired') {
        if (($verification['token_type'] ?? '') !== 'refresh') {
            err('Invalid refresh token. Please login again.', 401,
                ['code' => 'INVALID_REFRESH_TOKEN']);
        }
        err('Refresh token expired. Please login again.', 401,
            ['code' => 'REFRESH_EXPIRED']);
    }
    $payload = ($verification['state'] ?? '') === 'valid'
        ? ($verification['payload'] ?? null)
        : null;
    if (!is_array($payload) || ($payload['typ'] ?? '') !== 'refresh') {
        err('Invalid refresh token. Please login again.', 401,
            ['code' => 'INVALID_REFRESH_TOKEN']);
    }

    $rotation = $refreshService->rotate($refreshToken, $payload, $clientIp, $userAgent);
    $rotationState = (string)($rotation['state'] ?? 'invalid');
    if ($rotationState === 'reused') {
        logApiAction((int)($payload['uid'] ?? 0), (string)($payload['usr'] ?? ''),
            'Refresh token reuse blocked', 'Refresh-token family revoked');
        err('Refresh token reuse detected. Please login again.', 401,
            ['code' => 'REFRESH_REUSED']);
    }
    if ($rotationState === 'expired') {
        err('Refresh session expired. Please login again.', 401,
            ['code' => 'REFRESH_EXPIRED']);
    }
    if ($rotationState === 'revoked') {
        err('This session was revoked. Please login again.', 401,
            ['code' => 'SESSION_REVOKED']);
    }
    if ($rotationState === 'account_disabled') {
        err('This account is disabled. Contact an administrator.', 401,
            ['code' => 'ACCOUNT_DISABLED']);
    }
    if ($rotationState === 'account_removed') {
        err('This account no longer exists. Please login again.', 401,
            ['code' => 'ACCOUNT_REMOVED']);
    }
    if ($rotationState === 'unavailable') {
        error_log('API refresh rotation failed. Apply migrations 010 and 048.');
        err('Authentication service is temporarily unavailable.', 503,
            ['code' => 'AUTH_SERVICE_UNAVAILABLE']);
    }
    if ($rotationState !== 'rotated' || empty($rotation['token']) || empty($rotation['user'])) {
        err('Invalid refresh token. Please login again.', 401,
            ['code' => 'INVALID_REFRESH_TOKEN']);
    }

    $user = $rotation['user'];
    try {
        $accessFamily = apiRefreshAccessBinding($rotation['token'], (int)$user['id']);
    } catch (Throwable $error) {
        error_log('API rotated refresh-family binding unavailable.');
        err('Authentication service is temporarily unavailable.', 503,
            ['code' => 'AUTH_SERVICE_UNAVAILABLE']);
    }
    $hasImage = !empty($user['profile_image_path']);
    $profileVersion = \App\Services\ProfileService::profileVersion($user);
    $imageUrl = $hasImage
        ? (function_exists('ssms_app_url') ? ssms_app_url('api/v1/users/me/profile-image') : '/api/v1/users/me/profile-image')
        : null;

    ok([
        'token' => createToken(
            $user['id'],
            $user['username'],
            $user['role'],
            $user['full_name'],
            $accessTokenExpiry,
            max(1, (int)($user['authorization_version'] ?? 1)),
            $accessFamily
        ),
        'refresh_token' => $rotation['token'],
        'expires_in' => $accessTokenExpiry,
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'email' => isset($user['email']) ? (string)$user['email'] : null,
            'role' => $user['role'],
            'is_active' => (int)($user['is_active'] ?? 0) === 1,
            'profile_image' => [
                'present' => $hasImage,
                'version' => $hasImage ? hash('sha256', (string)$user['profile_image_path']) : null,
                'url' => $imageUrl,
            ],
            'profile_version' => $profileVersion,
            'authorization_version' => max(1, (int)($user['authorization_version'] ?? 1)),
        ],
    ]);
}

// ============================================================
// POST /auth/logout — revoke the presented refresh-token family
// ============================================================
if ($action === 'logout' && $method === 'POST') {
    if (isApiRateLimited('auth_logout', 30)) {
        err('Too many logout attempts. Please wait a minute.', 429);
    }
    $input = getBody();
    $refreshToken = (string)($input['refresh_token'] ?? '');
    if ($refreshToken !== '') {
        $payload = verifyToken($refreshToken);
        if ($payload && ($payload['typ'] ?? '') === 'refresh') {
            $refreshService->revokePresented($refreshToken, $payload);
        }
    }
    ok(['message' => 'Signed out.']);
}

// ============================================================
// GET /auth/verify
// ============================================================
if ($action === 'verify' && $method === 'GET') {
    $auth = apiRequireAuth();
    ok([
        'valid' => true,
        'user' => $auth,
        'expires_at' => date('c', $auth['exp']),
    ]);
}

err("Unknown auth action: {$action}. Use: login, refresh-token, logout, verify", 404);
