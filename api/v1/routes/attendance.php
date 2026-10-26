<?php
/**
 * School API v1 — Attendance
 * GET  /attendance?class_id=&date=   — sheet for one class/day
 * POST /attendance                   — save (transaction + class assignment)
 * GET  /attendance/daily-stats
 * GET  /attendance/summary
 */

$auth = apiRequireAuth();

// /attendance/warnings[/{id}[/close]] — department attendance warnings
// (Phase 2). Its own role set (HR / Info / Edu / Mezmur / admins read;
// department + HR + Info close) — handled before the taker/teacher gate.
if (($ROUTE['id'] ?? '') === 'warnings') {
    require __DIR__ . '/attendance_warnings.php';
    exit;
}

if (!apiRoleIs($auth, apiRolesAttendance())) {
    err('You cannot take or view attendance.', 403);
}

$action = $ROUTE['id'] ?? '';
$year = getCurrentAcademicYear();
$yearId = $year ? (int)$year['id'] : 0;

/**
 * Resolve and validate an explicit sheet against the current server roster.
 * Draft saves pass $requireComplete = false: a partial sheet is a normal
 * workflow state (the mobile app's instant autosave persists partial
 * drafts), so it must not be rejected as an error — only unknown students
 * are. Submits pass true and must carry the complete roster. This protects
 * mobile and future integrations from stale/partial payloads and keeps the
 * HTTP adapter separate from domain validation.
 *
 * @param array<int,mixed> $records
 * @return array<int,array{member_id:int,status:string,note:string}>
 */
if (!class_exists('\\App\\Services\\LessonAttendanceService')) {
    $lessonServicePath = dirname(__DIR__, 3) . '/admin/backend/services/LessonAttendanceService.php';
    if (is_file($lessonServicePath)) {
        require_once $lessonServicePath;
    }
}

if (!class_exists('\\App\\Services\\SchoolCalendarService')) {
    $calendarServicePath = dirname(__DIR__, 3) . '/admin/backend/services/SchoolCalendarService.php';
    if (is_file($calendarServicePath)) {
        require_once $calendarServicePath;
    }
}

/**
 * Class-day-only attendance (owner decisions 2026-10-20): a sheet may only be
 * saved for a class day of that class — inside the 45-day backdate window,
 * not a Day Off / holiday / unconfigured class. The UI gets the same verdict
 * from the 'date_status' fields so it can explain before anyone taps save.
 */
function apiAssertClassDay(\mysqli $conn, int $classId, string $date): void {
    if (!class_exists('\\App\\Services\\SchoolCalendarService')) {
        return; // graceful: service unavailable means the pre-calendar behaviour
    }
    $st = \App\Services\SchoolCalendarService::classDayStatus($conn, $classId, $date);
    if (empty($st['ok'])) {
        err($st['detail'] !== '' ? $st['detail'] : 'Attendance cannot be recorded for this date.',
            422, ['code' => $st['code'], 'date_status' => $st]);
    }
}

/**
 * Classes in the phone's attendance list — the same rules as GET /classes:
 * teachers / attendance takers get their active assignments, other
 * attendance roles get every active class.
 *
 * @return int[]
 */
function apiAttendanceClassIds(\mysqli $conn, array $auth, int $yearId): array {
    if (apiIsClassRestricted($auth)) {
        $stmt = $conn->prepare(
            'SELECT DISTINCT ta.class_id FROM teacher_assignments ta
               JOIN classes c ON c.id = ta.class_id
              WHERE ta.teacher_id = ? AND ta.is_active = 1
                AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
              ORDER BY ta.class_id'
        );
        if (!$stmt) {
            return [];
        }
        $uid = (int)$auth['uid'];
        $stmt->bind_param('ii', $uid, $yearId);
    } else {
        $stmt = $conn->prepare('SELECT id FROM classes WHERE is_active = 1 ORDER BY id');
        if (!$stmt) {
            return [];
        }
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_row()) {
        $ids[] = (int)$row[0];
    }
    $stmt->close();
    return $ids;
}

