<?php
// =========================================
// FELEGE KIDUSAN - AUTH, STAFF AND ADMIN API (Stage 1)
// =========================================
// POST /api/auth/staff-login
// GET  /api/auth/me
// POST /api/auth/logout
// POST /api/auth/change-password
// GET  /api/staff/lessons?grade=..&class=..
// POST /api/staff/lessons/{lessonId}/unlock
// POST /api/staff/lessons/{lessonId}/lock
// POST /api/admin/students
// POST /api/admin/students/{studentId}/reset-password
//
// Permission checks for WHO may call these are done in
// lib/security.php (fk_gate). Scope checks (which grade/class
// a teacher may control) are done here.
// =========================================

require_once __DIR__ . "/../lib/security.php";

function fk_read_json(): array
{
    $data = json_decode((string) file_get_contents("php://input"), true);
    if (!is_array($data)) {
        fk_send(["success" => false, "message" => "Invalid JSON request body."], 400);
    }
    return $data;
}

function fk_require_method(string $method): void
{
    if (($_SERVER["REQUEST_METHOD"] ?? "") !== $method) {
        fk_send(["success" => false, "message" => "Method not allowed."], 405);
    }
}

function fk_server_error(Throwable $e): void
{
    error_log("API error: " . $e->getMessage());
    fk_send(["success" => false, "message" => "Something went wrong. Please try again."], 500);
}

