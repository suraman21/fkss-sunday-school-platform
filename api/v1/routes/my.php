<?php
/**
 * School API v1 — My (the caller's own record)
 * ---------------------------------------------------------------------
 * GET /my/profile     — who the school thinks you are, plus department status
 * GET /my/attendance  — your own attendance history and summary
 * GET /my/grades      — your own assessment results
 *
 * WHY A NEW RESOURCE AND NOT A LOOSENED /members/{id}
 *   `GET /members/{id}` answers "may I see THIS member" and shapes the payload by
 *   the CALLER'S ROLE: staff get apiMemberStaffRow (phones, guardian contact),
 *   members and parents get apiMemberSafeRow (no phone, no address). A member
 *   opening their own page therefore receives the SAFE row — their own phone
 *   number is treated like somebody else's.
 *
 *   The fix is not to teach that route about selfhood. An id-taking route that
 *   also admits the member role is the IDOR the existing comment warns about,
 *   and "the phone is yours" is exactly the kind of exception that later becomes
 *   "the phone is yours unless…". So `/my/*` takes NO id at all: identity comes
 *   from the token, and the field set is decided once, here, for a member
 *   reading themselves.
 *
 * WHAT IS INCLUDED AND WHY IT IS SAFE
 *   The member's OWN phone and address. Both are on screen in the school's own
 *   records, and a student who cannot check their contact details cannot report
 *   that the office has the wrong number.
 *
 * WHAT IS EXCLUDED
 *   - Guardian contact details. A parent's phone number is the PARENT'S, and a
 *     student is not entitled to read it from this API. The family link exists;
 *     publishing it is a different decision.
 *   - recorded_by / staff ids, audit columns, internal notes.
 *   - Nothing about any other member, ever: there is no id parameter.
 *
 * SCOPE
 *   The `member` role only. Parents are NOT served here — a parent asking about
 *   a child asks with the verified-link rule in apiParentCanSeeMember, and that
 *   is a separate route with its own decision, not a widened one.
 */

$auth = apiRequireAuth();
apiRequireRole($auth, ['member']);

$selfMemberId = apiSelfMemberId($conn, $auth);
if ($selfMemberId <= 0) {
    err('Your member record is not linked to this account yet.', 403, ['code' => 'NO_MEMBER_LINK']);
}

// The router splits /my/profile into [resource, id, sub]:
//   parts[0] = 'my'  parts[1] = 'profile'  parts[2] = null
// so the action lands in the ID slot, not the sub slot. Reading 'sub' here
// (as /learning does, where the shape is /learning/lessons/{id}) silently
// matched nothing and every request fell through to 404.
//
// A numeric first segment is rejected rather than coerced: /my/12 must not
// quietly become "member 12". /my takes no id, ever.
$myAction = '';
$mySegments = $ROUTE['parts'] ?? [];
if (isset($mySegments[1]) && is_string($mySegments[1]) && $mySegments[1] !== '') {
    $mySegment = $mySegments[1];
    if (!ctype_digit($mySegment)) {
        $myAction = $mySegment;
    }
}
unset($mySegment);

