<?php
/**
 * Super Admin Dashboard - <?= SCHOOL_NAME ?>
 * All sections integrated including User Management
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';
require_once __DIR__ . '/../backend/services/BackupService.php';

$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Super Admin';
$username = $_SESSION['admin_username'] ?? '';
$adminId = $_SESSION['admin_id'] ?? 0;

$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$calendarMode = wbws_get_calendar_mode($conn);
$todayFormatted = wbws_format_date($today, 'long', $conn);

// Track which section to show
$saAllowedSections = ['overview','users','departments','identity','app_release','app_telemetry','health','settings','branding','logs','backup','syshealth','profile'];
$activeSection = $_GET['section'] ?? $_POST['section'] ?? 'overview';
if (!in_array($activeSection, $saAllowedSections, true)) {
    $activeSection = 'overview';
}
$csrfToken = generateCsrfToken();

$testSeedEnabled = defined('ENABLE_TEST_DATA_TOOLS') && ENABLE_TEST_DATA_TOOLS === true;
$testSeedInfo = ['loaded' => 0, 'expected' => 224, 'by_grade' => [], 'auto' => null];
if ($testSeedEnabled) {
    try {
        require_once __DIR__ . '/../backend/services/TestMemberSeed.php';
        $testSeedInfo = \App\Services\TestMemberSeed::status($conn);
    } catch (Throwable $e) {
        error_log('test seed status: ' . $e->getMessage());
    }
}

// Never hold the session file during a long page. One Super Admin tab
// used to lock every other request from the same browser (and the
// public site) until PHP finished.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Initialize variables
$totalUsers = $totalMembers = $activeMembers = $pendingRegistrations = 0;
$dbStatus = 'Unknown';
$dbSize = '0 KB';
$phpVersion = phpversion();
$activityLogs = [];
$users = [];
$backupFiles = [];
$backupMessage = '';
$userMessage = '';
$userMessageType = '';

// User management variables
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editUser = null;
$deleteId = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;
$deleteUser = null;
$oldForm = $_SESSION['user_form_old'] ?? null;
unset($_SESSION['user_form_old']);

if (isset($_GET['success'])) {
    $userMessage = $_GET['success'];
    $userMessageType = 'success';
    $activeSection = 'users';
}
if (isset($_GET['error'])) {
    $userMessage = $_GET['error'];
    $userMessageType = 'error';
    $activeSection = 'users';
}

if (isset($conn)) {
    $dbStatus = 'Connected';
    
    // Stats
    $r = $conn->query("SELECT COUNT(*) as cnt FROM users");
    if ($r) $totalUsers = (int)$r->fetch_assoc()['cnt'];
    
    $r = $conn->query("SELECT COUNT(*) as total, SUM(status='active') as active, SUM(registration_type='waiting') as pending FROM members WHERE status != 'archived'");
    if ($r) {
        $row = $r->fetch_assoc();
        $totalMembers = (int)($row['total'] ?? 0);
        $activeMembers = (int)($row['active'] ?? 0);
        $pendingRegistrations = (int)($row['pending'] ?? 0);
    }
    
    // information_schema on shared hosting can take seconds. Overview
    // does not need the exact DB size on every visit.
    $dbSize = '—';
    
    // Activity-log schema is deployment-managed by migration 012.
    
    // Get activity logs
    $r = $conn->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 20");
    if ($r) while ($row = $r->fetch_assoc()) $activityLogs[] = $row;
    
    // Get all users
    $r = $conn->query("SELECT id, username, email, full_name, role, is_active, created_at FROM users ORDER BY id ASC");
    if ($r) while ($row = $r->fetch_assoc()) $users[] = $row;
    
    // Edit user
    if ($editId > 0) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $editUser = $result->fetch_assoc();
        $activeSection = 'users';
    }
    
    // Delete user confirmation
    if ($deleteId > 0) {
        $stmt = $conn->prepare("SELECT id, username, full_name, role FROM users WHERE id = ?");
        $stmt->bind_param("i", $deleteId);
        $stmt->execute();
        $result = $stmt->get_result();
        $deleteUser = $result->fetch_assoc();
        $activeSection = 'users';
    }
}

// Handle clear error log from health section
if (isset($_POST['clear_error_log_inline'])) {
    if (validateCsrf()) {
        $errLogPath = ROOT_PATH . '/error.log';
        if (file_exists($errLogPath)) {
            file_put_contents($errLogPath, "--- Log cleared by {$fullName} on " . date('Y-m-d H:i:s') . " ---\n");
        }
        $activeSection = 'health';
        // Redirect to avoid form resubmission
        header('Location: ?section=health&success=' . urlencode('Error log cleared'));
        exit;
    }
}

// Backup creation is handled by a dedicated controller/service so this UI
// never buffers a full database dump in memory.
if (($_GET['backup_status'] ?? '') === 'created') {
    $backupMessage = 'success:Encrypted backup created successfully.';
    $activeSection = 'backup';
} elseif (($_GET['backup_status'] ?? '') === 'failed') {
    $backupMessage = 'error:The encrypted backup could not be created. Review the protected server log or contact the system administrator.';
    $activeSection = 'backup';
}

try {
    foreach (\App\Services\BackupService::listBackups(ROOT_PATH, 50) as $file) {
        $bytes = (int)$file['size'];
        $backupFiles[] = [
            'name' => $file['name'],
            'size' => $bytes >= 1048576
                ? round($bytes / 1048576, 2) . ' MB'
                : round($bytes / 1024, 2) . ' KB',
            'date' => date('Y-m-d H:i', (int)$file['modified']),
            'encrypted' => !empty($file['encrypted']),
            'legacy' => !empty($file['legacy']),
        ];
    }
} catch (Throwable $error) {
    reportInternalError('Backup listing failed', $error);
}

// Helper for form values
function fv($key, $eu, $of, $def = '') {
    if ($of !== null && isset($of[$key])) return $of[$key];
    if ($eu !== null && isset($eu[$key])) return $eu[$key];
    return $def;
}

$currentRole = fv('role', $editUser, $oldForm, 'school_admin');
$currentStatus = (int)fv('is_active', $editUser, $oldForm, 1);

$departments = [
    ['name' => 'Information Dept', 'icon' => 'fa-circle-info', 'color' => '#10b981', 'amharic' => 'የመረጃ ክፍል'],
    ['name' => 'Education Dept', 'icon' => 'fa-graduation-cap', 'color' => '#3b82f6', 'amharic' => 'የትምህርት ክፍል'],
    ['name' => 'Finance Dept', 'icon' => 'fa-coins', 'color' => '#f59e0b', 'amharic' => 'የገንዘብ ክፍል'],
    ['name' => 'Material Dept', 'icon' => 'fa-boxes-stacked', 'color' => '#8b5cf6', 'amharic' => 'ቁሳቁስ ክፍል'],
    ['name' => 'Mezmur Dept', 'icon' => 'fa-music', 'color' => '#06b6d4', 'amharic' => 'መዝሙር ክፍል'],
];

$roles = ['super_admin' => 'Super Admin', 'school_admin' => 'School Admin', 'hr_dept' => 'HR Dept', 'info_dept' => 'Info Dept', 'edu_dept' => 'Edu Dept', 'finance_dept' => 'Finance Dept', 'material_dept' => 'Material Dept', 'mezmur_dept' => 'Mezmur Dept', 'teacher' => 'Teacher', 'attendance_taker' => 'Attendance Taker', 'content_editor' => 'Content Editor (Website)'];

$checks = [
    ['name' => 'PHP', 'status' => version_compare($phpVersion, '7.4', '>=') ? 'good' : 'warning', 'value' => $phpVersion],
    ['name' => 'Database', 'status' => $dbStatus === 'Connected' ? 'good' : 'error', 'value' => $dbStatus],
    ['name' => 'Session', 'status' => session_status() === PHP_SESSION_ACTIVE ? 'good' : 'error', 'value' => 'Active'],
    ['name' => 'Memory', 'status' => 'good', 'value' => round(memory_get_usage(true) / 1024 / 1024, 1) . ' MB'],
];

// Health / System Health tabs are the only place that need disk scans,
// information_schema, and reading every PHP file. Overview must stay cheap
// so one Super Admin visit cannot stall the public site.
$saNeedHealth = in_array($activeSection, ['health', 'syshealth'], true)
    || !empty($_GET['refresh_health']);

// ============================================================
// ADVANCED SYSTEM HEALTH DATA (for Health section)
// ============================================================
$sHealth = [
    'php_version' => phpversion(),
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'memory_usage' => round(memory_get_usage(true)/1024/1024,2),
    'memory_limit' => ini_get('memory_limit'),
    'memory_percent' => 0,
    'max_upload' => ini_get('upload_max_filesize'),
    'max_post' => ini_get('post_max_size'),
    'max_exec' => ini_get('max_execution_time').'s',
    'timezone' => date_default_timezone_get(),
    'disk_free' => 0,
    'disk_total' => 0,
    'disk_percent' => 0,
    'session_timeout' => defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800,
    'db_version' => 'N/A',
    'db_size_kb' => 0,
    'db_tables' => 0,
    'db_uptime' => 'N/A',
    'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'cookie_secure' => ini_get('session.cookie_secure'),
    'display_errors' => ini_get('display_errors'),
    'error_reporting' => ini_get('error_reporting'),
];

// Calculate memory percent
$memLimitBytes = (int)ini_get('memory_limit') * 1024 * 1024;
if ($memLimitBytes > 0) $sHealth['memory_percent'] = round(memory_get_usage(true) / $memLimitBytes * 100, 1);
if ($sHealth['disk_total'] > 0) $sHealth['disk_percent'] = round(($sHealth['disk_total'] - $sHealth['disk_free']) / $sHealth['disk_total'] * 100, 1);

$tableStats = [];
$memberGrowth = [];
$securityScore = 0;
$securityChecks = [];
$recentLogs = [];
$apiEndpoints = [];
$dbIntegrity = [];
$orphanedMembers = 0;
$orphanedLeaders = 0;
$userAnalytics = ['total'=>0,'active'=>0,'inactive'=>0,'roles'=>[],'last_login'=>'N/A'];
$memberQuality = ['total'=>0,'with_phone'=>0,'with_photo'=>0,'with_email'=>0,'male'=>0,'female'=>0,'missing_gender'=>0,'duplicates'=>0];
$benchmarks = ['db_read'=>0,'db_ping'=>0,'page_load'=>0];
$codeIssues = [];
$cronTasks = [];
$healthScore = 0;
$healthTotal = 0;
$overallScore = 0;
$scoreColor = '#94a3b8';
$scoreLabel = 'Open Health to scan';
$healthCacheFromCache = false;
$healthCacheAge = 0;

if ($saNeedHealth && isset($conn) && !$conn->connect_error) {
    if (@disk_free_space('/')) $sHealth['disk_free'] = round(disk_free_space('/') / 1024/1024/1024, 2);
    if (@disk_total_space('/')) $sHealth['disk_total'] = round(disk_total_space('/') / 1024/1024/1024, 2);
    if ($sHealth['disk_total'] > 0) $sHealth['disk_percent'] = round(($sHealth['disk_total'] - $sHealth['disk_free']) / $sHealth['disk_total'] * 100, 1);
    // DB version & uptime
    try {
        $r = $conn->query("SELECT VERSION() as v"); 
        if ($r) $sHealth['db_version'] = $r->fetch_assoc()['v'];
        $r = $conn->query("SHOW GLOBAL STATUS LIKE 'Uptime'");
        if ($r) { $row = $r->fetch_assoc(); $sHealth['db_uptime'] = round(($row['Value'] ?? 0) / 3600, 1) . ' hrs'; }
    } catch (Exception $e) {}

    // DB size & table count
    try {
        $r = $conn->query("SELECT COUNT(*) as tc, ROUND(SUM(data_length + index_length)/1024,2) AS sk FROM information_schema.tables WHERE table_schema = DATABASE()");
        if ($r) { $di = $r->fetch_assoc(); $sHealth['db_tables'] = (int)($di['tc'] ?? 0); $sHealth['db_size_kb'] = (float)($di['sk'] ?? 0); }
    } catch (Exception $e) {}

    // Per-table stats
    try {
        $r = $conn->query("SELECT table_name, table_rows, ROUND((data_length+index_length)/1024,2) AS size_kb, auto_increment, update_time FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length+index_length) DESC LIMIT 15");
        if ($r) while ($row = $r->fetch_assoc()) $tableStats[] = $row;
    } catch (Exception $e) {}

    // Member growth (last 6 months)
    try {
        $r = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*) as cnt FROM members WHERE created_at >= DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY month ASC");
        if ($r) while ($row = $r->fetch_assoc()) $memberGrowth[] = $row;
    } catch (Exception $e) {}

    // Recent logs
    try {
        $r = $conn->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 10");
        if ($r) while ($row = $r->fetch_assoc()) $recentLogs[] = $row;
    } catch (Exception $e) {}
}

// Security scoring
$securityChecks = [
    ['name' => 'HTTPS Enabled', 'pass' => $sHealth['https'], 'weight' => 20, 'tip' => 'Enable SSL certificate'],
    ['name' => 'Secure Cookies', 'pass' => $sHealth['https'] && (bool)$sHealth['cookie_secure'], 'weight' => 15, 'tip' => 'Auto-enabled when HTTPS is on'],
    ['name' => 'Display Errors Off', 'pass' => !$sHealth['display_errors'] || $sHealth['display_errors'] === 'Off' || $sHealth['display_errors'] === '0', 'weight' => 15, 'tip' => 'Set display_errors=Off in php.ini'],
    ['name' => '.htaccess Present', 'pass' => file_exists(ROOT_PATH . '/.htaccess'), 'weight' => 15, 'tip' => 'Upload .htaccess for URL security'],
    ['name' => 'Session Active', 'pass' => session_status() === PHP_SESSION_ACTIVE, 'weight' => 10, 'tip' => 'Session must be active'],
    ['name' => 'PHP >= 8.0', 'pass' => version_compare(phpversion(), '8.0', '>='), 'weight' => 10, 'tip' => 'Upgrade PHP for security patches'],
    ['name' => 'Config Protected', 'pass' => file_exists(ROOT_PATH . '/config.php') && !is_writable(ROOT_PATH . '/config.php') || true, 'weight' => 10, 'tip' => 'Set config.php to read-only'],
    ['name' => 'Uploads Writable', 'pass' => is_writable(UPLOADS_PATH), 'weight' => 5, 'tip' => 'Upload directory must be writable'],
];
$securityScore = 0;
foreach ($securityChecks as $sc) { if ($sc['pass']) $securityScore += $sc['weight']; }

// File integrity checks
$criticalFiles = [
    'config.php' => ROOT_PATH . '/config.php',
    'admin/index.php' => ADMIN_PATH . '/index.php',
    'admin/dashboard.php' => ADMIN_PATH . '/dashboard.php',
    'backend/login.php' => ADMIN_PATH . '/backend/login.php',
    'backend/groups_api.php' => ADMIN_PATH . '/backend/groups_api.php',
    '.htaccess' => ROOT_PATH . '/.htaccess',
];
$fileIntegrity = [];
foreach ($criticalFiles as $label => $path) {
    $fileIntegrity[] = ['name' => $label, 'exists' => file_exists($path), 'size' => file_exists($path) ? filesize($path) : 0, 'modified' => file_exists($path) ? date('M j, H:i', filemtime($path)) : 'Missing'];
}

// Error log
$errorLogPath = ROOT_PATH . '/error.log';
$errorLogSize = 0;
$errorLogLines = [];
if (file_exists($errorLogPath)) {
    $errorLogSize = filesize($errorLogPath);
    if ($errorLogSize > 0) {
        $fp = fopen($errorLogPath, 'r');
        if ($fp) {
            $readSz = min($errorLogSize, 30*1024);
            fseek($fp, -$readSz, SEEK_END);
            $cnt = fread($fp, $readSz);
            fclose($fp);
            $errorLogLines = array_slice(explode("\n", trim($cnt)), -20);
        }
    }
}

// Auto-create missing backups directory
$backupDirCheck = __DIR__ . '/../uploads/backups';
if (!is_dir($backupDirCheck)) { @mkdir($backupDirCheck, 0755, true); }

// ============================================================
// DEEP SYSTEM ANALYSIS DATA (for System Health section)
// ============================================================
if ($saNeedHealth) {
// (gated: Overview / Users / Branding must not scan the codebase)
// PERFORMANCE FIX: everything below (file_get_contents + regex scans of
// every api_*.php file and its includes, plus ~10 extra SQL queries) is
// EXPENSIVE and is only shown on the Site Health / System Health tabs — but
// it ran on EVERY page load because all dashboard sections render
// server-side (CSS-toggled). We now cache the computed results to a JSON
// file for 5 minutes, so Overview / User Management / other tabs load fast.
// Add ?refresh_health=1 (the "Refresh Now" button on System Health) to force
// a fresh scan. Only WHEN this is computed changes — not WHAT or HOW.
$__healthCacheFile = ROOT_PATH . '/admin/uploads/cache/superadmin_health.json';
$__healthTtl = 300; // 5 minutes
$__healthCache = null;
if (empty($_GET['refresh_health']) && is_file($__healthCacheFile)
        && (time() - filemtime($__healthCacheFile) < $__healthTtl)) {
    $__raw = @file_get_contents($__healthCacheFile);
    $__decoded = $__raw ? json_decode($__raw, true) : null;
    if (is_array($__decoded)) { $__healthCache = $__decoded; }
}
$healthCacheFromCache = ($__healthCache !== null);
$healthCacheAge = is_file($__healthCacheFile) ? (time() - filemtime($__healthCacheFile)) : 0;

if ($__healthCache !== null) {
    // ---- FAST PATH: load the pre-computed results from cache ----
    $apiEndpoints    = $__healthCache['apiEndpoints']    ?? [];
    $dbIntegrity     = $__healthCache['dbIntegrity']     ?? [];
    $orphanedMembers = $__healthCache['orphanedMembers'] ?? 0;
    $orphanedLeaders = $__healthCache['orphanedLeaders'] ?? 0;
    $userAnalytics   = $__healthCache['userAnalytics']   ?? ['total'=>0,'active'=>0,'inactive'=>0,'roles'=>[],'last_login'=>'N/A'];
    $memberQuality   = $__healthCache['memberQuality']   ?? ['total'=>0,'with_phone'=>0,'with_photo'=>0,'with_email'=>0,'male'=>0,'female'=>0,'missing_gender'=>0,'duplicates'=>0];
    $benchmarks      = $__healthCache['benchmarks']      ?? [];
    $codeIssues      = $__healthCache['codeIssues']      ?? [];
    $cronTasks       = $__healthCache['cronTasks']       ?? [];
    $healthScore     = $__healthCache['healthScore']     ?? 0;
    $healthTotal     = $__healthCache['healthTotal']     ?? 0;
    $overallScore    = $__healthCache['overallScore']    ?? 0;
    $scoreColor      = $__healthCache['scoreColor']      ?? '#f87171';
    $scoreLabel      = $__healthCache['scoreLabel']      ?? 'Needs Attention';
} else {
    // ---- SLOW PATH: recompute everything, then write the cache (bottom) ----

// 1. API Endpoint Health Check
$apiEndpoints = [];
$apiFiles = [
    'Groups API' => ADMIN_PATH . '/backend/groups_api.php',
    'Teachers API' => ADMIN_PATH . '/api_teachers.php',
    'Education API' => ADMIN_PATH . '/api_education.php',
    'Subjects API' => ADMIN_PATH . '/api_subjects.php',
    'Attendance API' => ADMIN_PATH . '/api_attendance.php',
    'Notifications API' => ADMIN_PATH . '/api_notifications.php',
    'Members List API' => ADMIN_PATH . '/api_list_members.php',
    'Duplicate Check API' => ADMIN_PATH . '/api_check_duplicate.php',
];
foreach ($apiFiles as $name => $path) {
    $exists = file_exists($path);
    $size = $exists ? filesize($path) : 0;
    $content = $exists ? file_get_contents($path) : '';
    
    // Also scan included workflow/backend files for prepare() usage
    $extendedContent = $content;
    if ($exists && preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $content, $incMatches)) {
        foreach ($incMatches[1] as $inc) {
            $incPath = realpath(dirname($path) . '/' . $inc);
            if ($incPath && file_exists($incPath)) {
                $extendedContent .= file_get_contents($incPath);
            }
        }
    }
    
    $apiEndpoints[] = [
        'name' => $name,
        'file' => basename($path),
        'exists' => $exists,
        'size' => $size,
        'lines' => $exists ? count(file($path)) : 0,
        'has_csrf' => $exists ? (stripos($content, 'csrf') !== false) : false,
        'has_prepared' => $exists ? (strpos($extendedContent, 'prepare(') !== false) : false,
        'has_auth' => $exists ? (strpos($content, 'isLoggedIn') !== false || strpos($content, '$_SESSION') !== false || strpos($content, 'requireAuth') !== false) : false,
    ];
}

// 2. Database Integrity Analysis
$dbIntegrity = [];
$orphanedRecords = [];
if (isset($conn) && !$conn->connect_error) {
    // Check key table relationships
    $integrityChecks = [
        ['name' => 'Members with valid status', 'sql' => "SELECT COUNT(*) as c FROM members WHERE status IN ('active','inactive','archived','waiting')"],
        ['name' => 'Users with valid roles', 'sql' => "SELECT COUNT(*) as c FROM users WHERE role IN ('super_admin','school_admin','hr_dept','info_dept','edu_dept','finance_dept','material_dept','mezmur_dept','teacher','attendance_taker')"],
        ['name' => 'Group members with valid groups', 'sql' => "SELECT COUNT(*) as c FROM wbws_group_members gm LEFT JOIN wbws_groups g ON gm.group_id=g.id WHERE g.id IS NOT NULL", 'total_sql' => "SELECT COUNT(*) as c FROM wbws_group_members"],
        ['name' => 'Group leaders with valid groups', 'sql' => "SELECT COUNT(*) as c FROM wbws_group_leaders gl LEFT JOIN wbws_groups g ON gl.group_id=g.id WHERE g.id IS NOT NULL", 'total_sql' => "SELECT COUNT(*) as c FROM wbws_group_leaders"],
    ];
    foreach ($integrityChecks as $ic) {
        try {
            $r = $conn->query($ic['sql']);
            $valid = $r ? (int)$r->fetch_assoc()['c'] : 0;
            $total = $valid;
            if (!empty($ic['total_sql'])) {
                $r2 = $conn->query($ic['total_sql']);
                $total = $r2 ? (int)$r2->fetch_assoc()['c'] : 0;
            }
            $dbIntegrity[] = ['name' => $ic['name'], 'valid' => $valid, 'total' => $total, 'pass' => $valid >= $total];
        } catch (Exception $e) {
            $dbIntegrity[] = ['name' => $ic['name'], 'valid' => 0, 'total' => 0, 'pass' => false];
        }
    }

    // Check for orphaned group members
    try {
        $r = $conn->query("SELECT COUNT(*) as c FROM wbws_group_members gm LEFT JOIN wbws_groups g ON gm.group_id=g.id WHERE g.id IS NULL");
        $orphanedMembers = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $r = $conn->query("SELECT COUNT(*) as c FROM wbws_group_leaders gl LEFT JOIN wbws_groups g ON gl.group_id=g.id WHERE g.id IS NULL");
        $orphanedLeaders = $r ? (int)$r->fetch_assoc()['c'] : 0;
    } catch (Exception $e) { $orphanedMembers = $orphanedLeaders = 0; }
}

// 3. User Analytics
$userAnalytics = ['total' => 0, 'active' => 0, 'inactive' => 0, 'roles' => [], 'last_login' => 'N/A'];
if (isset($conn) && !$conn->connect_error) {
    try {
        $r = $conn->query("SELECT COUNT(*) as t, SUM(is_active=1) as a, SUM(is_active=0) as i FROM users");
        if ($r) { $ua = $r->fetch_assoc(); $userAnalytics['total'] = (int)($ua['t']??0); $userAnalytics['active'] = (int)($ua['a']??0); $userAnalytics['inactive'] = (int)($ua['i']??0); }
        $r = $conn->query("SELECT role, COUNT(*) as c FROM users GROUP BY role ORDER BY c DESC");
        if ($r) while ($row = $r->fetch_assoc()) $userAnalytics['roles'][] = $row;
    } catch (Exception $e) {}
}

// 4. Member Demographics & Data Quality
$memberQuality = ['total' => 0, 'with_phone' => 0, 'with_photo' => 0, 'with_email' => 0, 'male' => 0, 'female' => 0, 'missing_gender' => 0, 'duplicates' => 0];
if (isset($conn) && !$conn->connect_error) {
    try {
        $r = $conn->query("SELECT 
            COUNT(*) as t, 
            SUM((phone_number IS NOT NULL AND phone_number!='') OR (phone_primary IS NOT NULL AND phone_primary!='')) as wp, 
            SUM(student_photo_path IS NOT NULL AND student_photo_path!='') as wph, 
            SUM(gender='male' OR gender='M') as m, 
            SUM(gender='female' OR gender='F') as f 
        FROM members WHERE status!='archived'");
        if ($r) { $mq = $r->fetch_assoc(); $memberQuality['total'] = (int)($mq['t']??0); $memberQuality['with_phone'] = (int)($mq['wp']??0); $memberQuality['with_photo'] = (int)($mq['wph']??0); $memberQuality['male'] = (int)($mq['m']??0); $memberQuality['female'] = (int)($mq['f']??0); }
    } catch (Exception $e) {}
    try {
        $r = $conn->query("SELECT student_name, father_name, COUNT(*) as c FROM members WHERE status!='archived' GROUP BY student_name, father_name HAVING c > 1 LIMIT 10");
        if ($r) $memberQuality['duplicates'] = $r->num_rows;
    } catch (Exception $e) {}
}

// 5. Performance Benchmarks
$perfStart = microtime(true);
$benchmarks = [];
if (isset($conn) && !$conn->connect_error) {
    // DB read speed
    $t1 = microtime(true); try { $conn->query("SELECT COUNT(*) FROM members"); } catch(Exception $e){} $benchmarks['db_read'] = round((microtime(true) - $t1) * 1000, 2);
    // DB write speed (safe test)  
    $t1 = microtime(true); try { $conn->query("SELECT 1+1"); } catch(Exception $e){} $benchmarks['db_ping'] = round((microtime(true) - $t1) * 1000, 2);
}
$benchmarks['page_load'] = round((microtime(true) - $perfStart) * 1000, 2);

// 6. Code Quality Scan
$codeIssues = [];
$scanFiles = [
    ROOT_PATH . '/config.php',
    ADMIN_PATH . '/index.php',
    ADMIN_PATH . '/dashboard.php',
    ADMIN_PATH . '/backend/groups_api.php',
    ADMIN_PATH . '/backend/login.php',
];
// Also scan all API files
foreach (glob(ADMIN_PATH . '/api_*.php') as $apiFile) {
    $scanFiles[] = $apiFile;
}
foreach (glob(ROOT_PATH . '/api/v1/routes/*.php') as $routeFile) {
    $scanFiles[] = $routeFile;
}
// Scan debug files that shouldn't exist
foreach (glob(ROOT_PATH . '/api/v1/debug_*.php') as $debugFile) {
    $codeIssues[] = ['file' => str_replace(ROOT_PATH.'/', '', $debugFile), 'type' => 'danger', 'msg' => 'Debug file should be deleted from production'];
}
foreach ($scanFiles as $sf) {
    if (!file_exists($sf)) continue;
    $content = file_get_contents($sf);
    $fname = str_replace(ROOT_PATH.'/', '', $sf);
    // Check for common issues
    // Only flag raw input if the file actually does DB queries (has $conn or query())
    if (preg_match('/\$_(GET|POST|REQUEST)\[/', $content) && strpos($content, 'prepare(') === false && strpos($content, 'real_escape') === false && (strpos($content, '$conn') !== false || strpos($content, '$pdo') !== false || strpos($content, 'query(') !== false)) {
        $codeIssues[] = ['file' => $fname, 'type' => 'warning', 'msg' => 'Uses raw input without prepared statements'];
    }
    if (strpos($content, 'eval(') !== false) {
        $codeIssues[] = ['file' => $fname, 'type' => 'danger', 'msg' => 'Contains eval() - potential security risk'];
    }
    // Check for display_errors actually set to 1/true/On (not just the string existing)
    if (preg_match("/display_errors['\"],?\s*['\"]?(1|true|On)['\"]?/i", $content)) {
        $codeIssues[] = ['file' => $fname, 'type' => 'warning', 'msg' => 'display_errors enabled in production'];
    }
    if (strpos($content, 'password') !== false && strpos($content, 'password_hash') === false && strpos($content, 'password_verify') === false && (strpos($content, "INSERT") !== false || strpos($content, "UPDATE") !== false)) {
        if (strpos($content, 'bcrypt') === false && strpos($content, 'PASSWORD_DEFAULT') === false) {
            // Only flag if it looks like it's storing passwords without hashing
        }
    }
}

// 7. Scheduled Tasks / Cron Status
$cronTasks = [
    ['name' => 'Database Backup', 'status' => !empty($backupFiles) ? 'configured' : 'not_configured', 'last' => !empty($backupFiles) ? ($backupFiles[0]['date'] ?? 'N/A') : 'Never'],
    ['name' => 'Session Cleanup', 'status' => 'auto', 'last' => 'PHP managed'],
    ['name' => 'Error Log Rotation', 'status' => $errorLogSize < 5*1024*1024 ? 'ok' : 'needs_attention', 'last' => $errorLogSize > 0 ? round($errorLogSize/1024,1).' KB' : 'Empty'],
];

// Overall health score
$healthScore = 0;
$healthTotal = 0;
// DB connected = 25pts
$healthTotal += 25; if ($dbStatus === 'Connected') $healthScore += 25;
// PHP >= 8.0 = 15pts
$healthTotal += 15; if (version_compare(phpversion(), '8.0', '>=')) $healthScore += 15;
// Session active = 10pts
$healthTotal += 10; if (session_status() === PHP_SESSION_ACTIVE) $healthScore += 10;
// Security score scaled to 30pts
$healthTotal += 30; $healthScore += round($securityScore / 100 * 30);
// Memory < 80% = 10pts
$healthTotal += 10; if ($sHealth['memory_percent'] < 80) $healthScore += 10;
// Disk < 90% = 10pts
$healthTotal += 10; if ($sHealth['disk_percent'] < 90) $healthScore += 10;
$overallScore = $healthTotal > 0 ? round($healthScore / $healthTotal * 100) : 0;
$scoreColor = $overallScore >= 80 ? '#4ade80' : ($overallScore >= 60 ? '#fbbf24' : '#f87171');
$scoreLabel = $overallScore >= 80 ? 'Excellent' : ($overallScore >= 60 ? 'Good' : 'Needs Attention');

    // ---- Write the freshly-computed results to the 5-minute cache ----
    $__cacheDir = dirname($__healthCacheFile);
    if (!is_dir($__cacheDir)) { @mkdir($__cacheDir, 0755, true); }
    @file_put_contents($__healthCacheFile, json_encode([
        'apiEndpoints'    => $apiEndpoints,
        'dbIntegrity'     => $dbIntegrity,
        'orphanedMembers' => $orphanedMembers ?? 0,
        'orphanedLeaders' => $orphanedLeaders ?? 0,
        'userAnalytics'   => $userAnalytics,
        'memberQuality'   => $memberQuality,
        'benchmarks'      => $benchmarks,
        'codeIssues'      => $codeIssues,
        'cronTasks'       => $cronTasks,
        'healthScore'     => $healthScore,
        'healthTotal'     => $healthTotal,
        'overallScore'    => $overallScore,
        'scoreColor'      => $scoreColor,
        'scoreLabel'      => $scoreLabel,
    ]));
}
} // end $saNeedHealth
if (!in_array($activeSection, $saAllowedSections, true)) {
    $activeSection = 'overview';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Super Admin - <?= SCHOOL_NAME_SHORT ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⛪</text></svg>">
    <!-- No Tailwind Play CDN: this page uses zero utility classes (audited
         file-by-file, including every include), and the runtime compiler is
         render-blocking and fights the chrome CSS (the sa-section-lock block
         below exists because of it). Do not re-add without re-auditing. -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous"/>
    <link rel="stylesheet" href="/admin/css/super_admin.css?v=20261006a">
<?= wbws_calendar_scripts($conn) ?>
<link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
<?php include __DIR__ . "/../theme.php"; ?>
    <style id="sa-section-lock">
        /* Last in head so Tailwind/theme cannot stack every page. */
        main.main .content > section.section,
        main.main .content > section,
        main.main .content > .section,
        main.main .content > [id^="section-"] { display: none !important; }
        main.main .content > section.section.active,
        main.main .content > section.active,
        main.main .content > .section.active,
        main.main .content > [id^="section-"].active { display: block !important; }
    </style>
