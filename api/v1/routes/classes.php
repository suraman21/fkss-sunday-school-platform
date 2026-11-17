<?php
/**
 * School API v1 — Classes Routes (v4 — correct column names)
 * 
 * Classes table columns: id, class_name, class_name_en, class_code, 
 *   level_order, section, age_group, description, is_active, created_at
 * 
 * teacher_assignments columns: id, teacher_id, class_id, subject_id,
 *   academic_year_id, is_class_teacher, is_primary, is_active, status,
 *   assigned_by, assigned_at
 */

$auth = apiRequireAuth();
$id = $ROUTE['id'];
$sub = $ROUTE['sub'];

if (!apiRoleIs($auth, apiRolesClassBrowser())) {
    err('Classes are not available for this role.', 403);
}

// ============================================================
// GET /classes — List classes (role-filtered)
// ============================================================
if ($method === 'GET' && $id === null) {
    $year = getCurrentAcademicYear();
    $classes = [];
    $userId = $auth['uid'];
    $userRole = $auth['rol'];
    
    $restrictedRoles = ['teacher', 'attendance_taker'];
    $isRestricted = in_array($userRole, $restrictedRoles);
    
    try {
        $today = date('Y-m-d');
        $yearId = $year ? (int)$year['id'] : 0;
        $enrSql = class_exists('\\App\\Services\\EnrollmentService')
            ? \App\Services\EnrollmentService::rosterCountJoinSql($yearId)
            : ("SELECT class_id, COUNT(*) AS cnt FROM class_enrollments WHERE status = 'active'"
                . ($year ? " AND academic_year_id = {$yearId}" : "")
                . " GROUP BY class_id");
        
        if ($isRestricted) {
            $sql = "SELECT c.id, c.class_name, c.class_name_en, c.section,
                           c.level_order, c.age_group,
                           MAX(ta.is_class_teacher) as is_class_teacher,
                           COUNT(DISTINCT ta.subject_id) as subject_count,
                           COALESCE(enr.cnt, 0) as student_count,
                           COALESCE(att.cnt, 0) as attendance_count
                    FROM teacher_assignments ta
                    JOIN classes c ON ta.class_id = c.id
                    LEFT JOIN (
                        {$enrSql}
                    ) enr ON enr.class_id = c.id
                    LEFT JOIN (
                        SELECT class_id, COUNT(*) AS cnt
                        FROM attendance
                        WHERE attendance_date = ?
                        GROUP BY class_id
                    ) att ON att.class_id = c.id
                    WHERE ta.teacher_id = ?
                      AND ta.is_active = 1
                      AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
                    GROUP BY c.id, c.class_name, c.class_name_en, c.section,
                             c.level_order, c.age_group
                    ORDER BY c.level_order, c.class_name";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sii', $today, $userId, $yearId);
            $stmt->execute();
            $r = $stmt->get_result();
        } else {
            $sql = "SELECT c.id, c.class_name, c.class_name_en, c.section,
                           c.level_order, c.age_group,
                           0 as is_class_teacher,
                           NULL as subject_id,
                           COALESCE(enr.cnt, 0) as student_count,
                           COALESCE(att.cnt, 0) as attendance_count
                    FROM classes c
                    LEFT JOIN (
                        {$enrSql}
                    ) enr ON enr.class_id = c.id
                    LEFT JOIN (
                        SELECT class_id, COUNT(*) AS cnt
                        FROM attendance
                        WHERE attendance_date = ?
                        GROUP BY class_id
                    ) att ON att.class_id = c.id
                    WHERE c.is_active = 1
                    ORDER BY c.level_order, c.class_name";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $today);
            $stmt->execute();
            $r = $stmt->get_result();
        }
        
        while ($row = $r->fetch_assoc()) {
            $row['attendance_taken_today'] = ((int)$row['attendance_count']) > 0;
            $row['section_name'] = $row['section']; // alias for Flutter compatibility
            unset($row['attendance_count']);
            $row['id'] = (int)$row['id'];
            $row['student_count'] = (int)$row['student_count'];
            $row['is_class_teacher'] = (bool)$row['is_class_teacher'];
            $classes[] = $row;
        }
        $stmt->close();
        
    } catch (Exception $e) {
        reportInternalError('API class list failed', $e);
        err('Unable to load classes.', 500);
    }
    
    ok(['classes' => $classes, 'count' => count($classes)]);
}

