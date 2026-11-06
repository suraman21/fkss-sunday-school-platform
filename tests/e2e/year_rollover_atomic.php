<?php
/** Execute the real rollover handler against connection-local synthetic tables. */
if (PHP_SAPI !== 'cli' || getenv('SSMS_AUDIT_TESTING') !== '1') { exit(2); }
$scenario = $argv[1] ?? '';
$reporting = $argv[2] ?? '';
if (!in_array($scenario, ['normal', 'duplicate', 'duplicate_null', 'other_unique', 'prepare_error', 'data_warning'], true)
    || !in_array($reporting, ['strict', 'off'], true)) { exit(2); }
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = '/tests/e2e/year_rollover_atomic.php';
// Resolve the same first secrets file as config.php, but reject its DB target
// BEFORE loading config.php, which opens the connection as a side effect.
$root = dirname(__DIR__, 2);
foreach ([dirname($root), dirname($root, 2), $root] as $directory) {
    foreach (['.fkss_env.php', '.wbws_env.php'] as $name) {
        if (is_file($directory . '/' . $name)) {
            require_once $directory . '/' . $name;
            break 2;
        }
    }
}
if (!defined('DB_HOST') || !defined('DB_NAME') || DB_HOST !== '127.0.0.1' || DB_NAME !== 'ssms_comm_e2e') {
    throw new RuntimeException('Refusing non-isolated rollover fixture database.');
}
require_once __DIR__ . '/../../admin/config.php';
while (ob_get_level() > 0) ob_end_clean();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->query("CREATE TEMPORARY TABLE academic_years (id INT PRIMARY KEY, year_name VARCHAR(32), ec_year INT, is_current TINYINT, status VARCHAR(20)) ENGINE=InnoDB");
$conn->query("CREATE TEMPORARY TABLE members (id INT PRIMARY KEY, status VARCHAR(20)) ENGINE=InnoDB");
$conn->query("CREATE TEMPORARY TABLE class_enrollments (id INT AUTO_INCREMENT PRIMARY KEY, member_id INT, class_id INT, academic_year_id INT, enrolled_at DATE, status VARCHAR(20), enrolled_by INT, UNIQUE KEY uq_enrollment (member_id, class_id, academic_year_id)) ENGINE=InnoDB");
$roleColumn = $scenario === 'prepare_error' ? '' : ', assignment_role VARCHAR(20) NOT NULL';
$otherUnique = $scenario === 'other_unique' ? ', UNIQUE KEY uq_other (class_id, subject_id, academic_year_id)' : '';
$conn->query("CREATE TEMPORARY TABLE teacher_assignments (id INT AUTO_INCREMENT PRIMARY KEY, teacher_id INT, class_id INT, subject_id INT, subject_key INT AS (COALESCE(subject_id,0)) STORED, academic_year_id INT, is_class_teacher TINYINT, is_primary TINYINT, assigned_by TINYINT UNSIGNED, assigned_at DATETIME, is_active TINYINT $roleColumn, UNIQUE KEY uq_assignment (teacher_id, class_id, subject_key, academic_year_id) $otherUnique) ENGINE=InnoDB");
$conn->query("CREATE TEMPORARY TABLE activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, username VARCHAR(60), action VARCHAR(60), details TEXT, created_at DATETIME) ENGINE=InnoDB");
$conn->query("INSERT INTO academic_years VALUES (1,'Old',2018,1,'active'),(2,'New',2019,0,'upcoming')");
$conn->query("INSERT INTO members VALUES (1,'active')");
$conn->query("INSERT INTO class_enrollments (member_id,class_id,academic_year_id,enrolled_at,status,enrolled_by) VALUES (1,3,1,'2026-01-01','active',1)");
$roleName = $scenario === 'prepare_error' ? '' : ',assignment_role';
$roleValue = $scenario === 'prepare_error' ? '' : ",'primary'";
$subject = $scenario === 'duplicate_null' ? 'NULL' : '4';
$conn->query("INSERT INTO teacher_assignments (teacher_id,class_id,subject_id,academic_year_id,is_class_teacher,is_primary,assigned_by,assigned_at,is_active$roleName) VALUES (7,3,$subject,1,0,1,1,NOW(),1$roleValue)");
if (in_array($scenario, ['duplicate', 'duplicate_null', 'other_unique'], true)) {
    $targetTeacher = $scenario === 'other_unique' ? 8 : 7;
    $conn->query("INSERT INTO teacher_assignments (teacher_id,class_id,subject_id,academic_year_id,is_class_teacher,is_primary,assigned_by,assigned_at,is_active,assignment_role) VALUES ($targetTeacher,3,$subject,2,0,1,1,NOW(),1,'primary')");
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_role'] = 'school_admin';
$_SESSION['admin_id'] = $scenario === 'data_warning' ? 999 : 1;
$_SESSION['csrf_token'] = 'fixture-csrf-token';
unset($_SESSION['ay_view_year_id']);
$_POST = ['action'=>'run', 'old_year_id'=>1, 'new_year_id'=>2, 'mode'=>'carry_forward', 'confirm'=>'ROLLOVER', 'csrf_token'=>'fixture-csrf-token'];
$_REQUEST = $_POST;
mysqli_report($reporting === 'strict' ? MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);
ob_start();
register_shutdown_function(function () use ($conn, $scenario, $reporting) {
    $raw = ob_get_clean();
    $response = json_decode($raw, true);
    $years = $conn->query('SELECT id,status,is_current FROM academic_years ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $enrollments = $conn->query('SELECT academic_year_id,status FROM class_enrollments ORDER BY academic_year_id')->fetch_all(MYSQLI_ASSOC);
    $assignments = $conn->query('SELECT academic_year_id,assigned_by FROM teacher_assignments ORDER BY academic_year_id')->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['scenario'=>$scenario,'reporting'=>$reporting,'response'=>$response,'years'=>$years,'enrollments'=>$enrollments,'assignments'=>$assignments], JSON_THROW_ON_ERROR), "\n";
});
require __DIR__ . '/../../admin/tools/year_rollover.php';