/** ETag compare that tolerates W/ and the "-gzip" suffix Apache's mod_deflate adds. */
function apiEtagMatches(string $etag, string $ifNoneMatch): bool {
    $norm = static function (string $v): string {
        $v = trim($v);
        if (stripos($v, 'W/') === 0) {
            $v = substr($v, 2);
        }
        $v = trim($v, '"');
        return (string)preg_replace('/-(gzip|br|deflate)$/', '', $v);
    };
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        if ($candidate !== '' && hash_equals($norm($etag), $norm($candidate))) {
            return true;
        }
    }
    return false;
}

function apiValidateAttendanceSheet(\mysqli $conn, int $classId, int $yearId, array $records, bool $requireComplete, ?string $sheetDate = null): array {
    if (!class_exists('\\App\\Services\\AttendanceRecordService')
        || !class_exists('\\App\\Services\\EnrollmentService')) {
        err('Attendance validation is temporarily unavailable.', 503);
    }

    $scope = \App\Services\EnrollmentService::resolveRosterYear(
        $conn,
        $classId,
        $yearId > 0 ? $yearId : null
    );
    $roster = \App\Services\EnrollmentService::fetchRoster(
        $conn,
        $classId,
        $scope['year_id'] ?? null,
        ['on_date' => $sheetDate]
    );
    try {
        return \App\Services\AttendanceRecordService::normalizeSheet($records, $roster, $requireComplete);
    } catch (\App\Services\AttendanceSheetInvalid $error) {
        // Controlled service message (hardcoded strings) + the machine code
        // so sync monitoring can tell an expected workflow state
        // (INCOMPLETE_SHEET) from a real mismatch (ROSTER_MISMATCH).
        $safeMessage = $error->getMessage();
        err($safeMessage, 422, ['code' => $error->getErrorCode()]);
    } catch (\DomainException $error) {
        $safeMessage = $error->getMessage();
        err($safeMessage, 422);
    }
}

/**
 * Persist a validated sheet. Drafts merge (partial-safe upsert, never
 * deletes unmarked rows); submits replace (the complete sheet is the
 * authority). The route owns the transaction so the attendance rows and
 * Education workflow packet commit or roll back as one unit.
 *
 * @param array<int,array{member_id:int,status:string,note:string}> $records
 */
function apiReplaceAttendanceRows(
    \mysqli $conn,
    int $classId,
    string $date,
    ?int $yearId,
    int $userId,
    array $records,
    bool $merge = false
): int {
    // Phase 6: a day with submitted lesson sheets is derived → class/day
    // writes are refused (409 WORKFLOW_REJECTED via the caller's catch).
    if (class_exists('\\App\\Services\\LessonAttendanceService')) {
        \App\Services\LessonAttendanceService::assertClassDayWritable($conn, $classId, $date);
    }
    return $merge
        ? \App\Services\AttendanceRecordService::mergeSheet(
            $conn,
            $classId,
            $date,
            $yearId,
            $userId,
            $records
        )
        : \App\Services\AttendanceRecordService::replaceSheet(
            $conn,
            $classId,
            $date,
            $yearId,
            $userId,
            $records
        );
}

// ============================================================
// GET /attendance?class_id=X&date=Y
// ============================================================
// ============================================================
// GET /attendance/calendar — offline class-day calendar (mobile)
// Same rule as the save paths (SchoolCalendarService::dayType) for the
// classes in the phone's list, from the backdate limit to 60 days ahead.
// Conditional: If-None-Match with the last ETag → 304 without computing.
// ============================================================
if ($method === 'GET' && $action === 'calendar') {
    if (!class_exists('\\App\\Services\\SchoolCalendarService')) {
        ok(['available' => false]);
    }
    if (isApiRateLimited('attendance_calendar', 30)) {
        err('Too many requests. Please wait a moment.', 429);
    }
    $calendar = '\\App\\Services\\SchoolCalendarService';
    $classIds = apiAttendanceClassIds($conn, $auth, $yearId);
    $today = $calendar::today();
    $revision = $calendar::calendarRevision($conn);
    $etag = '"cal1-' . substr(sha1($revision . '|' . $today . '|' . implode(',', $classIds)), 0, 24) . '"';
    $ifNoneMatch = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($revision !== '') {
        header('ETag: ' . $etag);
        if ($ifNoneMatch !== '' && apiEtagMatches($etag, $ifNoneMatch)) {
            http_response_code(304);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            exit;
        }
    }
    $from = date('Y-m-d', strtotime($today . ' -' . $calendar::BACKDATE_DAYS . ' days'));
    $to = date('Y-m-d', strtotime($today . ' +' . $calendar::MOBILE_FUTURE_DAYS . ' days'));
    $data = $calendar::mobileCalendar($conn, $classIds, $from, $to);
    ok([
        'available'     => true,
        'enforced'      => $calendar::tablesReady($conn),
        'etag'          => $revision !== '' ? $etag : null,
        'revision'      => $revision,
        'today'         => $today,
        'from'          => $from,
        'to'            => $to,
        'backdate_days' => $calendar::BACKDATE_DAYS,
        'classes'       => $data['classes'],
        'school'        => (object)$data['school'],
    ]);
}

