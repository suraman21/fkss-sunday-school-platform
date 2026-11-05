<?php
/**
 * School API v1 — Middleware
 * Runs before every request: CORS, headers, method validation
 */

require_once __DIR__ . '/../../../admin/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/../../../admin/backend/services/ApiIdempotencyService.php';
require_once __DIR__ . '/../../../admin/backend/services/ApiSyncAttemptMonitorService.php';

/**
 * Handle CORS preflight and set response headers
 */
function handleCors() {
    header('Content-Type: application/json; charset=utf-8');
    
    // ── CORS: Allow specific origins only ──
    // Mobile apps (Flutter) don't send Origin headers, so they pass through.
    // Web browsers WILL send Origin — we only allow our own domains.
    $allowedOrigins = [
        SITE_URL,
        'http://' . SITE_DOMAIN,
        'https://www.' . SITE_DOMAIN,
        // Add more if needed, e.g. a staging domain
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    } elseif ($origin === '') {
        // No Origin header = not a browser CORS request (mobile app, curl, etc.).
        // Do not emit a wildcard ACAO: that is flagged as a cross-domain
        // misconfiguration and is unnecessary for non-browser clients, which
        // are authenticated via JWT and not subject to CORS.
    }
    // If Origin is set but NOT in our list → no CORS header = browser blocks it
    
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Idempotency-Key, X-Client-Attempt-Id, X-Client-Attempt-Number, X-Execution-Source, X-App-Version, X-App-Build, X-Installation-Id, X-Attendance-Receipt-Version');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'none'");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-API-Version: 1.0');
    
    // Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Require specific HTTP method(s)
 */
function requireMethod($allowed) {
    $allowed = (array)$allowed;
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, $allowed)) {
        err("Method $method not allowed. Use: " . implode(', ', $allowed), 405);
    }
}

/**
 * Get the HTTP method (supports method override for clients that can't send PUT/DELETE)
 */
function getMethod() {
    $method = $_SERVER['REQUEST_METHOD'];
    // Allow method override via header or POST field
    if ($method === 'POST') {
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_POST['_method'] ?? '';
        if (in_array(strtoupper($override), ['PUT', 'DELETE', 'PATCH'])) {
            $method = strtoupper($override);
        }
    }
    return $method;
}

/**
 * Stripe-style idempotency. Same user + route + key + payload = same JSON.
 */
function apiIdempotencyKey(): string {
    $key = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($key === '' || strlen($key) > 80 || !preg_match('/^[A-Za-z0-9._-]+$/', $key)) {
        return '';
    }
    return $key;
}

function apiIdempotencyService(): \App\Services\ApiIdempotencyService {
    static $service = null;
    global $conn;
    if (!$service instanceof \App\Services\ApiIdempotencyService) {
        $database = $conn instanceof \mysqli ? $conn : null;
        $service = new \App\Services\ApiIdempotencyService(
            $database,
            ROOT_PATH . '/admin/uploads/cache'
        );
    }
    return $service;
}

/** @return mixed */
function apiCanonicalizeIdempotencyValue($value) {
    if (!is_array($value)) {
        return $value;
    }
    $keys = array_keys($value);
    $isList = $keys === range(0, count($keys) - 1);
    if (!$isList) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = apiCanonicalizeIdempotencyValue($item);
    }
    return $value;
}

