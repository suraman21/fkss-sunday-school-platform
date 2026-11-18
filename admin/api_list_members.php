<?php
/**
 * Member directory API controller.
 *
 * Query policy and role-aware data projection live in MemberDirectoryService;
 * browser rendering and interaction live in admin/js/manage-members.js.
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

    $service = new MemberDirectoryService($pdo);
    $role = (string)($_SESSION['admin_role'] ?? '');
    $view = trim((string)($_GET['view'] ?? ''));

    $sections = ($_GET['include_options'] ?? '') === '1'
        ? $service->sections()
        : [];

    // P2a directory cutover: `q` searches are served by the ranked engine
    // when the flag is on and the engine can serve; a null falls through
    // to today's MySQL matching below. No UI changes needed — same params,
    // same shape, plus an honest `engine` field (tnt|mysql).
    $engine = 'mysql';
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '' && MemberSearchService::$directoryCutover) {
        $rankedPage = MemberSearchService::rankedDirectoryPage($pdo, $service, $_GET, $role, $view, $q);
        if ($rankedPage !== null) {
            $engine = 'tnt';
            echo json_encode([
                'status' => 'success',
                'engine' => $engine,
                'members' => $rankedPage['members'],
                'options' => ['sections' => $sections],
                'total' => $rankedPage['total'],
                'page' => $rankedPage['page'],
                'limit' => $rankedPage['limit'],
                'pages' => $rankedPage['pages'],
                'next_cursor' => $rankedPage['next_cursor'],
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    }

    $result = $service->search($_GET, $role, $view);
    echo json_encode([
        'status' => 'success',
        'engine' => $engine,
        'members' => $result['members'],
        'options' => ['sections' => $sections],
        'total' => $result['total'],
        'page' => $result['page'],
        'limit' => $result['limit'],
        'pages' => $result['pages'],
        'next_cursor' => $result['next_cursor'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $error) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'The requested directory view is not available.']);
} catch (Throwable $error) {
    reportInternalError('Member directory request failed', $error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not load members.']);
}
