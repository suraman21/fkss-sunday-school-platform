<?php
/**
 * Search-index build endpoint (P0, super_admin only).
 *
 * POST action=backfill (default): index the next bounded batch of members.
 *   Params: after_id (cursor), limit (1–500). Resume with next_cursor until
 *   done=true. Safe to call repeatedly; idempotent per member.
 * POST action=rebuild: WIPE the index and start over from 0. Requires
 *   confirm=rebuild-index. The first backfill batch runs in the same call.
 * GET (no writes): engine diagnostics (availability, index stats).
 *
 * Every response carries the engine state honestly so operators can see
 * whether search is served by TNT or by the MySQL fallback.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/MemberSearchService.php';

use App\Services\MemberSearchService;

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
if ((string)($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Super admin only.']);
    exit;
}

$diagnostics = static function (): array {
    return [
        'available' => MemberSearchService::available(),
        'index' => MemberSearchService::stats(),
        'index_version' => MemberSearchService::VERSION,
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['status' => 'success'] + $diagnostics(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

try {
    // Fail-closed CSRF like api_import_members.php (form field or header).
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!function_exists('validateCsrf') || !validateCsrf($csrf)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh and try again.'] + $diagnostics());
        exit;
    }
    if (!isset($conn) || $conn->connect_error) {
        throw new RuntimeException('Database connection error.');
    }
    $action = trim((string)($_POST['action'] ?? 'backfill'));
    if ($action === 'rebuild') {
        if (trim((string)($_POST['confirm'] ?? '')) !== 'rebuild-index') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Rebuild needs confirm=rebuild-index.'] + $diagnostics());
            exit;
        }
        MemberSearchService::rebuildStart();
        $result = MemberSearchService::backfill($conn, 0, (int)($_POST['limit'] ?? 100));
        echo json_encode(['status' => 'success', 'action' => 'rebuild'] + $result + $diagnostics(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action !== 'backfill') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Unknown action.'] + $diagnostics());
        exit;
    }
    $result = MemberSearchService::backfill(
        $conn,
        (int)($_POST['after_id'] ?? 0),
        (int)($_POST['limit'] ?? 100)
    );
    echo json_encode(['status' => 'success', 'action' => 'backfill'] + $result + $diagnostics(), JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    // An InvalidArgumentException here is a caller-facing validation message,
    // so the text is intentional -- but it is NOT safe to assume that forever:
    // the class name only promises "invalid argument", and the message is
    // whatever the thrower put in it. Log it in full, return it only when it
    // is short and free of the markers that indicate a leaked internals
    // message (file paths, SQLSTATE, "SQL", parameter dumps).
    reportInternalError('Search backfill rejected the request', $error);
    $safe = $error->getMessage();
    if (strlen($safe) > 200
        || preg_match('/(SQLSTATE|\\/|\bSQL\b|near \"|column |table )/i', $safe)) {
        $safe = 'The request was rejected as invalid.';
    }
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $safe] + $diagnostics());
} catch (Throwable $error) {
    reportInternalError('Search backfill failed', $error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Backfill failed.'] + $diagnostics());
}
