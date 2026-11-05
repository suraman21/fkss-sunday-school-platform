<?php
/** Real isolated synthetic storage/transactions; never loads school config. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('SSMS_AUDIT_TESTING') !== '1') { fwrite(STDERR, "Explicit isolated test environment required.\n"); exit(2); }
$socket = getenv('SSMS_PREPARATION_TEST_SOCKET') ?: null;
if ($socket !== null && !preg_match('#^/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock$#D', $socket)) throw new RuntimeException('Unexpected synthetic fixture socket.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../admin/backend/services/AttendancePreparationReadBoundary.php';
use App\Services\AttendancePreparationReadBoundary as B;
class BoundaryFailureConnection extends mysqli {
    public string $fault = '';
    public bool $discarded = false;
    public function begin_transaction(int $flags = 0, ?string $name = null): bool {
        if ($this->fault === 'begin_throw') throw new RuntimeException('Synthetic BEGIN boundary failure');
        return $this->fault === 'begin' ? false : parent::begin_transaction($flags, $name);
    }
    public function commit(int $flags = 0, ?string $name = null): bool {
        if ($this->fault === 'commit_throw') throw new RuntimeException('Synthetic COMMIT boundary failure');
        return $this->fault === 'commit' ? false : parent::commit($flags, $name);
    }
    public function rollback(int $flags = 0, ?string $name = null): bool {
        if ($this->fault === 'rollback_throw') throw new RuntimeException('Synthetic rollback boundary failure');
        return $this->fault === 'rollback_false' ? false : parent::rollback($flags, $name);
    }
    public function close(): true { $this->discarded = true; return parent::close(); }
}
function connection(): BoundaryFailureConnection {
    global $socket;
    $db = new BoundaryFailureConnection($socket ? 'localhost' : '127.0.0.1', 'ssms', 'ssms', 'ssms_comm_e2e', $socket ? 0 : 3306, $socket);
    $db->set_charset('utf8mb4');
    if ($db->query('SELECT DATABASE() AS db')->fetch_assoc()['db'] !== 'ssms_comm_e2e') throw new RuntimeException('Unexpected fixture database');
    return $db;
}
$checks = 0;
function verifyBoundary(bool $condition, string $message): void {
    global $checks; if (!$condition) throw new RuntimeException($message); $checks++;
}
function rejectsBoundary(callable $read): void {
    try { $read(); } catch (Throwable $error) { verifyBoundary(true, 'Rejected'); return; }
    throw new RuntimeException('Incomplete or unsafe preparation accepted');
}
function readValue(mysqli $db, string $table): int { return (int)$db->query('SELECT n FROM `' . $table . '`')->fetch_assoc()['n']; }
$conn = connection(); $writer = connection();
// Every persistent fixture object has a fresh unguessable name, is registered
// ONLY after successful CREATE (no IF NOT EXISTS), and only that list is dropped.
$prefix = 'prep_boundary_' . getmypid() . '_' . bin2hex(random_bytes(6));
$a = $prefix . '_a'; $b = $prefix . '_b'; $myisam = $prefix . '_m'; $view = $prefix . '_v';
$owned = [];
try {
    foreach ([$a, $b] as $table) {
        $conn->query('CREATE TABLE `' . $table . '` (n INT NOT NULL) ENGINE=InnoDB'); $owned[$table] = 'TABLE';
        $conn->query('INSERT INTO `' . $table . '` VALUES (1)');
    }
    $conn->query('CREATE TABLE `' . $myisam . '` (n INT) ENGINE=MyISAM'); $owned[$myisam] = 'TABLE';
    $conn->query('CREATE VIEW `' . $view . '` AS SELECT n FROM `' . $a . '`'); $owned[$view] = 'VIEW';
    $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $strict = ($argv[1] ?? '') === 'strict';
    mysqli_report($strict ? MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);
    $mode = (new mysqli_driver())->report_mode;
    $snapshot = B::read($conn, [$b, $a, $a], function ($db) use ($writer, $a, $b) {
        $before = readValue($db, $a);
        $writer->begin_transaction();
        $writer->query('UPDATE `' . $a . '` SET n=2');
        $writer->query('UPDATE `' . $b . '` SET n=2');
        $writer->commit();
        return [$before, readValue($db, $a), readValue($db, $b)];
    });
    verifyBoundary($snapshot === [1, 1, 1], 'Concurrent commits cannot split the snapshot');
    verifyBoundary(readValue($conn, $a) === 2 && readValue($conn, $b) === 2, 'Concurrent writer really committed');
    // No boundary: READ COMMITTED admits the mixed-state failure demonstrated
    // above, using the same real connections, not a fabricated missing method.
    $legacyFirst = readValue($conn, $a);
    $writer->begin_transaction();
    $writer->query('UPDATE `' . $a . '` SET n=3');
    $writer->query('UPDATE `' . $b . '` SET n=3');
    $writer->commit();
    $unbounded = [$legacyFirst, readValue($conn, $b)];
    $writerCommit = [readValue($writer, $a), readValue($writer, $b)];
    verifyBoundary($unbounded === [2, 3], 'Actual unbounded split-read baseline');
    verifyBoundary($writerCommit === [3, 3], 'Atomic writer changed BOTH tables: mixed reads were not a committed state');
    // Restore the independent transaction/refusal fixture AFTER observing the
    // split. Before that observation, committed states were only [2,2]/[3,3].
    $writer->query('UPDATE `' . $a . '` SET n=2');
    $conn->begin_transaction();
    verifyBoundary(readValue($conn, $b) === 3, 'Outer read begins');
    $writer->query('UPDATE `' . $b . '` SET n=4');
    verifyBoundary(readValue($conn, $b) === 4, 'Session default remains READ COMMITTED');
    $conn->rollback();
    verifyBoundary(B::read($conn, [$a], fn($db) => $db->query('SELECT n FROM `' . $a . '` WHERE n=999')->fetch_all(MYSQLI_ASSOC)) === [], 'Complete empty survives');
    verifyBoundary(B::read($conn, [$a], fn($db) => null) === null, 'Boundary does not invent completeness from a callback value');
    verifyBoundary((new mysqli_driver())->report_mode === $mode, 'Reporting mode untouched');
    foreach ([[], ['bad-name'], ['x; DROP TABLE y'], [1], ['key' => $a], [$prefix . '_missing'], [$myisam], [$view]] as $tables) {
        $called = false;
        rejectsBoundary(function () use ($conn, $tables, &$called) { B::read($conn, $tables, function () use (&$called) { $called = true; }); });
        verifyBoundary(!$called, 'Unsafe storage cannot reach the reader');
    }
    foreach (['MyISAM', 'InnoDB'] as $engine) {
        $conn->query('CREATE TEMPORARY TABLE `' . $a . '` (n INT) ENGINE=' . $engine);
        try { rejectsBoundary(fn() => B::read($conn, [$a], fn() => 'must not publish')); }
        finally { $conn->query('DROP TEMPORARY TABLE `' . $a . '`'); }
    }
    verifyBoundary(readValue($conn, $a) === 2, 'Persistent table survives temporary shadow refusal');
    rejectsBoundary(fn() => B::read($conn, [$a], function ($db) use ($a) {
        if ($db->query('UPDATE `' . $a . '` SET n=99') === false) throw new RuntimeException('Read-only SQL rejected');
    }));
    verifyBoundary(readValue($conn, $a) === 2, 'Actual read-only transaction refuses writes');
    $conn->begin_transaction();
    $conn->query('UPDATE `' . $a . '` SET n=7');
    rejectsBoundary(fn() => B::read($conn, [$a], fn() => 'nested'));
    verifyBoundary(readValue($conn, $a) === 7 && readValue($writer, $a) === 2, 'Rejected nested boundary leaves outer uncommitted work intact');
    $conn->rollback();
    verifyBoundary(readValue($conn, $a) === 2, 'Caller still owns outer rollback');
    $conn->autocommit(false);
    rejectsBoundary(fn() => B::read($conn, [$a], fn() => 'autocommit off'));
    $conn->autocommit(true);
    rejectsBoundary(fn() => B::read($conn, [$a], function () { throw new RuntimeException('Reader unavailable'); }));
    verifyBoundary(B::read($conn, [$a], fn($db) => readValue($db, $a)) === 2, 'Reader failure rolls back and later read works');
    $writer->query('SET SESSION lock_wait_timeout=1');
    B::read($conn, [$a, $b], function () use ($writer, $b) {
        $failed = false;
        try { $failed = $writer->query('ALTER TABLE `' . $b . '` COMMENT="blocked by preparation"') === false; }
        catch (mysqli_sql_exception $error) { $failed = $error->getCode() === 1205; }
        verifyBoundary($failed && $writer->errno === 1205, 'Real transaction-duration metadata lock prevents concurrent schema replacement');
    });
    verifyBoundary($writer->query('ALTER TABLE `' . $b . '` COMMENT="after preparation"') === true, 'Metadata lock released at completion');
    foreach (['commit', 'commit_throw'] as $fault) {
        $conn->fault = $fault;
        rejectsBoundary(fn() => B::read($conn, [$a], fn($db) => readValue($db, $a)));
        $conn->fault = '';
        verifyBoundary(B::read($conn, [$a], fn($db) => readValue($db, $a)) === 2, 'Boundary refusal does not publish and connection recovers');
    }
    foreach (['begin', 'begin_throw', 'rollback_false', 'rollback_throw'] as $fault) {
        $uncertain = connection(); $uncertain->fault = $fault;
        rejectsBoundary(fn() => B::read($uncertain, [$a], function () { throw new RuntimeException('Reader failure'); }));
        verifyBoundary($uncertain->discarded, 'Uncertain BEGIN or unconfirmed rollback discards connection');
    }
    verifyBoundary((new mysqli_driver())->report_mode === $mode, 'Reporting mode untouched after refusals');
    echo json_encode(['result'=>'PASS', 'checks'=>$checks, 'mysqli_strict'=>$strict,
        'real_connections'=>2, 'real_metadata'=>true, 'real_concurrency'=>true,
        'bounded_snapshot'=>$snapshot, 'unbounded_split'=>$unbounded, 'baseline_writer_commit'=>$writerCommit,
        'boundary_doubles'=>['begin false/exception', 'commit false/exception', 'rollback false/exception']], JSON_THROW_ON_ERROR) . "\n";
} finally {
    $conn->fault = '';
    $conn->rollback(); $conn->autocommit(true);
    foreach (array_reverse($owned, true) as $table => $type) {
        if (!str_starts_with($table, $prefix . '_')) throw new RuntimeException('Refusing unrelated cleanup');
        $conn->query('DROP ' . $type . ' `' . $table . '`');
    }
    $conn->close(); $writer->close();
}