// ============================================================
// GET /my/profile
// ============================================================
if ($myAction === 'profile' && $method === 'GET') {
    if (isApiRateLimited('my_profile', 90)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    $stmt = $conn->prepare(
        'SELECT id, member_code, legacy_member_code, student_name, father_name,'
        . ' grandfather_name, full_name_am, full_name_en, baptismal_name, christian_name,'
        . ' gender, date_of_birth, age, age_group, current_section, education_level,'
        . ' member_type, registration_type, status, phone_number, phone_primary,'
        . ' alt_phone_number, email, city, sub_city, woreda, mender, block_number,'
        . ' house_number, student_photo_path, is_teacher, is_staff, is_committee,'
        . ' is_volunteer, registered_at, created_at'
        . ' FROM members WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        err('Your record could not be loaded.', 503);
    }
    $stmt->bind_param('i', $selfMemberId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        // The account is linked to a register row that is gone. Fail closed
        // rather than presenting a half-record.
        err('Your member record could not be found. Contact the accounts office.', 404);
    }

    // ---- department status: "active on education? on mezmur?" -----------
    //
    // WHY Defaults ARE ACTIVE
    //   DepartmentStatusService::statusesFor() treats a member with no row as
    //   active on both, because a department row is only written when somebody
    //   is SUSPENDED from that department. A member with no row has not been
    //   suspended from anything. Inventing "inactive" for the absence of a row
    //   would tell a student they were removed from a department nobody ever
    //   removed them from.
    $departments = ['edu' => 'active', 'mezmur' => 'active'];
    $departmentAvailable = false;
    $statusSource = 'default';
    $dsPath = dirname(__DIR__, 3) . '/admin/backend/services/DepartmentStatusService.php';
    if (is_file($dsPath)) {
        require_once $dsPath;
    }
    if (class_exists('\\App\\Services\\DepartmentStatusService')) {
        $departmentAvailable = \App\Services\DepartmentStatusService::available($conn);
        if ($departmentAvailable) {
            $statuses = \App\Services\DepartmentStatusService::statuses($conn, $selfMemberId);
            foreach ($statuses as $dept => $status) {
                if (is_string($dept) && is_string($status)) {
                    $departments[$dept] = $status;
                }
            }
            $statusSource = 'recorded';
        }
    }

    ok([
        'profile' => [
            'id' => (int)$row['id'],
            'member_code' => (string)$row['member_code'],
            'full_name_am' => $row['full_name_am'],
            'full_name_en' => $row['full_name_en'],
            'student_name' => $row['student_name'],
            'father_name' => $row['father_name'],
            'grandfather_name' => $row['grandfather_name'],
            'baptismal_name' => $row['baptismal_name'],
            'christian_name' => $row['christian_name'],
            'gender' => $row['gender'],
            'date_of_birth' => $row['date_of_birth'],
            'age' => $row['age'] !== null ? (int)$row['age'] : null,
            'age_group' => $row['age_group'],
            'current_section' => $row['current_section'],
            'education_level' => $row['education_level'],
            'member_type' => $row['member_type'],
            'registration_type' => $row['registration_type'],
            'status' => (string)$row['status'],
            'contact' => [
                'phone' => $row['phone_primary'] ?? $row['phone_number'],
                'phone_alt' => $row['alt_phone_number'],
                'email' => $row['email'],
            ],
            'address' => [
                'city' => $row['city'],
                'sub_city' => $row['sub_city'],
                'woreda' => $row['woreda'],
                'mender' => $row['mender'],
                'block_number' => $row['block_number'],
                'house_number' => $row['house_number'],
            ],
            // Roles the school has recorded on the register. A student may be
            // several of these at once, so they are flags, not a single value.
            'roles' => [
                'teacher' => (int)($row['is_teacher'] ?? 0) === 1,
                'staff' => (int)($row['is_staff'] ?? 0) === 1,
                'committee' => (int)($row['is_committee'] ?? 0) === 1,
                'volunteer' => (int)($row['is_volunteer'] ?? 0) === 1,
            ],
            'photo_url' => function_exists('apiPhotoUrl')
                ? apiPhotoUrl($row['student_photo_path'] ?? null)
                : null,
            'registered_at' => $row['registered_at'] ?? $row['created_at'],
        ],
        // The direct answer to "am I active on education and mezmur?".
        'departments' => $departments,
        'departments_available' => $departmentAvailable,
        // 'recorded' = read from the department table; 'default' = the table is
        // not present yet, so nobody has been suspended from anything.
        'departments_source' => $statusSource,
    ]);
}

// ============================================================
// GET /my/attendance
// ============================================================
if ($myAction === 'attendance' && $method === 'GET') {
    if (isApiRateLimited('my_attendance', 60)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    // A window, not "everything ever": a member opening this page wants the
    // recent picture, and an unbounded history grows without limit.
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 90;
    $days = max(7, min(365, $days));
    $since = date('Y-m-d', strtotime('-' . $days . ' days'));

    $stmt = $conn->prepare(
        'SELECT attendance_date, status, check_in_time, check_out_time'
        . ' FROM attendance WHERE member_id = ? AND attendance_date >= ?'
        . ' ORDER BY attendance_date DESC LIMIT 400'
    );
    if (!$stmt) {
        err('Your attendance could not be loaded.', 503);
    }
    $stmt->bind_param('is', $selfMemberId, $since);
    $stmt->execute();
    $result = $stmt->get_result();

    $records = [];
    $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'holiday' => 0];
    while ($row = $result->fetch_assoc()) {
        $status = (string)$row['status'];
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        $records[] = [
            'date' => (string)$row['attendance_date'],
            'status' => $status,
            'check_in' => $row['check_in_time'],
            'check_out' => $row['check_out_time'],
        ];
    }
    $stmt->close();

    // The prototype's rule, and the one the school actually uses: a "late" is
    // still attended. Holidays are excluded from the denominator because a day
    // with no school is not a day a student could have attended.
    $attended = $counts['present'] + $counts['late'];
    $denominator = $counts['present'] + $counts['absent'] + $counts['late'] + $counts['excused'];

    ok([
        'window_days' => $days,
        'since' => $since,
        'summary' => [
            'present' => $counts['present'],
            'absent' => $counts['absent'],
            'late' => $counts['late'],
            'excused' => $counts['excused'],
            'holiday' => $counts['holiday'],
            'sessions' => $denominator,
            'attended' => $attended,
            // Percent is null rather than 0 when nothing is recorded. A student
            // with no records has not attended 0% of anything.
            'percent' => $denominator > 0 ? (int)round(($attended / $denominator) * 100) : null,
        ],
        'records' => $records,
    ]);
}

// ============================================================
// GET /my/grades
// ============================================================
if ($myAction === 'grades' && $method === 'GET') {
    if (isApiRateLimited('my_grades', 60)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    // academic_records is the school's assessment ledger. subject/class names
    // are joined so the app does not need three more round trips.
    $stmt = $conn->prepare(
        'SELECT ar.id, ar.assessment_type, ar.score, ar.max_score, ar.grade_letter,'
        . ' ar.remarks, ar.recorded_at, ar.academic_year_id,'
        . ' s.name AS subject_name, s.name_am AS subject_name_am,'
        . ' c.name AS class_name'
        . ' FROM academic_records ar'
        . ' LEFT JOIN subjects s ON s.id = ar.subject_id'
        . ' LEFT JOIN classes c ON c.id = ar.class_id'
        . ' WHERE ar.member_id = ?'
        . ' ORDER BY ar.recorded_at DESC, ar.id DESC LIMIT 200'
    );
    if (!$stmt) {
        // The table is deployment-owned. Absent means this database has not run
        // the schema completion migration — say so instead of pretending the
        // student has no grades.
        err('Grades are not available yet.', 409, ['code' => 'GRADES_UNAVAILABLE']);
    }
    $stmt->bind_param('i', $selfMemberId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $max = (float)($row['max_score'] ?? 0);
        $score = $row['score'] !== null ? (float)$row['score'] : null;
        $rows[] = [
            'id' => (int)$row['id'],
            'assessment_type' => (string)$row['assessment_type'],
            'subject' => $row['subject_name_am'] ?? $row['subject_name'],
            'class_name' => $row['class_name'],
            'score' => $score,
            'max_score' => $max,
            // Percent computed server-side so every client agrees, and null when
            // there is no score rather than a misleading 0.
            'percent' => ($score !== null && $max > 0) ? (int)round(($score / $max) * 100) : null,
            'grade_letter' => $row['grade_letter'],
            'remarks' => $row['remarks'],
            'recorded_at' => $row['recorded_at'],
        ];
    }
    $stmt->close();

    // Average across records that actually carry a percent. Averaging in the
    // nulls as zero would punish a student for an assessment not yet marked.
    $percents = array_values(array_filter(
        array_map(static fn(array $r): ?int => $r['percent'], $rows),
        static fn(?int $p): bool => $p !== null
    ));
    $average = $percents === [] ? null : (int)round(array_sum($percents) / count($percents));

    ok([
        'records' => $rows,
        'summary' => [
            'count' => count($rows),
            'average_percent' => $average,
        ],
    ]);
}

err('Unknown /my endpoint.', 404);