if ($method === 'GET' && ($action === '' || $action === null)) {
    $classId = (int)($_GET['class_id'] ?? 0);
    $date = validateDate($_GET['date'] ?? '', date('Y-m-d'));

    if (!$classId) err('class_id is required');
    apiRequireClassAccess($conn, $auth, $classId, $yearId);

    $stmt = $conn->prepare("SELECT class_name, class_name_en FROM classes WHERE id = ?");
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$class) err('Class not found', 404);

    $scope = class_exists('\\App\\Services\\EnrollmentService')
        ? \App\Services\EnrollmentService::resolveRosterYear($conn, $classId, $yearId ?: null)
        : ['year_id' => $yearId ?: null, 'fallback' => false, 'year_name' => null];
    $rosterYearId = !empty($scope['year_id']) ? (int)$scope['year_id'] : 0;

    $attByMember = [];
    $attStmt = $conn->prepare(
        "SELECT id, member_id, status, notes, check_in_time
         FROM attendance WHERE class_id = ? AND attendance_date = ?"
    );
    if ($attStmt) {
        $attStmt->bind_param('is', $classId, $date);
        $attStmt->execute();
        $ar = $attStmt->get_result();
        while ($row = $ar->fetch_assoc()) {
            $attByMember[(int)$row['member_id']] = $row;
        }
        $attStmt->close();
    }

    $roster = class_exists('\\App\\Services\\EnrollmentService')
        ? \App\Services\EnrollmentService::fetchRoster($conn, $classId, $rosterYearId ?: null, ['on_date' => $date])
        : [];

    // Fallback if the service is missing on an older deploy
    if (!$roster && !class_exists('\\App\\Services\\EnrollmentService')) {
        if ($year) {
            $stmt = $conn->prepare(
                "SELECT ce.member_id, m.student_name, m.father_name, m.member_code, m.gender
                 FROM class_enrollments ce
                 JOIN members m ON ce.member_id = m.id
                 WHERE ce.class_id = ? AND ce.academic_year_id = ? AND ce.status = 'active'
                 ORDER BY m.student_name"
            );
            $stmt->bind_param('ii', $classId, $year['id']);
        } else {
            $stmt = $conn->prepare(
                "SELECT ce.member_id, m.student_name, m.father_name, m.member_code, m.gender
                 FROM class_enrollments ce
                 JOIN members m ON ce.member_id = m.id
                 WHERE ce.class_id = ? AND ce.status = 'active'
                 ORDER BY m.student_name"
            );
            $stmt->bind_param('i', $classId);
        }
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $roster[] = $row;
        }
        $stmt->close();
    }

    $packetStatus = null;
    $locked = false;
    $review = null;
    if (class_exists('\\App\\Services\\SubmissionService')) {
        $packetStatus = \App\Services\SubmissionService::resolvedAttendanceStatus($conn, $classId, $date);
        $locked = \App\Services\SubmissionService::isLockedForTeacher($packetStatus, $auth);
        // Education's reason is delivered only while the packet is actually
        // returned for correction — the teacher's key back to editing.
        if ($packetStatus === \App\Services\SubmissionService::STATUS_REVISION) {
            $review = \App\Services\SubmissionService::attendanceReview($conn, $classId, $date);
        }
    }

    // Attendance alerting Phase 2: read-only "open warning" indicator per
    // roster row (kind/stage only — no new PII). Empty when the feature is
    // off or the tables are not there yet; never blocks the sheet.
    $openWarnings = [];
    if (class_exists('\\App\\Services\\AttendanceWarningService')) {
        try {
            $openWarnings = \App\Services\AttendanceWarningService::openByMembers(
                $conn, 'edu', array_map(static fn($r) => (int)($r['member_id'] ?? $r['id'] ?? 0), $roster)
            );
        } catch (\Throwable $e) {
            $openWarnings = [];
        }
    }

    $students = [];
    foreach ($roster as $row) {
        $mid = (int)($row['member_id'] ?? $row['id'] ?? 0);
        if ($mid <= 0) continue;
        $att = $attByMember[$mid] ?? null;
        $students[] = [
            'member_id' => $mid,
            'student_name' => $row['student_name'] ?? '',
            'father_name' => $row['father_name'] ?? '',
            'member_code' => $row['member_code'] ?? '',
            'gender' => $row['gender'] ?? '',
            'attendance_id' => $att && !empty($att['id']) ? (int)$att['id'] : null,
            'att_status' => $att['status'] ?? null,
            'notes' => $att['notes'] ?? null,
            'check_in_time' => $att['check_in_time'] ?? null,
            'warning' => $openWarnings[$mid] ?? null,
        ];
    }

    ok([
        'class' => $class,
        'date' => $date,
        'date_status' => class_exists('\\App\\Services\\SchoolCalendarService')
            ? \App\Services\SchoolCalendarService::classDayStatus($conn, $classId, $date)
            : null,
        'students' => $students,
        'count' => count($students),
        'roster_year_id' => $rosterYearId ?: null,
        'roster_year_name' => $scope['year_name'] ?? null,
        'roster_fallback' => !empty($scope['fallback']),
        'submission_status' => $packetStatus,
        'locked' => $locked,
        'review_notes' => $review['review_notes'] ?? null,
        'reviewed_at' => $review['reviewed_at'] ?? null,
        'reviewer_name' => $review['reviewer_name'] ?? null,
    ]);
}

