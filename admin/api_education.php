<?php
/**
 * Education Department API
 * Handles enrollments, teacher assignments, grades, and attendance
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/workflow.php';
require_once __DIR__ . '/backend/member_sync.php';
require_once __DIR__ . '/backend/services/EnrollmentService.php';
require_once __DIR__ . '/backend/services/AssignmentService.php';
require_once __DIR__ . '/backend/services/AttendanceRecordService.php';
require_once __DIR__ . '/backend/services/AttendanceWarningService.php';
require_once __DIR__ . '/backend/services/LessonAttendanceService.php';
require_once __DIR__ . '/backend/danger_confirm.php';

use App\Services\AssignmentService;
use App\Services\AttendanceRecordService;
use App\Services\EnrollmentService;
use App\Services\MemberCategory;

// Check authentication
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Validate CSRF for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

$action = is_scalar($_REQUEST['action'] ?? '') ? (string)($_REQUEST['action'] ?? '') : '';
requirePostActions($action, ['enroll', 'assign_teacher', 'record_grade', 'record_attendance', 'batch_attendance', 'promote', 'unenroll_student', 'save_class', 'delete_class', 'save_academic_year', 'set_current_year', 'delete_year', 'save_term', 'set_current_term', 'reopen_term', 'close_term_reopen', 'delete_term', 'bulk_enroll', 'transfer_student', 'sync_member_types', 'toggle_class', 'save_assessment_type', 'toggle_assessment_type', 'delete_assessment_type']);
$__featureActions = [
    'grades' => ['record_grade'],
    'attendance' => ['record_attendance', 'batch_attendance'],
];
foreach ($__featureActions as $__feature => $__actions) {
    if (in_array($action, $__actions, true) && !feature_enabled($__feature)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This feature is not enabled for this deployment.']);
        exit;
    }
}

// ── Action-level authorization (two tiers) ──
// Teachers and attendance-takers are allowed in to READ classes and record
// grades/attendance, but management is restricted:
$__role = $_SESSION['admin_role'] ?? '';

// HR may only fetch the live class catalog for the registration dropdown.
if ($__role === 'hr_dept' && !in_array($action, ['get_classes'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to use this Education action.']);
    exit;
}

// TIER 1a — Academic YEAR lifecycle (create/activate/delete the year itself):
// School Admin (owner) & Super Admin (break-glass) ONLY. The year is the
// system-wide container, so it stays with the school's owner role.
$__yearLifecycleActions = ['save_academic_year', 'set_current_year', 'reopen_year', 'delete_year'];
if (in_array($action, $__yearLifecycleActions, true)
        && !in_array($__role, ['super_admin', 'school_admin'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only a School Admin or Super Admin can manage the academic year.']);
    exit;
}

// TIER 1b — SEMESTER (term) management inside an existing year: the Education
// Department runs the semester calendar (dates, current semester), with the
// School Admin and Super Admin able to do the same. The year itself (above)
// remains School-Admin-only.
$__termActions = ['save_term', 'delete_term', 'set_current_term', 'reopen_term', 'close_term_reopen'];
$__termActions[] = 'impact_delete_term'; // danger-confirmation impact preview (read-only)
if (in_array($action, $__termActions, true)
        && !in_array($__role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only the Education Department, School Admin or Super Admin can manage semesters.']);
    exit;
}

// TIER 2 — Class / enrolment management: Education dept + admins.
$__manageActions = [
    'enroll', 'unenroll_student', 'promote', 'bulk_enroll', 'transfer_student',
    'assign_teacher', 'save_class', 'delete_class', 'toggle_class',
    'sync_member_types', 'impact_delete_class',
];
if (in_array($action, $__manageActions, true)) {
    if (!in_array($__role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Only the Education department can manage classes and enrolments.']);
        exit;
    }
}

// TIER 2b — Legacy direct-write adapters (audit 2026-10-20). `record_attendance`,
// `batch_attendance` and `record_grade` have NO caller in the web UI, the JS or
// the mobile app: every real attendance write goes through api_attendance.php /
// api_lesson_attendance.php / the mobile routes (which own the submission
// packet), and every real mark goes through api_subjects.php. They are kept
// for compatibility but are no longer reachable by teachers or takers, who
// would otherwise be able to write any class without the workflow.
$__legacyWriteActions = ['record_attendance', 'batch_attendance', 'record_grade'];
if (in_array($action, $__legacyWriteActions, true)
        && !in_array($__role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Use the attendance or mark sheet of your class to record this.']);
    exit;
}

// TIER 2c — Enrolment-management READS (audit 2026-10-20). The "unassigned"
// lists return every active member / teacher of the school with date of
// birth, education level and staff flags; they exist only for the Education
// enrolment screen (dashboards/edu_dept.php). Teachers and takers never call
// them and must not be able to list the whole school through them. The
// school-wide roster / member search / teacher directory belong to the same
// screen (teacher + taker dashboards only ever call get_enrolled_students).
$__manageReadActions = ['get_unassigned_members', 'get_unassigned_teachers', 'enrollment_overview',
    'roster', 'search_members', 'list_teachers'];
if (in_array($action, $__manageReadActions, true)
        && !in_array($__role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Enrolment management is available to the Education department only.']);
    exit;
}

// TIER 3 — School-wide academic ANALYTICS: Education dept + admins.
//
// These actions return other people's academic results: averages, ranks,
// grade letters, attendance and named students across every class. They are
// read-only, so they were not covered by the management tier above, and
// until now the only gate on them was "is logged in" -- which let a teacher
// or an attendance taker read the whole school's marks through the API even
// though the page that hosts the hub (dashboards/edu_dept.php) is itself
// restricted to these three roles in access_control.php. There is no
// non-admin consumer of these endpoints anywhere in the repository.
$__analyticsActions = [
    'get_education_hub',
    'filter_students_performance',
    'get_academic_intelligence',
    'get_academic_intelligence_options',
    // Academic Tracking (Phase 2) — scoped student workflow. Same payload
    // class as the rest of this tier: another person's marks, grades, rank
    // and attendance. It therefore joins the existing tier rather than
    // getting a gate of its own.
    'tracking_student_detail',
    'tracking_student_assessments',
    // Academic Tracking (Phase 3) — scoped teacher workflow. These return
    // who teaches what and where each mark list stands, which is the same
    // scoped academic surface as the rest of this tier.
    'tracking_teacher_detail',
    'tracking_teacher_assessments',
    // Academic Tracking (Phase 4) — scoped subject workflow. Same payload
    // class again: who teaches what, who studies it, and where the work
    // stands.
    'tracking_subject_detail',
    'tracking_subject_offering',
    'tracking_subject_students',
    // Academic Tracking (Phase 5) — scoped class workflow. Same payload
    // class again: a class's roll, offerings, assignments and the state
    // of its work.
    'tracking_class_detail',
    'tracking_class_students',
    'tracking_class_subjects',
    'tracking_class_teachers',
    'tracking_class_assessments',
];
if (in_array($action, $__analyticsActions, true)) {
    if (!in_array($__role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Academic analytics are available to the Education department only.',
        ]);
        exit;
    }
}

// Effective academic year — single source of truth (resolver, time-travel aware)
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;

// ── PII minimization (PATCH H7) ─────────────────────────────────────────────
// Teachers and attendance takers receive rosters to teach, not contact
// details: member/teacher phone numbers are stripped from every payload
// for those roles. Staff roles keep full contact data.
$__maskPhone = in_array($__role, ['teacher', 'attendance_taker'], true);

/** Strip phone columns from row arrays unless the caller is staff. */
function edu_scrub_phone(array $rows, bool $mask): array
{
    if (!$mask) {
        return $rows;
    }
    foreach ($rows as &$r) {
        if (is_array($r)) {
            foreach (['phone', 'phone_number', 'phone_primary'] as $k) {
                if (array_key_exists($k, $r)) {
                    $r[$k] = null;
                }
            }
        }
    }
    unset($r);
    return $rows;
}

// Education schema is deployment-managed by migrations 004, 006, and 013.

// ── Date integrity helpers (2026-10-08) ─────────────────────────────────────
// Year and semester start/end dates used to be stored with ZERO validation:
// any string was accepted, end-before-start was accepted. They are still
// OPTIONAL (Ethiopian schools often create the year before the Ministry of
// Education publishes exact dates), but when present they must be real ISO
// dates in a sane order, and a semester must sit inside its year.
function _ay_valid_date_str($s): bool {
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    $p = explode('-', $s);
    return checkdate((int)$p[1], (int)$p[2], (int)$p[0]);
}
/**
 * Returns null when the pair is acceptable, or an error message string.
 * Empty strings are allowed (dates are optional) and normalized to null by
 * reference so callers bind NULL for the DATE columns.
 */
function _ay_check_date_pair(&$start, &$end, string $what): ?string {
    if ($start !== '' && !_ay_valid_date_str($start)) {
        return "$what start date is not a valid date (expected YYYY-MM-DD).";
    }
    if ($end !== '' && !_ay_valid_date_str($end)) {
        return "$what end date is not a valid date (expected YYYY-MM-DD).";
    }
    if ($start !== '' && $end !== '' && $end < $start) {
        return "$what end date must be on or after the start date.";
    }
    return null;
}


// ── STEP 3: write-protection ────────────────────────────────────────────────
// Year-scoped writes stamp the ACTIVE year and are refused while time-travelling
// (viewing a past year) or when no active year is set. Year-MANAGEMENT actions
// (save_academic_year, set_current_year, save_term, …) are deliberately exempt —
// they are how the active year is administered, not year-scoped data writes.
if (function_exists('ay_require_writable')) {
    $ayYearScopedWrites = ['enroll','assign_teacher','record_grade','record_attendance','batch_attendance','promote','bulk_enroll','transfer_student'];
    $ayReadonlyBlocked  = ['save_class','delete_class','unenroll_student','sync_member_types'];
    if (in_array($action, $ayYearScopedWrites, true)) {
        ay_require_writable($conn);
    } elseif (in_array($action, $ayReadonlyBlocked, true)) {
        ay_block_if_readonly($conn);
    }
}

// ── Danger confirmation (owner decision D3, 2026-10-21) ─────────────────────
// HIGH-risk actions need the phrase the user typed in the danger dialog.
$__dcHigh = [
    'delete_class'      => 'education.delete_class',
    'delete_term'       => 'education.delete_term',
];
if (isset($__dcHigh[$action])) {
    dc_require_phrase($__dcHigh[$action]);
}

