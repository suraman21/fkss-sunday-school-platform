<?php
/**
 * Manual-only membership regression against real MariaDB services.
 * CLI + disposable database interlock required. Connection-local TEMPORARY
 * tables shadow the canonical ssms_test tables: no school rows are read or
 * rewritten. Run after provision_env.sh, like the existing live guards.
 *
 * SSMS_AUDIT_TESTING=1 php tests/e2e/member_type_manual.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require __DIR__ . '/destructive_guard.php';
$dbName = getenv('SSMS_MANUAL_TYPE_DB') ?: 'ssms_test';
ssms_require_disposable_database($dbName, 'member_type_manual');
require $root . '/.fkss_env.php';
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing non-local test database.\n"); exit(2);
}
require $root . '/admin/backend/member_sync.php';
require_once $root . '/admin/backend/services/PositionSyncService.php';

use App\Services\PositionSyncService;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
$conn->set_charset('utf8mb4');
// Minimal service-facing schema: no FULLTEXT indexes (unsupported by
// MariaDB TEMPORARY InnoDB tables), foreign keys or persistent DDL.
$tables = [
    'members' => 'id INT PRIMARY KEY, full_name_am VARCHAR(150), student_name VARCHAR(150), father_name VARCHAR(150), member_type VARCHAR(30), age_group VARCHAR(20), member_code VARCHAR(30) UNIQUE, legacy_member_code VARCHAR(30), is_teacher TINYINT DEFAULT 0, is_staff TINYINT DEFAULT 0, is_committee TINYINT DEFAULT 0, is_volunteer TINYINT DEFAULT 0, status VARCHAR(20) DEFAULT \'active\'',
    'users' => 'id INT PRIMARY KEY, username VARCHAR(50), full_name VARCHAR(100), role VARCHAR(50), password_hash VARCHAR(255), member_id INT, is_active TINYINT DEFAULT 1',
    'staff_positions' => 'id INT PRIMARY KEY, role_code VARCHAR(20), title_en VARCHAR(100), department_id INT NULL, legacy_flag VARCHAR(20), sort_order INT DEFAULT 0, is_active TINYINT DEFAULT 1',
    'member_staff_positions' => 'member_id INT, position_id INT, assigned_by INT, PRIMARY KEY (member_id, position_id)',
    'departments' => 'id INT PRIMARY KEY, code VARCHAR(4), sort_order INT DEFAULT 0, is_active TINYINT DEFAULT 1',
    'member_code_migrations' => 'member_id INT UNIQUE, old_code VARCHAR(30), new_code VARCHAR(30), reason VARCHAR(50)',
    'activity_logs' => 'user_id INT NULL, username VARCHAR(100), action VARCHAR(100), details TEXT, entity_type VARCHAR(50), entity_id INT, ip_address VARCHAR(45), user_agent VARCHAR(255), created_at DATETIME',
];
foreach ($tables as $table => $columns) {
    $conn->query("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
}
$conn->query('CREATE TEMPORARY TABLE teacher_assignments (id INT PRIMARY KEY, teacher_id INT, is_active TINYINT)');
$conn->query("INSERT INTO staff_positions (id, role_code, title_en, legacy_flag) VALUES
    (1, 'T', 'Synthetic Teacher', 'is_teacher'), (2, 'D', 'Synthetic Staff', 'is_staff'),
    (3, 'K', 'Synthetic Committee', 'is_committee'), (4, 'V', 'Synthetic Volunteer', 'is_volunteer')");
if (!PositionSyncService::hasLegacyFlag($conn)) {
    throw new RuntimeException('Canonical test schema must include sql/020 legacy_flag.');
}
$checks = 0;
function checkSame($expected, $actual, string $label): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
}
function memberRow(mysqli $conn, int $id): array {
    return $conn->query("SELECT * FROM members WHERE id = $id")->fetch_assoc();
}
$qrPaths = [];
try {
    $idBase = random_int(1000000000, 1500000000);
    foreach (['regular', 'special_regular', 'honorary'] as $offset => $type) {
        $id = $idBase + $offset;
        $qrPath = $root . '/admin/id_cards/assets/qr/qr_member_' . $id . '.png';
        if (file_exists($qrPath)) throw new RuntimeException('Refusing to overwrite pre-existing QR fixture path.');
        $qrPaths[] = $qrPath;
        $conn->query("INSERT INTO members (id, full_name_am, student_name, father_name, member_type, age_group)
            VALUES ($id, 'Synthetic Manual Member', 'Synthetic', 'Manual', '$type', '18_plus')");
        $conn->query("INSERT INTO users (id, username, full_name, role, password_hash, member_id)
            VALUES ($id, 'manual_$id', 'Synthetic Teacher', 'teacher', 'not-a-login-hash', $id)");
        $conn->query("INSERT INTO teacher_assignments VALUES ($id, $id, 0)");
        // Every flag combination, active/inactive teacher-account state,
        // active/inactive teaching assignment and legacy override polarity.
        for ($mask = 0; $mask < 16; $mask++) {
            $sets = [];
            foreach (PositionSyncService::LEGACY_FLAGS as $bit => $flag) {
                $sets[] = $flag . ' = ' . (($mask >> $bit) & 1);
            }
            $conn->query('UPDATE members SET ' . implode(', ', $sets) . " WHERE id = $id");
            foreach ([0, 1] as $activeAccount) {
                $conn->query("UPDATE users SET is_active = $activeAccount WHERE id = $id");
                foreach ([0, 1] as $activeAssignment) {
                    $conn->query("UPDATE teacher_assignments SET is_active = $activeAssignment WHERE id = $id");
                    foreach ([[], array_fill_keys(PositionSyncService::LEGACY_FLAGS, 0),
                                  array_fill_keys(PositionSyncService::LEGACY_FLAGS, 1)] as $override) {
                        $result = syncMemberType($conn, $id, $override);
                        checkSame(['changed'=>false, 'old_type'=>$type, 'new_type'=>$type], $result, 'read-only snapshot');
                        checkSame($type, memberRow($conn, $id)['member_type'], 'roles never reclassify');
                    }
                }
            }
        }
        foreach ([[1, 2, 3, 4], [], [1], [2], []] as $positions) {
            $result = PositionSyncService::applyPositions($conn, $id, $positions);
            checkSame('success', $result['status'], 'position application succeeds');
            $member = memberRow($conn, $id);
            checkSame($type, $member['member_type'], 'position changes preserve manual type');
            checkSame($positions, PositionSyncService::currentPositionIds($conn, $id), 'positions still saved');
            foreach (PositionSyncService::LEGACY_FLAGS as $bit => $flag) {
                checkSame((int)in_array($bit + 1, $positions, true), (int)$member[$flag], 'derived flag ' . $flag);
            }
            $prefix = $positions === [] ? 'C-' : ($positions === [1] ? 'T-' : ($positions === [2] ? 'D-' : 'TDKV-'));
            checkSame(true, str_starts_with($member['member_code'], $prefix), 'identity prefix still follows positions');
        }
        // Account removal must retain the teacher flag while another active
        // teacher account is linked, but must never change membership type.
        $conn->query("UPDATE users SET is_active = 1 WHERE id = $id");
        syncMemberTeacherFlag($conn, $id, true);
        checkSame(1, (int)memberRow($conn, $id)['is_teacher'], 'teacher flag grant');
        checkSame([1], PositionSyncService::currentPositionIds($conn, $id), 'teacher position grant');
        checkSame($type, memberRow($conn, $id)['member_type'], 'teacher grant preserves type');
        syncMemberTeacherFlag($conn, $id, false);
        checkSame(1, (int)memberRow($conn, $id)['is_teacher'], 'other active account protects teacher flag');
        $conn->query("UPDATE users SET is_active = 0 WHERE id = $id");
        syncMemberTeacherFlag($conn, $id, false);
        checkSame(0, (int)memberRow($conn, $id)['is_teacher'], 'teacher flag removal');
        checkSame([], PositionSyncService::currentPositionIds($conn, $id), 'teacher position removal');
        checkSame($type, memberRow($conn, $id)['member_type'], 'teacher removal preserves type');
        // Simulate the explicit UPDATE used by authorized manual editors;
        // subsequent legacy callbacks must preserve each selected value.
        foreach (['regular', 'special_regular', 'honorary'] as $selection) {
            $conn->query("UPDATE members SET member_type = '$selection' WHERE id = $id");
            syncMemberTeacherFlag($conn, $id, true);
            PositionSyncService::applyPositions($conn, $id, []);
            syncMemberType($conn, $id);
            checkSame($selection, memberRow($conn, $id)['member_type'], 'manual selection survives callbacks');
        }
    }
    $before = $conn->query('SELECT id, member_type FROM members ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    checkSame(['checked'=>0, 'fixed'=>0, 'details'=>[]], batchSyncMemberTypes($conn), 'retired batch compatibility result');
    checkSame($before, $conn->query('SELECT id, member_type FROM members ORDER BY id')->fetch_all(MYSQLI_ASSOC), 'batch does not touch members');
    checkSame(['changed'=>false, 'old_type'=>'', 'new_type'=>''], syncMemberType($conn, -1), 'invalid member compatibility');
    checkSame(['changed'=>false, 'old_type'=>'', 'new_type'=>''], syncMemberType($conn, 999999999), 'missing member compatibility');
    $summary = getMemberRoleSummary(['member_type'=>'regular', 'is_teacher'=>1, 'is_staff'=>1]);
    checkSame(false, $summary['is_special'], 'regular role-holder is not relabeled special');
    checkSame(['Teacher', 'Staff'], $summary['roles'], 'role summary remains independent');
    checkSame(true, getMemberRoleSummary(['member_type'=>'special_regular'])['is_special'], 'special without roles remains special');
    checkSame(true, (int)$conn->query("SELECT COUNT(*) AS c FROM activity_logs WHERE action = 'Member Positions Synced'")->fetch_assoc()['c'] > 0, 'position audit preserved');
    echo json_encode(['status'=>'PASS', 'checks'=>$checks]) . PHP_EOL;
} finally {
    // Only paths checked absent before these synthetic calls may be removed.
    foreach ($qrPaths as $path) if (is_file($path)) unlink($path);
    $conn->close(); // all TEMPORARY fixtures disappear with the connection
}
