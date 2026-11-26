<?php
/**
 * School API v1 — Members
 * GET    /members                 — paginated list (Info/Admin: +phones; Education: name/code only)
 * GET    /members/{id}            — one member (field set depends on role; parents: own verified-linked children only)
 * POST   /members                 — register (Info / School Admin / Super Admin)
 * PUT    /members/{id}            — update (same roles)
 * GET    /members/{id}/attendance — attendance history (assigned class or PII role)
 * GET    /members/{id}/department-status  — Education / Mezmur status (PII roles, HR, Edu, Mezmur)
 * POST   /members/{id}/department-status  — change it (HR / Information only; reason required)
 */

$auth = apiRequireAuth();
$id = $ROUTE['id'];
$sub = $ROUTE['sub'];
$year = getCurrentAcademicYear();
$yearId = $year ? (int)$year['id'] : 0;

// ============================================================
// GET /members — List
// ============================================================
if ($method === 'GET' && $id === null) {
    if (!apiCanBrowseMembers($auth)) {
        err('Members list is only for Information and Education staff.', 403);
    }
    if (isApiRateLimited('members_list', 60)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    list($page, $limit, $offset) = getPagination(50);
    $canPii = apiCanViewMemberPii($auth);

    $search = trim($_GET['search'] ?? '');
    $status = validateEnum($_GET['status'] ?? '', ['active', 'warning', 'inactive', 'archived'], '');
    $gender = validateEnum($_GET['gender'] ?? '', ['male', 'female'], '');
    $section = trim($_GET['section'] ?? '');
    $ageGroup = trim($_GET['age_group'] ?? '');
    $sort = validateEnum($_GET['sort'] ?? '', ['name', 'newest', 'oldest', 'code'], 'name');

    $where = ["status != 'archived'"];
    $params = [];
    $types = '';

    if ($status) { $where[] = "status = ?"; $params[] = $status; $types .= 's'; }
    if ($gender) { $where[] = "gender = ?"; $params[] = $gender; $types .= 's'; }
    if ($section) { $where[] = "current_section = ?"; $params[] = $section; $types .= 's'; }
    if ($ageGroup) { $where[] = "age_group = ?"; $params[] = $ageGroup; $types .= 's'; }
    if ($search) {
        if ($canPii) {
            $where[] = "(student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ? OR phone_number LIKE ? OR full_name_am LIKE ?)";
            $s = "%{$search}%";
            $params = array_merge($params, [$s, $s, $s, $s, $s]);
            $types .= 'sssss';
        } else {
            $where[] = "(student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ? OR full_name_am LIKE ? OR baptismal_name LIKE ?)";
            $s = "%{$search}%";
            $params = array_merge($params, [$s, $s, $s, $s, $s]);
            $types .= 'sssss';
        }
    }

    $whereSql = implode(' AND ', $where);
    $orderBy = match ($sort) {
        'newest' => 'created_at DESC',
        'oldest' => 'created_at ASC',
        'code' => 'member_code ASC',
        default => 'student_name ASC'
    };

    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM members WHERE {$whereSql}");
    if ($types) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $sql = "SELECT id, member_code, student_name, father_name, grandfather_name,
                   full_name_am, gender, age_group, status, member_type,
                   current_section, student_photo_path, created_at,
                   phone_number, phone_primary
            FROM members WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $members = [];
    while ($row = $result->fetch_assoc()) {
        $row['photo_url'] = apiPhotoUrl($row['student_photo_path'] ?? null);
        unset($row['student_photo_path']);
        $members[] = $canPii ? apiMemberStaffRow($row) : apiMemberSafeRow($row);
    }
    $stmt->close();

    paginated($members, $total, $page, $limit);
}

// ============================================================
// GET /members/{id}
// ============================================================
if ($method === 'GET' && is_int($id) && $sub === null) {
    $canPii = apiCanViewMemberPii($auth);
    $canEdu = apiRoleIs($auth, apiRolesEducation());
    $canTeacher = apiIsClassRestricted($auth);
    $canParent = apiRoleIs($auth, ['parent']);

    if (!$canPii && !$canEdu && !$canTeacher && !$canParent) {
        err('You cannot open member records.', 403);
    }
    if ($canTeacher && !$canPii && !$canEdu && !apiTeacherCanSeeMember($conn, $auth, $id, $yearId)) {
        err('You can only open students in your assigned classes.', 403);
    }
    if ($canParent && !$canPii && !$canEdu && !$canTeacher && !apiParentCanSeeMember($conn, $auth, $id)) {
        err('You can only open your own linked children.', 403);
    }

    $stmt = $conn->prepare(
        "SELECT id, member_code, student_name, father_name, grandfather_name, full_name_am,
                gender, date_of_birth, age_group, status, member_type, current_section,
                education_level, registration_type, baptismal_name, christian_name,
                phone_number, phone_primary, alt_phone_number,
                guardian_name, guardian_phone1, guardian_phone2,
                address, city, sub_city, woreda, student_photo_path, created_at
         FROM members WHERE id = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member) err('Member not found', 404);

    $member['photo_url'] = apiPhotoUrl($member['student_photo_path'] ?? null);
    unset($member['student_photo_path']);

    try {
        if ($year) {
            $stmt = $conn->prepare(
                "SELECT c.class_name, c.class_name_en, c.class_code, ce.enrolled_at, ce.status
                 FROM class_enrollments ce JOIN classes c ON ce.class_id = c.id
                 WHERE ce.member_id = ? AND ce.academic_year_id = ? AND ce.status = 'active' LIMIT 1"
            );
            $stmt->bind_param('ii', $id, $year['id']);
            $stmt->execute();
            $member['current_class'] = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    } catch (Exception $e) {
        $member['current_class'] = null;
    }

    ok($canPii ? apiMemberStaffRow($member) : apiMemberSafeRow($member));
}

// ============================================================
// GET /members/{id}/attendance
// ============================================================
if ($method === 'GET' && is_int($id) && $sub === 'attendance') {
    $canPii = apiCanViewMemberPii($auth);
    $canEdu = apiRoleIs($auth, apiRolesEducation());
    $canTeacher = apiIsClassRestricted($auth);
    // A parent may read the attendance of their own VERIFIED children —
    // the same resolver the member-record branch above uses. Without
    // this clause a parent could open /members/{id} but not that
    // member's attendance, which is the one screen a parent app shows
    // first. apiParentCanSeeMember is deny-by-default.
    $canParent = apiRoleIs($auth, ['parent']);
    if (!$canPii && !$canEdu
        && !($canTeacher && apiTeacherCanSeeMember($conn, $auth, $id, $yearId))
        && !($canParent && apiParentCanSeeMember($conn, $auth, $id))) {
        err('You cannot view this attendance history.', 403);
    }

    $days = min(365, max(7, (int)($_GET['days'] ?? 90)));
    $since = date('Y-m-d', strtotime("-{$days} days"));

    $stmt = $conn->prepare(
        "SELECT attendance_date, status, notes, check_in_time
         FROM attendance WHERE member_id = ? AND attendance_date >= ?
         ORDER BY attendance_date DESC"
    );
    $stmt->bind_param('is', $id, $since);
    $stmt->execute();
    $records = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $records[] = $row;
    $stmt->close();

    $total = count($records);
    $present = count(array_filter($records, fn($r) => $r['status'] === 'present'));
    $absent = count(array_filter($records, fn($r) => $r['status'] === 'absent'));
    $late = count(array_filter($records, fn($r) => $r['status'] === 'late'));
    $rate = $total > 0 ? round($present / $total * 100, 1) : 0;

    ok([
        'member_id' => $id,
        'days_covered' => $days,
        'stats' => [
            'total_days' => $total,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'attendance_rate' => $rate
        ],
        'records' => $records
    ]);
}

// ============================================================
// POST /members — intentionally NOT supported.
// Member registration is a web-dashboard-only HR workflow (Family Link
// guardian picker/auto-link lives there). Mobile clients may read and edit
// members but never create them. Falls through to the 404 handler below.
// ============================================================

// ============================================================
// PUT /members/{id}
// ============================================================
if ($method === 'PUT' && is_int($id)) {
    apiRequireRole($auth, apiRolesPii());

    $stmt = $conn->prepare("SELECT id, status, guardian_name, guardian_phone1, guardian_phone2, student_name, father_name, grandfather_name FROM members WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $__memberBefore = $stmt->get_result()->fetch_assoc();
    if (!$__memberBefore) err('Member not found', 404);
    $stmt->close();

    $input = getBody();

    // members.status is a manual HR / Information decision (attendance
    // alerting requirements §4): only those roles may change it, only to
    // active or inactive, never on an archived member. Anyone else sending
    // "status" has the field silently ignored (other fields still apply).
    $__statusChange = null;
    $__statusStripped = false;
    if (array_key_exists('status', $input)) {
        $__requested = is_string($input['status']) ? strtolower(trim($input['status'])) : '';
        $__current = (string)($__memberBefore['status'] ?? 'active');
        $__mayChange = apiRoleIs($auth, ['hr_dept', 'info_dept'])
            && in_array($__requested, ['active', 'inactive'], true)
            && $__current !== 'archived';
        if ($__mayChange && $__requested !== $__current) {
            $input['status'] = $__requested;
            $__statusChange = ['from' => $__current, 'to' => $__requested];
        } else {
            unset($input['status']);
            $__statusStripped = true;
        }
    }
    if (array_key_exists('christian_name', $input) && !array_key_exists('baptismal_name', $input)) {
        $input['baptismal_name'] = $input['christian_name'];
    }

    $allowedFields = [
        'student_name' => 's', 'father_name' => 's', 'grandfather_name' => 's',
        'full_name_en' => 's', 'baptismal_name' => 's',
        'gender' => 's', 'date_of_birth' => 's', 'age_group' => 's',
        'current_section' => 's', 'education_level' => 's', 'member_type' => 's',
        'status' => 's', 'phone_number' => 's', 'phone_primary' => 's',
        'alt_phone_number' => 's', 'guardian_name' => 's', 'guardian_phone1' => 's',
        'guardian_phone2' => 's', 'address' => 's', 'city' => 's', 'sub_city' => 's',
        'woreda' => 's', 'work_profession' => 's', 'emergency_name' => 's',
        'emergency_phone' => 's',
        'dob_ec_day' => 'i', 'dob_ec_month' => 'i', 'dob_ec_year' => 'i',
    ];

    $sets = [];
    $params = [];
    $types = '';

    foreach ($allowedFields as $field => $type) {
        if (array_key_exists($field, $input)) {
            $val = $input[$field];
            if ($val === '' || $val === null) $val = null;
            $sets[] = "`{$field}` = ?";
            $params[] = $val;
            $types .= $type;
        }
    }

    if (isset($input['student_name']) || isset($input['father_name']) || isset($input['grandfather_name'])) {
        // Audit 2026-10-20 (A12): a partial update (e.g. only father_name)
        // must not wipe the other name parts out of full_name_am — fall back
        // to the stored values for every part the payload did not send.
        $sn = trim((string)($input['student_name'] ?? ($__memberBefore['student_name'] ?? '')));
        $fn = trim((string)($input['father_name'] ?? ($__memberBefore['father_name'] ?? '')));
        $gn = trim((string)($input['grandfather_name'] ?? ($__memberBefore['grandfather_name'] ?? '')));
        if ($sn || $fn) {
            $sets[] = "full_name_am = ?";
            $params[] = trim("{$sn} {$fn} {$gn}");
            $types .= 's';
        }
    }

    if (empty($sets)) {
        if ($__statusStripped) {
            err('Member status can only be set to active or inactive by HR or the Information department.', 403);
        }
        err('No fields to update. Send at least one field.');
    }

    $params[] = $id;
    $types .= 'i';

    $sql = "UPDATE members SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        reportInternalError('API member statement preparation failed', $conn->error);
        err('Member storage is temporarily unavailable.', 500);
    }
    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        reportInternalError('API member update failed', $stmt->error);
        err('Unable to update the member.', 500);
    }
    $stmt->close();

    logApiAction($auth['uid'], $auth['usr'], 'Member Updated', "ID: {$id}");
    if ($__statusChange !== null) {
        logApiAction($auth['uid'], $auth['usr'], 'Member Status Changed', "ID: {$id} {$__statusChange['from']} -> {$__statusChange['to']} (mobile)");
    }

    // Family Link reverse-sync: guardian contact edits from the mobile app
    // flow into the linked primary guardian so every sibling follows
    // (guardian row = single source of truth). Never fatal for the update.
    try {
        $__gFields = ['guardian_name', 'guardian_phone1', 'guardian_phone2'];
        $__gChanged = false;
        foreach ($__gFields as $__gf) {
            if (array_key_exists($__gf, $input)
                && trim((string)$input[$__gf]) !== trim((string)($__memberBefore[$__gf] ?? ''))) {
                $__gChanged = true;
                break;
            }
        }
        if ($__gChanged) {
            $__gStmt = $conn->prepare('SELECT mg.guardian_id, g.display_name FROM member_guardians mg JOIN guardians g ON g.id = mg.guardian_id WHERE mg.member_id = ? AND mg.is_primary = 1 LIMIT 1');
            $__gGid = 0;
            $__gCurrentName = '';
            if ($__gStmt) {
                $__gStmt->bind_param('i', $id);
                $__gStmt->execute();
                $__gRow = $__gStmt->get_result()->fetch_assoc();
                $__gStmt->close();
                $__gGid = $__gRow ? (int)$__gRow['guardian_id'] : 0;
                $__gCurrentName = $__gRow ? (string)$__gRow['display_name'] : '';
            }
            // Name precedence: payload -> legacy column -> guardian row (phone-only edits).
            $__gName = trim((string)($input['guardian_name'] ?? ''));
            if ($__gName === '') {
                $__gName = trim((string)($__memberBefore['guardian_name'] ?? ''));
            }
            if ($__gName === '') {
                $__gName = $__gCurrentName;
            }
            require_once dirname(__DIR__, 3) . '/admin/backend/services/GuardianService.php';
            if ($__gGid === 0) {
                // No guardian linked yet: match-or-create from the saved legacy columns.
                \App\Services\GuardianService::attachFromLegacy($conn, (int)$id, 0, 'hr');
            } elseif ($__gName !== '') {
                // Only fields present in the payload are pushed; the guardian
                // row keeps its current values for everything else.
                $__gData = ['display_name' => $__gName];
                if (array_key_exists('guardian_phone1', $input)) {
                    $__gData['phone_primary'] = trim((string)$input['guardian_phone1']);
                }
                if (array_key_exists('guardian_phone2', $input)) {
                    $__gData['phone_alt'] = trim((string)$input['guardian_phone2']);
                }
                \App\Services\GuardianService::update($conn, $__gGid, $__gData);
            }
        }
    } catch (\Throwable $__gError) {
        error_log('Family Link API reverse-sync failed for member ' . $id . ': ' . $__gError->getMessage());
    }

    ok(['message' => 'Member updated successfully', 'id' => $id]);
}

// ============================================================
// GET|POST /members/{id}/department-status  (Attendance alerting — Phase 1)
// ============================================================
if (is_int($id) && $sub === 'department-status' && ($method === 'GET' || $method === 'POST')) {
    require_once dirname(__DIR__, 3) . '/admin/backend/services/DepartmentStatusService.php';
    $dsWriteRoles = ['hr_dept', 'info_dept'];
    $dsReadRoles = array_merge(apiRolesPii(), ['hr_dept', 'edu_dept', 'mezmur_dept']);
    if (!apiRoleIs($auth, $dsReadRoles)) {
        err('You cannot view department status.', 403);
    }
    $__exists = $conn->prepare('SELECT id FROM members WHERE id = ? LIMIT 1');
    $__exists->bind_param('i', $id);
    $__exists->execute();
    $__found = $__exists->get_result()->fetch_assoc();
    $__exists->close();
    if (!$__found) err('Member not found', 404);

    $available = \App\Services\DepartmentStatusService::available($conn);
    if ($method === 'GET') {
        ok([
            'available' => $available,
            'can_change' => $available && apiRoleIs($auth, $dsWriteRoles),
            'member_id' => $id,
            'statuses' => \App\Services\DepartmentStatusService::statuses($conn, $id),
            'history' => $available ? \App\Services\DepartmentStatusService::history($conn, $id, null, 50) : [],
        ]);
    }
    if (!apiRoleIs($auth, $dsWriteRoles)) {
        err('Only HR and Information may change a department status.', 403);
    }
    if (!$available) {
        err('Department status is not available yet.', 409, ['available' => false]);
    }
    $input = getBody();
    $dept = is_string($input['department'] ?? null) ? trim($input['department']) : '';
    $status = is_string($input['status'] ?? null) ? strtolower(trim($input['status'])) : '';
    $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
    if (!\App\Services\DepartmentStatusService::isDepartment($dept)) err('department must be edu or mezmur.');
    if (!in_array($status, ['active', 'inactive'], true)) err('status must be active or inactive.');
    if (mb_strlen($reason) < 3) err('A reason (at least 3 characters) is required.');
    try {
        $actor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
            (int)$auth['uid'],
            (string)($auth['usr'] ?? ''),
            'api',
            $_SERVER
        );
        $result = \App\Services\DepartmentStatusService::setStatus(
            $conn, $id, $dept, $status, (int)$auth['uid'], $reason, 'manual', null, $actor
        );
    } catch (\DomainException $e) {
        // Domain messages are user-facing validation text (no internals).
        $__dsMessage = $e->getMessage();
        err($__dsMessage, 422);
    } catch (\Throwable $e) {
        reportInternalError('Department status change failed', $e->getMessage());
        err('Unable to change the department status right now.', 500);
    }
    logApiAction($auth['uid'], $auth['usr'], 'Department Status Changed', "ID: {$id} {$dept} {$result['previous']} -> {$result['status']}");
    if (!empty($result['changed']) && $status === 'active' && class_exists('\\App\\Services\\AttendanceWarningService')) {
        // Phase 2: reactivation re-evaluates (fresh period, stale data cleared).
        \App\Services\AttendanceWarningService::safeEvaluate($conn, $dept, $id, ['actor' => (int)$auth['uid']]);
    }
    ok([
        'available' => true,
        'changed' => $result['changed'],
        'message' => $result['changed'] ? ucfirst($dept === 'edu' ? 'Education' : 'Mezmur') . " status set to {$status}." : ($result['message'] ?? 'No change.'),
        'statuses' => \App\Services\DepartmentStatusService::statuses($conn, $id),
    ]);
}