// ============================================================
// GET /classes/{id} — Class details
// ============================================================
if ($method === 'GET' && $id !== null && $sub === null) {
    $id = (int)$id;
    $userId = $auth['uid'];
    $userRole = $auth['rol'];
    
    if (in_array($userRole, ['teacher', 'attendance_taker'])) {
        $yearId = 0;
        $year = getCurrentAcademicYear();
        if ($year) $yearId = (int)$year['id'];
        $check = $conn->prepare("SELECT id FROM teacher_assignments 
                                 WHERE teacher_id = ? AND class_id = ? 
                                 AND (academic_year_id IS NULL OR academic_year_id = ?)
                                 AND is_active = 1");
        $check->bind_param('iii', $userId, $id, $yearId);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            err('You are not assigned to this class', 403);
        }
        $check->close();
    }
    
    $class = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Exception $e) {}
    
    if (!$class) err('Class not found', 404);
    
    $year = getCurrentAcademicYear();
    if ($year) {
        $yearId = (int)$year['id'];
        try {
            $stmt = $conn->prepare("SELECT ta.teacher_id, u.full_name as teacher_name
                                    FROM teacher_assignments ta 
                                    JOIN users u ON ta.teacher_id = u.id
                                    WHERE ta.class_id = ? 
                                    AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
                                    AND ta.is_class_teacher = 1 
                                    AND ta.is_active = 1
                                    LIMIT 1");
            $stmt->bind_param('ii', $id, $yearId);
            $stmt->execute();
            $class['class_teacher'] = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Exception $e) {
            $class['class_teacher'] = null;
        }
    }
    
    ok($class);
}

// ============================================================
// GET /classes/{id}/students — Students in a class
// ============================================================
if ($method === 'GET' && $id !== null && $sub === 'students') {
    $id = (int)$id;
    $userId = $auth['uid'];
    $userRole = $auth['rol'];
    
    if (in_array($userRole, ['teacher', 'attendance_taker'])) {
        $yearId = 0;
        $year = getCurrentAcademicYear();
        if ($year) $yearId = (int)$year['id'];
        $check = $conn->prepare("SELECT id FROM teacher_assignments 
                                 WHERE teacher_id = ? AND class_id = ? 
                                 AND (academic_year_id IS NULL OR academic_year_id = ?)
                                 AND is_active = 1");
        $check->bind_param('iii', $userId, $id, $yearId);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            err('You are not assigned to this class', 403);
        }
        $check->close();
    }
    
    $year = getCurrentAcademicYear();
    $students = [];
    $scope = ['year_id' => $year ? (int)$year['id'] : null, 'fallback' => false, 'year_name' => $year['year_name'] ?? null];

    try {
        if (class_exists('\\App\\Services\\EnrollmentService')) {
            $scope = \App\Services\EnrollmentService::resolveRosterYear($conn, $id, $year ? (int)$year['id'] : null);
            $rows = \App\Services\EnrollmentService::fetchRoster($conn, $id, $scope['year_id'] ?? null);
            foreach ($rows as $row) {
                $row['photo_url'] = apiPhotoUrl(
                    $row['student_photo_path'] ?? null,
                    (int)($row['id'] ?? 0)
                );
                unset($row['student_photo_path']);
                $students[] = apiRosterStudentRow($row);
            }
        } else {
            if ($year) {
                $stmt = $conn->prepare("SELECT m.id, m.member_code, m.student_name, m.father_name, 
                                               m.gender, m.age_group,
                                               m.student_photo_path, m.status
                                        FROM class_enrollments ce 
                                        JOIN members m ON ce.member_id = m.id 
                                        WHERE ce.class_id = ? 
                                          AND ce.academic_year_id = ? 
                                          AND ce.status = 'active'
                                        ORDER BY m.student_name");
                $stmt->bind_param('ii', $id, $year['id']);
            } else {
                $stmt = $conn->prepare("SELECT m.id, m.member_code, m.student_name, m.father_name, 
                                               m.gender, m.age_group,
                                               m.student_photo_path, m.status
                                        FROM class_enrollments ce 
                                        JOIN members m ON ce.member_id = m.id 
                                        WHERE ce.class_id = ? AND ce.status = 'active'
                                        ORDER BY m.student_name");
                $stmt->bind_param('i', $id);
            }
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $row['photo_url'] = apiPhotoUrl(
                    $row['student_photo_path'] ?? null,
                    (int)($row['id'] ?? 0)
                );
                unset($row['student_photo_path']);
                $students[] = apiRosterStudentRow($row);
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        reportInternalError('API class roster failed', $e);
        err('Unable to load students.', 500);
    }
    
    ok([
        'class_id' => $id,
        'students' => $students,
        'count' => count($students),
        'roster_year_id' => $scope['year_id'] ?? null,
        'roster_year_name' => $scope['year_name'] ?? null,
        'roster_fallback' => !empty($scope['fallback']),
    ]);
}

err("No handler for {$method} /classes" . ($id ? "/{$id}" : '') . ($sub ? "/{$sub}" : ''), 404);
