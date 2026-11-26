<?php
// =========================================
// FELEGE KIDUSAN - LEARNING API (Stage 3)
// =========================================
// GET  /api/learning/path
// GET  /api/learning/lessons/{id}
// POST /api/learning/lessons/{id}/complete
// GET  /api/learning/materials/{id}/download
// GET  /api/progress
// GET  /api/progress/weak-topics
// GET  /api/progress/attempts/{attemptId}
// GET  /api/verse/today
// POST /api/assistant/ask
//
// All of these are for signed-in STUDENTS. The student is always
// taken from the server session, never from the request.
//
// Lesson access rule (unchanged from Stage 1): a published lesson is
// available to a student only after a teacher has unlocked it for the
// student's grade (or class). Completing a lesson never unlocks another.
// =========================================

require_once __DIR__ . "/auth_staff.php";

function fk_learning_student(PDO $pdo): array
{
    $user = fk_current_user();
    if (!$user || $user["role"] !== "student") {
        fk_send(["success" => false, "code" => "FORBIDDEN", "message" => "This area is for students."], 403);
    }

    $stmt = $pdo->prepare("SELECT student_id, name, grade, `class` FROM students WHERE student_id = ? AND active = 1 LIMIT 1");
    $stmt->execute([$user["id"]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fk_send(["success" => false, "code" => "AUTH_REQUIRED", "message" => "Please sign in."], 401);
    }
    return $row;
}

// Every published lesson for the student's grade, in curriculum order, with real state.
//
// UNLOCK RULES (all decided on the server):
//  * Unit 1 of a subject is open from the start.
//  * Unit N+1 opens only when BOTH are true: the student finished the unit-N quiz
//    (a completed, passed 'unit' quiz attempt; units with no quiz questions count as done)
//    AND a teacher/admin unlocked unit N+1 (table unit_unlocks).
//  * Inside an open unit the first 2 lessons are open by default; every later lesson
//    needs a teacher/admin unlock (table lesson_unlocks).
//  * Finishing a lesson never unlocks anything by itself.
function fk_learning_rows(PDO $pdo, array $student): array
{
    $stmt = $pdo->prepare("
        SELECT
            s.id AS subject_id, s.name AS subject_name,
            c.id AS chapter_id, c.chapter_number, c.title AS chapter_title,
            l.id AS lesson_id, l.lesson_number, l.title AS lesson_title, l.description AS lesson_description,
            EXISTS (
                SELECT 1 FROM lesson_unlocks lu
                WHERE lu.lesson_id = l.id AND lu.grade = s.grade
                  AND (lu.`class` IS NULL OR lu.`class` = ?)
            ) AS teacher_unlocked,
            EXISTS (
                SELECT 1 FROM unit_unlocks uu
                WHERE uu.chapter_id = c.id AND uu.grade = s.grade
                  AND (uu.`class` IS NULL OR uu.`class` = ?)
            ) AS unit_teacher_unlocked,
            EXISTS (
                SELECT 1 FROM quiz_attempts qa
                WHERE qa.student_id = ? AND qa.chapter_id = c.id AND qa.scope = 'unit'
                  AND qa.completed_at IS NOT NULL AND qa.passed = 1
            ) AS unit_quiz_passed,
            (
                SELECT COUNT(*) FROM questions q
                LEFT JOIN lessons ql ON ql.id = q.lesson_id
                WHERE q.active = 1 AND (q.chapter_id = c.id OR ql.chapter_id = c.id)
            ) AS unit_question_count,
            EXISTS (
                SELECT 1 FROM lesson_progress lp
                WHERE lp.lesson_id = l.id AND lp.student_id = ?
            ) AS completed
        FROM subjects s
        INNER JOIN chapters c ON c.subject_id = s.id
        INNER JOIN lessons l ON l.chapter_id = c.id
        WHERE s.grade = ? AND l.status = 'published'
        ORDER BY s.id ASC, c.sort_order ASC, c.id ASC, l.sort_order ASC, l.id ASC
    ");
    $stmt->execute([$student["class"], $student["class"], $student["student_id"], $student["student_id"], $student["grade"]]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group lessons by subject -> unit so the unit rules can look at the previous unit.
    $units = [];       // [subject_id][chapter_id] => info
    $order = [];       // [subject_id] => list of chapter ids in order
    foreach ($rows as $r) {
        $sid = (int) $r["subject_id"];
        $cid = (int) $r["chapter_id"];
        if (!isset($units[$sid][$cid])) {
            $units[$sid][$cid] = [
                "teacher_unlocked" => (int) $r["unit_teacher_unlocked"] === 1,
                "quiz_required"    => (int) $r["unit_question_count"] > 0,
                "quiz_passed"      => (int) $r["unit_quiz_passed"] === 1,
                "lessons_total"    => 0,
                "lessons_done"     => 0
            ];
            $order[$sid][] = $cid;
        }
        $units[$sid][$cid]["lessons_total"]++;
        if ((int) $r["completed"] === 1) {
            $units[$sid][$cid]["lessons_done"]++;
        }
    }

    foreach ($units as $sid => &$chapters) {
        $prev = null;
        foreach ($order[$sid] as $cid) {
            $u = &$chapters[$cid];
            $u["quiz_ok"] = !$u["quiz_required"] || ($u["quiz_passed"] && $u["lessons_done"] === $u["lessons_total"]);
            if ($prev === null) {
                $u["open"] = true;
                $u["waiting_for"] = null;
            } else {
                $prevDone = $chapters[$prev]["quiz_ok"];
                $u["open"] = $prevDone && $u["teacher_unlocked"];
                $u["waiting_for"] = $u["open"] ? null : ($prevDone ? "teacher" : "previous_quiz");
            }
            $u["quiz_available"] = $u["open"] && $u["quiz_required"]
                && $u["lessons_total"] > 0 && $u["lessons_done"] === $u["lessons_total"];
            $prev = $cid;
            unset($u);
        }
    }
    unset($chapters);

    $rank = [];
    foreach ($rows as &$r) {
        $sid = (int) $r["subject_id"];
        $cid = (int) $r["chapter_id"];
        $rank[$cid] = ($rank[$cid] ?? -1) + 1;
        $u = $units[$sid][$cid];
        $r["rank"] = $rank[$cid];
        $r["unit"] = $u;
        $r["unlocked"] = ($u["open"] && ($rank[$cid] < 2 || (int) $r["teacher_unlocked"] === 1)) ? 1 : 0;
    }
    unset($r);

    return $rows;
}

// IDs of lessons the student may open (available or completed). Used by the quiz and assistant.
function fk_open_lesson_ids(PDO $pdo, array $student): array
{
    $ids = [];
    foreach (fk_learning_rows($pdo, $student) as $r) {
        if (fk_learning_state($r) !== "locked") {
            $ids[] = (int) $r["lesson_id"];
        }
    }
    return $ids;
}

function fk_learning_state(array $r): string
{
    if ((int) $r["completed"] === 1) {
        return "completed";
    }
    return ((int) $r["unlocked"] === 1) ? "available" : "locked";
}

// One lesson the student may look at (404 if not theirs/published). Adds unlocked/completed flags.
function fk_lesson_for_student(PDO $pdo, array $student, int $lessonId): array
{
    foreach (fk_learning_rows($pdo, $student) as $r) {
        if ((int) $r["lesson_id"] === $lessonId) {
            $r["state"] = fk_learning_state($r);
            return $r;
        }
    }
    fk_send(["success" => false, "message" => "Lesson not found."], 404);
}

function fk_require_lesson_open(array $lesson): void
{
    if ($lesson["state"] === "locked") {
        fk_send([
            "success" => false,
            "code"    => "LESSON_LOCKED",
            "message" => "This lesson is waiting for your teacher to unlock it."
        ], 403);
    }
}

function fk_next_available(array $rows, int $afterLessonId = 0): ?array
{
    $seen = ($afterLessonId === 0);
    foreach ($rows as $r) {
        if (!$seen) {
            if ((int) $r["lesson_id"] === $afterLessonId) {
                $seen = true;
            }
            continue;
        }
        if (fk_learning_state($r) === "available") {
            return ["lesson_id" => (int) $r["lesson_id"], "title" => $r["lesson_title"], "lesson_number" => $r["lesson_number"]];
        }
    }
    return null;
}

function fk_learning_totals(array $rows): array
{
    $t = ["total" => count($rows), "completed" => 0, "available" => 0, "locked" => 0];
    foreach ($rows as $r) {
        $t[fk_learning_state($r)]++;
    }
    return $t;
}

// Which of the last 7 Addis Ababa days had real activity? Returns [{date, active}] oldest first.
function fk_week_dots(array $timestamps): array
{
    $tz = new DateTimeZone("Africa/Addis_Ababa");
    $activeDays = [];
    foreach ($timestamps as $ts) {
        if ($ts) {
            $activeDays[(new DateTimeImmutable((string) $ts, new DateTimeZone("UTC")))->setTimezone($tz)->format("Y-m-d")] = true;
        }
    }
    $out = [];
    $today = new DateTimeImmutable("now", $tz);
    for ($i = 6; $i >= 0; $i--) {
        $d = $today->modify("-$i day")->format("Y-m-d");
        $out[] = ["date" => $d, "active" => isset($activeDays[$d])];
    }
    return $out;
}

function fk_pct(int $part, int $whole): ?int
{
    return $whole > 0 ? (int) round($part * 100 / $whole) : null;
}

function handleLearningApi(PDO $pdo, string $uri)
{
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";

    // ---------------------------------
    // LEARNING PATH
    // ---------------------------------
    if ($uri === "/api/learning/path") {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);

        try {
            $rows = fk_learning_rows($pdo, $student);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        $subjects = [];
        $currentId = null;

        foreach ($rows as $r) {
            $state = fk_learning_state($r);
            $sid = (int) $r["subject_id"];
            $cid = (int) $r["chapter_id"];

            if (!isset($subjects[$sid])) {
                $subjects[$sid] = ["subject_id" => $sid, "name" => $r["subject_name"], "units" => [], "total" => 0, "completed" => 0];
            }
            if (!isset($subjects[$sid]["units"][$cid])) {
                $u = $r["unit"];
                $subjects[$sid]["units"][$cid] = [
                    "chapter_id" => $cid, "number" => $r["chapter_number"], "title" => $r["chapter_title"],
                    "open" => $u["open"], "waiting_for" => $u["waiting_for"],
                    "quiz" => [
                        "required"  => $u["quiz_required"],
                        "passed"    => $u["quiz_passed"],
                        "available" => $u["quiz_available"]
                    ],
                    "lessons" => [], "total" => 0, "completed" => 0
                ];
            }

            if ($currentId === null && $state === "available") {
                $currentId = (int) $r["lesson_id"];
            }

            $subjects[$sid]["total"]++;
            $subjects[$sid]["units"][$cid]["total"]++;
            if ($state === "completed") {
                $subjects[$sid]["completed"]++;
                $subjects[$sid]["units"][$cid]["completed"]++;
            }

            $subjects[$sid]["units"][$cid]["lessons"][] = [
                "lesson_id"     => (int) $r["lesson_id"],
                "lesson_number" => $r["lesson_number"],
                "title"         => $r["lesson_title"],
                "state"         => $state
            ];
        }

        $out = [];
        foreach ($subjects as $s) {
            $s["units"] = array_values($s["units"]);
            $s["percent"] = fk_pct($s["completed"], $s["total"]);
            foreach ($s["units"] as &$u) {
                $u["percent"] = fk_pct($u["completed"], $u["total"]);
            }
            unset($u);
            $out[] = $s;
        }

        $current = null;
        if ($currentId !== null) {
            foreach ($rows as $r) {
                if ((int) $r["lesson_id"] === $currentId) {
                    $current = ["lesson_id" => $currentId, "title" => $r["lesson_title"], "lesson_number" => $r["lesson_number"], "subject" => $r["subject_name"]];
                    break;
                }
            }
        }

        $totals = fk_learning_totals($rows);
        fk_send([
            "success"        => true,
            "student"        => ["name" => $student["name"], "grade" => $student["grade"], "class" => $student["class"]],
            "totals"         => $totals,
            "percent"        => fk_pct($totals["completed"], $totals["total"]),
            "current_lesson" => $current,
            "subjects"       => $out
        ]);
    }

    // ---------------------------------
    if (preg_match("#^/api/learning/lessons/(\d+)$#", $uri, $m)) {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);
        $lessonId = (int) $m[1];

        try {
            $lesson = fk_lesson_for_student($pdo, $student, $lessonId);
            fk_require_lesson_open($lesson);

            $mat = $pdo->prepare("SELECT id, file_name, file_type, file_size FROM lesson_materials WHERE lesson_id = ? ORDER BY id ASC");
            $mat->execute([$lessonId]);
            $materials = $mat->fetchAll(PDO::FETCH_ASSOC);

            $qc = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE lesson_id = ? AND active = 1");
            $qc->execute([$lessonId]);
            $questionCount = (int) $qc->fetchColumn();

            $passed = false;
            if ($questionCount > 0) {
                $pq = $pdo->prepare("
                    SELECT 1 FROM quiz_attempts
                    WHERE student_id = ? AND lesson_id = ? AND passed = 1 AND completed_at IS NOT NULL
                    LIMIT 1
                ");
                $pq->execute([$student["student_id"], $lessonId]);
                $passed = (bool) $pq->fetchColumn();
            }

            $info = $pdo->prepare("
                SELECT s.id AS subject_id, s.name AS subject_name, c.id AS chapter_id, c.chapter_number, c.title AS chapter_title
                FROM lessons l
                INNER JOIN chapters c ON c.id = l.chapter_id
                INNER JOIN subjects s ON s.id = c.subject_id
                WHERE l.id = ? LIMIT 1
            ");
            $info->execute([$lessonId]);
            $ctx = $info->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        fk_send([
            "success" => true,
            "lesson" => [
                "lesson_id"     => $lessonId,
                "lesson_number" => $lesson["lesson_number"],
                "title"         => $lesson["lesson_title"],
                "description"   => $lesson["lesson_description"],
                "state"         => $lesson["state"],
                "subject"       => ["id" => (int) $ctx["subject_id"], "name" => $ctx["subject_name"]],
                "unit"          => ["id" => (int) $ctx["chapter_id"], "number" => $ctx["chapter_number"], "title" => $ctx["chapter_title"]],
                "materials"     => $materials,
                "question_count" => $questionCount,
                // Completion rule: lessons with questions need a passed lesson quiz; others need the student's explicit confirmation.
                "completion_rule" => "confirm",
                "quiz_passed"   => $passed,
                "can_complete"  => ($lesson["state"] === "available")
            ]
        ]);
    }

    // ---------------------------------
    // COMPLETE A LESSON
    // ---------------------------------
    if (preg_match("#^/api/learning/lessons/(\d+)/complete$#", $uri, $m)) {
        fk_require_method("POST");
        $student = fk_learning_student($pdo);
        $lessonId = (int) $m[1];

        try {
            $lesson = fk_lesson_for_student($pdo, $student, $lessonId);
            fk_require_lesson_open($lesson);

            if ($lesson["state"] !== "completed") {
                $ins = $pdo->prepare("INSERT IGNORE INTO lesson_progress (student_id, lesson_id) VALUES (?, ?)");
                $ins->execute([$student["student_id"], $lessonId]);
            }

            $rows = fk_learning_rows($pdo, $student);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        $totals = fk_learning_totals($rows);
        fk_send([
            "success"       => true,
            "lesson_id"     => $lessonId,
            "state"         => "completed",
            "totals"        => $totals,
            "percent"       => fk_pct($totals["completed"], $totals["total"]),
            // The next lesson is only offered if a teacher has ALREADY unlocked it.
            "next_lesson"   => fk_next_available($rows, $lessonId)
        ]);
    }

    // ---------------------------------
    // LESSON MATERIAL DOWNLOAD (only for open lessons)
    // ---------------------------------
    if (preg_match("#^/api/learning/materials/(\d+)/download$#", $uri, $m)) {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);

        try {
            $stmt = $pdo->prepare("SELECT id, lesson_id, file_name, file_path FROM lesson_materials WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $m[1]]);
            $mat = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mat) {
                fk_send(["success" => false, "message" => "File not found."], 404);
            }
            $lesson = fk_lesson_for_student($pdo, $student, (int) $mat["lesson_id"]);
            fk_require_lesson_open($lesson);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        $base = realpath(__DIR__ . "/../../uploads");
        $rel = ltrim(preg_replace("#^/?uploads/#", "", str_replace("\\", "/", (string) $mat["file_path"])), "/");
        $full = $base ? realpath($base . DIRECTORY_SEPARATOR . $rel) : false;

        if (!$full || strncmp($full, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0 || !is_file($full)) {
            fk_send(["success" => false, "message" => "File not found."], 404);
        }

        $types = [
            "pdf" => "application/pdf", "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png",
            "webp" => "image/webp", "doc" => "application/msword",
            "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        ];
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));

        session_write_close();
        header("Content-Type: " . ($types[$ext] ?? "application/octet-stream"));
        header("X-Content-Type-Options: nosniff");
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string) $mat["file_name"]));
        header("Content-Length: " . filesize($full));
        readfile($full);
        exit;
    }

    // ---------------------------------
    // PROGRESS SUMMARY (all numbers from stored records)
    // ---------------------------------
    if ($uri === "/api/progress") {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);

        try {
            $rows = fk_learning_rows($pdo, $student);
            $totals = fk_learning_totals($rows);

            $bySubject = [];
            foreach ($rows as $r) {
                $sid = (int) $r["subject_id"];
                if (!isset($bySubject[$sid])) {
                    $bySubject[$sid] = ["subject_id" => $sid, "name" => $r["subject_name"], "total" => 0, "completed" => 0];
                }
                $bySubject[$sid]["total"]++;
                if (fk_learning_state($r) === "completed") {
                    $bySubject[$sid]["completed"]++;
                }
            }
            $subjects = [];
            foreach ($bySubject as $s) {
                $s["percent"] = fk_pct($s["completed"], $s["total"]);
                $subjects[] = $s;
            }

            $agg = $pdo->prepare("
                SELECT
                    COUNT(*) AS started,
                    SUM(completed_at IS NOT NULL) AS completed,
                    SUM(completed_at IS NOT NULL AND passed = 1) AS passed,
                    AVG(CASE WHEN completed_at IS NOT NULL THEN score END) AS avg_score
                FROM quiz_attempts WHERE student_id = ?
            ");
            $agg->execute([$student["student_id"]]);
            $a = $agg->fetch(PDO::FETCH_ASSOC);

            $att = $pdo->prepare("
                SELECT id, scope, lesson_id, chapter_id, mode, question_count, score, passed, completed_at, created_at
                FROM quiz_attempts
                WHERE student_id = ? AND completed_at IS NOT NULL
                ORDER BY completed_at DESC, id DESC
                LIMIT 10
            ");
            $att->execute([$student["student_id"]]);
            $attempts = $att->fetchAll(PDO::FETCH_ASSOC);

            $lp = $pdo->prepare("
                SELECT lp.lesson_id, l.title, lp.completed_at
                FROM lesson_progress lp
                INNER JOIN lessons l ON l.id = lp.lesson_id
                WHERE lp.student_id = ?
                ORDER BY lp.completed_at DESC, lp.id DESC
                LIMIT 10
            ");
            $lp->execute([$student["student_id"]]);
            $completions = $lp->fetchAll(PDO::FETCH_ASSOC);

            $firstLesson = $pdo->prepare("SELECT MIN(completed_at) FROM lesson_progress WHERE student_id = ?");
            $firstLesson->execute([$student["student_id"]]);
            $firstLessonAt = $firstLesson->fetchColumn() ?: null;

            $firstQuiz = $pdo->prepare("SELECT MIN(completed_at) FROM quiz_attempts WHERE student_id = ? AND completed_at IS NOT NULL");
            $firstQuiz->execute([$student["student_id"]]);
            $firstQuizAt = $firstQuiz->fetchColumn() ?: null;

            $firstPass = $pdo->prepare("SELECT MIN(completed_at) FROM quiz_attempts WHERE student_id = ? AND completed_at IS NOT NULL AND passed = 1");
            $firstPass->execute([$student["student_id"]]);
            $firstPassAt = $firstPass->fetchColumn() ?: null;

            // Last 7 days of real activity (Africa/Addis_Ababa days; DB times are read as UTC)
            $sinceUtc = gmdate("Y-m-d H:i:s", time() - 8 * 86400);
            $wk = $pdo->prepare("
                SELECT completed_at FROM lesson_progress WHERE student_id = ? AND completed_at >= ?
                UNION ALL
                SELECT completed_at FROM quiz_attempts WHERE student_id = ? AND completed_at IS NOT NULL AND completed_at >= ?
            ");
            $wk->execute([$student["student_id"], $sinceUtc, $student["student_id"], $sinceUtc]);
            $weekRows = $wk->fetchAll(PDO::FETCH_COLUMN);

            $sh = $pdo->prepare("
                SELECT score, passed, completed_at FROM quiz_attempts
                WHERE student_id = ? AND completed_at IS NOT NULL AND score IS NOT NULL
                ORDER BY completed_at DESC, id DESC LIMIT 12
            ");
            $sh->execute([$student["student_id"]]);
            $scoreHistory = array_reverse($sh->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        $activity = [];
        foreach ($completions as $c) {
            $activity[] = ["type" => "lesson_completed", "title" => $c["title"], "lesson_id" => (int) $c["lesson_id"], "at" => $c["completed_at"]];
        }
        foreach ($attempts as $q) {
            $activity[] = ["type" => "quiz_completed", "attempt_id" => (int) $q["id"], "score" => $q["score"] !== null ? (float) $q["score"] : null, "passed" => (int) $q["passed"] === 1, "at" => $q["completed_at"]];
        }
        usort($activity, function ($x, $y) {
            return strcmp((string) $y["at"], (string) $x["at"]);
        });
        $activity = array_slice($activity, 0, 10);

        // Milestones are DERIVED from real records each time; nothing is stored or invented.
        $subjectDone = false;
        foreach ($subjects as $s) {
            if ($s["total"] > 0 && $s["completed"] === $s["total"]) {
                $subjectDone = true;
            }
        }
        $milestones = [
            ["key" => "first_lesson", "earned" => $firstLessonAt !== null, "at" => $firstLessonAt],
            ["key" => "five_lessons", "earned" => $totals["completed"] >= 5, "at" => null],
            ["key" => "first_quiz", "earned" => $firstQuizAt !== null, "at" => $firstQuizAt],
            ["key" => "first_pass", "earned" => $firstPassAt !== null, "at" => $firstPassAt],
            ["key" => "subject_done", "earned" => $subjectDone, "at" => null]
        ];

        $completedAttempts = (int) $a["completed"];
        fk_send([
            "success" => true,
            "lessons" => $totals + ["percent" => fk_pct($totals["completed"], $totals["total"])],
            "subjects" => $subjects,
            "quizzes" => [
                "started"       => (int) $a["started"],
                "completed"     => $completedAttempts,
                "passed"        => (int) $a["passed"],
                // null (not 0) when the student has no completed quiz yet
                "average_score" => $completedAttempts > 0 && $a["avg_score"] !== null ? round((float) $a["avg_score"], 1) : null,
                "recent"        => $attempts
            ],
            "activity"   => $activity,
            "milestones" => $milestones,
            "next_lesson" => fk_next_available($rows),
            "week"       => fk_week_dots($weekRows),
            "score_history" => array_map(function ($x) {
                return ["score" => (float) $x["score"], "passed" => (int) $x["passed"] === 1, "at" => $x["completed_at"]];
            }, $scoreHistory)
        ]);
    }

    // ---------------------------------
    // WEAK TOPICS
    // Rule: a lesson is "needs revision" only when the student has answered
    // at least 3 of its questions in completed quizzes AND got at least half wrong.
    // ---------------------------------
    if ($uri === "/api/progress/weak-topics") {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);

        try {
            $stmt = $pdo->prepare("
                SELECT q.lesson_id, l.title AS lesson_title, l.lesson_number,
                       COUNT(*) AS answered, SUM(qa.is_correct = 0) AS wrong
                FROM quiz_answers qa
                INNER JOIN quiz_attempts att ON att.id = qa.attempt_id AND att.student_id = ? AND att.completed_at IS NOT NULL
                INNER JOIN questions q ON q.id = qa.question_id
                INNER JOIN lessons l ON l.id = q.lesson_id
                INNER JOIN chapters c ON c.id = l.chapter_id
                INNER JOIN subjects s ON s.id = c.subject_id
                WHERE s.grade = ? AND l.status = 'published'
                GROUP BY q.lesson_id, l.title, l.lesson_number
                HAVING answered >= 3 AND (wrong / answered) >= 0.5
                ORDER BY (wrong / answered) DESC, answered DESC
                LIMIT 10
            ");
            $stmt->execute([$student["student_id"], $student["grade"]]);
            $weak = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = fk_learning_rows($pdo, $student);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        $open = [];
        foreach ($rows as $r) {
            $open[(int) $r["lesson_id"]] = fk_learning_state($r) !== "locked";
        }
        $topics = [];
        foreach ($weak as $w) {
            $lid = (int) $w["lesson_id"];
            $topics[] = [
                "lesson_id" => $lid, "lesson_number" => $w["lesson_number"], "title" => $w["lesson_title"],
                "answered" => (int) $w["answered"], "wrong" => (int) $w["wrong"],
                "open" => $open[$lid] ?? false
            ];
        }
        fk_send(["success" => true, "rule" => "at_least_3_answers_and_half_wrong", "topics" => $topics]);
    }

    // ---------------------------------
    // REVIEW ONE COMPLETED ATTEMPT (own attempts only)
    // ---------------------------------
    if (preg_match("#^/api/progress/attempts/(\d+)$#", $uri, $m)) {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);

        try {
            $a = $pdo->prepare("SELECT id, scope, mode, question_count, score, passed, completed_at FROM quiz_attempts WHERE id = ? AND student_id = ? LIMIT 1");
            $a->execute([(int) $m[1], $student["student_id"]]);
            $attempt = $a->fetch(PDO::FETCH_ASSOC);
            if (!$attempt) {
                fk_send(["success" => false, "message" => "Attempt not found."], 404);
            }
            if ($attempt["completed_at"] === null) {
                // Answers are only revealed after the attempt is submitted.
                fk_send(["success" => false, "code" => "ATTEMPT_OPEN", "message" => "Finish this quiz before reviewing it."], 409);
            }

            $q = $pdo->prepare("
                SELECT aq.question_id, aq.question_order, q.question_text, q.explanation,
                       qa.selected_option_id, qa.is_correct
                FROM quiz_attempt_questions aq
                INNER JOIN questions q ON q.id = aq.question_id
                LEFT JOIN quiz_answers qa ON qa.attempt_id = aq.attempt_id AND qa.question_id = aq.question_id
                WHERE aq.attempt_id = ?
                ORDER BY aq.question_order ASC
            ");
            $q->execute([(int) $attempt["id"]]);
            $questions = $q->fetchAll(PDO::FETCH_ASSOC);

            $options = [];
            if ($questions) {
                $ids = array_map(function ($r) { return (int) $r["question_id"]; }, $questions);
                $ph = implode(",", array_fill(0, count($ids), "?"));
                $o = $pdo->prepare("SELECT id, question_id, option_label, option_text, is_correct FROM question_options WHERE question_id IN ($ph) ORDER BY question_id, option_label");
                $o->execute($ids);
                foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $opt) {
                    $options[(int) $opt["question_id"]][] = [
                        "id" => (int) $opt["id"], "label" => $opt["option_label"], "text" => $opt["option_text"],
                        "is_correct" => (int) $opt["is_correct"] === 1
                    ];
                }
            }
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        $review = [];
        foreach ($questions as $r) {
            $review[] = [
                "question_id" => (int) $r["question_id"],
                "text" => $r["question_text"],
                "explanation" => $r["explanation"],
                "answered" => $r["selected_option_id"] !== null,
                "selected_option_id" => $r["selected_option_id"] !== null ? (int) $r["selected_option_id"] : null,
                "is_correct" => (int) $r["is_correct"] === 1,
                "options" => $options[(int) $r["question_id"]] ?? []
            ];
        }
        fk_send([
            "success" => true,
            "attempt" => [
                "id" => (int) $attempt["id"], "scope" => $attempt["scope"], "mode" => $attempt["mode"],
                "score" => $attempt["score"] !== null ? (float) $attempt["score"] : null,
                "passed" => (int) $attempt["passed"] === 1, "completed_at" => $attempt["completed_at"]
            ],
            "questions" => $review
        ]);
    }

    // ---------------------------------
    // VERSE OF THE DAY (same verse for everyone for the whole Addis Ababa calendar day)
    // ---------------------------------
    if ($uri === "/api/verse/today") {
        fk_require_method("GET");
        fk_learning_student($pdo);

        $file = __DIR__ . "/../data/verses.json";
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $verses = [];
        if (is_array($data) && !empty($data["verses"])) {
            foreach ($data["verses"] as $cand) {
                if (!empty($cand["text_am"]) && !empty($cand["reference"])) {   // only verses that have approved Amharic text
                    $verses[] = $cand;
                }
            }
        }

        $tz = new DateTimeZone("Africa/Addis_Ababa");
        $localDate = (new DateTimeImmutable("now", $tz))->format("Y-m-d");

        if (!$verses) {
            fk_send(["success" => true, "date" => $localDate, "verse" => null, "message" => "No verified verses have been added yet."]);
        }

        // Days since 1970-01-01 of the LOCAL calendar date -> stable all day, changes at local midnight.
        $dayNumber = intdiv((new DateTimeImmutable($localDate . " 00:00:00", new DateTimeZone("UTC")))->getTimestamp(), 86400);
        $v = $verses[$dayNumber % count($verses)];

        fk_send([
            "success" => true,
            "date"    => $localDate,
            "verse"   => [
                "reference"   => $v["reference"],
                "text_am"     => $v["text_am"],
                "source"      => $v["source"] ?? null
            ]
        ]);
    }

    // ---------------------------------
    // ASSISTANT (curriculum search; no external AI provider is connected yet)
    // ---------------------------------
    if ($uri === "/api/assistant/ask") {
        fk_require_method("POST");
        $student = fk_learning_student($pdo);
        $data = fk_read_json();

        $mode = (string) ($data["mode"] ?? "ask");
        if (!in_array($mode, ["ask", "explain", "revise", "practice"], true)) {
            $mode = "ask";
        }
        $text = trim((string) ($data["question"] ?? ""));
        $lessonId = (int) ($data["lesson_id"] ?? 0);
        $lang = (($data["lang"] ?? "am") === "en") ? "en" : "am";

        if (mb_strlen($text, "UTF-8") > 500) {
            fk_send(["success" => false, "message" => "Please keep your question under 500 characters."], 400);
        }
        if ($mode === "ask" && $text === "") {
            fk_send(["success" => false, "message" => "Please type a question."], 400);
        }

        $T = [
            "am" => [
                "none"   => "በተከፈቱ ትምህርቶችዎ ውስጥ ለዚህ ጥያቄ የሚሆን ይዘት አላገኘሁም። መምህርዎን ይጠይቁ።",
                "found"  => "በተከፈቱ ትምህርቶችዎ ውስጥ ያገኘሁት፦",
                "notopen"=> "ይህ ትምህርት ገና በመምህር አልተከፈተም።",
                "pickless"=> "ለዚህ ትምህርት የተመዘገበ ማብራሪያ ወይም ጥያቄ የለም።"
            ],
            "en" => [
                "none"   => "I could not find content that matches this in the lessons your teacher has opened for you. Please ask your teacher.",
                "found"  => "Here is what I found in your opened lessons:",
                "notopen"=> "This lesson has not been opened by your teacher yet.",
                "pickless"=> "No saved explanations or questions exist for this lesson yet."
            ]
        ][$lang];

        try {
            $open = [];
            foreach (fk_learning_rows($pdo, $student) as $r) {
                if (fk_learning_state($r) !== "locked") {
                    $open[(int) $r["lesson_id"]] = $r;
                }
            }

            $focus = null;
            if ($lessonId > 0) {
                if (!isset($open[$lessonId])) {
                    fk_send(["success" => false, "code" => "LESSON_LOCKED", "message" => $T["notopen"]], 403);
                }
                $focus = $open[$lessonId];
            }

            $scope = $focus ? [(int) $focus["lesson_id"] => $focus] : $open;
            $sources = [];
            $answer = null;

            if ($scope) {
                $ids = array_keys($scope);
                $ph = implode(",", array_fill(0, count($ids), "?"));
                $qs = $pdo->prepare("SELECT lesson_id, question_text, explanation FROM questions WHERE active = 1 AND lesson_id IN ($ph) ORDER BY id ASC");
                $qs->execute($ids);
                $qrows = $qs->fetchAll(PDO::FETCH_ASSOC);

                if ($mode === "ask") {
                    $stop = ["the", "and", "what", "how", "who", "why", "when", "where", "is", "are", "was", "about", "tell", "me", "explain", "please", "can", "you", "does", "mean", "means"];
                    $tokens = [];
                    foreach (preg_split("/[^\p{L}\p{N}]+/u", mb_strtolower($text, "UTF-8"), -1, PREG_SPLIT_NO_EMPTY) as $t) {
                        if (mb_strlen($t, "UTF-8") >= 2 && !in_array($t, $stop, true)) {
                            $tokens[$t] = true;
                        }
                    }
                    $tokens = array_slice(array_keys($tokens), 0, 8);

                    $scored = [];
                    foreach ($scope as $lid => $r) {
                        $hay = mb_strtolower($r["lesson_title"] . " " . (string) $r["lesson_description"], "UTF-8");
                        $score = 0;
                        foreach ($tokens as $t) {
                            if (mb_strpos($hay, $t, 0, "UTF-8") !== false) {
                                $score++;
                            }
                        }
                        if ($score > 0) {
                            $scored[] = [$score, $lid];
                        }
                    }
                    usort($scored, function ($a, $b) { return $b[0] <=> $a[0]; });

                    foreach (array_slice($scored, 0, 3) as $hit) {
                        $r = $scope[$hit[1]];
                        $sources[] = [
                            "type" => "lesson", "lesson_id" => $hit[1], "title" => $r["lesson_title"],
                            "snippet" => mb_substr((string) $r["lesson_description"], 0, 320, "UTF-8")
                        ];
                    }
                    foreach ($qrows as $qr) {
                        if (count($sources) >= 5 || trim((string) $qr["explanation"]) === "") {
                            continue;
                        }
                        $hay = mb_strtolower($qr["question_text"] . " " . $qr["explanation"], "UTF-8");
                        foreach ($tokens as $t) {
                            if (mb_strpos($hay, $t, 0, "UTF-8") !== false) {
                                $sources[] = ["type" => "explanation", "lesson_id" => (int) $qr["lesson_id"], "title" => $qr["question_text"], "snippet" => mb_substr((string) $qr["explanation"], 0, 320, "UTF-8")];
                                break;
                            }
                        }
                    }
                } elseif ($focus) {
                    if ($mode === "explain") {
                        $sources[] = [
                            "type" => "lesson", "lesson_id" => (int) $focus["lesson_id"], "title" => $focus["lesson_title"],
                            "snippet" => mb_substr((string) $focus["lesson_description"], 0, 600, "UTF-8")
                        ];
                    } elseif ($mode === "revise") {
                        foreach ($qrows as $qr) {
                            if (count($sources) >= 5) { break; }
                            if (trim((string) $qr["explanation"]) !== "") {
                                $sources[] = ["type" => "explanation", "lesson_id" => (int) $qr["lesson_id"], "title" => $qr["question_text"], "snippet" => mb_substr((string) $qr["explanation"], 0, 320, "UTF-8")];
                            }
                        }
                    } else { // practice: question text only, answers are never shown here
                        foreach (array_slice($qrows, 0, 5) as $qr) {
                            $sources[] = ["type" => "question", "lesson_id" => (int) $qr["lesson_id"], "title" => $qr["question_text"], "snippet" => ""];
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        if ($sources) {
            $answer = $T["found"];
        } elseif ($mode === "ask") {
            $answer = $T["none"];
        } else {
            $answer = $focus ? $T["pickless"] : $T["none"];
        }

        fk_send([
            "success"  => true,
            "provider" => "curriculum-search",   // no external AI model is connected
            "answer"   => $answer,
            "sources"  => $sources
        ]);
    }

    return false;
}
