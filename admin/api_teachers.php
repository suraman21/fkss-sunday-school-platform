<?php
/**
 * ============================================================
 * Teacher Management API
 * ============================================================
 * Complete CRUD for teachers:
 * - Create teacher accounts
 * - Edit teacher info
 * - Assign classes & subjects
 * - Deactivate/delete teachers
 * - Get teacher assignments
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/member_sync.php';
require_once __DIR__ . '/backend/services/AssignmentService.php';
require_once __DIR__ . '/backend/services/ProfileService.php';
require_once __DIR__ . '/backend/services/AccountCredentialService.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';

use App\Services\AssignmentService;

// Check authentication
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Only edu_dept, school_admin, super_admin can manage teachers
$allowedRoles = ['super_admin', 'school_admin', 'edu_dept'];
$currentRole = $_SESSION['admin_role'] ?? '';
$isTeacher = $currentRole === 'teacher';

// Teachers can only access their own data
if (!$isTeacher && !in_array($currentRole, $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
    exit;
}

// Validate CSRF for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

$action = is_string($_REQUEST['action'] ?? '') ? ($_REQUEST['action'] ?? '') : '';
requirePostActions($action, ['create_teacher', 'update_teacher', 'toggle_status', 'delete_teacher', 'add_assignment', 'remove_assignment', 'save_teacher_bundle']);

// Migration-backed compatibility helper; avoids per-request schema inspection.
function _teacherSafeColExists($conn, $table, $col) {
    return $table === 'users' && in_array($col, ['member_id', 'last_login'], true);
}

/** Consume reset buckets before any existing-account profile fields change. */
function _teacherEnforcePasswordResetRateLimit(): void
{
    global $pdo;
    $limiter = new \App\Services\SecurityRateLimiter(
        $pdo instanceof \PDO ? $pdo : null,
        ROOT_PATH . '/admin/uploads/cache'
    );
    $actorId = (int)($_SESSION['admin_id'] ?? 0);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rules = [
        ['action' => 'web-admin-password-reset-user', 'subject' => 'user:' . $actorId],
        ['action' => 'web-admin-password-reset-ip', 'subject' => 'ip:' . $ip],
    ];
    $retryAfter = 1;
    $blocked = false;
    foreach ($rules as $rule) {
        $rate = $limiter->consume($rule['action'], $rule['subject'], 10, 3600);
        if (!$rate['allowed']) {
            $blocked = true;
            $retryAfter = max($retryAfter, (int)$rate['retry_after']);
        }
    }
    if ($blocked) {
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        echo json_encode([
            'status' => 'error',
            'code' => 'RATE_LIMITED',
            'message' => 'Too many password reset attempts. Please try again later.',
            'retry_after' => $retryAfter,
        ]);
        exit;
    }
}

/**
 * Existing-account reset boundary. The account callback runs under the same
 * mysqli transaction as the password update and refresh-session revocation.
 * Creation branches deliberately do not call this helper because no refresh
 * families can exist before an account exists.
 *
 * @param callable():void $accountMutation
 */
function _teacherResetExistingPassword(
    mysqli $conn,
    int $teacherId,
    string $password,
    callable $accountMutation
): void {
    $actorId = (int)($_SESSION['admin_id'] ?? 0);
    $administrator = \App\Services\AuthorizedAdministratorIdentity::fromTrustedAuthorization(
        $actorId
    );
    $service = new \App\Services\AccountCredentialService(
        new \App\Services\MysqliCredentialRepository($conn)
    );
    $result = $service->resetPasswordByAdministratorWithAccountMutation(
        $administrator,
        $teacherId,
        $password,
        $password,
        $accountMutation
    );
    $actor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
        $actorId,
        (string)($_SESSION['admin_username'] ?? ''),
        'web',
        $_SERVER
    );
    \App\Services\SecurityAuditService::recordTrusted(
        $conn,
        $actor,
        'ADMIN_PASSWORD_RESET',
        $result->auditContext(),
        'user',
        $teacherId
    );
}

// Effective academic year — single source of truth (resolver, time-travel aware)
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;

// Schema is owned by AssignmentService + sql/006 (no ALTER on every request).
AssignmentService::ensureSchema($conn);