// ============================================================
// GET /members/{id}/attendance-risk — the shared risk object per department
// (Attendance alerting Phase 2). Replaces client-side colouring. Roles:
// HR / Info / Edu / Mezmur / admins (department-scoped), teachers and
// attendance takers for their own rosters are served through the sheet.
// ============================================================
if ($method === 'GET' && is_int($id) && $sub === 'attendance-risk') {
    require_once dirname(__DIR__, 3) . '/admin/backend/services/AttendanceWarningService.php';
    require_once dirname(__DIR__, 3) . '/admin/backend/services/AttendanceRiskService.php';
    if (!class_exists('\\App\\Services\\AttendanceWarningService')) {
        err('Attendance risk is temporarily unavailable.', 503);
    }
    $arRole = apiRoleOf($auth);
    $arDepartments = \App\Services\AttendanceWarningService::departmentsForRole($arRole);
    if ($arDepartments === []) {
        err('You cannot view attendance risk.', 403);
    }
    $__exists = $conn->prepare('SELECT id FROM members WHERE id = ? LIMIT 1');
    $__exists->bind_param('i', $id);
    $__exists->execute();
    $__found = $__exists->get_result()->fetch_assoc();
    $__exists->close();
    if (!$__found) err('Member not found', 404);
    $req = trim((string)($_GET['department'] ?? ''));
    if ($req !== '' && $req !== 'all') {
        if (!in_array($req, $arDepartments, true)) err('You cannot view that department.', 403);
        $arDepartments = [$req];
    }
    $risk = [];
    foreach ($arDepartments as $d) {
        try {
            $risk[$d] = \App\Services\AttendanceWarningService::decorate(
                $conn,
                \App\Services\AttendanceRiskService::riskFor($conn, $d, $id)
            );
        } catch (\Throwable $e) {
            reportInternalError('Attendance risk failed', $e->getMessage());
            $risk[$d] = null;
        }
    }
    ok([
        'available' => \App\Services\AttendanceWarningService::available($conn),
        'member_id' => $id,
        'departments' => $arDepartments,
        'risk' => $risk,
    ]);
}


