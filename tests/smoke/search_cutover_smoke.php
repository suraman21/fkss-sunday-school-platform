<?php
// Test/deployment tooling is CLI-only; deny before loading credentials or data.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * P2a directory-cutover smoke (run against a disposable DB).
 * Needs: MySQL + pdo_sqlite + vendor installed. Skips (exit 0) without
 * pdo_sqlite; fails loudly without MySQL (same as the other smokes).
 *
 * Exercises MemberSearchService::rankedDirectoryPage() — the shared engine
 * path behind api_list_members.php `q` searches and api_search.php:
 *  - served page shape (members/total/page/limit/pages/next_cursor/stages)
 *  - exact code ranks first; Ethiopic + Latin queries; rank slicing (page 2)
 *  - engine recall ∩ MySQL filters (status) and archived exclusion
 *  - include_total=0 parity (total/pages null, rows still served)
 *  - cursor/sort params ignored safely (no crash, correct rows)
 *  - flag off → null; missing index → null (caller falls back to MySQL)
 *  - bad role → null (the fallthrough re-throws as the usual 403)
 *
 * Uses the real admin/var/search/ index path (wiped + rebuilt here).
 * Never run against a database that is not disposable (guard below).
 * Exits non-zero with a message on the first failure.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$fail = function (string $msg): void { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };
$pass = function (string $msg): void { echo "  ok: $msg\n"; };

if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite missing (engine unavailable by design)\n";
    exit(0);
}

$root = dirname(__DIR__, 2);
require_once __DIR__ . '/../e2e/destructive_guard.php';
ssms_require_disposable_database('ssms_smoke', basename(__FILE__));

require $root . '/admin/backend/services/MemberSearchService.php';
require $root . '/admin/backend/services/MemberDirectoryService.php';

use App\Services\MemberDirectoryService;
use App\Services\MemberSearchService;

