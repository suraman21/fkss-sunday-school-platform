<?php
/**
 * Cross-department member role synchronization.
 *
 * Membership type (regular / special_regular / honorary) is manually selected
 * by an authorized editor. Roles, positions, teacher accounts and teaching
 * assignments must never promote or demote it. Role flags and identity codes
 * continue to synchronize independently.
 */

/**
 * Read-only compatibility snapshot for legacy synchronization callers.
 *
 * @deprecated Member types are manual; this function never changes them.
 * @param array $overrides Legacy role overrides, intentionally ignored.
 * @return array ['changed' => bool, 'old_type' => string, 'new_type' => string]
 */
function syncMemberType(mysqli $conn, int $memberId, array $overrides = []): array {
    if ($memberId <= 0) return ['changed' => false, 'old_type' => '', 'new_type' => ''];

    $stmt = $conn->prepare("SELECT member_type FROM members WHERE id = ?");
    if (!$stmt) return ['changed' => false, 'old_type' => '', 'new_type' => ''];
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member) return ['changed' => false, 'old_type' => '', 'new_type' => ''];
    $type = $member['member_type'] ?? 'regular';
    return ['changed' => false, 'old_type' => $type, 'new_type' => $type];
}

/**
 * Sync is_teacher flag on a member when a teacher account is created/deleted.
 * Member type is manual and is never changed by this operation.
 *
 * @param mysqli $conn
 * @param int    $memberId
 * @param bool   $isNowTeacher  true if teacher account was created, false if removed
 * @return array sync result
 */
function syncMemberTeacherFlag(mysqli $conn, int $memberId, bool $isNowTeacher): array {
    if ($memberId <= 0) return ['changed' => false];
    
    $flag = $isNowTeacher ? 1 : 0;
    
    // If removing teacher flag, check if there are OTHER teacher accounts pointing to this member
    if (!$isNowTeacher) {
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM users WHERE member_id = ? AND role = 'teacher' AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param("i", $memberId);
            $stmt->execute();
            $count = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            if ($count > 0) {
                // Still has active teacher accounts — keep the flag
                $flag = 1;
            }
        }
    }
    
    // Update the flag
    $stmt = $conn->prepare("UPDATE members SET is_teacher = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $flag, $memberId);
        $stmt->execute();
        $stmt->close();
    }

    // Identity v2 convergence: grant/remove the position mapped to
    // is_teacher (when the school defined one) and re-code the member so
    // flags, positions and codes never diverge. Best-effort.
    try {
        require_once __DIR__ . '/services/PositionSyncService.php';
        \App\Services\PositionSyncService::syncPositionFromFlag($conn, $memberId, 'is_teacher', (bool)$flag);
    } catch (\Throwable $e) {
        error_log('teacher position convergence skipped: ' . $e->getMessage());
    }

    // Preserve the legacy result shape without reclassifying the member.
    return syncMemberType($conn, $memberId, ['is_teacher' => $flag]);
}

/**
 * Retired automatic classification entry point. Kept as a no-op for legacy
 * callers; manual member types must never be repaired from role evidence.
 *
 * @deprecated Member types are edited explicitly, not synchronized.
 * @return array ['checked' => int, 'fixed' => int, 'details' => array]
 */
function batchSyncMemberTypes(mysqli $conn): array {
    return ['checked' => 0, 'fixed' => 0, 'details' => []];
}

/**
 * Get member role summary for display.
 * Returns a human-readable array of active roles.
 *
 * @param array $member  Member row from database
 * @return array ['roles' => ['Teacher', 'Staff'], 'is_special' => bool]
 */
function getMemberRoleSummary(array $member): array {
    $roles = [];
    if (!empty($member['is_teacher']))   $roles[] = 'Teacher';
    if (!empty($member['is_staff']))     $roles[] = 'Staff';
    if (!empty($member['is_committee'])) $roles[] = 'Committee';
    if (!empty($member['is_volunteer'])) $roles[] = 'Volunteer';
    
    return [
        'roles' => $roles,
        'is_special' => ($member['member_type'] ?? '') === 'special_regular',
        'type_label' => match($member['member_type'] ?? 'regular') {
            'special_regular' => 'Special Regular',
            'honorary' => 'Honorary',
            default => 'Regular'
        }
    ];
}