function handleStage1Api(PDO $pdo, string $uri)
{
    // ---------------------------------
    // STAFF LOGIN
    // ---------------------------------
    if ($uri === "/api/auth/staff-login") {
        fk_require_method("POST");
        $data = fk_read_json();
        $staffId = trim((string) ($data["staff_id"] ?? ""));
        $password = (string) ($data["password"] ?? "");

        if ($staffId === "" || $password === "") {
            fk_send(["success" => false, "message" => "Staff ID and password are required."], 400);
        }

        fk_throttle_check($pdo, "staff:" . $staffId);

        try {
            $stmt = $pdo->prepare("
                SELECT staff_id, name, role, password_hash, active
                FROM staff
                WHERE staff_id = ?
                LIMIT 1
            ");
            $stmt->execute([$staffId]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        if (!$staff || !password_verify($password, $staff["password_hash"])) {
            fk_throttle_fail($pdo, "staff:" . $staffId);
            fk_send(["success" => false, "message" => "Invalid staff ID or password."], 401);
        }
        if ((int) $staff["active"] !== 1) {
            fk_send(["success" => false, "message" => "This account is inactive."], 403);
        }

        fk_throttle_clear($pdo, "staff:" . $staffId);
        fk_login_session($staff["role"], $staff["staff_id"], false);

        fk_send([
            "success" => true,
            "user" => [
                "id"   => $staff["staff_id"],
                "name" => $staff["name"],
                "role" => $staff["role"]
            ]
        ]);
    }

    // ---------------------------------
    // WHO AM I
    // ---------------------------------
    if ($uri === "/api/auth/me") {
        fk_require_method("GET");
        $user = fk_current_user();

        try {
            if ($user["role"] === "student") {
                $stmt = $pdo->prepare("SELECT student_id, name, grade, `class` FROM students WHERE student_id = ? LIMIT 1");
                $stmt->execute([$user["id"]]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    fk_send(["success" => false, "code" => "AUTH_REQUIRED", "message" => "Please sign in."], 401);
                }
                fk_send([
                    "success" => true,
                    "user" => [
                        "role"               => "student",
                        "id"                 => $row["student_id"],
                        "name"               => $row["name"],
                        "grade"              => $row["grade"],
                        "class"              => $row["class"],
                        "mustChangePassword" => !empty($user["must_change"])
                    ]
                ]);
            }

            $stmt = $pdo->prepare("SELECT staff_id, name, role FROM staff WHERE staff_id = ? LIMIT 1");
            $stmt->execute([$user["id"]]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                fk_send(["success" => false, "code" => "AUTH_REQUIRED", "message" => "Please sign in."], 401);
            }
            fk_send([
                "success" => true,
                "user" => ["role" => $row["role"], "id" => $row["staff_id"], "name" => $row["name"]]
            ]);
        } catch (PDOException $e) {
            fk_server_error($e);
        }
    }

    // ---------------------------------
    // LOGOUT
    // ---------------------------------
    if ($uri === "/api/auth/logout") {
        fk_require_method("POST");
        fk_logout_session();
        fk_send(["success" => true, "message" => "Signed out."]);
    }

    // ---------------------------------
    // CHANGE PASSWORD (students)
    // ---------------------------------
    if ($uri === "/api/auth/change-password") {
        fk_require_method("POST");
        $user = fk_current_user();
        if ($user["role"] !== "student") {
            fk_send(["success" => false, "message" => "Password change is only available to students here."], 403);
        }

        $data = fk_read_json();
        $current = (string) ($data["current_password"] ?? "");
        $new = (string) ($data["new_password"] ?? "");

        if ($current === "" || $new === "") {
            fk_send(["success" => false, "message" => "Current and new passwords are required."], 400);
        }
        if (!fk_password_ok($new)) {
            fk_send(["success" => false, "message" => "The new password must be at least 8 characters."], 400);
        }
        if ($new === $current) {
            fk_send(["success" => false, "message" => "The new password must be different from the current one."], 400);
        }

        fk_throttle_check($pdo, "pw:" . $user["id"]);

        try {
            $stmt = $pdo->prepare("SELECT password_hash FROM students WHERE student_id = ? AND active = 1 LIMIT 1");
            $stmt->execute([$user["id"]]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($current, $hash)) {
                fk_throttle_fail($pdo, "pw:" . $user["id"]);
                fk_send(["success" => false, "message" => "The current password is incorrect."], 403);
            }

            $upd = $pdo->prepare("
                UPDATE students
                SET password_hash = ?, must_change_password = 0
                WHERE student_id = ?
            ");
            $upd->execute([password_hash($new, PASSWORD_DEFAULT), $user["id"]]);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        fk_throttle_clear($pdo, "pw:" . $user["id"]);
        fk_login_session("student", $user["id"], false);
        fk_send(["success" => true, "message" => "Password updated."]);
    }

    // ---------------------------------
    // STAFF: LIST LESSONS WITH UNLOCK STATUS
    // ---------------------------------
    if ($uri === "/api/staff/lessons") {
        fk_require_method("GET");
        $user = fk_current_user();
        $grade = trim((string) ($_GET["grade"] ?? ""));
        $class = trim((string) ($_GET["class"] ?? ""));

        if ($grade === "" || strlen($grade) > 20 || strlen($class) > 50) {
            fk_send(["success" => false, "message" => "A valid grade is required."], 400);
        }
        if (!fk_staff_can_manage($pdo, $user, $grade, $class)) {
            fk_send(["success" => false, "code" => "FORBIDDEN", "message" => "You do not have permission for this grade or class."], 403);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    s.id AS subject_id,
                    s.name AS subject_name,
                    c.id AS chapter_id,
                    c.chapter_number,
                    c.title AS chapter_title,
                    l.id AS lesson_id,
                    l.lesson_number,
                    l.title AS lesson_title,
                    l.status,
                    EXISTS (
                        SELECT 1 FROM lesson_unlocks lu
                        WHERE lu.lesson_id = l.id AND lu.grade = s.grade AND lu.`class` IS NULL
                    ) AS unlocked_for_grade,
                    EXISTS (
                        SELECT 1 FROM lesson_unlocks lu
                        WHERE lu.lesson_id = l.id AND lu.grade = s.grade AND lu.`class` = ?
                    ) AS unlocked_for_class
                FROM subjects s
                INNER JOIN chapters c ON c.subject_id = s.id
                INNER JOIN lessons l ON l.chapter_id = c.id
                WHERE s.grade = ?
                ORDER BY s.id ASC, c.sort_order ASC, l.sort_order ASC, l.id ASC
            ");
            $stmt->execute([$class, $grade]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        foreach ($rows as &$r) {
            $r["unlocked_for_grade"] = (bool) $r["unlocked_for_grade"];
            $r["unlocked_for_class"] = (bool) $r["unlocked_for_class"];
        }
        unset($r);

        fk_send(["success" => true, "grade" => $grade, "class" => $class, "lessons" => $rows]);
    }

    // ---------------------------------
    // STAFF: UNLOCK / LOCK ONE LESSON
    // ---------------------------------
    if (preg_match("#^/api/staff/lessons/(\d+)/(unlock|lock)$#", $uri, $m)) {
        fk_require_method("POST");
        $user = fk_current_user();
        $lessonId = (int) $m[1];
        $action = $m[2];

        $data = fk_read_json();
        $grade = trim((string) ($data["grade"] ?? ""));
        $class = trim((string) ($data["class"] ?? ""));

        if ($grade === "" || strlen($grade) > 20 || strlen($class) > 50) {
            fk_send(["success" => false, "message" => "A valid grade is required."], 400);
        }
        if (!fk_staff_can_manage($pdo, $user, $grade, $class)) {
            fk_send(["success" => false, "code" => "FORBIDDEN", "message" => "You do not have permission for this grade or class."], 403);
        }

        try {
            // The lesson must exist and belong to a subject of this grade.
            $stmt = $pdo->prepare("
                SELECT l.id, l.status
                FROM lessons l
                INNER JOIN chapters c ON c.id = l.chapter_id
                INNER JOIN subjects s ON s.id = c.subject_id
                WHERE l.id = ? AND s.grade = ?
                LIMIT 1
            ");
            $stmt->execute([$lessonId, $grade]);
            $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lesson) {
                fk_send(["success" => false, "message" => "Lesson not found for this grade."], 404);
            }

            $classValue = ($class === "") ? null : $class;

            if ($action === "unlock") {
                if ($lesson["status"] !== "published") {
                    fk_send(["success" => false, "message" => "Only published lessons can be unlocked."], 409);
                }

                $pdo->beginTransaction();
                $chk = $pdo->prepare("
                    SELECT id FROM lesson_unlocks
                    WHERE lesson_id = ? AND grade = ? AND `class` <=> ?
                    LIMIT 1
                ");
                $chk->execute([$lessonId, $grade, $classValue]);
                if (!$chk->fetchColumn()) {
                    $ins = $pdo->prepare("
                        INSERT INTO lesson_unlocks (lesson_id, grade, `class`, teacher_id)
                        VALUES (?, ?, ?, ?)
                    ");
                    $ins->execute([$lessonId, $grade, $classValue, $user["id"]]);
                }
                $pdo->commit();
            } else {
                $del = $pdo->prepare("
                    DELETE FROM lesson_unlocks
                    WHERE lesson_id = ? AND grade = ? AND `class` <=> ?
                ");
                $del->execute([$lessonId, $grade, $classValue]);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fk_server_error($e);
        }

        fk_send([
            "success"   => true,
            "lesson_id" => $lessonId,
            "grade"     => $grade,
            "class"     => $class,
            "unlocked"  => ($action === "unlock")
        ]);
    }

    // ---------------------------------
    // STAFF: UNLOCK / LOCK A WHOLE UNIT (chapter) for a grade or class
    // ---------------------------------
    if (preg_match("#^/api/staff/units/(\\d+)/(unlock|lock)$#", $uri, $m)) {
        fk_require_method("POST");
        $user = fk_current_user();
        $chapterId = (int) $m[1];
        $action = $m[2];

        $data = fk_read_json();
        $grade = trim((string) ($data["grade"] ?? ""));
        $class = trim((string) ($data["class"] ?? ""));

        if ($grade === "" || strlen($grade) > 20 || strlen($class) > 50) {
            fk_send(["success" => false, "message" => "A valid grade is required."], 400);
        }
        if (!fk_staff_can_manage($pdo, $user, $grade, $class)) {
            fk_send(["success" => false, "code" => "FORBIDDEN", "message" => "You do not have permission for this grade or class."], 403);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT c.id FROM chapters c INNER JOIN subjects s ON s.id = c.subject_id
                WHERE c.id = ? AND s.grade = ? LIMIT 1
            ");
            $stmt->execute([$chapterId, $grade]);
            if (!$stmt->fetchColumn()) {
                fk_send(["success" => false, "message" => "Unit not found for this grade."], 404);
            }

            $classValue = ($class === "") ? null : $class;
            if ($action === "unlock") {
                $pdo->beginTransaction();
                $chk = $pdo->prepare("SELECT id FROM unit_unlocks WHERE chapter_id = ? AND grade = ? AND `class` <=> ? LIMIT 1");
                $chk->execute([$chapterId, $grade, $classValue]);
                if (!$chk->fetchColumn()) {
                    $ins = $pdo->prepare("INSERT INTO unit_unlocks (chapter_id, grade, `class`, teacher_id) VALUES (?, ?, ?, ?)");
                    $ins->execute([$chapterId, $grade, $classValue, $user["id"]]);
                }
                $pdo->commit();
            } else {
                $del = $pdo->prepare("DELETE FROM unit_unlocks WHERE chapter_id = ? AND grade = ? AND `class` <=> ?");
                $del->execute([$chapterId, $grade, $classValue]);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fk_server_error($e);
        }

        fk_send(["success" => true, "chapter_id" => $chapterId, "grade" => $grade, "class" => $class, "unlocked" => ($action === "unlock")]);
    }

    // ---------------------------------
    // ADMIN: PROVISION A STUDENT
    // ---------------------------------
    if ($uri === "/api/admin/students") {
        fk_require_method("POST");
        $data = fk_read_json();

        $studentId = trim((string) ($data["student_id"] ?? ""));
        $name = trim((string) ($data["name"] ?? ""));
        $grade = trim((string) ($data["grade"] ?? ""));
        $class = trim((string) ($data["class"] ?? ""));
        $temp = (string) ($data["temporary_password"] ?? "");

        if (!preg_match("/^[A-Za-z0-9_-]{3,50}$/", $studentId)) {
            fk_send(["success" => false, "message" => "Student ID may use letters, numbers, - and _ (3-50 characters)."], 400);
        }
        if ($name === "" || mb_strlen($name, "UTF-8") > 150) {
            fk_send(["success" => false, "message" => "A name (up to 150 characters) is required."], 400);
        }
        if ($grade === "" || strlen($grade) > 20 || strlen($class) > 50) {
            fk_send(["success" => false, "message" => "A valid grade (and optional class) is required."], 400);
        }
        if (!fk_password_ok($temp)) {
            fk_send(["success" => false, "message" => "The temporary password must be at least 8 characters."], 400);
        }

        try {
            $exists = $pdo->prepare("SELECT 1 FROM students WHERE student_id = ? LIMIT 1");
            $exists->execute([$studentId]);
            if ($exists->fetchColumn()) {
                fk_send(["success" => false, "message" => "That student ID already exists."], 409);
            }

            $ins = $pdo->prepare("
                INSERT INTO students (student_id, name, grade, `class`, password_hash, must_change_password, active)
                VALUES (?, ?, ?, ?, ?, 1, 1)
            ");
            $ins->execute([
                $studentId,
                $name,
                $grade,
                ($class === "") ? null : $class,
                password_hash($temp, PASSWORD_DEFAULT)
            ]);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        fk_send(["success" => true, "message" => "Student created. They must choose a new password at first login."], 201);
    }

    // ---------------------------------
    // ADMIN: RESET A STUDENT PASSWORD
    // ---------------------------------
    if (preg_match("#^/api/admin/students/([^/]+)/reset-password$#", $uri, $m)) {
        fk_require_method("POST");
        $studentId = urldecode($m[1]);
        $data = fk_read_json();
        $temp = (string) ($data["temporary_password"] ?? "");

        if (!fk_password_ok($temp)) {
            fk_send(["success" => false, "message" => "The temporary password must be at least 8 characters."], 400);
        }

        try {
            $upd = $pdo->prepare("
                UPDATE students
                SET password_hash = ?, must_change_password = 1
                WHERE student_id = ?
            ");
            $upd->execute([password_hash($temp, PASSWORD_DEFAULT), $studentId]);

            // rowCount can be 0 if nothing changed; confirm existence separately.
            $exists = $pdo->prepare("SELECT 1 FROM students WHERE student_id = ? LIMIT 1");
            $exists->execute([$studentId]);
            if (!$exists->fetchColumn()) {
                fk_send(["success" => false, "message" => "Student not found."], 404);
            }
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        fk_send(["success" => true, "message" => "Password reset. The student must choose a new password at next login."]);
    }

    return false;
}
