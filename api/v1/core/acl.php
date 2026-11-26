<?php
/**
 * School API v1 — Access control and member-field shaping.
 *
 * Security lives here, not in the Flutter screens. Every route should
 * call these helpers so a future UI rewrite cannot leak Sunday-school PII.
 */

function apiRoleOf(array $auth): string
{
    return (string)($auth['rol'] ?? '');
}

function apiRoleIs(array $auth, $roles): bool
{
    return in_array(apiRoleOf($auth), (array)$roles, true);
}

/**
 * Every role a `users.role` row may hold. Staff only: this is the
 * staff-side catalogue, matching $validRoles in
 * admin/backend/user-save.php (the creation whitelist).
 *
 * member and parent are deliberately ABSENT. Those two roles are
 * provisioned only by approved ACM activation tickets — never by
 * staff user creation — so they are out of scope for staff school
 * data. Where a route must serve staff but not members/parents, gate
 * it with apiRequireStaffRole() below rather than hand-writing a role
 * list: a hardcoded list inside one route is how a future role gets a
 * silent 403 nobody notices.
 */
function apiRolesStaff(): array
{
    return [
        'super_admin', 'school_admin', 'info_dept', 'hr_dept',
        'photo_importer', 'photo_dept', 'edu_dept', 'finance_dept',
        'material_dept', 'mezmur_dept', 'teacher', 'attendance_taker',
        'content_editor', 'mezmur_attendance_taker',
        'hr_attendance_taker', 'accounts_office',
    ];
}

/**
 * Deny-by-default gate for endpoints that are staff-only by nature
 * (school-wide dashboards, class rosters, staff communications).
 *
 * Deliberately an ALLOW-list, not a deny-list. A deny-list naming the
 * two roles we know about today would silently admit every future
 * non-staff role; an allow-list fails closed for anything new.
 *
 * Audit note (2026-11-26): /classes, /dashboard/* and /notifications/*
 * previously had no staff gate at all, so a provisioned member or
 * parent token reached school-wide data. Those three now call this.
 */
function apiRequireStaffRole(array $auth): void
{
    if (!apiRoleIs($auth, apiRolesStaff())) {
        err('This endpoint is not available for this role.', 403);
    }
}

/** Info / school / super admin — the only roles that may see phones and addresses. */
function apiRolesPii(): array
{
    return ['info_dept', 'school_admin', 'super_admin'];
}

function apiRolesEducation(): array
{
    return ['edu_dept', 'school_admin', 'super_admin'];
}

function apiRolesAttendance(): array
{
    return ['teacher', 'attendance_taker', 'edu_dept', 'school_admin', 'super_admin'];
}

function apiRolesGrades(): array
{
    return ['teacher', 'edu_dept', 'school_admin', 'super_admin'];
}

function apiCanViewMemberPii(array $auth): bool
{
    return apiRoleIs($auth, apiRolesPii());
}

function apiCanBrowseMembers(array $auth): bool
{
    return apiRoleIs($auth, array_merge(apiRolesPii(), ['edu_dept']));
}

function apiCanManageMembers(array $auth): bool
{
    return apiRoleIs($auth, apiRolesPii());
}

function apiIsClassRestricted(array $auth): bool
{
    return apiRoleIs($auth, ['teacher', 'attendance_taker']);
}

function apiUserAssignedToClass($conn, int $userId, int $classId, int $yearId): bool
{
    if ($userId <= 0 || $classId <= 0) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT id FROM teacher_assignments
         WHERE teacher_id = ? AND class_id = ?
           AND is_active = 1
           AND (academic_year_id IS NULL OR academic_year_id = ?)
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $userId, $classId, $yearId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function apiRequireClassAccess($conn, array $auth, int $classId, int $yearId): void
{
    if (!apiIsClassRestricted($auth)) {
        return;
    }
    if (!apiUserAssignedToClass($conn, (int)$auth['uid'], $classId, $yearId)) {
        err('You are not assigned to this class.', 403);
    }
}

function apiTeacherCanSeeMember($conn, array $auth, int $memberId, int $yearId): bool
{
    $uid = (int)($auth['uid'] ?? 0);
    if ($uid <= 0 || $memberId <= 0) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT 1
         FROM class_enrollments ce
         JOIN teacher_assignments ta
           ON ta.class_id = ce.class_id
          AND ta.teacher_id = ?
          AND ta.is_active = 1
          AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
         WHERE ce.member_id = ?
           AND ce.status = 'active'
           AND ce.academic_year_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiii', $uid, $yearId, $memberId, $yearId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/**
 * A parent account's guardian record. 0 for non-parents, for accounts
 * whose ACM link is not active, and on pre-079 databases (deny by
 * default: no guardian, no children, never an error to the client).
 */
function apiParentGuardianId($conn, array $auth): int
{
    if (!apiRoleIs($auth, ['parent'])) {
        return 0;
    }
    $uid = (int)($auth['uid'] ?? 0);
    if ($uid <= 0) {
        return 0;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT p.guardian_id FROM acm_person_accounts pa"
            . " JOIN acm_persons p ON p.id = pa.person_id"
            . " WHERE pa.user_id = ? AND pa.account_type = 'parent'"
            . " AND pa.status = 'active' AND p.guardian_id IS NOT NULL LIMIT 1"
        );
    } catch (\mysqli_sql_exception $e) {
        return 0;
    }
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['guardian_id'] : 0;
}

/**
 * True when this user is in the middle of the ACM activation ceremony:
 * provisioned, credentials set, but the account is not yet `is_active`
 * because no device has been registered.
 *
 * Why this exists: between `establishCredentials` and device registration
 * a member/parent account has `users.is_active = 0`, exactly like a
 * deliberately disabled account. Login therefore answered "Your account
 * is inactive. Contact an administrator." — correct security, wrong
 * guidance. The holder had done nothing wrong and the office has nothing
 * to fix; they need to finish the ceremony on their device.
 *
 * Read-only and status-only: it returns a boolean, never a username, an
 * account id, or any ACM detail. That keeps the login response free of
 * role/account enumeration while still letting the app show the right
 * next step.
 *
 * Deny-safe: any missing table, missing row, or SQL error returns false,
 * so a pre-ACM database degrades to the existing generic message.
 */
function apiAccountPendingActivation($conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT 1 FROM acm_person_accounts"
            . " WHERE user_id = ? AND status = 'pending' LIMIT 1"
        );
    } catch (\mysqli_sql_exception $e) {
        return false;
    }
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $pending = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $pending;
}

