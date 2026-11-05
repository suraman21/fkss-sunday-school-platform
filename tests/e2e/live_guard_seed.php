<?php
/** Extra audited roles for the existing seed world; disposable integration DB only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('SSMS_AUDIT_TESTING') !== '1') {
    fwrite(STDERR, "Refusing live-guard seed outside explicit CLI test mode.\n"); exit(2);
}
require_once __DIR__ . '/../../config.php';
while (ob_get_level() > 0) ob_end_clean();
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true) || DB_NAME !== 'ssms_test') {
    fwrite(STDERR, "Refusing: live guards require the isolated ssms_test database.\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$hash = password_hash('AuditTest#2026', PASSWORD_BCRYPT);
foreach (['audit_school'=>'school_admin','audit_hr'=>'hr_dept','audit_info'=>'info_dept',
          'audit_taker'=>'attendance_taker','audit_mez'=>'mezmur_dept'] as $name=>$role) {
    $email = $name . '@test.local';
    $stmt = $conn->prepare('INSERT INTO users (username,email,full_name,role,password_hash,is_active) VALUES (?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE role=VALUES(role),password_hash=VALUES(password_hash),is_active=1');
    $stmt->bind_param('sssss', $name, $email, $name, $role, $hash); $stmt->execute(); $stmt->close();
}
foreach (['member_department_periods','attendance_policies','attendance_warnings','attendance_sessions','school_holidays','school_day_offs'] as $table) {
    // Validate exact required schema; never self-skip on a provisioning failure.
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$result->num_rows) throw new RuntimeException('Required live-guard table absent: ' . $table);
}
echo "Live guard roles and schema ready\n";