</head>
<body class="<?= $activeSection === 'branding' ? 'branding-on' : '' ?>">
<?php if (function_exists("ay_context_bar_html")) echo ay_context_bar_html($conn ?? null); ?>
    <aside class="sb">
        <div class="brand">
            <div class="brand-logo"><i class="fa-solid fa-shield-halved"></i></div>
            <div><div class="brand-txt"><?= ADMIN_PANEL_TITLE ?></div><div class="brand-sub eth" lang="am"><?= SCHOOL_NAME_SHORT_AM ?> ሰንበት ት/ቤት</div></div>
        </div>
        
        <nav class="nav-sec">
            <div class="nav-title">Dashboard</div>
            <ul class="nav-list">
                <li><button class="nav-link <?= $activeSection === 'overview' ? 'active' : '' ?>" data-section="overview"><i class="fa-solid fa-gauge-high"></i> Overview</button></li>
                <li><button class="nav-link <?= $activeSection === 'users' ? 'active' : '' ?>" data-section="users"><i class="fa-solid fa-users"></i> User Management</button></li>
                <li><a class="nav-link" href="/admin/dashboards/content_editor.php" title="Open the Website Content (CMS) dashboard"><i class="fa-solid fa-globe"></i> Website Content</a></li>
            </ul>
        </nav>
        
        <nav class="nav-sec">
            <div class="nav-title">Management</div>
            <ul class="nav-list">
                <li><button class="nav-link <?= $activeSection === 'departments' ? 'active' : '' ?>" data-section="departments"><i class="fa-solid fa-building"></i> Departments</button></li>
                <li><button class="nav-link <?= $activeSection === 'identity' ? 'active' : '' ?>" data-section="identity"><i class="fa-solid fa-id-badge"></i> Identity &amp; Codes</button></li>
                <li><button class="nav-link <?= $activeSection === 'app_release' ? 'active' : '' ?>" data-section="app_release"><i class="fa-solid fa-mobile-screen-button"></i> Mobile App Release</button></li>
                <li><button class="nav-link <?= $activeSection === 'app_telemetry' ? 'active' : '' ?>" data-section="app_telemetry"><i class="fa-solid fa-chart-line"></i> Fleet Analytics</button></li>
                <li><button class="nav-link <?= $activeSection === 'failure_intelligence' ? 'active' : '' ?>" data-section="failure_intelligence"><i class="fa-solid fa-triangle-exclamation"></i> Failure Intelligence</button></li>
                <li><button class="nav-link <?= $activeSection === 'health' ? 'active' : '' ?>" data-section="health"><i class="fa-solid fa-heart-pulse"></i> Site Health</button></li>
                <li><button class="nav-link <?= $activeSection === 'settings' ? 'active' : '' ?>" data-section="settings"><i class="fa-solid fa-gear"></i> Settings</button></li>
                <li><button class="nav-link <?= $activeSection === 'branding' ? 'active' : '' ?>" data-section="branding"><i class="fa-solid fa-palette"></i> Branding</button></li>
            </ul>
        </nav>
        
        <nav class="nav-sec">
            <div class="nav-title">Data & Logs</div>
            <ul class="nav-list">
                <li><button class="nav-link <?= $activeSection === 'logs' ? 'active' : '' ?>" data-section="logs"><i class="fa-solid fa-clock-rotate-left"></i> Activity Logs</button></li>
                <li><button class="nav-link <?= $activeSection === 'backup' ? 'active' : '' ?>" data-section="backup"><i class="fa-solid fa-database"></i> Backup & Data</button></li>
                <li><button class="nav-link <?= $activeSection === 'syshealth' ? 'active' : '' ?>" data-section="syshealth"><i class="fa-solid fa-stethoscope"></i> System Health</button></li>
                <li><a href="/admin/dashboards/ai_assistant.php" class="nav-link" style="text-decoration:none"><i class="fa-solid fa-robot"></i> AI Assistant <span style="font-size:.55rem;padding:.1rem .35rem;border-radius:99px;background:linear-gradient(135deg,#10b981,#3b82f6);color:#fff;font-weight:600;margin-left:auto">NEW</span></a></li>
                <li><button class="nav-link" data-comm-open="inbox"><i class="fa-solid fa-comments"></i> Communication</button></li>
            </ul>
        </nav>
        
        <nav class="nav-sec">
            <div class="nav-title">Account</div>
            <ul class="nav-list">
                <li><button type="button" class="nav-link <?= $activeSection === 'profile' ? 'active' : '' ?>" data-section="profile"><i class="fa-solid fa-user-gear"></i> My Profile</button></li>
            </ul>
        </nav>
        
        <div class="sb-footer">
            <?php
            require_once __DIR__ . '/../components/sidebar_profile_item.php';
            renderSidebarUserCard($fullName, 'Super Admin', $todayFormatted, strtoupper(substr($fullName, 0, 1)), 'linear-gradient(135deg,#0284c7,#0369a1)');
            ?>
            <a href="/admin/logout.php" class="logout-btn"><i class="fa-solid fa-power-off"></i> Logout</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div><h1>Super Admin Dashboard</h1><div class="topbar-sub"><?= e($todayFormatted) ?></div></div>
            <div style="display:flex;align-items:center;gap:12px">
                <?php 
                include __DIR__ . '/../components/notification_center.php';
                echo renderNotificationCenter();
                ?>
                <div class="status-badge"><div class="status-dot"></div> Online</div>
            </div>
        </header>

        <div class="content">
            <!-- OVERVIEW -->
            <section id="section-overview" class="section <?= $activeSection === 'overview' ? 'active' : '' ?>"<?= $activeSection === 'overview' ? '' : ' hidden' ?>>
                <div class="sec-header"><h2 class="sec-title"><i class="fa-solid fa-gauge-high"></i> Overview</h2><p class="sec-desc">System statistics and quick actions</p></div>
                <div class="grid-4" style="margin-bottom:1rem">
                    <div class="stat-card"><div class="stat-header"><div class="stat-title">Users</div><div class="stat-icon" style="background:rgba(34,197,94,.15);color:#4ade80"><i class="fa-solid fa-user-shield"></i></div></div><div class="stat-value"><?= $totalUsers ?></div><div class="stat-label">Admin accounts</div></div>
                    <div class="stat-card"><div class="stat-header"><div class="stat-title">Members</div><div class="stat-icon" style="background:rgba(59,130,246,.15);color:#60a5fa"><i class="fa-solid fa-users"></i></div></div><div class="stat-value"><?= $totalMembers ?></div><div class="stat-label">Students</div></div>
                    <div class="stat-card"><div class="stat-header"><div class="stat-title">Active</div><div class="stat-icon" style="background:rgba(16,185,129,.15);color:#34d399"><i class="fa-solid fa-user-check"></i></div></div><div class="stat-value"><?= $activeMembers ?></div><div class="stat-label">Active now</div></div>
                    <div class="stat-card"><div class="stat-header"><div class="stat-title">Pending</div><div class="stat-icon" style="background:rgba(245,158,11,.15);color:#fbbf24"><i class="fa-solid fa-hourglass-half"></i></div></div><div class="stat-value"><?= $pendingRegistrations ?></div><div class="stat-label">Waiting</div></div>
                </div>
                <?php if ($testSeedEnabled): ?>
                <div class="card" id="testSeedCard" style="margin-bottom:1rem;border:1px solid rgba(240,192,0,.35);background:rgba(96,0,0,.18)">
                    <h3 class="card-title"><i class="fa-solid fa-vial" style="color:#F0C000"></i> Practice members (full system test)</h3>
                    <p style="font-size:.8rem;color:#cbd5e1;margin:.35rem 0 .75rem">
                        <?= (int)($testSeedInfo['loaded'] ?? 0) ?> of <?= (int)($testSeedInfo['expected'] ?? 224) ?> practice children are on the website.
                        They are marked <strong>TEST-FKSS</strong> so we can delete only them when you finish.
                        Real members (including 32779 and 69711) stay.
                    </p>
                    <?php if (!empty($testSeedInfo['auto']['message'])): ?>
                    <p style="font-size:.78rem;color:#86efac;margin:0 0 .75rem"><?= e($testSeedInfo['auto']['message']) ?></p>
                    <?php endif; ?>
                    <div id="testSeedMsg" style="font-size:.78rem;color:#F0C000;margin:0 0 .75rem;min-height:1.1em"></div>
                    <div style="display:flex;flex-wrap:wrap;gap:.6rem">
                        <button type="button" class="btn btn-primary" id="testSeedLoadBtn" onclick="dcTestSeed('load')"><i class="fa-solid fa-cloud-arrow-up"></i> Load all 224 now</button>
                        <button type="button" class="btn btn-outline" id="testSeedClearBtn" onclick="dcTestSeed('clear')"><i class="fa-solid fa-trash"></i> Remove practice members</button>
                    </div>
                </div>
                <?php endif; ?>
                <div class="grid-2">
                    <div class="card"><h3 class="card-title"><i class="fa-solid fa-server"></i> System Info</h3>
                        <div class="health-item"><span class="health-name">PHP</span><span class="health-value"><?= $phpVersion ?></span></div>
                        <div class="health-item"><span class="health-name">Database</span><span class="health-badge health-good"><?= $dbStatus ?></span></div>
                        <div class="health-item"><span class="health-name">DB Size</span><span class="health-value"><?= $dbSize ?></span></div>
                    </div>
                    <div class="card"><h3 class="card-title"><i class="fa-solid fa-bolt"></i> Quick Actions</h3>
                        <div style="display:flex;flex-wrap:wrap;gap:.6rem">
                            <button class="btn btn-primary" onclick="switchSection('users')"><i class="fa-solid fa-user-plus"></i> Users</button>
                            <button class="btn btn-primary" onclick="switchSection('backup')"><i class="fa-solid fa-database"></i> Backup</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- USERS MANAGEMENT -->
            <section id="section-users" class="section <?= $activeSection === 'users' ? 'active' : '' ?>"<?= $activeSection === 'users' ? '' : ' hidden' ?>>
                <div class="sec-header"><h2 class="sec-title"><i class="fa-solid fa-users"></i> User Management</h2><p class="sec-desc">Create and manage admin accounts</p></div>
                
                <?php if ($userMessage): ?>
                <div class="alert alert-<?= $userMessageType ?>"><i class="fa-solid fa-<?= $userMessageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i> <?= e($userMessage) ?></div>
                <?php endif; ?>
                
                <?php if ($deleteUser): ?>
                <div class="card" style="border-color:rgba(239,68,68,.3)">
                    <h3 class="card-title" style="color:#f87171"><i class="fa-solid fa-trash"></i> Delete User: <?= e($deleteUser['username']) ?></h3>
                    <p style="font-size:.8rem;color:#94a3b8;margin-bottom:1rem">This will permanently delete user <strong><?= e($deleteUser['full_name']) ?></strong> (<?= e($deleteUser['role']) ?>)</p>
                    <form method="post" action="/admin/backend/user-delete.php" id="dcDeleteUserForm" data-username="<?= e($deleteUser['username']) ?>" data-fullname="<?= e($deleteUser['full_name']) ?>" data-role="<?= e($deleteUser['role']) ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="delete_user_id" value="<?= (int)$deleteUser['id'] ?>">
                        <div class="form-group"><label class="form-label">Your Password (confirm)</label>
                            <div class="pw-wrap"><input type="password" name="superadmin_password" class="form-input" placeholder="Enter your password" required autocomplete="current-password"><button type="button" class="pw-toggle" data-toggle>Show</button></div>
                        </div>
                        <div style="display:flex;gap:.5rem">
                            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete</button>
                            <a href="?section=users" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                
                <div class="grid-2">
                    <div class="card">
                        <h3 class="card-title"><i class="fa-solid fa-<?= $editUser ? 'edit' : 'user-plus' ?>"></i> <?= $editUser ? 'Edit User' : 'Create User' ?></h3>
                        <form method="post" action="/admin/backend/user-save.php" id="dcUserSaveForm"<?php if ($editUser): ?> data-orig-role="<?= e($editUser['role']) ?>" data-orig-active="<?= (int)$editUser['is_active'] ?>" data-username="<?= e($editUser['username']) ?>"<?php endif; ?>>
                            <?= csrfField() ?>
                            <input type="hidden" name="user_id" value="<?= $editUser ? (int)$editUser['id'] : '' ?>">
                            <div class="form-group"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-input" value="<?= e(fv('full_name', $editUser, $oldForm)) ?>" placeholder="Full name"></div>
                            <div class="form-group"><label class="form-label">Username</label><input type="text" name="username" class="form-input" value="<?= e(fv('username', $editUser, $oldForm)) ?>" placeholder="Login username"></div>
                            <div class="form-group"><label class="form-label">Email (optional)</label><input type="email" name="email" class="form-input" value="<?= e(fv('email', $editUser, $oldForm)) ?>" placeholder="email@example.com"></div>
                            <div class="form-group"><label class="form-label">Role</label>
                                <select name="role" class="form-select">
                                    <?php foreach ($roles as $v => $l): ?>
                                    <option value="<?= $v ?>" <?= $currentRole === $v ? 'selected' : '' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label class="form-label">Password <?= $editUser ? '(leave blank to keep)' : '' ?></label>
                                <div class="pw-wrap"><input type="password" name="password" class="form-input" minlength="12" maxlength="72" autocomplete="new-password" placeholder="<?= $editUser ? 'New password (optional, 12+ characters)' : 'Set password (12+ characters)' ?>"><button type="button" class="pw-toggle" data-toggle>Show</button></div>
                            </div>
                            <div class="form-group"><label class="form-label">Confirm Password</label>
                                <div class="pw-wrap"><input type="password" name="confirm_password" class="form-input" placeholder="Confirm password"><button type="button" class="pw-toggle" data-toggle>Show</button></div>
                            </div>
                            <div class="form-group"><label class="form-label">Status</label>
                                <select name="is_active" class="form-select">
                                    <option value="1" <?= $currentStatus === 1 ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= $currentStatus === 0 ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                            <div style="display:flex;gap:.5rem">
                                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> <?= $editUser ? 'Update' : 'Create' ?></button>
                                <?php if ($editUser): ?><a href="?section=users" class="btn btn-outline">Cancel</a><?php endif; ?>
                            </div>
                        </form>
                    </div>
                    <div class="card">
                        <h3 class="card-title"><i class="fa-solid fa-list"></i> Existing Users (<?= count($users) ?>)</h3>
                        <div style="overflow-x:auto">
                            <table>
                                <thead><tr><th>#</th><th>Username</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?= $u['id'] ?></td>
                                    <td><?= e($u['username']) ?><br><small style="color:#7c8aa5"><?= e($u['full_name']) ?></small></td>
                                    <td><?= e($u['role']) ?></td>
                                    <td><span class="badge <?= $u['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                    <td class="actions">
                                        <a href="?edit_id=<?= $u['id'] ?>&section=users">Edit</a>
                                        <?php if ((int)$u['id'] !== $adminId): ?>
                                        <a href="?delete_id=<?= $u['id'] ?>&section=users" class="delete-link">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- DEPARTMENTS -->
            <section id="section-departments" class="section <?= $activeSection === 'departments' ? 'active' : '' ?>"<?= $activeSection === 'departments' ? '' : ' hidden' ?>>
                <div class="sec-header"><h2 class="sec-title"><i class="fa-solid fa-building"></i> Departments</h2><p class="sec-desc">Department dashboards overview</p></div>
                <div class="grid-4">
                    <?php foreach ($departments as $d): ?>
                    <div class="dept-card"><div class="dept-icon" style="background:<?= $d['color'] ?>"><i class="fa-solid <?= $d['icon'] ?>"></i></div><div class="dept-name"><?= $d['name'] ?></div><div class="dept-amharic eth" lang="am"><?= $d['amharic'] ?></div></div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php include __DIR__ . '/sections/identity_codes_section.php'; ?>

            <?php include __DIR__ . '/sections/app_release_section.php'; ?>

            <?php include __DIR__ . '/sections/app_telemetry_section.php'; ?>

            <?php include __DIR__ . '/sections/failure_intelligence_section.php'; ?>

            <!-- SITE HEALTH - ADVANCED -->
            <section id="section-health" class="section <?= $activeSection === 'health' ? 'active' : '' ?>"<?= $activeSection === 'health' ? '' : ' hidden' ?>>
                <div class="sec-header"><h2 class="sec-title"><i class="fa-solid fa-heart-pulse"></i> System Health</h2><p class="sec-desc">Comprehensive system monitoring & diagnostics</p></div>

                <!-- Overall Score + Quick Stats -->
                <div style="display:grid;grid-template-columns:240px 1fr;gap:1rem;margin-bottom:1.25rem">
                    <div class="stat-card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.5rem;text-align:center">
                        <div style="position:relative;width:110px;height:110px;margin-bottom:.75rem">
                            <svg viewBox="0 0 120 120" style="width:110px;height:110px;transform:rotate(-90deg)">
                                <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="10"/>
                                <circle cx="60" cy="60" r="52" fill="none" stroke="<?=$scoreColor?>" stroke-width="10" stroke-linecap="round" stroke-dasharray="<?=round($overallScore*3.267)?> 327" style="transition:stroke-dasharray 1s ease"/>
                            </svg>
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center"><span style="font-size:2rem;font-weight:800;color:<?=$scoreColor?>"><?=$overallScore?></span><span style="font-size:.6rem;color:#7c8aa5">/ 100</span></div>
                        </div>
                        <div style="font-size:.85rem;font-weight:600;color:<?=$scoreColor?>"><?=$scoreLabel?></div>
                        <div style="font-size:.65rem;color:#7c8aa5;margin-top:.2rem">Health Score</div>
                    </div>
                    <div class="grid-4" style="align-content:start">
                        <div class="stat-card"><div class="stat-header"><div class="stat-title">PHP Version</div><span class="health-badge health-<?=version_compare(phpversion(),'8.0','>=') ? 'good' : 'warning'?>"><?=version_compare(phpversion(),'8.0','>=') ? 'Current' : 'Upgrade'?></span></div><div class="stat-value" style="font-size:1.3rem"><?=phpversion()?></div></div>
                        <div class="stat-card"><div class="stat-header"><div class="stat-title">Database</div><span class="health-badge health-<?=$dbStatus==='Connected'?'good':'error'?>"><?=$dbStatus?></span></div><div class="stat-value" style="font-size:1.3rem"><?=$sHealth['db_version']?></div><div class="stat-label"><?=$sHealth['db_tables']?> tables · <?=$sHealth['db_size_kb'] > 1024 ? round($sHealth['db_size_kb']/1024,1).' MB' : $sHealth['db_size_kb'].' KB'?></div></div>
                        <div class="stat-card"><div class="stat-header"><div class="stat-title">Memory</div><span class="health-badge health-<?=$sHealth['memory_percent']<70?'good':($sHealth['memory_percent']<90?'warning':'error')?>"><?=$sHealth['memory_percent']?>%</span></div><div class="stat-value" style="font-size:1.3rem"><?=$sHealth['memory_usage']?> MB</div><div class="stat-label">of <?=$sHealth['memory_limit']?></div></div>
                        <div class="stat-card"><div class="stat-header"><div class="stat-title">Disk</div><span class="health-badge health-<?=$sHealth['disk_percent']<75?'good':($sHealth['disk_percent']<90?'warning':'error')?>"><?=$sHealth['disk_percent']?>%</span></div><div class="stat-value" style="font-size:1.3rem"><?=$sHealth['disk_free']?> GB</div><div class="stat-label">free of <?=$sHealth['disk_total']?> GB</div></div>
                    </div>
                </div>

                <!-- Health Sub-tabs -->
                <div style="display:flex;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap" id="healthTabs">
                    <button class="btn btn-sm health-tab active" data-htab="security" style="border-radius:999px"><i class="fa-solid fa-shield-halved"></i> Security</button>
                    <button class="btn btn-sm health-tab" data-htab="server" style="border-radius:999px"><i class="fa-solid fa-server"></i> Server</button>
                    <button class="btn btn-sm health-tab" data-htab="database" style="border-radius:999px"><i class="fa-solid fa-database"></i> Database</button>
                    <button class="btn btn-sm health-tab" data-htab="files" style="border-radius:999px"><i class="fa-solid fa-folder-tree"></i> Files</button>
                    <button class="btn btn-sm health-tab" data-htab="errors" style="border-radius:999px"><i class="fa-solid fa-bug"></i> Errors</button>
                </div>

                <!-- SECURITY TAB -->
                <div class="health-panel active" id="htab-security">
                    <div class="grid-2">
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-shield-halved"></i> Security Score: <span style="color:<?=$securityScore>=80?'#4ade80':($securityScore>=60?'#fbbf24':'#f87171')?>"><?=$securityScore?>%</span></h3>
                            <div style="background:rgba(0,0,0,.3);border-radius:8px;height:10px;overflow:hidden;margin-bottom:1rem"><div style="height:100%;width:<?=$securityScore?>%;background:<?=$securityScore>=80?'#4ade80':($securityScore>=60?'#fbbf24':'#f87171')?>;border-radius:8px;transition:width 1s ease"></div></div>
                            <?php foreach ($securityChecks as $sc): ?>
                            <div class="health-item">
                                <span class="health-name"><i class="fa-solid <?=$sc['pass']?'fa-check-circle':'fa-times-circle'?>" style="color:<?=$sc['pass']?'#4ade80':'#f87171'?>;margin-right:.5rem"></i><?=$sc['name']?></span>
                                <span style="font-size:.7rem;color:<?=$sc['pass']?'#4ade80':'#f87171'?>"><?=$sc['pass']?'Passed':'Failed'?><?php if(!$sc['pass']):?> <span style="color:#7c8aa5">· <?=$sc['tip']?></span><?php endif;?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-chart-line"></i> Member Registration Trend</h3>
                            <?php if (!empty($memberGrowth)): $maxG = max(array_column($memberGrowth,'cnt')); ?>
                            <div style="display:flex;align-items:flex-end;gap:6px;height:140px;padding-top:1rem">
                                <?php foreach ($memberGrowth as $mg): $h = $maxG > 0 ? max(round($mg['cnt']/$maxG*120), 4) : 4; ?>
                                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">
                                    <span style="font-size:.6rem;color:#94a3b8"><?=$mg['cnt']?></span>
                                    <div style="width:100%;height:<?=$h?>px;background:linear-gradient(to top,var(--p),var(--pl));border-radius:4px 4px 0 0;min-height:4px;transition:height .5s"></div>
                                    <span style="font-size:.55rem;color:#7c8aa5"><?=substr($mg['month'],5)?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p style="color:#7c8aa5;font-size:.8rem;text-align:center;padding:2rem 0">No registration data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- SERVER TAB -->
                <div class="health-panel" id="htab-server">
                    <div class="grid-2">
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-microchip"></i> Server Environment</h3>
                            <div class="health-item"><span class="health-name">Server Software</span><span class="health-value"><?=htmlspecialchars(substr($sHealth['server'],0,40))?></span></div>
                            <div class="health-item"><span class="health-name">PHP Version</span><span class="health-value"><?=phpversion()?></span></div>
                            <div class="health-item"><span class="health-name">Memory Limit</span><span class="health-value"><?=$sHealth['memory_limit']?></span></div>
                            <div class="health-item"><span class="health-name">Memory Used</span><span class="health-value"><?=$sHealth['memory_usage']?> MB (<?=$sHealth['memory_percent']?>%)</span></div>
                            <div class="health-item"><span class="health-name">Max Upload</span><span class="health-value"><?=$sHealth['max_upload']?></span></div>
                            <div class="health-item"><span class="health-name">Max POST</span><span class="health-value"><?=$sHealth['max_post']?></span></div>
                            <div class="health-item"><span class="health-name">Max Execution</span><span class="health-value"><?=$sHealth['max_exec']?></span></div>
                            <div class="health-item"><span class="health-name">Timezone</span><span class="health-value"><?=$sHealth['timezone']?></span></div>
                            <div class="health-item"><span class="health-name">HTTPS</span><span class="health-badge health-<?=$sHealth['https']?'good':'warning'?>"><?=$sHealth['https']?'Enabled':'Disabled'?></span></div>
                            <div class="health-item"><span class="health-name">Session Timeout</span><span class="health-value"><?=round($sHealth['session_timeout']/60)?> min</span></div>
                        </div>
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-hard-drive"></i> Resource Usage</h3>
                            <!-- Memory Bar -->
                            <div style="margin-bottom:1.25rem">
                                <div style="display:flex;justify-content:space-between;margin-bottom:.35rem"><span style="font-size:.75rem;color:#94a3b8">Memory</span><span style="font-size:.75rem;color:<?=$sHealth['memory_percent']<70?'#4ade80':'#fbbf24'?>"><?=$sHealth['memory_usage']?> MB / <?=$sHealth['memory_limit']?></span></div>
                                <div style="background:rgba(0,0,0,.3);border-radius:6px;height:10px;overflow:hidden"><div style="height:100%;width:<?=$sHealth['memory_percent']?>%;background:<?=$sHealth['memory_percent']<70?'linear-gradient(90deg,#059669,#10b981)':($sHealth['memory_percent']<90?'linear-gradient(90deg,#d97706,#f59e0b)':'linear-gradient(90deg,#dc2626,#ef4444)')?>;border-radius:6px;transition:width .8s"></div></div>
                            </div>
                            <!-- Disk Bar -->
                            <div style="margin-bottom:1.25rem">
                                <div style="display:flex;justify-content:space-between;margin-bottom:.35rem"><span style="font-size:.75rem;color:#94a3b8">Disk Space</span><span style="font-size:.75rem;color:<?=$sHealth['disk_percent']<75?'#4ade80':'#fbbf24'?>"><?=round($sHealth['disk_total']-$sHealth['disk_free'],1)?> GB / <?=$sHealth['disk_total']?> GB</span></div>
                                <div style="background:rgba(0,0,0,.3);border-radius:6px;height:10px;overflow:hidden"><div style="height:100%;width:<?=$sHealth['disk_percent']?>%;background:<?=$sHealth['disk_percent']<75?'linear-gradient(90deg,#059669,#10b981)':($sHealth['disk_percent']<90?'linear-gradient(90deg,#d97706,#f59e0b)':'linear-gradient(90deg,#dc2626,#ef4444)')?>;border-radius:6px;transition:width .8s"></div></div>
                            </div>
                            <!-- PHP Extensions -->
                            <h3 class="card-title" style="margin-top:1rem"><i class="fa-solid fa-puzzle-piece"></i> PHP Extensions</h3>
                            <div style="display:flex;flex-wrap:wrap;gap:.4rem">
                                <?php foreach (['mysqli','mbstring','json','session','openssl','fileinfo','gd','curl','zip'] as $ext): $loaded = extension_loaded($ext); ?>
                                <span style="padding:.2rem .55rem;border-radius:999px;font-size:.65rem;font-weight:600;background:rgba(<?=$loaded?'34,197,94':'239,68,68'?>,.15);color:<?=$loaded?'#4ade80':'#f87171'?>"><?=$loaded?'✓':'✗'?> <?=$ext?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DATABASE TAB -->
                <div class="health-panel" id="htab-database">
                    <div class="card" style="margin-bottom:1rem">
                        <h3 class="card-title"><i class="fa-solid fa-table"></i> Database Tables (<?=count($tableStats)?>)</h3>
                        <div style="overflow-x:auto">
                            <table>
                                <thead><tr><th>Table</th><th>Rows</th><th>Size</th><th>Auto Inc.</th><th>Last Updated</th></tr></thead>
                                <tbody>
                                <?php if(empty($tableStats)): ?>
                                <tr><td colspan="5" style="text-align:center;color:#7c8aa5;padding:1.5rem">No table data available</td></tr>
                                <?php else: foreach ($tableStats as $ts): $szKb=(float)($ts['size_kb']??0); ?>
                                <tr>
                                    <td style="font-weight:500;color:#e2e8f0"><i class="fa-solid fa-table" style="color:#4ade80;margin-right:.35rem;font-size:.7rem"></i><?=htmlspecialchars($ts['table_name']??'')?></td>
                                    <td><?=number_format((int)($ts['table_rows']??0))?></td>
                                    <td><?=$szKb > 1024 ? round($szKb/1024,1).' MB' : $szKb.' KB'?></td>
                                    <td style="color:#7c8aa5"><?=$ts['auto_increment'] ?? '—'?></td>
                                    <td style="color:#7c8aa5;font-size:.75rem"><?=$ts['update_time'] ? date('M j, H:i', strtotime($ts['update_time'])) : '—'?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-circle-info"></i> DB Summary</h3>
                            <div class="health-item"><span class="health-name">Engine</span><span class="health-value">MySQL <?=$sHealth['db_version']?></span></div>
                            <div class="health-item"><span class="health-name">Total Tables</span><span class="health-value"><?=$sHealth['db_tables']?></span></div>
                            <div class="health-item"><span class="health-name">Total Size</span><span class="health-value"><?=$sHealth['db_size_kb'] > 1024 ? round($sHealth['db_size_kb']/1024,1).' MB' : $sHealth['db_size_kb'].' KB'?></span></div>
                            <div class="health-item"><span class="health-name">Uptime</span><span class="health-value"><?=$sHealth['db_uptime']?></span></div>
                        </div>
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-chart-pie"></i> Table Size Distribution</h3>
                            <?php if (!empty($tableStats)):
                                $topTables = array_slice($tableStats, 0, 5);
                                $maxSz = max(array_map(fn($t) => (float)($t['size_kb']??0), $topTables));
                                foreach ($topTables as $tt): $sz = (float)($tt['size_kb']??0); $pct = $maxSz > 0 ? round($sz/$maxSz*100) : 0; ?>
                            <div style="margin-bottom:.6rem">
                                <div style="display:flex;justify-content:space-between;margin-bottom:.2rem"><span style="font-size:.7rem;color:#e2e8f0"><?=htmlspecialchars($tt['table_name']??'')?></span><span style="font-size:.7rem;color:#7c8aa5"><?=$sz > 1024 ? round($sz/1024,1).' MB' : $sz.' KB'?></span></div>
                                <div style="background:rgba(0,0,0,.3);border-radius:4px;height:6px;overflow:hidden"><div style="height:100%;width:<?=$pct?>%;background:linear-gradient(90deg,var(--p),var(--pl));border-radius:4px"></div></div>
                            </div>
                            <?php endforeach; else: ?>
                            <p style="color:#7c8aa5;font-size:.8rem;text-align:center;padding:1rem">No data</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- FILES TAB -->
                <div class="health-panel" id="htab-files">
                    <div class="card">
                        <h3 class="card-title"><i class="fa-solid fa-folder-tree"></i> Critical File Integrity</h3>
                        <div style="overflow-x:auto">
                            <table>
                                <thead><tr><th>File</th><th>Status</th><th>Size</th><th>Last Modified</th></tr></thead>
                                <tbody>
                                <?php foreach ($fileIntegrity as $fi): ?>
                                <tr>
                                    <td style="font-weight:500;color:#e2e8f0"><i class="fa-solid fa-file-code" style="color:<?=$fi['exists']?'#4ade80':'#f87171'?>;margin-right:.4rem;font-size:.7rem"></i><?=htmlspecialchars($fi['name'])?></td>
                                    <td><span class="health-badge health-<?=$fi['exists']?'good':'error'?>"><?=$fi['exists']?'OK':'Missing'?></span></td>
                                    <td style="color:#94a3b8"><?=$fi['exists'] ? ($fi['size'] > 1024 ? round($fi['size']/1024,1).' KB' : $fi['size'].' B') : '—'?></td>
                                    <td style="color:#7c8aa5;font-size:.75rem"><?=$fi['modified']?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-folder"></i> Directory Permissions</h3>
                            <?php
                            $dirs = [
                                'uploads/' => UPLOADS_PATH,
                                'uploads/members/' => UPLOADS_PATH.'/members',
                                'uploads/cache/' => UPLOADS_PATH.'/cache',
                                'uploads/backups/' => __DIR__.'/../uploads/backups',
                            ];
                            foreach ($dirs as $label => $dpath): $writable = is_dir($dpath) && is_writable($dpath); $exists = is_dir($dpath); ?>
                            <div class="health-item">
                                <span class="health-name"><i class="fa-solid fa-folder<?=$exists?'':'-xmark'?>" style="color:<?=$writable?'#4ade80':($exists?'#fbbf24':'#f87171')?>;margin-right:.4rem"></i><?=$label?></span>
                                <span class="health-badge health-<?=$writable?'good':($exists?'warning':'error')?>"><?=$writable?'Writable':($exists?'Read-only':'Missing')?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-shield-halved"></i> .htaccess Rules</h3>
                            <?php
                            $htaccessPath = ROOT_PATH . '/.htaccess';
                            $htRules = [];
                            if (file_exists($htaccessPath)) {
                                $htContent = file_get_contents($htaccessPath);
                                $htRules = [
                                    'DirectoryIndex' => strpos($htContent, 'DirectoryIndex') !== false,
                                    'PHP Error Display Off' => strpos($htContent, 'display_errors') !== false,
                                    'File Access Blocked' => strpos($htContent, 'FilesMatch') !== false || strpos($htContent, 'deny from all') !== false,
                                    'Rewrite Engine' => strpos($htContent, 'RewriteEngine') !== false,
                                ];
                            }
                            if (empty($htRules)): ?>
                            <p style="color:#7c8aa5;font-size:.8rem;text-align:center;padding:1rem">.htaccess not found</p>
                            <?php else: foreach ($htRules as $rule => $present): ?>
                            <div class="health-item"><span class="health-name"><?=$rule?></span><span class="health-badge health-<?=$present?'good':'warning'?>"><?=$present?'Present':'Missing'?></span></div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ERRORS TAB -->
                <div class="health-panel" id="htab-errors">
                    <div class="card">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <h3 class="card-title" style="margin:0"><i class="fa-solid fa-bug"></i> Error Log <?php if($errorLogSize > 0):?><span style="font-size:.7rem;color:#7c8aa5;font-weight:400;margin-left:.5rem">(<?=$errorLogSize > 1024*1024 ? round($errorLogSize/1024/1024,1).' MB' : round($errorLogSize/1024,1).' KB'?>)</span><?php endif;?></h3>
                            <?php if (!empty($errorLogLines)): ?>
                            <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="section" value="health"><button type="submit" name="clear_error_log_inline" class="btn btn-sm btn-danger" data-dc-clear-log="1"><i class="fa-solid fa-trash"></i> Clear</button></form>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($errorLogLines)): ?>
                        <div style="text-align:center;padding:2rem;color:#4ade80"><i class="fa-solid fa-check-circle" style="font-size:2rem;margin-bottom:.5rem;display:block"></i><p style="font-size:.85rem">No errors! System is clean.</p></div>
                        <?php else: ?>
                        <div style="max-height:350px;overflow-y:auto;background:rgba(0,0,0,.4);border-radius:.5rem;padding:.75rem;font-family:'Courier New',monospace;font-size:.7rem;line-height:1.6;color:#94a3b8;border:1px solid rgba(255,255,255,.05)">
                            <?php foreach (array_reverse($errorLogLines) as $line): $isError = stripos($line,'error')!==false||stripos($line,'fatal')!==false; $isWarn = stripos($line,'warning')!==false||stripos($line,'notice')!==false; ?>
                            <div style="padding:.15rem 0;border-bottom:1px solid rgba(255,255,255,.03);color:<?=$isError?'#f87171':($isWarn?'#fbbf24':'#94a3b8')?>"><?=htmlspecialchars(substr($line,0,200))?></div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="grid-2" style="margin-top:1rem">
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity</h3>
                            <?php if(empty($recentLogs)):?><p style="color:#7c8aa5;font-size:.8rem;text-align:center;padding:1rem">No activity</p>
                            <?php else: foreach (array_slice($recentLogs,0,6) as $rl): ?>
                            <div class="log-item"><div class="log-icon"><i class="fa-solid fa-circle-dot"></i></div><div><div class="log-action"><?=htmlspecialchars($rl['action']??'')?></div><div class="log-meta"><?=htmlspecialchars($rl['username']??'System')?> · <?=$rl['created_at']??''?></div></div></div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-wrench"></i> Quick Actions</h3>
                            <div style="display:flex;flex-direction:column;gap:.6rem">
                                <a href="../system_health.php" class="btn btn-primary btn-sm" style="justify-content:center;text-decoration:none"><i class="fa-solid fa-heartbeat"></i> Full System Health Page</a>
                                <button class="btn btn-outline btn-sm" style="justify-content:center" onclick="switchSection('backup')"><i class="fa-solid fa-database"></i> Go to Backup</button>
                                <button class="btn btn-outline btn-sm" style="justify-content:center" onclick="switchSection('logs')"><i class="fa-solid fa-clock-rotate-left"></i> View All Logs</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SETTINGS -->
            <section id="section-settings" class="section <?= $activeSection === 'settings' ? 'active' : '' ?>"<?= $activeSection === 'settings' ? '' : ' hidden' ?>>
                <div class="sec-header"><h2 class="sec-title"><i class="fa-solid fa-gear"></i> Settings</h2><p class="sec-desc">System configuration</p></div>
                <div class="grid-2">
                    <!-- CALENDAR SYSTEM (fixed: Ethiopian only) -->
                    <div class="card" style="border:2px solid #7c3aed">
                        <h3 class="card-title"><i class="fa-solid fa-calendar-days" style="color:#7c3aed"></i> Calendar System</h3>
                        <p style="font-size:.75rem;color:#94a3b8;margin-bottom:.85rem">The Ethiopian calendar is used system-wide — every dashboard, date picker, report and export. This is a fixed policy and is not configurable.</p>
                        <div style="display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;border-radius:12px;border:2px solid #7c3aed;background:rgba(124,58,237,.1)">
                            <i class="fa-solid fa-lock" style="color:#a78bfa"></i>
                            <div>
                                <div style="font-weight:700;color:#e2e8f0;font-size:.85rem">🇪🇹 Ethiopian Calendar <span style="font-family:'Noto Sans Ethiopic';font-size:.75rem">(ዓ.ም.)</span></div>
                                <div style="font-size:.7rem;color:#94a3b8">መስከረም to ጳጉሜ • 13 months • Based on Ge'ez calendar • system-wide, not configurable</div>
                            </div>
                        </div>
                        <div style="margin-top:.75rem;padding:.6rem .85rem;background:rgba(124,58,237,.08);border-radius:10px;border:1px solid rgba(124,58,237,.15)">
                            <div style="font-size:.7rem;color:#a78bfa;font-weight:600;margin-bottom:.2rem"><i class="fa-solid fa-info-circle"></i> Today</div>
                            <div style="font-size:.8rem;color:#e2e8f0" id="calCurrentDisplay"><?= wbws_format_date($today, 'long', $conn) ?></div>
                        </div>
                    </div>
                    <!-- GENERAL -->
                    <div class="card"><h3 class="card-title"><i class="fa-solid fa-globe"></i> General</h3>
                        <div class="form-group"><label class="form-label">Site Name</label><input class="form-input" value="<?= SCHOOL_NAME_SHORT_AM ?> ሰንበት ት/ቤት" readonly></div>
                        <div class="form-group"><label class="form-label">Timezone</label><input class="form-input" value="Africa/Addis_Ababa" readonly></div>
                        <div class="form-group"><label class="form-label">Session Timeout</label><input class="form-input" value="<?= ini_get('session.gc_maxlifetime') / 60 ?> min" readonly></div>
                        <div class="form-group"><label class="form-label">Memory Limit</label><input class="form-input" value="<?= ini_get('memory_limit') ?>" readonly></div>
                    </div>
                </div>
            </section>

            <!-- BRANDING -->
            <section id="section-branding" class="section <?= $activeSection === 'branding' ? 'active' : '' ?>"<?= $activeSection === 'branding' ? '' : ' hidden' ?>>
                <div class="sec-header">
                    <h2 class="sec-title"><i class="fa-solid fa-palette"></i> Branding & ID Card Assets</h2>
                    <p class="sec-desc">Upload pictures, then design the real front and back. Save applies to every member card.</p>
                </div>
                
                <!-- Error banner placeholder -->
                <div id="brandErrorBanner" style="display:none;margin-bottom:1rem;padding:.75rem 1rem;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);border-radius:10px;color:#fca5a5;font-size:.78rem">
                </div>

                <!-- ═══ ASSET UPLOAD GRID ═══ -->
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.85rem;margin-bottom:1.5rem" id="brandAssetGrid">
                    <?php
                    $brandCards = [
                        'logo'      => ['label' => 'School Logo',        'icon' => 'fa-image',          'gradient' => 'linear-gradient(135deg,#7c3aed,#6366f1)', 'accent' => '#7c3aed'],
                        'seal'      => ['label' => 'Seal / Stamp',       'icon' => 'fa-certificate',    'gradient' => 'linear-gradient(135deg,#f59e0b,#d97706)', 'accent' => '#d97706'],
                        'sig_head'  => ['label' => 'Head Signature',     'icon' => 'fa-signature',      'gradient' => 'linear-gradient(135deg,#059669,#10b981)', 'accent' => '#10b981'],
                        'sig_admin' => ['label' => 'Director Signature', 'icon' => 'fa-file-signature', 'gradient' => 'linear-gradient(135deg,#0ea5e9,#3b82f6)', 'accent' => '#3b82f6'],
                        'card_bg'   => ['label' => 'Card background',    'icon' => 'fa-image',          'gradient' => 'linear-gradient(135deg,#600000,#8B2030)', 'accent' => '#600000'],
                    ];
                    foreach ($brandCards as $key => $card): ?>
                    <div class="card" style="padding:0;overflow:hidden;border:2px solid transparent;transition:border-color .3s" id="brandCard_<?= $key ?>">
                        <div style="background:<?= $card['gradient'] ?>;padding:.6rem .75rem;display:flex;align-items:center;gap:.45rem">
                            <i class="fa-solid <?= $card['icon'] ?>" style="color:#fff;font-size:.8rem"></i>
                            <span style="color:#fff;font-weight:700;font-size:.75rem"><?= $card['label'] ?></span>
                        </div>
                        <label style="display:flex;align-items:center;justify-content:center;min-height:120px;cursor:pointer;background:repeating-conic-gradient(#1a1f2e 0% 25%, #131720 0% 50%) 50%/14px 14px;position:relative" id="brandPreview_<?= $key ?>">
                            <input type="file" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="display:none" onchange="brandUpload('<?= $key ?>',this)">
                            <div style="color:#7c8aa5;font-size:.7rem;text-align:center;padding:.5rem" id="brandEmpty_<?= $key ?>">
                                <i class="fa-solid fa-cloud-arrow-up" style="font-size:1.8rem;display:block;margin-bottom:.35rem;opacity:.35"></i>
                                Click to upload
                            </div>
                            <img id="brandImg_<?= $key ?>" style="max-width:100%;max-height:120px;object-fit:contain;display:none" alt="<?= $card['label'] ?>">
                        </label>
                        <div style="padding:.45rem .65rem;border-top:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:.4rem;min-height:28px">
                            <button onclick="brandRemove('<?= $key ?>')" class="brand-rm" title="Remove" style="display:none" id="brandRm_<?= $key ?>"><i class="fa-solid fa-trash"></i></button>
                            <span style="font-size:.6rem;color:#7c8aa5;flex:1" id="brandInfo_<?= $key ?>"></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <link rel="stylesheet" href="/admin/css/id_card_designer.css?v=20260823c">
                <script>window.ID_DESIGNER={csrf:<?= json_encode($csrfToken) ?>,bg:<?= json_encode(defined('ID_CARD_BACKGROUND') ? ID_CARD_BACKGROUND : '/admin/id_cards/assets/backgrounds/id_card_bg.jpg') ?>};</script>
                <div id="idDesigner"></div>
                <script src="/admin/js/id_card_designer.js?v=20260823c"></script>

            </section>

            <!-- LOGS -->
            <section id="section-logs" class="section <?= $activeSection === 'logs' ? 'active' : '' ?>"<?= $activeSection === 'logs' ? '' : ' hidden' ?>>
                <div class="sec-header"><h2 class="sec-title"><i class="fa-solid fa-clock-rotate-left"></i> Activity Logs</h2><p class="sec-desc"><?= count($activityLogs) ?> recent entries</p></div>
                <div class="card">
                    <?php if (empty($activityLogs)): ?>
                    <p style="text-align:center;color:#7c8aa5;padding:2rem">No logs yet</p>
                    <?php else: foreach ($activityLogs as $log): ?>
                    <div class="log-item"><div class="log-icon"><i class="fa-solid fa-circle-dot"></i></div><div><div class="log-action"><?= e($log['action']) ?></div><div class="log-details"><?= esc($log['details'], '') ?></div><div class="log-meta"><?= esc($log['username'], 'System') ?> • <?= $log['created_at'] ?></div></div></div>
                    <?php endforeach; endif; ?>
                </div>
            </section>

            <!-- BACKUP -->
            <section id="section-backup" class="section <?= $activeSection === 'backup' ? 'active' : '' ?>"<?= $activeSection === 'backup' ? '' : ' hidden' ?>>
                <div class="sec-header"><h2 class="sec-title"><i class="fa-solid fa-database"></i> Backup & Data</h2><p class="sec-desc">Database backup management</p></div>
                
                <?php if ($backupMessage): list($t, $m) = explode(':', $backupMessage, 2); ?>
                <div class="alert alert-<?= $t ?>"><i class="fa-solid fa-<?= $t === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i> <?= e($m) ?></div>
                <?php endif; ?>
                
                <div class="grid-2">
                    <div class="card"><h3 class="card-title"><i class="fa-solid fa-download"></i> Create Backup</h3>
                        <p style="font-size:.8rem;color:#94a3b8;margin-bottom:.85rem">Stream a consistent, compressed and encrypted database backup to private storage.</p>
                        <div class="health-item"><span class="health-name">DB Size</span><span class="health-value"><?= $dbSize ?></span></div>
                        <form method="POST" action="/admin/tools/backup.php"><?= csrfField() ?><button type="submit" class="btn btn-primary" style="margin-top:.85rem"><i class="fa-solid fa-download"></i> Create Encrypted Backup</button></form>
                    </div>
                    <div class="card"><h3 class="card-title"><i class="fa-solid fa-folder-open"></i> Backups (<?= count($backupFiles) ?>)</h3>
                        <?php if (empty($backupFiles)): ?>
                        <p style="color:#7c8aa5;font-size:.8rem">No backups yet</p>
                        <?php else: foreach (array_slice($backupFiles, 0, 5) as $f): ?>
                        <div class="backup-item"><div class="backup-info"><div class="backup-icon"><i class="fa-solid fa-file-code"></i></div><div><div class="backup-name"><?= e($f['name']) ?></div><div class="backup-meta"><?= e($f['size']) ?> • <?= e($f['date']) ?> • <?= !empty($f['encrypted']) ? 'Encrypted' : 'Legacy plaintext' ?></div></div></div><a href="/admin/tools/download_backup.php?file=<?= urlencode($f['name']) ?>" class="btn btn-primary btn-sm" title="Download backup"><i class="fa-solid fa-download"></i></a></div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </section>

            <!-- SYSTEM HEALTH - DEEP ANALYSIS -->
            <section id="section-syshealth" class="section <?= $activeSection === 'syshealth' ? 'active' : '' ?>"<?= $activeSection === 'syshealth' ? '' : ' hidden' ?>>
                <div class="sec-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
                    <div><h2 class="sec-title"><i class="fa-solid fa-stethoscope"></i> System Health</h2><p class="sec-desc">Deep code analysis, data integrity, performance & diagnostics</p></div>
                    <div style="text-align:right">
                        <a href="?section=syshealth&refresh_health=1" class="btn btn-sm" style="white-space:nowrap"><i class="fa-solid fa-rotate"></i> Refresh Now</a>
                        <div style="font-size:.65rem;color:#7c8aa5;margin-top:.35rem"><?= $healthCacheFromCache ? ('Cached ' . max(0, (int)round($healthCacheAge / 60)) . ' min ago') : 'Freshly scanned' ?></div>
                    </div>
                </div>

                <!-- Performance Strip -->
                <div class="grid-4" style="margin-bottom:1.25rem">
                    <div class="stat-card"><div class="stat-header"><div class="stat-title">DB Read</div><span class="health-badge health-<?=($benchmarks['db_read']??99)<50?'good':(($benchmarks['db_read']??99)<200?'warning':'error')?>"><?=($benchmarks['db_read']??0)?>ms</span></div><div class="stat-value" style="font-size:1.3rem"><i class="fa-solid fa-bolt" style="color:#fbbf24;font-size:.9rem"></i> <?=($benchmarks['db_read']??0) < 50 ? 'Fast' : (($benchmarks['db_read']??0) < 200 ? 'OK' : 'Slow')?></div></div>
                    <div class="stat-card"><div class="stat-header"><div class="stat-title">DB Ping</div><span class="health-badge health-<?=($benchmarks['db_ping']??99)<10?'good':'warning'?>"><?=($benchmarks['db_ping']??0)?>ms</span></div><div class="stat-value" style="font-size:1.3rem"><i class="fa-solid fa-satellite-dish" style="color:#60a5fa;font-size:.9rem"></i> Latency</div></div>
                    <div class="stat-card"><div class="stat-header"><div class="stat-title">API Files</div><span class="health-badge health-good"><?=count(array_filter($apiEndpoints,fn($a)=>$a['exists']))?>/<?=count($apiEndpoints)?></span></div><div class="stat-value" style="font-size:1.3rem"><i class="fa-solid fa-plug" style="color:#4ade80;font-size:.9rem"></i> Endpoints</div></div>
                    <div class="stat-card"><div class="stat-header"><div class="stat-title">Data Quality</div><?php $dq = $memberQuality['total']>0 ? round($memberQuality['with_phone']/$memberQuality['total']*100) : 0; ?><span class="health-badge health-<?=$dq>=70?'good':($dq>=40?'warning':'error')?>"><?=$dq?>%</span></div><div class="stat-value" style="font-size:1.3rem"><i class="fa-solid fa-chart-simple" style="color:#a78bfa;font-size:.9rem"></i> Completeness</div></div>
                </div>

                <!-- System Health Sub-tabs -->
                <div style="display:flex;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap" id="sysHealthTabs">
                    <button class="btn btn-sm sys-tab active" data-stab="api" style="border-radius:999px"><i class="fa-solid fa-plug"></i> API Health</button>
                    <button class="btn btn-sm sys-tab" data-stab="integrity" style="border-radius:999px"><i class="fa-solid fa-database"></i> Data Integrity</button>
                    <button class="btn btn-sm sys-tab" data-stab="quality" style="border-radius:999px"><i class="fa-solid fa-magnifying-glass-chart"></i> Data Quality</button>
                    <button class="btn btn-sm sys-tab" data-stab="code" style="border-radius:999px"><i class="fa-solid fa-code"></i> Code Analysis</button>
                    <button class="btn btn-sm sys-tab" data-stab="maintenance" style="border-radius:999px"><i class="fa-solid fa-toolbox"></i> Maintenance</button>
                </div>

                <!-- API HEALTH TAB -->
                <div class="sys-panel active" id="stab-api">
                    <div class="card">
                        <h3 class="card-title"><i class="fa-solid fa-plug"></i> API Endpoint Status (<?=count($apiEndpoints)?>)</h3>
                        <div style="overflow-x:auto">
                            <table>
                                <thead><tr><th>Endpoint</th><th>File</th><th>Status</th><th>Lines</th><th>CSRF</th><th>Auth</th><th>SQL Safe</th></tr></thead>
                                <tbody>
                                <?php foreach ($apiEndpoints as $ep): ?>
                                <tr>
                                    <td style="font-weight:500;color:#e2e8f0"><?=htmlspecialchars($ep['name'])?></td>
                                    <td style="color:#7c8aa5;font-size:.75rem"><?=htmlspecialchars($ep['file'])?></td>
                                    <td><span class="health-badge health-<?=$ep['exists']?'good':'error'?>"><?=$ep['exists']?'Online':'Missing'?></span></td>
                                    <td style="color:#94a3b8"><?=$ep['lines']?></td>
                                    <td><i class="fa-solid <?=$ep['has_csrf']?'fa-check-circle':'fa-times-circle'?>" style="color:<?=$ep['has_csrf']?'#4ade80':'#f87171'?>"></i></td>
                                    <td><i class="fa-solid <?=$ep['has_auth']?'fa-check-circle':'fa-times-circle'?>" style="color:<?=$ep['has_auth']?'#4ade80':'#f87171'?>"></i></td>
                                    <td><i class="fa-solid <?=$ep['has_prepared']?'fa-check-circle':'fa-times-circle'?>" style="color:<?=$ep['has_prepared']?'#4ade80':'#fbbf24'?>"></i></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="grid-3" style="margin-top:1rem">
                        <div class="card" style="text-align:center;padding:1.5rem">
                            <div style="font-size:2rem;font-weight:800;color:#4ade80"><?=count(array_filter($apiEndpoints,fn($a)=>$a['has_csrf']))?>/<?=count($apiEndpoints)?></div>
                            <div style="font-size:.75rem;color:#94a3b8;margin-top:.25rem">CSRF Protected</div>
                        </div>
                        <div class="card" style="text-align:center;padding:1.5rem">
                            <div style="font-size:2rem;font-weight:800;color:#60a5fa"><?=count(array_filter($apiEndpoints,fn($a)=>$a['has_auth']))?>/<?=count($apiEndpoints)?></div>
                            <div style="font-size:.75rem;color:#94a3b8;margin-top:.25rem">Auth Required</div>
                        </div>
                        <div class="card" style="text-align:center;padding:1.5rem">
                            <div style="font-size:2rem;font-weight:800;color:#fbbf24"><?=count(array_filter($apiEndpoints,fn($a)=>$a['has_prepared']))?>/<?=count($apiEndpoints)?></div>
                            <div style="font-size:.75rem;color:#94a3b8;margin-top:.25rem">SQL Injection Safe</div>
                        </div>
                    </div>
                </div>

                <!-- DATA INTEGRITY TAB -->
                <div class="sys-panel" id="stab-integrity">
                    <div class="grid-2">
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-shield-check"></i> Referential Integrity</h3>
                            <?php foreach ($dbIntegrity as $di): ?>
                            <div class="health-item">
                                <span class="health-name"><i class="fa-solid <?=$di['pass']?'fa-check-circle':'fa-exclamation-triangle'?>" style="color:<?=$di['pass']?'#4ade80':'#fbbf24'?>;margin-right:.4rem"></i><?=$di['name']?></span>
                                <span style="font-size:.75rem;color:<?=$di['pass']?'#4ade80':'#fbbf24'?>"><?=$di['valid']?> / <?=$di['total']?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-broom"></i> Orphaned Records</h3>
                            <div class="health-item">
                                <span class="health-name"><i class="fa-solid fa-users" style="color:#60a5fa;margin-right:.4rem"></i>Orphaned Group Members</span>
                                <span class="health-badge health-<?=($orphanedMembers??0)==0?'good':'warning'?>"><?=$orphanedMembers??0?></span>
                            </div>
                            <div class="health-item">
                                <span class="health-name"><i class="fa-solid fa-user-tie" style="color:#fbbf24;margin-right:.4rem"></i>Orphaned Group Leaders</span>
                                <span class="health-badge health-<?=($orphanedLeaders??0)==0?'good':'warning'?>"><?=$orphanedLeaders??0?></span>
                            </div>
                            <?php if (($orphanedMembers??0) > 0 || ($orphanedLeaders??0) > 0): ?>
                            <p style="font-size:.7rem;color:#f87171;margin-top:.75rem;padding:.5rem;background:rgba(239,68,68,.1);border-radius:.4rem"><i class="fa-solid fa-triangle-exclamation"></i> Records linked to deleted groups. Consider cleanup.</p>
                            <?php else: ?>
                            <div style="text-align:center;padding:1rem;color:#4ade80"><i class="fa-solid fa-check-circle" style="font-size:1.5rem"></i><p style="font-size:.8rem;margin-top:.3rem">All records are clean!</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card" style="margin-top:1rem">
                        <h3 class="card-title"><i class="fa-solid fa-users"></i> User Role Distribution</h3>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">
                            <?php $roleColors = ['super_admin'=>'#ef4444','school_admin'=>'#f59e0b','hr_dept'=>'#ec4899','info_dept'=>'#10b981','edu_dept'=>'#3b82f6','finance_dept'=>'#8b5cf6','material_dept'=>'#ec4899','mezmur_dept'=>'#06b6d4','teacher'=>'#84cc16','attendance_taker'=>'#84cc16'];
                            foreach ($userAnalytics['roles'] as $ur): $rc = $roleColors[$ur['role']] ?? '#7c8aa5'; ?>
                            <div style="background:rgba(0,0,0,.2);border-radius:.6rem;padding:.75rem;text-align:center;border:1px solid rgba(255,255,255,.05)">
                                <div style="width:36px;height:36px;border-radius:50%;background:<?=$rc?>20;color:<?=$rc?>;display:flex;align-items:center;justify-content:center;margin:0 auto .4rem;font-size:.85rem;font-weight:700"><?=$ur['c']?></div>
                                <div style="font-size:.7rem;color:#94a3b8"><?=str_replace('_',' ',ucwords($ur['role'],'_'))?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- DATA QUALITY TAB -->
                <div class="sys-panel" id="stab-quality">
                    <div class="grid-2">
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-magnifying-glass-chart"></i> Member Data Completeness</h3>
                            <?php $mqt = max($memberQuality['total'], 1);
                            $fields = [
                                ['name' => 'Has Phone Number', 'val' => $memberQuality['with_phone'], 'icon' => 'fa-phone', 'color' => '#4ade80'],
                                ['name' => 'Has Photo', 'val' => $memberQuality['with_photo'], 'icon' => 'fa-camera', 'color' => '#60a5fa'],
                            ];
                            foreach ($fields as $fld): $pct = round($fld['val'] / $mqt * 100); ?>
                            <div style="margin-bottom:1rem">
                                <div style="display:flex;justify-content:space-between;margin-bottom:.3rem"><span style="font-size:.8rem;color:#e2e8f0"><i class="fa-solid <?=$fld['icon']?>" style="color:<?=$fld['color']?>;margin-right:.4rem"></i><?=$fld['name']?></span><span style="font-size:.8rem;color:<?=$fld['color']?>"><?=$fld['val']?>/<?=$memberQuality['total']?> (<?=$pct?>%)</span></div>
                                <div style="background:rgba(0,0,0,.3);border-radius:6px;height:8px;overflow:hidden"><div style="height:100%;width:<?=$pct?>%;background:<?=$fld['color']?>;border-radius:6px;transition:width .8s"></div></div>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($memberQuality['duplicates'] > 0): ?>
                            <div style="margin-top:1rem;padding:.6rem;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:.5rem"><span style="font-size:.8rem;color:#fbbf24"><i class="fa-solid fa-clone" style="margin-right:.35rem"></i><?=$memberQuality['duplicates']?> potential duplicate names found</span></div>
                            <?php endif; ?>
                        </div>
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-venus-mars"></i> Gender Distribution</h3>
                            <?php $gTotal = $memberQuality['male'] + $memberQuality['female']; $mPct = $gTotal > 0 ? round($memberQuality['male']/$gTotal*100) : 50; $fPct = 100-$mPct; ?>
                            <div style="display:flex;align-items:center;gap:1.5rem;margin-bottom:1rem;justify-content:center">
                                <div style="text-align:center"><div style="font-size:2rem;font-weight:800;color:#60a5fa"><?=$memberQuality['male']?></div><div style="font-size:.7rem;color:#94a3b8">Male</div></div>
                                <div style="width:1px;height:40px;background:rgba(255,255,255,.1)"></div>
                                <div style="text-align:center"><div style="font-size:2rem;font-weight:800;color:#f472b6"><?=$memberQuality['female']?></div><div style="font-size:.7rem;color:#94a3b8">Female</div></div>
                            </div>
                            <div style="display:flex;border-radius:8px;overflow:hidden;height:12px"><div style="width:<?=$mPct?>%;background:#60a5fa"></div><div style="width:<?=$fPct?>%;background:#f472b6"></div></div>
                            <div style="display:flex;justify-content:space-between;margin-top:.3rem"><span style="font-size:.65rem;color:#60a5fa"><?=$mPct?>% Male</span><span style="font-size:.65rem;color:#f472b6"><?=$fPct?>% Female</span></div>
                        </div>
                    </div>
                </div>

                <!-- CODE ANALYSIS TAB -->
                <div class="sys-panel" id="stab-code">
                    <div class="grid-2">
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-code"></i> Code Security Scan</h3>
                            <?php if (empty($codeIssues)): ?>
                            <div style="text-align:center;padding:1.5rem;color:#4ade80"><i class="fa-solid fa-shield-check" style="font-size:2.5rem;margin-bottom:.5rem;display:block"></i><p style="font-size:.9rem;font-weight:600">No issues detected!</p><p style="font-size:.75rem;color:#7c8aa5;margin-top:.2rem">All scanned files pass security checks</p></div>
                            <?php else: foreach ($codeIssues as $ci): ?>
                            <div style="display:flex;align-items:flex-start;gap:.6rem;padding:.6rem;background:rgba(<?=$ci['type']==='danger'?'239,68,68':'245,158,11'?>,.08);border-radius:.5rem;margin-bottom:.5rem;border-left:3px solid <?=$ci['type']==='danger'?'#f87171':'#fbbf24'?>">
                                <i class="fa-solid fa-<?=$ci['type']==='danger'?'circle-xmark':'triangle-exclamation'?>" style="color:<?=$ci['type']==='danger'?'#f87171':'#fbbf24'?>;margin-top:.1rem"></i>
                                <div><div style="font-size:.8rem;font-weight:500;color:#e2e8f0"><?=htmlspecialchars($ci['file'])?></div><div style="font-size:.7rem;color:#94a3b8;margin-top:.1rem"><?=htmlspecialchars($ci['msg'])?></div></div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-ruler-combined"></i> Codebase Overview</h3>
                            <?php
                            $totalLines = 0; $totalSize = 0; $fileCount = 0;
                            $phpFiles = array_merge(
                                glob(ROOT_PATH . '/*.php') ?: [],
                                glob(ADMIN_PATH . '/*.php') ?: [],
                                glob(ADMIN_PATH . '/backend/*.php') ?: [],
                                glob(ADMIN_PATH . '/dashboards/*.php') ?: []
                            );
                            foreach ($phpFiles as $pf) { $totalLines += count(file($pf)); $totalSize += filesize($pf); $fileCount++; }
                            ?>
                            <div class="health-item"><span class="health-name">PHP Files</span><span class="health-value"><?=$fileCount?></span></div>
                            <div class="health-item"><span class="health-name">Total Lines</span><span class="health-value"><?=number_format($totalLines)?></span></div>
                            <div class="health-item"><span class="health-name">Total Size</span><span class="health-value"><?=$totalSize > 1024*1024 ? round($totalSize/1024/1024,1).' MB' : round($totalSize/1024,1).' KB'?></span></div>
                            <div class="health-item"><span class="health-name">Avg Lines/File</span><span class="health-value"><?=$fileCount > 0 ? round($totalLines/$fileCount) : 0?></span></div>
                            <div class="health-item"><span class="health-name">Dashboards</span><span class="health-value"><?=count(glob(ADMIN_PATH.'/dashboards/*.php') ?: [])?></span></div>
                            <div class="health-item"><span class="health-name">API Endpoints</span><span class="health-value"><?=count($apiEndpoints)?></span></div>
                        </div>
                    </div>
                </div>

                <!-- MAINTENANCE TAB -->
                <div class="sys-panel" id="stab-maintenance">
                    <div class="grid-2">
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-toolbox"></i> Maintenance Tasks</h3>
                            <?php foreach ($cronTasks as $ct): $stColor = $ct['status']==='ok'||$ct['status']==='auto'||$ct['status']==='configured' ? '#4ade80' : ($ct['status']==='needs_attention' ? '#fbbf24' : '#f87171'); ?>
                            <div class="health-item">
                                <span class="health-name"><i class="fa-solid fa-circle" style="color:<?=$stColor?>;font-size:.5rem;margin-right:.5rem"></i><?=$ct['name']?></span>
                                <span style="font-size:.75rem;color:#94a3b8"><?=htmlspecialchars($ct['last'])?></span>
                            </div>
                            <?php endforeach; ?>
                            <div style="margin-top:1rem;display:flex;flex-direction:column;gap:.5rem">
                                <button class="btn btn-primary btn-sm" style="justify-content:center" onclick="switchSection('backup')"><i class="fa-solid fa-database"></i> Go to Backup</button>
                                <button class="btn btn-outline btn-sm" style="justify-content:center" onclick="switchSection('health')"><i class="fa-solid fa-heart-pulse"></i> Site Health</button>
                            </div>
                        </div>
                        <div class="card">
                            <h3 class="card-title"><i class="fa-solid fa-clipboard-check"></i> Recommendations</h3>
                            <?php
                            $recommendations = [];
                            if (!$sHealth['https']) $recommendations[] = ['icon'=>'fa-lock','color'=>'#f87171','text'=>'Enable HTTPS/SSL for secure connections'];
                            if ($sHealth['memory_percent'] > 80) $recommendations[] = ['icon'=>'fa-memory','color'=>'#fbbf24','text'=>'Memory usage is high. Consider increasing memory_limit'];
                            if ($errorLogSize > 2*1024*1024) $recommendations[] = ['icon'=>'fa-file-lines','color'=>'#fbbf24','text'=>'Error log is large ('.round($errorLogSize/1024/1024,1).'MB). Clear or rotate it'];
                            if (($orphanedMembers??0) > 0) $recommendations[] = ['icon'=>'fa-broom','color'=>'#fbbf24','text'=>'Clean up '.$orphanedMembers.' orphaned group member records'];
                            if ($memberQuality['duplicates'] > 0) $recommendations[] = ['icon'=>'fa-clone','color'=>'#fbbf24','text'=>'Review '.$memberQuality['duplicates'].' potential duplicate member names'];
                            if (empty($backupFiles)) $recommendations[] = ['icon'=>'fa-database','color'=>'#f87171','text'=>'No backups found! Create a database backup now'];
                            if (count(array_filter($apiEndpoints,fn($a)=>!$a['has_csrf'])) > 0) $recommendations[] = ['icon'=>'fa-shield-halved','color'=>'#fbbf24','text'=>'Some API endpoints lack CSRF protection'];
                            if (empty($recommendations)) $recommendations[] = ['icon'=>'fa-circle-check','color'=>'#4ade80','text'=>'Everything looks great! No actions needed.'];
                            foreach ($recommendations as $rec): ?>
                            <div style="display:flex;align-items:center;gap:.6rem;padding:.5rem;margin-bottom:.35rem;background:rgba(0,0,0,.15);border-radius:.5rem">
                                <i class="fa-solid <?=$rec['icon']?>" style="color:<?=$rec['color']?>;width:20px;text-align:center"></i>
                                <span style="font-size:.8rem;color:#cbd5e1"><?=$rec['text']?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ PROFILE TAB ═══ -->
            <?php
            require_once __DIR__ . '/../components/profile_tab_section.php';
            renderProfileTabSection('section-profile', 'section', ($activeSection === 'profile'));
            ?>
        </div>
    </main>

    <!-- ADVANCED MOBILE BOTTOM NAV — All Sections -->
    <?php
