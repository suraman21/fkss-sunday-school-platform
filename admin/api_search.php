<?php
/**
 * Unified member search API (P0 search engine).
 *
 * Same request/response contract as api_list_members.php, but the `q`
 * parameter is resolved by the ranked engine cascade instead of MySQL
 * matching: exact code → exact normalized name → TNT boolean AND →
 * relevance + as-you-type → fuzzy. Role projection, filters and pagination
 * are still enforced by MemberDirectoryService — the engine only supplies
 * ranked IDs, and rows are re-sorted by rank after the filtered fetch.
 *
 * Honest fallback: when the engine is unavailable (index not built,
 * pdo_sqlite missing, any error), the request is served by today's MySQL
 * `q` matching and the response says so ("engine":"mysql_fallback").
 * Search never goes down because of the engine.
 *
 * P0 serves members only; other entities migrate per module (P2) behind
 * their own flags. No UI points here yet — UI cutover is P2a.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/MemberDirectoryService.php';
require_once __DIR__ . '/backend/services/AmharicIdentityNormalizer.php';
require_once __DIR__ . '/backend/services/MemberSearchService.php';

use App\Services\AmharicIdentityNormalizer;
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

/**
 * Exact-name IDs from the canonical norm columns (multi-word aware via the
 * first/father pair hash). Empty when canonical keys are unavailable.
 * @return int[]
 */
function search_exact_name_ids(PDO $pdo, string $q): array
{
    $ids = [];
    try {
        $split = AmharicIdentityNormalizer::splitFullName($q);
        if ($split['pair_hash'] !== '') {
            $stmt = $pdo->prepare('SELECT id FROM members WHERE name_pair_hash = ? LIMIT 20');
            $stmt->execute([$split['pair_hash']]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ids[] = (int)$row['id'];
            }
            return $ids;
        }
        $single = $split['student'] !== '' ? $split['student'] : $split['full'];
        if ($single === '') {
            return $ids;
        }
        $stmt = $pdo->prepare(
            'SELECT id FROM members WHERE student_name_norm = ? OR father_name_norm = ? LIMIT 20'
        );
        $stmt->execute([$single, $single]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ids[] = (int)$row['id'];
        }
    } catch (Throwable $ignored) {
        // Canonical columns missing (pre-migration DB): skip the stage.
    }
    return $ids;
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

    // Stage 1 exacts (DB): member code, then canonical normalized name.
    $exactIds = [];
    try {
        $stmt = $pdo->prepare('SELECT id FROM members WHERE member_code = ? LIMIT 5');
        $stmt->execute([$q]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $exactIds[] = (int)$row['id'];
        }
    } catch (Throwable $ignored) {
    }
    foreach (search_exact_name_ids($pdo, $q) as $id) {
        if (!in_array($id, $exactIds, true)) {
            $exactIds[] = $id;
        }
    }

    $ranked = MemberSearchService::searchRanked($q, MemberSearchService::TOP_K, $exactIds);
    if ($ranked === null) {
        // Engine unavailable → today's MySQL matching (honest fallback).
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

    // Engine served zero hits: honest empty page (never fall through to an
    // unfiltered listing — an empty ID set must stay empty).
    if ($ranked['ids'] === []) {
        echo json_encode([
            'status' => 'success',
            'engine' => 'tnt',
            'stages' => $ranked['stages'],
            'options' => ['sections' => $sections],
            'members' => [],
            'total' => 0,
            'page' => 1,
            'limit' => max(1, min(MemberDirectoryService::MAX_PAGE_SIZE, (int)($_GET['limit'] ?? MemberDirectoryService::DEFAULT_PAGE_SIZE))),
            'pages' => 1,
            'next_cursor' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // Engine path: filtered fetch over ranked IDs, then restore rank order.
    // P0 pages in PHP over a bounded recall set (TOP_K); cursor semantics
    // for infinite scroll arrive with the UI cutover (P2a).
    $params = $_GET;
    unset($params['q']);
    $params['ids'] = $ranked['ids'];
    $params['limit'] = MemberSearchService::TOP_K;
    $params['page'] = 1;
    $params['include_total'] = '1';
    $result = $service->search($params, $role, $view);

    $rank = [];
    foreach ($ranked['ids'] as $position => $id) {
        $rank[(int)$id] = $position;
    }
    $members = $result['members'];
    usort($members, static function (array $a, array $b) use ($rank): int {
        return ($rank[(int)($a['id'] ?? 0)] ?? PHP_INT_MAX)
            <=> ($rank[(int)($b['id'] ?? 0)] ?? PHP_INT_MAX);
    });

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(MemberDirectoryService::MAX_PAGE_SIZE, (int)($_GET['limit'] ?? MemberDirectoryService::DEFAULT_PAGE_SIZE)));
    $total = count($members);
    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    $slice = array_slice($members, ($page - 1) * $limit, $limit);

    echo json_encode([
        'status' => 'success',
        'engine' => 'tnt',
        'stages' => $ranked['stages'],
        'options' => ['sections' => $sections],
        'members' => $slice,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => $pages,
        'next_cursor' => null,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $error) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'The requested directory view is not available.']);
} catch (Throwable $error) {
    reportInternalError('Unified search request failed', $error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not search members.']);
}
