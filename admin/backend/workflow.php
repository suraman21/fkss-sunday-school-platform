<?php
/**
 * ============================================================
 * School Workflow & Notification System
 * ============================================================
 * 
 * This library handles:
 * 1. Sending notifications to departments/users
 * 2. Logging member changes for department sync
 * 3. Creating cross-department tasks
 * 4. Auto-updating related member data
 * 
 * Include this file in any page that needs these features:
 * require_once __DIR__ . '/backend/workflow.php';
 * ============================================================
 */

// Ensure config is loaded
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config.php';
}

// Notification schema is deployment-managed by migration 012
// (+ 042 Communication Center tables). The service is the single writer.
require_once __DIR__ . '/services/NotificationCenterService.php';

/**
 * Department role mappings for notifications
 */
// P72: labels come from NotificationCenterService (single source of
// truth — includes teachers, attendance takers and content editors).
$DEPT_ROLES = \App\Services\NotificationCenterService::ROLE_LABELS;

/**
 * Which departments should be notified for each event type
 */
$NOTIFICATION_MATRIX = [
    'failure_alert' => ['super_admin'],
    'member_registered' => ['super_admin', 'school_admin', 'edu_dept'],
    'member_eligible_permanent' => ['hr_dept', 'super_admin', 'school_admin'],
    'member_promoted' => ['hr_dept', 'super_admin', 'school_admin'],
    'member_updated' => ['super_admin', 'school_admin'],
    'member_archived' => ['super_admin', 'school_admin', 'edu_dept', 'finance_dept'],
    'member_restored' => ['super_admin', 'school_admin', 'edu_dept'],
    'role_changed' => ['super_admin', 'school_admin', 'info_dept', 'edu_dept'],
    'teacher_assigned' => ['super_admin', 'school_admin', 'info_dept'],
    'class_enrolled' => ['super_admin', 'school_admin', 'info_dept'],
    'grade_recorded' => ['super_admin', 'school_admin'],
    // Attendance alerting Phase 2 (requirements §8): department + HR + Info
    // only — never School Admin / Super Admin. Produced by
    // AttendanceWarningService (direct insert, same table); listed here so the
    // matrix stays the single reference of who may receive which type.
    'attendance_warning' => ['edu_dept', 'mezmur_dept', 'hr_dept', 'info_dept'],
    'document_shared' => ['super_admin', 'school_admin', 'info_dept', 'edu_dept', 'finance_dept', 'material_dept', 'mezmur_dept'],
    'task_assigned' => [], // Specific to target
];

// ============================================================
// NOTIFICATION FUNCTIONS
// ============================================================

/**
 * Send a notification to specific roles or user
 * 
 * @param mysqli $conn Database connection
 * @param string $type Notification type (e.g., 'member_registered')
 * @param string $title Notification title
 * @param string $message Notification message
 * @param array $options Optional settings:
 *   - 'data' => array of additional data
 *   - 'priority' => 'low', 'normal', 'high', 'urgent'
 *   - 'target_roles' => array of roles to notify
 *   - 'target_user_id' => specific user ID
 *   - 'source_dept' => department that triggered this
 * @return int|false Notification ID or false on failure
 */