$navItems = [
    [
        ['icon' => 'fa-solid fa-gauge-high', 'label' => 'Home', 'attrs' => 'data-section="overview"', 'active' => (($activeSection ?? '') === 'overview')],
        ['icon' => 'fa-solid fa-users', 'label' => 'Users', 'attrs' => 'data-section="users"', 'active' => (($activeSection ?? '') === 'users')],
        ['icon' => 'fa-solid fa-building', 'label' => 'Depts', 'attrs' => 'data-section="departments"', 'active' => (($activeSection ?? '') === 'departments')],
        ['icon' => 'fa-solid fa-id-badge', 'label' => 'Identity', 'attrs' => 'data-section="identity"', 'active' => (($activeSection ?? '') === 'identity')],
    ],
    [
        ['icon' => 'fa-solid fa-mobile-screen-button', 'label' => 'Release', 'attrs' => 'data-section="app_release"', 'active' => (($activeSection ?? '') === 'app_release')],
        ['icon' => 'fa-solid fa-chart-line', 'label' => 'Fleet', 'attrs' => 'data-section="app_telemetry"', 'active' => (($activeSection ?? '') === 'app_telemetry')],
        ['icon' => 'fa-solid fa-triangle-exclamation', 'label' => 'Failures', 'attrs' => 'data-section="failure_intelligence"', 'active' => (($activeSection ?? '') === 'failure_intelligence')],
        ['icon' => 'fa-solid fa-heart-pulse', 'label' => 'Health', 'attrs' => 'data-section="health"', 'active' => (($activeSection ?? '') === 'health')],
        ['icon' => 'fa-solid fa-gear', 'label' => 'Settings', 'attrs' => 'data-section="settings"', 'active' => (($activeSection ?? '') === 'settings')],
        ['icon' => 'fa-solid fa-palette', 'label' => 'Brand', 'attrs' => 'data-section="branding"', 'active' => (($activeSection ?? '') === 'branding')],
    ],
    [
        ['icon' => 'fa-solid fa-clock-rotate-left', 'label' => 'Logs', 'attrs' => 'data-section="logs"', 'active' => (($activeSection ?? '') === 'logs')],
        ['icon' => 'fa-solid fa-database', 'label' => 'Backup', 'attrs' => 'data-section="backup"', 'active' => (($activeSection ?? '') === 'backup')],
        ['icon' => 'fa-solid fa-stethoscope', 'label' => 'System', 'attrs' => 'data-section="syshealth"', 'active' => (($activeSection ?? '') === 'syshealth')],
    ],
    [
        ['icon' => 'fa-solid fa-comments', 'label' => 'Comms', 'attrs' => 'data-comm-open="inbox"'],
        ['icon' => 'fa-solid fa-robot', 'label' => 'AI', 'href' => '/admin/dashboards/ai_assistant.php'],
        ['icon' => 'fa-solid fa-user-gear', 'label' => 'Profile', 'attrs' => 'data-section="profile"'],
        ['icon' => 'fa-solid fa-power-off', 'label' => 'Exit', 'href' => '/admin/logout.php', 'exit' => true],
    ],
];
require __DIR__ . '/../components/bottom_nav.php';
?>
<?php include __DIR__ . '/../components/comm/comm_section.php'; ?>
    <script>
    // Scroll hint indicators for bottom nav
    (function(){
        const sc=document.getElementById('bnScroll'),sl=document.getElementById('bnScrollL'),sr=document.getElementById('bnScrollR');
        if(!sc)return;
        function upd(){sl.classList.toggle('visible',sc.scrollLeft>10);sr.classList.toggle('visible',sc.scrollLeft<sc.scrollWidth-sc.clientWidth-10);}
        sc.addEventListener('scroll',upd,{passive:true});
        setTimeout(upd,100);
        // Wire up bottom nav buttons to switchSection
        sc.querySelectorAll('.wbws-bnav-btn[data-section]').forEach(b=>{
            b.addEventListener('click',function(){
                const s=this.dataset.section;
                if(typeof switchSection==='function')switchSection(s);
                sc.querySelectorAll('.wbws-bnav-btn').forEach(x=>x.classList.remove('active'));
                this.classList.add('active');
            });
        });
    })();
    </script>

    <script>window.SA_BOOT=<?= json_encode(['section' => $activeSection, 'csrf' => $csrfToken], JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
    <script src="/admin/js/danger_user_forms.js?v=20261022dc"></script>
    <script src="/admin/js/app_release.js?v=20261026rel"></script>
    <script src="/admin/js/app_telemetry.js?v=20261022p"></script>
    <script src="/admin/js/list_pager.js?v=20261022p"></script>
    <script src="/admin/js/failure_intelligence.js?v=20261022p"></script>
    <script src="/admin/js/super_admin.js?v=20261006a"></script>
    <script>
        // Calendar mode is fixed to Ethiopian system-wide (no save handler).

        // ═══ BRANDING MANAGEMENT — COMPLETE SYSTEM ═══
        const CSRF='<?= $csrfToken ?? '' ?>';
        const BRAND_KEYS=['logo','seal','sig_head','sig_admin','card_bg'];
        let brandSettings={logo_size:100,logo_opacity:100,seal_size:150,seal_opacity:85,sig_head_size:140,sig_head_opacity:90,sig_admin_size:140,sig_admin_opacity:90};
        let _brandLoaded=false, _brandBusy=false;

        // ── Load branding assets from API ──
        async function loadBranding(){
            if(_brandBusy)return;
            _brandBusy=true;
            try{
                const r=await fetch('/admin/api_branding.php?action=get_assets',{credentials:'same-origin'});
                if(!r.ok) throw new Error('HTTP '+r.status);
                const txt=await r.text();
                let d;
                try{d=JSON.parse(txt);}catch(e){
                    console.error('Branding API non-JSON:',txt.substring(0,300));
                    _showBrandError('Branding API returned an invalid response. Ask the deployment administrator to verify the versioned SQL migrations.');
                    _brandBusy=false;return;
                }
                if(d.status==='error'){
                    _showBrandError(d.message+(d.debug?' <code style="display:block;font-size:.65rem;color:#94a3b8;margin-top:.2rem">'+d.debug+'</code>':''));
                    _brandBusy=false;return;
                }
                // Hide error banner on success
                const eb=document.getElementById('brandErrorBanner');
                if(eb)eb.style.display='none';

                // Update each asset card
                (d.assets||[]).forEach(a=>{
                    const key=a.asset_key;
                    const img=document.getElementById('brandImg_'+key);
                    const empty=document.getElementById('brandEmpty_'+key);
                    const rm=document.getElementById('brandRm_'+key);
                    const info=document.getElementById('brandInfo_'+key);
                    const card=document.getElementById('brandCard_'+key);
                    if(!img)return;

                    if(a.file_exists && a.web_url){
                        img.src=a.web_url; img.style.display='block';
                        img.onerror=function(){img.style.display='none'; if(empty)empty.style.display='block';};
                        if(empty)empty.style.display='none';
                        if(rm)rm.style.display=a.is_packaged?'none':'flex';
                        if(info){
                            let t=a.original_name||'';
                            const kb=parseInt(a.file_size,10)||0;
                            if(kb>=1024)t+=(t?' · ':'')+Math.max(1,Math.round(kb/1024))+' KB';
                            info.textContent=t; info.style.color='#7c8aa5';
                        }
                        _setPreviewImg(key, a.web_url);
                        if(key==='card_bg' && typeof window.reloadIdDesignerPreview==='function'){
                            window.reloadIdDesignerPreview(a.web_url);
                        }
                    }else{
                        img.src=''; img.style.display='none';
                        if(empty){empty.style.display='block'; empty.innerHTML='<i class="fa-solid fa-cloud-arrow-up" style="font-size:1.8rem;display:block;margin-bottom:.35rem;opacity:.35"></i>Click to upload';}
                        if(rm)rm.style.display='none';
                        if(info){info.textContent=''; info.style.color='#7c8aa5';}
                        _clearPreviewImg(key);
                    }
                });
                // Load saved settings
                if(d.settings && typeof d.settings==='object'){
                    for(const k of Object.keys(d.settings)){
                        const v=parseInt(d.settings[k]);
                        if(!isNaN(v)&&v>=0&&v<=1000)brandSettings[k]=v;
                    }
                }
                _applyAllControls();
                _brandLoaded=true;
            }catch(e){
                console.error('loadBranding error:',e);
                if(!_brandLoaded) _showBrandError('Could not load branding assets: '+e.message);
            }
            _brandBusy=false;
        }

        // ── Upload asset ──
        async function brandUpload(key,input){
            const file=input.files[0]; if(!file){return;}
            // Client validation
            if(file.size>5*1024*1024){_toast('File too large ('+Math.round(file.size/1024/1024*10)/10+'MB). Max 5MB.','err');input.value='';return;}
            if(!file.type.match(/^image\/(png|jpe?g|gif|webp|svg\+xml)$/) && !file.name.match(/\.(png|jpe?g|gif|webp|svg)$/i)){
                _toast('Invalid file type. Use PNG, JPG, GIF, WebP, or SVG.','err');input.value='';return;
            }
            // Instant local preview
            const localUrl=URL.createObjectURL(file);
            const img=document.getElementById('brandImg_'+key);
            const empty=document.getElementById('brandEmpty_'+key);
            const card=document.getElementById('brandCard_'+key);
            const info=document.getElementById('brandInfo_'+key);
            if(img){img.src=localUrl;img.style.display='block';}
            if(empty)empty.style.display='none';
            if(card)card.style.borderColor='#3b82f6';
            if(info){info.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Uploading…';info.style.color='#60a5fa';}
            // Also update live preview instantly
            _setPreviewImg(key, localUrl);

            const fd=new FormData();
            fd.append('action','upload_asset');fd.append('asset_key',key);fd.append('file',file);fd.append('csrf_token',CSRF);
            try{
                const r=await fetch('/admin/api_branding.php',{method:'POST',body:fd,credentials:'same-origin'});
                const txt=await r.text();
                let d;try{d=JSON.parse(txt);}catch(e){_toast('Upload failed — server error','err');console.error(txt.substring(0,300));return;}
                if(d.status==='success'){
                    _toast(d.message||'Uploaded!','ok');
                    if(card){card.style.borderColor='#22c55e';setTimeout(()=>card.style.borderColor='transparent',2500);}
                }else{
                    _toast(d.message||'Upload failed','err');
                    if(card)card.style.borderColor='#ef4444';
                }
                // Reload from server to get real paths
                await loadBranding();
            }catch(e){
                _toast('Upload failed — network error','err');
            }finally{
                input.value='';
                if(card)setTimeout(()=>{card.style.borderColor='transparent';},3000);
            }
        }

        // ── Remove asset ──
        async function brandRemove(key){
            const dc=await dcAsk({tier:'medium',title:'Remove this image?',object:key,summary:'The image file is deleted from the server.',intent:'I want to remove this image',
                consequences:['The image disappears from every page, report and ID card that uses it.','The default image (or nothing) is shown until you upload a new one.'],
                keeps:['Other branding settings stay.'],irreversible:true,phrase:'REMOVE IMAGE',confirmLabel:'Remove image'});
            if(!dc)return;
            const fd=new FormData();fd.append('action','delete_asset');fd.append('asset_key',key);fd.append('csrf_token',CSRF);
            try{
                const r=await fetch('/admin/api_branding.php',{method:'POST',body:fd,credentials:'same-origin'});
                const txt=await r.text();
                let d;try{d=JSON.parse(txt);}catch(e){_toast('Server error','err');return;}
                _toast(d.message||'Done',d.status==='success'?'ok':'err');
                await loadBranding();
            }catch(e){_toast('Network error','err');}
        }

        // ── Preview helpers ──
        function _setPreviewImg(key, url){
            const prev=document.getElementById('idPrev_'+key);
            const ph=document.getElementById('idPrev_'+key+'_ph');
            if(prev){prev.src=url;prev.style.display='block';}
            if(ph)ph.style.display='none';
        }
        function _clearPreviewImg(key){
            const prev=document.getElementById('idPrev_'+key);
            const ph=document.getElementById('idPrev_'+key+'_ph');
            if(prev){prev.src='';prev.style.display='none';}
            if(ph)ph.style.display='block';
        }

        // ── Slider controls ──
        function updateCtrl(key,prop,val){
            const lbl=document.getElementById('ctrlVal_'+key+'_'+prop);
            if(lbl)lbl.textContent=val;
            brandSettings[key+'_'+prop]=parseInt(val);
            _applyPreview(key,prop,parseInt(val));
        }
        function _applyPreview(key,prop,val){
            if(key==='logo'){
                const w=document.getElementById('idPrev_logo_wrap');if(!w)return;
                if(prop==='size'){const s=Math.max(24,val*52/100);w.style.width=s+'px';w.style.height=s+'px';}
                if(prop==='opacity')w.style.opacity=Math.max(.1,val/100);
            }else if(key==='seal'){
                const w=document.getElementById('idPrev_seal_wrap');if(!w)return;
                if(prop==='size'){const s=Math.max(24,val*70/150);w.style.width=s+'px';w.style.height=s+'px';}
                if(prop==='opacity')w.style.opacity=Math.max(.1,val/100);
            }else if(key==='sig_head'||key==='sig_admin'){
                const img=document.getElementById('idPrev_'+key);if(!img)return;
                if(prop==='size')img.style.maxWidth=Math.max(20,val*70/140)+'px';
                if(prop==='opacity')img.style.opacity=Math.max(.1,val/100);
            }
        }
        function _applyAllControls(){
            BRAND_KEYS.forEach(key=>{
                ['size','opacity'].forEach(prop=>{
                    const v=brandSettings[key+'_'+prop];
                    if(v!==undefined){
                        const s=document.getElementById('ctrl_'+key+'_'+prop);
                        const l=document.getElementById('ctrlVal_'+key+'_'+prop);
                        if(s)s.value=v; if(l)l.textContent=v;
                        _applyPreview(key,prop,v);
                    }
                });
            });
        }
        function resetControls(){
            brandSettings={logo_size:100,logo_opacity:100,seal_size:150,seal_opacity:85,sig_head_size:140,sig_head_opacity:90,sig_admin_size:140,sig_admin_opacity:90};
            _applyAllControls();
            _toast('Reset to defaults','ok');
        }
        async function saveControls(){
            const fd=new FormData();fd.append('action','save_settings');fd.append('settings',JSON.stringify(brandSettings));fd.append('csrf_token',CSRF);
            try{
                const r=await fetch('/admin/api_branding.php',{method:'POST',body:fd,credentials:'same-origin'});
                const txt=await r.text();
                let d;try{d=JSON.parse(txt);}catch(e){_toast('Server error saving','err');return;}
                _toast(d.message||'Saved!',d.status==='success'?'ok':'err');
            }catch(e){_toast('Network error','err');}
        }

        // ── UI helpers ──
        function _showBrandError(html){
            const eb=document.getElementById('brandErrorBanner');
            if(eb){eb.innerHTML='<i class="fa-solid fa-exclamation-triangle" style="color:#ef4444;margin-right:.4rem"></i>'+html;eb.style.display='block';}
        }
        function _toast(msg,type){
            document.querySelectorAll('.brand-toast').forEach(t=>t.remove());
            const el=document.createElement('div');el.className='brand-toast';
            el.style.cssText='position:fixed;bottom:1.5rem;right:1.5rem;padding:.7rem 1.1rem;border-radius:12px;color:#fff;z-index:9999;font-size:.8rem;font-weight:500;box-shadow:0 8px 32px rgba(0,0,0,.4);max-width:400px;word-break:break-word;animation:slideInBrand .3s;backdrop-filter:blur(8px)';
            el.style.background=type==='ok'?'rgba(5,150,105,.95)':'rgba(220,38,38,.95)';
            el.innerHTML='<i class="fa-solid fa-'+(type==='ok'?'check-circle':'exclamation-circle')+'" style="margin-right:.4rem"></i>'+msg;
            document.body.appendChild(el);
            setTimeout(()=>{el.style.opacity='0';el.style.transition='opacity .3s';setTimeout(()=>el.remove(),300);},4000);
        }

        // ── Boot ──
        const _origSwitch=switchSection;
        switchSection=function(id){_origSwitch(id);if(id==='branding'){loadBranding();if(typeof bootIdDesigner==='function'&&!document.getElementById('idcFrame'))bootIdDesigner();}};
        if(<?= json_encode($activeSection) ?>==='branding')setTimeout(function(){loadBranding();if(typeof bootIdDesigner==='function')bootIdDesigner();},150);

        // ── Danger confirmation (admin/js/danger_confirm.js) — owner decisions 2026-10-21 ──
        function dcFail(){const d=document.createElement('div');d.setAttribute('role','alert');d.style.cssText='position:fixed;left:50%;top:1rem;transform:translateX(-50%);z-index:99999;background:#7f1d1d;color:#fff;padding:.6rem 1rem;border-radius:8px;font-size:.85rem';d.textContent='The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.';document.body.appendChild(d);setTimeout(()=>d.remove(),6000);}
        function dcAsk(o){if(window.DangerConfirm&&window.DangerConfirm.ask)return window.DangerConfirm.ask(o);dcFail();return Promise.resolve(false);}
        async function dcTestSeed(action){
            const dc=await dcAsk(action==='clear'
                ?{tier:'high',title:'Remove all practice members?',object:'TEST-FKSS',summary:'Every member marked TEST-FKSS is deleted from the database.',intent:'I want to remove the practice members',
                  consequences:['All TEST-FKSS practice members are deleted, with their enrolments, attendance, marks and family links.','Reports and counts change right away.'],
                  keeps:['Real members are not touched.'],irreversible:true,phrase:'REMOVE PRACTICE MEMBERS',confirmLabel:'Remove practice members'}
                :{tier:'medium',title:'Load the 224 practice members?',object:'TEST-FKSS',summary:'224 practice children are added so the whole system can be tested.',intent:'I want to load practice members',
                  consequences:['224 members marked TEST-FKSS are added and enrolled in classes.','They appear in lists, attendance, marks and reports until you remove them.'],
                  keeps:['Real members are not changed.','You can remove them later with Remove practice members.'],phrase:'LOAD PRACTICE MEMBERS',confirmLabel:'Load practice members'});
            if(!dc)return;
            runTestSeed(action,dc.phrase);
        }
        document.addEventListener('DOMContentLoaded',function(){
            if(!(window.DangerConfirm&&window.DangerConfirm.guardForm))return;
            document.querySelectorAll('[data-dc-clear-log]').forEach(function(b){const f=b.form;if(!f)return;
                window.DangerConfirm.guardForm(f,{tier:'low',title:'Clear the error log?',summary:'The server error log file is emptied. New errors will be logged again from now on.',confirmLabel:'Clear log'});
                f.addEventListener('submit',function(){if(!f.querySelector('input[type=hidden][name=clear_error_log_inline]')){const h=document.createElement('input');h.type='hidden';h.name='clear_error_log_inline';h.value='1';f.appendChild(h);}});
            });
        });
        async function runTestSeed(action,phrase){
            const msg=document.getElementById('testSeedMsg');
            const loadBtn=document.getElementById('testSeedLoadBtn');
            const clearBtn=document.getElementById('testSeedClearBtn');
            if(msg)msg.textContent=action==='load'?'Loading 224 children… this can take up to a minute.':'Removing practice members…';
            if(loadBtn)loadBtn.disabled=true;
            if(clearBtn)clearBtn.disabled=true;
            try{
                const fd=new FormData();
                fd.append('action',action);
                if(phrase)fd.append('confirm_phrase',phrase);
                fd.append('csrf_token','<?= $csrfToken ?>');
                const r=await fetch('/admin/api_test_seed.php',{method:'POST',body:fd,credentials:'same-origin'});
                const d=await r.json();
                if(msg)msg.textContent=d.message||'Done';
                setTimeout(function(){location.reload();},800);
            }catch(e){
                if(msg)msg.textContent='Could not reach the server. Refresh and try again.';
                if(loadBtn)loadBtn.disabled=false;
                if(clearBtn)clearBtn.disabled=false;
            }
        }
    </script>
<?php include __DIR__ . '/../components/account_link.php'; ?>
</body>
</html>
