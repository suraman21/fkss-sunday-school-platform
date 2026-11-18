<?php
/**
 * SSMS member search engine (P0): TNTSearch core + Amharic language layer.
 *
 * Owns the member full-text index lifecycle (create / write-through /
 * resumable backfill) and the ranked query cascade. Every public method is
 * failure-safe: index problems NEVER break member saves or member listing —
 * writers return false (backfill catches up later) and searchRanked()
 * returns null so the caller falls back to today's MySQL matching.
 *
 * Cascade (exact can never lose to fuzzy):
 *   1. caller-supplied exact IDs (member-code + canonical-name DB hits)
 *   2. TNT boolean AND over normalized tokens (skipped for operator words)
 *   3. TNT relevance + as-you-type
 *   4. TNT fuzzy, gated (only when stages 1–3 under-fill, q ≥ 2 chars)
 *
 * Attribution: full-text core is TeamTNT TNTSearch (MIT, vendored via
 * composer). The Amharic pipeline (folding, transliteration) is SSMS code.
 */
namespace App\Services;

use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\Indexer\TNTIndexer;
use TeamTNT\TNTSearch\Stemmer\NoStemmer;
use TeamTNT\TNTSearch\TNTSearch;

require_once __DIR__ . '/AmharicIdentityNormalizer.php';
require_once __DIR__ . '/AmharicTransliterator.php';
// NOTE: EthiopicSearchTokenizer is required lazily in available(), after
// vendor/autoload.php (it extends a vendor class).

final class MemberSearchService
{
    public const VERSION = 1;
    public const INDEX_NAME = 'members.v1.index';
    /** Engine recall cap per query (ranked IDs, filtered + paged after). */
    public const TOP_K = 500;
    public const BACKFILL_MAX = 500;
    /**
     * Directory cutover flag (P2a). True = api_list_members.php serves `q`
     * searches from the ranked engine with automatic MySQL fallback.
     * Instant rollback: set false (or delete the index file). Mutable so
     * tests can prove both paths; production code never flips it at runtime.
     */
    public static bool $directoryCutover = true;

    /** @var bool|null */
    private static $available = null;

    public static function storageDir(): string
    {
        return __DIR__ . '/../../var/search/';
    }

    public static function indexPath(): string
    {
        return self::storageDir() . self::INDEX_NAME;
    }

