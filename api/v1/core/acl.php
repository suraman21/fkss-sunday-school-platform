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

function apiRolesPhotoDepartment(): array
{
    return ['photo_dept', 'photo_importer'];
}

function apiRolesMobileDashboard(): array
{
    return [
        'teacher', 'attendance_taker', 'super_admin', 'school_admin',
        'edu_dept', 'info_dept', 'finance_dept', 'material_dept',
        'mezmur_dept', 'mezmur_attendance_taker', 'hr_dept',
        'hr_attendance_taker',
    ];
}

function apiRolesClassBrowser(): array
{
    return [
        'teacher', 'attendance_taker', 'super_admin', 'school_admin',
        'edu_dept', 'info_dept', 'hr_dept', 'mezmur_dept',
        'mezmur_attendance_taker', 'hr_attendance_taker',
    ];
}

function apiCanViewMemberPii(array $auth): bool
{
    return apiRoleIs($auth, apiRolesPii());
}

function apiCanBrowseMembers(array $auth): bool
{
    return apiRoleIs($auth, array_merge(
        apiRolesPii(),
        ['edu_dept'],
        apiRolesPhotoDepartment()
    ));
}

function apiCanManageMembers(array $auth): bool
{
    return apiRoleIs($auth, apiRolesPii());
}

function apiCanUploadMemberPhotos(array $auth): bool
{
    return apiRoleIs($auth, array_merge(
        apiRolesPii(),
        ['hr_dept'],
        apiRolesPhotoDepartment()
    ));
}

function apiMemberPhotoRow(array $row): array
{
    return [
        'id' => isset($row['id']) ? (int)$row['id'] : 0,
        'member_code' => $row['member_code'] ?? '',
        'student_name' => $row['student_name'] ?? '',
        'father_name' => $row['father_name'] ?? '',
        'grandfather_name' => $row['grandfather_name'] ?? '',
        'photo_url' => $row['photo_url'] ?? null,
    ];
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
        $row['photo_url'] = apiPhotoUrl(
            $row['student_photo_path'] ?? null,
            (int)($row['id'] ?? 0)
        );
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

function apiPhotoUrl(?string $path, ?int $memberId = null): ?string
{
    if (!$path) {
        return null;
    }
    if ($memberId !== null && $memberId > 0) {
        $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        return $base . '/api/v1/members/' . $memberId . '/photo';
    }
    if (defined('SITE_URL')) {
        return SITE_URL . '/' . ltrim($path, '/');
    }
    return '/' . ltrim($path, '/');
}
