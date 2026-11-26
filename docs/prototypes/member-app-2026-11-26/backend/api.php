<?php

// Never show PHP errors to the browser (they are still written to the PHP error log).
ini_set('display_errors', '0');

// Any uncaught error becomes a safe JSON answer. Details go to the PHP error log only.
set_exception_handler(function (Throwable $e) {
    error_log('Unhandled API error: ' . $e->getMessage());
    $setup = ($e instanceof PDOException)
        && in_array((string) $e->getCode(), ['42S22', '42S02'], true);

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'code'    => $setup ? 'SETUP_INCOMPLETE' : 'SERVER_ERROR',
        'message' => $setup
            ? 'The database is missing required tables or columns. Apply database/migrations/001_stage1_auth_and_unlocks.sql to felege_kidusan_test (see docs/STAGE1_SETUP.md).'
            : 'Something went wrong. Please try again.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// =========================================
// FELEGE KIDUSAN API
// =========================================


// =========================================
// DATABASE
// =========================================

require_once __DIR__ . "/config/database.php";


// =========================================
// JSON RESPONSE HELPER
// =========================================

function sendJson($data, $statusCode = 200)
{
    http_response_code($statusCode);

    header(
        "Content-Type: application/json; charset=utf-8"
    );

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// =========================================
// GET REQUEST PATH
// =========================================

$uri = parse_url(
    $_SERVER["REQUEST_URI"],
    PHP_URL_PATH
);

if ($uri === false || $uri === null) {
    $uri = "/";
}

// =========================================
// SECURITY GATE (Stage 1)
// Decides who may call what, before any handler runs.
// =========================================
require_once __DIR__ . "/lib/security.php";
fk_gate($pdo, $uri);


// =========================================
// LOGIN API
// =========================================
// POST /api/auth/login
// =========================================

function handleLoginApi($pdo, $uri)
{
    if ($uri !== "/api/auth/login") {
        return false;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }


    // =========================================
    // READ JSON REQUEST
    // =========================================

    $rawInput = file_get_contents(
        "php://input"
    );

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


    // =========================================
    // GET LOGIN VALUES
    // =========================================

    $studentId = trim(
        $data["student_id"] ??
        $data["studentId"] ??
        ""
    );

    $password =
        $data["password"] ??
        "";


    // =========================================
    // VALIDATION
    // =========================================

    if ($studentId === "") {
        sendJson([
            "success" => false,
            "message" => "Student ID is required."
        ], 400);
    }

    if ($password === "") {
        sendJson([
            "success" => false,
            "message" => "Password is required."
        ], 400);
    }


    // =========================================
    // FIND STUDENT
    // =========================================

    fk_throttle_check($pdo, $studentId);

    $stmt = $pdo->prepare("
        SELECT
            student_id,
            name,
            grade,
            class,
            password_hash,
            must_change_password,
            active
        FROM students
        WHERE student_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $studentId
    ]);

    $student = $stmt->fetch(
        PDO::FETCH_ASSOC
    );


    // =========================================
    // CHECK STUDENT
    // =========================================

    if (!$student) {
        fk_throttle_fail($pdo, $studentId);
        sendJson([
            "success" => false,
            "message" => "Invalid student ID or password."
        ], 401);
    }


    // =========================================
    // CHECK ACTIVE ACCOUNT
    // =========================================

    if ((int) $student["active"] !== 1) {
        sendJson([
            "success" => false,
            "message" => "This student account is inactive."
        ], 403);
    }


    // =========================================
    // VERIFY PASSWORD
    // =========================================

    if (!password_verify(
        $password,
        $student["password_hash"]
    )) {
        fk_throttle_fail($pdo, $studentId);
        sendJson([
            "success" => false,
            "message" => "Invalid student ID or password."
        ], 401);
    }


    // =========================================
    // LOGIN SUCCESS
    // =========================================

    fk_throttle_clear($pdo, $studentId);
    fk_login_session(
        "student",
        $student["student_id"],
        ((int) $student["must_change_password"] === 1)
    );

    sendJson([
        "success" => true,
        "message" => "Login successful.",
        "mustChangePassword" => ((int) $student["must_change_password"] === 1),
        "student" => [
            "studentId" => $student["student_id"],
            "name" => $student["name"],
            "grade" => $student["grade"],
            "class" => $student["class"]
        ]
    ]);
}


// =========================================
// STUDENT API
// =========================================
// GET /api/student/FK-1001
// =========================================

function handleStudentApi($pdo, $uri)
{
    if (!str_starts_with($uri, "/api/student/")) {
        return false;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $studentId = substr(
        $uri,
        strlen("/api/student/")
    );

    $studentId = urldecode($studentId);

    if ($studentId === "") {
        sendJson([
            "success" => false,
            "message" => "Student ID is required."
        ], 400);
    }

    $stmt = $pdo->prepare("
        SELECT
            student_id,
            name,
            grade,
            class
        FROM students
        WHERE student_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $studentId
    ]);

    $student = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$student) {
        sendJson([
            "success" => false,
            "message" => "Student not found."
        ], 404);
    }

    sendJson([
        "success" => true,
        "student" => [
            "studentId" => $student["student_id"],
            "name" => $student["name"],
            "grade" => $student["grade"],
            "class" => $student["class"]
        ]
    ]);
}


// =========================================
// LESSONS API
// =========================================
// GET /api/lessons/FK-1001
// =========================================

function handleLessonsApi($pdo, $uri)
{
    if (!str_starts_with($uri, "/api/lessons/")) {
        return false;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $studentId = substr(
        $uri,
        strlen("/api/lessons/")
    );

    $studentId = urldecode($studentId);

    if ($studentId === "") {
        sendJson([
            "success" => false,
            "message" => "Student ID is required."
        ], 400);
    }

    $studentStmt = $pdo->prepare("
        SELECT
            student_id,
            name,
            grade,
            class
        FROM students
        WHERE student_id = ?
        LIMIT 1
    ");

    $studentStmt->execute([
        $studentId
    ]);

    $student = $studentStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$student) {
        sendJson([
            "success" => false,
            "message" => "Student not found."
        ], 404);
    }

    $lessonStmt = $pdo->prepare("
        SELECT
            s.id AS subject_id,
            s.name AS subject_name,
            s.grade,

            c.id AS chapter_id,
            c.chapter_number,
            c.title AS chapter_title,
            c.description AS chapter_description,

            l.id AS lesson_id,
            l.lesson_number,
            l.title AS lesson_title,
            l.description AS lesson_description,
            l.sort_order,
            l.status,
            l.created_at,
            l.updated_at,

        (EXISTS (
                SELECT 1 FROM lesson_unlocks lu
                WHERE lu.lesson_id = l.id
                  AND lu.grade = s.grade
                  AND (lu.`class` IS NULL OR lu.`class` = ?)
            )) AS unlocked
        FROM subjects s

        INNER JOIN chapters c
            ON c.subject_id = s.id

        INNER JOIN lessons l
            ON l.chapter_id = c.id

        WHERE s.grade = ?
          AND l.status = 'published'

        ORDER BY
            s.id ASC,
            c.sort_order ASC,
            l.sort_order ASC,
            l.id ASC
    ");

    $lessonStmt->execute([
        $student["class"],
        $student["grade"]
    ]);

    $lessons = $lessonStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    // Locked lessons stay visible in the path but their content is withheld.
    foreach ($lessons as &$lessonRow) {
        $lessonRow["unlocked"] = (bool) $lessonRow["unlocked"];
        if (!$lessonRow["unlocked"]) {
            $lessonRow["lesson_description"] = null;
        }
    }
    unset($lessonRow);

    sendJson([
        "success" => true,
        "studentId" => $studentId,
        "grade" => $student["grade"],
        "lessons" => $lessons
    ]);
}


// =========================================
// TASK ATTACHMENT UPLOAD API
// =========================================
// POST /api/tasks/{task_id}/attachments
// =========================================

function handleTaskAttachmentApi($pdo, $uri)
{
    if (!str_starts_with($uri, "/api/tasks/")) {
        return false;
    }

    $remainingPath = substr(
        $uri,
        strlen("/api/tasks/")
    );

    if (
        !str_ends_with(
            $remainingPath,
            "/attachments"
        )
    ) {
        return false;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    $taskId = substr(
        $remainingPath,
        0,
        -strlen("/attachments")
    );

    $taskId = trim($taskId);

    if (
        $taskId === "" ||
        !ctype_digit($taskId)
    ) {
        sendJson([
            "success" => false,
            "message" => "Valid task ID is required."
        ], 400);
    }

    $taskId = (int) $taskId;

    $taskStmt = $pdo->prepare("
        SELECT
            id,
            title,
            grade
        FROM tasks
        WHERE id = ?
        LIMIT 1
    ");

    $taskStmt->execute([
        $taskId
    ]);

    $task = $taskStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$task) {
        sendJson([
            "success" => false,
            "message" => "Task not found."
        ], 404);
    }

    if (
        !isset($_FILES["file"]) ||
        !is_array($_FILES["file"])
    ) {
        sendJson([
            "success" => false,
            "message" => "No file was uploaded."
        ], 400);
    }

    $file = $_FILES["file"];

    if ($file["error"] !== UPLOAD_ERR_OK) {
        sendJson([
            "success" => false,
            "message" => "File upload failed."
        ], 400);
    }

    $allowedExtensions = [
        "pdf",
        "jpg",
        "jpeg",
        "png",
        "webp",
        "doc",
        "docx"
    ];

    $originalName = basename(
        $file["name"]
    );

    $extension = strtolower(
        pathinfo(
            $originalName,
            PATHINFO_EXTENSION
        )
    );

    if (
        $extension === "" ||
        !in_array(
            $extension,
            $allowedExtensions,
            true
        )
    ) {
        sendJson([
            "success" => false,
            "message" => "File type is not allowed."
        ], 400);
    }

    $maxFileSize = 10 * 1024 * 1024;

    if ($file["size"] > $maxFileSize) {
        sendJson([
            "success" => false,
            "message" =>
                "File is too large. Maximum size is 10 MB."
        ], 400);
    }

    $uploadDirectory =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        "uploads";

    if (!is_dir($uploadDirectory)) {
        if (!mkdir(
            $uploadDirectory,
            0755,
            true
        )) {
            sendJson([
                "success" => false,
                "message" =>
                    "Could not create upload directory."
            ], 500);
        }
    }

    $serverFileName =
        uniqid("task_", true) .
        "." .
        $extension;

    $destination =
        $uploadDirectory .
        DIRECTORY_SEPARATOR .
        $serverFileName;

    if (
        !move_uploaded_file(
            $file["tmp_name"],
            $destination
        )
    ) {
        sendJson([
            "success" => false,
            "message" =>
                "Could not save uploaded file."
        ], 500);
    }

    $filePath =
        "uploads/" .
        $serverFileName;

    $attachmentStmt = $pdo->prepare("
        INSERT INTO task_attachments (
            task_id,
            file_name,
            file_path
        )
        VALUES (?, ?, ?)
    ");

    $attachmentStmt->execute([
        $taskId,
        $originalName,
        $filePath
    ]);

    $attachmentId =
        (int) $pdo->lastInsertId();

    sendJson([
        "success" => true,
        "message" =>
            "Attachment uploaded successfully.",
        "attachment" => [
            "id" => $attachmentId,
            "task_id" => $taskId,
            "file_name" => $originalName,
            "file_path" => $filePath
        ]
    ], 201);
}


// =========================================
// TASKS API
// =========================================
// GET  /api/tasks/FK-1001
// POST /api/tasks
// =========================================

function handleTasksApi($pdo, $uri)
{
    if (str_starts_with($uri, "/api/tasks/")) {

        if ($_SERVER["REQUEST_METHOD"] !== "GET") {
            sendJson([
                "success" => false,
                "message" => "Method not allowed."
            ], 405);
        }

        $studentId = substr(
            $uri,
            strlen("/api/tasks/")
        );

        $studentId = urldecode($studentId);

        if ($studentId === "") {
            sendJson([
                "success" => false,
                "message" => "Student ID is required."
            ], 400);
        }

        $studentStmt = $pdo->prepare("
            SELECT
                student_id,
                name,
                grade
            FROM students
            WHERE student_id = ?
            LIMIT 1
        ");

        $studentStmt->execute([
            $studentId
        ]);

        $student = $studentStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$student) {
            sendJson([
                "success" => false,
                "message" => "Student not found."
            ], 404);
        }

        $taskStmt = $pdo->prepare("
            SELECT
                t.id AS task_id,
                t.title,
                t.description,
                t.subject_id,
                s.name AS subject_name,
                t.grade,
                t.due_date,
                t.created_at

            FROM tasks t

            LEFT JOIN subjects s
                ON s.id = t.subject_id

            WHERE t.grade = ?

            ORDER BY
                t.due_date IS NULL ASC,
                t.due_date ASC,
                t.created_at DESC
        ");

        $taskStmt->execute([
            $student["grade"]
        ]);

        $tasks = $taskStmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        $attachmentStmt = $pdo->prepare("
            SELECT
                id,
                task_id,
                file_name,
                file_path,
                created_at

            FROM task_attachments

            WHERE task_id = ?

            ORDER BY id ASC
        ");

        foreach ($tasks as &$task) {

            $attachmentStmt->execute([
                $task["task_id"]
            ]);

            $task["attachments"] =
                $attachmentStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );
        }

        unset($task);

        sendJson([
            "success" => true,
            "studentId" => $studentId,
            "grade" => $student["grade"],
            "tasks" => $tasks
        ]);
    }

    if ($uri === "/api/tasks") {

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            sendJson([
                "success" => false,
                "message" => "Method not allowed."
            ], 405);
        }

        $rawInput = file_get_contents(
            "php://input"
        );

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

        $title = trim(
            $data["title"] ?? ""
        );

        $description = trim(
            $data["description"] ?? ""
        );

        $subjectId =
            $data["subject_id"] ?? null;

        $grade = trim(
            $data["grade"] ?? ""
        );

        $dueDate =
            $data["due_date"] ?? null;

        if ($title === "") {
            sendJson([
                "success" => false,
                "message" => "Title is required."
            ], 400);
        }

        if (
            $subjectId === null ||
            $subjectId === ""
        ) {
            sendJson([
                "success" => false,
                "message" => "Subject ID is required."
            ], 400);
        }

        if ($grade === "") {
            sendJson([
                "success" => false,
                "message" => "Grade is required."
            ], 400);
        }

        $subjectStmt = $pdo->prepare("
            SELECT
                id,
                name,
                grade

            FROM subjects

            WHERE id = ?

            LIMIT 1
        ");

        $subjectStmt->execute([
            $subjectId
        ]);

        $subject = $subjectStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$subject) {
            sendJson([
                "success" => false,
                "message" => "Subject not found."
            ], 404);
        }

        if ($subject["grade"] !== $grade) {
            sendJson([
                "success" => false,
                "message" =>
                    "Subject does not belong to the selected grade."
            ], 400);
        }

        if (
            $dueDate !== null &&
            $dueDate !== ""
        ) {

            $dateObject = DateTime::createFromFormat(
                "Y-m-d",
                $dueDate
            );

            $validDate =
                $dateObject &&
                $dateObject->format("Y-m-d") === $dueDate;

            if (!$validDate) {
                sendJson([
                    "success" => false,
                    "message" =>
                        "Due date must use YYYY-MM-DD format."
                ], 400);
            }

        } else {
            $dueDate = null;
        }

        $taskStmt = $pdo->prepare("
            INSERT INTO tasks (
                title,
                description,
                subject_id,
                grade,
                due_date
            )

            VALUES (?, ?, ?, ?, ?)
        ");

        $taskStmt->execute([
            $title,
            $description !== ""
                ? $description
                : null,
            $subjectId,
            $grade,
            $dueDate
        ]);

        $taskId =
            (int) $pdo->lastInsertId();

        sendJson([
            "success" => true,
            "message" =>
                "Task created successfully.",

            "task" => [
                "task_id" => $taskId,
                "title" => $title,

                "description" =>
                    $description !== ""
                        ? $description
                        : null,

                "subject_id" =>
                    (int) $subjectId,

                "subject_name" =>
                    $subject["name"],

                "grade" =>
                    $grade,

                "due_date" =>
                    $dueDate
            ]
        ], 201);
    }

    return false;
}


// =========================================
// ROUTE REQUEST
// =========================================
// =========================================
// BULK QUESTION IMPORT API
// =========================================

require_once __DIR__ . "/api/auth_staff.php";
handleStage1Api(
    $pdo,
    $uri
);

require_once __DIR__ . "/api/learning.php";
handleLearningApi(
    $pdo,
    $uri
);

require_once __DIR__ . "/api/extras.php";
handleExtrasApi(
    $pdo,
    $uri
);

require_once __DIR__ . "/api/question_import.php";

handleQuestionImportRouteApi(
    $pdo,
    $uri
);
// =========================================
// QUESTION BANK API
// =========================================

require_once __DIR__ . "/api/question.php";

handleQuestionApi(
    $pdo,
    $uri
);
// =========================================
// QUIZ API
// =========================================

require_once __DIR__ . "/api/quiz.php";

handleQuizApi(
    $pdo,
    $uri
);
handleLoginApi(
    $pdo,
    $uri
);

handleStudentApi(
    $pdo,
    $uri
);

handleLessonsApi(
    $pdo,
    $uri
);

// IMPORTANT:
// Attachment handler must come BEFORE
// the normal tasks handler.

handleTaskAttachmentApi(
    $pdo,
    $uri
);

handleTasksApi(
    $pdo,
    $uri
);


// =========================================
// API ENDPOINT NOT FOUND
// =========================================

sendJson([
    "success" => false,
    "message" => "API endpoint not found."
], 404);

?>