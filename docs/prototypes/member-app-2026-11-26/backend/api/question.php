<?php

// =========================================
// FELEGE KIDUSAN QUESTION BANK API
// =========================================
// This file is loaded by backend/api.php.
//
// Purpose:
// - Add questions to the question bank
// - List/search questions
// - Update questions
// - Activate/deactivate questions
//
// IMPORTANT:
// This API stores collected/approved questions.
// It does NOT generate questions with AI.
//
// Management endpoints must be protected by
// proper admin/content-manager authentication
// before production deployment.
// =========================================


// =========================================
// READ JSON REQUEST
// =========================================

function questionReadJsonBody()
{
    $rawInput = file_get_contents("php://input");

    $data = json_decode(
        $rawInput,
        true
    );

    if (!is_array($data)) {
        sendJson([
            "success" => false,
            "message" => "Invalid JSON request body."
        ], 400);
    }

    return $data;
}


// =========================================
// VALIDATE SUBJECT
// =========================================

function questionGetSubject($pdo, $subjectId)
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            grade,
            language
        FROM subjects
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $subjectId
    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    );
}


// =========================================
// VALIDATE CHAPTER / UNIT
// =========================================

function questionGetChapter($pdo, $chapterId, $subjectId)
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            subject_id,
            chapter_number,
            title
        FROM chapters
        WHERE id = ?
          AND subject_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $chapterId,
        $subjectId
    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    );
}


// =========================================
// VALIDATE OPTIONAL LESSON
// =========================================

function questionGetLesson($pdo, $lessonId, $chapterId)
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            chapter_id,
            lesson_number,
            title,
            status
        FROM lessons
        WHERE id = ?
          AND chapter_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $lessonId,
        $chapterId
    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    );
}


// =========================================
// VALIDATE QUESTION OPTIONS
// =========================================

function questionValidateOptions($options)
{
    if (!is_array($options)) {
        sendJson([
            "success" => false,
            "message" => "Options must be an array."
        ], 400);
    }

    if (count($options) < 2) {
        sendJson([
            "success" => false,
            "message" => "At least 2 options are required."
        ], 400);
    }

    if (count($options) > 6) {
        sendJson([
            "success" => false,
            "message" => "A maximum of 6 options is allowed."
        ], 400);
    }

    $cleanOptions = [];
    $correctCount = 0;
    $usedLabels = [];

    foreach ($options as $index => $option) {

        if (!is_array($option)) {
            sendJson([
                "success" => false,
                "message" => "Each option must be an object."
            ], 400);
        }

        $label = strtoupper(
            trim(
                $option["label"] ??
                $option["option_label"] ??
                ""
            )
        );

        $text = trim(
            $option["text"] ??
            $option["option_text"] ??
            ""
        );

        $isCorrect =
            !empty(
                $option["is_correct"] ??
                $option["isCorrect"] ??
                false
            );

        if ($label === "") {
            sendJson([
                "success" => false,
                "message" => "Every option must have a label."
            ], 400);
        }

        if ($text === "") {
            sendJson([
                "success" => false,
                "message" =>
                    "Every option must have option text."
            ], 400);
        }

        if (isset($usedLabels[$label])) {
            sendJson([
                "success" => false,
                "message" =>
                    "Option labels must be unique."
            ], 400);
        }

        $usedLabels[$label] = true;

        if ($isCorrect) {
            $correctCount++;
        }

        $cleanOptions[] = [
            "label" => $label,
            "text" => $text,
            "is_correct" => $isCorrect ? 1 : 0
        ];
    }

    if ($correctCount !== 1) {
        sendJson([
            "success" => false,
            "message" =>
                "Exactly one option must be marked as correct."
        ], 400);
    }

    return $cleanOptions;
}


// =========================================
// GET QUESTION WITH OPTIONS
// =========================================

