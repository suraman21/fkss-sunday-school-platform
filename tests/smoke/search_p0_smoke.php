<?php
// Test/deployment tooling is CLI-only; deny before loading credentials or data.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * P0 search-engine full-stack smoke (run against a disposable DB).
 * Needs: MySQL + pdo_sqlite + vendor installed. Skips (exit 0) without
 * pdo_sqlite; fails loudly without MySQL (same as the other smokes).
 *
 * Exercises, against the REAL MemberSearchService index path:
 *  - rebuild + resumable cursor backfill (multi-batch) over 10 members
 *  - Ethiopic exact + verified-fold twins (ሐኪም ⇄ ሀኪም)
 *  - Latin transliteration queries (hakim/selam/tsion/tadesse/bekele/girma)
 *  - typo tolerance, multi-word AND, word-order freedom, as-you-type prefix
 *  - operator-word safety ("or"), empty + nonsense queries
 *  - caller exact IDs rank first; update/remove write paths
 *  - fallback contract (missing index → null) + stats()
 *
 * NOTE: uses the real admin/var/search/ index path (wiped + rebuilt here).
 * Re-run the backfill endpoint on dev after this smoke. Never run against
 * a database that is not disposable (guard enforced below).
 * Exits non-zero with a message on the first failure.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$fail = function (string $msg): void { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };
$pass = function (string $msg): void { echo "  ok: $msg\n"; };
$has = function (array $ids, int $id, string $msg) use ($fail, $pass): void {
    if (!in_array($id, $ids, true)) {
        $fail($msg . ' (ids: ' . implode(',', $ids) . ')');
    }
    $pass($msg);
};

if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite missing (engine unavailable by design)\n";
    exit(0);
}

$root = dirname(__DIR__, 2);
require_once __DIR__ . '/../e2e/destructive_guard.php';
ssms_require_disposable_database('ssms_smoke', basename(__FILE__));

require $root . '/admin/backend/services/MemberSearchService.php';

use App\Services\MemberSearchService;

if (!MemberSearchService::available()) {
    $fail('engine reports unavailable despite pdo_sqlite + vendor present');
}
$pass('engine available (vendor + pdo_sqlite)');