function apiIdempotencyRequestHash(string $method, string $scope): string {
    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $canonical = json_encode(
        apiCanonicalizeIdempotencyValue($payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($canonical === false) {
        $canonical = $raw;
    }
    return hash('sha256', $method . "\0" . $scope . "\0" . $canonical);
}

function apiSyncMonitorAttemptNumber(): ?int {
    $raw = trim((string)($_SERVER['HTTP_X_CLIENT_ATTEMPT_NUMBER'] ?? ''));
    if ($raw === '' || !preg_match('/^[0-9]{1,6}$/D', $raw)) {
        return null;
    }
    $value = (int)$raw;
    return $value >= 1 && $value <= 100000 ? $value : null;
}

function apiSyncMonitorExecutionSource(): ?string {
    $value = trim((string)($_SERVER['HTTP_X_EXECUTION_SOURCE'] ?? ''));
    return in_array($value, ['foreground', 'background'], true) ? $value : null;
}

/** Fleet context: app version header (validated, never required). */
function apiSyncMonitorAppVersion(): ?string {
    $value = trim((string)($_SERVER['HTTP_X_APP_VERSION'] ?? ''));
    if ($value === '' || strlen($value) > 32) {
        return null;
    }
    // Version-shaped strings only: no punctuation beyond separators.
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,31}$/D', $value) ? $value : null;
}

/** Fleet context: app build header (validated, never required). */
function apiSyncMonitorAppBuild(): ?int {
    $raw = trim((string)($_SERVER['HTTP_X_APP_BUILD'] ?? ''));
    if ($raw === '' || !preg_match('/^[0-9]{1,10}$/D', $raw)) {
        return null;
    }
    $value = (int)$raw;
    return ($value >= 1 && $value <= 4294967295) ? $value : null;
}

/**
 * Fleet context: anonymous installation id. Same UUID shape and validation
 * the public telemetry route applies to its installation_id field, so the
 * two channels become joinable on one stable anonymous identity. Absent or
 * malformed header = null = not observed (never a request rejection).
 */
function apiSyncMonitorInstallationId(): ?string {
    $value = strtolower(trim((string)($_SERVER['HTTP_X_INSTALLATION_ID'] ?? '')));
    if ($value === ''
        || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
        return null;
    }
    return $value;
}

/** @return array<string,mixed> */
function apiSyncMonitorContext(int $userId, string $scope, string $clientOpId): array {
    return [
        'user_id' => $userId,
        'scope' => $scope,
        'client_op_id' => $clientOpId,
        'attempt_uid' => function_exists('apiClientAttemptId') ? apiClientAttemptId() : '',
        'attempt_number' => apiSyncMonitorAttemptNumber(),
        'execution_source' => apiSyncMonitorExecutionSource(),
        'request_id' => function_exists('apiRequestId') ? apiRequestId() : '',
        'entity_ref' => $GLOBALS['_fkss_sync_entity_ref'] ?? null,
        'app_version' => apiSyncMonitorAppVersion(),
        'app_build' => apiSyncMonitorAppBuild(),
        'installation_id' => apiSyncMonitorInstallationId(),
    ];
}

function apiIdempotencyBegin(
    int $userId,
    ?string $fromBody = null,
    ?string $explicitRequestHash = null
): void {
    $bodyKey = trim((string)$fromBody);
    if ($bodyKey !== '' && empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $bodyKey;
    }
    $key = apiIdempotencyKey();
    if ($key === '') {
        if ($bodyKey !== '' || !empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
            err('Invalid idempotency key.', 400);
        }
        return;
    }
    if ($userId <= 0) {
        err('Authentication is required for idempotent writes.', 401);
    }

    global $ROUTE;
    $method = function_exists('getMethod') ? getMethod() : (string)($_SERVER['REQUEST_METHOD'] ?? 'POST');
    $route = is_array($ROUTE) ? (string)($ROUTE['full_route'] ?? '') : '';
    if ($route === '') {
        $route = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/api/v1'), PHP_URL_PATH) ?: '/api/v1');
    }
    $scope = substr(strtoupper($method) . ' ' . $route, 0, 255);
    if ($explicitRequestHash !== null
        && !preg_match('/^[a-f0-9]{64}$/D', $explicitRequestHash)) {
        err('Invalid explicit idempotency request hash.', 500);
    }
    $requestHash = $explicitRequestHash
        ?? apiIdempotencyRequestHash(strtoupper($method), $scope);
    $service = apiIdempotencyService();
    $result = $service->begin($userId, $key, $scope, $requestHash);
    $monitorContext = apiSyncMonitorContext($userId, $scope, $key);
    $monitor = null;
    global $conn;

    if (($result['state'] ?? '') === 'acquired') {
        $monitor = \App\Services\ApiSyncAttemptMonitorService::recordStart($conn, $monitorContext);
        $GLOBALS['_fkss_idem'] = [
            'service' => $service,
            'reservation' => $result,
            'monitor_id' => $monitor,
        ];
        return;
    }
    if (($result['state'] ?? '') === 'replay') {
        \App\Services\ApiSyncAttemptMonitorService::recordEvent(
            $conn,
            $monitorContext,
            'replayed',
            'replayed',
            (int)($result['status_code'] ?? 200),
            'replayed'
        );
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code((int)($result['status_code'] ?? 200));
            header('Content-Type: application/json; charset=utf-8');
            header('Idempotency-Replayed: true');
            header('X-Request-Id: ' . apiRequestId());
        }
        echo (string)($result['body'] ?? '');
        exit;
    }
    if (($result['state'] ?? '') === 'conflict') {
        \App\Services\ApiSyncAttemptMonitorService::recordEvent(
            $conn, $monitorContext, 'rejected', 'conflict', 409, 'not_retryable', 'conflict', 'IDEMPOTENCY_CONFLICT'
        );
        err('This idempotency key was already used with a different request.', 409,
            ['code' => 'IDEMPOTENCY_CONFLICT']);
    }
    if (($result['state'] ?? '') === 'processing') {
        \App\Services\ApiSyncAttemptMonitorService::recordEvent(
            $conn, $monitorContext, 'rejected', 'processing', 409, 'retryable', 'conflict', 'IDEMPOTENCY_IN_PROGRESS'
        );
        if (!headers_sent()) {
            header('Retry-After: ' . max(1, (int)($result['retry_after'] ?? 1)));
        }
        err('A request with this idempotency key is still processing.', 409,
            ['code' => 'IDEMPOTENCY_IN_PROGRESS']);
    }
    \App\Services\ApiSyncAttemptMonitorService::recordEvent(
        $conn, $monitorContext, 'failed', 'unavailable', 503, 'retryable', 'server', 'IDEMPOTENCY_UNAVAILABLE'
    );
    err('Idempotency service is temporarily unavailable. Please retry safely.', 503);
}