// ============================================================
// POST /members/{id}/photo  — mobile offline photo upload (P3)
// ============================================================
if ($method === 'POST' && is_int($id) && $sub === 'photo') {
    require_once __DIR__ . '/../../../admin/backend/services/PhotoUploadService.php';
    require_once __DIR__ . '/../../../admin/backend/services/PhotoDevicePairing.php';

    if (!apiCanManageMembers($auth)) {
        err('Only Information, HR, or Admin staff can upload member photos.', 403,
            ['code' => 'FORBIDDEN']);
    }
    // Bulk-photo workflow: one authenticated request per photo; a school
    // intake run is thousands of photos, often behind one NAT'd IP.
    // 120/min/IP with client 429+Retry-After backoff keeps abuse bounded
    // while letting legit bulk syncs finish the same day.
    if (isApiRateLimited('member_photo_upload', 120)) {
        err('Too many photo uploads. Please wait a moment.', 429);
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headers)) $headers = [];
    $header = function (string $name) use ($headers): string {
        $try = [
            $name,
            strtolower($name),
            str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower($name)))),
            'HTTP_' . strtoupper(str_replace('-', '_', $name)),
        ];
        foreach ($try as $k) {
            if (isset($headers[$k]) && is_string($headers[$k])) return trim($headers[$k]);
        }
        // Fall back to $_SERVER for $_SERVER['HTTP_*']
        $srvKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$srvKey]) && is_string($_SERVER[$srvKey])) {
            return trim($_SERVER[$srvKey]);
        }
        return '';
    };

    $deviceId = $header('X-Photo-Device-Id');
    $operatorId = (int)($auth['uid'] ?? 0);

    // P7 pairing: look up or create the per-device HMAC key for this
    // (operator, device_id) pair. We do this BEFORE ingest so the key
    // header is returned even for replays (the client needs it on
    // every response until it acknowledges storage).
    $pairingKey = null;
    $isNewPairing = false;
    if ($deviceId !== '' && $operatorId > 0) {
        $pairingKey = \App\Services\PhotoDevicePairing::getOrCreateKey(
            $conn, $operatorId, $deviceId, $isNewPairing);
        if ($pairingKey !== null) {
            header('X-Photo-Pairing-Key: ' . $pairingKey);
            header('X-Photo-Pairing-New: ' . ($isNewPairing ? '1' : '0'));
        }
    }

    $result = \App\Services\PhotoUploadService::ingest($conn, [
        'member_id'         => (int)$id,
        'operator_admin_id' => $operatorId,
        'device_id'         => $deviceId,
        'client_op_id'      => $header('Idempotency-Key'),
        'field'             => strtolower($header('X-Photo-Field') ?: 'student_photo'),
        'sha256'            => strtolower($header('X-Photo-SHA256')),
        'taken_at'          => $header('X-Photo-Taken-At'),
        'width'             => (int)$header('X-Photo-Width'),
        'height'            => (int)$header('X-Photo-Height'),
        'format'            => strtolower($header('X-Photo-Format') ?: 'jpeg'),
        'file_field'        => 'photo',
    ]);

    http_response_code((int)$result['status']);
    header('Content-Type: application/json; charset=utf-8');
    if (empty($result['ok'])) {
        $payload = [
            'status'  => 'error',
            'message' => $result['error'],
            'code'    => $result['code'],
        ];
        if (!empty($result['extra'])) {
            $payload = array_merge($payload, $result['extra']);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $responsePayload = [
        'status'   => 'success',
        'path'     => $result['path'],
        'replayed' => !empty($result['replayed']),
        'conflict' => !empty($result['conflict']),
    ];
    // Echo pairing key in body too, for HTTP clients that don't read
    // response headers reliably.
    if ($pairingKey !== null) {
        $responsePayload['photo_hmac_key'] = $pairingKey;
        $responsePayload['photo_pairing_new'] = $isNewPairing;
    }
    echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE);
    exit;
}

err("No handler for {$method} /members" . ($id ? "/{$id}" : '') . ($sub ? "/{$sub}" : ''), 404);
