<?php
/**
 * School API v1 — Response Helpers
 * Standard JSON response format for all endpoints
 */

/**
 * Send success response
 * @param mixed $data Response data
 * @param int $code HTTP status code
 */
function ok($data = null, $code = 200) {
    apiSendJson(apiSuccessPayload($data), $code);
}

/** Build the unchanged success envelope before an atomic business commit. */
function apiSuccessPayload($data = null): array {
    $response = ['status' => 'success'];
    if ($data !== null) {
        if (is_array($data) && isset($data['message'])) {
            $response['message'] = $data['message'];
            unset($data['message']);
            if (!empty($data)) $response['data'] = $data;
        } else {
            $response['data'] = $data;
        }
    }
    return $response;
}

/**
 * Send error response
 * @param string $message Error message
 * @param int $code HTTP status code
 * @param array $extra Additional error data
 */
function err($message, $code = 400, $extra = []) {
    $response = ['status' => 'error', 'message' => $message];
    if (!empty($extra)) $response = array_merge($response, $extra);
    apiSendJson($response, $code);
}

/**
 * Opaque per-request correlation id (S1).
 *
 * One value per HTTP request, generated once and reused, so the response
 * header, the error log and any diagnostics all name the same transmission.
 * It is random and carries no token, user id or payload. A client attempt id
 * is accepted and echoed only after strict validation, so a caller cannot
 * inject log content or oversized data through it.
 */
function apiRequestId(): string {
    static $requestId = null;
    if ($requestId !== null) {
        return $requestId;
    }
    try {
        $requestId = 'req_' . bin2hex(random_bytes(12));
    } catch (\Throwable $ignored) {
        $requestId = 'req_' . substr(hash('sha256', uniqid('', true)), 0, 24);
    }
    return $requestId;
}

/**
 * The client's attempt correlation id, if it sent a well-formed one.
 *
 * Deliberately strict: an opaque token of bounded length and a fixed
 * alphabet. Anything else is ignored rather than sanitised, because this
 * value reaches the server log.
 */
function apiClientAttemptId(): string {
    $value = trim((string)($_SERVER['HTTP_X_CLIENT_ATTEMPT_ID'] ?? ''));
    if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
        return '';
    }
    return $value;
}

/** @return string|false Identical encoding for precommit and wire responses. */
function apiEncodeJsonResponse(array $response) {
    // Module version handshake (see routes/mezmur.php): lets clients
    // detect a stale server and show an actionable update message.
    if (defined('MEZMUR_API_VERSION') && !isset($response['server_meta'])) {
        $response['server_meta'] = ['mezmur' => MEZMUR_API_VERSION];
    }
    return json_encode($response, JSON_UNESCAPED_UNICODE);
}

function apiSendJson(array $response, int $code = 200) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        // S1 correlation. Additive and advisory: older clients ignore it.
        header('X-Request-Id: ' . apiRequestId());
    }
    $json = apiEncodeJsonResponse($response);
    if ($json === false) {
        $json = '{"status":"error","message":"Could not encode response."}';
        $code = 500;
        if (!headers_sent()) http_response_code($code);
    }
    if (function_exists('apiIdempotencyStore')) {
        apiIdempotencyStore($json, $code);
    }
    echo $json;
    exit;
}

/**
 * Send paginated list response
 */
function paginated($items, $total, $page, $limit) {
    ok([
        'items' => $items,
        'pagination' => [
            'total' => (int)$total,
            'page' => (int)$page,
            'limit' => (int)$limit,
            'pages' => (int)ceil($total / max($limit, 1)),
            'has_more' => ($page * $limit) < $total
        ]
    ]);
}

function getPagination($maxLimit = 100) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min($maxLimit, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    return [$page, $limit, $offset];
}

function getBody(?int $maxBytes = null) {
    if ($maxBytes !== null) {
        $maxBytes = max(1, $maxBytes);
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (is_string($raw) && strlen($raw) > $maxBytes) {
            err('Request body too large.', 413);
        }
    } else {
        $raw = file_get_contents('php://input');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
        // The byte budget above bounds php://input only. Form posts — most
        // notably multipart/form-data, which never appears in php://input —
        // would otherwise reach validation bounded only by post_max_size.
        // Re-encode the fallback payload and hold it to the same budget.
        if ($maxBytes !== null && $data !== []) {
            $formSize = strlen(http_build_query($data));
            if ($formSize > $maxBytes) {
                err('Request body too large.', 413);
            }
        }
    }
    // Keep only a bounded, allow-listed entity projection for the optional
    // server sync monitor. The request body itself is never retained here.
    if (class_exists('\\App\\Services\\ApiSyncAttemptMonitorService')) {
        $GLOBALS['_fkss_sync_entity_ref'] =
            \App\Services\ApiSyncAttemptMonitorService::entityReference($data);
    }
    return $data;
}