/**
 * Atomically finalise the in-flight idempotency reservation inside the caller's
 * ALREADY-OPEN business transaction (S2, F-20).
 *
 * Call this as the last statement before the business COMMIT, for routes whose
 * business effect is NOT convergent under re-execution. After it returns true
 * the later apiIdempotencyStore() is a no-op, because complete() only matches
 * rows still in `processing`.
 *
 * Returns false when atomic completion was not possible (file-backend fallback,
 * oversized body, or already finalised); the caller simply proceeds, leaving
 * pre-S2 behaviour intact.
 *
 * MUST only be called with a transaction open, otherwise the UPDATE autocommits
 * and reintroduces the very window it exists to close.
 */
function apiIdempotencyCompleteAtomically(string $json, int $code): bool {
    $pack = $GLOBALS['_fkss_idem'] ?? null;
    if (!is_array($pack)
        || !(($pack['service'] ?? null) instanceof \App\Services\ApiIdempotencyService)
        || !is_array($pack['reservation'] ?? null)) {
        return false;
    }
    if ($code === 429) {
        return false;
    }
    $completed = $pack['service']->completeWithinTransaction($pack['reservation'], $json, $code);
    $monitorId = (int)($pack['monitor_id'] ?? 0);
    if ($monitorId > 0 && $completed) {
        global $conn;
        if ($conn instanceof \mysqli) {
            \App\Services\ApiSyncAttemptMonitorService::completeWithinTransaction(
                $conn, $monitorId, $json, $code
            );
        }
    }
    return $completed;
}

/** A DB-backed attendance success could not join its business transaction. */
final class ApiIdempotencyResponseNotRecorded extends \RuntimeException {}

/**
 * Strict for acquired database reservations only. Caller owns an OPEN business
 * transaction on the same connection. Legacy unkeyed/file modes return false;
 * that compatibility result is NOT an atomic-response guarantee.
 */
function apiIdempotencyCompleteDatabaseSuccess($data, int $code): bool {
    $pack = $GLOBALS['_fkss_idem'] ?? null;
    if ($pack === null && apiIdempotencyKey() === '') return false;
    if (!is_array($pack)
        || !(($pack['service'] ?? null) instanceof \App\Services\ApiIdempotencyService)
        || !is_array($pack['reservation'] ?? null)) {
        throw new ApiIdempotencyResponseNotRecorded('Missing response reservation.');
    }
    $backend = $pack['reservation']['backend'] ?? '';
    if ($backend === 'file') return false;
    if ($backend !== 'database') {
        throw new ApiIdempotencyResponseNotRecorded('Unknown response backend.');
    }
    try {
        $json = apiEncodeJsonResponse(apiSuccessPayload($data));
        // Keep this the last authoritative write. The permissive wrapper also
        // performs advisory monitor SQL whose swallowed deadlock could roll
        // back the whole transaction. Monitoring belongs in the existing
        // ordinary response sender AFTER business commit instead.
        if ($json === false || !$pack['service']->completeWithinTransaction(
            $pack['reservation'], $json, $code
        )) {
            throw new ApiIdempotencyResponseNotRecorded('Response was not recorded.');
        }
    } catch (ApiIdempotencyResponseNotRecorded $error) {
        throw $error;
    } catch (\Throwable $error) {
        throw new ApiIdempotencyResponseNotRecorded('Response was not recorded.', 0, $error);
    }
    return true;
}

/**
 * Only for the typed PRECOMMIT response failure, never an unknown commit result.
 * Detach the sender first: even an outer exception response must not be cached.
 * A failed/throwing rollback must leave the durable reservation untouched.
 */
function apiIdempotencyRollbackResponse(\mysqli $connection): void {
    $pack = $GLOBALS['_fkss_idem'] ?? null;
    unset($GLOBALS['_fkss_idem']); // Suppress ordinary AND outer error recording.
    if (!$connection->rollback()) {
        throw new \RuntimeException('Attendance rollback was not confirmed.');
    }
    if (is_array($pack)
        && ($pack['service'] ?? null) instanceof \App\Services\ApiIdempotencyService
        && is_array($pack['reservation'] ?? null)
        && ($pack['reservation']['backend'] ?? '') === 'database') {
        $pack['service']->abandon($pack['reservation']); // owner-token CAS
        $monitorId = (int)($pack['monitor_id'] ?? 0);
        if ($monitorId > 0) {
            \App\Services\ApiSyncAttemptMonitorService::complete(
                $connection, $monitorId,
                '{"status":"error","code":"IDEMPOTENCY_RESPONSE_NOT_RECORDED"}',
                503, 'abandoned'
            );
        }
    }
}

