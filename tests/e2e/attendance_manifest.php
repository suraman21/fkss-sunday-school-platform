<?php
/** Pure production codec; no config, database, authentication or HTTP fixture. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../admin/backend/services/AttendanceOperationManifest.php';
use App\Services\AttendanceOperationManifest;
$fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/attendance_manifest_v1.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = 0;
function checkManifest(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function rejectsManifest(array $input): void {
    try { AttendanceOperationManifest::create($input); }
    catch (InvalidArgumentException $e) { checkManifest(true, 'invalid input rejected'); return; }
    throw new RuntimeException('Invalid manifest input was accepted');
}
foreach ($fixture['vectors'] as $v) {
    $copy = $v['input'];
    $actual = AttendanceOperationManifest::create($copy);
    checkManifest($actual === $v['expected'], $v['name']);
    checkManifest($copy === $v['input'], 'caller input unchanged');
    checkManifest(hash('sha256', hex2bin($v['records_bytes_hex'])) === $actual['records_sha256'], 'independent record bytes');
    checkManifest(hash('sha256', hex2bin($v['manifest_bytes_hex'])) === $actual['manifest_sha256'], 'independent context bytes');
}
$base = $fixture['vectors'][0]['input'];
$original = AttendanceOperationManifest::create($base);
foreach (['owner_user_id'=>102, 'client_op_id'=>'other', 'packet_kind'=>'submit', 'class_id'=>4, 'academic_year_id'=>2019, 'date'=>'2026-11-06'] as $key=>$value) {
    $input=$base; $input[$key]=$value; $result=AttendanceOperationManifest::create($input);
    checkManifest($result['manifest_sha256'] !== $original['manifest_sha256'], 'context bound');
    checkManifest($result['records_sha256'] === $original['records_sha256'], 'records independent of context');
}
foreach (['member_id'=>32, 'status'=>'late', 'notes'=>'different'] as $key=>$value) {
    $input=$base; $input['records'][0][$key]=$value; $result=AttendanceOperationManifest::create($input);
    checkManifest($result['records_sha256'] !== $original['records_sha256'] && $result['manifest_sha256'] !== $original['manifest_sha256'], 'complete requested record bound');
}
foreach (['owner_user_id','class_id','academic_year_id'] as $key) {
    foreach ([null,0,-1,true,'1',1.0,9007199254740992,NAN,INF] as $value) {
        $input=$base; $input[$key]=$value; rejectsManifest($input);
    }
}
foreach (['2026-02-29','1900-02-29','2100-02-29','2026-02-31','0000-01-01','10000-01-01','2026-1-01','2026-11-05T00:00:00Z',null] as $value) {
    $input=$base; $input['date']=$value; rejectsManifest($input);
}
foreach (['',str_repeat('a',81),' padded','a:b',"a\nb",null] as $value) {
    $input=$base; $input['client_op_id']=$value; rejectsManifest($input);
}
foreach (array_keys($base) as $key) { $input=$base; unset($input[$key]); rejectsManifest($input); }
foreach (['submitted','Draft',null] as $value) { $input=$base; $input['packet_kind']=$value; rejectsManifest($input); }
$input=$base; $input['records'][]=$input['records'][0]; rejectsManifest($input);
$input=$base; $input['records']=[]; rejectsManifest($input);
$input=$base; $input['attempt_number']=1; rejectsManifest($input);
$input=$base; $input['records'][0]['note']='alias'; rejectsManifest($input);
$input=$base; unset($input['records'][0]['notes']); rejectsManifest($input);
foreach ([false,null,[],123,str_repeat('x',501),"\xED\xA0\x80"] as $value) { $input=$base; $input['records'][0]['notes']=$value; rejectsManifest($input); }
foreach (['Present',' present','unknown',null] as $value) { $input=$base; $input['records'][0]['status']=$value; rejectsManifest($input); }
foreach ([true,1.0,'30',0,-1,9007199254740992] as $value) { $input=$base; $input['records'][0]['member_id']=$value; rejectsManifest($input); }
$input=$base; $input['records']=[];
for ($i=1;$i<=2000;$i++) $input['records'][]=['member_id'=>$i,'status'=>'present','notes'=>$fixture['max_case']['notes']];
checkManifest(AttendanceOperationManifest::create($input) === $fixture['max_case']['expected'], 'max byte/count reference');
$input['records'][]=['member_id'=>2001,'status'=>'present','notes'=>'']; rejectsManifest($input);
require_once __DIR__ . '/../../admin/backend/services/AttendanceRecordService.php';
checkManifest(AttendanceOperationManifest::MAX_RECORDS === \App\Services\AttendanceRecordService::MAX_RECORDS, 'current record policy alignment');
checkManifest(AttendanceOperationManifest::MAX_NOTE_SCALARS === \App\Services\AttendanceRecordService::MAX_NOTE_LENGTH, 'current note policy alignment');
echo 'MANIFEST-VERDICT: PASS (' . $checks . " checks)\n";
