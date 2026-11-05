<?php
/**
 * School API v1 — Grades Routes (v2 — teacher-subject filtering)
 * 
 * FIXES:
 * - Teachers only see subjects they're assigned to teach (not all class subjects)
 * - Subject-level access check for teachers
 * - Admins/edu_dept still see all subjects
 * 
 * GET  /grades/subjects?class_id=X              — Subjects for a class
 * GET  /grades/assessments?class_id=X&subject_id=Y — Assessments list
 * POST /grades/assessments                       — Create assessment
 * GET  /grades/students?assessment_id=X          — Students with scores
 * POST /grades/save                              — Save grades
 * GET  /grades/summary?class_id=X                — Grade summary
 */

$action = $ROUTE['id'] ?? '';
if ($action === 'preparation') header('Cache-Control: no-store');
$auth = apiRequireAuth();
$sub = $ROUTE['sub'] ?? '';
if (in_array($action, ['preparation'], true)) {
    $auth = apiRevalidateAuthorizationScope($auth, true);
}
if (!apiRoleIs($auth, apiRolesGrades())) {
    // Preparation is the only grades route that needs its own role error inside the block;
    // other routes enforce below after year resolution.
    if ($action === 'preparation') err('You cannot manage grades.', 403);
}
if ($action === 'preparation') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); err('Use GET for grades preparation.', 405); }
    if (!empty($ROUTE['sub'])) err('Unknown preparation endpoint.', 404);
    $query = $_GET; unset($query['_route'], $query['path']);
    if ($query !== []) err('Preparation scope is selected by the server.', 422);
    require_once dirname(__DIR__, 3) . '/admin/backend/services/GradesPreparationService.php';
    require_once dirname(__DIR__, 3) . '/admin/backend/services/GradesPreparationGrant.php';
    try {
        $identity = \App\Services\GradesPreparationGrant::serverIdentity((string)SITE_URL);
        $facts = \App\Services\GradesPreparationService::read($conn, $auth, apiRolesGrades(), apiIsClassRestricted($auth));
        $preparation = \App\Services\GradesPreparationGrant::issue($facts, $identity, API_TOKEN_SECRET);
    } catch (\App\Services\GradesPreparationDenied $denial) {
        err('Preparation authority changed. Preserve older work and sign in again.', 401, ['code'=>$denial->reason]);
    } catch (Throwable $error) {
        error_log('Grades preparation unavailable.');
        err('Complete grades preparation is unavailable. Preserve the previous dataset.', 503, ['code'=>'GRADES_PREPARATION_UNAVAILABLE']);
    }
    ok($preparation);
}

// /grades/followups[/counts|/{id}[/decide|/attempt]] — assessment follow-ups
// after an Education reactivation (Attendance alerting Phase 5). Own role
// set (teacher scoped / edu_dept write; HR / Info / admins read) and no
// active-year requirement: historical assessments stay reachable (§12).
if ($action === 'followups') {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    require __DIR__ . '/assessment_followups.php';
    exit;
}
$year = getCurrentAcademicYear();

if (!$year) {
    err('No active academic year. Set one in Settings.', 400);
}

$yearId = (int)$year['id'];
$userId = $auth['uid'];
$userRole = $auth['rol'];

$restrictedRoles = ['teacher', 'attendance_taker'];
$isRestricted = in_array($userRole, $restrictedRoles);