/**
 * True when the caller's guardian has a VERIFIED link to this member
 * (G2 office stamp). Mirrors apiTeacherCanSeeMember's scoping pattern.
 */
function apiParentCanSeeMember($conn, array $auth, int $memberId): bool
{
    $gid = apiParentGuardianId($conn, $auth);
    if ($gid <= 0 || $memberId <= 0) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT 1 FROM member_guardians WHERE guardian_id = ? AND member_id = ?"
        . " AND verified_by IS NOT NULL LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $gid, $memberId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/** The caller's verified-linked, non-archived children, safe-row shaped. */
function apiParentChildren($conn, array $auth): array
{
    $gid = apiParentGuardianId($conn, $auth);
    if ($gid <= 0) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT m.id, m.member_code, m.student_name, m.father_name, m.grandfather_name,"
        . " m.full_name_am, m.gender, m.age_group, m.status, m.member_type,"
        . " m.current_section, m.student_photo_path, m.created_at,"
        . " mg.relation, mg.is_primary, mg.verified_at"
        . " FROM member_guardians mg JOIN members m ON m.id = mg.member_id"
        . " WHERE mg.guardian_id = ? AND mg.verified_by IS NOT NULL"
        . " AND m.status != 'archived' ORDER BY m.student_name LIMIT 100"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $gid);
    $stmt->execute();
    $out = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['photo_url'] = apiPhotoUrl($row['student_photo_path'] ?? null);
        unset($row['student_photo_path']);
        $child = apiMemberSafeRow($row);
        $child['relation'] = $row['relation'] ?? 'other';
        $child['is_primary'] = (int)($row['is_primary'] ?? 0);
        $child['verified_at'] = $row['verified_at'] ?? null;
        $out[] = $child;
    }
    $stmt->close();
    return $out;
}

/** Name, code, class — never phones or home address. */
function apiMemberSafeRow(array $row): array
{
    $out = [
        'id' => isset($row['id']) ? (int)$row['id'] : 0,
        'member_code' => $row['member_code'] ?? '',
        'student_name' => $row['student_name'] ?? '',
        'father_name' => $row['father_name'] ?? '',
        'grandfather_name' => $row['grandfather_name'] ?? '',
        'full_name_am' => $row['full_name_am'] ?? null,
        'gender' => $row['gender'] ?? '',
        'age_group' => $row['age_group'] ?? null,
        'status' => $row['status'] ?? '',
        'member_type' => $row['member_type'] ?? null,
        'current_section' => $row['current_section'] ?? null,
        'photo_url' => $row['photo_url'] ?? null,
        'created_at' => $row['created_at'] ?? null,
    ];
    if (array_key_exists('current_class', $row)) {
        $out['current_class'] = $row['current_class'];
    }
    return $out;
}

/** Extra fields for Info / School Admin only. */
function apiMemberStaffRow(array $row): array
{
    $out = apiMemberSafeRow($row);
    $out['phone_number'] = $row['phone_number'] ?? null;
    $out['phone_primary'] = $row['phone_primary'] ?? null;
    $out['alt_phone_number'] = $row['alt_phone_number'] ?? null;
    $out['guardian_name'] = $row['guardian_name'] ?? null;
    $out['guardian_phone1'] = $row['guardian_phone1'] ?? null;
    $out['guardian_phone2'] = $row['guardian_phone2'] ?? null;
    $out['address'] = $row['address'] ?? null;
    $out['city'] = $row['city'] ?? null;
    $out['sub_city'] = $row['sub_city'] ?? null;
    $out['woreda'] = $row['woreda'] ?? null;
    $out['date_of_birth'] = $row['date_of_birth'] ?? null;
    $out['baptismal_name'] = $row['baptismal_name'] ?? ($row['christian_name'] ?? null);
    $out['education_level'] = $row['education_level'] ?? null;
    $out['registration_type'] = $row['registration_type'] ?? null;
    return $out;
}

function apiRosterStudentRow(array $row): array
{
    return [
        'id' => isset($row['id']) ? (int)$row['id'] : (isset($row['member_id']) ? (int)$row['member_id'] : 0),
        'member_id' => isset($row['member_id']) ? (int)$row['member_id'] : (isset($row['id']) ? (int)$row['id'] : 0),
        'member_code' => $row['member_code'] ?? '',
        'student_name' => $row['student_name'] ?? '',
        'father_name' => $row['father_name'] ?? '',
        'gender' => $row['gender'] ?? '',
        'age_group' => $row['age_group'] ?? null,
        'status' => $row['status'] ?? null,
        'photo_url' => $row['photo_url'] ?? null,
    ];
}

function apiPhotoUrl(?string $path): ?string
{
    if (!$path) {
        return null;
    }
    if (defined('SITE_URL')) {
        return SITE_URL . '/' . ltrim($path, '/');
    }
    return '/' . ltrim($path, '/');
}