    /**
     * Engine capability probe. Loads composer autoload on first success and
     * caches the verdict. False = caller must use MySQL fallback.
     */
    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        self::$available = false;
        if (!extension_loaded('pdo_sqlite')) {
            return false;
        }
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return false;
        }
        require_once $autoload;
        if (!class_exists(TNTSearch::class)) {
            return false;
        }
        require_once __DIR__ . '/AmharicIdentityNormalizer.php';
        require_once __DIR__ . '/AmharicTransliterator.php';
        require_once __DIR__ . '/EthiopicSearchTokenizer.php';
        self::$available = true;
        return true;
    }

    public static function indexExists(): bool
    {
        return is_file(self::indexPath());
    }

    /** @return array{exists:bool,documents:int,bytes:int,mtime:int|null} */
    public static function stats(): array
    {
        $out = ['exists' => false, 'documents' => 0, 'bytes' => 0, 'mtime' => null];
        try {
            $path = self::indexPath();
            if (!is_file($path)) {
                return $out;
            }
            $out['exists'] = true;
            $out['bytes'] = (int)filesize($path);
            $mtime = filemtime($path);
            $out['mtime'] = $mtime === false ? null : (int)$mtime;
            if (!self::available()) {
                return $out;
            }
            $pdo = new \PDO('sqlite:' . $path);
            $row = $pdo->query("SELECT value FROM info WHERE key = 'total_documents'")->fetch(\PDO::FETCH_ASSOC);
            $out['documents'] = (int)($row['value'] ?? 0);
        } catch (\Throwable $ignored) {
        }
        return $out;
    }

    private static function baseConfig(): array
    {
        // File engine only (no Redis). wal=false: no -wal/-shm sidecars on
        // shared hosting; write volume is low (single-row write-through).
        // driver/database feed the source connector createIndex wants; docs
        // are inserted manually and bulk run() is never used, so point it
        // at throwaway in-memory SQLite (no filesystem, no MySQL attempt).
        return ['storage' => self::storageDir(), 'wal' => false, 'driver' => 'sqlite', 'database' => ':memory:', 'engine' => SqliteEngine::class];
    }

    /**
     * @throws \Throwable when the index cannot be opened (caller falls back)
     */
    private static function openSearch(): TNTSearch
    {
        $tnt = new TNTSearch();
        $tnt->loadConfig(self::baseConfig());
        $tnt->selectIndex(self::INDEX_NAME); // restores tokenizer+stemmer from info table
        return $tnt;
    }

    /**
     * Writer for an EXISTING index. Never creates (creation belongs to the
     * backfill endpoint only, so concurrent first-writes cannot wipe data).
     *
     * @throws \Throwable when the index is missing or cannot be opened
     */
    private static function openWriter(): TNTIndexer
    {
        if (!self::indexExists()) {
            throw new \RuntimeException('Search index is not built yet.');
        }
        $tnt = new TNTSearch();
        $tnt->loadConfig(self::baseConfig());
        $tnt->selectIndex(self::INDEX_NAME);
        // NB: the constructor resets engine tokenizer/stemmer to defaults,
        // so configure explicitly right after (same pipeline as creation).
        $indexer = new TNTIndexer($tnt->engine);
        $indexer->setTokenizer(new EthiopicSearchTokenizer());
        $indexer->setStemmer(new NoStemmer());
        $indexer->setPrimaryKey('id');
        $indexer->excludePrimaryKey();
        return $indexer;
    }

    /**
     * Fresh index (WIPES any existing file). Super-admin backfill only.
     * @throws \Throwable
     */
    public static function rebuildStart(): void
    {
        if (!self::available()) {
            throw new \RuntimeException('Search engine is unavailable (pdo_sqlite or vendor missing).');
        }
        $tnt = new TNTSearch();
        $tnt->loadConfig(self::baseConfig());
        $indexer = $tnt->createIndex(self::INDEX_NAME, true);
        $indexer->setTokenizer(new EthiopicSearchTokenizer());
        $indexer->setStemmer(new NoStemmer());
        $indexer->setPrimaryKey('id');
        $indexer->excludePrimaryKey();
    }

    /**
     * Weighted document text. Repetition = field weight (TNTSearch ranks one
     * BM25 pool): code ×5, names ×3, transliterations ×2, context ×1.
     * Ethiopic originals are ALWAYS indexed; Latin forms are extra keys.
     */
    public static function buildDocumentText(array $row): string
    {
        $parts = [];
        $add = static function ($value, int $weight) use (&$parts): void {
            $value = is_scalar($value) ? trim((string)$value) : '';
            if ($value === '') {
                return;
            }
            for ($i = 0; $i < $weight; $i++) {
                $parts[] = $value;
            }
        };
        $add($row['member_code'] ?? '', 5);
        $add($row['student_name'] ?? '', 3);
        $add($row['father_name'] ?? '', 3);
        $add($row['grandfather_name'] ?? '', 3);
        $add($row['full_name_am'] ?? '', 2);
        $add($row['baptismal_name'] ?? '', 2);
        $add($row['phone_number'] ?? '', 2);
        foreach (['student_name', 'father_name', 'grandfather_name', 'full_name_am'] as $field) {
            foreach (AmharicTransliterator::variants($row[$field] ?? '') as $latin) {
                $add($latin, 2);
            }
        }
        $add($row['work_profession'] ?? '', 1);
        $add($row['city'] ?? '', 1);
        $add($row['sub_city'] ?? '', 1);
        $add($row['education_level'] ?? '', 1);
        return implode(' ', $parts);
    }

    /**
     * Write-through: (re)index one member. Never throws, never fatal to the
     * save. False = index missing/stale; the backfill endpoint catches up.
     */
    public static function indexMember(\mysqli $conn, int $memberId): bool
    {
        try {
            if ($memberId <= 0 || !self::available() || !self::indexExists()) {
                return false;
            }
            $stmt = $conn->prepare(
                'SELECT id, member_code, student_name, father_name, grandfather_name,
                        full_name_am, baptismal_name, phone_number, work_profession,
                        city, sub_city, education_level
                 FROM members WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) {
                return false;
            }
            $indexer = self::openWriter();
            // update() = delete + insert, safe whether or not the doc exists.
            $indexer->update($memberId, ['id' => $memberId, 'text' => self::buildDocumentText($row)]);
            return true;
        } catch (\Throwable $error) {
            error_log('MemberSearchService::indexMember failed for #' . $memberId . ': ' . $error->getMessage());
            return false;
        }
    }

    /** Remove one member from the index. Never throws. */
    public static function removeMember(int $memberId): bool
    {
        try {
            if ($memberId <= 0 || !self::available() || !self::indexExists()) {
                return true; // nothing to remove
            }
            self::openWriter()->delete($memberId);
            return true;
        } catch (\Throwable $error) {
            error_log('MemberSearchService::removeMember failed for #' . $memberId . ': ' . $error->getMessage());
            return false;
        }
    }

    /**
     * Bounded resumable backfill (same cursor protocol as the canonical
     * identity keys). Creates the index when starting from 0.
     *
     * @return array{processed:int,updated:int,next_cursor:int,done:bool,error:string|null}
     */
    public static function backfill(\mysqli $conn, int $afterId = 0, int $limit = 100): array
    {
        $afterId = max(0, $afterId);
        $limit = max(1, min(self::BACKFILL_MAX, $limit));
        $result = ['processed' => 0, 'updated' => 0, 'next_cursor' => $afterId, 'done' => true, 'error' => null];
        try {
            if (!self::available()) {
                $result['error'] = 'Search engine is unavailable (pdo_sqlite or vendor missing).';
                return $result;
            }
            if (!self::indexExists()) {
                if ($afterId > 0) {
                    $result['error'] = 'Index is missing: restart the backfill from 0.';
                    return $result;
                }
                self::rebuildStart();
            }
            $stmt = $conn->prepare('SELECT id FROM members WHERE id > ? ORDER BY id LIMIT ' . $limit);
            if (!$stmt) {
                $result['error'] = 'Could not read member ids.';
                return $result;
            }
            $stmt->bind_param('i', $afterId);
            $stmt->execute();
            $res = $stmt->get_result();
            $ids = [];
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int)$row['id'];
            }
            $stmt->close();
            $cursor = $afterId;
            $updated = 0;
            foreach ($ids as $id) {
                $cursor = $id;
                if (self::indexMember($conn, $id)) {
                    $updated++;
                }
            }
            $result['processed'] = count($ids);
            $result['updated'] = $updated;
            $result['next_cursor'] = $cursor;
            $result['done'] = count($ids) < $limit;
            return $result;
        } catch (\Throwable $error) {
            error_log('MemberSearchService::backfill failed: ' . $error->getMessage());
            $result['error'] = 'Backfill failed partway; resume from next_cursor.';
            return $result;
        }
    }

    /**
     * Ranked cascade. Returns null ONLY when the engine cannot serve (caller
     * falls back to MySQL). Never throws.
     *
     * @param string $q raw query
     * @param int $limit recall cap (bounded by TOP_K)
     * @param int[] $exactIds caller-supplied exact hits, ranked first
     * @return array{ids:int[],stages:array<string,int>,engine:string}|null
     */
    public static function searchRanked(string $q, int $limit = 100, array $exactIds = []): ?array
    {
        try {
            if (!self::available() || !self::indexExists()) {
                return null;
            }
            $limit = max(1, min(self::TOP_K, $limit));
            $ranked = [];
            foreach ($exactIds as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ranked[$id] = true;
                }
            }
            $stages = ['exact' => count($ranked), 'boolean' => 0, 'relevance' => 0, 'prefix' => 0, 'fuzzy' => 0];
            $tnt = self::openSearch();

            // Stage 2: strict AND over pre-normalized tokens. Operands are
            // NOT re-tokenized by searchBoolean, so tokenize here — and skip
            // the stage if any token is an operator word (names like "Or").
            $tokenizer = new EthiopicSearchTokenizer();
            $tokens = $tokenizer->tokenize($q);
            if ($tokens !== [] && self::booleanSafe($tokens) && count($ranked) < $limit) {
                $bool = $tnt->searchBoolean(implode(' ', $tokens), $limit);
                foreach ((array)($bool['ids'] ?? []) as $id) {
                    $id = (int)$id;
                    if ($id > 0 && !isset($ranked[$id])) {
                        $ranked[$id] = true;
                        $stages['boolean']++;
                    }
                    if (count($ranked) >= $limit) {
                        break;
                    }
                }
            }

            // Stage 3: relevance + as-you-type prefix on the last word.
            if (count($ranked) < $limit) {
                $tnt->asYouType(true);
                $tnt->fuzziness(false);
                $rel = $tnt->search($q, $limit);
                foreach ((array)($rel['ids'] ?? []) as $id) {
                    $id = (int)$id;
                    if ($id > 0 && !isset($ranked[$id])) {
                        $ranked[$id] = true;
                        $stages['relevance']++;
                    }
                    if (count($ranked) >= $limit) {
                        break;
                    }
                }
            }

            // Stage 3b: prefix fan-out for single-token queries. The engine's
            // own as-you-type expands a prefix to ONE term (LIMIT 1, shortest
            // first), so "sel" would find Selam but never Selamawit. Expand
            // to the top few matching terms here (read-only SELECT on our own
            // index file) and let the engine search each term — the engine
            // still does every document lookup and all scoring. Additive-only:
            // skipped silently if the direct read fails.
            if (count($ranked) < $limit && count($tokens) === 1) {
                foreach (self::prefixFanOut($tnt, (string)$tokens[0], $limit) as $id) {
                    if (!isset($ranked[$id])) {
                        $ranked[$id] = true;
                        $stages['prefix']++;
                    }
                    if (count($ranked) >= $limit) {
                        break;
                    }
                }
            }

            // Stage 4: fuzzy (typos, missing/extra chars). Gated: only when
            // earlier stages under-fill and the query is ≥ 2 chars.
            if (count($ranked) < $limit && self::charLength($q) >= 2) {
                $tnt->fuzziness(true);
                $tnt->setFuzzyPrefixLength(1); // bridge q/k, ts/s style initials
                $tnt->setFuzzyDistance(2);
                $fuzzy = $tnt->search($q, $limit);
                foreach ((array)($fuzzy['ids'] ?? []) as $id) {
                    $id = (int)$id;
                    if ($id > 0 && !isset($ranked[$id])) {
                        $ranked[$id] = true;
                        $stages['fuzzy']++;
                    }
                    if (count($ranked) >= $limit) {
                        break;
                    }
                }
            }

            return ['ids' => array_keys($ranked), 'stages' => $stages, 'engine' => 'tnt'];
        } catch (\Throwable $error) {
            error_log('MemberSearchService::searchRanked failed: ' . $error->getMessage());
            return null;
        }
    }

    /**
     * Stage-1 exact IDs from the DB: member code, then canonical normalized
     * name (multi-word aware via the first/father pair hash). Empty when
     * canonical keys are unavailable. Never throws.
     *
     * @return int[]
     */
    public static function exactIds(\PDO $pdo, string $q): array
    {
        $ids = [];
        try {
            $stmt = $pdo->prepare('SELECT id FROM members WHERE member_code = ? LIMIT 5');
            $stmt->execute([$q]);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $ids[] = (int)$row['id'];
            }
        } catch (\Throwable $ignored) {
        }
        try {
            $split = AmharicIdentityNormalizer::splitFullName($q);
            if ($split['pair_hash'] !== '') {
                $stmt = $pdo->prepare('SELECT id FROM members WHERE name_pair_hash = ? LIMIT 20');
                $stmt->execute([$split['pair_hash']]);
            } else {
                $single = $split['student'] !== '' ? $split['student'] : $split['full'];
                if ($single === '') {
                    return $ids;
                }
                $stmt = $pdo->prepare(
                    'SELECT id FROM members WHERE student_name_norm = ? OR father_name_norm = ? LIMIT 20'
                );
                $stmt->execute([$single, $single]);
            }
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $id = (int)$row['id'];
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        } catch (\Throwable $ignored) {
            // Canonical columns missing (pre-migration DB): skip the stage.
        }
        return $ids;
    }

    /**
     * Engine path for directory-style endpoints (P2a cutover). Runs the
     * cascade and returns a MemberDirectoryService::search()-shaped page,
     * or null when the caller must run today's MySQL matching instead
     * (flag off, engine unavailable, any error). Never throws.
     *
     * Rank order wins on this path: sort/cursor params are ignored (search
     * means relevance); page/limit/include_total are honored exactly like
     * the service (total/pages null when include_total=0). Recall is
     * bounded by TOP_K: totals above that are reported as the fetched set.
     *
     * @param array<string,mixed> $params raw request params (q, page, limit, filters…)
     * @return array{members:array,total:?int,page:int,limit:int,pages:?int,next_cursor:null,stages:array}|null
     */
    public static function rankedDirectoryPage(
        \PDO $pdo,
        MemberDirectoryService $service,
        array $params,
        string $role,
        string $view,
        string $q
    ): ?array {
        try {
            if (!self::$directoryCutover) {
                return null;
            }
            $ranked = self::searchRanked($q, self::TOP_K, self::exactIds($pdo, $q));
            if ($ranked === null) {
                return null;
            }
            $limit = max(1, min(MemberDirectoryService::MAX_PAGE_SIZE, (int)($params['limit'] ?? MemberDirectoryService::DEFAULT_PAGE_SIZE)));
            $includeTotal = (string)($params['include_total'] ?? '1') !== '0';
            // Engine served zero hits: honest empty page (never fall through
            // to an unfiltered listing — an empty ID set must stay empty).
            if ($ranked['ids'] === []) {
                return [
                    'members' => [], 'total' => $includeTotal ? 0 : null,
                    'page' => 1, 'limit' => $limit,
                    'pages' => $includeTotal ? 1 : null,
                    'next_cursor' => null, 'stages' => $ranked['stages'],
                ];
            }
            // Filtered fetch over ranked IDs, then restore rank order. `q`
            // MUST be unset (MySQL matching would filter fuzzy matches out)
            // and so must `cursor` (it belongs to id-ordered paging, which
            // rank order replaces on this path). Fetched in MAX_PAGE_SIZE
            // chunks: the service caps every fetch there, and one fetch
            // would silently truncate recall past 200 rows.
            $fetch = $params;
            unset($fetch['q'], $fetch['cursor']);
            $fetch['limit'] = self::TOP_K;
            $fetch['page'] = 1;
            $fetch['include_total'] = '0'; // total is computed below
            $members = [];
            foreach (array_chunk($ranked['ids'], MemberDirectoryService::MAX_PAGE_SIZE) as $chunk) {
                $fetch['ids'] = $chunk;
                $result = $service->search($fetch, $role, $view);
                foreach ($result['members'] as $member) {
                    $members[] = $member;
                }
            }
            $rank = [];
            foreach ($ranked['ids'] as $position => $id) {
                $rank[(int)$id] = $position;
            }
            usort($members, static function (array $a, array $b) use ($rank): int {
                return ($rank[(int)($a['id'] ?? 0)] ?? PHP_INT_MAX)
                    <=> ($rank[(int)($b['id'] ?? 0)] ?? PHP_INT_MAX);
            });
            $total = count($members);
            $pages = max(1, (int)ceil($total / $limit));
            $page = min(max(1, (int)($params['page'] ?? 1)), $pages);
            return [
                'members' => array_slice($members, ($page - 1) * $limit, $limit),
                'total' => $includeTotal ? $total : null,
                'page' => $page, 'limit' => $limit,
                'pages' => $includeTotal ? $pages : null,
                'next_cursor' => null, 'stages' => $ranked['stages'],
            ];
        } catch (\InvalidArgumentException $denied) {
            // Role/view rejected: silent null — the caller's MySQL fallthrough
            // re-throws it as the usual 403, so nothing is logged as an error.
            return null;
        } catch (\Throwable $error) {
            error_log('MemberSearchService::rankedDirectoryPage failed, falling back: ' . $error->getMessage());
            return null;
        }
    }

    /**
     * Expand a single-token prefix to the top matching terms, then search
     * each term through the engine. Returns ranked doc ids (deduped).
     * Never throws; [] on any failure (stage is additive-only).
     */
    private static function prefixFanOut(TNTSearch $tnt, string $token, int $limit): array
    {
        try {
            if (self::charLength($token) < 2) {
                return [];
            }
            $pdo = new \PDO('sqlite:' . self::indexPath());
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $lower = function_exists('mb_strtolower') ? mb_strtolower($token, 'UTF-8') : strtolower($token);
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $lower);
            $stmt = $pdo->prepare(
                "SELECT term FROM wordlist WHERE term LIKE :pfx ESCAPE '\\' AND term <> :exact "
                . 'ORDER BY num_hits DESC, length(term) ASC LIMIT 8'
            );
            $stmt->bindValue(':pfx', $escaped . '%');
            $stmt->bindValue(':exact', $lower);
            $stmt->execute();
            $terms = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return [];
        }
        $ids = [];
        foreach ((array)$terms as $term) {
            try {
                $res = $tnt->search((string)$term, $limit);
            } catch (\Throwable) {
                continue;
            }
            foreach ((array)($res['ids'] ?? []) as $id) {
                $id = (int)$id;
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /** True when tokens can be safely joined into a boolean expression. */
    private static function booleanSafe(array $tokens): bool
    {
        foreach ($tokens as $token) {
            $token = (string)$token;
            if ($token === '') {
                return false;
            }
            $lower = function_exists('mb_strtolower') ? mb_strtolower($token, 'UTF-8') : strtolower($token);
            if ($lower === 'or' || $lower === 'and' || $lower === 'not') {
                return false;
            }
            if (preg_match('/^[|&~()\-]/u', $token) === 1) {
                return false;
            }
        }
        return true;
    }

    private static function charLength(string $text): int
    {
        $text = trim($text);
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? count($chars) : strlen($text);
    }
}