/** COUNT(*) helper for the read-only impact previews; 0 when a table is missing. */
function __edu_dc_count(mysqli $conn, string $sql, array $ids): int
{
    try {
        $st = $conn->prepare($sql);
        if (!$st) {
            return 0;
        }
        $st->bind_param(str_repeat('i', count($ids)), ...$ids);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        $st->close();
        return (int)($row[0] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** What deleting a semester would touch (F1 guard + dialog counts). */
function __edu_term_usage(mysqli $conn, int $tid): array
{
    return [
        'assessments'       => __edu_dc_count($conn, "SELECT COUNT(*) FROM assessments WHERE term_id = ?", [$tid]),
        'grades'            => __edu_dc_count($conn, "SELECT COUNT(*) FROM academic_records WHERE term_id = ? OR assessment_id IN (SELECT id FROM assessments WHERE term_id = ?)", [$tid, $tid]),
        'grade_submissions' => __edu_dc_count($conn, "SELECT COUNT(*) FROM grade_submissions WHERE term_id = ?", [$tid]),
        'class_subjects'    => __edu_dc_count($conn, "SELECT COUNT(*) FROM class_subjects WHERE term_id = ?", [$tid]),
    ];
}

try {
switch ($action) {

    // Read-only preview for the "Delete class" danger dialog.
    case 'impact_delete_class':
        $id = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
        if (!$id) { echo json_encode(['status' => 'error', 'message' => 'ID required']); break; }
        $active = __edu_dc_count($conn, "SELECT COUNT(*) FROM class_enrollments WHERE class_id = ? AND status = 'active'", [$id]);
        $history = class_exists('\\App\\Services\\EnrollmentService') && EnrollmentService::classHasGradeHistory($conn, $id);
        echo json_encode([
            'status' => 'success',
            'mode'   => $active > 0 ? 'blocked' : ($history ? 'deactivate' : 'delete'),
            'counts' => [
                'active_enrollments'  => $active,
                'past_enrollments'    => __edu_dc_count($conn, "SELECT COUNT(*) FROM class_enrollments WHERE class_id = ? AND status <> 'active'", [$id]),
                'subjects'            => __edu_dc_count($conn, "SELECT COUNT(*) FROM class_subjects WHERE class_id = ?", [$id]),
                'teacher_assignments' => __edu_dc_count($conn, "SELECT COUNT(*) FROM teacher_assignments WHERE class_id = ?", [$id]),
                'timetable_lessons'   => __edu_dc_count($conn, "SELECT COUNT(*) FROM timetable_entries WHERE class_id = ?", [$id]),
                'assessments'         => __edu_dc_count($conn, "SELECT COUNT(*) FROM assessments WHERE class_id = ?", [$id]),
                'grade_submissions'   => __edu_dc_count($conn, "SELECT COUNT(*) FROM grade_submissions WHERE class_id = ?", [$id]),
            ],
        ]);
        break;

    // Read-only preview for the "Delete semester" danger dialog.
    case 'impact_delete_term':
        $tid = (int)($_GET['term_id'] ?? $_POST['term_id'] ?? 0);
        if (!$tid) { echo json_encode(['status' => 'error', 'message' => 'Term ID required']); break; }
        $u = __edu_term_usage($conn, $tid);
        echo json_encode([
            'status'  => 'success',
            'blocked' => ($u['assessments'] + $u['grades'] + $u['grade_submissions']) > 0,
            'counts'  => $u,
        ]);
        break;

    // ============================================================
    // ENROLL STUDENT IN CLASS
    // ============================================================
    case 'enroll':
        $memberId = (int)($_POST['member_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);

        if (!$memberId || !$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Please select both member and class']);
            exit;
        }

        $enr = EnrollmentService::enroll($conn, $memberId, $classId, $currentYear['id'] ?? null, (int)($_SESSION['admin_id'] ?? 0));
        if (($enr['status'] ?? '') !== 'success') {
            echo json_encode($enr);
            exit;
        }

        $stmt = $conn->prepare("SELECT student_name, father_name, member_code FROM members WHERE id = ?");
        $stmt->bind_param("i", $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $memberName = trim(($member['student_name'] ?? '') . ' ' . ($member['father_name'] ?? ''));
        $className = $class['class_name'] ?? 'class';

        if (empty($enr['skipped']) && function_exists('sendNotification')) {
            sendNotification($conn, 'class_enrolled',
                "Student Enrolled in Class",
                "$memberName has been enrolled in {$className}",
                [
                    'data' => ['member_id' => $memberId, 'class_id' => $classId],
                    'target_roles' => ['info_dept', 'school_admin']
                ]
            );
        }

        $msg = !empty($enr['transferred'])
            ? "$memberName transferred to {$className}."
            : (!empty($enr['skipped'])
                ? "$memberName is already in {$className}."
                : "$memberName enrolled in {$className} successfully!");
        echo json_encode(['status' => 'success', 'message' => $msg]);
        break;
    
    // ============================================================
    // ASSIGN TEACHER TO CLASS
    // ============================================================
    case 'assign_teacher':
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
        $isClassTeacher = !empty($_POST['is_class_teacher']);
        $assignedBy = (int)($_SESSION['admin_id'] ?? 0);

        if ($isClassTeacher && !$subjectId) {
            echo json_encode(AssignmentService::setHomeroom($conn, $teacherId, $classId, null, $assignedBy), JSON_UNESCAPED_UNICODE);
            break;
        }

        $res = AssignmentService::assign($conn, $teacherId, $classId, $subjectId, 'primary', null, $assignedBy);
        if (($res['status'] ?? '') === 'success' && $isClassTeacher) {
            $home = AssignmentService::setHomeroom($conn, $teacherId, $classId, null, $assignedBy);
            if (($home['status'] ?? '') === 'success') {
                $res['message'] = ($res['message'] ?? 'Assigned.') . ' Also set as Class Teacher.';
            }
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;
    
    // ============================================================
    // RECORD GRADES
    // ============================================================
    case 'record_grade':
        $memberId = (int)($_POST['member_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $assessmentType = $_POST['assessment_type'] ?? 'test';
        $score = isset($_POST['score']) ? (float)$_POST['score'] : null;
        $maxScore = isset($_POST['max_score']) ? (float)$_POST['max_score'] : 100;
        $remarks = trim($_POST['remarks'] ?? '');
        
        if (!$memberId || !$classId || !$subjectId) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }
        
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'No active academic year.']);
            exit;
        }
        
        // Get current term
        $currentTerm = null;
        $result = $conn->query("SELECT id FROM academic_terms WHERE is_current = 1 LIMIT 1");
        if ($result) $currentTerm = $result->fetch_assoc();
        $termId = $currentTerm ? $currentTerm['id'] : null;
        
        // Calculate grade letter
        $gradeLetter = calculateGradeLetter($score, $maxScore);
        
        // Insert grade record
        $stmt = $conn->prepare("
            INSERT INTO academic_records 
            (member_id, class_id, subject_id, academic_year_id, term_id, assessment_type, 
             score, max_score, grade_letter, remarks, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $recordedBy = $_SESSION['admin_id'];
        $stmt->bind_param("iiiissddssi", 
            $memberId, $classId, $subjectId, $currentYear['id'], $termId,
            $assessmentType, $score, $maxScore, $gradeLetter, $remarks, $recordedBy
        );
        
        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Grade recorded successfully!',
                'grade_letter' => $gradeLetter
            ]);
        } else {
            reportInternalError('Grade record failed', $stmt->error ?: $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to record the grade.']);
        }
        break;
    
    // ============================================================
    // RECORD ATTENDANCE
    // ============================================================
    case 'record_attendance':
        $memberId = (int)($_POST['member_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $attendanceDate = validateDate($_POST['attendance_date'] ?? '', date('Y-m-d'));
        $statusValue = $_POST['status'] ?? null;
        $status = is_string($statusValue) ? strtolower(trim($statusValue)) : '';
        $checkInTime = $_POST['check_in_time'] ?? null;
        $checkInTime = is_string($checkInTime) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $checkInTime)
            ? $checkInTime
            : null;
        $noteValue = $_POST['notes'] ?? '';
        $notes = is_string($noteValue) ? trim($noteValue) : '';
        
        if (!$memberId || !$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Member ID and class ID are required']);
            exit;
        }
        
        if (!in_array($status, ['present', 'absent', 'late', 'excused', 'holiday'], true)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid attendance status']);
            exit;
        }
        
        if ((function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes)) > AttendanceRecordService::MAX_NOTE_LENGTH) {
            echo json_encode(['status' => 'error', 'message' => 'Attendance notes are too long.']);
            exit;
        }

        if ($attendanceDate > date('Y-m-d')) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Attendance cannot be recorded for a future date.']);
            exit;
        }
        // Phase 6: a day with lesson sheets is derived → class/day rows read-only.
        if (\App\Services\LessonAttendanceService::dayHasLessons($conn, $classId, $attendanceDate)) {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'code' => 'LESSON_DAY', 'message' => \App\Services\LessonAttendanceService::DAY_LOCKED_MESSAGE]);
            exit;
        }
        $yearId = $currentYear ? (int)$currentYear['id'] : null;
        $recordedBy = (int)$_SESSION['admin_id'];
        $enrollment = $conn->prepare(
            "SELECT 1 FROM class_enrollments
             WHERE member_id = ? AND class_id = ? AND status = 'active'
               AND (? IS NULL OR academic_year_id = ?)
             LIMIT 1"
        );
        $enrollment->bind_param('iiii', $memberId, $classId, $yearId, $yearId);
        $enrollment->execute();
        $isEnrolled = $enrollment->get_result()->num_rows > 0;
        $enrollment->close();
        if (!$isEnrolled) {
            echo json_encode(['status' => 'error', 'message' => 'The member is not actively enrolled in this class.']);
            exit;
        }
        
        // Upsert one explicitly selected attendance record.
        $stmt = $conn->prepare("
            INSERT INTO attendance 
            (member_id, class_id, academic_year_id, attendance_date, status, check_in_time, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                check_in_time = VALUES(check_in_time),
                notes = VALUES(notes),
                recorded_by = VALUES(recorded_by)
        ");
        $stmt->bind_param("iiissssi",
            $memberId, $classId, $yearId, $attendanceDate, 
            $status, $checkInTime, $notes, $recordedBy
        );
        
        if ($stmt->execute()) {
            // Update attendance summary for the RECORDED date's month.
            updateAttendanceSummary($conn, $memberId, $yearId, $attendanceDate);
            // Attendance alerting Phase 2: Education edits re-evaluate the member.
            \App\Services\AttendanceWarningService::safeEvaluateMany($conn, 'edu', [$memberId], $attendanceDate, $recordedBy);
            
            echo json_encode(['status' => 'success', 'message' => 'Attendance recorded!']);
        } else {
            reportInternalError('Attendance record failed', $stmt->error ?: $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to record attendance.']);
        }
        break;
    
    // ============================================================
    // BATCH RECORD ATTENDANCE
    // ============================================================
    case 'batch_attendance':
        $classId = (int)($_POST['class_id'] ?? 0);
        $attendanceDate = validateDate($_POST['attendance_date'] ?? '', date('Y-m-d'));
        $records = json_decode($_POST['records'] ?? '[]', true);
        
        if (!$classId || !is_array($records) || empty($records)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing class or records']);
            exit;
        }
        
        $yearId = $currentYear ? (int)$currentYear['id'] : null;
        $recordedBy = (int)$_SESSION['admin_id'];
        if ($attendanceDate > date('Y-m-d')) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Attendance cannot be recorded for a future date.']);
            exit;
        }
        // Phase 6: a day with lesson sheets is derived → class/day sheet read-only.
        if (\App\Services\LessonAttendanceService::dayHasLessons($conn, $classId, $attendanceDate)) {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'code' => 'LESSON_DAY', 'message' => \App\Services\LessonAttendanceService::DAY_LOCKED_MESSAGE]);
            exit;
        }
        $scope = EnrollmentService::resolveRosterYear($conn, $classId, $yearId);
        $roster = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null, ['on_date' => $attendanceDate]);
        try {
            $records = AttendanceRecordService::normalizeCompleteSheet($records, $roster);
        } catch (DomainException $error) {
            http_response_code(422);
            $safeMessage = $error->getMessage();
            echo json_encode(['status' => 'error', 'message' => $safeMessage]);
            exit;
        }

        $conn->begin_transaction();
        try {
            $successCount = AttendanceRecordService::replaceSheet(
                $conn,
                $classId,
                $attendanceDate,
                $yearId,
                $recordedBy,
                $records
            );
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            error_log('batch_attendance failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Attendance was not saved. The previous sheet is unchanged.',
            ]);
            exit;
        }

        foreach ($records as $record) {
            updateAttendanceSummary($conn, (int)$record['member_id'], $yearId, $attendanceDate);
        }
        // Attendance alerting Phase 2 (flag-gated, never fails the save).
        \App\Services\AttendanceWarningService::safeEvaluateMany(
            $conn,
            'edu',
            \App\Services\AttendanceWarningService::memberIdsFromRecords($records),
            $attendanceDate,
            $recordedBy
        );
        
        echo json_encode([
            'status' => 'success',
            'message' => "$successCount attendance records saved!"
        ]);
        break;
    
    // ============================================================
    // GET STUDENTS IN CLASS
    // ============================================================
    case 'get_class_students':
        $classId = (int)($_GET['class_id'] ?? 0);
        
        if (!$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID required']);
            exit;
        }

        $preferYear = $currentYear ? (int)$currentYear['id'] : null;
        $scope = EnrollmentService::resolveRosterYear($conn, $classId, $preferYear);
        // Optional ?date= lets sheet builders use the department status as of that day.
        $rosterDate = validateDate($_GET['date'] ?? '', date('Y-m-d'));
        $students = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null, ['on_date' => $rosterDate]);
        
        echo json_encode([
            'status' => 'success',
            'students' => $students,
            'count' => count($students),
            'roster_year_id' => $scope['year_id'] ?? null,
            'roster_year_name' => $scope['year_name'] ?? null,
            'roster_fallback' => !empty($scope['fallback']),
        ], JSON_UNESCAPED_UNICODE);
        break;
    
    // ============================================================
    // PROMOTE STUDENT
    // ============================================================
    case 'promote':
        // ATOMICITY GUARANTEE: a promotion either completes fully (old
        // enrollment closed + new enrollment created + member denormalized)
        // or makes NO changes at all. Previously the four steps ran as
        // independent autocommits, so a mid-sequence failure could leave a
        // member enrolled in two classes or in none.
        $memberId = (int)($_POST['member_id'] ?? 0);
        $fromClassId = (int)($_POST['from_class_id'] ?? 0);
        $toClassId = (int)($_POST['to_class_id'] ?? 0);

        if (!$memberId || !$fromClassId || !$toClassId) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'No active academic year']);
            exit;
        }
        if ($fromClassId === $toClassId) {
            echo json_encode(['status' => 'error', 'message' => 'Source and target class are the same.']);
            exit;
        }

        // Pre-flight validation answers with fixed messages only — nothing
        // diagnostic ever reaches the client.
        $check = $conn->prepare(
            "SELECT id FROM class_enrollments
             WHERE member_id = ? AND class_id = ? AND status = 'active' LIMIT 1"
        );
        $check->bind_param('ii', $memberId, $fromClassId);
        $check->execute();
        $sourceEnrollment = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$sourceEnrollment) {
            echo json_encode(['status' => 'error', 'message' => 'No active enrollment found in the source class.']);
            exit;
        }

        // Target class must exist.
        $classCheck = $conn->prepare('SELECT id FROM classes WHERE id = ? LIMIT 1');
        $classCheck->bind_param('i', $toClassId);
        $classCheck->execute();
        $targetClass = $classCheck->get_result()->fetch_assoc();
        $classCheck->close();
        if (!$targetClass) {
            echo json_encode(['status' => 'error', 'message' => 'Target class does not exist.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            // Mark old enrollment as completed.
            // Finding K (2026-10-02): the pre-flight SELECT above is a plain
            // read, so two concurrent promotions of the same member to
            // *different* target classes both passed it and both inserted an
            // 'active' row — verified at runtime, the member ended up active
            // in two classes. Re-assert the precondition here so only one
            // request can close the source enrollment. `unique_enrollment`
            // does not help: it is on (member_id, class_id, academic_year_id)
            // and the two targets differ.
            $stmt = $conn->prepare("UPDATE class_enrollments SET status = 'completed' WHERE id = ? AND status = 'active'");
            $stmt->bind_param("i", $sourceEnrollment['id']);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to close the source enrollment.'); }
            $closedRows = $stmt->affected_rows;
            $stmt->close();
            if ($closedRows < 1) { throw new RuntimeException('__ENROLLMENT_NOT_ACTIVE__'); }

            // Create new enrollment
            $stmt = $conn->prepare("
                INSERT INTO class_enrollments
                (member_id, class_id, academic_year_id, enrolled_at, status, promoted_from, enrolled_by)
                VALUES (?, ?, ?, CURDATE(), 'active', ?, ?)
            ");
            $enrolledBy = (int)($_SESSION['admin_id'] ?? 0);
            $yearId = (int)$currentYear['id'];
            $stmt->bind_param("iiiii", $memberId, $toClassId, $yearId, $fromClassId, $enrolledBy);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to create the new enrollment.');
            }
            $stmt->close();

            // Update member's denormalized class reference
            autoUpdateMemberClass($conn, $memberId, $toClassId, $yearId);

            // Update promoted_at (column added by migration/config auto-fix)
            try {
                $stmt = $conn->prepare("UPDATE members SET promoted_at = CURDATE() WHERE id = ?");
                $stmt->bind_param("i", $memberId);
                $stmt->execute();
                $stmt->close();
            } catch (Exception $e) { /* promoted_at column may not exist yet */ }

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Student promoted successfully!']);
        } catch (Exception $e) {
            $conn->rollback();
            if ($e->getMessage() === '__ENROLLMENT_NOT_ACTIVE__') {
                // Lost the race, or the request was replayed. Nothing written.
                http_response_code(409);
                echo json_encode(['status'=>'error','message'=>'This enrollment is no longer active — it may already have been promoted. Refresh and try again.']);
            } else {
                error_log('promote failed: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Promotion failed. No changes were made.']);
            }
        }
        break;
    

    // ============================================================
    // GET ENROLLED STUDENTS (alias for get_class_students)
    // ============================================================
    case 'get_enrolled_students':
        $classId = (int)($_GET['class_id'] ?? 0);
        $search = trim($_GET['search'] ?? '');
        $genderFilter = trim($_GET['gender'] ?? '');
        $memberTypeFilter = trim($_GET['member_type'] ?? '');
        $sortBy = trim($_GET['sort'] ?? 'name');
        if (!$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Please pick a class first.']);
            exit;
        }
        try {
            $preferYear = $currentYear ? (int)$currentYear['id'] : null;
            $scope = EnrollmentService::resolveRosterYear($conn, $classId, $preferYear);
            $students = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null, [
                'search' => $search,
                'gender' => $genderFilter,
                'member_type' => $memberTypeFilter,
                'sort' => $sortBy,
            ]);
            $stats = ['total' => count($students), 'male' => 0, 'female' => 0, 'regular' => 0, 'special_regular' => 0, 'honorary' => 0, 'teachers' => 0];
            foreach ($students as $s) {
                if (($s['gender'] ?? '') === 'male') $stats['male']++; else $stats['female']++;
                $mt = $s['member_type'] ?? 'regular';
                if (isset($stats[$mt])) $stats[$mt]++; else $stats['regular']++;
                if (!empty($s['is_teacher'])) $stats['teachers']++;
            }
            echo json_encode([
                'status' => 'success',
                'students' => $students,
                'stats' => $stats,
                'roster_year_id' => $scope['year_id'] ?? null,
                'roster_year_name' => $scope['year_name'] ?? null,
                'roster_fallback' => !empty($scope['fallback']),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Could not load enrolled students. Please try again.']);
        }
        break;

    // ============================================================
    // UNENROLL STUDENT
    // ============================================================
    case 'unenroll_student':
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        if (!$enrollmentId) {
            echo json_encode(['status' => 'error', 'message' => 'Enrollment ID required']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE class_enrollments SET status = 'withdrawn' WHERE id = ?");
        $stmt->bind_param("i", $enrollmentId);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Student removed from class']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to unenroll']);
        }
        break;

    // ============================================================
    // DASHBOARD STATS
    // ============================================================
    case 'dashboard':
        $data = [];
        try {
            $yearCond = $currentYear ? " AND ce.academic_year_id={$currentYear['id']}" : "";
            $r = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id=c.id AND ce.status='active'{$yearCond}) as student_count FROM classes c WHERE c.is_active=1 ORDER BY c.level_order");
            $data['classes'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $data['classes'][] = $row;
            
            // Also include teacher info if teacher_assignments table exists
            try {
                foreach ($data['classes'] as &$cls) {
                    $cid = (int)$cls['id'];
                    $yc = $currentYear ? " AND ta.academic_year_id={$currentYear['id']}" : "";
                    $tr = $conn->query("SELECT u.full_name FROM teacher_assignments ta JOIN users u ON ta.teacher_id=u.id WHERE ta.class_id=$cid AND ta.is_active=1{$yc} LIMIT 1");
                    $cls['teacher_name'] = ($tr && $trow = $tr->fetch_assoc()) ? $trow['full_name'] : null;
                }
                unset($cls);
            } catch (Exception $e) { /* teacher_assignments may not exist */ }
            
            echo json_encode(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            // classes table may not exist
            echo json_encode(['status' => 'success', 'data' => ['classes' => []], 'note' => 'Education tables may need setup']);
        }
        break;

    // ============================================================
    // CLASS MANAGEMENT
    // ============================================================
    // Serves two callers with different needs, so the list controls are all
    // OPT-IN. Called with no parameters it returns every class ordered by
    // level_order exactly as it always has -- that is what the class
    // management screen and the HR registration dropdown expect, and neither
    // sends the new parameters. Supply q / status / sort / page / per_page
    // and it behaves as a proper root list instead. The envelope always
    // carries total/page/per_page/pages; adding keys is backward compatible,
    // removing or reordering `classes` would not have been.
    case 'get_classes':
        $yearCond2 = $currentYear ? " AND ce.academic_year_id=" . (int)$currentYear['id'] : "";
        $clsQ = trim((string)($_GET['q'] ?? ''));
        $clsStatus = trim((string)($_GET['status'] ?? 'all'));
        $clsSort = trim((string)($_GET['sort'] ?? 'level'));
        $clsDir = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        // Paging only engages when the caller asks for it, so existing
        // consumers keep receiving the complete list.
        $clsPaged = isset($_GET['page']) || isset($_GET['per_page']);
        $clsPage = max(1, (int)($_GET['page'] ?? 1));
        $clsPerPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));

        // Allowlist: the column is chosen here, never interpolated from input.
        $clsOrderMap = [
            'level' => 'c.level_order',
            'name' => 'c.class_name',
            'code' => 'c.class_code',
            'students' => 'student_count',
        ];
        $clsOrderCol = $clsOrderMap[$clsSort] ?? 'c.level_order';

        $clsW = [];
        $clsP = [];
        $clsT = '';
        if ($clsStatus === 'active') {
            $clsW[] = 'c.is_active = 1';
        } elseif ($clsStatus === 'inactive') {
            $clsW[] = 'c.is_active = 0';
        }
        if ($clsQ !== '') {
            $clsW[] = '(c.class_name LIKE ? OR c.class_name_en LIKE ? OR c.class_code LIKE ?)';
            $clsLike = '%' . $clsQ . '%';
            array_push($clsP, $clsLike, $clsLike, $clsLike);
            $clsT .= 'sss';
        }
        $clsWhere = $clsW ? ('WHERE ' . implode(' AND ', $clsW)) : '';

        $classes = [];
        $clsTotal = 0;
        $clsFallback = false;
        try {
            $countSql = "SELECT COUNT(*) AS total FROM classes c $clsWhere";
            if ($clsT !== '') {
                $st = $conn->prepare($countSql);
                $st->bind_param($clsT, ...$clsP);
                $st->execute();
                $clsTotal = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
                $st->close();
            } else {
                $rc = $conn->query($countSql);
                $clsTotal = $rc ? (int)$rc->fetch_assoc()['total'] : 0;
            }

            $sql = "SELECT c.*, COALESCE((SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id=c.id AND ce.status='active'{$yearCond2}), 0) as student_count
                    FROM classes c
                    $clsWhere
                    ORDER BY $clsOrderCol $clsDir, c.level_order ASC";
            $clsFp = $clsP;
            $clsFt = $clsT;
            if ($clsPaged) {
                $sql .= " LIMIT ? OFFSET ?";
                $clsFp[] = $clsPerPage;
                $clsFp[] = ($clsPage - 1) * $clsPerPage;
                $clsFt .= 'ii';
            }
            if ($clsFt !== '') {
                $st = $conn->prepare($sql);
                $st->bind_param($clsFt, ...$clsFp);
                $st->execute();
                $r = $st->get_result();
                while ($row = $r->fetch_assoc()) $classes[] = $row;
                $st->close();
            } else {
                $r = $conn->query($sql);
                if ($r) {
                    while ($row = $r->fetch_assoc()) $classes[] = $row;
                }
            }
        } catch (Exception $e) {
            // class_enrollments or classes table may not exist yet
            $clsFallback = true;
            try {
                $r2 = $conn->query("SELECT c.*, 0 as student_count FROM classes c ORDER BY c.level_order");
                if ($r2) while ($row = $r2->fetch_assoc()) $classes[] = $row;
                $clsTotal = count($classes);
            } catch (Exception $e2) { /* classes table doesn't exist */ }
        }
        echo json_encode([
            'status' => 'success',
            'classes' => $classes,
            'total' => $clsFallback ? count($classes) : $clsTotal,
            'page' => $clsPaged ? $clsPage : 1,
            'per_page' => $clsPaged ? $clsPerPage : max(1, count($classes)),
            'pages' => $clsPaged && $clsPerPage > 0 ? (int)ceil($clsTotal / $clsPerPage) : 1,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save_class':
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['class_name'] ?? '');
        $nameEn = trim($_POST['class_name_en'] ?? '');
        $code = trim($_POST['class_code'] ?? '');
        $level = (int)($_POST['level_order'] ?? 0);
        $section = trim($_POST['section'] ?? '');
        $ageGroup = trim((string)($_POST['age_group'] ?? ''));
        // P71 — single source of truth (App\Services\MemberCategory):
        // section IS the age group's name. The UI posts one Section/Age
        // select (the age_group code); the section name is always DERIVED
        // server-side so the stored pair can never disagree. A legacy
        // client that posts only a section name still resolves through
        // the canonical map; unknown values are rejected, never guessed.
        if ($ageGroup !== '' && !in_array($ageGroup, MemberCategory::groups(), true)) {
            echo json_encode(['status' => 'error', 'message' => 'Unknown section / age group. Please pick one of the sections listed in the form.']);
            exit;
        }
        if ($ageGroup === '') {
            $ageGroup = MemberCategory::ageGroupForSectionAm($section) ?? '';
        }
        $section = $ageGroup !== '' ? (MemberCategory::sectionAm($ageGroup) ?? '') : '';
        // ENUM columns reject empty strings — convert to NULL
        if ($ageGroup === '' || $ageGroup === null) {
            $ageGroup = null;
        }
        $desc = trim($_POST['description'] ?? '');
        $isActive = (int)($_POST['is_active'] ?? 1);
        if (!$name || !$code) {
            echo json_encode(['status' => 'error', 'message' => 'Name and code required']);
            exit;
        }
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE classes SET class_name=?,class_name_en=?,class_code=?,level_order=?,section=?,age_group=?,description=?,is_active=? WHERE id=?");
            $stmt->bind_param("sssisssii", $name, $nameEn, $code, $level, $section, $ageGroup, $desc, $isActive, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO classes (class_name,class_name_en,class_code,level_order,section,age_group,description,is_active) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param("sssisssi", $name, $nameEn, $code, $level, $section, $ageGroup, $desc, $isActive);
        }
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Class saved', 'id' => $id ?: $conn->insert_id]);
        } else {
            reportInternalError('Class save failed', $stmt->error ?: $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to save the class.']);
        }
        break;

    case 'delete_class':
        $id = (int)($_POST['class_id'] ?? 0);
        if (!$id) { echo json_encode(['status'=>'error','message'=>'ID required']); exit; }
        
        // Use prepared statement to check enrollments
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM class_enrollments WHERE class_id = ? AND status = 'active'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        
        if ($cnt > 0) {
            echo json_encode(['status'=>'error','message'=>"Cannot delete: $cnt active enrollments"]);
        } elseif (class_exists('\\App\\Services\\EnrollmentService')
            && \App\Services\EnrollmentService::classHasGradeHistory($conn, $id)) {
            // H4: the class carries grade/assessment history — deactivate it so
            // reports and transcripts stay intact instead of orphaning them.
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE classes SET is_active = 0 WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                // Assignments to a deactivated class stop appearing everywhere.
                $stmt = $conn->prepare("UPDATE teacher_assignments SET is_active = 0 WHERE class_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                echo json_encode(['status'=>'success','message'=>'Class deactivated (it has grade history). Records are preserved.']);
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        } else {
            // No active enrollments, no grade history: hard delete the class
            // AND every dependent row in one transaction (H4 orphan cleanup).
            try {
                $conn->begin_transaction();
                foreach (
                    [
                        "DELETE FROM class_subjects WHERE class_id = ?",
                        "DELETE FROM teacher_assignments WHERE class_id = ?",
                        "DELETE FROM timetable_entries WHERE class_id = ?",
                        "DELETE FROM grade_submissions WHERE class_id = ?",
                        "DELETE FROM assessments WHERE class_id = ?",
                        "DELETE FROM class_enrollments WHERE class_id = ?",
                        "DELETE FROM classes WHERE id = ?",
                    ] as $sql
                ) {
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                echo json_encode(['status'=>'success','message'=>'Class deleted']);
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }
        break;

    case 'toggle_class':
        $id = (int)($_POST['class_id'] ?? 0);
        if (!$id) { echo json_encode(['status'=>'error','message'=>'ID required']); exit; }
        $stmt = $conn->prepare("SELECT class_name, is_active FROM classes WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $cls = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$cls) { echo json_encode(['status'=>'error','message'=>'Class not found.']); exit; }
        $newState = ((int)$cls['is_active'] === 1) ? 0 : 1;
        $stmt = $conn->prepare("UPDATE classes SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $newState, $id);
        $stmt->execute();
        $stmt->close();
        try {
            $aStmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if ($aStmt) {
                $aAction = $newState === 1 ? 'Class Restored' : 'Class Deactivated';
                $aDetails = "Class '{$cls['class_name']}' (id {$id}) " . ($newState === 1 ? 're-activated.' : 'deactivated.');
                $aUid = (int)($_SESSION['admin_id'] ?? 0);
                $aUser = (string)($_SESSION['admin_username'] ?? 'admin');
                $aIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                $aStmt->bind_param('issss', $aUid, $aUser, $aAction, $aDetails, $aIp);
                $aStmt->execute();
                $aStmt->close();
            }
        } catch (Throwable $e) {
            // Auditing must never break the managing action itself.
        }
        echo json_encode([
            'status' => 'success',
            'message' => $newState === 1 ? 'Class restored and active again.' : 'Class deactivated.',
            'is_active' => $newState,
        ]);
        break;

    // ============================================================
    // ACADEMIC YEAR MANAGEMENT
    // ============================================================
    case 'get_academic_years':
        try {
            $r = $conn->query("SELECT ay.*, COALESCE((SELECT COUNT(*) FROM academic_terms WHERE academic_year_id=ay.id), 0) as term_count FROM academic_years ay ORDER BY ay.ec_year DESC, ay.id DESC");
            $years = [];
            if ($r) {
                while ($row = $r->fetch_assoc()) $years[] = $row;
            } else {
                // academic_terms might not exist — try without
                $r2 = $conn->query("SELECT ay.*, 0 as term_count FROM academic_years ay ORDER BY ay.id DESC");
                if ($r2) while ($row = $r2->fetch_assoc()) $years[] = $row;
            }
            echo json_encode(['status' => 'success', 'years' => $years]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'success', 'years' => []]);
        }
        break;

    case 'save_academic_year':
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['year_name'] ?? '');
        $ecYear = (int)($_POST['ec_year'] ?? 0);
        $yearGc = trim($_POST['year_gc'] ?? '');
        $start = trim($_POST['start_date'] ?? '');
        $end = trim($_POST['end_date'] ?? '');
        $isCurrent = (int)($_POST['is_current'] ?? 0);
        if (!$name) { echo json_encode(['status'=>'error','message'=>'Year name required']); exit; }

    // ── Date integrity: optional but, when present, valid and ordered ──
    $dateErr = _ay_check_date_pair($start, $end, 'Academic year');
    if ($dateErr !== null) { echo json_encode(['status'=>'error','message'=>$dateErr]); exit; }

    // ── Semester weights (migration 056) ────────────────────────────────────
    // Optional: only touched when BOTH fields are posted, so existing callers
    // that know nothing about weights never overwrite a configured split.
    // Validation is server-side and authoritative — the browser check is a
    // convenience, not the rule. Invalid values are REJECTED, never silently
    // normalised to 50/50.
    $rawS1 = $_POST['s1_weight_pct'] ?? null;
    $rawS2 = $_POST['s2_weight_pct'] ?? null;
    $weightsProvided = ($rawS1 !== null && $rawS1 !== '' && $rawS2 !== null && $rawS2 !== '');
    $s1w = null; $s2w = null;
    if ($weightsProvided) {
        require_once __DIR__ . '/backend/services/SubjectDurationPolicy.php';
        if (!is_numeric($rawS1) || !is_numeric($rawS2)) {
            echo json_encode(['status'=>'error','message'=>'Semester weights must be numbers.']);
            exit;
        }
        $s1w = (float)$rawS1;
        $s2w = (float)$rawS2;
        if ($s1w < 0 || $s2w < 0 || $s1w > 100 || $s2w > 100) {
            echo json_encode(['status'=>'error','message'=>'Each semester weight must be between 0 and 100.']);
            exit;
        }
        if (!\App\Services\SubjectDurationPolicy::weightsAreValid($s1w, $s2w)) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Semester 1 and Semester 2 weights must add up to exactly 100% (got '
                             . rtrim(rtrim(number_format($s1w, 2, '.', ''), '0'), '.') . '% + '
                             . rtrim(rtrim(number_format($s2w, 2, '.', ''), '0'), '.') . '%).',
            ]);
            exit;
        }
    }
        
        // Convert empty strings to NULL for DATE columns
        $startDate = ($start !== '') ? $start : null;
        $endDate = ($end !== '') ? $end : null;
        $ecYearVal = ($ecYear > 0) ? $ecYear : null;
        $yearGcVal = ($yearGc !== '') ? $yearGc : null;
        
        // LIFECYCLE: this form NEVER flips the active year directly. A new year
        // is created as 'upcoming'; it becomes active only through the explicit
        // "Set Active" switch (ay_switch_active) with typed confirmation. The
        // one exception is first-time setup: if no active year exists yet, a
        // newly created year is auto-activated (nothing to close). This keeps a
        // single source of truth and makes two-active-years impossible.
        $isCurrent = 0; // ignored for lifecycle; kept for backward-compat only.

        // M3: application-level uniqueness guard. Migration 018 adds the real
        // UNIQUE(year_name) index (skipped automatically on deployments that
        // still contain duplicate names); this pre-check enforces uniqueness
        // even where that migration has not run yet.
        try {
            if ($id > 0) {
                $dupCheck = $conn->prepare('SELECT id FROM academic_years WHERE year_name = ? AND id <> ? LIMIT 1');
                $dupCheck->bind_param('si', $name, $id);
            } else {
                $dupCheck = $conn->prepare('SELECT id FROM academic_years WHERE year_name = ? LIMIT 1');
                $dupCheck->bind_param('s', $name);
            }
            $dupCheck->execute();
            $duplicateYear = $dupCheck->get_result()->fetch_assoc();
            $dupCheck->close();
            if ($duplicateYear) {
                echo json_encode(['status'=>'error','message'=>'An academic year with this name already exists — please choose a different year name.']);
                exit;
            }
        } catch (Exception $dupError) {
            // Fall through to the write path; the UNIQUE index (when present)
            // still rejects duplicates with errno 1062 handled below.
        }

        try {
            if ($id > 0) {
                // UPDATE existing — descriptive fields only. The active-year
                // lifecycle (status/is_current) changes ONLY through the explicit
                // "Set Active" switch, never from this edit form.
            if ($weightsProvided) {
                $sql = "UPDATE academic_years SET year_name=?, ec_year=?, year_gc=?, start_date=?, end_date=?, s1_weight_pct=?, s2_weight_pct=? WHERE id=?";
            } else {
                $sql = "UPDATE academic_years SET year_name=?, ec_year=?, year_gc=?, start_date=?, end_date=? WHERE id=?";
            }
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                reportInternalError('Academic year update prepare failed', $conn->error);
                echo json_encode(['status'=>'error','message'=>'Academic year storage is temporarily unavailable.']);
                exit;
            }
            if ($weightsProvided) {
                $stmt->bind_param("sisssddi", $name, $ecYearVal, $yearGcVal, $startDate, $endDate, $s1w, $s2w, $id);
            } else {
                $stmt->bind_param("sisssi", $name, $ecYearVal, $yearGcVal, $startDate, $endDate, $id);
            }
                if ($stmt->execute()) {
                    // Non-blocking sanity note: overlapping year dates are
                    // tolerated (mid-year corrections happen) but surfaced.
                    $warnings = [];
                    try {
                        $ov = $conn->prepare("SELECT year_name FROM academic_years WHERE id<>? AND start_date IS NOT NULL AND end_date IS NOT NULL AND start_date<=? AND end_date>=?");
                        $ov->bind_param('iss', $id, $endDate, $startDate);
                        $ov->execute();
                        $wr = $ov->get_result();
                        while ($w = $wr->fetch_assoc()) $warnings[] = 'Dates overlap with '.$w['year_name'].' — allowed, but check that it is intended.';
                        $ov->close();
                    } catch (Exception $eOv) {}
                    echo json_encode(['status'=>'success','message'=>'Academic year updated','id'=>$id,'warnings'=>$warnings]);
                } else {
                    if ($stmt->errno == 1062) {
                        echo json_encode(['status'=>'error','message'=>'An academic year with this name already exists — please choose a different year name.']);
                    } else {
                        reportInternalError('Academic year update failed', $stmt->error);
                        echo json_encode(['status'=>'error','message'=>'Unable to update the academic year.']);
                    }
                }
            } else {
                // INSERT new — always starts as 'upcoming' (is_current=0). It
                // becomes active only via the explicit "Set Active" switch.
                $sql = "INSERT INTO academic_years (year_name, ec_year, year_gc, start_date, end_date, is_current) VALUES (?,?,?,?,?,0)";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    reportInternalError('Academic year insert prepare failed', $conn->error);
                    echo json_encode(['status'=>'error','message'=>'Academic year storage is temporarily unavailable.']);
                    exit;
                }
                $stmt->bind_param("sisss", $name, $ecYearVal, $yearGcVal, $startDate, $endDate);
            if ($stmt->execute()) {
                $newId = $conn->insert_id;
                // Semester weights, when the form supplied them. Validated above.
                // Left at the column default (50/50) otherwise.
                if ($newId && $weightsProvided) {
                    $wStmt = $conn->prepare("UPDATE academic_years SET s1_weight_pct=?, s2_weight_pct=? WHERE id=?");
                    if ($wStmt) {
                        $wStmt->bind_param("ddi", $s1w, $s2w, $newId);
                        $wStmt->execute();
                        $wStmt->close();
                    }
                }
                // Auto-create 2 semesters
                    if ($newId) {
                        try {
                            $term1 = '1ኛ ሴሚስተር'; $term2 = '2ኛ ሴሚስተር';
                            $stmt2 = $conn->prepare("INSERT IGNORE INTO academic_terms (academic_year_id, term_name, term_number, is_current) VALUES (?,?,?,?)");
                            if ($stmt2) {
                                $tn1=1; $tn2=2; $cur1=1; $cur0=0;
                                $stmt2->bind_param("isii", $newId, $term1, $tn1, $cur1);
                                $stmt2->execute();
                                $stmt2->bind_param("isii", $newId, $term2, $tn2, $cur0);
                                $stmt2->execute();
                            }
                        } catch (Exception $e) { /* terms table might not exist */ }
                    }
                    // Bootstrap: if there is no active year yet, make this one
                    // active — there is no running year to close, so it is safe.
                    $activated = false;
                    if ($newId) {
                        $hasActive = false;
                        try {
                            $ac = $conn->query("SELECT COUNT(*) c FROM academic_years WHERE status='active'");
                            if ($ac) $hasActive = ((int)$ac->fetch_assoc()['c'] > 0);
                        } catch (Exception $e) {
                            try { $ac = $conn->query("SELECT COUNT(*) c FROM academic_years WHERE is_current=1"); if ($ac) $hasActive = ((int)$ac->fetch_assoc()['c'] > 0); } catch (Exception $e2) {}
                        }
                        if (!$hasActive) {
                            if (function_exists('ay_switch_active')) {
                                $sw = ay_switch_active($conn, $newId, false);
                                $activated = (($sw['status'] ?? '') === 'success');
                            } else {
                                $conn->query("UPDATE academic_years SET is_current=1 WHERE id=".(int)$newId);
                                $activated = true;
                            }
                        }
                    }
                    $msg = $activated
                        ? 'Academic year created and set as the ACTIVE year (first year).'
                        : 'Academic year created with 2 semesters. Use "Set Active" to make it the current year.';
                    // Non-blocking sanity note (same rule as the update path).
                    $warnings = [];
                    try {
                        $ov = $conn->prepare("SELECT year_name FROM academic_years WHERE id<>? AND start_date IS NOT NULL AND end_date IS NOT NULL AND start_date<=? AND end_date>=?");
                        $ov->bind_param('iss', $newId, $endDate, $startDate);
                        $ov->execute();
                        $wr = $ov->get_result();
                        while ($w = $wr->fetch_assoc()) $warnings[] = 'Dates overlap with '.$w['year_name'].' — allowed, but check that it is intended.';
                        $ov->close();
                    } catch (Exception $eOv) {}
                    echo json_encode(['status'=>'success','message'=>$msg,'id'=>$newId,'activated'=>$activated,'warnings'=>$warnings]);
                } else {
                    if ($stmt->errno == 1062) {
                        echo json_encode(['status'=>'error','message'=>'An academic year with this name already exists — please choose a different year name.']);
                    } else {
                        reportInternalError('Academic year insert failed', $stmt->error);
                        echo json_encode(['status'=>'error','message'=>'Unable to create the academic year.']);
                    }
                }
            }
        } catch (Exception $e) {
            // PHP 8.1+ mysqli raises SQL errors as exceptions (MYSQLI_REPORT_
            // ERROR|STRICT default), so the friendly errno branches below the
            // execute() calls are unreachable — every SQL failure lands HERE.
            // Map the two expectable errors to actionable messages and attach
            // the log reference (reportInternalError) so any residual failure
            // points straight at its log line instead of failing blindly.
            $ref = reportInternalError('Academic year save failed', $e);
            $code = ($e instanceof mysqli_sql_exception) ? (int)$e->getCode() : 0;
            if ($code === 1062) {
                echo json_encode(['status'=>'error','message'=>'An academic year with this name already exists — please choose a different year name.']);
            } elseif ($code === 1366) {
                echo json_encode(['status'=>'error','message'=>'The year name contains characters this database table cannot store (charset mismatch). Run sql/066_academic_year_charset_repair.sql on the database, then try again. (ref SSMS:'.$ref.')']);
            } else {
                echo json_encode(['status'=>'error','message'=>'Unable to save the academic year. (ref SSMS:'.$ref.($code !== 0 ? ', SQL '.$code : '').')']);
            }
        }
        break;

    case 'set_current_year':
        // STEP 4 — SAFE, ATOMIC active-year switch. Never leaves zero or two
        // active years. Requires typed confirmation; reopening a CLOSED (past)
        // year requires the stronger "REOPEN" confirmation.
        $yid = (int)($_POST['year_id'] ?? 0);
        if (!$yid) { echo json_encode(['status'=>'error','message'=>'Year ID required']); exit; }

        if (!function_exists('ay_switch_active')) {
            // Fallback: legacy transactional flip (resolver unavailable).
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE academic_years SET is_current=0");
                $stmt = $conn->prepare("UPDATE academic_years SET is_current=1 WHERE id = ?");
                if (!$stmt) { throw new Exception($conn->error); }
                $stmt->bind_param("i", $yid);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                echo json_encode(['status'=>'success','message'=>'Current year updated']);
            } catch (Exception $e) {
                $conn->rollback();
                error_log("set_current_year failed: " . $e->getMessage());
                echo json_encode(['status'=>'error','message'=>'Could not change the current year. No changes were made.']);
            }
            break;
        }

        $target = ay_year_by_id($conn, $yid);
        if (!$target) { echo json_encode(['status'=>'error','message'=>'That academic year does not exist.']); break; }
        $tstatus = $target['status'] ?? ((int)($target['is_current'] ?? 0) === 1 ? 'active' : 'upcoming');

        if ($tstatus === 'active') {
            echo json_encode(['status'=>'success','message'=>'That year is already the active year.']);
            break;
        }

        $reopen  = ($tstatus === 'closed');
        $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
        $need    = $reopen ? 'REOPEN' : 'SWITCH';
        if ($confirm !== $need) {
            echo json_encode([
                'status'             => 'error',
                'code'               => $reopen ? 'confirm_reopen' : 'confirm_switch',
                'needs_confirmation' => true,
                'reopen'             => $reopen,
                'target_name'        => $target['year_name'] ?? '',
                'message'            => $reopen
                    ? 'This year is CLOSED. Reopening a past year makes it active again and NEW records will be stamped to it. This is unusual — only do it to correct a mistake. Type REOPEN to confirm.'
                    : 'Switching the active year will CLOSE the current year. New records will then belong to the newly selected year. Type SWITCH to confirm.'
            ]);
            break;
        }

        $res = ay_switch_active($conn, $yid, $reopen);
        echo json_encode($res);
        break;

    case 'delete_year':
        // STEP 6 — deletion protection. Only an EMPTY 'upcoming' year may be
        // deleted. The active year and any closed (past) year — and any year
        // that already holds records — are permanently protected.
        $yid = (int)($_POST['year_id'] ?? 0);
        if (!$yid) { echo json_encode(['status'=>'error','message'=>'Year ID required']); exit; }
        $target = function_exists('ay_year_by_id') ? ay_year_by_id($conn, $yid) : null;
        if (!$target) {
            $rr = $conn->query("SELECT * FROM academic_years WHERE id=".(int)$yid." LIMIT 1");
            $target = $rr ? $rr->fetch_assoc() : null;
        }
        if (!$target) { echo json_encode(['status'=>'error','message'=>'That academic year does not exist.']); break; }
        $tstatus = $target['status'] ?? ((int)($target['is_current'] ?? 0) === 1 ? 'active' : 'upcoming');
        if ($tstatus === 'active') { echo json_encode(['status'=>'error','message'=>'The ACTIVE year cannot be deleted. Switch to another year first.']); break; }
        if ($tstatus === 'closed') { echo json_encode(['status'=>'error','message'=>'Closed (past) years are permanently protected and cannot be deleted.']); break; }
        // 'upcoming' — allowed only if it holds NO year-scoped records.
        $recCount = 0;
        foreach (['class_enrollments','attendance','academic_records','teacher_assignments','submissions','assessments'] as $tbl) {
            try { $rc = $conn->query("SELECT COUNT(*) c FROM `$tbl` WHERE academic_year_id=".(int)$yid); if ($rc) $recCount += (int)$rc->fetch_assoc()['c']; } catch (Exception $e) {}
        }
        if ($recCount > 0) { echo json_encode(['status'=>'error','message'=>"This year already holds $recCount record(s) and cannot be deleted. Only an empty upcoming year can be removed."]); break; }
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM academic_terms WHERE academic_year_id=".(int)$yid);
            $st = $conn->prepare("DELETE FROM academic_years WHERE id=?");
            if (!$st) { throw new Exception($conn->error); }
            $st->bind_param('i', $yid); $st->execute(); $st->close();
            $conn->commit();
            echo json_encode(['status'=>'success','message'=>'Empty upcoming year deleted.']);
        } catch (Exception $e) {
            $conn->rollback();
            error_log('delete_year failed: '.$e->getMessage());
            echo json_encode(['status'=>'error','message'=>'Could not delete the year. No changes were made.']);
        }
        break;

    case 'get_terms':
        $yid = (int)($_GET['year_id'] ?? 0);
        $terms = [];
        try {
            $stmt = $conn->prepare("SELECT * FROM academic_terms WHERE academic_year_id = ? ORDER BY term_number");
            if ($stmt) {
                $stmt->bind_param("i", $yid);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) $terms[] = $row;
                $stmt->close();
            }
        } catch (Exception $e) { /* table may not exist */ }
        echo json_encode(['status'=>'success','terms'=>$terms]);
        break;

    case 'save_term':
        $tid = (int)($_POST['term_id'] ?? 0);
        $ayid = (int)($_POST['academic_year_id'] ?? 0);
        $tname = trim($_POST['term_name'] ?? '');
        $tnum = (int)($_POST['term_number'] ?? 1);
        $tstart = trim($_POST['start_date'] ?? '');
        $tend = trim($_POST['end_date'] ?? '');
        $tstartVal = ($tstart !== '') ? $tstart : null;
        $tendVal = ($tend !== '') ? $tend : null;
        if (!$tname) { echo json_encode(['status'=>'error','message'=>'Semester name required']); exit; }

        // ── Date integrity: optional, but valid and ordered when present ──
        $dateErr = _ay_check_date_pair($tstart, $tend, 'Semester');
        if ($dateErr !== null) { echo json_encode(['status'=>'error','message'=>$dateErr]); exit; }
        $tstartVal = ($tstart !== '') ? $tstart : null;
        $tendVal = ($tend !== '') ? $tend : null;

        // ── Containment: a semester's dates must sit inside its year's dates
        //    (checked only when BOTH the semester and the year carry dates —
        //    PowerSchool / Microsoft SDS rule). Blank semester dates stay OK:
        //    schools often set the year before the term calendar is published.
        if ($tstartVal !== null && $tendVal !== null) {
            $parentYearId = $ayid;
            if ($tid > 0 && $parentYearId <= 0) {
                try {
                    $py = $conn->prepare("SELECT academic_year_id FROM academic_terms WHERE id=? LIMIT 1");
                    $py->bind_param('i', $tid); $py->execute();
                    $row = $py->get_result()->fetch_assoc(); $py->close();
                    $parentYearId = (int)($row['academic_year_id'] ?? 0);
                } catch (Exception $ePy) {}
            }
            if ($parentYearId > 0) {
                try {
                    $yr = $conn->prepare("SELECT year_name, start_date, end_date FROM academic_years WHERE id=? LIMIT 1");
                    $yr->bind_param('i', $parentYearId); $yr->execute();
                    $yrow = $yr->get_result()->fetch_assoc(); $yr->close();
                    if ($yrow && $yrow['start_date'] && $yrow['end_date']
                            && ($tstartVal < $yrow['start_date'] || $tendVal > $yrow['end_date'])) {
                        echo json_encode(['status'=>'error','message'=>'Semester dates must fall within the year\'s dates ('.$yrow['start_date'].' to '.$yrow['end_date'].').']);
                        exit;
                    }
                } catch (Exception $eYr) {}
            }
        }
        try {
            if ($tid > 0) {
                // UPDATE existing term
                $stmt = $conn->prepare("UPDATE academic_terms SET term_name=?, term_number=?, start_date=?, end_date=? WHERE id=?");
                if ($stmt) {
                    $stmt->bind_param("sissi", $tname, $tnum, $tstartVal, $tendVal, $tid);
                    if ($stmt->execute()) echo json_encode(['status'=>'success','message'=>'Semester updated']);
                    else { reportInternalError('Academic term update failed', $stmt->error); echo json_encode(['status'=>'error','message'=>'Unable to update the semester.']); }
                } else {
                    reportInternalError('Academic term update prepare failed', $conn->error);
                    echo json_encode(['status'=>'error','message'=>'Semester storage is temporarily unavailable.']);
                }
            } else {
                // INSERT new term
                if (!$ayid) { echo json_encode(['status'=>'error','message'=>'Year ID required']); exit; }
                $stmt = $conn->prepare("INSERT INTO academic_terms (academic_year_id, term_name, term_number, start_date, end_date, is_current) VALUES (?,?,?,?,?,0)");
                if ($stmt) {
                    $stmt->bind_param("isiss", $ayid, $tname, $tnum, $tstartVal, $tendVal);
                    if ($stmt->execute()) echo json_encode(['status'=>'success','message'=>'Semester added']);
                    else { reportInternalError('Academic term insert failed', $stmt->error); echo json_encode(['status'=>'error','message'=>'Unable to add the semester.']); }
                } else {
                    reportInternalError('Academic term insert prepare failed', $conn->error);
                    echo json_encode(['status'=>'error','message'=>'Semester storage is temporarily unavailable.']);
                }
            }
        } catch (Exception $e) {
            // Same exception-mode hardening as save_academic_year above.
            $ref = reportInternalError('Academic term save failed', $e);
            $code = ($e instanceof mysqli_sql_exception) ? (int)$e->getCode() : 0;
            if ($code === 1366) {
                echo json_encode(['status'=>'error','message'=>'The semester name contains characters this database table cannot store (charset mismatch). Run sql/066_academic_year_charset_repair.sql on the database, then try again. (ref SSMS:'.$ref.')']);
            } else {
                echo json_encode(['status'=>'error','message'=>'Unable to save the semester. (ref SSMS:'.$ref.($code !== 0 ? ', SQL '.$code : '').')']);
            }
        }
        break;

    case 'set_current_term':
        $tid = (int)($_POST['term_id'] ?? 0);
        if (!$tid) { echo json_encode(['status'=>'error','message'=>'Term ID required']); exit; }
        // 2026-10-08 fix: the flag used to be cleared GLOBALLY and could be
        // set on a semester of ANY year — including a closed one — while the
        // active year ran. Now the target must belong to the ACTIVE year, the
        // reset is scoped to that year, and the action is refused while
        // time-travelling (viewing a past year).
        if (function_exists('ay_block_if_readonly')) ay_block_if_readonly($conn);
        $activeId = 0;
        if (function_exists('ay_resolve')) {
            $activeId = (int)ay_resolve($conn)['active_id'];
        }
        if ($activeId <= 0) {
            try {
                $ar = $conn->query("SELECT id FROM academic_years WHERE is_current=1 LIMIT 1");
                if ($ar) $activeId = (int)($ar->fetch_assoc()['id'] ?? 0);
            } catch (Exception $eAr) {}
        }
        if ($activeId <= 0) { echo json_encode(['status'=>'error','message'=>'No active academic year is set. A School Admin must set the current year first.']); exit; }
        try {
            $tr = $conn->prepare("SELECT academic_year_id, term_name FROM academic_terms WHERE id=? LIMIT 1");
            $tr->bind_param('i', $tid); $tr->execute();
            $trow = $tr->get_result()->fetch_assoc(); $tr->close();
            if (!$trow) { echo json_encode(['status'=>'error','message'=>'That semester does not exist.']); exit; }
            if ((int)$trow['academic_year_id'] !== $activeId) {
                echo json_encode(['status'=>'error','message'=>'Only a semester of the ACTIVE academic year can be set as the current one.']);
                exit;
            }
            $conn->query("UPDATE academic_terms SET is_current=0 WHERE academic_year_id=".(int)$activeId);
            // 1.6.5 term-close model: flipping the current semester CLOSES
            // every reopen-for-corrections window of this year. An old
            // semester can never stay silently writable after the school
            // has moved on; the department reopens one deliberately.
            try { $conn->query("UPDATE academic_terms SET is_reopened=0 WHERE academic_year_id=".(int)$activeId." AND is_reopened<>0"); } catch (Exception $eRr) {}
            $stmt = $conn->prepare("UPDATE academic_terms SET is_current=1 WHERE id = ?");
            $stmt->bind_param('i', $tid);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['status'=>'success','message'=>'Current semester updated']);
        } catch (Exception $eCur) {
            error_log('set_current_term failed: '.$eCur->getMessage());
            echo json_encode(['status'=>'error','message'=>'Could not update the current semester. No changes were made.']);
        }
        break;

    // ── 1.6.5 term-close model: reopen / close correction windows ──────────
    // A closed semester is read-only for teachers (they can still view it
    // for analysis). When a correction is needed, the Education Department
    // reopens the semester: teachers may then edit its assessments exactly
    // as in the current semester, until the department closes the window
    // again or a new current semester is set (which re-closes everything).
    // Same tier as set_current_term (edu_dept / school_admin / super_admin).
    case 'reopen_term':
    case 'close_term_reopen':
        $tid = (int)($_POST['term_id'] ?? 0);
        $opening = ($action === 'reopen_term');
        if (!$tid) { echo json_encode(['status'=>'error','message'=>'Term ID required']); exit; }
        if (function_exists('ay_block_if_readonly')) ay_block_if_readonly($conn);
        try {
            $activeId = 0;
            if (function_exists('ay_resolve')) {
                $activeId = (int)ay_resolve($conn)['active_id'];
            }
            if ($activeId <= 0) {
                $ar = $conn->query("SELECT id FROM academic_years WHERE is_current=1 LIMIT 1");
                if ($ar) $activeId = (int)($ar->fetch_assoc()['id'] ?? 0);
            }
            if ($activeId <= 0) { echo json_encode(['status'=>'error','message'=>'No active academic year is set.']); exit; }
            $tr = $conn->prepare("SELECT academic_year_id, term_name, is_current FROM academic_terms WHERE id=? LIMIT 1");
            $tr->bind_param('i', $tid); $tr->execute();
            $trow = $tr->get_result()->fetch_assoc(); $tr->close();
            if (!$trow) { echo json_encode(['status'=>'error','message'=>'That semester does not exist.']); exit; }
            if ((int)$trow['academic_year_id'] !== $activeId) {
                echo json_encode(['status'=>'error','message'=>'Only a semester of the ACTIVE academic year can be reopened.']); exit;
            }
            if ((int)$trow['is_current'] === 1) {
                echo json_encode(['status'=>'error','message'=>'The current semester is already open for grade entry.']); exit;
            }
            $stmt = $conn->prepare("UPDATE academic_terms SET is_reopened = ? WHERE id = ?");
            $flag = $opening ? 1 : 0;
            $stmt->bind_param('ii', $flag, $tid);
            $stmt->execute(); $stmt->close();
            echo json_encode(['status'=>'success','message'=>$opening
                ? ('Semester "'.($trow['term_name'] ?? '').'" reopened for corrections. Teachers can now edit it until it is closed again or a new current semester is set.')
                : ('Correction window for "'.($trow['term_name'] ?? '').'" closed. The semester is read-only for teachers again.')]);
        } catch (Exception $eRe) {
            error_log($action.' failed: '.$eRe->getMessage());
            echo json_encode(['status'=>'error','message'=>'Could not update the correction window. No changes were made. (If this keeps failing, run sql/067_semester_reopen_flag.sql on the database.)']);
        }
        break;

    case 'delete_term':
        $tid = (int)($_POST['term_id'] ?? 0);
        if (!$tid) { echo json_encode(['status'=>'error','message'=>'Term ID required']); exit; }
        // STEP 6 — protect historical data: semesters of a CLOSED (past) year
        // cannot be deleted.
        try {
            $chk = $conn->prepare("SELECT ay.status FROM academic_terms t JOIN academic_years ay ON ay.id=t.academic_year_id WHERE t.id=?");
            if ($chk) {
                $chk->bind_param('i', $tid);
                $chk->execute();
                $row = $chk->get_result()->fetch_assoc();
                $chk->close();
                if ($row && ($row['status'] ?? '') === 'closed') {
                    echo json_encode(['status'=>'error','message'=>'This semester belongs to a closed (past) year and is protected. It cannot be deleted.']);
                    break;
                }
            }
        } catch (Exception $e) { /* status column may not exist yet — allow */ }
        // F1 (2026-10-21): a semester that already holds assessments, grades or
        // mark lists is protected — deleting it would orphan that history.
        $__use = __edu_term_usage($conn, $tid);
        if (($__use['assessments'] + $__use['grades'] + $__use['grade_submissions']) > 0) {
            echo json_encode([
                'status'  => 'error',
                'code'    => 'term_in_use',
                'counts'  => $__use,
                'message' => 'This semester cannot be deleted: it has ' . $__use['assessments'] . ' assessment(s), '
                    . $__use['grades'] . ' grade record(s) and ' . $__use['grade_submissions'] . ' mark list(s). '
                    . 'Deleting it would orphan that history. Nothing was changed.',
            ]);
            break;
        }
        $stmt = $conn->prepare("DELETE FROM academic_terms WHERE id = ?");
        $stmt->bind_param("i", $tid);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status'=>'success','message'=>'Semester deleted']);
        break;

    // ============================================================
    // BULK ENROLL STUDENTS
    // ============================================================
    case 'bulk_enroll':
        $classId = (int)($_POST['class_id'] ?? 0);
        $memberIds = json_decode($_POST['member_ids'] ?? '[]', true);
        if (!$classId || empty($memberIds)) {
            echo json_encode(['status' => 'error', 'message' => 'Pick a class and at least one student.', 'field' => !$classId ? 'class_id' : 'member_ids']);
            exit;
        }
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'There is no active academic year. Ask a School Admin to set one first.']);
            exit;
        }
        $by = (int)($_SESSION['admin_id'] ?? 0); $ok=0; $skip=0; $fail=0;
        foreach ($memberIds as $mid) {
            $mid = (int)$mid; if (!$mid) continue;
            $res = EnrollmentService::enroll($conn, $mid, $classId, (int)$currentYear['id'], $by);
            if (($res['status'] ?? '') === 'success') {
                if (!empty($res['skipped'])) $skip++; else $ok++;
            } else {
                $fail++;
            }
        }
        $msg = "$ok student(s) enrolled!"; if ($skip) $msg .= " ($skip already enrolled)"; if ($fail) $msg .= " ($fail failed)";
        echo json_encode(['status'=>'success','message'=>$msg,'enrolled'=>$ok,'skipped'=>$skip,'failed'=>$fail]);
        break;

    // ============================================================
    // TRANSFER STUDENT BETWEEN CLASSES
    // ============================================================
    case 'transfer_student':
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        $toClassId = (int)($_POST['to_class_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if (!$enrollmentId || !$toClassId) { echo json_encode(['status'=>'error','message'=>'Enrollment and target class required']); exit; }
        if (!$currentYear) { echo json_encode(['status'=>'error','message'=>'No active academic year']); exit; }
        $stmt = $conn->prepare("SELECT ce.*, m.student_name, m.father_name FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.id=?");
        $stmt->bind_param("i", $enrollmentId); $stmt->execute(); $enr = $stmt->get_result()->fetch_assoc();
        if (!$enr) { echo json_encode(['status'=>'error','message'=>'Enrollment not found']); exit; }
        $stmt = $conn->prepare("SELECT id FROM class_enrollments WHERE member_id=? AND class_id=? AND academic_year_id=? AND status='active'");
        $stmt->bind_param("iii", $enr['member_id'], $toClassId, $currentYear['id']); $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) { echo json_encode(['status'=>'error','message'=>'Already in target class']); exit; }
        // Target class must exist before mutating anything.
        $classCheck = $conn->prepare('SELECT id FROM classes WHERE id = ? LIMIT 1');
        $classCheck->bind_param('i', $toClassId); $classCheck->execute();
        if (!$classCheck->get_result()->fetch_assoc()) { $classCheck->close(); echo json_encode(['status'=>'error','message'=>'Target class does not exist.']); exit; }
        $classCheck->close();

        // ATOMICITY GUARANTEE: closing the old enrollment and creating the
        // new one commit together or not at all — a member can never end up
        // stranded (no active enrollment) or double-enrolled by a
        // mid-sequence failure.
        $conn->begin_transaction();
        try {
            // Finding K (2026-10-02): the SELECT at the top of this case is a
            // plain read, so a replayed or concurrent request could transfer an
            // enrollment that has ALREADY been transferred — leaving the member
            // active in two classes at once. Re-assert the precondition inside
            // the UPDATE so only a still-active row can be closed, and treat a
            // zero-row result as a lost race rather than success. The
            // `unique_enrollment` key does NOT catch this: it is on
            // (member_id, class_id, academic_year_id), so two transfers to
            // *different* target classes both insert cleanly.
            // EnrollmentService::transferByEnrollment() and the 'promote' case
            // already enforce this same rule; this path was the outlier.
            $stmt = $conn->prepare("UPDATE class_enrollments SET status='transferred', notes=CONCAT(IFNULL(notes,''),' [Transferred: ',?,']') WHERE id=? AND status='active'");
            $stmt->bind_param("si", $reason, $enrollmentId);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to close the source enrollment.'); }
            $closedRows = $stmt->affected_rows;
            $stmt->close();
            if ($closedRows < 1) { throw new RuntimeException('__ENROLLMENT_NOT_ACTIVE__'); }
            $by = (int)($_SESSION['admin_id'] ?? 0); $dt = date('Y-m-d'); $from = $enr['class_id'];
            $tnote = "Transferred from class #$from".($reason ? ": $reason" : '');
            $yearId = (int)$currentYear['id']; $memberId = (int)$enr['member_id'];
            $stmt = $conn->prepare("INSERT INTO class_enrollments (member_id,class_id,academic_year_id,enrolled_at,status,notes,promoted_from,enrolled_by) VALUES (?,?,?,?,'active',?,?,?)");
            $stmt->bind_param("iiissii", $memberId, $toClassId, $yearId, $dt, $tnote, $from, $by);
            if (!$stmt->execute()) { throw new RuntimeException('Unable to create the target enrollment.'); }
            $stmt->close();
            if (function_exists('autoUpdateMemberClass')) autoUpdateMemberClass($conn, $memberId, $toClassId, $yearId);
            $conn->commit();
            echo json_encode(['status'=>'success','message'=>$enr['student_name'].' '.$enr['father_name'].' transferred!']);
        } catch (Exception $e) {
            $conn->rollback();
            if ($e->getMessage() === '__ENROLLMENT_NOT_ACTIVE__') {
                // Lost the race, or the request was replayed: the source
                // enrollment is no longer active. Nothing was written.
                http_response_code(409);
                echo json_encode(['status'=>'error','message'=>'This enrollment is no longer active — it may already have been transferred. Refresh and try again.']);
            } else {
                reportInternalError('Enrollment transfer failed', $e->getMessage());
                echo json_encode(['status'=>'error','message'=>'Unable to transfer the enrollment. No changes were made.']);
            }
        }
        break;

    // ============================================================
    // GET UNASSIGNED MEMBERS (not enrolled in any class this year)
    // ============================================================
    case 'get_unassigned_members':
        $search=trim($_GET['search']??''); $genderFilter=trim($_GET['gender']??'');
        $ageGroupFilter=trim($_GET['age_group']??''); $memberTypeFilter=trim($_GET['member_type']??'');
        $limit=min(100,max(10,(int)($_GET['limit']??50)));
        $offset=max(0,(int)($_GET['offset']??0));
        if (!$currentYear) { echo json_encode(['status'=>'success','members'=>[],'total'=>0]); exit; }
        $w=["m.status='active'"]; $p=[]; $t='';
        if ($search!=='') { $w[]="(m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ? OR m.baptismal_name LIKE ?)"; $st="%$search%"; $p=array_merge($p,[$st,$st,$st,$st]); $t.='ssss'; }
        if ($genderFilter!=='' && in_array($genderFilter,['male','female'])) { $w[]="m.gender=?"; $p[]=$genderFilter; $t.='s'; }
        if ($ageGroupFilter!=='' && in_array($ageGroupFilter, MemberCategory::groups(), true)) { $w[]="m.age_group=?"; $p[]=$ageGroupFilter; $t.='s'; }
        if ($memberTypeFilter!=='' && in_array($memberTypeFilter,['regular','special_regular','honorary'])) { $w[]="m.member_type=?"; $p[]=$memberTypeFilter; $t.='s'; }
        $wc=implode(' AND ',$w);
        $csql="SELECT COUNT(*) as total FROM members m WHERE $wc AND m.id NOT IN (SELECT ce.member_id FROM class_enrollments ce WHERE ce.academic_year_id=? AND ce.status='active')";
        $cp=array_merge($p,[$currentYear['id']]); $ct=$t.'i';
        if(!empty($cp)) { $stmt=$conn->prepare($csql); $stmt->bind_param($ct,...$cp); $stmt->execute(); }
        else { $stmt=$conn->prepare($csql); $stmt->execute(); }
        $total=(int)$stmt->get_result()->fetch_assoc()['total'];
        $sql="SELECT m.id, m.student_name, m.father_name, m.grandfather_name, m.member_code, m.gender, m.phone_number, m.phone_primary, m.age_group, m.current_section, m.date_of_birth, m.age, m.baptismal_name, m.education_level, m.is_teacher, m.member_type, m.is_staff, m.is_committee, m.is_volunteer, m.registered_at FROM members m WHERE $wc AND m.id NOT IN (SELECT ce.member_id FROM class_enrollments ce WHERE ce.academic_year_id=? AND ce.status='active') ORDER BY m.student_name LIMIT ? OFFSET ?";
        $fp=array_merge($p,[$currentYear['id'],$limit,$offset]); $ft=$t.'iii';
        $stmt=$conn->prepare($sql); $stmt->bind_param($ft,...$fp); $stmt->execute();
        $members=[]; $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $members[]=$row;
        echo json_encode(['status'=>'success','members'=>edu_scrub_phone($members, $__maskPhone),'total'=>$total,'limit'=>$limit,'offset'=>$offset]);
        break;

    // ============================================================
    // GET UNASSIGNED TEACHERS
    // ============================================================
    case 'get_unassigned_teachers':
        $search=trim($_GET['search']??'');
        $w=["u.role='teacher'","u.is_active=1"]; $p=[]; $t='';
        if ($search!=='') { $w[]="(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)"; $st="%$search%"; $p=[$st,$st,$st]; $t='sss'; }
        $wc=implode(' AND ',$w);
        $yc=$currentYear ? " AND ta.academic_year_id=".(int)$currentYear['id'] : "";
        $sql="SELECT u.id, u.full_name, u.username, u.email, u.member_id, COALESCE(m.member_code,'') as member_code, COALESCE(m.phone_number,'') as phone FROM users u LEFT JOIN members m ON u.member_id=m.id WHERE $wc AND u.id NOT IN (SELECT ta.teacher_id FROM teacher_assignments ta WHERE ta.is_active=1 $yc) ORDER BY u.full_name";
        if (!empty($p)) { $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute(); $result=$stmt->get_result(); }
        else $result=$conn->query($sql);
        $teachers=[]; if($result) while($row=$result->fetch_assoc()) $teachers[]=$row;
        echo json_encode(['status'=>'success','teachers'=>edu_scrub_phone($teachers, $__maskPhone)]);
        break;

    // ============================================================
    // ENROLLMENT OVERVIEW (stats per class for dashboard sync)
    // ============================================================
    case 'enrollment_overview':
        if (!$currentYear) { echo json_encode(['status'=>'success','classes'=>[],'summary'=>[]]); exit; }
        $classes = [];
        try {
            // Try full query with teacher info
            $sql="SELECT c.id, c.class_name, c.class_name_en, c.class_code, c.level_order, c.section, c.age_group,
                COALESCE(enr.total,0) as enrolled_count, COALESCE(enr.male_count,0) as male_count, COALESCE(enr.female_count,0) as female_count,
                COALESCE(tch.teacher_count,0) as teacher_count, COALESCE(tch.class_teacher_name,'') as class_teacher_name
            FROM classes c
            LEFT JOIN (SELECT ce.class_id, COUNT(*) as total, SUM(CASE WHEN m.gender='male' THEN 1 ELSE 0 END) as male_count, SUM(CASE WHEN m.gender='female' THEN 1 ELSE 0 END) as female_count FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.academic_year_id=? AND ce.status='active' GROUP BY ce.class_id) enr ON c.id=enr.class_id
            LEFT JOIN (SELECT ta.class_id, COUNT(DISTINCT ta.teacher_id) as teacher_count, (SELECT u2.full_name FROM teacher_assignments ta2 JOIN users u2 ON ta2.teacher_id=u2.id WHERE ta2.class_id=ta.class_id AND ta2.is_class_teacher=1 AND ta2.is_active=1 LIMIT 1) as class_teacher_name FROM teacher_assignments ta WHERE ta.is_active=1 GROUP BY ta.class_id) tch ON c.id=tch.class_id
            WHERE c.is_active=1 ORDER BY c.level_order";
            $stmt=$conn->prepare($sql); $stmt->bind_param("i",$currentYear['id']); $stmt->execute();
            $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $classes[]=$row;
        } catch (Exception $e) {
            // Fallback without teacher_assignments join
            try {
                $stmt=$conn->prepare("SELECT c.id, c.class_name, c.class_name_en, c.class_code, c.level_order, c.section, c.age_group,
                    COALESCE(enr.total,0) as enrolled_count, COALESCE(enr.male_count,0) as male_count, COALESCE(enr.female_count,0) as female_count,
                    0 as teacher_count, '' as class_teacher_name
                FROM classes c
                LEFT JOIN (SELECT ce.class_id, COUNT(*) as total, SUM(CASE WHEN m.gender='male' THEN 1 ELSE 0 END) as male_count, SUM(CASE WHEN m.gender='female' THEN 1 ELSE 0 END) as female_count FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.academic_year_id=? AND ce.status='active' GROUP BY ce.class_id) enr ON c.id=enr.class_id
                WHERE c.is_active=1 ORDER BY c.level_order");
                $stmt->bind_param("i",$currentYear['id']); $stmt->execute();
                $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $classes[]=$row;
            } catch (Exception $e2) { /* tables don't exist yet */ }
        }
        $totalM=0; $r=$conn->query("SELECT COUNT(*) c FROM members WHERE status='active'"); if($r) $totalM=(int)$r->fetch_assoc()['c'];
        $totalE=0; $stmt=$conn->prepare("SELECT COUNT(DISTINCT ce.member_id) c FROM class_enrollments ce WHERE ce.academic_year_id=? AND ce.status='active'");
        $stmt->bind_param("i",$currentYear['id']); $stmt->execute(); $r=$stmt->get_result(); if($r) $totalE=(int)$r->fetch_assoc()['c'];
        $totalT=0; $r2=$conn->query("SELECT COUNT(*) c FROM users WHERE role='teacher' AND is_active=1"); if($r2) $totalT=(int)$r2->fetch_assoc()['c'];
        $assignedT=0; try { $yc2=$currentYear?" AND ta.academic_year_id=".(int)$currentYear['id']:""; $r3=$conn->query("SELECT COUNT(DISTINCT ta.teacher_id) c FROM teacher_assignments ta WHERE ta.is_active=1 $yc2"); if($r3) $assignedT=(int)$r3->fetch_assoc()['c']; } catch(Exception $e){}
        // Member type breakdown
        $typeBreakdown=['regular'=>0,'special_regular'=>0,'honorary'=>0];
        try { $r4=$conn->query("SELECT member_type, COUNT(*) as c FROM members WHERE status='active' GROUP BY member_type");
            if($r4) while($rw=$r4->fetch_assoc()) { $typeBreakdown[$rw['member_type']??'regular']=(int)$rw['c']; }
        } catch(Exception $e){}
        // Enrolled by type
        $enrolledByType=['regular'=>0,'special_regular'=>0,'honorary'=>0];
        if($currentYear) { try { $stmt4=$conn->prepare("SELECT m.member_type, COUNT(DISTINCT ce.member_id) c FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.academic_year_id=? AND ce.status='active' GROUP BY m.member_type");
            $stmt4->bind_param("i",$currentYear['id']); $stmt4->execute(); $r5=$stmt4->get_result();
            if($r5) while($rw=$r5->fetch_assoc()) { $enrolledByType[$rw['member_type']??'regular']=(int)$rw['c']; } $stmt4->close();
        } catch(Exception $e){} }
        echo json_encode(['status'=>'success','classes'=>$classes,'summary'=>[
            'total_members'=>$totalM,'total_enrolled'=>$totalE,'unassigned_members'=>$totalM-$totalE,
            'total_teachers'=>$totalT,'assigned_teachers'=>$assignedT,'unassigned_teachers'=>$totalT-$assignedT,
            'total_classes'=>count($classes),'year_name'=>$currentYear['year_name']??'',
            'type_breakdown'=>$typeBreakdown,'enrolled_by_type'=>$enrolledByType
        ]]);
        break;

    // ============================================================
    // RETIRED: member types are manual, never inferred from roles.
    // ============================================================
    case 'sync_member_types':
        http_response_code(410);
        echo json_encode([
            'status' => 'error',
            'code' => 'member_type_manual_only',
            'message' => 'Automatic member type synchronization has been retired. Edit the member type manually in the member record.'
        ]);
        break;

    // ============================================================
    // SEARCH MEMBERS (live search for enrollment)
    // ============================================================
    case 'search_members':
        $q=trim($_GET['q']??''); $excClass=(int)($_GET['exclude_class']??0);
        $limit=min(30,max(5,(int)($_GET['limit']??15))); $unassigned=isset($_GET['unassigned'])&&$_GET['unassigned']==='1';
        if (strlen($q)<1) { echo json_encode(['status'=>'success','members'=>[]]); exit; }
        $w=["m.status='active'"]; $p=[]; $t='';
        $w[]="(m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ? OR m.baptismal_name LIKE ?)";
        $st="%$q%"; $p=[$st,$st,$st,$st]; $t='ssss';
        if ($excClass>0 && $currentYear) { $w[]="m.id NOT IN (SELECT ce.member_id FROM class_enrollments ce WHERE ce.class_id=? AND ce.academic_year_id=? AND ce.status='active')"; $p[]=$excClass; $p[]=$currentYear['id']; $t.='ii'; }
        if ($unassigned && $currentYear) { $w[]="m.id NOT IN (SELECT ce.member_id FROM class_enrollments ce WHERE ce.academic_year_id=? AND ce.status='active')"; $p[]=$currentYear['id']; $t.='i'; }
        $wc=implode(' AND ',$w); $p[]=$limit; $t.='i';
        $sql="SELECT m.id, m.student_name, m.father_name, m.member_code, m.gender, m.age_group, m.phone_number, m.current_section, m.is_teacher, m.member_type FROM members m WHERE $wc ORDER BY m.student_name LIMIT ?";
        $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute();
        $members=[]; $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $members[]=$row;
        echo json_encode(['status'=>'success','members'=>edu_scrub_phone($members, $__maskPhone)]);
        break;

    // ============================================================
    // SCHOOL-WIDE ROSTER (server-side search / filter / page)
    // ============================================================
    case 'roster':
        $q = trim((string)($_GET['q'] ?? ''));
        $classId = (int)($_GET['class_id'] ?? 0);
        $unassigned = isset($_GET['unassigned']) && $_GET['unassigned'] === '1';
        $gender = trim((string)($_GET['gender'] ?? ''));
        $ageGroup = trim((string)($_GET['age_group'] ?? ''));
        $memberType = trim((string)($_GET['member_type'] ?? ''));
        $status = trim((string)($_GET['status'] ?? 'active'));
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        $sort = trim((string)($_GET['sort'] ?? 'name'));
        $dir = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, (int)($_GET['page'] ?? 1));
        // Phase 3: up to 200 per page (the shared pager's largest choice).
        $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $yearId = $currentYear ? (int)$currentYear['id'] : 0;
        $w = [];
        $p = [];
        $t = '';

        if ($status !== '' && $status !== 'all') {
            $w[] = 'm.status = ?';
            $p[] = $status;
            $t .= 's';
        } else {
            $w[] = "m.status != 'archived'";
        }
        if ($gender !== '' && in_array($gender, ['male', 'female'], true)) {
            $w[] = 'm.gender = ?';
            $p[] = $gender;
            $t .= 's';
        }
        if ($ageGroup !== '' && in_array($ageGroup, MemberCategory::groups(), true)) {
            $w[] = 'm.age_group = ?';
            $p[] = $ageGroup;
            $t .= 's';
        }
        if ($memberType !== '' && in_array($memberType, ['regular', 'special_regular', 'honorary'], true)) {
            $w[] = 'm.member_type = ?';
            $p[] = $memberType;
            $t .= 's';
        }
        if ($q !== '') {
            $w[] = '(m.student_name LIKE ? OR m.father_name LIKE ? OR m.grandfather_name LIKE ? OR m.member_code LIKE ? OR m.baptismal_name LIKE ? OR m.phone_number LIKE ?)';
            $st = '%' . $q . '%';
            array_push($p, $st, $st, $st, $st, $st, $st);
            $t .= 'ssssss';
        }

        // H2: dedupe enrollments to ONE row per member BEFORE joining, so a
        // member with active enrollments in two classes appears once and the
        // row count matches COUNT(DISTINCT m.id). The dedupe is scoped the
        // same way the roster is (year, class, teacher) so class rosters and
        // teacher rosters keep every legitimate member.
        $innerW = ["e0.status = 'active'"];
        $innerP = [];
        $innerT = '';
        if ($yearId) {
            $innerW[] = 'e0.academic_year_id = ?';
            $innerP[] = $yearId;
            $innerT .= 'i';
        }
        if ($classId > 0) {
            $innerW[] = 'e0.class_id = ?';
            $innerP[] = $classId;
            $innerT .= 'i';
        }
        if ($teacherId > 0 && $yearId) {
            $innerW[] = 'e0.class_id IN (SELECT ta.class_id FROM teacher_assignments ta'
                . ' WHERE ta.teacher_id = ? AND ta.is_active = 1 AND ta.academic_year_id = ?)';
            $innerP[] = $teacherId;
            $innerP[] = $yearId;
            $innerT .= 'ii';
        }
        $join = "LEFT JOIN (
                    SELECT e1.member_id, e1.id, e1.class_id, e1.enrolled_at, e1.status
                    FROM class_enrollments e1
                    JOIN (SELECT member_id, MIN(id) AS pick_id
                          FROM class_enrollments e0
                          WHERE " . implode(' AND ', $innerW) . "
                          GROUP BY member_id) ep ON ep.pick_id = e1.id
                 ) ce ON ce.member_id = m.id
                 LEFT JOIN classes c ON c.id = ce.class_id";
        $p = array_merge($innerP, $p);
        $t = $innerT . $t;

        if ($unassigned) {
            $w[] = 'ce.id IS NULL';
        } elseif ($classId > 0) {
            $w[] = 'ce.class_id = ?';
            $p[] = $classId;
            $t .= 'i';
        }

        if ($teacherId > 0 && $yearId) {
            $w[] = 'ce.class_id IN (SELECT ta.class_id FROM teacher_assignments ta WHERE ta.teacher_id = ? AND ta.is_active = 1 AND ta.academic_year_id = ?)';
            $p[] = $teacherId;
            $p[] = $yearId;
            $t .= 'ii';
        }

        $orderMap = [
            'name' => 'm.student_name',
            'code' => 'm.member_code',
            'class' => 'c.level_order',
            'gender' => 'm.gender',
            'date' => 'ce.enrolled_at',
        ];
        $orderCol = $orderMap[$sort] ?? 'm.student_name';
        $wc = implode(' AND ', $w);

        $countSql = "SELECT COUNT(DISTINCT m.id) AS total FROM members m $join WHERE $wc";
        $stmt = $conn->prepare($countSql);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Query prepare failed.']);
            exit;
        }
        if ($t !== '') {
            $stmt->bind_param($t, ...$p);
        }
        $stmt->execute();
        $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $sql = "SELECT m.id, m.member_code, m.student_name, m.father_name, m.grandfather_name,
                       m.baptismal_name, m.gender, m.age, m.age_group, m.member_type, m.status,
                       m.phone_number, m.current_section, m.education_level, m.is_teacher,
                       ce.id AS enrollment_id, ce.enrolled_at, ce.status AS enrollment_status,
                       c.id AS class_id, c.class_name, c.class_name_en, c.class_code
                FROM members m
                $join
                WHERE $wc
                ORDER BY $orderCol $dir, m.student_name ASC
                LIMIT ? OFFSET ?";
        $fp = $p;
        $ft = $t . 'ii';
        $fp[] = $perPage;
        $fp[] = $offset;
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Query prepare failed.']);
            exit;
        }
        $stmt->bind_param($ft, ...$fp);
        $stmt->execute();
        $rows = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'rows' => edu_scrub_phone($rows, $__maskPhone),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
            'year_id' => $yearId,
            'year_name' => $currentYear['year_name'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'list_teachers':
        $q = trim((string)($_GET['q'] ?? ''));
        $assigned = trim((string)($_GET['assigned'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;
        $yearId = $currentYear ? (int)$currentYear['id'] : 0;

        $w = ["u.role = 'teacher'"];
        $p = [];
        $t = '';
        if (empty($_GET['include_inactive'])) {
            $w[] = 'u.is_active = 1';
        }
        if ($q !== '') {
            $w[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR m.member_code LIKE ?)';
            $st = '%' . $q . '%';
            array_push($p, $st, $st, $st, $st);
            $t .= 'ssss';
        }
        $yc = $yearId ? ' AND ta.academic_year_id = ' . $yearId : '';
        if ($assigned === '1') {
            $w[] = "u.id IN (SELECT ta.teacher_id FROM teacher_assignments ta WHERE ta.is_active = 1 $yc)";
        } elseif ($assigned === '0') {
            $w[] = "u.id NOT IN (SELECT ta.teacher_id FROM teacher_assignments ta WHERE ta.is_active = 1 $yc)";
        }
        $wc = implode(' AND ', $w);

        $csql = "SELECT COUNT(*) AS total FROM users u LEFT JOIN members m ON u.member_id = m.id WHERE $wc";
        if ($t !== '') {
            $stmt = $conn->prepare($csql);
            $stmt->bind_param($t, ...$p);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } else {
            $r = $conn->query($csql);
            $total = $r ? (int)$r->fetch_assoc()['total'] : 0;
        }

        $sql = "SELECT u.id, u.full_name, u.username, u.email, u.is_active, u.member_id,
                       COALESCE(m.member_code,'') AS member_code,
                       COALESCE(m.phone_number,'') AS phone,
                       (SELECT COUNT(DISTINCT ta.class_id) FROM teacher_assignments ta WHERE ta.teacher_id = u.id AND ta.is_active = 1 $yc) AS assigned_classes
                FROM users u
                LEFT JOIN members m ON u.member_id = m.id
                WHERE $wc
                ORDER BY u.full_name
                LIMIT ? OFFSET ?";
        $fp = $p;
        $ft = $t . 'ii';
        $fp[] = $perPage;
        $fp[] = $offset;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($ft, ...$fp);
        $stmt->execute();
        $teachers = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $teachers[] = $row;
        }
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'teachers' => edu_scrub_phone($teachers, $__maskPhone),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================================
    // ASSESSMENT TYPES MANAGEMENT
    // ============================================================
    case 'get_assessment_types':
        require_once __DIR__ . '/backend/services/AssessmentTypeService.php';
        $activeOnly = isset($_GET['active_only']) && ($_GET['active_only'] === '1' || $_GET['active_only'] === 'true');
        $types = \App\Services\AssessmentTypeService::getAll($conn, $activeOnly);
        echo json_encode(['status' => 'success', 'types' => $types, 'count' => count($types)], JSON_UNESCAPED_UNICODE);
        break;

    case 'save_assessment_type':
        if (!in_array((string)($_SESSION['admin_role'] ?? ''), ['super_admin', 'school_admin', 'edu_dept'], true)) {
            respondApiError('Only Education Department and administrators can manage assessment types.', 403);
        }
        require_once __DIR__ . '/backend/services/AssessmentTypeService.php';
        $res = \App\Services\AssessmentTypeService::save($conn, $_POST, (int)($_SESSION['admin_id'] ?? 0));
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'toggle_assessment_type':
        if (!in_array((string)($_SESSION['admin_role'] ?? ''), ['super_admin', 'school_admin', 'edu_dept'], true)) {
            respondApiError('Only Education Department and administrators can manage assessment types.', 403);
        }
        require_once __DIR__ . '/backend/services/AssessmentTypeService.php';
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            respondApiError('Invalid assessment type ID.', 422);
        }
        $res = \App\Services\AssessmentTypeService::toggleActive($conn, $id);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'delete_assessment_type':
        if (!in_array((string)($_SESSION['admin_role'] ?? ''), ['super_admin', 'school_admin', 'edu_dept'], true)) {
            respondApiError('Only Education Department and administrators can manage assessment types.', 403);
        }
        require_once __DIR__ . '/backend/services/AssessmentTypeService.php';
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            respondApiError('Invalid assessment type ID.', 422);
        }
        $res = \App\Services\AssessmentTypeService::delete($conn, $id);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    // ============================================================
    // EDUCATION ANALYTICS & INTELLIGENCE HUB
    // ============================================================
    case 'get_education_hub':
        require_once __DIR__ . '/backend/services/EducationAnalyticsService.php';
        $filters = [
            'class_id' => $_GET['class_id'] ?? 'all',
            'year_id' => !empty($_GET['year_id']) ? (int)$_GET['year_id'] : ($currentYear['id'] ?? 0),
            'term_id' => !empty($_GET['term_id']) ? (int)$_GET['term_id'] : 0,
            'gender' => $_GET['gender'] ?? 'all',
            'min_grade' => (isset($_GET['min_grade']) && $_GET['min_grade'] !== '') ? (float)$_GET['min_grade'] : null,
            'max_grade' => (isset($_GET['max_grade']) && $_GET['max_grade'] !== '') ? (float)$_GET['max_grade'] : null,
            'grade_letter' => $_GET['grade_letter'] ?? 'all',
            'min_attendance' => (isset($_GET['min_attendance']) && $_GET['min_attendance'] !== '') ? (float)$_GET['min_attendance'] : null,
            'max_attendance' => (isset($_GET['max_attendance']) && $_GET['max_attendance'] !== '') ? (float)$_GET['max_attendance'] : null,
            'search' => $_GET['search'] ?? null,
            'sort' => $_GET['sort'] ?? 'grade_desc',
        ];
        $res = \App\Services\EducationAnalyticsService::getHubData($conn, $filters);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    // ============================================================
    // ACADEMIC INTELLIGENCE — one dataset, four perspectives
    // ============================================================
    // Student / Teacher / Subject / Class are four projections of the same
    // ReportCardService calculation, not four reports. The perspective is a
    // parameter so the workspace can drill from one into another without
    // changing endpoint.
    // ────────────────────────────────────────────────────────────────────
    // ACADEMIC TRACKING — STUDENT WORKFLOW (Phase 2)
    //
    // Two actions, both scoped to one explicitly selected student:
    //   tracking_student_detail      header + overview + subjects + attendance
    //   tracking_student_assessments the assessment list, loaded on demand
    //
    // The Report Card section is NOT here. It reuses the existing
    // api_communication.php?action=get_report_card, which already returns
    // ReportCardService::getCard() behind the same canViewClass check.
    // Adding a third action that re-served the same card would be the
    // duplication the phase brief forbids.
    // ────────────────────────────────────────────────────────────────────
    case 'tracking_student_detail':
    case 'tracking_student_assessments':
        require_once __DIR__ . '/backend/services/AcademicTrackingService.php';

        $trkMember = (int)($_GET['member_id'] ?? 0);
        $trkClass  = (int)($_GET['class_id'] ?? 0);
        $trkYear   = !empty($_GET['year_id']) ? (int)$_GET['year_id'] : (int)($currentYear['id'] ?? 0);
        $trkTerm   = !empty($_GET['term_id']) ? (int)$_GET['term_id'] : 0;

        // 1. Validate the id before it reaches a query. The browser is not
        //    trusted to send a sane one.
        if ($trkMember <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'code' => 'invalid_student',
                'message' => 'A student must be selected.',
            ]);
            break;
        }

        // 2. If the caller names a class, they must be allowed to see it.
        //    Same rule the report card uses — not a second system.
        if ($trkClass > 0 && !\App\Services\ReportCardService::canViewClass(
            $conn,
            (int)($_SESSION['admin_id'] ?? 0),
            (string)$__role,
            $trkClass
        )) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'code' => 'forbidden', 'message' => 'Access denied for this class.']);
            break;
        }

        try {
            $trkRes = $action === 'tracking_student_detail'
                ? \App\Services\AcademicTrackingService::studentDetail($conn, $trkMember, $trkClass, $trkYear, $trkTerm)
                : \App\Services\AcademicTrackingService::studentAssessments($conn, $trkMember, $trkClass, $trkYear, $trkTerm);

            // 3. When the class was resolved from the enrolment rather than
            //    supplied, re-check visibility against what came back, so an
            //    id for a class the caller cannot see cannot be reached by
            //    simply omitting class_id.
            if (($trkRes['status'] ?? '') === 'success') {
                $trkResolved = (int)($trkRes['scope']['class_id'] ?? 0);
                if ($trkResolved > 0 && $trkResolved !== $trkClass
                    && !\App\Services\ReportCardService::canViewClass(
                        $conn,
                        (int)($_SESSION['admin_id'] ?? 0),
                        (string)$__role,
                        $trkResolved
                    )) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'code' => 'forbidden', 'message' => 'Access denied for this class.']);
                    break;
                }
            }
            echo json_encode($trkRes);
        } catch (Throwable $e) {
            error_log('tracking student: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'code' => 'server_error',
                'message' => 'Could not load this student right now.',
            ]);
        }
        break;

    case 'tracking_teacher_detail':
    case 'tracking_teacher_assessments':
        require_once __DIR__ . '/backend/services/AcademicTrackingService.php';

        $ttTeacher = (int)($_GET['teacher_id'] ?? 0);
        $ttClass   = (int)($_GET['class_id'] ?? 0);
        $ttSubject = (int)($_GET['subject_id'] ?? 0);
        $ttYear    = !empty($_GET['year_id']) ? (int)$_GET['year_id'] : (int)($currentYear['id'] ?? 0);
        $ttTerm    = !empty($_GET['term_id']) ? (int)$_GET['term_id'] : 0;

        // 1. Validate ids before they reach a query. Every one of these
        //    arrives from the browser and none of them is trusted.
        if ($ttTeacher <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'code' => 'invalid_teacher',
                'message' => 'A teacher must be selected.',
            ]);
            break;
        }

        // 2. The class gate, if a class is named. Same canViewClass() the
        //    report card uses — deliberately not a second permission system.
        if ($ttClass > 0 && !\App\Services\ReportCardService::canViewClass(
            $conn,
            (int)($_SESSION['admin_id'] ?? 0),
            (string)$__role,
            $ttClass
        )) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'code' => 'forbidden', 'message' => 'Access denied for this class.']);
            break;
        }

        try {
            if ($action === 'tracking_teacher_detail') {
                $ttRes = \App\Services\AcademicTrackingService::teacherDetail($conn, $ttTeacher, $ttYear, $ttTerm);

                // 3. Visibility is re-checked against the classes actually
                //    returned. The detail call takes no class_id, so the gate
                //    above never ran for it; without this, a caller who may
                //    not see a class could still learn it exists by reading
                //    a teacher who is assigned to it.
                if (($ttRes['status'] ?? '') === 'success') {
                    $ttVisible = [];
                    foreach ($ttRes['assignments'] as $ttRow) {
                        $ttCid = (int)$ttRow['class_id'];
                        if (!array_key_exists($ttCid, $ttVisible)) {
                            $ttVisible[$ttCid] = \App\Services\ReportCardService::canViewClass(
                                $conn,
                                (int)($_SESSION['admin_id'] ?? 0),
                                (string)$__role,
                                $ttCid
                            );
                        }
                    }
                    $ttRes['assignments'] = array_values(array_filter(
                        $ttRes['assignments'],
                        static function ($r) use ($ttVisible) {
                            return !empty($ttVisible[(int)$r['class_id']]);
                        }
                    ));
                    // The state has to follow the filtered list, otherwise a
                    // teacher whose every class is hidden would report "ok"
                    // with nothing in it.
                    $ttRes['data_state']['assignments'] = $ttRes['assignments']
                        ? \App\Services\AcademicTrackingService::STATE_OK
                        : \App\Services\AcademicTrackingService::STATE_NO_ASSIGNMENTS;
                }
            } else {
                // 4. The subject scope is required here, and the service
                //    re-validates the teacher/class/subject triple against
                //    teacher_assignments before returning any workflow row.
                if ($ttClass <= 0) {
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'code' => 'invalid_class',
                        'message' => 'A class must be selected.',
                    ]);
                    break;
                }
                if ($ttSubject <= 0) {
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'code' => 'invalid_subject',
                        'message' => 'A subject must be selected.',
                    ]);
                    break;
                }
                $ttRes = \App\Services\AcademicTrackingService::teacherAssessments(
                    $conn,
                    $ttTeacher,
                    $ttClass,
                    $ttSubject,
                    $ttYear,
                    $ttTerm
                );
                if (($ttRes['code'] ?? '') === 'not_assigned') {
                    http_response_code(404);
                }
            }
            echo json_encode($ttRes);
        } catch (Throwable $e) {
            error_log('tracking teacher: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'code' => 'server_error',
                'message' => 'Could not load this teacher right now.',
            ]);
        }
        break;

    case 'tracking_subject_detail':
    case 'tracking_subject_offering':
    case 'tracking_subject_students':
        require_once __DIR__ . '/backend/services/AcademicTrackingService.php';

        $tsSubject = (int)($_GET['subject_id'] ?? 0);
        $tsClass   = (int)($_GET['class_id'] ?? 0);
        $tsYear    = !empty($_GET['year_id']) ? (int)$_GET['year_id'] : (int)($currentYear['id'] ?? 0);
        $tsTerm    = !empty($_GET['term_id']) ? (int)$_GET['term_id'] : 0;

        // 1. Validate ids before they reach a query. None of these is
        //    trusted; all three arrive from the browser.
        if ($tsSubject <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'code' => 'invalid_subject',
                'message' => 'A subject must be selected.',
            ]);
            break;
        }
        if ($action !== 'tracking_subject_detail' && $tsClass <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'code' => 'invalid_class',
                'message' => 'A class must be selected.',
            ]);
            break;
        }

        // 2. The class gate, when a class is named. Same canViewClass() the
        //    report card uses — deliberately not a second permission system.
        if ($tsClass > 0 && !\App\Services\ReportCardService::canViewClass(
            $conn,
            (int)($_SESSION['admin_id'] ?? 0),
            (string)$__role,
            $tsClass
        )) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'code' => 'forbidden', 'message' => 'Access denied for this class.']);
            break;
        }

        try {
            if ($action === 'tracking_subject_detail') {
                $tsRes = \App\Services\AcademicTrackingService::subjectDetail($conn, $tsSubject, $tsYear, $tsTerm);

                // 3. The detail call takes no class_id, so the gate above
                //    never ran for it. Its offering list is filtered by
                //    visibility instead; otherwise a caller who may not see
                //    a class could learn it exists, and how many students
                //    it holds, by reading a subject taught there.
                if (($tsRes['status'] ?? '') === 'success') {
                    $tsVisible = [];
                    foreach ($tsRes['offerings'] as $tsRow) {
                        $tsCid = (int)$tsRow['class_id'];
                        if (!array_key_exists($tsCid, $tsVisible)) {
                            $tsVisible[$tsCid] = \App\Services\ReportCardService::canViewClass(
                                $conn,
                                (int)($_SESSION['admin_id'] ?? 0),
                                (string)$__role,
                                $tsCid
                            );
                        }
                    }
                    $tsRes['offerings'] = array_values(array_filter(
                        $tsRes['offerings'],
                        static function ($r) use ($tsVisible) {
                            return !empty($tsVisible[(int)$r['class_id']]);
                        }
                    ));
                    // The state has to follow the filtered list, or a
                    // subject whose every class is hidden would report
                    // "ok" with nothing in it.
                    $tsRes['data_state']['offerings'] = $tsRes['offerings']
                        ? \App\Services\AcademicTrackingService::STATE_OK
                        : \App\Services\AcademicTrackingService::STATE_NOT_OFFERED;
                }
            } elseif ($action === 'tracking_subject_offering') {
                // 4. The service re-validates that this class really offers
                //    this subject before returning a single row.
                $tsRes = \App\Services\AcademicTrackingService::subjectOffering(
                    $conn,
                    $tsSubject,
                    $tsClass,
                    $tsYear,
                    $tsTerm
                );
            } else {
                $tsRes = \App\Services\AcademicTrackingService::subjectStudents(
                    $conn,
                    $tsSubject,
                    $tsClass,
                    $tsYear,
                    (int)($_GET['page'] ?? 1),
                    (int)($_GET['per_page'] ?? 25)
                );
            }

            if (($tsRes['code'] ?? '') === 'not_offered_here') {
                http_response_code(404);
            }
            echo json_encode($tsRes);
        } catch (Throwable $e) {
            error_log('tracking subject: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'code' => 'server_error',
                'message' => 'Could not load this subject right now.',
            ]);
        }
        break;

    case 'tracking_class_detail':
    case 'tracking_class_students':
    case 'tracking_class_subjects':
    case 'tracking_class_teachers':
    case 'tracking_class_assessments':
        require_once __DIR__ . '/backend/services/AcademicTrackingService.php';

        $tcClass = (int)($_GET['class_id'] ?? 0);
        $tcYear  = !empty($_GET['year_id']) ? (int)$_GET['year_id'] : (int)($currentYear['id'] ?? 0);
        $tcTerm  = !empty($_GET['term_id']) ? (int)$_GET['term_id'] : 0;

        // 1. Validate before any query. class_id arrives from the browser
        //    and is never trusted.
        if ($tcClass <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'code' => 'invalid_class',
                'message' => 'A class must be selected.',
            ]);
            break;
        }

        // 2. Authorize the class itself, on EVERY one of these actions.
        //    This is the whole scope boundary: the class id is the only
        //    thing standing between a caller and another class's roll,
        //    assignments and mark lists. Same canViewClass() the report
        //    card uses — deliberately not a second permission system.
        if (!\App\Services\ReportCardService::canViewClass(
            $conn,
            (int)($_SESSION['admin_id'] ?? 0),
            (string)$__role,
            $tcClass
        )) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'code' => 'forbidden',
                'message' => 'Access denied for this class.',
            ]);
            break;
        }

        try {
            if ($action === 'tracking_class_detail') {
                $tcRes = \App\Services\AcademicTrackingService::classDetail(
                    $conn, $tcClass, $tcYear, $tcTerm
                );
            } elseif ($action === 'tracking_class_students') {
                $tcRes = \App\Services\AcademicTrackingService::classStudents(
                    $conn,
                    $tcClass,
                    $tcYear,
                    is_scalar($_GET['q'] ?? '') ? (string)($_GET['q'] ?? '') : '',
                    (int)($_GET['page'] ?? 1),
                    (int)($_GET['per_page'] ?? 25)
                );
            } elseif ($action === 'tracking_class_subjects') {
                $tcRes = \App\Services\AcademicTrackingService::classSubjects(
                    $conn, $tcClass, $tcYear, $tcTerm
                );
            } elseif ($action === 'tracking_class_teachers') {
                $tcRes = \App\Services\AcademicTrackingService::classTeachers(
                    $conn, $tcClass, $tcYear
                );
            } else {
                $tcRes = \App\Services\AcademicTrackingService::classAssessments(
                    $conn, $tcClass, $tcYear, $tcTerm, (int)($_GET['subject_id'] ?? 0)
                );
            }

            if (($tcRes['code'] ?? '') === 'not_offered_here') {
                http_response_code(404);
            }
            echo json_encode($tcRes, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('tracking class: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'code' => 'server_error',
                'message' => 'Could not load this class right now.',
            ]);
        }
        break;

    case 'get_academic_intelligence':
        require_once __DIR__ . '/backend/services/AcademicIntelligenceService.php';
        $aiPerspective = is_scalar($_GET['perspective'] ?? '') ? (string)($_GET['perspective'] ?? '') : '';
        $aiYear = !empty($_GET['year_id']) ? (int)$_GET['year_id'] : (int)($currentYear['id'] ?? 0);
        $aiClass = (int)($_GET['class_id'] ?? 0);

        // Defence in depth: the tier-3 gate above already limits this action
        // to the Education roles, for whom canViewClass() is always true.
        // Enforcing it anyway means that if the role list is ever widened,
        // class scoping is already in force rather than something somebody
        // has to remember to add.
        if ($aiClass > 0 && !\App\Services\ReportCardService::canViewClass(
            $conn,
            (int)($_SESSION['admin_id'] ?? 0),
            (string)$__role,
            $aiClass
        )) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied for this class.']);
            break;
        }

        try {
            $res = \App\Services\AcademicIntelligenceService::perspective($conn, $aiPerspective, [
                'year_id' => $aiYear,
                'term_id' => !empty($_GET['term_id']) ? (int)$_GET['term_id'] : 0,
                'member_id' => (int)($_GET['member_id'] ?? 0),
                'teacher_id' => (int)($_GET['teacher_id'] ?? 0),
                'subject_id' => (int)($_GET['subject_id'] ?? 0),
                'class_id' => $aiClass,
                'filters' => [
                    'class_id' => $aiClass,
                    'subject_id' => (int)($_GET['subject_id'] ?? 0),
                    'gender' => $_GET['gender'] ?? null,
                    'grade_letter' => $_GET['grade_letter'] ?? null,
                    'min_grade' => (isset($_GET['min_grade']) && $_GET['min_grade'] !== '') ? (float)$_GET['min_grade'] : null,
                    'max_grade' => (isset($_GET['max_grade']) && $_GET['max_grade'] !== '') ? (float)$_GET['max_grade'] : null,
                    'min_attendance' => (isset($_GET['min_attendance']) && $_GET['min_attendance'] !== '') ? (float)$_GET['min_attendance'] : null,
                    'max_attendance' => (isset($_GET['max_attendance']) && $_GET['max_attendance'] !== '') ? (float)$_GET['max_attendance'] : null,
                    'search' => $_GET['search'] ?? null,
                    'page' => (int)($_GET['page'] ?? 1),
                    'per_page' => (int)($_GET['per_page'] ?? 0),
                ],
            ]);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('get_academic_intelligence: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Could not build this analysis.']);
        }
        break;

    case 'get_academic_intelligence_options':
        require_once __DIR__ . '/backend/services/AcademicIntelligenceService.php';
        try {
            echo json_encode(
                \App\Services\AcademicIntelligenceService::options(
                    $conn,
                    !empty($_GET['year_id']) ? (int)$_GET['year_id'] : (int)($currentYear['id'] ?? 0)
                ),
                JSON_UNESCAPED_UNICODE
            );
        } catch (Throwable $e) {
            error_log('get_academic_intelligence_options: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Could not load the filter options.']);
        }
        break;

    // ============================================================
    // ADVANCED PERFORMANCE & ATTENDANCE FILTER
    // ============================================================
    case 'filter_students_performance':
        require_once __DIR__ . '/backend/services/ReportCardService.php';
        $filters = [
            'class_id' => $_GET['class_id'] ?? 'all',
            'year_id' => !empty($_GET['year_id']) ? (int)$_GET['year_id'] : ($currentYear['id'] ?? 0),
            'term_id' => !empty($_GET['term_id']) ? (int)$_GET['term_id'] : 0,
            'gender' => $_GET['gender'] ?? 'all',
            'min_grade' => (isset($_GET['min_grade']) && $_GET['min_grade'] !== '') ? (float)$_GET['min_grade'] : null,
            'max_grade' => (isset($_GET['max_grade']) && $_GET['max_grade'] !== '') ? (float)$_GET['max_grade'] : null,
            'grade_letter' => $_GET['grade_letter'] ?? 'all',
            'min_attendance' => (isset($_GET['min_attendance']) && $_GET['min_attendance'] !== '') ? (float)$_GET['min_attendance'] : null,
            'max_attendance' => (isset($_GET['max_attendance']) && $_GET['max_attendance'] !== '') ? (float)$_GET['max_attendance'] : null,
            'search' => $_GET['search'] ?? null,
            'sort' => $_GET['sort'] ?? 'grade_desc',
        ];
        $res = \App\Services\ReportCardService::filterStudentsPerformance($conn, $filters);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
} catch (Throwable $e) {
    // Enterprise error handling (audit patch 8): mapped friendly message +
    // correlation reference; internals only in the server log.
    respondApiThrowable("api_education error [{$action}]", $e);
}

$conn->close();