$conn = @new mysqli('127.0.0.1', 'ssms', 'ssms', 'ssms_smoke');
if ($conn->connect_errno) {
    $fail('db connect: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// Minimal members table: only columns the engine reads (smoke-only shape).
$conn->query('DROP TABLE IF EXISTS members');
$created = $conn->query(
    'CREATE TABLE members (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        member_code VARCHAR(30) NULL,
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
    // id auto: 1..10 in insert order
    ['C-00001', 'አበበ', 'ከበደ', '', 'አበበ ከበደ', '', '0911000001', 'መምህር', 'አዲስ አበባ', '', ''],
    ['C-00002', 'ሐኪም', 'ታደሰ', '', 'ሐኪም ታደሰ', '', '0911222333', '', '', '', ''],
    ['C-00003', 'ሀኪም', 'አለሙ', '', 'ሀኪም አለሙ', '', '', '', '', '', ''],
    ['C-00004', 'ጽዮን', 'ግርማ', '', 'ጽዮን ግርማ', '', '', 'ሐኪም', '', '', ''],
    ['C-00005', 'በቀለ', 'ተስፋዬ', '', 'በቀለ ተስፋዬ', '', '', 'Doctor', '', '', ''],
    ['C-00006', 'እውነት', 'ሰላም', '', 'እውነት ሰላም', '', '', '', '', '', ''],
    ['C-00007', 'Selamawit', 'Tadesse', '', 'Selamawit Tadesse', '', '', 'Student', '', '', ''],
    ['C-00008', 'ዳዊት', 'ዮናስ', '', 'ዳዊት ዮናስ', '', '', '', '', '', ''],
    ['C-00009', 'ሚካኤል', 'ገብረ', '', 'ሚካኤል ገብረ', '', '', '', '', '', ''],
    ['C-00010', 'ፍቅር', 'ነጋ', '', 'ፍቅር ነጋ', '', '', '', '', '', ''],
];
$stmt = $conn->prepare(
    'INSERT INTO members (member_code, student_name, father_name, grandfather_name,
     full_name_am, baptismal_name, phone_number, work_profession, city, sub_city, education_level)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if (!$stmt) {
    $fail('seed prepare: ' . $conn->error);
}
foreach ($seed as $row) {
    $stmt->bind_param('sssssssssss', ...$row);
    if (!$stmt->execute()) {
        $fail('seed insert: ' . $stmt->error);
    }
}
$stmt->close();
$pass('seeded 10 members (Amharic + Latin + fold twins)');

// ── rebuild + multi-batch backfill (limit 4 forces 3 batches) ──
MemberSearchService::rebuildStart();
$cursor = 0;
$batches = 0;
$totalUpdated = 0;
do {
    $res = MemberSearchService::backfill($conn, $cursor, 4);
    if (($res['error'] ?? null) !== null) {
        $fail('backfill: ' . $res['error']);
    }
    $batches++;
    $totalUpdated += $res['updated'];
    $cursor = $res['next_cursor'];
    if ($batches > 10) {
        $fail('backfill did not finish');
    }
} while (!$res['done']);
if ($totalUpdated !== 10) {
    $fail("backfill updated $totalUpdated, expected 10");
}
$pass("backfill complete in $batches batches (10/10 indexed)");

$stats = MemberSearchService::stats();
if (!$stats['exists'] || $stats['documents'] !== 10) {
    $fail('stats after backfill: ' . json_encode($stats));
}
$pass('stats: 10 documents indexed');

$ranked = static function (string $q, array $exact = []) use ($fail): array {
    $res = MemberSearchService::searchRanked($q, 100, $exact);
    if ($res === null || ($res['engine'] ?? '') !== 'tnt') {
        $fail("query '$q' did not use the engine: " . json_encode($res));
    }
    return $res['ids'];
};

// ── Ethiopic exact + fold twins ──
$has($ranked('ሐኪም'), 2, "Ethiopic 'ሐኪም' finds #2");
$has($ranked('ሐኪም'), 3, "fold twin: 'ሐኪም' also finds ሀኪም #3");
$has($ranked('ሀኪም'), 2, "fold twin reversed: 'ሀኪም' finds #2");
$has($ranked('ሐኪም'), 4, "profession token: 'ሐኪም' finds #4 (ሐኪም profession)");

// ── Latin transliteration queries ──
$has($ranked('hakim'), 2, "Latin 'hakim' finds #2");
$has($ranked('hakim'), 3, "Latin 'hakim' finds #3");
$has($ranked('selam'), 6, "Latin 'selam' finds #6 (father ሰላም)");
$has($ranked('tadesse'), 2, "doubled-s 'tadesse' finds #2 (ታደሰ)");
$has($ranked('tadesse'), 7, "doubled-s 'tadesse' finds Latin-stored #7");
$has($ranked('girma'), 4, "Latin 'girma' finds #4 (ግርማ)");
$has($ranked('tsion'), 4, "Latin 'tsion' finds #4 (ጽዮን)");
$has($ranked('bekele'), 5, "Latin 'bekele' finds #5 (በቀለ, loose q→k)");
$has($ranked('ewnet'), 6, "Latin 'ewnet' finds #6 (እውነት)");

// ── typo tolerance ──
$has($ranked('hakmi'), 2, "typo 'hakmi' still finds #2 (fuzzy)");

// ── multi-word AND + word order ──
$both = $ranked('አበበ ከበደ');
if ($both !== [1]) {
    $fail('AND query should return exactly #1, got: ' . implode(',', $both));
}
$pass('multi-word AND returns exactly #1');
$has($ranked('ከበደ አበበ'), 1, 'reversed word order still finds #1');

// ── as-you-type prefix ──
$has($ranked('sel'), 6, "prefix 'sel' finds #6");
$has($ranked('sel'), 7, "prefix 'sel' finds #7 (Selamawit)");

// ── code + phone tokens ──
$has($ranked('C-00001'), 1, "code 'C-00001' finds #1");
$has($ranked('0911222333'), 2, 'phone query finds #2');

// ── operator word / empty / nonsense safety ──
$orIds = $ranked('or');
$pass('"or" query served without boolean crash (' . count($orIds) . ' hits)');
if ($ranked('') !== []) {
    $fail('empty query should return zero ids');
}
$pass('empty query returns zero ids');
if ($ranked('zzzqqq') !== []) {
    $fail('nonsense query should return zero ids');
}
$pass('nonsense query returns zero ids');

// ── caller exacts rank first ──
$exactFirst = $ranked('zzzqqq', [5, 2]);
if (array_slice($exactFirst, 0, 2) !== [5, 2]) {
    $fail('exact IDs not ranked first: ' . implode(',', $exactFirst));
}
$pass('caller exact IDs rank first');

// ── write-through update + remove ──
// Real saves recompute full_name_am from the parts (canonical refresh),
// so the smoke updates both — student_name alone would be stale data.
$conn->query("UPDATE members SET student_name = 'ሙሉ', full_name_am = 'ሙሉ ከበደ' WHERE id = 1");
if (!MemberSearchService::indexMember($conn, 1)) {
    $fail('indexMember update returned false');
}
$has($ranked('ሙሉ'), 1, "re-indexed name 'ሙሉ' finds #1");
// White-box check: no doclist row may still join doc 1 to the old term.
// (A query-level check can't work here: fuzzy legitimately matches the
// old query 'አበበ' to doc 1's city token 'አበባ' at distance 1.)
$idxCheck = new PDO('sqlite:' . MemberSearchService::indexPath());
$stale = $idxCheck->query(
    "SELECT COUNT(*) FROM doclist d JOIN wordlist w ON w.id = d.term_id
     WHERE d.doc_id = 1 AND w.term = 'አበበ'"
)->fetchColumn();
if ((int)$stale !== 0) {
    $fail('stale doclist rows still join doc 1 to the old term');
}
$pass('stale tokens removed on update (verified in doclist)');
if (!MemberSearchService::removeMember(10)) {
    $fail('removeMember returned false');
}
if (in_array(10, $ranked('ፍቅር'), true)) {
    $fail('#10 still found after removeMember');
}
$pass('removeMember drops the document');
$stats = MemberSearchService::stats();
if ($stats['documents'] !== 9) {
    $fail('stats after remove: ' . json_encode($stats));
}
$pass('stats: 9 documents after removal');

// ── fallback contract: missing index → null (caller uses MySQL) ──
$indexPath = MemberSearchService::indexPath();
$hiddenPath = $indexPath . '.hidden-by-smoke';
if (!rename($indexPath, $hiddenPath)) {
    $fail('could not hide the index for the fallback check');
}
$fallback = MemberSearchService::searchRanked('selam');
if ($fallback !== null) {
    rename($hiddenPath, $indexPath);
    $fail('missing index did not return null (fallback broken)');
}
$pass('missing index returns null (MySQL fallback contract)');
if (!rename($hiddenPath, $indexPath)) {
    $fail('could not restore the index');
}
$has($ranked('selam'), 6, 'index restored and serving again');

echo "SEARCH P0 SMOKE: ALL GREEN\n";
