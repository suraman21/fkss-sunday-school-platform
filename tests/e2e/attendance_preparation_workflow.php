<?php
/** Isolated actual TEMP SQL; never loads school config. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('SSMS_AUDIT_TESTING')!=='1')exit(2);
$socket=getenv('SSMS_PREPARATION_TEST_SOCKET')?:null;
if($socket!==null&&!preg_match('#^/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock$#D',$socket))throw new RuntimeException('Unexpected test socket');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
class WorkflowConnection extends mysqli{public int $prepares=0;public function prepare(string $query):mysqli_stmt|false{$this->prepares++;return parent::prepare($query);}}
$conn=new WorkflowConnection($socket?'localhost':'127.0.0.1','ssms','ssms','ssms_comm_e2e',$socket?0:3306,$socket);$conn->set_charset('utf8mb4');
$enabled=($argv[1]??'')==='on';define('FEATURE_ATTENDANCE_V2',$enabled);
require_once __DIR__.'/../../admin/backend/services/AttendancePreparationWorkflow.php';
use App\Services\AttendancePreparationWorkflow as W;
use App\Services\SubmissionService as S;
$checks=0;function checkWorkflow(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function rejectWorkflow(callable $f):void{try{$f();}catch(Throwable $e){checkWorkflow(true,'Rejected');return;}throw new RuntimeException('Invalid read accepted');}
function readWorkflow(int $class,array $dates):array{global $conn;$conn->prepares=0;$r=W::read($conn,$class,6,$dates);return[$r,$conn->prepares];}
$tables=[];
try{
$conn->query('CREATE TEMPORARY TABLE attendance(member_id INT,class_id INT,academic_year_id INT NULL,attendance_date DATE,status VARCHAR(30),notes VARCHAR(255)) ENGINE=InnoDB');$tables[]='attendance';
$conn->query('CREATE TEMPORARY TABLE grade_submissions(id INT,class_id INT,academic_year_id INT NULL,attendance_date DATE,submission_type VARCHAR(30),status VARCHAR(40)) ENGINE=InnoDB');$tables[]='grade_submissions';
$conn->query('CREATE TEMPORARY TABLE attendance_sessions(class_id INT,academic_year_id INT NULL,session_date DATE,status VARCHAR(30)) ENGINE=InnoDB');$tables[]='attendance_sessions';
$conn->query('CREATE TEMPORARY TABLE attendance_session_records(session_id INT,member_id INT,status VARCHAR(30)) ENGINE=InnoDB');$tables[]='attendance_session_records';
$conn->query("INSERT INTO attendance VALUES(1,3,6,'2026-11-01','present','note'),(1,3,5,'2026-11-02','absent','old'),(1,3,NULL,'2026-11-03','late',NULL),(1,3,6,'2026-11-04','excused','a'),(2,3,5,'2026-11-04','holiday','b'),(1,99,5,'2026-11-01','absent','OTHER CLASS')");
$conn->query("INSERT INTO grade_submissions VALUES(10,3,6,'2026-11-05','attendance','approved'),(11,3,6,'2026-11-05','attendance','revision_needed'),(12,3,6,'2026-11-06','attendance','submitted'),(13,3,6,'2026-11-07','attendance','future_unknown'),(14,3,5,'2026-11-08','attendance','draft'),(15,3,6,'2026-11-08','attendance','incomplete'),(16,99,5,'2026-11-05','attendance','rejected'),(17,3,5,'2026-11-09','marklist','approved')");
$conn->query("INSERT INTO attendance_sessions VALUES(3,6,'2026-11-09','submitted'),(3,5,'2026-11-10','submitted'),(3,NULL,'2026-11-11','submitted'),(3,5,'2026-11-12','cancelled'),(99,5,'2026-11-12','submitted')");
$strict=($argv[2]??'')==='strict';mysqli_report($strict?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);$mode=(new mysqli_driver())->report_mode;
$dates=[];for($i=1;$i<=13;$i++)$dates[]=sprintf('2026-11-%02d',$i);
[$r,$count]=readWorkflow(3,$dates);$d=$r['days'];
checkWorkflow($count===($enabled?4:2),'Bounded SQL count');checkWorkflow($r['lessons_enabled']===$enabled,'Actual feature flag');checkWorkflow(array_column($d,'date')===$dates,'Exact explicit day coverage');
checkWorkflow($d[0]['records']===[['member_id'=>1,'academic_year_id'=>6,'status'=>'present','note'=>'note']],'Minimal current sheet; no other class');
checkWorkflow($d[0]['resolved_status']==='submitted'&&!$d[0]['teacher_workflow_open'],'Legacy rows/no packet submitted policy');
checkWorkflow($d[0]['origin_matches_write_year']&&$d[0]['origin_year_ids']===[6],'Current origin facts');
checkWorkflow(!$d[1]['origin_matches_write_year']&&$d[1]['origin_year_ids']===[5],'Foreign year not restamped');
checkWorkflow(!$d[2]['origin_matches_write_year']&&$d[2]['origin_unknown']&&$d[2]['records'][0]['academic_year_id']===null,'Unknown original year preserved');
checkWorkflow(!$d[3]['origin_matches_write_year']&&$d[3]['origin_year_ids']===[5,6],'Mixed origins retained');
checkWorkflow($d[4]['packet']['id']===11&&$d[4]['teacher_workflow_open']&&$d[4]['resolved_status']==='revision_needed','Latest packet precedence/shared policy');
checkWorkflow(!$d[5]['teacher_workflow_open']&&$d[5]['resolved_status']==='submitted','Submitted locked');
checkWorkflow(!$d[6]['workflow_supported']&&!$d[6]['teacher_workflow_open'],'Unknown status cannot normalize into permission');
checkWorkflow($d[7]['packet']['id']===15&&$d[7]['teacher_workflow_open']&&!$d[7]['origin_matches_write_year']&&$d[7]['origin_year_ids']===[5,6],'Earlier foreign packet origin outranks latest open packet');
checkWorkflow($d[8]['packet']===null&&$d[8]['lesson_derived']===$enabled,'Marklist excluded; submitted lesson recognized');
checkWorkflow($d[9]['lesson_derived']===$enabled&&$d[9]['origin_matches_write_year']===!$enabled,'Foreign lesson origin preserved');
checkWorkflow($d[10]['origin_unknown']===$enabled,'Unknown lesson origin preserved');
checkWorkflow(!$d[11]['lesson_derived']&&$d[11]['origin_matches_write_year'],'Cancelled/other-class lesson excluded');
checkWorkflow($d[12]['records']===[]&&$d[12]['packet']===null&&$d[12]['teacher_workflow_open']&&$d[12]['origin_matches_write_year'],'Complete empty distinct from failure');
foreach([0,1,2,3,4,5,7,11,12]as$i){checkWorkflow($d[$i]['resolved_status']===S::resolvedAttendanceStatus($conn,3,$dates[$i]),'Actual legacy helper resolved-status parity for supported workflow');}
$legacyUnknownOpen=S::teacherMayWriteAttendance($conn,['rol'=>'teacher'],3,'2026-11-07');checkWorkflow($legacyUnknownOpen&&!$d[6]['teacher_workflow_open'],'Observed actual legacy unknown normalization vs strict no-permission fact');
$legacyForeignStaffOpen=S::teacherMayWriteAttendance($conn,['rol'=>'edu_dept'],3,'2026-11-02');checkWorkflow($legacyForeignStaffOpen&&!$d[1]['origin_matches_write_year'],'Observed actual legacy override has no original-year check; strict origin remains false');
checkWorkflow(W::tables()===($enabled?['attendance','grade_submissions','attendance_sessions','attendance_session_records']:['attendance','grade_submissions']),'All actual dependencies declared');
[$empty,$emptyCount]=readWorkflow(77,$dates);checkWorkflow(array_column($empty['days'],'records')===array_fill(0,13,[]),'Successful empty authorized class facts');
[$one,$oneCount]=readWorkflow(3,[$dates[0]]);checkWorkflow($oneCount===$count,'One/full dates bounded statements');
foreach(['draft','incomplete','revision_needed','submitted','approved','rejected']as$status){$conn->query("UPDATE grade_submissions SET status='$status' WHERE id=11");[$x]=readWorkflow(3,['2026-11-05']);checkWorkflow($x['days'][0]['resolved_status']===S::normalizeStatus($status)&&$x['days'][0]['teacher_workflow_open']===S::statusIsOpen($status),'All established packet statuses shared');}
foreach([[],['bad'],['2026-02-30'],['2026-11-01','2026-11-01'],[1],['k'=>'2026-11-01']]as$bad)rejectWorkflow(fn()=>W::read($conn,3,6,$bad));rejectWorkflow(fn()=>W::read($conn,0,6,$dates));rejectWorkflow(fn()=>W::read($conn,3,0,$dates));
$long=[];$start=new DateTimeImmutable('2026-09-21');for($i=0;$i<106;$i++)$long[]=$start->modify('+'.$i.' days')->format('Y-m-d');[$full,$fullCount]=readWorkflow(3,$long);checkWorkflow(count($full['days'])===106&&$fullCount===$count,'Full existing calendar budget bounded SQL');rejectWorkflow(fn()=>W::read($conn,3,6,array_merge($long,['2027-01-05'])));
$conn->query("INSERT INTO attendance VALUES(1,3,6,'2026-11-01','present','duplicate')");rejectWorkflow(fn()=>W::read($conn,3,6,$dates));$conn->query("DELETE FROM attendance WHERE notes='duplicate'");
$conn->query("INSERT INTO attendance VALUES(1,3,5,'2026-11-01','absent','old same member')");[$mixed]=readWorkflow(3,['2026-11-01']);checkWorkflow(count($mixed['days'][0]['records'])===2&&!$mixed['days'][0]['origin_matches_write_year'],'Same member different year retained read-only');$conn->query("DELETE FROM attendance WHERE notes='old same member'");
[$reversed]=readWorkflow(3,array_reverse($dates));checkWorkflow(array_column($reversed['days'],'date')===array_reverse($dates),'Input ordering preserved');
$conn->query("INSERT INTO attendance VALUES(1,3,6,'2026-11-13','invalid','bad status')");rejectWorkflow(fn()=>W::read($conn,3,6,$dates));$conn->query("DELETE FROM attendance WHERE notes='bad status'");
$conn->query("INSERT INTO attendance VALUES(0,3,6,'2026-11-13','present','bad id')");rejectWorkflow(fn()=>W::read($conn,3,6,$dates));$conn->query("DELETE FROM attendance WHERE notes='bad id'");
$conn->query("INSERT INTO attendance VALUES(1,3,-1,'2026-11-13','present','bad year')");rejectWorkflow(fn()=>W::read($conn,3,6,$dates));$conn->query("DELETE FROM attendance WHERE notes='bad year'");
$conn->query("INSERT INTO grade_submissions VALUES(11,3,6,'2026-11-05','attendance','draft')");rejectWorkflow(fn()=>W::read($conn,3,6,$dates));$conn->query("DELETE FROM grade_submissions WHERE id=11 AND status='draft'");
$conn->query("INSERT INTO attendance_sessions VALUES(3,6,'2026-11-13','unknown')");if($enabled)rejectWorkflow(fn()=>W::read($conn,3,6,$dates));else{[$ignored]=readWorkflow(3,$dates);checkWorkflow(!$ignored['lessons_enabled'],'OFF ignores lesson data');}$conn->query("DELETE FROM attendance_sessions WHERE status='unknown'");
$conn->query('ALTER TABLE attendance DROP COLUMN notes');rejectWorkflow(fn()=>W::read($conn,3,6,$dates));$conn->query('ALTER TABLE attendance ADD notes VARCHAR(255)');
$conn->query('ALTER TABLE grade_submissions DROP COLUMN academic_year_id');rejectWorkflow(fn()=>W::read($conn,3,6,$dates));$conn->query('ALTER TABLE grade_submissions ADD academic_year_id INT NULL');
$conn->query('ALTER TABLE attendance_sessions DROP COLUMN session_date');if($enabled)rejectWorkflow(fn()=>W::read($conn,3,6,$dates));else{[$off]=readWorkflow(3,$dates);checkWorkflow(!$off['lessons_enabled'],'OFF does not probe unavailable lessons');}
$conn->query('ALTER TABLE attendance_sessions ADD session_date DATE');$conn->query('ALTER TABLE attendance_session_records DROP COLUMN status');if($enabled)rejectWorkflow(fn()=>W::read($conn,3,6,$dates));else{[$off]=readWorkflow(3,$dates);checkWorkflow(!$off['lessons_enabled'],'OFF does not require optional schema');}
$conn->query('ALTER TABLE attendance_session_records ADD status VARCHAR(30)');[$recovered]=readWorkflow(3,$dates);checkWorkflow(count($recovered['days'])===13,'Recovery after actual schema failure');checkWorkflow((new mysqli_driver())->report_mode===$mode,'Reporting mode preserved');
echo json_encode(['result'=>'PASS','checks'=>$checks,'feature'=>$enabled,'mysqli_strict'=>$strict,'statements'=>$count,'one_date_statements'=>$oneCount,'full106_statements'=>$fullCount,'actual_temp_sql'=>true,'result_or_failure_doubles'=>[],'authorization_or_capture_grant'=>false,'legacy_unknown_status_teacher_open'=>$legacyUnknownOpen,'legacy_foreign_origin_staff_helper_open'=>$legacyForeignStaffOpen],JSON_THROW_ON_ERROR)."\n";
}finally{foreach(array_reverse($tables)as$t)$conn->query('DROP TEMPORARY TABLE IF EXISTS `'.$t.'`');$conn->close();}