function questionGetById($pdo, $questionId)
{
    $stmt = $pdo->prepare("
        SELECT
            q.id AS question_id,
            q.chapter_id,
            c.chapter_number,
            c.title AS chapter_title,
            c.subject_id,
            s.name AS subject_name,
            s.grade,
            q.lesson_id,
            l.lesson_number,
            l.title AS lesson_title,
            q.question_text,
            q.difficulty,
            q.language,
            q.explanation,
            q.active,
            q.created_at
        FROM questions q
        INNER JOIN chapters c
            ON c.id = q.chapter_id
        INNER JOIN subjects s
            ON s.id = c.subject_id
        LEFT JOIN lessons l
            ON l.id = q.lesson_id
        WHERE q.id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $questionId
    ]);

    $question = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$question) {
        return null;
    }

    $optionStmt = $pdo->prepare("
        SELECT
            id AS option_id,
            option_label,
            option_text,
            is_correct
        FROM question_options
        WHERE question_id = ?
        ORDER BY id ASC
    ");

    $optionStmt->execute([
        $questionId
    ]);

    $question["options"] =
        $optionStmt->fetchAll(
            PDO::FETCH_ASSOC
        );

    $question["question_id"] =
        (int) $question["question_id"];

    $question["chapter_id"] =
        (int) $question["chapter_id"];

    $question["subject_id"] =
        (int) $question["subject_id"];

    $question["lesson_id"] =
        $question["lesson_id"] !== null
            ? (int) $question["lesson_id"]
            : null;

    $question["active"] =
        (bool) $question["active"];

    foreach ($question["options"] as &$option) {

        $option["option_id"] =
            (int) $option["option_id"];

        $option["is_correct"] =
            (bool) $option["is_correct"];
    }

    unset($option);

    return $question;
}


// =========================================
// ADD QUESTION
// =========================================
// POST /api/questions
// =========================================

