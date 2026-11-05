<?php
/** Synthetic connection-local calendar facts; no school config loaded. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('SSMS_AUDIT_TESTING') !== '1') { fwrite(STDERR, "Explicit isolated test environment required.\n"); exit(2); }
$socket = getenv('SSMS_PREPARATION_TEST_SOCKET') ?: null;
if ($socket !== null && !preg_match('#^/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock$#D', $socket)) throw new RuntimeException('Unexpected synthetic fixture socket.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
class CalendarFailureConnection extends mysqli {
    public string $makeupFailure = 'none';
    public function prepare(string $query): mysqli_stmt|false {
        if (str_contains($query, 'FROM timetable_makeup_classes')) {
            if ($this->makeupFailure === 'throw') throw new RuntimeException('Synthetic makeup read failure');
            if ($this->makeupFailure === 'false') return false;
        }
        return parent::prepare($query);
    }
}
$conn = new CalendarFailureConnection($socket ? 'localhost' : '127.0.0.1', 'ssms', 'ssms', 'ssms_comm_e2e', $socket ? 0 : 3306, $socket);
$conn->set_charset('utf8mb4');
require_once __DIR__ . '/../../admin/backend/ethiopian_date.php';
require_once __DIR__ . '/../../admin/backend/services/SchoolCalendarService.php';
use App\Services\SchoolCalendarService as S;
$checks = 0;
function verifyCalendar(bool $ok, string $why): void { global $checks; if (!$ok) throw new RuntimeException($why); $checks++; }
function refusesCalendar(callable $read, string $type = RuntimeException::class): void {
    try { $read(); } catch (Throwable $error) { verifyCalendar($error instanceof $type, 'Expected controlled refusal, got ' . get_class($error)); return; }
    throw new RuntimeException('Incomplete calendar was accepted');
}
function legacyCalendarSchemaKnown(): void {
    // Temporary tables are not enumerated in information_schema.TABLES.
    // Only this metadata-cache boundary is doubled; queries/rules are real.
    foreach (['tablesReady','timetableTablesReady'] as $name) (new ReflectionProperty(S::class,$name))->setValue(null,true);
}
$tables = ['academic_years','timetable_class_days','timetable_makeup_classes','school_day_offs','school_holidays'];
try {
    $conn->query('CREATE TEMPORARY TABLE academic_years (id INT,status VARCHAR(20),is_current TINYINT,start_date DATE,end_date DATE) ENGINE=InnoDB');
    $conn->query('CREATE TEMPORARY TABLE timetable_class_days (class_id INT,academic_year_id INT,working_days VARCHAR(40)) ENGINE=InnoDB');
    $conn->query('CREATE TEMPORARY TABLE timetable_makeup_classes (class_id INT,lesson_date DATE) ENGINE=InnoDB');
    $conn->query('CREATE TEMPORARY TABLE school_day_offs (id INT,day_date DATE,is_off TINYINT,cancel_makeup TINYINT,reason VARCHAR(80),created_at DATETIME,created_by INT) ENGINE=InnoDB');
    $conn->query('CREATE TEMPORARY TABLE school_holidays (id INT,title VARCHAR(80),title_am VARCHAR(80),date_from DATE,date_to DATE,type VARCHAR(20),created_at DATETIME) ENGINE=InnoDB');
    $conn->query("INSERT INTO academic_years VALUES (6,'active',1,'2026-01-01','2026-12-31')");
    $conn->query("INSERT INTO timetable_class_days VALUES (3,6,'4,6')");
    $conn->query("INSERT INTO timetable_makeup_classes VALUES (3,'2026-11-06'),(3,'2026-11-07')");
    $conn->query("INSERT INTO school_day_offs VALUES (1,'2026-11-06',1,1,'Training','2026-11-01',1)");
    $conn->query("INSERT INTO school_holidays VALUES (1,'Festival','','2026-11-07','2026-11-07','holiday','2026-11-01')");
    $strict = ($argv[1] ?? '') === 'strict';
    mysqli_report($strict ? MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);
    $mode = (new mysqli_driver())->report_mode;
    S::resetCache(); legacyCalendarSchemaKnown();
    $legacy = S::mobileCalendar($conn,[3,4],'2026-11-05','2026-11-07');
    verifyCalendar($legacy['classes'] === [['class_id'=>3,'days'=>'odm'],['class_id'=>4,'days'=>'cdh']], 'Existing rule precedence');
    $actual = S::preparationCalendar($conn,[3,4],6,'2026-11-05','2026-11-07');
    verifyCalendar($actual['classes'] === $legacy['classes'] && $actual['school'] === $legacy['school'], 'Strict/legacy parity for complete known facts');
    verifyCalendar($actual['academic_year_id'] === 6 && $actual['enforced'] === true && $actual['backdate_days'] === 45, 'Captured context unchanged');
    verifyCalendar((new mysqli_driver())->report_mode === $mode, 'Reporting mode restored after success');
    foreach (['tablesReady','timetableTablesReady','range','yearMemo'] as $name) verifyCalendar((new ReflectionProperty(S::class,$name))->getValue() === null, 'Unbound cache cleared');
    foreach (['tablesReady','timetableTablesReady'] as $name) (new ReflectionProperty(S::class,$name))->setValue(null,false);
    verifyCalendar(S::preparationCalendar($conn,[3,4],6,'2026-11-05','2026-11-07') === $actual, 'Stale permissive cache cannot suppress preparation facts');
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    S::preparationCalendar($conn,[3,4],6,'2026-11-05','2026-11-07');
    verifyCalendar((int)$conn->query('SELECT @@in_transaction')->fetch_row()[0] === 1, 'Caller transaction not committed');
    $conn->rollback();
    $empty = S::preparationCalendar($conn,[],6,'2026-11-05','2026-11-07');
    verifyCalendar($empty['classes'] === [] && $empty['school'] === [], 'Empty authorized class list leaks no school labels');
    foreach ([[0],[3,3],['3'],[1=>3],[9007199254740992]] as $ids) refusesCalendar(fn()=>S::preparationCalendar($conn,$ids,6,'2026-11-05','2026-11-07'), InvalidArgumentException::class);
    foreach ([['2026-02-29','2026-03-01'],['0000-01-01','2026-11-07'],['2026-11-07','2026-11-05'],['2026-01-01','2026-12-31']] as [$from,$to]) refusesCalendar(fn()=>S::preparationCalendar($conn,[3],6,$from,$to),InvalidArgumentException::class);
    refusesCalendar(fn()=>S::preparationCalendar($conn,[3],5,'2026-11-05','2026-11-07'));
    $conn->query("INSERT INTO timetable_class_days VALUES (3,6,'7')");
    refusesCalendar(fn()=>S::preparationCalendar($conn,[3],6,'2026-11-05','2026-11-07'));
    $conn->query("DELETE FROM timetable_class_days WHERE working_days='7'");
    $conn->query("UPDATE timetable_class_days SET working_days='4,9'");
    refusesCalendar(fn()=>S::preparationCalendar($conn,[3],6,'2026-11-05','2026-11-07'));
    $conn->query("UPDATE timetable_class_days SET working_days='4,6'");
    // Supplemental actual-connection method failure injection: legacy swallows
    // this makeup error; the opt-in preparation must not publish partial facts.
    foreach (['throw','false'] as $failure) {
        $conn->makeupFailure = $failure;
        refusesCalendar(fn()=>S::preparationCalendar($conn,[3],6,'2026-11-05','2026-11-07'));
        verifyCalendar((new mysqli_driver())->report_mode === $mode, 'Reporting mode restored after failure');
        verifyCalendar((new ReflectionProperty(S::class,'strictPreparationRead'))->getValue() === false, 'Strict state restored after failure');
    }
    $conn->makeupFailure = 'none';
    verifyCalendar(S::preparationCalendar($conn,[3,4],6,'2026-11-05','2026-11-07') === $actual, 'Failure does not poison subsequent snapshots');
    foreach (['school_day_offs','school_holidays','timetable_makeup_classes'] as $table) $conn->query('DELETE FROM '.$table);
    $emptyFacts = S::preparationCalendar($conn,[3,4],6,'2026-11-05','2026-11-07');
    verifyCalendar($emptyFacts['classes'] === [['class_id'=>3,'days'=>'ono'],['class_id'=>4,'days'=>'ccc']], 'Genuinely empty optional facts are complete, not errors');
    foreach (['academic_years'=>'end_date','timetable_class_days'=>'working_days','timetable_makeup_classes'=>'lesson_date','school_day_offs'=>'reason','school_holidays'=>'title_am'] as $table=>$column) {
        $conn->query('ALTER TABLE '.$table.' DROP COLUMN '.$column);
        refusesCalendar(fn()=>S::preparationCalendar($conn,[3],6,'2026-11-05','2026-11-07'));
        verifyCalendar((new mysqli_driver())->report_mode === $mode, 'Reporting restored on schema failure');
        $conn->query('ALTER TABLE '.$table.' ADD COLUMN '.$column.' VARCHAR(40) NULL');
    }
    echo json_encode(['result'=>'PASS','checks'=>$checks,'mysqli_strict'=>$strict,'sql'=>'Real temporary table facts and missing-column failures','boundary_doubles'=>['legacy temporary-table metadata','makeup prepare exception/false']],JSON_THROW_ON_ERROR),"\n";
} finally {
    $conn->rollback();
    foreach ($tables as $table) $conn->query('DROP TEMPORARY TABLE IF EXISTS '.$table);
    $conn->close();
}
