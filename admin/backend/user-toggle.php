<?php
/**
 * User Toggle Status API
 * Toggle user active/inactive status.
 *
 * HARDENING (L1 + F8):
 *  - CSRF token is now mandatory for this state-changing POST
 *    (hr-dept / info-dept callers already send it; school_admin.js was
 *    updated in the same change).
 *  - Accepts BOTH caller conventions so no dashboard breaks:
 *      user_id | id            target user
 *      action  = toggle_status | activate | deactivate
 *  - Self-lockout prevention: an admin can never deactivate their own
 *    account through this endpoint.
 */

require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}



// CSRF protection for this state-changing action.
requireCsrf();

$userId = (int)($_POST['user_id'] ?? ($_POST['id'] ?? 0));
$action = $_POST['action'] ?? '';

if (!$userId || !in_array($action, ['toggle_status', 'activate', 'deactivate'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit;
}

// Never allow an admin to deactivate their own account (lockout guard).
if ($userId === (int)($_SESSION['admin_id'] ?? 0)) {
    echo json_encode(['status' => 'error', 'message' => 'You cannot change the status of your own account.']);
    exit;
}

$currentRole = $_SESSION['admin_role'] ?? '';

// Check permissions - who can toggle which users
$allowedToToggle = [
    'super_admin' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept', 'edu_dept', 'finance_dept', 'material_dept', 'mezmur_dept', 'teacher', 'attendance_taker', 'content_editor', 'mezmur_attendance_taker', 'hr_attendance_taker', 'accounts_office'],
    'school_admin' => ['info_dept', 'hr_dept', 'edu_dept', 'finance_dept', 'material_dept', 'mezmur_dept', 'teacher', 'attendance_taker'],
    'info_dept' => ['attendance_taker'],
    'edu_dept' => ['teacher'],
    'mezmur_dept' => ['attendance_taker'],
];

// Get target user role
$stmt = $pdo->prepare("SELECT role, is_active FROM users WHERE id = ?");
$stmt->execute([$userId]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

$allowedRoles = $allowedToToggle[$currentRole] ?? [];
if (!in_array($targetUser['role'], $allowedRoles)) {
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to modify this user']);
    exit;
}

// Resolve the target status from the requested action.
switch ($action) {
    case 'activate':
        $newStatus = 1;
        break;
    case 'deactivate':
        $newStatus = 0;
        break;
    default: // toggle_status
        $newStatus = $targetUser['is_active'] ? 0 : 1;
}

if ((int)$targetUser['is_active'] === $newStatus) {
    echo json_encode([
        'status' => 'success',
        'message' => $newStatus ? 'User already active' : 'User already inactive',
        'new_status' => $newStatus,
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    $stmt->execute([$newStatus, $userId]);

    echo json_encode([
        'status' => 'success',
        'message' => $newStatus ? 'User activated' : 'User deactivated',
        'new_status' => $newStatus
    ]);
} catch (Exception $e) {
    error_log("User toggle error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