function handleQuestionCreateApi($pdo)
{
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $data = questionReadJsonBody();

    $subjectId =
        (int) (
            $data["subject_id"] ??
            $data["subjectId"] ??
            0
        );

    $chapterId =
        (int) (
            $data["chapter_id"] ??
            $data["unit_id"] ??
            $data["chapterId"] ??
            0
        );

    $lessonValue =
        $data["lesson_id"] ??
        $data["lessonId"] ??
        null;

    $lessonId =
        $lessonValue !== null &&
        $lessonValue !== ""
            ? (int) $lessonValue
            : null;

    $questionText = trim(
        $data["question_text"] ??
        $data["question"] ??
        ""
    );

    $difficulty = strtolower(
        trim(
            $data["difficulty"] ??
            "medium"
        )
    );

    $language = strtolower(
        trim(
            $data["language"] ??
            "am"
        )
    );

    $explanation = trim(
        $data["explanation"] ??
        ""
    );

    $active =
        isset($data["active"])
            ? (
                (int) $data["active"] === 1
                    ? 1
                    : 0
            )
            : 1;

    $options =
        $data["options"] ??
        [];

    // =========================================
    // REQUIRED FIELDS
    // =========================================

    if ($subjectId <= 0) {
        sendJson([
            "success" => false,
            "message" => "Subject ID is required."
        ], 400);
    }

    if ($chapterId <= 0) {
        sendJson([
            "success" => false,
            "message" => "Unit/chapter ID is required."
        ], 400);
    }

    if ($questionText === "") {
        sendJson([
            "success" => false,
            "message" => "Question text is required."
        ], 400);
    }

    if (!in_array(
        $difficulty,
        ["easy", "medium", "hard"],
        true
    )) {
        sendJson([
            "success" => false,
            "message" =>
                "Difficulty must be easy, medium, or hard."
        ], 400);
    }

    if (!in_array(
        $language,
        ["am", "en"],
        true
    )) {
        sendJson([
            "success" => false,
            "message" =>
                "Language must be am or en."
        ], 400);
    }

    // =========================================
    // SUBJECT
    // =========================================

    $subject =
        questionGetSubject(
            $pdo,
            $subjectId
        );

    if (!$subject) {
        sendJson([
            "success" => false,
            "message" => "Subject not found."
        ], 404);
    }

    // =========================================
    // DEMO RESTRICTION
    // =========================================

    if (
        $subject["id"] != 1 ||
        $subject["grade"] !== "10ኛ"
    ) {
        sendJson([
            "success" => false,
            "message" =>
                "Question bank demo currently supports Grade 10 subject 1 only."
        ], 400);
    }

    // =========================================
    // CHAPTER / UNIT
    // =========================================

    $chapter =
        questionGetChapter(
            $pdo,
            $chapterId,
            $subjectId
        );

    if (!$chapter) {
        sendJson([
            "success" => false,
            "message" =>
                "Selected unit does not belong to the selected subject."
        ], 400);
    }

    // =========================================
    // OPTIONAL LESSON
    // =========================================

    if ($lessonId !== null) {

        if ($lessonId <= 0) {
            sendJson([
                "success" => false,
                "message" =>
                    "Lesson ID must be valid."
            ], 400);
        }

        $lesson =
            questionGetLesson(
                $pdo,
                $lessonId,
                $chapterId
            );

        if (!$lesson) {
            sendJson([
                "success" => false,
                "message" =>
                    "Selected lesson does not belong to the selected unit."
            ], 400);
        }
    }

    // =========================================
    // OPTIONS
    // =========================================

    $cleanOptions =
        questionValidateOptions(
            $options
        );

    // =========================================
    // SAVE EVERYTHING
    // =========================================

    $pdo->beginTransaction();

    try {

        $questionStmt = $pdo->prepare("
            INSERT INTO questions (
                chapter_id,
                lesson_id,
                question_text,
                difficulty,
                language,
                explanation,
                active
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $questionStmt->execute([
            $chapterId,
            $lessonId,
            $questionText,
            $difficulty,
            $language,
            $explanation !== ""
                ? $explanation
                : null,
            $active
        ]);

        $questionId =
            (int) $pdo->lastInsertId();

        $optionStmt = $pdo->prepare("
            INSERT INTO question_options (
                question_id,
                option_label,
                option_text,
                is_correct
            )
            VALUES (?, ?, ?, ?)
        ");

        foreach ($cleanOptions as $option) {

            $optionStmt->execute([
                $questionId,
                $option["label"],
                $option["text"],
                $option["is_correct"]
            ]);
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        sendJson([
            "success" => false,
            "message" =>
                "Could not save the question."
        ], 500);
    }

    $question =
        questionGetById(
            $pdo,
            $questionId
        );

    sendJson([
        "success" => true,
        "message" =>
            "Question added successfully.",
        "question" => $question
    ], 201);
}


// =========================================
// LIST QUESTIONS
// =========================================
// GET /api/questions
// =========================================

function handleQuestionListApi($pdo)
{
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $subjectId =
        isset($_GET["subject_id"])
            ? (int) $_GET["subject_id"]
            : 1;

    $chapterId =
        isset($_GET["chapter_id"])
            ? (int) $_GET["chapter_id"]
            : null;

    $lessonId =
        isset($_GET["lesson_id"])
            ? (int) $_GET["lesson_id"]
            : null;

    $activeFilter =
        isset($_GET["active"])
            ? $_GET["active"]
            : "all";

    $search =
        trim(
            $_GET["search"] ??
            ""
        );

    $sql = "
        SELECT
            q.id AS question_id,
            q.chapter_id,
            c.chapter_number,
            c.title AS chapter_title,
            q.lesson_id,
            l.lesson_number,
            l.title AS lesson_title,
            q.question_text,
            q.difficulty,
            q.language,
            q.explanation,
            q.active,
            q.created_at
        FROM questions q

        INNER JOIN chapters c
            ON c.id = q.chapter_id

        INNER JOIN subjects s
            ON s.id = c.subject_id

        LEFT JOIN lessons l
            ON l.id = q.lesson_id

        WHERE s.id = ?
          AND s.grade = '10ኛ'
    ";

    $params = [
        $subjectId
    ];

    if ($chapterId !== null) {
        $sql .= "
            AND q.chapter_id = ?
        ";

        $params[] = $chapterId;
    }

    if ($lessonId !== null) {
        $sql .= "
            AND q.lesson_id = ?
        ";

        $params[] = $lessonId;
    }

    if (
        $activeFilter === "1" ||
        $activeFilter === "0"
    ) {
        $sql .= "
            AND q.active = ?
        ";

        $params[] =
            (int) $activeFilter;
    }

    if ($search !== "") {
        $sql .= "
            AND (
                q.question_text LIKE ?
                OR q.explanation LIKE ?
            )
        ";

        $searchPattern =
            "%" . $search . "%";

        $params[] =
            $searchPattern;

        $params[] =
            $searchPattern;
    }

    $sql .= "
        ORDER BY
            q.created_at DESC,
            q.id DESC
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute(
        $params
    );

    $questions =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

    $optionStmt = $pdo->prepare("
        SELECT
            id AS option_id,
            option_label,
            option_text,
            is_correct
        FROM question_options
        WHERE question_id = ?
        ORDER BY id ASC
    ");

    foreach ($questions as &$question) {

        $optionStmt->execute([
            $question["question_id"]
        ]);

        $question["options"] =
            $optionStmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        $question["question_id"] =
            (int) $question["question_id"];

        $question["chapter_id"] =
            (int) $question["chapter_id"];

        $question["lesson_id"] =
            $question["lesson_id"] !== null
                ? (int) $question["lesson_id"]
                : null;

        $question["active"] =
            (bool) $question["active"];

        foreach (
            $question["options"]
            as &$option
        ) {
            $option["option_id"] =
                (int) $option["option_id"];

            $option["is_correct"] =
                (bool) $option["is_correct"];
        }

        unset($option);
    }

    unset($question);

    sendJson([
        "success" => true,
        "count" => count($questions),
        "questions" => $questions
    ]);
}


// =========================================
// UPDATE QUESTION
// =========================================
// PUT /api/questions/{id}
// =========================================

function handleQuestionUpdateApi($pdo, $questionId)
{
    if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $existing =
        questionGetById(
            $pdo,
            $questionId
        );

    if (!$existing) {
        sendJson([
            "success" => false,
            "message" => "Question not found."
        ], 404);
    }

    $data =
        questionReadJsonBody();

    $subjectId =
        (int) (
            $data["subject_id"] ??
            $existing["subject_id"]
        );

    $chapterId =
        (int) (
            $data["chapter_id"] ??
            $data["unit_id"] ??
            $existing["chapter_id"]
        );

    $lessonValue =
        array_key_exists(
            "lesson_id",
            $data
        )
            ? $data["lesson_id"]
            : $existing["lesson_id"];

    $lessonId =
        $lessonValue !== null &&
        $lessonValue !== ""
            ? (int) $lessonValue
            : null;

    $questionText =
        trim(
            $data["question_text"] ??
            $existing["question_text"]
        );

    $difficulty =
        strtolower(
            trim(
                $data["difficulty"] ??
                $existing["difficulty"]
            )
        );

    $language =
        strtolower(
            trim(
                $data["language"] ??
                $existing["language"]
            )
        );

    $explanation =
        array_key_exists(
            "explanation",
            $data
        )
            ? trim(
                $data["explanation"] ?? ""
            )
            : (
                $existing["explanation"] ??
                ""
            );

    $active =
        array_key_exists(
            "active",
            $data
        )
            ? (
                (int) $data["active"] === 1
                    ? 1
                    : 0
            )
            : (
                $existing["active"]
                    ? 1
                    : 0
            );

    $optionsProvided =
        array_key_exists(
            "options",
            $data
        );

    $options =
        $optionsProvided
            ? questionValidateOptions(
                $data["options"]
            )
            : null;

    if ($subjectId <= 0) {
        sendJson([
            "success" => false,
            "message" =>
                "Subject ID is required."
        ], 400);
    }

    if ($chapterId <= 0) {
        sendJson([
            "success" => false,
            "message" =>
                "Unit/chapter ID is required."
        ], 400);
    }

    if ($questionText === "") {
        sendJson([
            "success" => false,
            "message" =>
                "Question text is required."
        ], 400);
    }

    if (!in_array(
        $difficulty,
        ["easy", "medium", "hard"],
        true
    )) {
        sendJson([
            "success" => false,
            "message" =>
                "Difficulty must be easy, medium, or hard."
        ], 400);
    }

    if (!in_array(
        $language,
        ["am", "en"],
        true
    )) {
        sendJson([
            "success" => false,
            "message" =>
                "Language must be am or en."
        ], 400);
    }

    $subject =
        questionGetSubject(
            $pdo,
            $subjectId
        );

    if (!$subject) {
        sendJson([
            "success" => false,
            "message" => "Subject not found."
        ], 404);
    }

    if (
        $subject["id"] != 1 ||
        $subject["grade"] !== "10ኛ"
    ) {
        sendJson([
            "success" => false,
            "message" =>
                "Question bank demo currently supports Grade 10 subject 1 only."
        ], 400);
    }

    $chapter =
        questionGetChapter(
            $pdo,
            $chapterId,
            $subjectId
        );

    if (!$chapter) {
        sendJson([
            "success" => false,
            "message" =>
                "Selected unit does not belong to the selected subject."
        ], 400);
    }

    if ($lessonId !== null) {

        $lesson =
            questionGetLesson(
                $pdo,
                $lessonId,
                $chapterId
            );

        if (!$lesson) {
            sendJson([
                "success" => false,
                "message" =>
                    "Selected lesson does not belong to the selected unit."
            ], 400);
        }
    }

    $pdo->beginTransaction();

    try {

        $updateStmt = $pdo->prepare("
            UPDATE questions
            SET
                chapter_id = ?,
                lesson_id = ?,
                question_text = ?,
                difficulty = ?,
                language = ?,
                explanation = ?,
                active = ?
            WHERE id = ?
        ");

        $updateStmt->execute([
            $chapterId,
            $lessonId,
            $questionText,
            $difficulty,
            $language,
            $explanation !== ""
                ? $explanation
                : null,
            $active,
            $questionId
        ]);

        if ($optionsProvided) {

            $deleteOptionsStmt =
                $pdo->prepare("
                    DELETE FROM question_options
                    WHERE question_id = ?
                ");

            $deleteOptionsStmt->execute([
                $questionId
            ]);

            $insertOptionStmt =
                $pdo->prepare("
                    INSERT INTO question_options (
                        question_id,
                        option_label,
                        option_text,
                        is_correct
                    )
                    VALUES (?, ?, ?, ?)
                ");

            foreach ($options as $option) {

                $insertOptionStmt->execute([
                    $questionId,
                    $option["label"],
                    $option["text"],
                    $option["is_correct"]
                ]);
            }
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        sendJson([
            "success" => false,
            "message" =>
                "Could not update the question."
        ], 500);
    }

    $question =
        questionGetById(
            $pdo,
            $questionId
        );

    sendJson([
        "success" => true,
        "message" =>
            "Question updated successfully.",
        "question" => $question
    ]);
}


// =========================================
// CHANGE QUESTION STATUS
// =========================================
// PATCH /api/questions/{id}/status
// =========================================

function handleQuestionStatusApi($pdo, $questionId)
{
    if ($_SERVER["REQUEST_METHOD"] !== "PATCH") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $data =
        questionReadJsonBody();

    if (!array_key_exists(
        "active",
        $data
    )) {
        sendJson([
            "success" => false,
            "message" =>
                "Active value is required."
        ], 400);
    }

    $active =
        (int) $data["active"] === 1
            ? 1
            : 0;

    $stmt = $pdo->prepare("
        UPDATE questions
        SET active = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $active,
        $questionId
    ]);

    if ($stmt->rowCount() === 0) {

        $existsStmt =
            $pdo->prepare("
                SELECT id
                FROM questions
                WHERE id = ?
                LIMIT 1
            ");

        $existsStmt->execute([
            $questionId
        ]);

        if (!$existsStmt->fetch()) {
            sendJson([
                "success" => false,
                "message" =>
                    "Question not found."
            ], 404);
        }
    }

    sendJson([
        "success" => true,
        "message" =>
            $active === 1
                ? "Question activated successfully."
                : "Question deactivated successfully.",
        "active" => (bool) $active
    ]);
}


// =========================================
// ROUTE QUESTION API
// =========================================

function handleQuestionApi($pdo, $uri)
{
    // =========================================
    // /api/questions
    // =========================================

    if ($uri === "/api/questions") {

        if ($_SERVER["REQUEST_METHOD"] === "GET") {
            handleQuestionListApi($pdo);
            return true;
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            handleQuestionCreateApi($pdo);
            return true;
        }

        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }


    // =========================================
    // PATCH /api/questions/{id}/status
    // =========================================

    if (
        preg_match(
            "#^/api/questions/([0-9]+)/status$#",
            $uri,
            $matches
        )
    ) {

        handleQuestionStatusApi(
            $pdo,
            (int) $matches[1]
        );

        return true;
    }


    // =========================================
    // PUT /api/questions/{id}
    // =========================================

    if (
        preg_match(
            "#^/api/questions/([0-9]+)$#",
            $uri,
            $matches
        )
    ) {

        handleQuestionUpdateApi(
            $pdo,
            (int) $matches[1]
        );

        return true;
    }

    return false;
}

?>