// ============================================================
// POST /attendance — Save (one transaction)
// ============================================================
if ($method === 'POST' && ($action === '' || $action === null)) {
    $input = getBody();
    $classId = (int)($input['class_id'] ?? 0);
    $date = validateDate($input['date'] ?? '', date('Y-m-d'));
    $records = $input['records'] ?? [];

    if (!$classId || empty($records)) err('class_id and records array are required');
    if (!is_array($records)) err('records must be an array');
    if ($date > date('Y-m-d')) err('Attendance cannot be recorded for a future date.', 422, ['code' => 'FUTURE_DATE']);

    apiRequireClassAccess($conn, $auth, $classId, $yearId);
    // After the access check: a teacher must not learn another class's
    // schedule verdicts (class-day / day-off detail is class-specific).
    apiAssertClassDay($conn, $classId, $date);
    $userId = (int)$auth['uid'];
    apiIdempotencyBegin($userId, (string)($input['client_op_id'] ?? ''));
    if (isApiRateLimited('attendance_save', 30)) {
        err('Too many attendance saves. Please wait a moment.', 429);
    }
    // Draft: a partial sheet is a normal workflow state (instant autosave);
    // unknown students are still rejected. See apiValidateAttendanceSheet.
    $records = apiValidateAttendanceSheet($conn, $classId, $yearId, $records, false, $date);

    if (class_exists('\\App\\Services\\SubmissionService')
        && !\App\Services\SubmissionService::teacherMayWriteAttendance($conn, $auth, $classId, $date)) {
        err('This day’s attendance is already submitted. Only Education can change it.', 409,
            ['code' => 'ALREADY_SUBMITTED']);
    }

    if (!class_exists('\\App\\Services\\SubmissionService')) {
        err('Attendance workflow is temporarily unavailable.', 503);
    }

    $yearIdOrNull = $year ? (int)$year['id'] : null;
    $counts = \App\Services\SubmissionService::countsFromRecords($records);
    $saved = 0;
    $packet = [];
    $conn->begin_transaction();
    try {
        $saved = apiReplaceAttendanceRows(
            $conn,
            $classId,
            $date,
            $yearIdOrNull,
            $userId,
            $records,
            true // draft merge: upsert submitted rows, never delete unmarked
        );
        $packet = \App\Services\SubmissionService::upsertAttendance($conn, [
            'teacher_id' => $userId,
            'class_id' => $classId,
            'date' => $date,
            'status' => \App\Services\SubmissionService::STATUS_DRAFT,
            'student_count' => $saved,
            'year_id' => $yearIdOrNull,
            'present' => $counts['present'],
            'absent' => $counts['absent'],
            'late' => $counts['late'],
            'excused' => $counts['excused'],
        ]);
        if (empty($packet['ok'])) {
            throw new \DomainException(
                $packet['message'] ?? 'Could not update the attendance workflow.'
            );
        }
        $conn->commit();
    } catch (\DomainException $error) {
        $conn->rollback();
        $safeMessage = $error->getMessage();
        err($safeMessage, 409, ['code' => 'WORKFLOW_REJECTED']);
    } catch (\Throwable $error) {
        $conn->rollback();
        error_log('API attendance draft failed: ' . $error->getMessage());
        err('Could not save attendance. Nothing was changed. Please try again.', 500);
    }

    logApiAction($auth['uid'], $auth['usr'], 'Attendance Saved', "Class: {$classId}, Date: {$date}, Records: {$saved}");

    ok([
        'message' => $packet['message'] ?? "{$saved} records saved as a draft for Education",
        'saved' => $saved,
        'class_id' => $classId,
        'date' => $date,
        'submission_id' => $packet['id'] ?? 0,
        'submission_status' => $packet['status'] ?? 'draft',
    ], 201);
}