try {
switch ($action) {

    // ============================================================
    // GET ALL TEACHERS
    // ============================================================
    case 'get_teachers':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
        
        // Migration 006 guarantees assignment storage.
        $hasTaTable = true;
        
        // Check if member_id and last_login columns exist
        $hasMemberId = _teacherSafeColExists($conn, 'users', 'member_id');
        $hasLastLogin = _teacherSafeColExists($conn, 'users', 'last_login');
        
        $memberIdCol = $hasMemberId ? "u.member_id," : "";
        $lastLoginCol = $hasLastLogin ? "u.last_login," : "";
        $memberJoin = $hasMemberId ? "LEFT JOIN members m ON u.member_id = m.id" : "";
        $memberCols = $hasMemberId ? "m.student_name as member_name, m.father_name as member_father,
                    m.phone_number as member_phone, m.member_code," : "";
        
        if ($hasTaTable) {
            $assignedClasses = "(SELECT COUNT(DISTINCT ta.class_id) FROM teacher_assignments ta 
                     WHERE ta.teacher_id = u.id AND ta.is_active = 1) as assigned_classes,";
            $assignedSubjects = "(SELECT COUNT(DISTINCT ta.subject_id) FROM teacher_assignments ta 
                     WHERE ta.teacher_id = u.id AND ta.is_active = 1) as assigned_subjects";
        } else {
            $assignedClasses = "0 as assigned_classes,";
            $assignedSubjects = "0 as assigned_subjects";
        }
        
        $select = "SELECT 
                    u.id, u.username, u.email, u.full_name, u.is_active, 
                    $memberIdCol u.created_at, $lastLoginCol
                    $memberCols
                    $assignedClasses
                    $assignedSubjects ";
        $sql = "FROM users u
                $memberJoin
                WHERE u.role = 'teacher'";
        
        if (!$includeInactive) {
            $sql .= " AND u.is_active = 1";
        } elseif (($_GET['only_inactive'] ?? '') === '1') {
            $sql .= " AND u.is_active = 0";
        }
        $q = trim((string)($_GET['q'] ?? ''));
        $bind = [];
        $types = '';
        if ($q !== '') {
            $sql .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?";
            $st = '%' . $q . '%';
            $bind = [$st, $st, $st];
            $types = 'sss';
            if ($hasMemberId) {
                $sql .= " OR m.member_code LIKE ? OR m.student_name LIKE ?";
                $bind[] = $st;
                $bind[] = $st;
                $types .= 'ss';
            }
            $sql .= ")";
        }
        // Phase 3 paging: page / per_page / total + server-side sort when the
        // screen sends page. Without page the old response is unchanged.
        $pageInfo = [];
        if (isset($_GET['page'])) {
            $perPage = (int)($_GET['per_page'] ?? 50);
            if (!in_array($perPage, [25, 50, 100, 200], true)) { $perPage = 50; }
            $cstmt = $conn->prepare("SELECT COUNT(*) AS c " . $sql);
            if ($types !== '') { $cstmt->bind_param($types, ...$bind); }
            $cstmt->execute();
            $total = (int)($cstmt->get_result()->fetch_assoc()['c'] ?? 0);
            $cstmt->close();
            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min(max(1, (int)$_GET['page']), $totalPages);
            $orderBy = [
                'name' => 'u.full_name ASC, u.id ASC',
                'username' => 'u.username ASC, u.id ASC',
                'classes' => 'assigned_classes DESC, u.full_name ASC, u.id ASC',
            ][(string)($_GET['sort'] ?? 'name')] ?? 'u.full_name ASC, u.id ASC';
            $sql = $select . $sql . " ORDER BY " . $orderBy . " LIMIT " . (int)$perPage . " OFFSET " . (int)(($page - 1) * $perPage);
            $pageInfo = ['total' => $total, 'page' => $page, 'total_pages' => $totalPages, 'per_page' => $perPage];
        } else {
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 100)));
            $sql = $select . $sql . " ORDER BY u.full_name LIMIT " . $limit;
        }
        
        try {
            if ($types !== '') {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$bind);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $conn->query($sql);
            }
            $teachers = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $teachers[] = $row;
                }
            }
            echo json_encode(['status' => 'success', 'teachers' => $teachers] + $pageInfo);
        } catch (Exception $e) {
            reportInternalError('Teacher list failed', $e);
            echo json_encode(['status' => 'error', 'message' => 'Unable to load teachers.']);
        }
        break;

    // ============================================================
    // GET SINGLE TEACHER DETAILS
    // ============================================================
    case 'get_teacher':
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        
        // Teachers can only view their own profile
        if ($isTeacher && $teacherId != $_SESSION['admin_id']) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        if (!$teacherId) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID required']);
            exit;
        }
        
        // Get teacher info (use safe column references)
        $hasMemberId = _teacherSafeColExists($conn, 'users', 'member_id');
        $memberIdRef = $hasMemberId ? "u.member_id," : "";
        $memberJoinRef = $hasMemberId ? "LEFT JOIN members m ON u.member_id = m.id" : "";
        $memberColsRef = $hasMemberId ? "m.student_name as member_name, m.father_name as member_father,
                   m.phone_number, m.member_code" : "NULL as member_name, NULL as member_father,
                   NULL as phone_number, NULL as member_code";
        
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.email, u.full_name, u.is_active, $memberIdRef u.created_at,
                   $memberColsRef
            FROM users u
            $memberJoinRef
            WHERE u.id = ? AND u.role = 'teacher'
        ");
        $stmt->bind_param("i", $teacherId);
        $stmt->execute();
        $teacher = $stmt->get_result()->fetch_assoc();
        
        if (!$teacher) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher not found']);
            exit;
        }
        
        // Get assignments
        $yearId = $currentYear ? $currentYear['id'] : 0;
        $stmt = $conn->prepare("
            SELECT ta.id, ta.class_id, ta.subject_id, ta.is_primary, ta.is_class_teacher,
                   c.class_name, c.class_name_en, c.level_order,
                   s.subject_name, s.subject_name_en,
                   (SELECT COUNT(*) FROM class_enrollments ce 
                    WHERE ce.class_id = ta.class_id AND ce.status = 'active'
                    AND (ce.academic_year_id = ? OR ? = 0)) as student_count
            FROM teacher_assignments ta
            JOIN classes c ON ta.class_id = c.id
            LEFT JOIN subjects s ON ta.subject_id = s.id
            WHERE ta.teacher_id = ? AND ta.is_active = 1
            ORDER BY c.level_order, s.subject_name
        ");
        $stmt->bind_param("iii", $yearId, $yearId, $teacherId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            if (empty($row['subject_name']) && !empty($row['is_class_teacher'])) {
                $row['subject_name'] = 'Class Teacher';
            }
            $assignments[] = $row;
        }
        
        $teacher['assignments'] = $assignments;
        
        echo json_encode(['status' => 'success', 'teacher' => $teacher]);
        break;

    // ============================================================
    // CREATE TEACHER
    // ============================================================
    case 'create_teacher':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $memberId = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
        
        // Validation
        if (empty($fullName) || empty($username) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Full name, username, and password are required']);
            exit;
        }
        
        $passwordErrors = validatePassword($password);
        if ($passwordErrors !== []) {
            echo json_encode(['status' => 'error', 'message' => implode(' ', $passwordErrors)]);
            exit;
        }
        
        // Check if username exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }
        
        // Check if email exists (if provided)
        if (!empty($email)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Email already exists']);
                exit;
            }
        }
        
        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert teacher
        $stmt = $conn->prepare("
            INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)
            VALUES (?, ?, ?, 'teacher', ?, 1, ?)
        ");
        $emailDb = !empty($email) ? $email : null;
        $stmt->bind_param("ssssi", $username, $emailDb, $fullName, $passwordHash, $memberId);
        
        if ($stmt->execute()) {
            $newTeacherId = $conn->insert_id;
            
            // If linked to a member, sync teacher flag/position, not manual member type.
            if ($memberId) {
                syncMemberTeacherFlag($conn, $memberId, true);
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Teacher account created successfully!',
                'teacher_id' => $newTeacherId
            ]);
        } else {
            reportInternalError('Teacher account creation failed', $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to create the teacher account.']);
        }
        break;

    // ============================================================
    // UPDATE TEACHER
    // ============================================================
    case 'update_teacher':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $memberId = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
        $newPassword = $_POST['new_password'] ?? '';
        
        if (!$teacherId || empty($fullName) || empty($username)) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID, full name, and username required']);
            exit;
        }
        
        // Get current teacher data
        $stmt = $conn->prepare("SELECT member_id, username FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->bind_param("i", $teacherId);
        $stmt->execute();
        $currentTeacher = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$currentTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher not found']);
            exit;
        }
        
        // Check username uniqueness (if changed)
        if ($username !== $currentTeacher['username']) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->bind_param("si", $username, $teacherId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Username already in use']);
                exit;
            }
        }
        
        // Check email uniqueness (if provided and changed)
        if (!empty($email)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $email, $teacherId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Email already in use']);
                exit;
            }
        }
        
        // Build update query
        $emailDb = !empty($email) ? $email : null;
        
        if (!empty($newPassword)) {
            $passwordErrors = validatePassword($newPassword);
            if ($passwordErrors !== []) {
                echo json_encode(['status' => 'error', 'message' => implode(' ', $passwordErrors)]);
                exit;
            }
            _teacherEnforcePasswordResetRateLimit();
        }
        $applyTeacherUpdate = static function () use (
            $conn,
            $fullName,
            $username,
            $emailDb,
            $memberId,
            $teacherId,
            $currentTeacher
        ): void {
            $statement = $conn->prepare(
                'UPDATE users
                    SET full_name = ?, username = ?, email = ?, member_id = ?
                  WHERE id = ?'
            );
            if (!$statement) {
                throw new \App\Services\CredentialPersistenceException(
                    'Could not prepare teacher update.'
                );
            }
            $statement->bind_param(
                'sssii',
                $fullName,
                $username,
                $emailDb,
                $memberId,
                $teacherId
            );
            $updated = $statement->execute();
            $statement->close();
            if (!$updated) {
                throw new \App\Services\CredentialPersistenceException(
                    'Could not update teacher account.'
                );
            }
        };

        try {
            if ($newPassword !== '') {
                _teacherResetExistingPassword(
                    $conn,
                    $teacherId,
                    $newPassword,
                    $applyTeacherUpdate
                );
            } else {
                $applyTeacherUpdate();
            }
        } catch (\App\Services\CredentialDomainException $error) {
            echo json_encode([
                'status' => 'error',
                'code' => $error->reason(),
                'message' => 'Password reset request was rejected.',
            ]);
            exit;
        } catch (\Throwable $error) {
            reportInternalError('Teacher update failed', $error);
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
            exit;
        }

        // Preserve established member/position convergence after the atomic
        // account+credential commit; that subsystem owns its own transaction.
        $oldMemberId = (int)($currentTeacher['member_id'] ?? 0);
        if ($oldMemberId > 0 && $oldMemberId !== (int)$memberId) {
            syncMemberTeacherFlag($conn, $oldMemberId, false);
        }
        if ($memberId) {
            syncMemberTeacherFlag($conn, (int)$memberId, true);
        }

        echo json_encode(['status' => 'success', 'message' => 'Teacher updated successfully!']);
        break;

    // ============================================================
    // TOGGLE TEACHER STATUS (Activate/Deactivate)
    // ============================================================
    case 'toggle_status':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        
        if (!$teacherId) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID required']);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT is_active, member_id FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->bind_param("i", $teacherId);
        $stmt->execute();
        $teacher = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$teacher) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher not found']);
            exit;
        }
        
        if (!class_exists('\\App\\Services\\SecurityAuditService')) {
            require_once __DIR__ . '/backend/services/SecurityAuditService.php';
        }

        $newStatus = $teacher['is_active'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $newStatus, $teacherId);
        
        if ($stmt->execute()) {
            $stmt->close();

            // Symmetric teacher lifecycle: suspending pauses the teacher's
            // assignments (snapshotted in the audit trail); reactivating
            // restores EXACTLY those — so a teacher who comes back gets
            // their classes, subjects and homerooms back, while assignments
            // removed individually before the suspension stay removed.
            $message = $newStatus ? 'Teacher activated' : 'Teacher deactivated';
            if (!$newStatus) {
                $ids = \App\Services\AssignmentService::suspendTeacherAssignments($conn, $teacherId);
                \App\Services\SecurityAuditService::record(
                    $conn,
                    'Teacher Suspended',
                    ['assignment_ids' => $ids, 'via' => 'toggle_status'],
                    'user',
                    $teacherId
                );
                if (!empty($teacher['member_id'])) {
                    syncMemberTeacherFlag($conn, (int)$teacher['member_id'], false);
                }
                $message = 'Teacher deactivated — ' . count($ids) . ' assignment(s) paused. Reactivating will restore them.';
            } else {
                $ids = \App\Services\AssignmentService::latestSuspensionSnapshot($conn, $teacherId);
                $restored = \App\Services\AssignmentService::restoreTeacherAssignments($conn, $teacherId, $ids);
                \App\Services\SecurityAuditService::record(
                    $conn,
                    'Teacher Reactivated',
                    ['restored_assignments' => $restored, 'from_snapshot' => $ids],
                    'user',
                    $teacherId
                );
                if (!empty($teacher['member_id'])) {
                    syncMemberTeacherFlag($conn, (int)$teacher['member_id'], true);
                }
                $message = $restored > 0
                    ? "Teacher activated — {$restored} assignment(s) restored."
                    : 'Teacher activated';
            }

            echo json_encode([
                'status' => 'success',
                'message' => $message,
                'new_status' => $newStatus
            ]);
        } else {
            $stmt->close();
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;

    // ============================================================
    // DELETE TEACHER
    // ============================================================
    case 'delete_teacher':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        
        if (!$teacherId) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID required']);
            exit;
        }

        // Never deactivate your own account through this endpoint.
        if ((int)($_SESSION['admin_id'] ?? 0) === $teacherId) {
            respondApiError('You cannot remove your own account here. Ask another administrator.', 422, 'self_delete');
        }
        
        // Get member_id before removal
        $stmt = $conn->prepare("SELECT member_id FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->bind_param("i", $teacherId);
        $stmt->execute();
        $teacher = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$teacher) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher not found']);
            exit;
        }

        // H3 (audit): soft-remove only. The old code ran hard DELETEs on
        // teacher_assignments and users, orphaning grade_submissions.teacher_id
        // and academic_records.recorded_by — the review inbox then showed blank
        // submitter names and audit history lost accountability. Deactivating
        // the account (login refuses inactive users) and the assignments keeps
        // every historical record attributed and queryable.
        // The deactivated assignment ids are SNAPSHOTTED in the audit trail so
        // reactivating the account restores exactly what was suspended.
        if (!class_exists('\\App\\Services\\SecurityAuditService')) {
            require_once __DIR__ . '/backend/services/SecurityAuditService.php';
        }
        $suspendedIds = \App\Services\AssignmentService::suspendTeacherAssignments($conn, $teacherId);
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND role = 'teacher'");
            $stmt->bind_param("i", $teacherId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        \App\Services\SecurityAuditService::record(
            $conn,
            'Teacher Suspended',
            ['assignment_ids' => $suspendedIds, 'via' => 'delete_teacher'],
            'user',
            $teacherId
        );

        // Sync the teacher flag/position; preserve the manually selected member type.
        if ($teacher['member_id']) {
            syncMemberTeacherFlag($conn, (int)$teacher['member_id'], false);
        }
        
        $hadHistory = false;
        $hist = $conn->prepare(
            "SELECT 1 FROM grade_submissions WHERE teacher_id = ? LIMIT 1"
        );
        if ($hist) {
            $hist->bind_param("i", $teacherId);
            $hist->execute();
            $hadHistory = $hist->get_result()->num_rows > 0;
            $hist->close();
        }
        echo json_encode([
            'status' => 'success',
            'message' => $hadHistory
                ? 'Teacher removed. The account is deactivated and their submitted mark lists and grades remain attributed for audit.'
                : 'Teacher removed. The account is deactivated and can be re-enabled later if needed.',
        ]);
        break;

    // ============================================================
    // GET TEACHER ASSIGNMENTS
    // ============================================================
    case 'get_assignments':
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        
        // Teachers can only view their own assignments
        if ($isTeacher && $teacherId != $_SESSION['admin_id']) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        if (!$teacherId) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID required']);
            exit;
        }
        
        $yearId = $currentYear ? $currentYear['id'] : 0;
        
        $stmt = $conn->prepare("
            SELECT 
                ta.id, ta.class_id, ta.subject_id, ta.is_primary,
                c.class_name, c.class_name_en, c.level_order,
                s.subject_name, s.subject_name_en,
                (SELECT COUNT(*) FROM class_enrollments ce 
                 WHERE ce.class_id = ta.class_id AND ce.status = 'active'
                 AND (ce.academic_year_id = ? OR ? = 0)) as student_count
            FROM teacher_assignments ta
            JOIN classes c ON ta.class_id = c.id
            LEFT JOIN subjects s ON ta.subject_id = s.id
            WHERE ta.teacher_id = ? AND ta.is_active = 1
            ORDER BY c.level_order, s.subject_name
        ");
        $stmt->bind_param("iii", $yearId, $yearId, $teacherId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            if (empty($row['subject_name']) && !empty($row['is_class_teacher'])) {
                $row['subject_name'] = 'Class Teacher';
            }
            $assignments[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'assignments' => $assignments]);
        break;

    // ============================================================
    // ASSIGN CLASS & SUBJECT TO TEACHER
    // ============================================================
    case 'add_assignment':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        if (function_exists('ay_require_writable')) {
            ay_require_writable($conn);
        }
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
        $isPrimary = isset($_POST['is_primary']) ? (int)$_POST['is_primary'] : 1;
        $isClassTeacher = !empty($_POST['is_class_teacher']);
        $asgRole = $isClassTeacher && !$subjectId ? 'homeroom' : ($isPrimary ? 'primary' : 'assistant');
        echo json_encode(AssignmentService::assign(
            $conn, $teacherId, $classId, $subjectId, $asgRole, null, (int)$_SESSION['admin_id']
        ), JSON_UNESCAPED_UNICODE);
        break;

    // ============================================================
    // REMOVE ASSIGNMENT
    // ============================================================
    case 'remove_assignment':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        
        if (!$assignmentId) {
            echo json_encode(['status' => 'error', 'message' => 'Assignment ID required']);
            exit;
        }
        
        if (function_exists('ay_require_writable')) {
            ay_require_writable($conn);
        }
        echo json_encode(AssignmentService::unassign($conn, $assignmentId), JSON_UNESCAPED_UNICODE);
        break;

    // ============================================================
    // GET AVAILABLE CLASSES & SUBJECTS FOR ASSIGNMENT
    // ============================================================
    case 'get_available_for_assignment':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        
        // Get all active classes
        $classes = [];
        $result = $conn->query("SELECT id, class_name, class_name_en FROM classes WHERE is_active = 1 ORDER BY level_order");
        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }
        
        // Get all active subjects
        $subjects = [];
        $result = $conn->query("SELECT id, subject_name, subject_name_en FROM subjects WHERE is_active = 1 ORDER BY subject_name");
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row;
        }
        
        // Get existing assignments for this teacher (to disable already assigned)
        $existing = [];
        if ($teacherId) {
            $stmt = $conn->prepare("
                SELECT CONCAT(class_id, '-', subject_id) as combo 
                FROM teacher_assignments 
                WHERE teacher_id = ? AND is_active = 1
            ");
            $stmt->bind_param("i", $teacherId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $existing[] = $row['combo'];
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'classes' => $classes,
            'subjects' => $subjects,
            'existing' => $existing
        ]);
        break;

    // ============================================================
    // GET MY ASSIGNMENTS (For Teacher Dashboard)
    // ============================================================
    case 'get_my_assignments':
        $userId = $_SESSION['admin_id'];
        $role = $_SESSION['admin_role'] ?? '';
        
        if ($role !== 'teacher') {
            echo json_encode(['status' => 'error', 'message' => 'Not a teacher account']);
            exit;
        }
        
        $yearId = $currentYear ? $currentYear['id'] : 0;
        
        $stmt = $conn->prepare("
            SELECT 
                ta.id, ta.class_id, ta.subject_id, ta.is_primary,
                c.class_name, c.class_name_en, c.level_order,
                s.subject_name, s.subject_name_en,
                (SELECT COUNT(*) FROM class_enrollments ce 
                 WHERE ce.class_id = ta.class_id AND ce.status = 'active'
                 AND (ce.academic_year_id = ? OR ? = 0)) as student_count
            FROM teacher_assignments ta
            JOIN classes c ON ta.class_id = c.id
            LEFT JOIN subjects s ON ta.subject_id = s.id
            WHERE ta.teacher_id = ? AND ta.is_active = 1
            ORDER BY c.level_order, s.subject_name
        ");
        $stmt->bind_param("iii", $yearId, $yearId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            if (empty($row['subject_name']) && !empty($row['is_class_teacher'])) {
                $row['subject_name'] = 'Class Teacher';
            }
            $assignments[] = $row;
        }
        
        echo json_encode([
            'status' => 'success',
            'assignments' => $assignments,
            'academic_year' => $currentYear
        ]);
        break;

    // ============================================================
    // GET MEMBERS FOR LINKING (Teachers who are also members)
    // ============================================================
    case 'get_members_for_linking':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        // Get adult members who could be teachers
        $result = $conn->query("
            SELECT id, member_code, student_name, father_name, grandfather_name, phone_number
            FROM members 
            WHERE status = 'active' 
            AND (age_group = '18+' OR age_group IS NULL OR TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 18)
            ORDER BY student_name
            LIMIT 500
        ");
        
        $members = [];
        while ($row = $result->fetch_assoc()) {
            $members[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'members' => $members]);
        break;

    // ============================================================
    // SEARCH MEMBERS TO LINK (name + code only — no phone/address)
    // ============================================================
    case 'search_members_for_teacher':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if (strlen($q) < 1) {
            echo json_encode(['status' => 'success', 'members' => []]);
            break;
        }
        $st = '%' . $q . '%';
        $limit = 15;
        $stmt = $conn->prepare(
            "SELECT id, member_code, student_name, father_name
             FROM members
             WHERE status = 'active'
               AND (student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ? OR baptismal_name LIKE ?)
             ORDER BY student_name
             LIMIT ?"
        );
        if (!$stmt) {
            echo json_encode(['status' => 'success', 'members' => []]);
            break;
        }
        $stmt->bind_param('ssssi', $st, $st, $st, $st, $limit);
        $stmt->execute();
        $members = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $members[] = [
                'id' => (int)$row['id'],
                'member_code' => $row['member_code'] ?? '',
                'student_name' => $row['student_name'] ?? '',
                'father_name' => $row['father_name'] ?? '',
            ];
        }
        $stmt->close();
        echo json_encode(['status' => 'success', 'members' => $members], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================================
    // SAVE TEACHER + LOGIN + ASSIGNMENTS IN ONE REQUEST
    // ============================================================
    case 'save_teacher_bundle':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }

        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $memberId = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
        $dutiesRaw = $_POST['duties'] ?? '[]';
        $duties = is_array($dutiesRaw) ? $dutiesRaw : (json_decode((string)$dutiesRaw, true) ?: []);
        // One-form payload: assignments[{class_id,subject_id}] + homeroom_class_ids[]
        // Empty subject on a row is ignored (homeroom is only the chips).
        $asgRaw = array_key_exists('assignments', $_POST) ? $_POST['assignments'] : null;
        $homeRaw = array_key_exists('homeroom_class_ids', $_POST) ? $_POST['homeroom_class_ids'] : null;
        $syncRows = null;
        $syncHomes = null;
        if ($asgRaw !== null || $homeRaw !== null) {
            $syncRows = [];
            $rawRows = $asgRaw === null ? [] : (is_array($asgRaw) ? $asgRaw : (json_decode((string)$asgRaw, true) ?: []));
            foreach ($rawRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cid = (int)($row['class_id'] ?? 0);
                $sid = !empty($row['subject_id']) ? (int)$row['subject_id'] : 0;
                if ($cid > 0 && $sid > 0) {
                    $syncRows[] = ['class_id' => $cid, 'subject_id' => $sid];
                }
            }
            $rawHomes = $homeRaw === null ? [] : (is_array($homeRaw) ? $homeRaw : (json_decode((string)$homeRaw, true) ?: []));
            $syncHomes = array_values(array_unique(array_filter(array_map('intval', $rawHomes))));
        }

        if ($fullName === '' || $username === '') {
            echo json_encode(['status' => 'error', 'message' => 'Please enter the full name and a username.', 'field' => $fullName === '' ? 'full_name' : 'username']);
            exit;
        }
        if ($teacherId <= 0 || $password !== '') {
            $passwordErrors = validatePassword($password);
            if ($passwordErrors !== []) {
                echo json_encode([
                    'status' => 'error',
                    'message' => implode(' ', $passwordErrors),
                    'field' => 'password',
                ]);
                exit;
            }
        }
        if ($teacherId > 0 && $password !== '') {
            _teacherEnforcePasswordResetRateLimit();
        }

        if ($memberId) {
            $mchk = $conn->prepare("SELECT id, student_name, status FROM members WHERE id = ? LIMIT 1");
            if ($mchk) {
                $mchk->bind_param('i', $memberId);
                $mchk->execute();
                $mrow = $mchk->get_result()->fetch_assoc();
                $mchk->close();
                if (!$mrow || ($mrow['status'] ?? '') === 'archived') {
                    echo json_encode(['status' => 'error', 'message' => 'That member was not found.']);
                    exit;
                }
                if ($fullName === '') {
                    $fullName = (string)$mrow['student_name'];
                }
            }
        }

        $unameChk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $otherId = $teacherId ?: 0;
        $unameChk->bind_param('si', $username, $otherId);
        $unameChk->execute();
        if ($unameChk->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'That username is already used. Choose another.', 'field' => 'username']);
            exit;
        }
        $unameChk->close();

        if ($email !== '') {
            $emChk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $emChk->bind_param('si', $email, $otherId);
            $emChk->execute();
            if ($emChk->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'That email is already used. Choose another.', 'field' => 'email']);
                exit;
            }
            $emChk->close();
        }

        $emailDb = $email !== '' ? $email : null;
        $created = false;

        if ($teacherId > 0) {
            $cur = $conn->prepare("SELECT id, member_id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
            $cur->bind_param('i', $teacherId);
            $cur->execute();
            $current = $cur->get_result()->fetch_assoc();
            $cur->close();
            if (!$current) {
                echo json_encode(['status' => 'error', 'message' => 'Teacher not found.']);
                exit;
            }

            $applyTeacherLoginUpdate = static function () use (
                $conn,
                $fullName,
                $username,
                $emailDb,
                $memberId,
                $teacherId,
                $current
            ): void {
                $statement = $conn->prepare(
                    'UPDATE users
                        SET full_name = ?, username = ?, email = ?, member_id = ?
                      WHERE id = ?'
                );
                if (!$statement) {
                    throw new \App\Services\CredentialPersistenceException(
                        'Could not prepare teacher login update.'
                    );
                }
                $statement->bind_param(
                    'sssii',
                    $fullName,
                    $username,
                    $emailDb,
                    $memberId,
                    $teacherId
                );
                $updated = $statement->execute();
                $statement->close();
                if (!$updated) {
                    throw new \App\Services\CredentialPersistenceException(
                        'Could not update teacher login.'
                    );
                }
            };

            try {
                if ($password !== '') {
                    _teacherResetExistingPassword(
                        $conn,
                        $teacherId,
                        $password,
                        $applyTeacherLoginUpdate
                    );
                } else {
                    $applyTeacherLoginUpdate();
                }
            } catch (\App\Services\CredentialDomainException $error) {
                echo json_encode([
                    'status' => 'error',
                    'code' => $error->reason(),
                    'message' => 'Password reset request was rejected.',
                ]);
                exit;
            } catch (\Throwable $error) {
                reportInternalError('Teacher login update failed', $error);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Could not update teacher login.',
                ]);
                exit;
            }
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)
                 VALUES (?, ?, ?, 'teacher', ?, 1, ?)"
            );
            $stmt->bind_param('ssssi', $username, $emailDb, $fullName, $hash, $memberId);
            if (!$stmt->execute()) {
                echo json_encode(['status' => 'error', 'message' => 'Could not create teacher login.']);
                exit;
            }
            $teacherId = (int)$conn->insert_id;
            $stmt->close();
            $created = true;
        }

        // Preserve established member/position convergence after the atomic
        // existing-account update. Position synchronization may start its own
        // transaction and therefore cannot run inside the credential boundary.
        if (!$created) {
            $oldMid = (int)($current['member_id'] ?? 0);
            if ($oldMid > 0 && $oldMid !== (int)$memberId) {
                syncMemberTeacherFlag($conn, $oldMid, false);
            }
        }
        if ($memberId) {
            syncMemberTeacherFlag($conn, (int)$memberId, true);
        }

        $assigned = 0;
        $removed = 0;
        $assignErrors = [];
        if (($syncRows !== null || $duties) && function_exists('ay_require_writable')) {
            $okYear = ay_require_writable($conn, true);
            if ($okYear === false) {
                $assignErrors[] = 'Teacher login was saved. Assignments were skipped because there is no writable academic year.';
                $duties = [];
                $syncRows = null;
            }
        }
        if ($syncRows !== null) {
            $sync = AssignmentService::syncForTeacher(
                $conn,
                $teacherId,
                $syncRows,
                $syncHomes ?: [],
                null,
                (int)$_SESSION['admin_id']
            );
            $assigned = (int)($sync['assigned'] ?? 0);
            $removed = (int)($sync['removed'] ?? 0);
            if (($sync['status'] ?? '') !== 'success') {
                $assignErrors[] = $sync['message'] ?? 'Could not save assignments.';
            }
            $duties = [];
        }
        foreach ($duties as $duty) {
            if (!is_array($duty)) {
                continue;
            }
            $kind = strtolower(trim((string)($duty['type'] ?? 'regular')));
            $classIds = $duty['class_ids'] ?? [];
            if (!is_array($classIds)) {
                $classIds = [];
            }
            $classIds = array_values(array_unique(array_filter(array_map('intval', $classIds))));
            if (!$classIds) {
                continue;
            }
            if ($kind === 'homeroom') {
                foreach ($classIds as $cid) {
                    $res = AssignmentService::setHomeroom($conn, $teacherId, $cid, null, (int)$_SESSION['admin_id']);
                    if (($res['status'] ?? '') === 'success') {
                        $assigned++;
                    } else {
                        $assignErrors[] = $res['message'] ?? 'Could not set Class Teacher.';
                    }
                }
            } else {
                $subjectId = !empty($duty['subject_id']) ? (int)$duty['subject_id'] : null;
                $role = (string)($duty['role'] ?? 'primary');
                $res = AssignmentService::assignBulk($conn, $teacherId, $classIds, $subjectId, $role, null, (int)$_SESSION['admin_id']);
                $assigned += (int)($res['assigned'] ?? 0);
                if (($res['status'] ?? '') !== 'success' && empty($res['assigned'])) {
                    $assignErrors[] = $res['message'] ?? 'Could not assign classes.';
                }
            }
        }

        $msg = $created
            ? 'Teacher login created. They can sign in with username “' . $username . '”.'
            : 'Teacher updated.';
        if ($assigned || $removed) {
            $msg .= ' ' . $assigned . ' class assignment(s) saved.';
            if ($removed) {
                $msg .= ' ' . $removed . ' removed.';
            }
        }
        if ($assignErrors) {
            $msg .= ' ' . $assignErrors[0];
        }

        echo json_encode([
            'status' => 'success',
            'message' => $msg,
            'teacher_id' => $teacherId,
            'created' => $created,
            'assigned' => $assigned,
            'removed' => $removed,
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
}
} catch (Exception $e) {
    error_log("api_teachers error [{$action}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again.']);
}

$conn->close();