function sendNotification($conn, $type, $title, $message, $options = []) {
    global $NOTIFICATION_MATRIX;
    
    try {
        $data = isset($options['data']) ? json_encode($options['data']) : null;
        $priority = $options['priority'] ?? 'normal';
        $sourceDept = $options['source_dept'] ?? ($_SESSION['admin_role'] ?? null);
        $sourceUserId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        
        // Determine target roles
        $targetRoles = $options['target_roles'] ?? ($NOTIFICATION_MATRIX[$type] ?? []);
        $targetRolesStr = is_array($targetRoles) ? implode(',', $targetRoles) : $targetRoles;
        
        $targetUserId = isset($options['target_user_id']) ? (int)$options['target_user_id'] : null;

        // ── Broadcast guard ───────────────────────────────────────────
        // NotificationCenterService::feed() treats a row with
        // `target_roles IS NULL AND target_user_id IS NULL` as a BROADCAST:
        // it is delivered to every account in the system. An empty target
        // list is truthy-as-'', and '' is NOT NULL, so it matches nobody —
        // the notification is silently dropped.
        //
        // Those two outcomes are opposite and neither is what a caller
        // means by "no target". An addressed notification that reaches
        // nobody is a lost task; one addressed to nobody is a school-wide
        // broadcast. `task_assigned` has an empty entry in
        // $NOTIFICATION_MATRIX ('Specific to target'), and
        // createDepartmentTask() passes `[]` when a task has no department
        // — exactly the shape that produced the lost notification. So:
        // refuse to write a row that is addressed to no one, and make the
        // reason visible instead of silent.
        $hasRoleTarget = is_array($targetRoles)
            ? $targetRoles !== []
            : ($targetRoles !== null && $targetRoles !== '');
        if (!$hasRoleTarget && $targetUserId === null) {
            error_log(
                'sendNotification refused: type=' . $type . ' has no target role and no target user.'
                . ' An untargeted notification is either dropped or broadcast school-wide.'
            );
            return false;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO notifications 
            (type, title, message, data, priority, source_dept, source_user_id, target_roles, target_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) return false;
        
        $stmt->bind_param(
            "sssssssss",
            $type, $title, $message, $data, $priority, 
            $sourceDept, $sourceUserId, $targetRolesStr, $targetUserId
        );
        
        if ($stmt->execute()) {
            return $conn->insert_id;
        }
    } catch (Exception $e) {
        // Silently fail - notifications should never crash the main operation
        error_log("sendNotification error: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get unread notifications for current user
 */
function getUnreadNotifications($conn, $limit = 20) {
    // P72: per-user read state via notification_reads (the global
    // is_read column is no longer the source of truth for feeds).
    $userRole = (string)($_SESSION['admin_role'] ?? '');
    $userId = (int)($_SESSION['admin_id'] ?? 0);
    $feed = \App\Services\NotificationCenterService::feed(
        $conn, $userId, $userRole, max(1, min(50, (int)$limit)), 0, true
    );
    return $feed['rows'];
}

/**
 * Get unread notification count
 */
function getUnreadNotificationCount($conn) {
    $userRole = (string)($_SESSION['admin_role'] ?? '');
    $userId = (int)($_SESSION['admin_id'] ?? 0);
    $summary = \App\Services\NotificationCenterService::unreadSummary($conn, $userId, $userRole);
    return $summary['alerts'];
}

/**
 * Mark notification as read
 */
function markNotificationRead($conn, $notificationId) {
    // P72: delegates to the Communication Center (per-user read state;
    // still writes the legacy is_read column for compatibility).
    $role = (string)($_SESSION['admin_role'] ?? '');
    $userId = (int)($_SESSION['admin_id'] ?? 0);
    return \App\Services\NotificationCenterService::markRead(
        $conn, $userId, $role, (int)$notificationId
    );
}

/**
 * Mark all notifications as read for current user
 */
function markAllNotificationsRead($conn) {
    // P72: marks read for THE CURRENT USER ONLY (notification_reads
    // pivot) — one user clearing their badge no longer clears the
    // whole department's. Legacy is_read column still updated.
    $userRole = (string)($_SESSION['admin_role'] ?? '');
    $userId = (int)($_SESSION['admin_id'] ?? 0);
    return \App\Services\NotificationCenterService::markAllRead(
        $conn, $userId, $userRole
    );
}

// ============================================================
// MEMBER CHANGE LOGGING
// ============================================================

/**
 * Log a member change for department sync
 * 
 * @param mysqli $conn Database connection
 * @param int $memberId Member ID
 * @param string $changeType Type of change
 * @param array $options Optional settings:
 *   - 'field_changed' => specific field name
 *   - 'old_value' => previous value
 *   - 'new_value' => new value
 *   - 'summary' => human-readable summary
 *   - 'requires_sync' => whether other depts need to know
 * @return int|false Change log ID or false on failure
 */
function logMemberChange($conn, $memberId, $changeType, $options = []) {
    try {
        $fieldChanged = $options['field_changed'] ?? null;
        $oldValue = $options['old_value'] ?? null;
        $newValue = $options['new_value'] ?? null;
        $summary = $options['summary'] ?? null;
        $requiresSync = $options['requires_sync'] ?? 1;
        $changedByDept = $_SESSION['admin_role'] ?? null;
        $changedByUser = $_SESSION['admin_id'] ?? null;
        
        $stmt = $conn->prepare("
            INSERT INTO member_changes 
            (member_id, change_type, field_changed, old_value, new_value, change_summary, 
             changed_by_dept, changed_by_user, requires_sync)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) return false;
        
        $stmt->bind_param(
            "issssssis",
            $memberId, $changeType, $fieldChanged, $oldValue, $newValue, 
            $summary, $changedByDept, $changedByUser, $requiresSync
        );
        
        if ($stmt->execute()) {
            return $conn->insert_id;
        }
    } catch (Exception $e) {
        error_log("logMemberChange error: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get recent member changes that require sync
 */
function getUnsyncedChanges($conn, $forDept = null, $limit = 50) {
    try {
        $forDept = $forDept ?? ($_SESSION['admin_role'] ?? '');
        
        $stmt = $conn->prepare("
            SELECT mc.*, m.student_name, m.father_name, m.member_code
            FROM member_changes mc
            LEFT JOIN members m ON mc.member_id = m.id
            WHERE mc.requires_sync = 1
            AND (mc.synced_to IS NULL OR FIND_IN_SET(?, mc.synced_to) = 0)
            AND mc.changed_by_dept != ?
            ORDER BY mc.created_at DESC
            LIMIT ?
        ");
        
        if (!$stmt) return [];
        
        $stmt->bind_param("ssi", $forDept, $forDept, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $changes = [];
        while ($row = $result->fetch_assoc()) {
            $changes[] = $row;
        }
        
        return $changes;
    } catch (Exception $e) { return []; }
}

/**
 * Mark a change as synced for current department
 */
function markChangeSynced($conn, $changeId) {
    try {
        $dept = $_SESSION['admin_role'] ?? '';
        
        $stmt = $conn->prepare("SELECT synced_to FROM member_changes WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $changeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        
        $syncedTo = $row['synced_to'] ?? '';
        $depts = $syncedTo ? explode(',', $syncedTo) : [];
        if (!in_array($dept, $depts)) {
            $depts[] = $dept;
        }
        $newSyncedTo = implode(',', $depts);
        
        $stmt = $conn->prepare("UPDATE member_changes SET synced_to = ? WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("si", $newSyncedTo, $changeId);
        return $stmt->execute();
    } catch (Exception $e) { return false; }
}

// ============================================================
// CROSS-DEPARTMENT TASKS
// ============================================================

/**
 * Create a task for another department
 * 
 * @param mysqli $conn Database connection
 * @param string $title Task title
 * @param array $options Task options:
 *   - 'description' => detailed description
 *   - 'task_type' => 'approval', 'review', 'action', 'info'
 *   - 'priority' => 'low', 'normal', 'high', 'urgent'
 *   - 'to_dept' => target department
 *   - 'to_user_id' => specific user
 *   - 'related_member_id' => member this is about
 *   - 'due_date' => YYYY-MM-DD
 *   - 'related_data' => additional data array
 * @return int|false Task ID or false on failure
 */
function createDepartmentTask($conn, $title, $options = []) {
    try {
        $description = $options['description'] ?? null;
        $taskType = $options['task_type'] ?? 'action';
        $priority = $options['priority'] ?? 'normal';
        $fromDept = $_SESSION['admin_role'] ?? null;
        $fromUserId = $_SESSION['admin_id'] ?? null;
        $toDept = $options['to_dept'] ?? null;
        $toUserId = $options['to_user_id'] ?? null;
        $relatedMemberId = $options['related_member_id'] ?? null;
        $relatedData = isset($options['related_data']) ? json_encode($options['related_data']) : null;
        $dueDate = $options['due_date'] ?? null;
        
        $stmt = $conn->prepare("
            INSERT INTO department_tasks 
            (title, description, task_type, priority, from_dept, from_user_id, 
             to_dept, to_user_id, related_member_id, related_data, due_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) return false;
        
        $stmt->bind_param(
            "sssssisisss",
            $title, $description, $taskType, $priority, $fromDept, $fromUserId,
            $toDept, $toUserId, $relatedMemberId, $relatedData, $dueDate
        );
        
        if ($stmt->execute()) {
            $taskId = $conn->insert_id;
            
            // Only notify when the task is actually addressed to someone.
            // A task with neither a department nor a user is storable (the
            // row above already landed) but has no recipient; notifying
            // anyway wrote a notification addressed to nobody, which
            // NotificationCenterService::feed() either dropped or
            // broadcast. The guard in sendNotification() now refuses that
            // shape — this check avoids the pointless attempt and the log
            // line it would produce.
            if ($toDept || $toUserId) {
                sendNotification($conn, 'task_assigned', "New Task: $title", $description ?: $title, [
                    'priority' => $priority,
                    'target_roles' => $toDept ? [$toDept] : [],
                    'target_user_id' => $toUserId,
                    'data' => ['task_id' => $taskId, 'related_member_id' => $relatedMemberId]
                ]);
            }
            
            return $taskId;
        }
    } catch (Exception $e) {
        error_log("createDepartmentTask error: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get pending tasks for current user/department
 */
function getPendingTasks($conn, $limit = 20) {
    try {
        $dept = $_SESSION['admin_role'] ?? '';
        $userId = $_SESSION['admin_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT dt.*, m.student_name, m.father_name, m.member_code,
                   u.full_name as from_user_name
            FROM department_tasks dt
            LEFT JOIN members m ON dt.related_member_id = m.id
            LEFT JOIN users u ON dt.from_user_id = u.id
            WHERE dt.status IN ('pending', 'in_progress')
            AND (dt.to_dept = ? OR dt.to_user_id = ?)
            ORDER BY 
                FIELD(dt.priority, 'urgent', 'high', 'normal', 'low'),
                dt.due_date ASC,
                dt.created_at DESC
            LIMIT ?
        ");
        
        if (!$stmt) return [];
        
        $stmt->bind_param("sii", $dept, $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $row['related_data'] = $row['related_data'] ? json_decode($row['related_data'], true) : null;
            $tasks[] = $row;
        }
        
        return $tasks;
    } catch (Exception $e) { return []; }
}

/**
 * Update task status
 */
function updateTaskStatus($conn, $taskId, $status, $notes = null) {
    try {
        $userId = (int)($_SESSION['admin_id'] ?? 0);
        $role = (string)($_SESSION['admin_role'] ?? '');
        if ($userId <= 0 || !in_array($status, ['pending','in_progress','completed','cancelled'], true)) return false;
        $find = $conn->prepare('SELECT id FROM department_tasks WHERE id=? AND (to_dept=? OR to_user_id=?)');
        $find->bind_param('isi', $taskId, $role, $userId); $find->execute();
        if (!$find->get_result()->fetch_assoc()) { $find->close(); return false; }
        $find->close();
        $completedAt = in_array($status, ['completed', 'cancelled']) ? date('Y-m-d H:i:s') : null;
        $completedBy = in_array($status, ['completed', 'cancelled']) ? ($_SESSION['admin_id'] ?? null) : null;
        
        $stmt = $conn->prepare("
            UPDATE department_tasks 
            SET status = ?, notes = CONCAT(IFNULL(notes, ''), ?), 
                completed_at = ?, completed_by = ?
            WHERE id = ? AND (to_dept = ? OR to_user_id = ?)
        ");
        
        if (!$stmt) return false;
        
        $noteText = $notes ? "\n[" . date('Y-m-d H:i') . "] " . ($_SESSION['admin_full_name'] ?? 'System') . ": $notes" : '';
        
        $stmt->bind_param("sssiisi", $status, $noteText, $completedAt, $completedBy, $taskId, $role, $userId);
        return $stmt->execute();
    } catch (Exception $e) { return false; }
}

// ============================================================
// AUTO-UPDATE FUNCTIONS (System Workflows)
// ============================================================

/**
 * When Education Dept assigns a teaching role, auto-update member flags
 */
function autoUpdateTeacherStatus($conn, $memberId, $isTeacher = true) {
    try {
        $flag = $isTeacher ? 1 : 0;
    
    // Get current status
    $stmt = $conn->prepare("SELECT is_teacher FROM members WHERE id = ?");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    
    if ($current && (int)$current['is_teacher'] !== $flag) {
        // Update the flag
        $stmt = $conn->prepare("UPDATE members SET is_teacher = ? WHERE id = ?");
        $stmt->bind_param("ii", $flag, $memberId);
        $stmt->execute();

        // Identity v2 convergence: mirror the flag onto the mapped
        // Teacher position and re-code (best-effort, never blocks the
        // education workflow).
        try {
            require_once __DIR__ . '/services/PositionSyncService.php';
            \App\Services\PositionSyncService::syncPositionFromFlag($conn, $memberId, 'is_teacher', (bool)$flag);
        } catch (\Throwable $e) {
            error_log('teacher position convergence skipped: ' . $e->getMessage());
        }

        // Log the change
        $summary = $isTeacher ? 'Member assigned as teacher' : 'Teacher role removed';
        logMemberChange($conn, $memberId, 'role_changed', [
            'field_changed' => 'is_teacher',
            'old_value' => $current['is_teacher'],
            'new_value' => $flag,
            'summary' => $summary
        ]);
        
        // Notify Info Dept
        $stmt = $conn->prepare("SELECT student_name, father_name FROM members WHERE id = ?");
        $stmt->bind_param("i", $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $memberName = $member['student_name'] . ' ' . $member['father_name'];
        
        sendNotification($conn, 'role_changed', 
            ($isTeacher ? "New Teacher Assigned" : "Teacher Role Removed"),
            "$memberName has been " . ($isTeacher ? "assigned as a teacher" : "removed from teaching role"),
            [
                'data' => ['member_id' => $memberId],
                'target_roles' => ['info_dept', 'school_admin']
            ]
        );
        
        return true;
    }
    
    return false;
    } catch (Exception $e) { return false; }
}

/**
 * When a member is enrolled in a class, update their class info
 */
function autoUpdateMemberClass($conn, $memberId, $classId, $academicYearId) {
    try {
    // Get class info
    $stmt = $conn->prepare("SELECT class_code, class_name FROM classes WHERE id = ?");
    $stmt->bind_param("i", $classId);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc();
    
    if (!$class) return false;
    
    // Update member
    $stmt = $conn->prepare("
        UPDATE members 
        SET current_class_id = ?, spiritual_education = ?, academic_status = 'active'
        WHERE id = ?
    ");
    $stmt->bind_param("isi", $classId, $class['class_code'], $memberId);
    $stmt->execute();
    
    // Log the change
    logMemberChange($conn, $memberId, 'class_enrolled', [
        'field_changed' => 'current_class_id',
        'new_value' => $classId,
        'summary' => "Enrolled in {$class['class_name']}"
    ]);
    
    return true;
    } catch (Exception $e) { return false; }
}

/**
 * Update attendance summary after recording attendance.
 *
 * SINGLE-WRITER NOTICE: the summary table is maintained exclusively by
 * App\Services\AttendanceSummaryService (derived-cache pattern: the row is
 * recomputed from the attendance source rows, so repeated saves are
 * idempotent and self-healing).
 *
 * The month is derived from the RECORDED attendance date — never from
 * "today" — so backfilling an older session updates that month's row
 * instead of corrupting the current month. Pass $attendanceDate whenever
 * the caller knows the record's date; it falls back to today for legacy
 * call sites.
 */
function updateAttendanceSummary($conn, $memberId, $academicYearId = null, $attendanceDate = null) {
    try {
        require_once __DIR__ . '/services/AttendanceSummaryService.php';

        $date = (is_string($attendanceDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate))
            ? $attendanceDate
            : date('Y-m-d');

        \App\Services\AttendanceSummaryService::recordSaved(
            $conn,
            (int)$memberId,
            $date,
            $academicYearId !== null ? (int)$academicYearId : null
        );
        return true;
    } catch (Exception $e) { return false; }
}

/**
 * Handle new member registration - notify all relevant departments
 */
function onMemberRegistered($conn, $memberId) {
    try {
    $stmt = $conn->prepare("SELECT student_name, father_name, member_code, registration_type FROM members WHERE id = ?");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    
    if (!$member) return false;
    
    $memberName = $member['student_name'] . ' ' . $member['father_name'];
    $regType = $member['registration_type'];
    
    // Log the change
    logMemberChange($conn, $memberId, 'registered', [
        'summary' => "New member registered: $memberName"
    ]);
    
    // Send notification
    sendNotification($conn, 'member_registered',
        "New Member Registered",
        "$memberName has been registered (Type: $regType)",
        [
            'data' => [
                'member_id' => $memberId,
                'member_code' => $member['member_code'],
                'registration_type' => $regType
            ]
        ]
    );
    
    // If direct or transfer registration, create task for Education Dept to assign class
    if ($regType === 'direct' || $regType === 'transfer') {
        createDepartmentTask($conn, "Assign class for new member: $memberName", [
            'description' => "New member $memberName needs to be assigned to a class based on their age and spiritual education level.",
            'task_type' => 'action',
            'priority' => 'normal',
            'to_dept' => 'edu_dept',
            'related_member_id' => $memberId
        ]);
    }
    
    return true;
    } catch (Exception $e) { return false; }
}

/**
 * Handle promotion of a temporary member to permanent.
 * Called from hr_register_member.php when upgrade_member_id > 0.
 */
function onMemberPromoted($conn, $memberId, $overrideReason = null) {
    try {
    $stmt = $conn->prepare("SELECT student_name, father_name, member_code, membership_tier FROM members WHERE id = ?");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();

    if (!$member) return false;

    $memberName = trim($member['student_name'] . ' ' . $member['father_name']);

    $details = ['summary' => "Member promoted to permanent: $memberName"];
    if (is_string($overrideReason) && trim($overrideReason) !== '') {
        $details['override_reason'] = trim($overrideReason);
    }

    logMemberChange($conn, $memberId, 'promoted', $details);

    sendNotification($conn, 'member_promoted',
        "Member Promoted to Permanent",
        "$memberName has been promoted to permanent member",
        [
            'data' => [
                'member_id' => $memberId,
                'member_code' => $member['member_code'],
                'override_reason' => is_string($overrideReason) ? trim($overrideReason) : null,
            ]
        ]
    );

    return true;
    } catch (Exception $e) { return false; }
}

/**
 * Handle member archival
 */
function onMemberArchived($conn, $memberId, $reason = null) {
    try {
    $stmt = $conn->prepare("SELECT student_name, father_name, member_code FROM members WHERE id = ?");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    
    if (!$member) return false;
    
    $memberName = $member['student_name'] . ' ' . $member['father_name'];
    
    logMemberChange($conn, $memberId, 'archived', [
        'summary' => "Member archived: $memberName" . ($reason ? " (Reason: $reason)" : "")
    ]);
    
    sendNotification($conn, 'member_archived',
        "Member Archived",
        "$memberName has been moved to archive" . ($reason ? " (Reason: $reason)" : ""),
        [
            'priority' => 'normal',
            'data' => ['member_id' => $memberId, 'reason' => $reason]
        ]
    );
    
    return true;
    } catch (Exception $e) { return false; }
}

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

/**
 * Get current academic year
 */
function getCurrentAcademicYear($conn) {
    // Delegates to the central resolver — the ACTIVE year (used for stamping).
    if (function_exists('ay_active_year')) return ay_active_year($conn);
    try {
        $result = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    } catch (Exception $e) { return null; }
}

/**
 * Get current term
 */
function getCurrentTerm($conn) {
    try {
        $result = $conn->query("SELECT * FROM academic_terms WHERE is_current = 1 LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    } catch (Exception $e) { return null; }
}

/**
 * Calculate grade letter from score
 */
function calculateGradeLetter($score, $maxScore = 100) {
    if ($score === null || $maxScore == 0) return null;
    
    $percentage = ($score / $maxScore) * 100;
    
    if ($percentage >= 90) return 'A';
    if ($percentage >= 80) return 'B';
    if ($percentage >= 70) return 'C';
    if ($percentage >= 60) return 'D';
    return 'F';
}

/**
 * Get department display name
 */
function getDeptDisplayName($roleCode) {
    global $DEPT_ROLES;
    return $DEPT_ROLES[$roleCode] ?? ucfirst(str_replace('_', ' ', $roleCode));
}