function apiIdempotencyStore(string $json, int $code): void {
    $pack = $GLOBALS['_fkss_idem'] ?? null;
    unset($GLOBALS['_fkss_idem']);
    if (!is_array($pack)
        || !(($pack['service'] ?? null) instanceof \App\Services\ApiIdempotencyService)
        || !is_array($pack['reservation'] ?? null)) {
        return;
    }

    if ($code === 429) {
        $pack['service']->abandon($pack['reservation']);
        $monitorId = (int)($pack['monitor_id'] ?? 0);
        global $conn;
        if ($monitorId > 0 && $conn instanceof \mysqli) {
            \App\Services\ApiSyncAttemptMonitorService::complete($conn, $monitorId, $json, $code, 'abandoned');
        }
        return;
    }
    $pack['service']->complete($pack['reservation'], $json, $code);
    $monitorId = (int)($pack['monitor_id'] ?? 0);
    global $conn;
    if ($monitorId > 0 && $conn instanceof \mysqli) {
        \App\Services\ApiSyncAttemptMonitorService::complete($conn, $monitorId, $json, $code);
    }
}

/** Shared multi-instance limiter used by every API boundary. */
function apiSecurityRateLimiter(): \App\Services\SecurityRateLimiter {
    static $rateLimiter = null;
    global $pdo;

    if (!$rateLimiter instanceof \App\Services\SecurityRateLimiter) {
        $database = $pdo instanceof \PDO ? $pdo : null;
        $rateLimiter = new \App\Services\SecurityRateLimiter(
            $database,
            ROOT_PATH . '/admin/uploads/cache'
        );
    }
    return $rateLimiter;
}

/** @return array{allowed:bool,retry_after:int} */
function apiConsumeRateLimit(
    string $action,
    string $subject,
    int $limit,
    int $windowSeconds
): array {
    $safeAction = preg_replace('/[^A-Za-z0-9._:-]/', '_', $action);
    return apiSecurityRateLimiter()->consume(
        'api:' . substr((string)$safeAction, 0, 59),
        $subject !== '' ? $subject : 'unknown',
        max(1, min($limit, 1000)),
        max(1, min($windowSeconds, 86400))
    );
}

/**
 * Enforce several user/IP buckets on one HTTP operation. Every bucket is
 * consumed so alternating identities cannot bypass the companion limit.
 *
 * @param array<int,array{action:string,subject:string,limit:int,window:int}> $rules
 */
function apiEnforceRateLimits(array $rules): void {
    $blocked = false;
    $retryAfter = 1;
    $publishedLimit = 1;
    foreach ($rules as $rule) {
        $limit = max(1, min((int)($rule['limit'] ?? 1), 1000));
        $result = apiConsumeRateLimit(
            (string)($rule['action'] ?? 'request'),
            (string)($rule['subject'] ?? 'unknown'),
            $limit,
            (int)($rule['window'] ?? 60)
        );
        if (!$result['allowed']) {
            $blocked = true;
            $retryAfter = max($retryAfter, (int)$result['retry_after']);
            $publishedLimit = $limit;
        }
    }
    if (!$blocked) {
        return;
    }
    if (!headers_sent()) {
        header('Retry-After: ' . max(1, $retryAfter));
        header('X-RateLimit-Limit: ' . $publishedLimit);
    }
    err('Too many requests. Please try again later.', 429, [
        'code' => 'RATE_LIMITED',
        'retry_after' => max(1, $retryAfter),
    ]);
}

/**
 * Atomic API rate limiting per client address + endpoint.
 *
 * Idempotency keys never bypass throttling: they are caller-controlled and are
 * only a replay-safety mechanism. Existing login/authentication limits retain
 * their one-minute convention.
 */
function isApiRateLimited($endpoint, $maxPerMinute = 60) {
    $safeEndpoint = preg_replace('/[^A-Za-z0-9._:-]/', '_', (string)$endpoint);
    $limit = max(1, min((int)$maxPerMinute, 1000));
    $result = apiConsumeRateLimit(
        (string)$safeEndpoint,
        (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        $limit,
        60
    );
    if (!$result['allowed'] && !headers_sent()) {
        header('Retry-After: ' . max(1, (int)$result['retry_after']));
        header('X-RateLimit-Limit: ' . $limit);
    }

    return !$result['allowed'];
}