// ============================================================
// POST /attendance/submit — save + mark sent to Education
// ============================================================
if ($method === 'POST' && $action === 'submit') {
    $input = getBody();
    $classId = (int)($input['class_id'] ?? 0);
    $date = validateDate($input['date'] ?? '', date('Y-m-d'));
    $records = $input['records'] ?? [];

    if (!$classId || empty($records)) err('class_id and records array are required');
    if (!is_array($records)) err('records must be an array');
    if ($date > date('Y-m-d')) err('Attendance cannot be recorded for a future date.', 422, ['code' => 'FUTURE_DATE']);

    apiRequireClassAccess($conn, $auth, $classId, $yearId);
    // After the access check: a teacher must not learn another class's
    // schedule verdicts (class-day / day-off detail is class-specific).
    apiAssertClassDay($conn, $classId, $date);
    $yearIdOrNull = $year ? (int)$year['id'] : null;
    $userId = (int)$auth['uid'];
    apiIdempotencyBegin($userId, (string)($input['client_op_id'] ?? ''));
    if (isApiRateLimited('attendance_submit', 20)) {
        err('Too many submits. Please wait a moment.', 429);
    }
    // Submit: the complete sheet is still required — a submission must be a
    // full, explicit record for the day (default-absent clients satisfy this
    // by construction; the guard still protects against roster drift).
    $records = apiValidateAttendanceSheet($conn, $classId, $yearId, $records, true, $date);
    if (class_exists('\\App\\Services\\SubmissionService')
        && !\App\Services\SubmissionService::teacherMayWriteAttendance($conn, $auth, $classId, $date)) {
        err('This day’s attendance is already submitted. Only Education can change it.', 409,
            ['code' => 'ALREADY_SUBMITTED']);
    }
    if (!class_exists('\\App\\Services\\SubmissionService')) {
        err('Attendance workflow is temporarily unavailable.', 503);
    }

    $counts = \App\Services\SubmissionService::countsFromRecords($records);
    $saved = 0;
    $packet = [];
    $conn->begin_transaction();
    try {
        $saved = apiReplaceAttendanceRows(
            $conn,
            $classId,
            $date,
            $yearIdOrNull,
            $userId,
            $records
        );
        $packet = \App\Services\SubmissionService::upsertAttendance($conn, [
            'channel' => 'mobile',
            'teacher_id' => $userId,
            'class_id' => $classId,
            'date' => $date,
            'status' => \App\Services\SubmissionService::STATUS_SUBMITTED,
            'student_count' => $saved,
            'year_id' => $yearIdOrNull,
            'present' => $counts['present'],
            'absent' => $counts['absent'],
            'late' => $counts['late'],
            'excused' => $counts['excused'],
        ]);
        if (empty($packet['ok'])) {
            throw new \DomainException(
                $packet['message'] ?? 'Could not mark attendance complete.'
            );
        }
        $conn->commit();
    } catch (\DomainException $error) {
        $conn->rollback();
        $safeMessage = $error->getMessage();
        err($safeMessage, 409, ['code' => 'WORKFLOW_REJECTED']);
    } catch (\Throwable $error) {
        $conn->rollback();
        error_log('API attendance submission failed: ' . $error->getMessage());
        err('Could not submit attendance. Nothing was changed. Please try again.', 500);
    }

    logApiAction($auth['uid'], $auth['usr'], 'Attendance Submitted', "Class: {$classId}, Date: {$date}, Records: {$saved}");

    // Attendance alerting Phase 2: submitted sheets feed the risk engine
    // (drafts via POST /attendance do not). Flag-gated, never fails the submit.
    if (class_exists('\\App\\Services\\AttendanceWarningService')) {
        \App\Services\AttendanceWarningService::safeEvaluateMany(
            $conn,
            'edu',
            \App\Services\AttendanceWarningService::memberIdsFromRecords($records),
            $date,
            $userId
        );
    }

    ok([
        'message' => $packet['message'] ?? "{$saved} attendance records marked complete for Education",
        'saved' => $saved,
        'class_id' => $classId,
        'date' => $date,
        'submission_id' => $packet['id'] ?? 0,
        'submission_status' => $packet['status'] ?? 'submitted',
    ], 201);
}

