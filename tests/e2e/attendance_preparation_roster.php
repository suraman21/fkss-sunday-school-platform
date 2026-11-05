<?php
/** Synthetic SQL only: temporary tables shadow names; no school config loaded. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('SSMS_AUDIT_TESTING') !== '1') {
    fwrite(STDERR, "Explicit isolated test environment required.\n"); exit(2);
}
$socket = getenv('SSMS_PREPARATION_TEST_SOCKET') ?: null;
if ($socket !== null && !preg_match('#^/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock$#D', $socket)) {
    throw new RuntimeException('Unexpected synthetic fixture socket.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($socket ? 'localhost' : '127.0.0.1', 'ssms', 'ssms', 'ssms_comm_e2e', $socket ? 0 : 3306, $socket);
$conn->set_charset('utf8mb4');
$enabled = ($argv[1] ?? '') === 'on';
$reporting = ($argv[2] ?? '') === 'strict';
define('FEATURE_ATTENDANCE_V2', $enabled);
require_once __DIR__ . '/../../admin/backend/services/EnrollmentService.php';
use App\Services\EnrollmentService as E;
use App\Services\DepartmentStatusService as D;
$checks = 0;
function verifyPreparation(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function refusesPreparation(callable $read, string $type = RuntimeException::class): void {
    try { $read(); }
    catch (Throwable $error) { verifyPreparation($error instanceof $type, 'Expected controlled refusal, got ' . get_class($error)); return; }
    throw new RuntimeException('An incomplete/invalid preparation read was accepted.');
}
// Explicit statement-boundary doubles supplement, not replace, real SQL tests.
class PreparationFailureStatement extends mysqli_stmt {
    public string $failure = 'bind';
    public function bind_param(string $types, mixed &...$vars): bool { return $this->failure !== 'bind'; }
    public function execute(?array $params = null): bool { return false; }
}
class PreparationFailureConnection extends mysqli {
    public string $failure = 'bind';
    public function prepare(string $query): mysqli_stmt|false {
        $stmt = new PreparationFailureStatement($this, 'SELECT 1');
        $stmt->failure = $this->failure;
        return $stmt;
    }
}
try {
    $conn->query('CREATE TEMPORARY TABLE members (id BIGINT, student_name VARCHAR(80), father_name VARCHAR(80), member_code VARCHAR(80)) ENGINE=InnoDB');
    $conn->query('CREATE TEMPORARY TABLE class_enrollments (id INT, member_id BIGINT, class_id INT, academic_year_id INT NULL, enrolled_at DATE, status VARCHAR(20)) ENGINE=InnoDB');
    $conn->query('CREATE TEMPORARY TABLE member_department_periods (member_id BIGINT, department VARCHAR(20), status VARCHAR(20), started_at DATETIME, ended_at DATETIME NULL) ENGINE=InnoDB');
    for ($id = 30; $id <= 37; $id++) {
        $conn->query("INSERT INTO members VALUES ($id,'Synthetic $id','Parent','CODE-$id')");
    }
    $conn->query("INSERT INTO class_enrollments VALUES
        (1,30,3,5,'2026-01-01','active'),(2,31,3,6,'2026-01-01','active'),
        (3,32,3,NULL,'2026-01-01','active'),(4,33,3,0,'2026-01-01','active'),
        (5,34,4,6,'2026-01-01','active'),(6,35,3,6,'2026-01-01','transferred'),
        (7,36,3,6,'2026-01-01','active'),(8,37,3,6,'2026-01-01','active'),
        (9,30,7,5,'2026-01-01','active')");
    $conn->query("INSERT INTO member_department_periods VALUES
        (36,'edu','inactive','2026-11-04 12:00:00',NULL),
        (37,'mezmur','inactive','2026-11-01 00:00:00',NULL),
        (31,'edu','inactive','2026-11-01 00:00:00','2026-11-05 12:00:00')");
    mysqli_report($reporting ? MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);
    $scope = E::resolvePreparationRosterYear($conn, 3, 6);
    verifyPreparation($scope === ['year_id'=>6,'preferred_year_id'=>6,'fallback'=>false], 'Preferred year and unscoped enrollment policy');
    $fallback = E::resolvePreparationRosterYear($conn, 7, 6);
    verifyPreparation($fallback === ['year_id'=>5,'preferred_year_id'=>6,'fallback'=>true], 'Roster fallback never relabels the preferred write year');
    verifyPreparation(E::resolvePreparationRosterYear($conn, 99, 6) === $scope, 'Genuine empty scope retains preferred year');
    verifyPreparation(E::fetchPreparationRoster($conn, 99, 6, '2026-11-05') === [], 'Genuine empty roster succeeds');
    $rows = E::fetchPreparationRoster($conn, 3, 6, '2026-11-05');
    verifyPreparation(array_column($rows, 'member_id') === ($enabled ? [31,32,33,37] : [31,32,33,36,37]), 'Exact class/year/null/zero/active/department scope');
    foreach ($rows as $row) verifyPreparation(array_keys($row) === ['member_id','student_name','father_name','member_code'], 'Only attendance UI/QR fields');
    verifyPreparation(array_column(E::fetchPreparationRoster($conn, 3, 6, '2026-11-03'), 'member_id') === ($enabled ? [32,33,36,37] : [31,32,33,36,37]), 'Dated eligibility includes the later-inactive student and excludes the not-yet-reactivated student');
    $cache = new ReflectionProperty(D::class, 'tableCache');
    $cache->setValue(null, [spl_object_id($conn)=>false]);
    verifyPreparation(E::fetchPreparationRoster($conn, 3, 6, '2026-11-05') === $rows, 'Strict path ignores a cached permissive unavailability result');
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    E::resolvePreparationRosterYear($conn, 3, 6);
    E::fetchPreparationRoster($conn, 3, 6, '2026-11-05');
    verifyPreparation((int)$conn->query('SELECT @@in_transaction')->fetch_row()[0] === 1, 'Reader must not commit the caller transaction');
    $conn->rollback();
    foreach (['2026-02-29','0000-01-01','2026-1-05','2026-11-05T00:00:00Z',''] as $date) {
        refusesPreparation(fn()=>E::fetchPreparationRoster($conn, 3, 6, $date), InvalidArgumentException::class);
    }
    foreach ([[0,6],[3,0],[-1,6],[3,9007199254740992]] as [$class,$year]) {
        refusesPreparation(fn()=>E::resolvePreparationRosterYear($conn, $class, $year), InvalidArgumentException::class);
        refusesPreparation(fn()=>E::fetchPreparationRoster($conn, $class, $year, '2026-11-05'), InvalidArgumentException::class);
    }
    $conn->query("INSERT INTO class_enrollments VALUES (999,31,3,6,'2026-01-01','active')");
    refusesPreparation(fn()=>E::fetchPreparationRoster($conn, 3, 6, '2026-11-05'));
    $conn->query('DELETE FROM class_enrollments WHERE id=999');
    $conn->query("INSERT INTO class_enrollments VALUES (998,99999,3,6,'2026-01-01','active')");
    refusesPreparation(fn()=>E::fetchPreparationRoster($conn, 3, 6, '2026-11-05'));
    $conn->query('DELETE FROM class_enrollments WHERE id=998');
    if ($enabled) refusesPreparation(fn()=>E::fetchPreparationRoster($conn, 3, 6, '9999-12-31'));
    $boundary = new PreparationFailureConnection($socket ? 'localhost' : '127.0.0.1', 'ssms', 'ssms', 'ssms_comm_e2e', $socket ? 0 : 3306, $socket);
    try {
        foreach (['bind','execute'] as $failure) {
            $boundary->failure = $failure;
            refusesPreparation(fn()=>E::resolvePreparationRosterYear($boundary, 3, 6));
        }
    } finally { $boundary->close(); }
    $conn->query('ALTER TABLE member_department_periods DROP COLUMN ended_at');
    if ($enabled) refusesPreparation(fn()=>E::fetchPreparationRoster($conn, 3, 6, '2026-11-05'));
    else verifyPreparation(count(E::fetchPreparationRoster($conn, 3, 6, '2026-11-05')) === 5, 'Deployment flag OFF stays OFF even without eligibility schema');
    $conn->query('ALTER TABLE member_department_periods ADD COLUMN ended_at DATETIME NULL');
    $conn->query('ALTER TABLE members DROP COLUMN father_name');
    refusesPreparation(fn()=>E::fetchPreparationRoster($conn, 3, 6, '2026-11-05'));
    $conn->query('ALTER TABLE class_enrollments DROP COLUMN academic_year_id');
    refusesPreparation(fn()=>E::resolvePreparationRosterYear($conn, 3, 6));
    echo json_encode(['result'=>'PASS','checks'=>$checks,'feature'=>$enabled,'mysqli_strict'=>$reporting,'sql'=>'real temporary tables, no permanent business-table changes','additional_boundary_doubles'=>['bind','execute']], JSON_THROW_ON_ERROR), "\n";
} finally {
    // Never DROP a permanent table: these are private connection-local fixtures.
    $conn->rollback();
    foreach (['class_enrollments','members','member_department_periods'] as $table) $conn->query('DROP TEMPORARY TABLE IF EXISTS ' . $table);
    $conn->close();
}