// Helper: check teacher has access to class
function checkTeacherClassAccess($conn, $userId, $userRole, $classId, $yearId) {
    if (!in_array($userRole, ['teacher', 'attendance_taker'])) return;
    
    $stmt = $conn->prepare("SELECT id FROM teacher_assignments 
                            WHERE teacher_id = ? AND class_id = ? 
                            AND (academic_year_id IS NULL OR academic_year_id = ?)
                            AND is_active = 1");
    $stmt->bind_param('iii', $userId, $classId, $yearId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        err('You are not assigned to this class', 403);
    }
    $stmt->close();
}

// Helper: check teacher has access to specific subject in class
function checkTeacherSubjectAccess($conn, $userId, $userRole, $classId, $subjectId, $yearId) {
    if (!in_array($userRole, ['teacher', 'attendance_taker'])) return;
    
    $stmt = $conn->prepare("SELECT id FROM teacher_assignments 
                            WHERE teacher_id = ? AND class_id = ? AND subject_id = ?
                            AND (academic_year_id IS NULL OR academic_year_id = ?)
                            AND is_active = 1");
    $stmt->bind_param('iiii', $userId, $classId, $subjectId, $yearId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        err('You are not assigned to teach this subject in this class', 403);
    }
    $stmt->close();
}

/**
 * H1: honest empty-state for a teacher with NO subject rows for a class.
 * Class-level (subject_id NULL) assignments grant class access — the
 * teacher deserves to know WHY the subject list is empty and what to do.
 */
function teacherNoSubjectsNotice($conn, $userId, $classId) {
    $notice = 'No subjects are assigned to you for this class yet. Ask the Education department to assign you subjects.';
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM teacher_assignments
                                 WHERE teacher_id = ? AND class_id = ? AND subject_id IS NULL AND is_active = 1");
        $stmt->bind_param('ii', $userId, $classId);
        $stmt->execute();
        $c = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        if ($c > 0) {
            $notice = 'You are registered as a class teacher for this class, but no subjects are assigned to you yet. Ask the Education department to assign you subjects.';
        }
    } catch (Exception $e) {
        reportInternalError('teacherNoSubjectsNotice failed', $e);
    }
    return $notice;
}

// ============================================================
// GET /grades/bootstrap?class_id=X
// One round-trip: subjects the teacher may see + their assessments.
// ============================================================
if ($action === 'bootstrap' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    if (!$classId) err('class_id is required');
    checkTeacherClassAccess($conn, $userId, $userRole, $classId, $yearId);

    $subjects = [];
    $linked = true;
    $notice = null;
    if ($isRestricted) {
        $stmt = $conn->prepare(
            "SELECT DISTINCT s.id, s.subject_name, s.subject_name_en, s.subject_code
             FROM teacher_assignments ta
             JOIN subjects s ON ta.subject_id = s.id
             WHERE ta.teacher_id = ? AND ta.class_id = ?
               AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
               AND ta.is_active = 1 AND s.is_active = 1
             ORDER BY s.subject_name"
        );
        $stmt->bind_param('iii', $userId, $classId, $yearId);
    } else {
        // Subjects linked to the class, falling back to all active subjects
        // when none are linked yet (empty-dropdown fix).
        if (!class_exists('\\App\\Services\\AssignmentService')) {
            require_once __DIR__ . '/../../../admin/backend/services/AssignmentService.php';
        }
        $catalog = \App\Services\AssignmentService::subjectsForClass($conn, $classId);
        $linked = $catalog['linked'];
        $notice = $catalog['message'];
        $subjects = array_map(static function ($s) {
            return [
                'id' => (int)$s['id'],
                'subject_name' => $s['subject_name'],
                'subject_name_en' => $s['subject_name_en'] ?? null,
                'subject_code' => $s['subject_code'] ?? null,
            ];
        }, $catalog['subjects']);
        $stmt = null;
    }
    if ($stmt) {
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $subjects[] = $row;
        }
        $stmt->close();
    }

    $classStudentCount = 0;
    if (class_exists('\\App\\Services\\EnrollmentService')) {
        $scope = \App\Services\EnrollmentService::resolveRosterYear($conn, $classId, $yearId);
        $classStudentCount = (int)($scope['count'] ?? 0);
    }
    if ($classStudentCount <= 0) {
        $cstmt = $conn->prepare("SELECT COUNT(*) as cnt FROM class_enrollments WHERE class_id = ? AND status = 'active' AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)");
        if ($cstmt) {
            $cstmt->bind_param('ii', $classId, $yearId);
            $cstmt->execute();
            $classStudentCount = (int)($cstmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $cstmt->close();
        }
    }

    $assessments = [];
    $ids = array_map(static fn($s) => (int)$s['id'], $subjects);
    if ($ids) {
        $in = implode(',', $ids);
        $stmt = $conn->prepare(
            "SELECT a.id, a.subject_id, a.assessment_name, a.assessment_type,
                    a.weight_percentage, a.max_score, a.assessment_order, a.is_published,
                    COALESCE(g.cnt, 0) AS grades_entered,
                    (SELECT gs.status FROM grade_submissions gs
                      WHERE gs.assessment_id = a.id AND gs.submission_type = 'marklist'
                      ORDER BY gs.id DESC LIMIT 1) AS submission_status
             FROM assessments a
             LEFT JOIN (
                SELECT assessment_id, COUNT(*) AS cnt
                FROM academic_records
                WHERE class_id = ? AND academic_year_id = ?
                GROUP BY assessment_id
             ) g ON g.assessment_id = a.id
             WHERE a.class_id = ? AND a.academic_year_id = ?
               AND a.subject_id IN ($in)
             ORDER BY a.subject_id, a.assessment_order, a.created_at"
        );
        $stmt->bind_param('iiii', $classId, $yearId, $classId, $yearId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $ge = (int)$row['grades_entered'];
            $assessments[] = [
                'id' => (int)$row['id'],
                'subject_id' => (int)$row['subject_id'],
                'assessment_name' => $row['assessment_name'],
                'assessment_type' => $row['assessment_type'],
                'weight_percentage' => (float)$row['weight_percentage'],
                'max_score' => (float)$row['max_score'],
                'assessment_order' => (int)$row['assessment_order'],
                'is_published' => (bool)$row['is_published'],
                'grades_entered' => $ge,
                'graded_count' => $ge,
                'total_students' => $classStudentCount,
                'student_count' => $classStudentCount,
                'pending_count' => max(0, $classStudentCount - $ge),
                'completion_percentage' => $classStudentCount > 0 ? round(($ge / $classStudentCount) * 100, 1) : 0,
                'is_complete' => ($classStudentCount > 0 && $ge >= $classStudentCount),
                'submission_status' => $row['submission_status'] ?? null,
                'locked' => class_exists('\\App\\Services\\SubmissionService')
                    && \App\Services\SubmissionService::isLockedForTeacher($row['submission_status'] ?? null, $auth),
            ];
        }
        $stmt->close();
    }

    if (empty($subjects) && $isRestricted) {
        $notice = teacherNoSubjectsNotice($conn, $userId, $classId);
    }
    // 1.6.5 term-close model: semester context for the hero box and the
    // term switcher (current semester + every semester of the year).
    $bootTerms = [];
    $bootCurrent = null;
    try {
        $tq = $conn->prepare("SELECT id, term_name, term_number, is_current, is_reopened
                              FROM academic_terms WHERE academic_year_id = ? ORDER BY term_number");
        if ($tq) {
            $tq->bind_param('i', $yearId);
            $tq->execute();
            $tr = $tq->get_result();
            while ($trow = $tr->fetch_assoc()) {
                $trow['id'] = (int)$trow['id'];
                $trow['term_number'] = (int)$trow['term_number'];
                $trow['is_current'] = (bool)$trow['is_current'];
                $trow['is_reopened'] = (bool)$trow['is_reopened'];
                if ($trow['is_current']) {
                    $bootCurrent = ['id' => $trow['id'], 'term_name' => $trow['term_name'], 'term_number' => $trow['term_number']];
                }
                $bootTerms[] = $trow;
            }
            $tq->close();
        }
    } catch (Exception $eBootTerms) { /* pre-056 databases have no terms */ }
    ok([
        'subjects' => $subjects,
        'assessments' => $assessments,
        'class_id' => $classId,
        'linked' => $linked,
        'notice' => $notice,
        'terms' => $bootTerms,
        'current_term' => $bootCurrent,
    ]);
}

// ============================================================
// GET /grades/assessment-types
// ============================================================
if (($action === 'assessment-types' || $action === 'types') && $method === 'GET') {
    require_once dirname(__DIR__, 3) . '/admin/backend/services/AssessmentTypeService.php';
    $types = \App\Services\AssessmentTypeService::getAll($conn, true);
    ok(['types' => $types, 'count' => count($types)]);
}

// ============================================================
// GET /grades/subjects?class_id=X
// ============================================================
if ($action === 'subjects' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    if (!$classId) err('class_id is required');
    
    checkTeacherClassAccess($conn, $userId, $userRole, $classId, $yearId);
    
    $subjects = [];
    try {
        if ($isRestricted) {
            // TEACHER: Only subjects they're assigned to teach in this class
            $stmt = $conn->prepare("SELECT DISTINCT s.id, s.subject_name, s.subject_name_en, s.subject_code
                                    FROM teacher_assignments ta
                                    JOIN subjects s ON ta.subject_id = s.id
                                    WHERE ta.teacher_id = ? 
                                      AND ta.class_id = ?
                                      AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
                                      AND ta.is_active = 1
                                      AND s.is_active = 1
                                    ORDER BY s.subject_name");
            $stmt->bind_param('iii', $userId, $classId, $yearId);
        } else {
            // ADMIN/EDU_DEPT: subjects linked to the class, falling back to
            // all active subjects when none are linked yet (empty-dropdown fix)
            if (!class_exists('\\App\\Services\\AssignmentService')) {
                require_once __DIR__ . '/../../../admin/backend/services/AssignmentService.php';
            }
            $catalog = \App\Services\AssignmentService::subjectsForClass($conn, $classId);
            $linked = $catalog['linked'];
            $notice = $catalog['message'];
            $subjects = array_map(static function ($s) {
                return [
                    'id' => (int)$s['id'],
                    'subject_name' => $s['subject_name'],
                    'subject_name_en' => $s['subject_name_en'] ?? null,
                    'subject_code' => $s['subject_code'] ?? null,
                ];
            }, $catalog['subjects']);
            $stmt = null;
        }
        
        if ($stmt) {
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $subjects[] = $row;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        reportInternalError('API grades subject list failed', $e);
        err('Unable to load subjects.', 500);
    }
    
    if (empty($subjects) && $isRestricted) {
        $notice = teacherNoSubjectsNotice($conn, $userId, $classId);
    }
    ok([
        'subjects' => $subjects,
        'count' => count($subjects),
        'linked' => $linked ?? true,
        'notice' => $notice ?? null,
    ]);
}

// ============================================================
// GET /grades/assessments?class_id=X&subject_id=Y
// ============================================================
if ($action === 'assessments' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    $subjectId = (int)($_GET['subject_id'] ?? 0);
    if (!$classId || !$subjectId) err('class_id and subject_id are required');
    
    // Teachers can only see assessments for their assigned subjects
    if ($isRestricted) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $classId, $subjectId, $yearId);
    }
    
    $classStudentCount = 0;
    if (class_exists('\\App\\Services\\EnrollmentService')) {
        $scope = \App\Services\EnrollmentService::resolveRosterYear($conn, $classId, $yearId);
        $classStudentCount = (int)($scope['count'] ?? 0);
    }
    if ($classStudentCount <= 0) {
        $cstmt = $conn->prepare("SELECT COUNT(*) as cnt FROM class_enrollments WHERE class_id = ? AND status = 'active' AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)");
        if ($cstmt) {
            $cstmt->bind_param('ii', $classId, $yearId);
            $cstmt->execute();
            $classStudentCount = (int)($cstmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $cstmt->close();
        }
    }
    
    $assessments = [];
    // 1.6.5 term-close model: the list is TERM-SCOPED. Without term_id the
    // response shows only the CURRENT semester's assessments (the working
    // view — a closed semester's tests must not sit next to the live ones);
    // term_id=0 returns the whole year (legacy/explicit); term_id=N returns
    // that semester (view-only analysis of closed data for teachers).
    $termFilter = null;            // null = current-term default
    if (array_key_exists('term_id', $_GET)) {
        $tf = (int)$_GET['term_id'];
        if ($tf > 0) $termFilter = $tf;
        elseif ($tf === 0) $termFilter = 0;   // explicit "all terms"
    }
    $currentTerm = null;
    if (class_exists('\\App\\Services\\SubmissionService')) {
        $currentTerm = \App\Services\SubmissionService::currentTermOfActiveYear($conn);
    }
    try {
        $sql = "SELECT a.id, a.assessment_name, a.assessment_type, 
                       a.weight_percentage, a.max_score, a.description,
                       a.due_date, a.assessment_order, a.is_published, a.term_id,
                       (SELECT COUNT(*) FROM academic_records ar WHERE ar.assessment_id = a.id AND ar.score IS NOT NULL) as grades_entered
                FROM assessments a
                WHERE a.class_id = ? AND a.subject_id = ? AND a.academic_year_id = ?";
        $params = [$classId, $subjectId, $yearId];
        $types = 'iii';
        if ($termFilter === null && $currentTerm !== null) {
            $sql .= " AND (a.term_id = ? OR a.term_id IS NULL)";
            $params[] = (int)$currentTerm['id'];
            $types .= 'i';
        } elseif ($termFilter !== null && $termFilter > 0) {
            $sql .= " AND a.term_id = ?";
            $params[] = $termFilter;
            $types .= 'i';
        }
        $sql .= " ORDER BY a.assessment_order, a.created_at";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        $reopenedIds = [];
        try {
            $rr = $conn->query("SELECT id FROM academic_terms WHERE is_reopened = 1");
            if ($rr) { while ($rrow = $rr->fetch_assoc()) $reopenedIds[(int)$rrow['id']] = true; }
        } catch (Exception $eReopened) { /* pre-067 database */ }
        while ($row = $r->fetch_assoc()) {
            $ge = (int)$row['grades_entered'];
            $row['id'] = (int)$row['id'];
            $row['max_score'] = (float)$row['max_score'];
            $row['weight_percentage'] = (float)$row['weight_percentage'];
            $row['grades_entered'] = $ge;
            $row['graded_count'] = $ge;
            $row['total_students'] = $classStudentCount;
            $row['student_count'] = $classStudentCount;
            $row['pending_count'] = max(0, $classStudentCount - $ge);
            $row['completion_percentage'] = $classStudentCount > 0 ? round(($ge / $classStudentCount) * 100, 1) : 0;
            $row['is_complete'] = ($classStudentCount > 0 && $ge >= $classStudentCount);
            $row['is_published'] = (bool)$row['is_published'];
            $row['term_id'] = $row['term_id'] !== null ? (int)$row['term_id'] : null;
            $row['is_current_term'] = ($currentTerm !== null && $row['term_id'] !== null
                && $row['term_id'] === (int)$currentTerm['id']);
            $row['term_reopened'] = ($row['term_id'] !== null && isset($reopenedIds[$row['term_id']]));
            $row['term_locked'] = false;
            if (class_exists('\\App\\Services\\SubmissionService') && $row['term_id'] !== null) {
                $row['term_locked'] = !\App\Services\SubmissionService::termWritableForTeachers(
                    $conn, $auth, $row['term_id']
                );
            }
            $assessments[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        reportInternalError('API grades assessment list failed', $e);
        err('Unable to load assessments.', 500);
    }
    // The semester switcher data: every semester of the year with its
    // state, so the app can offer "view last semester" without extra calls.
    $termsOut = [];
    try {
        $tq = $conn->prepare("SELECT id, term_name, term_number, is_current, is_reopened
                              FROM academic_terms WHERE academic_year_id = ? ORDER BY term_number");
        if ($tq) {
            $tq->bind_param('i', $yearId);
            $tq->execute();
            $tr = $tq->get_result();
            while ($trow = $tr->fetch_assoc()) {
                $trow['id'] = (int)$trow['id'];
                $trow['term_number'] = (int)$trow['term_number'];
                $trow['is_current'] = (bool)$trow['is_current'];
                $trow['is_reopened'] = (bool)$trow['is_reopened'];
                $termsOut[] = $trow;
            }
            $tq->close();
        }
    } catch (Exception $eTerms) { /* pre-056 databases have no terms */ }
    $currentOut = $currentTerm !== null ? [
        'id' => (int)$currentTerm['id'],
        'term_name' => (string)$currentTerm['term_name'],
        'term_number' => (int)$currentTerm['term_number'],
    ] : null;
    ok([
        'assessments' => $assessments,
        'count' => count($assessments),
        'terms' => $termsOut,
        'current_term' => $currentOut,
    ]);
}

// ============================================================
// POST /grades/assessments — Create new assessment / batch apply
// ============================================================
if ($action === 'assessments' && $method === 'POST') {
    $body = getBody();
    $rawClasses = $body['class_ids'] ?? $body['class_id'] ?? 0;
    $rawSubjects = $body['subject_ids'] ?? $body['subject_id'] ?? 0;
    $items = $body['items'] ?? null;
    
    // Assessments are managed exclusively by the Education department (super_admin, school_admin, edu_dept)
    if ($isRestricted) {
        err('Only the Education department can create assessments. Teachers enter grades for assessments configured by the department.', 403);
    }

    // Resolve target classes
    $targetClassIds = [];
    if ($rawClasses === 'all') {
        $cRes = $conn->query("SELECT id FROM classes WHERE is_active = 1 ORDER BY level_order, id");
        while ($cRow = $cRes->fetch_assoc()) $targetClassIds[] = (int)$cRow['id'];
    } elseif (is_array($rawClasses)) {
        $targetClassIds = array_map('intval', array_filter($rawClasses));
    } elseif (is_string($rawClasses) && strpos($rawClasses, ',') !== false) {
        $targetClassIds = array_map('intval', array_filter(explode(',', $rawClasses)));
    } elseif ((int)$rawClasses > 0) {
        $targetClassIds = [(int)$rawClasses];
    }

    if (empty($targetClassIds)) {
        err('class_id or class_ids is required');
    }

    // Resolve target subjects
    $explicitSubjectIds = [];
    $allSubjectsRequested = false;
    if ($rawSubjects === 'all' || $rawSubjects === 0 || $rawSubjects === '0' || empty($rawSubjects)) {
        $allSubjectsRequested = true;
    } elseif (is_array($rawSubjects)) {
        $explicitSubjectIds = array_map('intval', array_filter($rawSubjects));
        if (empty($explicitSubjectIds) || in_array(0, $explicitSubjectIds, true)) $allSubjectsRequested = true;
    } elseif (is_string($rawSubjects) && strpos($rawSubjects, ',') !== false) {
        $explicitSubjectIds = array_map('intval', array_filter(explode(',', $rawSubjects)));
    } elseif ((int)$rawSubjects > 0) {
        $explicitSubjectIds = [(int)$rawSubjects];
    } else {
        $allSubjectsRequested = true;
    }

    // Batch Template Application via REST API
    if (!empty($items) && is_array($items)) {
        $totalWeight = 0;
        foreach ($items as $it) {
            $w = (float)($it['weight_percentage'] ?? $it['weight'] ?? 0);
            $maxS = (float)($it['max_score'] ?? 100);
            $name = trim($it['name'] ?? $it['assessment_name'] ?? '');
            if (empty($name) || $w <= 0 || $maxS <= 0) {
                err('Each assessment item must have a name, valid max score, and positive weight.', 422);
            }
            $totalWeight += $w;
        }
        if ($totalWeight > 100) {
            err("Total template weight is {$totalWeight}%, which exceeds 100%.", 422);
        }

        // 1.6.6 per-semester model: the template targets ONE semester —
        // the current one by default, or an explicit ACTIVE-year semester
        // (validated) for back-fill. It replaces only that semester's
        // un-graded scheme; the other semester is untouched.
        $termId = null;
        if (class_exists('\\App\\Services\\SubmissionService')) {
            $ctTpl = \App\Services\SubmissionService::currentTermOfActiveYear($conn);
            if ($ctTpl) $termId = (int)$ctTpl['id'];
        }
        $postedTermIdTpl = (int)($body['term_id'] ?? 0);
        if ($postedTermIdTpl > 0) {
            try {
                $vt = $conn->prepare("SELECT t.id FROM academic_terms t JOIN academic_years y ON y.id = t.academic_year_id WHERE t.id = ? AND y.status = 'active' LIMIT 1");
                $vt->bind_param('i', $postedTermIdTpl);
                $vt->execute();
                $vtr = $vt->get_result()->fetch_assoc();
                $vt->close();
                if (!$vtr) err('That semester is not part of the active academic year.', 422);
                $termId = (int)$vtr['id'];
            } catch (Exception $eVt) {
                err('That semester is not part of the active academic year.', 422);
            }
        }
        // 1.6.6: weights are validated before anything is deleted — each
        // item sane, and the scheme itself cannot exceed the 100% semester
        // budget (this path previously had NO weight validation at all).
        {
            $tplTotal = 0.0;
            foreach ($items as $it) {
                $w = (float)($it['weight_percentage'] ?? $it['weight'] ?? 0);
                if ($w <= 0 || $w > 100) {
                    err("Each assessment item needs a weight between 1 and 100 (got {$w}%).", 422);
                }
                $tplTotal += $w;
            }
            if ($tplTotal > 100) {
                err("Total template weight is {$tplTotal}%, which exceeds the 100% semester budget.", 422);
            }
        }

        $appliedCount = 0;
        $skippedCount = 0;
        $conn->begin_transaction();
        try {
            foreach ($targetClassIds as $cid) {
                $sids = [];
                if ($allSubjectsRequested) {
                    $stmt = $conn->prepare("SELECT subject_id FROM class_subjects WHERE class_id = ?");
                    $stmt->bind_param("i", $cid);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) $sids[] = (int)$r['subject_id'];
                    $stmt->close();
                    if (empty($sids)) {
                        $res = $conn->query("SELECT id FROM subjects WHERE is_active = 1");
                        while ($r = $res->fetch_assoc()) $sids[] = (int)$r['id'];
                    }
                } else {
                    $sids = $explicitSubjectIds;
                }

                foreach ($sids as $sid) {
                    if ($termId !== null) {
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as grade_count 
                            FROM academic_records ar
                            JOIN assessments a ON ar.assessment_id = a.id
                            WHERE a.class_id = ? AND a.subject_id = ? AND a.academic_year_id = ? AND (a.term_id = ? OR a.term_id IS NULL)
                        ");
                        $stmt->bind_param("iiii", $cid, $sid, $yearId, $termId);
                    } else {
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as grade_count 
                            FROM academic_records ar
                            JOIN assessments a ON ar.assessment_id = a.id
                            WHERE a.class_id = ? AND a.subject_id = ? AND a.academic_year_id = ?
                        ");
                        $stmt->bind_param("iii", $cid, $sid, $yearId);
                    }
                    $stmt->execute();
                    $hasGrades = (int)$stmt->get_result()->fetch_assoc()['grade_count'] > 0;
                    $stmt->close();

                    if ($hasGrades) {
                        $skippedCount++;
                        continue;
                    }

                    if ($termId !== null) {
                        $stmt = $conn->prepare("DELETE FROM assessments WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND (term_id = ? OR term_id IS NULL)");
                        $stmt->bind_param("iiii", $cid, $sid, $yearId, $termId);
                    } else {
                        $stmt = $conn->prepare("DELETE FROM assessments WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?");
                        $stmt->bind_param("iii", $cid, $sid, $yearId);
                    }
                    $stmt->execute();
                    $stmt->close();

                    $order = 1;
                    foreach ($items as $it) {
                        $name = trim($it['name'] ?? $it['assessment_name'] ?? '');
                        $type = $it['type'] ?? $it['assessment_type'] ?? 'test';
                        $w = (float)($it['weight_percentage'] ?? $it['weight'] ?? 0);
                        $maxS = (float)($it['max_score'] ?? 100);
                        $desc = trim($it['description'] ?? '');

                        $ins = $conn->prepare("
                            INSERT INTO assessments 
                            (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, 
                             weight_percentage, max_score, description, assessment_order, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $ins->bind_param("iiiissddssi", $cid, $sid, $yearId, $termId, $name, $type, $w, $maxS, $desc, $order, $userId);
                        $ins->execute();
                        $ins->close();
                        $order++;
                    }
                    $appliedCount++;
                }
            }
            $conn->commit();
            ok([
                'message' => "Assessment scheme applied to {$appliedCount} class-subject(s)",
                'applied_count' => $appliedCount,
                'skipped_count' => $skippedCount,
                'classes_count' => count($targetClassIds)
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            reportInternalError('API batch assessment template failed', $e);
            err('Failed to apply assessment template. Please try again.', 500);
        }
    }

    // Single / Multi-target Assessment creation
    $name = trim($body['assessment_name'] ?? '');
    $type = $body['assessment_type'] ?? 'test';
    $maxScore = (float)($body['max_score'] ?? 100);
    $weight = (float)($body['weight_percentage'] ?? $body['weight'] ?? 100);
    $dueDate = $body['due_date'] ?? null;
    $desc = trim($body['description'] ?? '');
    
    if (!$name) {
        err('assessment_name is required');
    }
    
    if ($maxScore <= 0 || $maxScore > 1000) {
        err('Max score must be between 1 and 1000');
    }
    
    if ($weight <= 0 || $weight > 100) {
        err('Weight percentage must be between 1 and 100');
    }
    
    $validTypes = ['test', 'quiz', 'midterm', 'final', 'assignment', 'project', 'participation', 'other'];
    if (!in_array($type, $validTypes)) {
        $type = 'test';
    }
    
    // 1.6.6: same semester rules as the website — default the current
    // semester of the active year, allow a validated ACTIVE-year override
    // (back-fill), and charge the 100% budget PER SEMESTER.
    $termId = null;
    if (class_exists('\\App\\Services\\SubmissionService')) {
        $ctS = \App\Services\SubmissionService::currentTermOfActiveYear($conn);
        if ($ctS) $termId = (int)$ctS['id'];
    }
    $postedTermIdS = (int)($body['term_id'] ?? 0);
    if ($postedTermIdS > 0) {
        try {
            $vt = $conn->prepare("SELECT t.id FROM academic_terms t JOIN academic_years y ON y.id = t.academic_year_id WHERE t.id = ? AND y.status = 'active' LIMIT 1");
            $vt->bind_param('i', $postedTermIdS);
            $vt->execute();
            $vtr = $vt->get_result()->fetch_assoc();
            $vt->close();
            if (!$vtr) err('That semester is not part of the active academic year.', 422);
            $termId = (int)$vtr['id'];
        } catch (Exception $eVt2) {
            // Fail closed: if the override cannot be verified, it is refused.
            err('That semester is not part of the active academic year.', 422);
        }
    }

    // If single target
    if (count($targetClassIds) === 1 && !$allSubjectsRequested && count($explicitSubjectIds) === 1) {
        $classId = $targetClassIds[0];
        $subjectId = $explicitSubjectIds[0];

        $order = 1;
        try {
            $stmt = $conn->prepare("SELECT MAX(assessment_order) as mx FROM assessments WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?");
            $stmt->bind_param('iii', $classId, $subjectId, $yearId);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            if ($r && $r['mx']) $order = (int)$r['mx'] + 1;
            $stmt->close();
        } catch (Exception $e) {}

        // 1.6.6: the 100% budget is per SEMESTER (year-wide only when no
        // semester exists — legacy databases).
        try {
            if ($termId !== null) {
                $stmt = $conn->prepare(
                    "SELECT COALESCE(SUM(weight_percentage), 0) AS total
                     FROM assessments
                     WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?"
                );
                $stmt->bind_param('iiii', $classId, $subjectId, $yearId, $termId);
            } else {
                $stmt = $conn->prepare(
                    "SELECT COALESCE(SUM(weight_percentage), 0) AS total
                     FROM assessments
                     WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?"
                );
                $stmt->bind_param('iii', $classId, $subjectId, $yearId);
            }
            $stmt->execute();
            $currentTotal = (float)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } catch (Exception $e) {
            $currentTotal = 0.0;
        }
        if ($currentTotal + $weight > 100) {
            $remaining = max(0, 100 - $currentTotal);
            err("This semester's assessments already total {$currentTotal}%; only {$remaining}% remains. Please adjust the weight.", 422);
        }
        
        try {
            $stmt = $conn->prepare("INSERT INTO assessments 
                (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, weight_percentage, max_score, description, due_date, assessment_order, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iiiissddssii', $classId, $subjectId, $yearId, $termId, $name, $type, $weight, $maxScore, $desc, $dueDate, $order, $userId);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            
            logApiAction($userId, $auth['usr'], 'create_assessment', 
                "Created assessment '{$name}' for class #{$classId} subject #{$subjectId}");
            
            ok(['id' => $newId, 'message' => 'Assessment created', 'new_total' => $currentTotal + $weight]);
        } catch (Exception $e) {
            reportInternalError('API grades assessment creation failed', $e);
            err('Unable to create assessment.', 500);
        }
    }

    // Multi-target creation
    $createdCount = 0;
    $exceededCount = 0;
    $conn->begin_transaction();
    try {
        foreach ($targetClassIds as $cid) {
            $sids = [];
            if ($allSubjectsRequested) {
                $stmt = $conn->prepare("SELECT subject_id FROM class_subjects WHERE class_id = ?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $sids[] = (int)$r['subject_id'];
                $stmt->close();
                if (empty($sids)) {
                    $res = $conn->query("SELECT id FROM subjects WHERE is_active = 1");
                    while ($r = $res->fetch_assoc()) $sids[] = (int)$r['id'];
                }
            } else {
                $sids = $explicitSubjectIds;
            }

            foreach ($sids as $sid) {
                // 1.6.6: per-semester budget (year-wide only when no
                // semester exists — legacy databases).
                if ($termId !== null) {
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(weight_percentage), 0) as total,
                               COALESCE(MAX(assessment_order), 0) + 1 as next_order
                        FROM assessments 
                        WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?
                    ");
                    $stmt->bind_param("iiii", $cid, $sid, $yearId, $termId);
                } else {
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(weight_percentage), 0) as total,
                               COALESCE(MAX(assessment_order), 0) + 1 as next_order
                        FROM assessments 
                        WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?
                    ");
                    $stmt->bind_param("iii", $cid, $sid, $yearId);
                }
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $currTot = (float)($row['total'] ?? 0);
                $nextOrd = (int)($row['next_order'] ?? 1);

                if ($currTot + $weight > 100) {
                    $exceededCount++;
                    continue;
                }

                $ins = $conn->prepare("
                    INSERT INTO assessments 
                    (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, 
                     weight_percentage, max_score, description, due_date, assessment_order, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param(
                    "iiiissddssii",
                    $cid, $sid, $yearId, $termId, $name, $type,
                    $weight, $maxScore, $desc, $dueDate, $nextOrd, $userId
                );
                $ins->execute();
                $ins->close();
                $createdCount++;
            }
        }
        $conn->commit();
        ok([
            'message' => "Assessment created across {$createdCount} class-subject(s)",
            'created_count' => $createdCount,
            'exceeded_count' => $exceededCount
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        reportInternalError('API batch assessment creation failed', $e);
        err('Unable to create assessments. Please try again.', 500);
    }
}

// ============================================================
// GET /grades/students?assessment_id=X — Students with scores
// ============================================================
if ($action === 'students' && $method === 'GET') {
    $assessmentId = (int)($_GET['assessment_id'] ?? 0);
    if (!$assessmentId) err('assessment_id is required');
    
    // Get assessment info
    $assessment = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM assessments WHERE id = ?");
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Exception $e) {}
    
    if (!$assessment) err('Assessment not found', 404);
    
    $aClassId = (int)$assessment['class_id'];
    $aSubjectId = (int)$assessment['subject_id'];
    
    // Check both class AND subject access for teachers
    if ($isRestricted) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $aClassId, $aSubjectId, $yearId);
    }
    
    $students = [];
    $scope = ['year_id' => (int)$assessment['academic_year_id'], 'fallback' => false, 'year_name' => null];
    try {
        $preferYear = (int)$assessment['academic_year_id'] ?: $yearId;
        if (class_exists('\\App\\Services\\EnrollmentService')) {
            $scope = \App\Services\EnrollmentService::resolveRosterYear($conn, $aClassId, $preferYear);
            $roster = \App\Services\EnrollmentService::fetchRoster($conn, $aClassId, $scope['year_id'] ?? null);
        } else {
            $roster = [];
            $stmt = $conn->prepare("SELECT ce.member_id, m.student_name, m.father_name, m.member_code, m.gender
                                    FROM class_enrollments ce
                                    JOIN members m ON ce.member_id = m.id
                                    WHERE ce.class_id = ? AND ce.academic_year_id = ? AND ce.status = 'active'
                                    ORDER BY m.student_name");
            $stmt->bind_param('ii', $aClassId, $preferYear);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $roster[] = $row;
            $stmt->close();
        }

        $gradesByMember = [];
        $gstmt = $conn->prepare("SELECT id, member_id, score, remarks FROM academic_records WHERE assessment_id = ? ORDER BY id ASC");
        if ($gstmt) {
            $gstmt->bind_param('i', $assessmentId);
            $gstmt->execute();
            $gr = $gstmt->get_result();
            while ($grow = $gr->fetch_assoc()) {
                $gradesByMember[(int)$grow['member_id']] = $grow;
            }
            $gstmt->close();
        }

        foreach ($roster as $row) {
            $mid = (int)($row['member_id'] ?? $row['id'] ?? 0);
            if ($mid <= 0) continue;
            $g = $gradesByMember[$mid] ?? null;
            $students[] = [
                'member_id' => $mid,
                'student_name' => $row['student_name'] ?? '',
                'father_name' => $row['father_name'] ?? '',
                'member_code' => $row['member_code'] ?? '',
                'gender' => $row['gender'] ?? '',
                'record_id' => $g && !empty($g['id']) ? (int)$g['id'] : null,
                'score' => $g && $g['score'] !== null ? (float)$g['score'] : null,
                'remarks' => $g['remarks'] ?? null,
            ];
        }
    } catch (Exception $e) {
        reportInternalError('API grades student list failed', $e);
        err('Unable to load students.', 500);
    }
    
    $packetStatus = null;
    $locked = false;
    $review = null;
    if (class_exists('\\App\\Services\\SubmissionService')) {
        $packetStatus = \App\Services\SubmissionService::resolvedMarklistStatus($conn, $assessmentId);
        $locked = \App\Services\SubmissionService::isLockedForTeacher($packetStatus, $auth);
        if ($packetStatus === \App\Services\SubmissionService::STATUS_REVISION) {
            $review = \App\Services\SubmissionService::marklistReview($conn, $assessmentId);
        }
    }

    $totalStudents = count($students);
    $gradedCount = 0;
    foreach ($students as $s) {
        if ($s['score'] !== null && $s['score'] !== '') {
            $gradedCount++;
        }
    }
    $pendingCount = max(0, $totalStudents - $gradedCount);
    $completionPercentage = $totalStudents > 0 ? round(($gradedCount / $totalStudents) * 100, 1) : 0;

    // 1.6.5 term-close model: expose the semester context + whether THIS
    // actor may write into it, so the entry screen can lock before a
    // keystroke is wasted (the save path re-enforces server-side).
    $termCtx = null;
    $termLocked = false;
    $termReopened = false;
    if (!empty($assessment['term_id'])) {
        $atid = (int)$assessment['term_id'];
        try {
            $tst = $conn->prepare("SELECT id, term_name, term_number, is_current, is_reopened FROM academic_terms WHERE id=? LIMIT 1");
            if ($tst) {
                $tst->bind_param('i', $atid);
                $tst->execute();
                $trow = $tst->get_result()->fetch_assoc();
                $tst->close();
                if ($trow) {
                    $termReopened = (bool)$trow['is_reopened'];
                    $currentT = class_exists('\\App\\Services\\SubmissionService')
                        ? \App\Services\SubmissionService::currentTermOfActiveYear($conn) : null;
                    $termCtx = [
                        'id' => (int)$trow['id'],
                        'term_name' => $trow['term_name'],
                        'term_number' => (int)$trow['term_number'],
                        'is_current' => ($currentT !== null && (int)$trow['id'] === (int)$currentT['id']),
                    ];
                }
            }
        } catch (Exception $eTermCtx) { /* pre-056/067 */ }
        if (class_exists('\\App\\Services\\SubmissionService')) {
            $termLocked = !\App\Services\SubmissionService::termWritableForTeachers($conn, $auth, $atid);
        }
    }
    ok([
        'assessment' => [
            'id' => (int)$assessment['id'],
            'assessment_name' => $assessment['assessment_name'],
            'max_score' => (float)$assessment['max_score'],
            'weight_percentage' => (float)$assessment['weight_percentage'],
            'term_id' => !empty($assessment['term_id']) ? (int)$assessment['term_id'] : null,
            'term' => $termCtx,
            'term_locked' => $termLocked,
            'term_reopened' => $termReopened,
        ],
        'students' => $students,
        'count' => $totalStudents,
        'total_students' => $totalStudents,
        'graded_count' => $gradedCount,
        'pending_count' => $pendingCount,
        'completion_percentage' => $completionPercentage,
        'roster_year_id' => $scope['year_id'] ?? null,
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
// POST /grades/save — Save grades for an assessment
// ============================================================
if ($action === 'save' && $method === 'POST') {
    $body = getBody();
    $assessmentId = (int)($body['assessment_id'] ?? 0);
    $grades = $body['grades'] ?? [];
    
    if (!$assessmentId || empty($grades)) {
        err('assessment_id and grades array are required');
    }
    apiIdempotencyBegin((int)$userId, (string)($body['client_op_id'] ?? ''));

    // Get assessment
    $assessment = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM assessments WHERE id = ?");
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Exception $e) {}
    
    if (!$assessment) err('Assessment not found', 404);
    
    $aClassId = (int)$assessment['class_id'];
    $aSubjectId = (int)$assessment['subject_id'];
    
    // Check both class AND subject access
    if ($isRestricted) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $aClassId, $aSubjectId, $yearId);
    }

    if (class_exists('\\App\\Services\\SubmissionService')) {
        // 1.6.5 term-close model: same gate as the web path — the semester
        // must be current or reopened for teachers; staff always pass.
        $refusal = \App\Services\SubmissionService::teacherWriteRefusal($conn, $auth, $assessmentId);
        if ($refusal !== null) {
            $st = \App\Services\SubmissionService::resolvedMarklistStatus($conn, $assessmentId);
            err($refusal, 409,
                ['code' => ($st !== null && !\App\Services\SubmissionService::statusIsOpen($st)) ? 'ALREADY_SUBMITTED' : 'TERM_CLOSED']);
        }
    }
    
    // Score-limit rule (2026-10-22): refuse the whole list, write nothing.
    $badScores = \App\Services\SubmissionService::invalidScores($conn, $grades, (float)$assessment['max_score']);
    if ($badScores) {
        err(\App\Services\SubmissionService::invalidScoresMessage($badScores, (float)$assessment['max_score']), 422,
            ['code' => 'INVALID_SCORES', 'invalid' => $badScores, 'max_score' => (float)$assessment['max_score']]);
    }

    $successCount = 0;
    $errors = [];
    
    foreach ($grades as $grade) {
        $memberId = (int)($grade['member_id'] ?? 0);
        $score = isset($grade['score']) && $grade['score'] !== '' && $grade['score'] !== null ? (float)$grade['score'] : null;
        $remarks = trim($grade['remarks'] ?? $grade['remark'] ?? '');
        
        if (!$memberId) continue;
        
        if ($score !== null && ($score < 0 || $score > (float)$assessment['max_score'])) {
            $errors[] = "Invalid score for member $memberId (max: {$assessment['max_score']})";
            continue;
        }
        
        try {
            if (class_exists('\\App\\Services\\SubmissionService')) {
                $rid = \App\Services\SubmissionService::upsertScore($conn, [
                    'assessment_id' => $assessmentId,
                    'member_id' => $memberId,
                    'score' => $score,
                    'remarks' => $remarks,
                    'recorded_by' => $userId,
                    'class_id' => $aClassId,
                    'subject_id' => $aSubjectId,
                    'year_id' => (int)($assessment['academic_year_id'] ?? $yearId),
                    'term_id' => $assessment['term_id'] ? (int)$assessment['term_id'] : null,
                    'max_score' => (float)$assessment['max_score'],
                ]);
                if ($rid) $successCount++;
            } else {
                $successCount++;
            }
        } catch (Exception $e) {
            $errors[] = "Error saving grade for member $memberId";
        }
    }
    
    $avg = null;
    $scoreSum = 0;
    $scoreN = 0;
    foreach ($grades as $g) {
        if (isset($g['score']) && $g['score'] !== '' && $g['score'] !== null) {
            $scoreSum += (float)$g['score'];
            $scoreN++;
        }
    }
    if ($scoreN > 0) {
        $avg = $scoreSum / $scoreN;
    }

    apiEnsureSubmissionsTable();
    $packet = ['id' => 0, 'status' => 'draft', 'message' => "$successCount grade(s) saved as a draft"];
    if (class_exists('\\App\\Services\\SubmissionService')) {
        $packet = \App\Services\SubmissionService::upsertMarklist($conn, [
            'teacher_id' => $userId,
            'class_id' => $aClassId,
            'subject_id' => $aSubjectId,
            'assessment_id' => $assessmentId,
            'status' => \App\Services\SubmissionService::STATUS_DRAFT,
            'student_count' => $successCount,
            'average' => $avg,
            'year_id' => (int)($assessment['academic_year_id'] ?? $yearId),
            'term_id' => $assessment['term_id'] ?? null,
            'force' => \App\Services\SubmissionService::staffCanOverride($auth),
        ]);
    }

    logApiAction($userId, $auth['usr'], 'save_grades', 
        "Saved $successCount grades for assessment #{$assessmentId}");
    
    ok([
        'message' => $packet['message'] ?? "$successCount grade(s) saved — Education can see this as incomplete",
        'saved' => $successCount,
        'errors' => $errors,
        'submission_id' => $packet['id'] ?? 0,
        'submission_status' => $packet['status'] ?? 'incomplete',
    ]);
}

// ============================================================
// POST /grades/submit — save scores + send marklist to Education
// ============================================================
if ($action === 'submit' && $method === 'POST') {
    if (isApiRateLimited('grades_submit', 20)) {
        err('Too many submits. Please wait a moment.', 429);
    }

    $body = getBody();
    $assessmentId = (int)($body['assessment_id'] ?? 0);
    $grades = $body['grades'] ?? [];
    if (!$assessmentId || empty($grades)) {
        err('assessment_id and grades array are required');
    }
    apiIdempotencyBegin((int)$userId, (string)($body['client_op_id'] ?? ''));

    $stmt = $conn->prepare("SELECT * FROM assessments WHERE id = ?");
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$assessment) err('Assessment not found', 404);

    $aClassId = (int)$assessment['class_id'];
    $aSubjectId = (int)$assessment['subject_id'];
    if ($isRestricted) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $aClassId, $aSubjectId, $yearId);
    }

    if (class_exists('\\App\\Services\\SubmissionService')) {
        // 1.6.5 term-close model: same gate as the web path — the semester
        // must be current or reopened for teachers; staff always pass.
        $refusal = \App\Services\SubmissionService::teacherWriteRefusal($conn, $auth, $assessmentId);
        if ($refusal !== null) {
            $st = \App\Services\SubmissionService::resolvedMarklistStatus($conn, $assessmentId);
            err($refusal, 409,
                ['code' => ($st !== null && !\App\Services\SubmissionService::statusIsOpen($st)) ? 'ALREADY_SUBMITTED' : 'TERM_CLOSED']);
        }
    }

    // Score-limit rule (2026-10-22): an invalid score used to be skipped
    // silently while the packet was still locked as SUBMITTED, so that
    // student vanished from the submission. Refuse the whole list instead.
    $badScores = \App\Services\SubmissionService::invalidScores($conn, $grades, (float)$assessment['max_score']);
    if ($badScores) {
        err(\App\Services\SubmissionService::invalidScoresMessage($badScores, (float)$assessment['max_score']), 422,
            ['code' => 'INVALID_SCORES', 'invalid' => $badScores, 'max_score' => (float)$assessment['max_score']]);
    }

    apiEnsureSubmissionsTable();
    $termId = $assessment['term_id'] ? (int)$assessment['term_id'] : null;
    $ayId = (int)($assessment['academic_year_id'] ?: $yearId);
    $saved = 0;
    $totalScore = 0;
    $scoreCount = 0;

    foreach ($grades as $grade) {
        $memberId = (int)($grade['member_id'] ?? 0);
        $score = isset($grade['score']) && $grade['score'] !== '' && $grade['score'] !== null ? (float)$grade['score'] : null;
        $remarks = trim($grade['remarks'] ?? $grade['remark'] ?? '');
        if (!$memberId) continue;
        if ($score !== null && ($score < 0 || $score > (float)$assessment['max_score'])) continue;
        if (class_exists('\\App\\Services\\SubmissionService')) {
            $rid = \App\Services\SubmissionService::upsertScore($conn, [
                'assessment_id' => $assessmentId,
                'member_id' => $memberId,
                'score' => $score,
                'remarks' => $remarks,
                'recorded_by' => $userId,
                'class_id' => $aClassId,
                'subject_id' => $aSubjectId,
                'year_id' => $ayId,
                'term_id' => $termId,
                'max_score' => (float)$assessment['max_score'],
            ]);
            if ($rid) $saved++;
        } else {
            $saved++;
        }
        if ($score !== null) { $totalScore += $score; $scoreCount++; }
    }

    $avg = $scoreCount > 0 ? $totalScore / $scoreCount : null;
    $packet = ['ok' => true, 'id' => 0, 'status' => 'submitted'];
    if (class_exists('\\App\\Services\\SubmissionService')) {
        $packet = \App\Services\SubmissionService::upsertMarklist($conn, [
            'teacher_id' => $userId,
            'class_id' => $aClassId,
            'subject_id' => $aSubjectId,
            'assessment_id' => $assessmentId,
            'status' => \App\Services\SubmissionService::STATUS_SUBMITTED,
            'student_count' => $saved,
            'average' => $avg,
            'year_id' => $ayId,
            'term_id' => $termId,
            'force' => \App\Services\SubmissionService::staffCanOverride($auth),
        ]);
        if (empty($packet['ok'])) {
            err($packet['message'] ?? 'This test is already submitted. Only Education can change scores now.', 409,
            ['code' => 'WORKFLOW_REJECTED']);
        }
    }

    logApiAction($userId, $auth['usr'], 'submit_grades', "Submitted $saved grades for assessment #{$assessmentId}");

    ok([
        'message' => $packet['message'] ?? "$saved grade(s) sent to Education",
        'saved' => $saved,
        'submission_id' => $packet['id'] ?? 0,
        'submission_status' => $packet['status'] ?? 'submitted',
    ]);
}

// ============================================================
// // GET /grades/summary?class_id=X&subject_id=Y — Grade report
// ============================================================
if ($action === 'summary' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    $subjectId = (int)($_GET['subject_id'] ?? 0);
    
    if (!$classId) err('class_id is required');
    
    checkTeacherClassAccess($conn, $userId, $userRole, $classId, $yearId);
    
    // For teachers, only show summary for their assigned subjects
    if ($isRestricted && $subjectId) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $classId, $subjectId, $yearId);
    }
    
    $data = [];
    try {
        $sql = "SELECT a.id as assessment_id, a.assessment_name, a.assessment_type, 
                       a.weight_percentage, a.max_score, a.subject_id,
                       s.subject_name,
                       ar.member_id, ar.score, ar.remarks,
                       m.student_name, m.father_name, m.member_code
                FROM assessments a
                JOIN subjects s ON a.subject_id = s.id
                LEFT JOIN academic_records ar ON ar.assessment_id = a.id
                LEFT JOIN members m ON ar.member_id = m.id
                WHERE a.class_id = ? AND a.academic_year_id = ?";
        $params = [$classId, $yearId];
        $types = 'ii';
        
        if ($subjectId) {
            $sql .= " AND a.subject_id = ?";
            $params[] = $subjectId;
            $types .= 'i';
        } elseif ($isRestricted) {
            // If no subject specified, only show teacher's assigned subjects
            $sql .= " AND a.subject_id IN (SELECT ta2.subject_id FROM teacher_assignments ta2 
                       WHERE ta2.teacher_id = ? AND ta2.class_id = ? 
                       AND (ta2.academic_year_id IS NULL OR ta2.academic_year_id = ?)
                       AND ta2.is_active = 1)";
            $params[] = $userId;
            $params[] = $classId;
            $params[] = $yearId;
            $types .= 'iii';
        }
        
        $sql .= " ORDER BY a.assessment_order, m.student_name";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $data[] = $row;
        $stmt->close();
    } catch (Exception $e) {
        reportInternalError('API grades summary failed', $e);
        err('Unable to load grade summary.', 500);
    }
    
    ok(['data' => $data, 'class_id' => $classId, 'subject_id' => $subjectId]);
}

// ════════════════════════════════════════════════════════════════
// EDUCATION DEPARTMENT REVIEW INBOX (Phase 9)
// Mobile mirror of the web console's Submissions workflow
// (api_communication.php): list → detail → approve / reject /
// return-with-reason. Server stays the single source of truth.
// ════════════════════════════════════════════════════════════════

$__eduReviewRoles = ['edu_dept', 'school_admin', 'super_admin'];

// ── GET /grades/student-results?assessment_id=&member_id= ───────
// Phase 2 (owner D2, 2026-10-22): the private full-screen result card —
// one student's results for the same class + subject + semester.
if ($method === 'GET' && $action === 'student-results') {
    $assessmentId = (int)($_GET['assessment_id'] ?? 0);
    $memberId = (int)($_GET['member_id'] ?? 0);
    if ($assessmentId <= 0 || $memberId <= 0) err('assessment_id and member_id are required', 422);
    if (isApiRateLimited('grades_student_results', 120)) {
        err('Too many requests. Please wait a moment.', 429);
    }
    require_once __DIR__ . '/../../../admin/backend/services/SubmissionService.php';
    $stmt = $conn->prepare('SELECT class_id, subject_id FROM assessments WHERE id = ?');
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $own = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$own) err('Test not found.', 404);
    if ($isRestricted) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, (int)$own['class_id'], (int)$own['subject_id'], $yearId);
    }
    try {
        $out = \App\Services\SubmissionService::studentResults($conn, $assessmentId, $memberId);
    } catch (Throwable $e) {
        reportInternalError('grades student-results failed', $e);
        err('Could not load results. Please try again.', 500);
    }
    if (($out['status'] ?? '') !== 'success') {
        err((string)($out['message'] ?? 'Not found.'), ($out['code'] ?? '') === 'not_found' ? 404 : 422);
    }
    unset($out['status']);
    ok($out);
}

// ── GET /grades/submissions — department review queue ───────────
if ($method === 'GET' && $action === 'submissions') {
    if (!in_array($userRole, $__eduReviewRoles, true)) {
        err('Only the Education department can review submissions.', 403);
    }
    if (isApiRateLimited('edu_submissions_list', 60)) {
        err('Too many requests. Please wait a moment.', 429);
    }
    require_once __DIR__ . '/../../../admin/backend/services/SubmissionService.php';
    try {
        $filters = [
            'class_id' => (int)($_GET['class_id'] ?? 0),
            'status'   => (string)($_GET['status_filter'] ?? 'attention'),
            'type'     => (string)($_GET['type'] ?? ''),
        ];
        $stats = \App\Services\SubmissionService::stats($conn);
        // Phase 3 paging: only clients that send with_total=1 (APK 1.7.3+)
        // get pages. Older APKs already send page=1&per_page=50 and must
        // keep receiving the newest 200 exactly as before.
        if ((string)($_GET['with_total'] ?? '') === '1') {
            $out = \App\Services\SubmissionService::listPaged(
                $conn, $filters, (int)($_GET['page'] ?? 1), (int)($_GET['per_page'] ?? 50)
            );
            ok([
                'submissions' => $out['items'],
                'total' => $out['total'],
                'page' => $out['page'],
                'total_pages' => $out['total_pages'],
                'per_page' => $out['per_page'],
                'stats' => $stats,
            ]);
        }
        $submissions = \App\Services\SubmissionService::list($conn, $filters);
        ok(['submissions' => $submissions, 'stats' => $stats]);
    } catch (Throwable $e) {
        err('Could not load submissions. Please try again.', 500);
    }
}

// ── GET /grades/submission?id=N — full packet for review ────────
if ($method === 'GET' && $action === 'submission') {
    if (!in_array($userRole, $__eduReviewRoles, true)) {
        err('Only the Education department can review submissions.', 403);
    }
    require_once __DIR__ . '/../../../admin/backend/services/SubmissionService.php';
    $detail = \App\Services\SubmissionService::detail($conn, (int)($_GET['id'] ?? 0));
    if (!$detail) err('Submission not found.', 404);
    ok(['submission' => $detail]);
}

// ── POST /grades/submission-review — decide a packet ────────────
if ($method === 'POST' && $action === 'submission-review') {
    if (!in_array($userRole, $__eduReviewRoles, true)) {
        err('Only the Education department can review submissions.', 403);
    }
    if (isApiRateLimited('edu_submission_review', 30)) {
        err('Too many reviews. Please wait a moment.', 429);
    }
    require_once __DIR__ . '/../../../admin/backend/services/SubmissionService.php';
    require_once __DIR__ . '/../../../admin/backend/services/SecurityAuditService.php';
    require_once __DIR__ . '/../../../admin/backend/services/AttendanceSubmissionEventService.php';
    require_once __DIR__ . '/../../../admin/backend/services/AttendanceWarningService.php';

    $input = getBody();
    $submissionId = (int)($input['id'] ?? 0);
    $newStatus = (string)($input['status'] ?? '');
    $notes = trim((string)($input['notes'] ?? ''));

    if (!$submissionId || !in_array($newStatus, ['approved', 'rejected', 'revision_needed'], true)) {
        err('Invalid review parameters.', 422);
    }
    if ($newStatus !== 'approved' && mb_strlen($notes) < 3) {
        err('Write a short reason so the teacher knows what to fix.', 422);
    }

    // Phase 3 (attendance alerting): same decision path as the web console
    // — H9 transition guard, race-safe UPDATE, audit, per-packet history and
    // attendance-warning recalculation. ATTENDANCE sheets: every non-approve
    // decision (return / reject / reopen, submitted or approved) is edu_dept
    // only (owner decision Q-1, 2026-10-19); reason required.
    $review = \App\Services\SubmissionService::reviewPacket(
        $conn, $submissionId, $newStatus, $notes, (int)$userId, (string)$userRole, 'mobile'
    );
    if (empty($review['ok'])) {
        err((string)($review['message'] ?? 'Review failed.'), (int)($review['http'] ?? 409));
    }
    ok(['message' => (string)$review['message'], 'reopened' => !empty($review['reopened'])]);
}

err("No handler for {$method} /grades" . ($action ? "/{$action}" : ''), 404);