// ============================================================
// GET /attendance/daily-stats
// ============================================================
if ($action === 'daily-stats' && $method === 'GET') {
    $date = validateDate($_GET['date'] ?? '', date('Y-m-d'));

    $classFilterSql = '';
    $params = [$date];
    $types = 's';
    if (apiIsClassRestricted($auth)) {
        $ids = [];
        $st = $conn->prepare(
            "SELECT DISTINCT class_id FROM teacher_assignments
             WHERE teacher_id = ? AND is_active = 1
               AND (academic_year_id IS NULL OR academic_year_id = ?)"
        );
        $uid = (int)$auth['uid'];
        $st->bind_param('ii', $uid, $yearId);
        $st->execute();
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) $ids[] = (int)$row['class_id'];
        $st->close();
        if (!$ids) {
            ok(['date' => $date, 'classes' => []]);
        }
        $classFilterSql = ' AND c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($ids as $cid) {
            $params[] = $cid;
            $types .= 'i';
        }
    }

    $sql = "SELECT c.id as class_id, c.class_name,
                   COUNT(DISTINCT a.member_id) as recorded,
                   COALESCE(SUM(a.status='present'),0) as present,
                   COALESCE(SUM(a.status='absent'),0) as absent,
                   COALESCE(SUM(a.status='late'),0) as late
            FROM classes c
            LEFT JOIN attendance a ON a.class_id = c.id AND a.attendance_date = ?
            WHERE c.is_active = 1 {$classFilterSql}
            GROUP BY c.id ORDER BY c.level_order";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stats = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['recorded'] = (int)$row['recorded'];
        $row['present'] = (int)$row['present'];
        $row['absent'] = (int)$row['absent'];
        $row['late'] = (int)$row['late'];
        $stats[] = $row;
    }
    $stmt->close();

    ok(['date' => $date, 'classes' => $stats]);
}

// ============================================================
// GET /attendance/summary
// ============================================================
if ($action === 'summary' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    $month = validateMonth($_GET['month'] ?? '', date('Y-m'));
    if (!$classId) err('class_id is required');
    apiRequireClassAccess($conn, $auth, $classId, $yearId);

    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $stmt = $conn->prepare(
        "SELECT a.member_id, m.student_name, m.father_name,
                COUNT(*) as total_days,
                SUM(a.status='present') as present,
                SUM(a.status='absent') as absent,
                SUM(a.status='late') as late
         FROM attendance a JOIN members m ON a.member_id = m.id
         WHERE a.class_id = ? AND a.attendance_date BETWEEN ? AND ?
         GROUP BY a.member_id ORDER BY m.student_name"
    );
    $stmt->bind_param('iss', $classId, $startDate, $endDate);
    $stmt->execute();
    $summary = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['total_days'] = (int)$row['total_days'];
        $row['present'] = (int)$row['present'];
        $row['absent'] = (int)$row['absent'];
        $row['late'] = (int)$row['late'];
        $row['rate'] = $row['total_days'] > 0 ? round($row['present'] / $row['total_days'] * 100, 1) : 0;
        $summary[] = $row;
    }
    $stmt->close();

    ok(['class_id' => $classId, 'month' => $month, 'summary' => $summary]);
}

err("No handler for {$method} /attendance" . ($action ? "/{$action}" : ''), 404);
