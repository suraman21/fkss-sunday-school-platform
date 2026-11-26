<?php

// =========================================
// FELEGE KIDUSAN QUIZ API
// =========================================
// Questions are approved stored content. This API never generates quiz content.

function quizReadJsonBody()
{
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        sendJson([
            "success" => false,
            "message" => "Invalid JSON request body."
        ], 400);
    }

    return $data;
}


function quizGetStudent($pdo, $studentId)
{
    $stmt = $pdo->prepare("
        SELECT
            student_id,
            name,
            grade,
            class,
            active
        FROM students
        WHERE student_id = ?
        LIMIT 1
    ");

    $stmt->execute([$studentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}


function quizEnsureStudent($pdo, $studentId)
{
    $student = quizGetStudent($pdo, $studentId);

    if (!$student) {
        sendJson([
            "success" => false,
            "message" => "Student not found."
        ], 404);
    }

    if ((int) $student["active"] !== 1) {
        sendJson([
            "success" => false,
            "message" => "This student account is inactive."
        ], 403);
    }

    return $student;
}


function quizGetSubjectForGrade($pdo, $subjectId, $grade)
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            grade
        FROM subjects
        WHERE id = ?
          AND grade = ?
        LIMIT 1
    ");

    $stmt->execute([
        $subjectId,
        $grade
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}


function quizGetQuestionsForIds($pdo, $questionIds, $subjectId, $grade)
{
    if (count($questionIds) === 0) {
        return [];
    }

    $placeholders = implode(",", array_fill(0, count($questionIds), "?"));

    $stmt = $pdo->prepare("
        SELECT
            q.id AS question_id,
            q.question_text,
            q.difficulty,
            q.language,
            q.chapter_id,
            c.chapter_number,
            c.title AS chapter_title,
            q.lesson_id,
            l.lesson_number,
            l.title AS lesson_title
        FROM questions q
        INNER JOIN chapters c
            ON c.id = q.chapter_id
        INNER JOIN subjects s
            ON s.id = c.subject_id
        LEFT JOIN lessons l
            ON l.id = q.lesson_id
        WHERE q.id IN ($placeholders)
          AND q.active = 1
          AND s.id = ?
          AND s.grade = ?
          AND (q.lesson_id IS NULL OR l.status = 'published')
        ORDER BY FIELD(q.id, $placeholders)
    ");

    $params = array_merge(
        $questionIds,
        [$subjectId, $grade],
        $questionIds
    );

    $stmt->execute($params);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $optionStmt = $pdo->prepare("
        SELECT
            id AS option_id,
            option_label,
            option_text
        FROM question_options
        WHERE question_id = ?
        ORDER BY id ASC
    ");

    foreach ($questions as &$question) {
        $optionStmt->execute([$question["question_id"]]);
        $question["options"] = $optionStmt->fetchAll(PDO::FETCH_ASSOC);

        $question["question_id"] = (int) $question["question_id"];
        $question["chapter_id"] = (int) $question["chapter_id"];
        $question["lesson_id"] = $question["lesson_id"] !== null
            ? (int) $question["lesson_id"]
            : null;
    }

    unset($question);
    return $questions;
}


function handleQuizOptionsApi($pdo, $studentId)
{
    $student = quizEnsureStudent($pdo, $studentId);
    $grade = $student["grade"];

    $subjectStmt = $pdo->prepare("
        SELECT
            id,
            name,
            grade
        FROM subjects
        WHERE grade = ?
        ORDER BY id ASC
    ");

    $subjectStmt->execute([$grade]);
    $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

    $chapterStmt = $pdo->prepare("
        SELECT
            id,
            chapter_number,
            title,
            description,
            sort_order
        FROM chapters
        WHERE subject_id = ?
        ORDER BY sort_order ASC, id ASC
    ");

    $lessonStmt = $pdo->prepare("
        SELECT
            id,
            chapter_id,
            lesson_number,
            title,
            description,
            sort_order,
            status
        FROM lessons
        WHERE chapter_id = ?
          AND status = 'published'
        ORDER BY sort_order ASC, id ASC
    ");

    foreach ($subjects as &$subject) {
        $chapterStmt->execute([(int) $subject["id"]]);
        $chapters = $chapterStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($chapters as &$chapter) {
            $chapter["id"] = (int) $chapter["id"];
            $lessonStmt->execute([(int) $chapter["id"]]);
            $chapter["lessons"] = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        unset($chapter);

        $subject["id"] = (int) $subject["id"];
        $subject["units"] = $chapters;
    }

    unset($subject);

    sendJson([
        "success" => true,
        "studentId" => $student["student_id"],
        "grade" => $grade,
        "subjects" => $subjects,
        // Backward-compatible first subject for any older frontend code.
        "subject" => count($subjects) > 0 ? $subjects[0] : null,
        "scopes" => [
            [
                "value" => "whole_book",
                "label" => "Whole Subject"
            ],
            [
                "value" => "unit",
                "label" => "Specific Unit"
            ],
            [
                "value" => "lesson",
                "label" => "Specific Lesson"
            ]
        ]
    ]);
}


function handleQuizStartApi($pdo)
{
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $data = quizReadJsonBody();

    $studentId = trim(
        $data["student_id"] ??
        $data["studentId"] ??
        ""
    );

    $subjectId = (int) ($data["subject_id"] ?? 0);
    $scope = strtolower(trim($data["scope"] ?? ""));
    $unitId = $data["unit_id"] ?? $data["chapter_id"] ?? null;
    $lessonId = $data["lesson_id"] ?? null;
    $mode = strtolower(trim($data["mode"] ?? ""));
    $questionCount = (int) (
        $data["question_count"] ??
        $data["questionCount"] ??
        10
    );
    $language = strtolower(trim($data["language"] ?? "am"));

    if ($studentId === "") {
        sendJson([
            "success" => false,
            "message" => "Student ID is required."
        ], 400);
    }

    $student = quizEnsureStudent($pdo, $studentId);

    if ($subjectId <= 0) {
        sendJson([
            "success" => false,
            "message" => "Subject ID is required."
        ], 400);
    }

    $subject = quizGetSubjectForGrade(
        $pdo,
        $subjectId,
        $student["grade"]
    );

    if (!$subject) {
        sendJson([
            "success" => false,
            "message" => "The selected subject is not available for this student's grade."
        ], 400);
    }

    if (!in_array($scope, ["whole_book", "unit", "lesson"], true)) {
        sendJson([
            "success" => false,
            "message" => "Scope must be whole_book, unit, or lesson."
        ], 400);
    }

    if (!in_array($mode, ["immediate", "final_review"], true)) {
        sendJson([
            "success" => false,
            "message" => "Mode must be immediate or final_review."
        ], 400);
    }

    if (!in_array($language, ["am", "en"], true)) {
        sendJson([
            "success" => false,
            "message" => "Language must be am or en."
        ], 400);
    }

    if ($questionCount < 1 || $questionCount > 100) {
        sendJson([
            "success" => false,
            "message" => "Question count must be between 1 and 100."
        ], 400);
    }

    $unitId = $unitId !== null && $unitId !== ""
        ? (int) $unitId
        : null;

    $lessonId = $lessonId !== null && $lessonId !== ""
        ? (int) $lessonId
        : null;

    $chapterIdForAttempt = null;

    if ($scope === "whole_book") {
        $unitId = null;
        $lessonId = null;
    }

    if ($scope === "unit") {
        if ($unitId === null || $unitId <= 0) {
            sendJson([
                "success" => false,
                "message" => "Unit ID is required for unit scope."
            ], 400);
        }

        $unitStmt = $pdo->prepare("
            SELECT id
            FROM chapters
            WHERE id = ?
              AND subject_id = ?
            LIMIT 1
        ");

        $unitStmt->execute([
            $unitId,
            $subjectId
        ]);

        if (!$unitStmt->fetch(PDO::FETCH_ASSOC)) {
            sendJson([
                "success" => false,
                "message" => "Selected unit was not found."
            ], 404);
        }

        $chapterIdForAttempt = $unitId;
        // A lesson is optional for Unit practice.
        if ($lessonId !== null) {
            $lessonCheck = $pdo->prepare("
                SELECT id
                FROM lessons
                WHERE id = ?
                  AND chapter_id = ?
                  AND status = 'published'
                LIMIT 1
            ");

            $lessonCheck->execute([
                $lessonId,
                $unitId
            ]);

            if (!$lessonCheck->fetch(PDO::FETCH_ASSOC)) {
                sendJson([
                    "success" => false,
                    "message" => "Selected lesson was not found in this unit."
                ], 404);
            }
        }
    }

    if ($scope === "lesson") {
        if ($lessonId === null || $lessonId <= 0) {
            sendJson([
                "success" => false,
                "message" => "Lesson ID is required for lesson scope."
            ], 400);
        }

        $lessonStmt = $pdo->prepare("
            SELECT
                l.id,
                l.chapter_id
            FROM lessons l
            INNER JOIN chapters c
                ON c.id = l.chapter_id
            WHERE l.id = ?
              AND c.subject_id = ?
              AND l.status = 'published'
            LIMIT 1
        ");

        $lessonStmt->execute([
            $lessonId,
            $subjectId
        ]);

        $lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lesson) {
            sendJson([
                "success" => false,
                "message" => "Selected lesson was not found."
            ], 404);
        }

        $chapterIdForAttempt = (int) $lesson["chapter_id"];
        $unitId = $chapterIdForAttempt;
    }

    // Lessons the student may open (unit rules + teacher unlocks). Integers only, so safe to inline.
    $openIds = fk_open_lesson_ids($pdo, fk_learning_student($pdo));
    $openLessonSql = $openIds
        ? " OR q.lesson_id IN (" . implode(",", array_map("intval", $openIds)) . ")"
        : "";

    // Count eligible approved questions.
    $countSql = "
        SELECT COUNT(*)
        FROM questions q
        INNER JOIN chapters c
            ON c.id = q.chapter_id
        INNER JOIN subjects s
            ON s.id = c.subject_id
        LEFT JOIN lessons l
            ON l.id = q.lesson_id
        WHERE q.active = 1
          AND s.id = ?
          AND s.grade = ?
          AND q.language = ?
          AND (q.lesson_id IS NULL OR l.status = 'published')
          /* only lessons that are open for this student (see fk_open_lesson_ids) */
          AND (q.lesson_id IS NULL" . $openLessonSql . ")
    ";

    $countParams = [
        $subjectId,
        $student["grade"],
        $language
    ];

    if ($scope === "unit") {
        $countSql .= " AND q.chapter_id = ? ";
        $countParams[] = $unitId;

        // When a lesson is supplied for Unit practice, focus on that lesson.
        if ($lessonId !== null) {
            $countSql .= " AND q.lesson_id = ? ";
            $countParams[] = $lessonId;
        }
    }

    if ($scope === "lesson") {
        $countSql .= " AND q.lesson_id = ? ";
        $countParams[] = $lessonId;
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);

    $availableQuestions = (int) $countStmt->fetchColumn();

    if ($availableQuestions === 0) {
        sendJson([
            "success" => false,
            "message" => "No approved questions are available for this selection yet.",
            "availableQuestions" => 0
        ], 404);
    }

    $actualQuestionCount = min(
        $questionCount,
        $availableQuestions
    );

    $pdo->beginTransaction();

    try {
        $attemptStmt = $pdo->prepare("
            INSERT INTO quiz_attempts (
                chapter_id,
                scope,
                lesson_id,
                student_id,
                mode,
                question_count
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $attemptStmt->execute([
            $chapterIdForAttempt,
            $scope,
            $scope === "lesson" ? $lessonId : null,
            $studentId,
            $mode,
            $actualQuestionCount
        ]);

        $attemptId = (int) $pdo->lastInsertId();

        $questionSql = "
            SELECT q.id
            FROM questions q
            INNER JOIN chapters c
                ON c.id = q.chapter_id
            INNER JOIN subjects s
                ON s.id = c.subject_id
            LEFT JOIN lessons l
                ON l.id = q.lesson_id
            WHERE q.active = 1
              AND s.id = ?
              AND s.grade = ?
              AND q.language = ?
              AND (q.lesson_id IS NULL OR l.status = 'published')
              /* only lessons that are open for this student (see fk_open_lesson_ids) */
              AND (q.lesson_id IS NULL" . $openLessonSql . ")
        ";

        $questionParams = [
            $subjectId,
            $student["grade"],
            $language
        ];

        if ($scope === "unit") {
            $questionSql .= " AND q.chapter_id = ? ";
            $questionParams[] = $unitId;

            if ($lessonId !== null) {
                $questionSql .= " AND q.lesson_id = ? ";
                $questionParams[] = $lessonId;
            }
        }

        if ($scope === "lesson") {
            $questionSql .= " AND q.lesson_id = ? ";
            $questionParams[] = $lessonId;
        }

        // Randomly select approved questions from the selected subject/scope.
        $questionSql .= " ORDER BY RAND() LIMIT " . $actualQuestionCount;

        $questionStmt = $pdo->prepare($questionSql);
        $questionStmt->execute($questionParams);

        $selectedQuestionIds = array_map(
            "intval",
            $questionStmt->fetchAll(PDO::FETCH_COLUMN)
        );

        if (count($selectedQuestionIds) !== $actualQuestionCount) {
            throw new RuntimeException("Could not select the requested questions.");
        }

        $attemptQuestionStmt = $pdo->prepare("
            INSERT INTO quiz_attempt_questions (
                attempt_id,
                question_id,
                question_order
            )
            VALUES (?, ?, ?)
        ");

        foreach ($selectedQuestionIds as $index => $questionId) {
            $attemptQuestionStmt->execute([
                $attemptId,
                $questionId,
                $index + 1
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        sendJson([
            "success" => false,
            "message" => "Could not start the quiz."
        ], 500);
    }

    $questions = quizGetQuestionsForIds(
        $pdo,
        $selectedQuestionIds,
        $subjectId,
        $student["grade"]
    );

    // Do not expose correct answers or explanations at quiz start.
    sendJson([
        "success" => true,
        "message" => "Quiz started successfully.",
        "attempt" => [
            "id" => $attemptId,
            "studentId" => $studentId,
            "grade" => $student["grade"],
            "subjectId" => $subjectId,
            "subjectName" => $subject["name"],
            "scope" => $scope,
            "unitId" => $unitId,
            "lessonId" => $lessonId,
            "mode" => $mode,
            "questionCount" => $actualQuestionCount
        ],
        "questions" => $questions
    ]);
}


function handleQuizAnswerApi($pdo)
{
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $data = quizReadJsonBody();

    $studentId = trim(
        $data["student_id"] ??
        $data["studentId"] ??
        ""
    );

    $attemptId = (int) ($data["attempt_id"] ?? 0);
    $questionId = (int) ($data["question_id"] ?? 0);
    $selectedOptionId = (int) (
        $data["selected_option_id"] ??
        $data["selectedOptionId"] ??
        0
    );

    if ($studentId === "") {
        sendJson([
            "success" => false,
            "message" => "Student ID is required."
        ], 400);
    }

    quizEnsureStudent($pdo, $studentId);

    if ($attemptId <= 0 || $questionId <= 0 || $selectedOptionId <= 0) {
        sendJson([
            "success" => false,
            "message" => "Attempt ID, question ID, and selected option ID are required."
        ], 400);
    }

    $attemptStmt = $pdo->prepare("
        SELECT
            id,
            student_id,
            mode,
            completed_at
        FROM quiz_attempts
        WHERE id = ?
          AND student_id = ?
        LIMIT 1
    ");

    $attemptStmt->execute([
        $attemptId,
        $studentId
    ]);

    $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);

    if (!$attempt) {
        sendJson([
            "success" => false,
            "message" => "Quiz attempt not found."
        ], 404);
    }

    if ($attempt["completed_at"] !== null) {
        sendJson([
            "success" => false,
            "message" => "This quiz has already been completed."
        ], 400);
    }

    $membershipStmt = $pdo->prepare("
        SELECT question_order
        FROM quiz_attempt_questions
        WHERE attempt_id = ?
          AND question_id = ?
        LIMIT 1
    ");

    $membershipStmt->execute([
        $attemptId,
        $questionId
    ]);

    $membership = $membershipStmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership) {
        sendJson([
            "success" => false,
            "message" => "This question does not belong to the selected quiz."
        ], 400);
    }

    $optionStmt = $pdo->prepare("
        SELECT
            id,
            option_label,
            option_text,
            is_correct
        FROM question_options
        WHERE id = ?
          AND question_id = ?
        LIMIT 1
    ");

    $optionStmt->execute([
        $selectedOptionId,
        $questionId
    ]);

    $selectedOption = $optionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$selectedOption) {
        sendJson([
            "success" => false,
            "message" => "Selected option does not belong to this question."
        ], 400);
    }

    $isCorrect = (int) $selectedOption["is_correct"] === 1;

    $existingAnswerStmt = $pdo->prepare("
        SELECT id
        FROM quiz_answers
        WHERE attempt_id = ?
          AND question_id = ?
        LIMIT 1
    ");

    $existingAnswerStmt->execute([
        $attemptId,
        $questionId
    ]);

    $existingAnswerId = $existingAnswerStmt->fetchColumn();

    if ($existingAnswerId) {
        $updateStmt = $pdo->prepare("
            UPDATE quiz_answers
            SET
                selected_option_id = ?,
                is_correct = ?,
                answered_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $updateStmt->execute([
            $selectedOptionId,
            $isCorrect ? 1 : 0,
            (int) $existingAnswerId
        ]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO quiz_answers (
                attempt_id,
                question_id,
                selected_option_id,
                is_correct
            )
            VALUES (?, ?, ?, ?)
        ");

        $insertStmt->execute([
            $attemptId,
            $questionId,
            $selectedOptionId,
            $isCorrect ? 1 : 0
        ]);
    }

    // Final Review mode intentionally hides the correct answer until completion.
    if ($attempt["mode"] === "final_review") {
        sendJson([
            "success" => true,
            "saved" => true,
            "questionOrder" => (int) $membership["question_order"]
        ]);
    }

    $resultStmt = $pdo->prepare("
        SELECT
            q.explanation,
            correct_option.id AS correct_option_id,
            correct_option.option_label AS correct_option_label,
            correct_option.option_text AS correct_option_text
        FROM questions q
        LEFT JOIN question_options correct_option
            ON correct_option.question_id = q.id
           AND correct_option.is_correct = 1
        WHERE q.id = ?
        LIMIT 1
    ");

    $resultStmt->execute([$questionId]);
    $result = $resultStmt->fetch(PDO::FETCH_ASSOC);

    sendJson([
        "success" => true,
        "saved" => true,
        "isCorrect" => $isCorrect,
        "selectedOption" => [
            "id" => (int) $selectedOption["id"],
            "label" => $selectedOption["option_label"],
            "text" => $selectedOption["option_text"]
        ],
        "correctOption" => [
            "id" => (int) $result["correct_option_id"],
            "label" => $result["correct_option_label"],
            "text" => $result["correct_option_text"]
        ],
        "explanation" => $result["explanation"]
    ]);
}


function handleQuizCompleteApi($pdo)
{
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $data = quizReadJsonBody();

    $studentId = trim(
        $data["student_id"] ??
        $data["studentId"] ??
        ""
    );

    $attemptId = (int) ($data["attempt_id"] ?? 0);

    if ($studentId === "") {
        sendJson([
            "success" => false,
            "message" => "Student ID is required."
        ], 400);
    }

    quizEnsureStudent($pdo, $studentId);

    if ($attemptId <= 0) {
        sendJson([
            "success" => false,
            "message" => "Attempt ID is required."
        ], 400);
    }

    $attemptStmt = $pdo->prepare("
        SELECT
            qa.id,
            qa.student_id,
            qa.scope,
            qa.chapter_id,
            qa.lesson_id,
            qa.mode,
            qa.question_count,
            qa.score,
            qa.passed,
            qa.completed_at
        FROM quiz_attempts qa
        WHERE qa.id = ?
          AND qa.student_id = ?
        LIMIT 1
    ");

    $attemptStmt->execute([
        $attemptId,
        $studentId
    ]);

    $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);

    if (!$attempt) {
        sendJson([
            "success" => false,
            "message" => "Quiz attempt not found."
        ], 404);
    }

    if ($attempt["completed_at"] !== null) {
        sendJson([
            "success" => false,
            "message" => "This quiz has already been completed."
        ], 400);
    }

    $answerCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM quiz_answers
        WHERE attempt_id = ?
    ");

    $answerCountStmt->execute([$attemptId]);

    $answerCount = (int) $answerCountStmt->fetchColumn();
    $questionCount = (int) $attempt["question_count"];

    if ($answerCount < $questionCount) {
        sendJson([
            "success" => false,
            "message" => "Please answer all quiz questions before completing the quiz.",
            "answered" => $answerCount,
            "total" => $questionCount
        ], 400);
    }

    $scoreStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct
        FROM quiz_answers
        WHERE attempt_id = ?
    ");

    $scoreStmt->execute([$attemptId]);
    $scoreData = $scoreStmt->fetch(PDO::FETCH_ASSOC);

    $total = (int) $scoreData["total"];
    $correct = (int) $scoreData["correct"];

    $percentage = $total > 0
        ? round(($correct / $total) * 100, 2)
        : 0;

    $passed = $percentage >= 60;

    $updateAttemptStmt = $pdo->prepare("
        UPDATE quiz_attempts
        SET
            score = ?,
            passed = ?,
            completed_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $updateAttemptStmt->execute([
        $percentage,
        $passed ? 1 : 0,
        $attemptId
    ]);

    $resultsStmt = $pdo->prepare("
        SELECT
            qap.question_order,
            q.id AS question_id,
            q.question_text,
            q.difficulty,
            q.explanation,
            q.chapter_id,
            c.chapter_number,
            c.title AS chapter_title,
            q.lesson_id,
            l.lesson_number,
            l.title AS lesson_title,
            qa.selected_option_id,
            qa.is_correct,
            selected_option.option_label AS selected_option_label,
            selected_option.option_text AS selected_option_text,
            correct_option.id AS correct_option_id,
            correct_option.option_label AS correct_option_label,
            correct_option.option_text AS correct_option_text
        FROM quiz_attempt_questions qap
        INNER JOIN questions q
            ON q.id = qap.question_id
        INNER JOIN chapters c
            ON c.id = q.chapter_id
        LEFT JOIN lessons l
            ON l.id = q.lesson_id
        LEFT JOIN quiz_answers qa
            ON qa.attempt_id = qap.attempt_id
           AND qa.question_id = qap.question_id
        LEFT JOIN question_options selected_option
            ON selected_option.id = qa.selected_option_id
        LEFT JOIN question_options correct_option
            ON correct_option.question_id = q.id
           AND correct_option.is_correct = 1
        WHERE qap.attempt_id = ?
        ORDER BY qap.question_order ASC
    ");

    $resultsStmt->execute([$attemptId]);
    $results = $resultsStmt->fetchAll(PDO::FETCH_ASSOC);

    $weakAreas = [];
    $difficultyStats = [
        "easy" => ["correct" => 0, "total" => 0],
        "medium" => ["correct" => 0, "total" => 0],
        "hard" => ["correct" => 0, "total" => 0]
    ];

    foreach ($results as &$result) {
        $chapterKey = (string) $result["chapter_id"];

        if ((int) $result["is_correct"] !== 1) {
            if (!isset($weakAreas[$chapterKey])) {
                $weakAreas[$chapterKey] = [
                    "chapterId" => (int) $result["chapter_id"],
                    "chapterNumber" => $result["chapter_number"],
                    "chapterTitle" => $result["chapter_title"],
                    "incorrectCount" => 0
                ];
            }

            $weakAreas[$chapterKey]["incorrectCount"]++;
        }

        $difficulty = $result["difficulty"];

        if (isset($difficultyStats[$difficulty])) {
            $difficultyStats[$difficulty]["total"]++;

            if ((int) $result["is_correct"] === 1) {
                $difficultyStats[$difficulty]["correct"]++;
            }
        }

        $result["question_id"] = (int) $result["question_id"];
        $result["question_order"] = (int) $result["question_order"];
        $result["chapter_id"] = (int) $result["chapter_id"];
        $result["lesson_id"] = $result["lesson_id"] !== null
            ? (int) $result["lesson_id"]
            : null;
        $result["selected_option_id"] = $result["selected_option_id"] !== null
            ? (int) $result["selected_option_id"]
            : null;
        $result["correct_option_id"] = $result["correct_option_id"] !== null
            ? (int) $result["correct_option_id"]
            : null;
        $result["is_correct"] = (bool) $result["is_correct"];
    }

    unset($result);

    usort(
        $weakAreas,
        function ($a, $b) {
            return $b["incorrectCount"] <=> $a["incorrectCount"];
        }
    );

    $weakAreas = array_values($weakAreas);

    if (count($weakAreas) === 0) {
        $recommendation =
            "Excellent work. You did not miss any questions in this practice. Try another set to keep your understanding strong.";
    } else {
        $topWeakArea = $weakAreas[0];

        $recommendation =
            "Review Unit " .
            $topWeakArea["chapterNumber"] .
            " — " .
            $topWeakArea["chapterTitle"] .
            " and try another practice set focused on that area.";
    }

    sendJson([
        "success" => true,
        "message" => "Quiz completed successfully.",
        "attempt" => [
            "id" => (int) $attemptId,
            "mode" => $attempt["mode"],
            "scope" => $attempt["scope"],
            "unitId" => $attempt["chapter_id"] !== null
                ? (int) $attempt["chapter_id"]
                : null,
            "lessonId" => $attempt["lesson_id"] !== null
                ? (int) $attempt["lesson_id"]
                : null,
            "questionCount" => $questionCount,
            "answered" => $answerCount,
            "score" => $percentage,
            "passed" => $passed
        ],
        "summary" => [
            "correct" => $correct,
            "incorrect" => $total - $correct,
            "percentage" => $percentage
        ],
        "results" => $results,
        "personalization" => [
            "weakAreas" => $weakAreas,
            "difficultyStats" => $difficultyStats,
            "recommendation" => $recommendation
        ]
    ]);
}


function handleQuizHistoryApi($pdo, $studentId)
{
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    quizEnsureStudent($pdo, $studentId);

    $stmt = $pdo->prepare("
        SELECT
            qa.id AS attempt_id,
            qa.scope,
            qa.chapter_id,
            c.chapter_number,
            c.title AS chapter_title,
            qa.lesson_id,
            l.lesson_number,
            l.title AS lesson_title,
            qa.mode,
            qa.question_count,
            qa.score,
            qa.passed,
            qa.completed_at,
            qa.created_at
        FROM quiz_attempts qa
        LEFT JOIN chapters c
            ON c.id = qa.chapter_id
        LEFT JOIN lessons l
            ON l.id = qa.lesson_id
        WHERE qa.student_id = ?
        ORDER BY qa.created_at DESC, qa.id DESC
        LIMIT 20
    ");

    $stmt->execute([$studentId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($history as &$item) {
        $item["attempt_id"] = (int) $item["attempt_id"];
        $item["chapter_id"] = $item["chapter_id"] !== null
            ? (int) $item["chapter_id"]
            : null;
        $item["lesson_id"] = $item["lesson_id"] !== null
            ? (int) $item["lesson_id"]
            : null;
        $item["question_count"] = (int) $item["question_count"];
        $item["score"] = $item["score"] !== null
            ? (float) $item["score"]
            : null;
        $item["passed"] = (bool) $item["passed"];
    }

    unset($item);

    sendJson([
        "success" => true,
        "studentId" => $studentId,
        "history" => $history
    ]);
}


function handleQuizApi($pdo, $uri)
{
    if ($uri === "/api/quiz/start") {
        handleQuizStartApi($pdo);
        return true;
    }

    if ($uri === "/api/quiz/answer") {
        handleQuizAnswerApi($pdo);
        return true;
    }

    if ($uri === "/api/quiz/complete") {
        handleQuizCompleteApi($pdo);
        return true;
    }

    if (preg_match("#^/api/quiz/options/([^/]+)$#", $uri, $matches)) {
        handleQuizOptionsApi(
            $pdo,
            urldecode($matches[1])
        );
        return true;
    }

    if (preg_match("#^/api/quiz/history/([^/]+)$#", $uri, $matches)) {
        handleQuizHistoryApi(
            $pdo,
            urldecode($matches[1])
        );
        return true;
    }

    return false;
}
