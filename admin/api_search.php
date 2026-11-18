<?php
/**
 * Unified member search API (P0 search engine).
 *
 * Same request/response contract as api_list_members.php, but always makes
 * the engine choice explicit ("engine":"tnt"|"mysql_fallback"|"none") with
 * per-stage counts for diagnosis. The engine path itself is shared with the
 * directory cutover (MemberSearchService::rankedDirectoryPage) — one ranked
 * implementation, two envelopes. Role projection, filters and pagination
 * are enforced by MemberDirectoryService in both.
 *
 * P0 serves members only; other entities migrate per module (P2) behind
 * their own flags.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/MemberDirectoryService.php';
require_once __DIR__ . '/backend/services/MemberSearchService.php';

use App\Services\MemberDirectoryService;
use App\Services\MemberSearchService;

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Member directory database is unavailable.');
    }

    $role = (string)($_SESSION['admin_role'] ?? '');
    $view = trim((string)($_GET['view'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));
    $service = new MemberDirectoryService($pdo);
    $sections = ($_GET['include_options'] ?? '') === '1'
        ? $service->sections()
        : [];

    // No query = plain directory listing (engine uninvolved).
    if ($q === '') {
        $result = $service->search($_GET, $role, $view);
        echo json_encode([
            'status' => 'success',
            'engine' => 'none',
            'options' => ['sections' => $sections],
            'members' => $result['members'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'pages' => $result['pages'],
            'next_cursor' => $result['next_cursor'],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $rankedPage = MemberSearchService::rankedDirectoryPage($pdo, $service, $_GET, $role, $view, $q);
    if ($rankedPage === null) {
        // Engine unavailable (or flag off) → today's MySQL matching.
        $result = $service->search($_GET, $role, $view);
        echo json_encode([
            'status' => 'success',
            'engine' => 'mysql_fallback',
            'options' => ['sections' => $sections],
            'members' => $result['members'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'pages' => $result['pages'],
            'next_cursor' => $result['next_cursor'],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'engine' => 'tnt',
        'stages' => $rankedPage['stages'],
        'options' => ['sections' => $sections],
        'members' => $rankedPage['members'],
        'total' => $rankedPage['total'],
        'page' => $rankedPage['page'],
        'limit' => $rankedPage['limit'],
        'pages' => $rankedPage['pages'],
        'next_cursor' => $rankedPage['next_cursor'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $error) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'The requested directory view is not available.']);
} catch (Throwable $error) {
    reportInternalError('Unified search request failed', $error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not search members.']);
}