$conn = @new mysqli('127.0.0.1', 'ssms', 'ssms', 'ssms_smoke');
if ($conn->connect_errno) {
    $fail('db connect: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=ssms_smoke;charset=utf8mb4', 'ssms', 'ssms', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $error) {
    $fail('pdo connect: ' . $error->getMessage());
}

// Minimal members table: indexed columns + manager projection + filters.
$conn->query('DROP TABLE IF EXISTS members');
$created = $conn->query(
    'CREATE TABLE members (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        member_code VARCHAR(30) NULL,
        registration_type VARCHAR(20) NOT NULL DEFAULT \'direct\',
        member_type VARCHAR(20) NOT NULL DEFAULT \'regular\',
        status VARCHAR(20) NOT NULL DEFAULT \'active\',
        student_name VARCHAR(150) NOT NULL DEFAULT \'\',
        father_name VARCHAR(150) NOT NULL DEFAULT \'\',
        grandfather_name VARCHAR(150) NULL,
        full_name_am VARCHAR(150) NOT NULL DEFAULT \'\',
        baptismal_name VARCHAR(150) NULL,
        phone_number VARCHAR(30) NULL,
        work_profession VARCHAR(150) NULL,
        city VARCHAR(60) NULL,
        sub_city VARCHAR(60) NULL,
        education_level VARCHAR(60) NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
if (!$created) {
    $fail('create members: ' . $conn->error);
}

$seed = [
    // code, reg_type, member_type, status, student, father, gfather, full_am
    ['C-00001', 'direct', 'regular', 'active', 'አበበ', 'ከበደ', '', 'አበበ ከበደ'],
    ['C-00002', 'direct', 'regular', 'active', 'ሐኪም', 'ታደሰ', '', 'ሐኪም ታደሰ'],
    ['C-00003', 'direct', 'regular', 'active', 'ሀኪም', 'አለሙ', '', 'ሀኪም አለሙ'],
    ['C-00004', 'direct', 'regular', 'active', 'Selamawit', 'Tadesse', '', 'Selamawit Tadesse'],
    ['C-00005', 'direct', 'regular', 'active', 'ሰላም', 'ገብረ', '', 'ሰላም ገብረ'],
    ['C-00006', 'direct', 'regular', 'archived', 'ሙሉ', 'ተስፋ', '', 'ሙሉ ተስፋ'],
];
$stmt = $conn->prepare(
    'INSERT INTO members (member_code, registration_type, member_type, status,
     student_name, father_name, grandfather_name, full_name_am)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
if (!$stmt) {
    $fail('seed prepare: ' . $conn->error);
}
foreach ($seed as $row) {
    $stmt->bind_param('ssssssss', ...$row);
    if (!$stmt->execute()) {
        $fail('seed insert: ' . $stmt->error);
    }
}
$stmt->close();

MemberSearchService::rebuildStart();
$res = MemberSearchService::backfill($conn, 0, 500);
if (($res['error'] ?? null) !== null || !$res['done'] || $res['updated'] !== 6) {
    $fail('backfill 6 members: ' . json_encode($res));
}
$pass('seeded 6 members, indexed 6/6');

$service = new MemberDirectoryService($pdo);
$idsOf = static function (array $page): array {
    return array_map(static function (array $m): int { return (int)$m['id']; }, $page['members']);
};

/** @return array<string,mixed> */
$pageFor = function (string $q, array $params = [], string $role = 'super_admin', string $view = 'manager') use ($fail, $service, $pdo): array {
    $page = MemberSearchService::rankedDirectoryPage($pdo, $service, $params, $role, $view, $q);
    if ($page === null) {
        $fail("query '$q' was not served by the engine (params: " . json_encode($params) . ')');
    }
    foreach (['members', 'total', 'page', 'limit', 'pages', 'next_cursor', 'stages'] as $key) {
        if (!array_key_exists($key, $page)) {
            $fail("served page misses key '$key'");
        }
    }
    if ($page['next_cursor'] !== null) {
        $fail('served page must carry next_cursor=null (rank order has no id cursor)');
    }
    return $page;
};

// ── Ethiopic query: fold twins served, manager projection intact ──
$page = $pageFor('ሐኪም');
$ids = $idsOf($page);
if ($ids !== [2, 3] && $ids !== [3, 2]) {
    $fail("'ሐኪም' should serve exactly #2+#3, got: " . implode(',', $ids));
}
if ($page['total'] !== 2 || $page['page'] !== 1 || $page['pages'] !== 1) {
    $fail("'ሐኪም' paging wrong: " . json_encode(['total' => $page['total'], 'pages' => $page['pages']]));
}
$row = $page['members'][0];
foreach (['id', 'member_code', 'registration_type', 'member_type', 'student_name', 'father_name'] as $col) {
    if (!array_key_exists($col, $row)) {
        $fail("manager projection misses column '$col'");
    }
}
$pass("Ethiopic query serves twins with manager projection (total=2)");

// ── exact code ranks first ──
$page = $pageFor('C-00004');
if ($idsOf($page)[0] !== 4) {
    $fail("code query should rank #4 first, got: " . implode(',', $idsOf($page)));
}
$pass('exact code ranks first');

// ── Latin query: exact before prefix ──
$page = $pageFor('selam');
if ($idsOf($page)[0] !== 5 || !in_array(4, $idsOf($page), true)) {
    $fail("'selam' should lead with #5 and include #4, got: " . implode(',', $idsOf($page)));
}
$pass('Latin query leads exact, includes prefix match');

// ── rank slicing: page 2 ──
$all = $idsOf($pageFor('ሐኪም', ['limit' => 50]));
$p1 = $idsOf($pageFor('ሐኪም', ['limit' => 1, 'page' => 1]));
$p2 = $pageFor('ሐኪም', ['limit' => 1, 'page' => 2]);
if ($idsOf($p2) !== [$all[1]] || $p2['page'] !== 2 || $p2['pages'] !== 2 || $p2['total'] !== 2) {
    $fail('page-2 slice wrong: ' . json_encode($p2['members']));
}
if ($p1 === $idsOf($p2)) {
    $fail('page 1 and page 2 serve the same row');
}
$pass('rank order slices correctly across pages');

// ── engine recall ∩ MySQL filters ──
$page = $pageFor('ሐኪም', ['status' => 'archived']);
if ($page['members'] !== [] || $page['total'] !== 0) {
    $fail('status=archived should intersect to empty, got: ' . implode(',', $idsOf($page)));
}
$pass('status filter intersects engine recall');
$page = $pageFor('ሙሉ');
if ($page['members'] !== [] || $page['total'] !== 0) {
    $fail('archived #6 must stay hidden by the default filter, got: ' . implode(',', $idsOf($page)));
}
$pass('default filter still hides archived members');

// ── include_total=0 parity ──
$page = $pageFor('ሐኪም', ['include_total' => '0']);
if ($page['total'] !== null || $page['pages'] !== null || count($page['members']) !== 2) {
    $fail('include_total=0 must null totals but keep rows');
}
$pass('include_total=0 returns rows with null totals');

// ── cursor/sort ignored safely ──
$page = $pageFor('ሐኪም', ['cursor' => 2, 'sort' => 'id', 'direction' => 'desc']);
if ($idsOf($page) !== $all) {
    $fail('id cursor must not filter rank results, got: ' . implode(',', $idsOf($page)));
}
$pass('stale id cursor ignored without breaking rank results');

// ── nonsense query: honest empty ──
$page = $pageFor('zzzqqq');
if ($page['members'] !== [] || $page['total'] !== 0 || $page['page'] !== 1) {
    $fail('nonsense query must serve an honest empty page');
}
$pass('nonsense query serves honest empty page');

// ── bad role → null (controller fallthrough re-throws the usual 403) ──
$denied = MemberSearchService::rankedDirectoryPage($pdo, $service, [], 'nobody', 'manager', 'ሐኪም');
if ($denied !== null) {
    $fail('bad role should return null so the fallthrough enforces the 403');
}
$pass('bad role returns null (403 stays enforced by the fallthrough)');

// ── flag off → null (rollback path) ──
MemberSearchService::$directoryCutover = false;
try {
    $off = MemberSearchService::rankedDirectoryPage($pdo, $service, [], 'super_admin', 'manager', 'ሐኪም');
    if ($off !== null) {
        $fail('flag off must return null');
    }
    $pass('flag off returns null (rollback path)');
} finally {
    MemberSearchService::$directoryCutover = true;
}

// ── missing index → null (MySQL fallback contract) ──
$indexPath = MemberSearchService::indexPath();
$hiddenPath = $indexPath . '.hidden-by-smoke';
if (!rename($indexPath, $hiddenPath)) {
    $fail('could not hide the index for the fallback check');
}
$fallback = MemberSearchService::rankedDirectoryPage($pdo, $service, [], 'super_admin', 'manager', 'ሐኪም');
if ($fallback !== null) {
    rename($hiddenPath, $indexPath);
    $fail('missing index did not return null (fallback broken)');
}
$pass('missing index returns null (MySQL fallback contract)');
if (!rename($hiddenPath, $indexPath)) {
    $fail('could not restore the index');
}
$pageFor('ሐኪም');
$pass('index restored and serving again');

echo "SEARCH CUTOVER SMOKE: ALL GREEN\n";
