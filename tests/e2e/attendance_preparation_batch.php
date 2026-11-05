<?php
/** Real TEMP SQL, forwarded query-count instrumentation; no school config. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('SSMS_AUDIT_TESTING') !== '1') { fwrite(STDERR,"Explicit isolated test environment required.\n"); exit(2); }
$socket=getenv('SSMS_PREPARATION_TEST_SOCKET') ?: null;
if ($socket !== null && !preg_match('#^/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock$#D',$socket)) throw new RuntimeException('Unexpected synthetic fixture socket.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
class PreparationBatchConnection extends mysqli {
    public array $preparedSql=[];
    public function prepare(string $query): mysqli_stmt|false { $this->preparedSql[]=$query; return parent::prepare($query); }
}
$conn=new PreparationBatchConnection($socket?'localhost':'127.0.0.1','ssms','ssms','ssms_comm_e2e',$socket?0:3306,$socket);
$conn->set_charset('utf8mb4');
$feature=($argv[1]??'')==='on'; define('FEATURE_ATTENDANCE_V2',$feature);
require_once __DIR__.'/../../admin/backend/services/EnrollmentService.php';
use App\Services\EnrollmentService as E;
use App\Services\DepartmentStatusService as D;
$checks=0;
function verifyBatch(bool $ok,string $message):void { global $checks; if(!$ok)throw new RuntimeException($message); $checks++; }
function rejectsBatch(callable $read):void { try{$read();}catch(Throwable $e){verifyBatch(true,'Rejected');return;}throw new RuntimeException('Invalid batch accepted'); }
function batchRows(int $class,int $year,array $dates):array {global $conn;$conn->preparedSql=[];$data=E::fetchPreparationRosterDates($conn,$class,$year,$dates);return[$data,count($conn->preparedSql),count(array_filter($conn->preparedSql,fn($sql)=>str_contains($sql,'SELECT member_id, department, status, started_at, ended_at')))];}
$tables=[];
try {
    $conn->query('CREATE TEMPORARY TABLE members (id INT,student_name VARCHAR(80),father_name VARCHAR(80),member_code VARCHAR(40),status VARCHAR(20),phone VARCHAR(40)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');$tables[]='members';
    $conn->query('CREATE TEMPORARY TABLE class_enrollments (id INT,member_id INT,class_id INT,academic_year_id INT NULL,status VARCHAR(20)) ENGINE=InnoDB');$tables[]='class_enrollments';
    $conn->query('CREATE TEMPORARY TABLE member_department_periods (member_id INT,department VARCHAR(20),status VARCHAR(20),started_at DATETIME,ended_at DATETIME NULL) ENGINE=InnoDB');$tables[]='member_department_periods';
    $conn->query("INSERT INTO members VALUES (1,'Zebra','F','M1','active','NOT EXPORTED'),(2,'Alpha','F','M2','active','NOT EXPORTED'),(3,'alpha','F','M3','active','NOT EXPORTED'),(4,'ሀና','F','M4','active','NOT EXPORTED'),(5,NULL,NULL,'M5','active','NOT EXPORTED'),(6,'Future','F','M6','active','NOT EXPORTED'),(7,'Boundary','F','M7','active','NOT EXPORTED'),(8,'Unscoped','F','M8','active','NOT EXPORTED'),(9,'Zero','F','M9','active','NOT EXPORTED'),(10,'Fallback','F','M10','active','NOT EXPORTED'),(11,'Withdrawn','F','M11','active','NOT EXPORTED'),(12,'Other class','F','M12','active','NOT EXPORTED'),(13,'Manual status','F','M13','archived','NOT EXPORTED')");
    $conn->query("INSERT INTO class_enrollments VALUES (1,1,3,6,'active'),(2,2,3,6,'active'),(3,3,3,6,'active'),(4,4,3,6,'active'),(5,5,3,6,'active'),(6,6,3,6,'active'),(7,7,3,6,'active'),(8,8,3,NULL,'active'),(9,9,3,0,'active'),(10,10,4,5,'active'),(11,11,3,6,'withdrawn'),(12,12,99,6,'active'),(13,13,3,6,'active')");
    $conn->query("INSERT INTO member_department_periods VALUES (2,'edu','inactive','2026-11-05 09:00:00',NULL),(3,'edu','inactive','2026-11-04 00:00:00','2026-11-06 12:00:00'),(4,'edu','inactive','2026-11-07 10:00:00',NULL),(5,'mezmur','inactive','2026-11-01',NULL),(6,'edu','inactive','2026-11-09',NULL),(7,'edu','inactive','2026-11-04','2026-11-06 00:00:00'),(8,'edu','inactive','2026-11-06 00:00:00',NULL),(9,'edu','active','2026-11-01',NULL)");
    $strict=($argv[2]??'')==='strict';mysqli_report($strict?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);$mode=(new mysqli_driver())->report_mode;
    $dates=['2026-11-08','2026-11-05','2026-11-07','2026-11-06'];
    [$batch,$statements,$probes]=batchRows(3,6,$dates);
    verifyBatch($statements===($feature?3:2) && $probes===($feature?1:0),'Bounded prepared SQL and enabled-column probe count');
    verifyBatch(array_column($batch['dates'],'date')===$dates,'Explicit date ordering preserved');
    $members=[];foreach($batch['members']as$row){verifyBatch(array_keys($row)===['member_id','student_name','father_name','member_code'],'Only minimal identity fields');verifyBatch(!isset($members[$row['member_id']]),'Identity returned once');$members[$row['member_id']]=$row;}
    verifyBatch(count($members)===($feature?9:10),'Normalized union, not repeated names for every date');
    $all=[1,2,3,4,5,6,7,8,9,13];$excluded=['2026-11-05'=>[2,3,7],'2026-11-06'=>[2,8],'2026-11-07'=>[2,4,8],'2026-11-08'=>[2,4,8]];
    foreach($batch['dates']as$day){
        $single=E::fetchPreparationRoster($conn,3,6,$day['date']);
        verifyBatch($day['member_ids']===array_column($single,'member_id'),'Exact existing DB name/id ordering and date eligibility');
        $actual=$day['member_ids'];sort($actual);$expected=$feature?array_values(array_diff($all,$excluded[$day['date']])):$all;
        verifyBatch($actual===$expected,'Independent approved next-day eligibility expectation');
        foreach($single as$row)verifyBatch($members[$row['member_id']]===$row,'Minimal identity values agree with actual single-date helper');
    }
    [$one,$oneCount,$oneProbes]=batchRows(3,6,[$dates[0]]);
    verifyBatch($oneCount===$statements && $oneProbes===$probes,'One and four dates use same SQL count');
    $long=[];$d=new DateTimeImmutable('2026-09-21');for($i=0;$i<106;$i++){$long[]=$d->modify('+'.$i.' days')->format('Y-m-d');}
    [$large,$largeCount,$largeProbes]=batchRows(3,6,$long);
    verifyBatch(count($large['dates'])===106 && $largeCount===$statements && $largeProbes===$probes,'Full existing calendar span has constant statement/probe count');
    // Genuine prior strategy: invoke the unchanged real strict single-date
    // reader for EVERY date, compare all memberships, count forwarded SQL.
    $conn->preparedSql=[];
    foreach($large['dates']as$day){
        $single=E::fetchPreparationRoster($conn,3,6,$day['date']);
        verifyBatch($day['member_ids']===array_column($single,'member_id'),'Full106-date batch/single parity');
    }
    $singleDateStrategyCount=count($conn->preparedSql);
    verifyBatch($singleDateStrategyCount===($feature?212:106),'Actual repeated-reader baseline statement count');
    rejectsBatch(fn()=>E::fetchPreparationRosterDates($conn,3,6,array_merge($long,['2027-01-05'])));
    [$empty,$emptyCount]=batchRows(77,6,$dates);
    verifyBatch($empty['members']===[] && count($empty['dates'])===4 && array_column($empty['dates'],'member_ids')===[[],[],[],[]],'Successful complete empty dates preserved');
    verifyBatch($emptyCount===($feature?2:1),'No unnecessary member fetch on empty union');
    $scope=E::resolvePreparationRosterYear($conn,4,6);
    verifyBatch($scope===['year_id'=>5,'preferred_year_id'=>6,'fallback'=>true],'Original preferred year separate from fallback');
    [$fallback]=batchRows(4,$scope['year_id'],$dates);
    verifyBatch(array_column($fallback['members'],'member_id')===[10] && array_column($fallback['dates'],'member_ids')===[[10],[10],[10],[10]],'Batch preserves fallback without restamping write context');
    foreach([[],['bad'],['2026-02-30'],['2026-11-05','2026-11-05'],[1],['key'=>'2026-11-05']]as$bad)rejectsBatch(fn()=>E::fetchPreparationRosterDates($conn,3,6,$bad));
    if($feature)rejectsBatch(fn()=>E::fetchPreparationRosterDates($conn,3,6,['9999-12-31']));
    rejectsBatch(fn()=>E::fetchPreparationRosterDates($conn,0,6,$dates));rejectsBatch(fn()=>E::fetchPreparationRosterDates($conn,3,0,$dates));
    $conn->query("INSERT INTO class_enrollments VALUES (900,1,3,6,'active')");rejectsBatch(fn()=>E::fetchPreparationRosterDates($conn,3,6,$dates));$conn->query('DELETE FROM class_enrollments WHERE id=900');
    $conn->query("INSERT INTO class_enrollments VALUES (901,999,3,6,'active')");rejectsBatch(fn()=>E::fetchPreparationRosterDates($conn,3,6,$dates));$conn->query('DELETE FROM class_enrollments WHERE id=901');
    $conn->query('ALTER TABLE members DROP COLUMN father_name');rejectsBatch(fn()=>E::fetchPreparationRosterDates($conn,3,6,$dates));$conn->query('ALTER TABLE members ADD father_name VARCHAR(80) NULL');
    [$recovered]=batchRows(3,6,$dates);verifyBatch(count($recovered['members'])===($feature?9:10),'Failure never publishes partial dataset; later complete read recovers');
    $conn->query('ALTER TABLE member_department_periods DROP COLUMN ended_at');
    if($feature)rejectsBatch(fn()=>E::fetchPreparationRosterDates($conn,3,6,$dates));
    else{[$off]=batchRows(3,6,$dates);verifyBatch(count($off['members'])===10,'Feature OFF remains OFF despite unavailable optional eligibility schema');}
    verifyBatch((new mysqli_driver())->report_mode===$mode,'Reporting mode unchanged');
    echo json_encode(['result'=>'PASS','checks'=>$checks,'feature'=>$feature,'mysqli_strict'=>$strict,'prepared_statements'=>['one_date'=>$oneCount,'four_dates'=>$statements,'calendar_span'=>$largeCount],'schema_probes'=>['one_date'=>$oneProbes,'four_dates'=>$probes,'calendar_span'=>$largeProbes],'actual_single_date_strategy_statements'=>$singleDateStrategyCount,'instrumentation'=>'prepare calls forwarded unchanged to real mysqli','boundary_doubles'=>[],'authorization_or_grant'=>false],JSON_THROW_ON_ERROR)."\n";
}finally{foreach(array_reverse($tables)as$table)$conn->query('DROP TEMPORARY TABLE IF EXISTS `'.$table.'`');$conn->close();}
