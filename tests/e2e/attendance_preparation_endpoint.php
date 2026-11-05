<?php
/** Owned isolated synthetic SCHEMA, actual full storage boundary and readers. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('SSMS_AUDIT_TESTING')!=='1')exit(2);
$socket=getenv('SSMS_PREPARATION_TEST_SOCKET')?:null;
if($socket!==null&&!preg_match('#^/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock$#D',$socket))throw new RuntimeException('Unexpected synthetic socket');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
class EndpointConnection extends mysqli{
 public $beforePrepare=null;
 public int $prepares=0;public int $queries=0;
 public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT):mysqli_result|bool{$this->queries++;return parent::query($query,$result_mode);}
 public function prepare(string $query):mysqli_stmt|false{$this->prepares++;if($this->beforePrepare!==null)($this->beforePrepare)($query);return parent::prepare($query);}
}
function endpointConnection(string $db):EndpointConnection{global $socket;return new EndpointConnection($socket?'localhost':'127.0.0.1','root',$socket?'':'root',$db,$socket?0:3306,$socket);}
require_once __DIR__.'/../../admin/backend/services/AttendancePreparationService.php';
require_once __DIR__.'/../../api/v1/core/acl.php';
require_once __DIR__.'/../../admin/backend/services/AttendancePreparationGrant.php';
use App\Services\AttendancePreparationService as P;
use App\Services\AttendancePreparationDenied as Denied;
$feature=($argv[1]??'')==='on';define('FEATURE_ATTENDANCE_V2',$feature);
class EndpointResponse extends RuntimeException{public function __construct(public int $status,public array $data){parent::__construct('Synthetic response adapter');}}
function ok($data=null,$code=200){throw new EndpointResponse($code,$data??[]);}
function err($message,$code=400,$extra=[]){throw new EndpointResponse($code,$extra);}
$checks=0;function endpointCheck(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function endpointReject(callable $f,?string $reason=null):void{try{$f();}catch(Throwable $e){endpointCheck($reason===null||($e instanceof Denied&&$e->reason===$reason),'Expected preparation refusal');return;}throw new RuntimeException('Unsafe preparation accepted');}
function endpointRead(array $auth):array{global $conn;return P::read($conn,$auth,apiRolesAttendance(),apiIsClassRestricted($auth));}
$conn=endpointConnection('ssms_comm_e2e');$owned=false;$writer=null;
$name='ssms_preparation_'.getmypid().'_'.bin2hex(random_bytes(6));
if(!preg_match('/^ssms_preparation_[0-9]+_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Unsafe fixture schema');
try{
 if($conn->query('SELECT DATABASE()')->fetch_row()[0]!=='ssms_comm_e2e')throw new RuntimeException('Unexpected synthetic base database');
 $conn->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$owned=true;$conn->select_db($name);$conn->set_charset('utf8mb4');$writer=endpointConnection($name);
 $definitions=[
 'users'=>'id INT,role VARCHAR(40),is_active TINYINT,authorization_version INT,username VARCHAR(80),full_name VARCHAR(100)',
 'academic_years'=>'id INT,status VARCHAR(20),is_current TINYINT,start_date DATE,end_date DATE',
 'api_refresh_sessions'=>'session_id CHAR(64) PRIMARY KEY,family_id CHAR(64),user_id INT,revoked_at DATETIME NULL,expires_at DATETIME',
 'classes'=>'id INT,class_name VARCHAR(100),class_name_en VARCHAR(100),section VARCHAR(30),is_active TINYINT',
 'teacher_assignments'=>'teacher_id INT,class_id INT,is_active TINYINT,academic_year_id INT NULL',
 'class_enrollments'=>'member_id INT,class_id INT,academic_year_id INT NULL,status VARCHAR(20)',
 'members'=>'id INT,student_name VARCHAR(100),father_name VARCHAR(100),member_code VARCHAR(50)',
 'attendance'=>'member_id INT,class_id INT,academic_year_id INT NULL,attendance_date DATE,status VARCHAR(30),notes VARCHAR(255)',
 'grade_submissions'=>'id INT,class_id INT,academic_year_id INT NULL,attendance_date DATE,submission_type VARCHAR(30),status VARCHAR(40)',
 'timetable_class_days'=>'class_id INT,academic_year_id INT,working_days VARCHAR(40)',
 'timetable_makeup_classes'=>'class_id INT,lesson_date DATE',
 'school_day_offs'=>'id INT,day_date DATE,is_off TINYINT,cancel_makeup TINYINT,reason VARCHAR(100),created_at DATETIME,created_by INT',
 'school_holidays'=>'id INT,title VARCHAR(100),title_am VARCHAR(100),date_from DATE,date_to DATE,type VARCHAR(20),created_at DATETIME',
 'member_department_periods'=>'member_id INT,department VARCHAR(20),status VARCHAR(20),started_at DATETIME,ended_at DATETIME NULL',
 'attendance_sessions'=>'class_id INT,academic_year_id INT NULL,session_date DATE,status VARCHAR(30)',
 'attendance_session_records'=>'session_id INT,member_id INT,status VARCHAR(30)',
 ];
 foreach($definitions as$table=>$columns)$conn->query('CREATE TABLE `'.$table.'` ('.$columns.') ENGINE=InnoDB');
 $today=App\Services\SchoolCalendarService::today();$d=new DateTimeImmutable($today);$from=$d->modify('-100 days')->format('Y-m-d');$to=$d->modify('+100 days')->format('Y-m-d');
 $fid=str_repeat('a',64);$sid=str_repeat('b',64);$auth=['uid'=>7,'av'=>3,'rol'=>'teacher','fid'=>$fid,'rsid'=>$sid];
 $conn->query("INSERT INTO users VALUES(7,'teacher',1,3,'teacher7','Teacher Seven')");
 $conn->query("INSERT INTO academic_years VALUES(6,'active',1,'$from','$to'),(5,'closed',0,'$from','$to')");
 $conn->query("INSERT INTO api_refresh_sessions VALUES('$sid','$fid',7,NULL,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 90 DAY))");
 $conn->query("INSERT INTO classes VALUES(3,'Assigned','A','',1),(4,'Assigned inactive','B','',0),(99,'Other','C','',1)");
 $conn->query('INSERT INTO teacher_assignments VALUES(7,3,1,6),(7,4,1,NULL),(8,99,1,6)');
 $conn->query("INSERT INTO class_enrollments VALUES(1,3,6,'active'),(2,4,5,'active'),(99,99,6,'active')");
 $conn->query("INSERT INTO members VALUES(1,'One','Father','M1'),(2,'Two','Father','M2'),(99,'PRIVATE OTHER','Father','M99')");
 $conn->query("INSERT INTO timetable_class_days VALUES(3,6,'1,2,3,4,5,6,7'),(4,6,'1,2,3,4,5,6,7'),(99,6,'7')");
 $conn->query("INSERT INTO attendance VALUES(1,3,5,'$today','present','old year')");
 $strict=($argv[2]??'')==='strict';mysqli_report($strict?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);$mode=(new mysqli_driver())->report_mode;
 $conn->prepares=0;$conn->queries=0;$readStart=microtime(true);$r=endpointRead($auth);$readMs=(microtime(true)-$readStart)*1000;$initialPrepares=$conn->prepares;$initialQueries=$conn->queries;endpointCheck($r['complete']===true&&$r['write_year']['id']===6,'Full actual boundary succeeds');
 endpointCheck(array_column($r['classes'],'class_id')===[3,4],'Exact actual restricted assignment/class-active semantics');
 endpointCheck(array_column($r['members'],'member_id')===[1,2],'Minimal normalized authorized identities; no other class');
 endpointCheck(count($r['classes'][0]['days'])===106&&count($r['classes'][1]['days'])===106,'Full server-defined calendar coverage');
 endpointCheck($r['classes'][1]['roster_year_id']===5&&$r['classes'][1]['write_year_id']===6&&$r['classes'][1]['roster_fallback'],'Fallback never original write year');
 $todayIndex=array_search($today,array_column($r['classes'][0]['days'],'date'),true);endpointCheck($todayIndex!==false,'Today covered');
 endpointCheck(!$r['classes'][0]['days'][$todayIndex]['origin_matches_write_year']&&$r['classes'][0]['days'][$todayIndex]['records'][0]['academic_year_id']===5,'Foreign original mark stays foreign/read-only');
 endpointCheck($r['deadline']-$r['issued_at']===30*86400,'Original successful preparation thirty-day maximum');
 endpointCheck((int)$conn->query('SELECT @@in_transaction')->fetch_row()[0]===0,'Owned read transaction completed');
 $identity=App\Services\AttendancePreparationGrant::serverIdentity('https://school.example');$key=str_repeat('k',32);
 $issued=App\Services\AttendancePreparationGrant::issue($r,$identity,$key);
 $rootClaims=App\Services\AttendancePreparationGrant::inspect($issued['grant'],'root',$key);
 $payload=base64_decode(strtr($issued['payload'],'-_','+/'),true);$payloadBytes=strlen($payload);$transportBytes=strlen(json_encode($issued,JSON_THROW_ON_ERROR));$data=json_decode($payload,true,512,JSON_THROW_ON_ERROR);
 endpointCheck(hash('sha256',$payload)===$rootClaims['payload_sha256'],'Root signs exact complete payload bytes');
 endpointCheck($rootClaims['owner_user_id']===7&&$rootClaims['authorization_version']===3&&$rootClaims['family_id']===$fid&&$rootClaims['server_identity']===$identity,'Signed owner/authority/family/configured server');
 $leaf=App\Services\AttendancePreparationGrant::inspect($data['classes'][0]['days'][$todayIndex]['context_token'],'day',$key);
 endpointCheck(!$leaf['capture_eligible']&&$leaf['write_year_id']===6,'Foreign original day never new write permission');
 $fallback=App\Services\AttendancePreparationGrant::inspect($data['classes'][1]['days'][$todayIndex]['context_token'],'day',$key);
 endpointCheck($fallback['capture_eligible']&&$fallback['write_year_id']===6&&$fallback['roster_year_id']===5,'Fresh known write scope separate from fallback, never older queued context');
 $future=$data['classes'][1]['days'][$todayIndex+1];endpointCheck($future['capture_eligible']&&$future['not_before']>time(),'Future coverage requires future opening, no early capture');
 $lateFuture=$data['classes'][1]['days'][$todayIndex+40];endpointCheck(!$lateFuture['capture_eligible'],'Future calendar beyond original lease does not grant capture');
 endpointCheck($fallback['not_after']===$rootClaims['deadline']&&$fallback['preparation_id']===$rootClaims['preparation_id'],'Exact common original lease/preparation binding');
 $earliest=App\Services\AttendancePreparationGrant::inspect($data['classes'][1]['days'][0]['context_token'],'day',$key);endpointCheck($earliest['not_after']<$rootClaims['deadline'],'Rolling existing backdate boundary limits a historical day');
 endpointReject(fn()=>App\Services\AttendancePreparationGrant::inspect($issued['grant'],'day',$key));
 endpointReject(fn()=>App\Services\AttendancePreparationGrant::inspect($issued['grant'].'a','root',$key));
 endpointReject(fn()=>App\Services\AttendancePreparationGrant::inspect($issued['grant'],'root',str_repeat('x',32)));
 endpointReject(fn()=>App\Services\AttendancePreparationGrant::issue($r,$identity,'short'));
 foreach(['http://school.example','https://localhost','https://user@school.example','https://school.example/?x=1','https://school.example/#x']as$badSite)endpointReject(fn()=>App\Services\AttendancePreparationGrant::serverIdentity($badSite));
 endpointCheck(!str_contains(json_encode($issued,JSON_THROW_ON_ERROR),$key),'Server signing key never sent');
 // Existing staff workflow override cannot override a closed original year.
 $conn->query("UPDATE users SET role='edu_dept'");$staffAuth=$auth;$staffAuth['rol']='edu_dept';
 $staffGrant=App\Services\AttendancePreparationGrant::issue(endpointRead($staffAuth),$identity,$key);
 $staffData=json_decode(base64_decode(strtr($staffGrant['payload'],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
 endpointCheck(!$staffData['classes'][0]['days'][$todayIndex]['capture_eligible'],'Staff cannot acquire closed original-year capture permission');
 $conn->query('UPDATE attendance SET academic_year_id=6');
 $staffGrant=App\Services\AttendancePreparationGrant::issue(endpointRead($staffAuth),$identity,$key);
 $staffData=json_decode(base64_decode(strtr($staffGrant['payload'],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
 endpointCheck($staffData['classes'][0]['days'][$todayIndex]['capture_eligible'],'Existing supported staff workflow override only in active original year');
 $conn->query("UPDATE users SET role='teacher'");$teacherGrant=App\Services\AttendancePreparationGrant::issue(endpointRead($auth),$identity,$key);
 $teacherData=json_decode(base64_decode(strtr($teacherGrant['payload'],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
 endpointCheck(!$teacherData['classes'][0]['days'][$todayIndex]['capture_eligible'],'Teacher cannot reopen existing no-packet submitted sheet');
 $conn->query("INSERT INTO grade_submissions VALUES(90,3,6,'$today','attendance','draft')");
 $draftGrant=App\Services\AttendancePreparationGrant::issue(endpointRead($auth),$identity,$key);
 $draftData=json_decode(base64_decode(strtr($draftGrant['payload'],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
 endpointCheck($draftData['classes'][0]['days'][$todayIndex]['capture_eligible'],'Known current-year teacher draft remains open');
 $conn->query('UPDATE attendance SET member_id=99');
 $mismatchGrant=App\Services\AttendancePreparationGrant::issue(endpointRead($auth),$identity,$key);
 $mismatchData=json_decode(base64_decode(strtr($mismatchGrant['payload'],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
 endpointCheck(!$mismatchData['classes'][0]['days'][$todayIndex]['capture_eligible']&&!$mismatchData['classes'][0]['days'][$todayIndex]['base_roster_compatible']&&$mismatchData['classes'][0]['days'][$todayIndex]['records'][0]['member_id']===99,'Saved off-roster mark preserved, never silently dropped for new capture');
 $conn->query('UPDATE attendance SET member_id=1');$conn->query('UPDATE attendance SET academic_year_id=NULL');
 $unknownYearGrant=App\Services\AttendancePreparationGrant::issue(endpointRead($auth),$identity,$key);
 $unknownYearData=json_decode(base64_decode(strtr($unknownYearGrant['payload'],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
 endpointCheck(!$unknownYearData['classes'][0]['days'][$todayIndex]['capture_eligible']&&$unknownYearData['classes'][0]['days'][$todayIndex]['records'][0]['academic_year_id']===null,'Unknown original mark cannot acquire new context');
 $conn->query('UPDATE attendance SET academic_year_id=6');$conn->query("INSERT INTO attendance_sessions VALUES(3,6,'$today','submitted')");
 $lessonGrant=App\Services\AttendancePreparationGrant::issue(endpointRead($auth),$identity,$key);
 $lessonData=json_decode(base64_decode(strtr($lessonGrant['payload'],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
 endpointCheck($lessonData['classes'][0]['days'][$todayIndex]['capture_eligible']===!$feature,'Submitted lesson locks class-day capture only when feature ON');
 $conn->query('DELETE FROM attendance_sessions');
 $conn->query("UPDATE grade_submissions SET status='unknown' WHERE id=90");
 $unknownGrant=App\Services\AttendancePreparationGrant::issue(endpointRead($auth),$identity,$key);
 $unknownData=json_decode(base64_decode(strtr($unknownGrant['payload'],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
 endpointCheck(!$unknownData['classes'][0]['days'][$todayIndex]['capture_eligible'],'Unknown workflow never becomes new capture permission');
 $conn->query('DELETE FROM grade_submissions WHERE id=90');$conn->query('UPDATE attendance SET academic_year_id=5');
 $conn->query('UPDATE teacher_assignments SET is_active=0 WHERE teacher_id=7');$empty=endpointRead($auth);endpointCheck($empty['classes']===[]&&$empty['members']===[]&&$empty['school_calendar']===[],'Complete empty scoped authority distinct from failure');$conn->query('UPDATE teacher_assignments SET is_active=1 WHERE teacher_id=7');
 $conn->query("UPDATE users SET role='edu_dept'");$staff=$auth;$staff['rol']='edu_dept';$all=endpointRead($staff);endpointCheck(array_column($all['classes'],'class_id')===[3,99],'Actual privileged active-class scope');$conn->query("UPDATE users SET role='teacher'");
 $bad=$auth;unset($bad['fid']);endpointReject(fn()=>endpointRead($bad),'PREPARATION_BOUND_AUTH_REQUIRED');
 $conn->query('UPDATE users SET authorization_version=4');endpointReject(fn()=>endpointRead($auth),'PREPARATION_SCOPE_CHANGED');$conn->query('UPDATE users SET authorization_version=3');
 $conn->query('UPDATE users SET is_active=0');endpointReject(fn()=>endpointRead($auth),'PREPARATION_ACCOUNT_INACTIVE');$conn->query('UPDATE users SET is_active=1');
 $conn->query('UPDATE api_refresh_sessions SET revoked_at=CURRENT_TIMESTAMP');endpointReject(fn()=>endpointRead($auth),'SESSION_REVOKED');$conn->query('UPDATE api_refresh_sessions SET revoked_at=NULL');
 $conn->query("UPDATE academic_years SET status='closed' WHERE id=6");endpointReject(fn()=>endpointRead($auth));$conn->query("UPDATE academic_years SET status='active' WHERE id=6");
 $conn->query("UPDATE academic_years SET status='active' WHERE id=5");endpointReject(fn()=>endpointRead($auth));$conn->query("UPDATE academic_years SET status='closed' WHERE id=5");
 $conn->query('ALTER TABLE users DROP COLUMN authorization_version');endpointReject(fn()=>endpointRead($auth));$conn->query('ALTER TABLE users ADD authorization_version INT');$conn->query('UPDATE users SET authorization_version=3');
 $conn->query('ALTER TABLE school_day_offs ENGINE=MyISAM');endpointReject(fn()=>endpointRead($auth));$conn->query('ALTER TABLE school_day_offs ENGINE=InnoDB');
 $conn->query('CREATE TEMPORARY TABLE members(id INT) ENGINE=InnoDB');endpointReject(fn()=>endpointRead($auth));$conn->query('DROP TEMPORARY TABLE members');
 $conn->begin_transaction();endpointReject(fn()=>endpointRead($auth));endpointCheck((int)$conn->query('SELECT @@in_transaction')->fetch_row()[0]===1,'Existing caller transaction untouched');$conn->rollback();
 $conn->query('ALTER TABLE attendance_session_records DROP COLUMN status');if($feature)endpointReject(fn()=>endpointRead($auth));else endpointCheck(endpointRead($auth)['lessons_enabled']===false,'OFF optional schema remains unused');$conn->query('ALTER TABLE attendance_session_records ADD status VARCHAR(30)');
 // Actual core authentication + staged real attendance route, response adapter
 // only. One include per process avoids redeclaring legacy route functions.
 define('JWT_SECRET',$key);define('SITE_URL','https://school.example');
 require_once __DIR__.'/../../api/v1/core/auth.php';
 $originalFixtureAuth=$auth;
 $routeMode=$argv[3]??'success';
 endpointCheck(verifyToken($issued['grant'])===null,'Preparation token cannot become access token with same signing secret');
 $token=createToken(7,'teacher7','teacher','Teacher Seven',900,3,['family_id'=>$fid,'session_id'=>$sid]);
 endpointReject(fn()=>App\Services\AttendancePreparationGrant::inspect($token,'root',API_TOKEN_SECRET));
 $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;$_SERVER['HTTP_X_APP_BUILD']='24';$_GET=[];
 $method='GET';$ROUTE=['id'=>'preparation','sub'=>null];$expectedStatus=200;
 if($routeMode==='query'){$_GET=['class_id'=>'99'];$expectedStatus=422;}
 if($routeMode==='post'){$method='POST';$expectedStatus=405;}
 if($routeMode==='sub'){$ROUTE['sub']='extra';$expectedStatus=404;}
 if($routeMode==='unbound'){$_SERVER['HTTP_AUTHORIZATION']='Bearer '.createToken(7,'teacher7','teacher','Teacher Seven',900,3);$expectedStatus=401;}
 if($routeMode==='revoked'){$conn->query('UPDATE api_refresh_sessions SET revoked_at=CURRENT_TIMESTAMP');$expectedStatus=401;}
 if($routeMode==='scope'){$conn->query('UPDATE users SET authorization_version=4');$expectedStatus=401;}
 try{include __DIR__.'/../../api/v1/routes/attendance.php';throw new RuntimeException('Route did not respond');}
 catch(EndpointResponse $response){
  endpointCheck($response->status===$expectedStatus,'Actual route expected typed response');
  if($expectedStatus===200){$verified=App\Services\AttendancePreparationGrant::inspect($response->data['grant'],'root',API_TOKEN_SECRET);endpointCheck($verified['owner_user_id']===7&&$verified['server_identity']===$identity,'Actual authenticated route returns scoped signed preparation');}
 }
 $auth=$originalFixtureAuth;
 $conn->query('UPDATE api_refresh_sessions SET revoked_at=NULL');$conn->query('UPDATE users SET authorization_version=3');
 // Real concurrent atomic writer after snapshot start, before authority read.
 // Hook forwards actual queries/results unchanged, never fabricates data.
 $fired=false;$conn->beforePrepare=static function(string $sql)use(&$fired,$writer):void{
  if(!$fired&&str_starts_with($sql,'SELECT id,role,is_active,authorization_version FROM users')){
   $fired=true;$writer->begin_transaction();$writer->query('UPDATE users SET authorization_version=4');$writer->query("UPDATE members SET student_name='CHANGED' WHERE id=1");$writer->query('UPDATE teacher_assignments SET is_active=0 WHERE teacher_id=7');$writer->query("UPDATE timetable_class_days SET working_days='9'");$writer->query("INSERT INTO grade_submissions VALUES(100,3,6,CURRENT_DATE,'attendance','approved')");$writer->query("UPDATE academic_years SET status='closed' WHERE id=6");$writer->commit();
  }
 };
 $coherent=endpointRead($auth);$conn->beforePrepare=null;
 endpointCheck($fired&&$coherent['authorization_version']===3&&array_column($coherent['classes'],'class_id')===[3,4]&&$coherent['members'][0]['student_name']==='One'&&$coherent['write_year']['id']===6&&$coherent['classes'][0]['days'][$todayIndex]['packet']===null,'Full snapshot sees one authority/assignment/member version across concurrent commit');
 endpointReject(fn()=>endpointRead($auth),'PREPARATION_SCOPE_CHANGED');
 endpointCheck((new mysqli_driver())->report_mode===$mode,'Reporting mode unchanged');
 echo json_encode(['result'=>'PASS','checks'=>$checks,'feature'=>$feature,'mysqli_strict'=>$strict,'actual_owned_schema'=>true,'full_boundary_and_readers'=>true,'query_hook'=>'Concurrent real commit before forwarded authority prepare; no result/error doubles','route_mode'=>$routeMode,'adapter_doubles'=>['response only, not HTTP transport'],'no_mobile_activation'=>true,'synthetic_metrics'=>['classes'=>2,'dates_per_class'=>106,'initial_prepares'=>$initialPrepares,'initial_query_calls'=>$initialQueries,'raw_payload_bytes'=>$payloadBytes,'transport_data_bytes'=>$transportBytes,'read_ms'=>$readMs,'not_production_or_phone_performance'=>true]],JSON_THROW_ON_ERROR)."\n";
}finally{
 $conn->beforePrepare=null;
 if($writer){try{$writer->close();}catch(Throwable $ignored){}}
 try{$conn->rollback();}catch(Throwable $ignored){}
 try{
  if($owned){$cleanup=endpointConnection('ssms_comm_e2e');try{$cleanup->query('DROP DATABASE `'.$name.'`');}finally{$cleanup->close();}}
 }finally{try{$conn->close();}catch(Throwable $ignored){}}
}
