<?php
// Destructive, CLI-only synthetic smoke: never point at a real database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../e2e/destructive_guard.php';
ssms_require_disposable_database('ssms_smoke', basename(__FILE__));
require_once __DIR__ . '/../../admin/backend/services/MezmurAttendanceService.php';
require_once __DIR__ . '/../../admin/backend/services/MezmurSubmissionService.php';
use App\Services\MezmurAttendanceService as Attendance;
use App\Services\MezmurSubmissionService as Submission;

$conn = new mysqli('127.0.0.1', 'ssms', 'ssms', 'ssms_smoke');
$conn->set_charset('utf8mb4');
$conn->query("SET SESSION sql_mode = ''");
$conn->query('SET FOREIGN_KEY_CHECKS=0');
foreach (['mezmur_submissions', 'mezmur_attendance', 'mezmur_days', 'mezmur_attendance_audit', 'members', 'users'] as $table) {
    $conn->query("TRUNCATE TABLE `$table`");
}
$conn->query('SET FOREIGN_KEY_CHECKS=1');
$conn->query("INSERT INTO users (id,username,full_name,role,password_hash) VALUES (1,'synthetic_taker','Synthetic Taker','attendance_taker','synthetic')");
$conn->query("INSERT INTO members (id,member_code,student_name,father_name,status,current_section) VALUES
  (1,'TEST-1','A One','Test','active','ህናት'), (2,'TEST-2','A Two','Test','active','ህናት'),
  (3,'TEST-3','B One','Test','active','ማዕከዋይ'), (4,'TEST-4','B Two','Test','active','ማዕከዋይ')");
function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "  ok: $message\n";
}
function submit(mysqli $conn, string $date, string $section, array $rows): void {
    $conn->begin_transaction();
    try {
        $summary = Attendance::saveSectionSheet($conn, $date, $section, $rows, 1, false, true);
        $packet = Submission::upsert($conn, [
            'taker_id' => 1, 'date' => $date, 'section' => $section,
            'status' => Submission::STATUS_SUBMITTED, 'member_count' => $summary['marked'],
            'present' => $summary['present'], 'absent' => $summary['absent'],
        ]);
        if (empty($packet['ok'])) throw new RuntimeException('packet rejected');
        $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
$date = '2026-08-25';
$a = 'ህናት'; $b = 'ማዕከዋይ'; $auth = ['role' => 'mezmur_attendance_taker'];
check(Submission::takerMayWrite($conn, $auth, $date, $a), 'section A initially writable');
submit($conn, $date, $a, [['member_id'=>1,'status'=>'present'], ['member_id'=>2,'status'=>'absent']]);
check(!Submission::takerMayWrite($conn, $auth, $date, $a), 'section A locks after submission');
check(Submission::takerMayWrite($conn, $auth, $date, $b), 'same-day section B remains writable');
$before = Attendance::fetchSectionSheet($conn, $date, $a, $auth);
check(Attendance::fetchSectionSheet($conn, $date, $b, $auth)['locked'] === false, 'section B sheet is open');
submit($conn, $date, $b, [['member_id'=>3,'status'=>'absent'], ['member_id'=>4,'status'=>'present']]);
$after = Attendance::fetchSectionSheet($conn, $date, $a, $auth);
check($before['members'] === $after['members'] && $after['locked'] === true, 'section A marks and lock preserved');
check(Attendance::fetchSectionSheet($conn, $date, $b, $auth)['locked'] === true, 'section B locks independently');
check(Submission::takerMayWrite($conn, $auth, '2026-08-26', $a), 'section A on a different date remains open');
check((int)$conn->query("SELECT COUNT(*) c FROM mezmur_submissions WHERE attendance_date='$date'")->fetch_assoc()['c'] === 2, 'both same-day packets exist');
echo "PASS: independent same-day section submissions\n";
