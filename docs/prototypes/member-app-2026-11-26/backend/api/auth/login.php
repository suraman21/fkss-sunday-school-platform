<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);

    exit;
}

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid request data.'
    ]);

    exit;
}

$studentId = trim($input['student_id'] ?? '');
$password = $input['password'] ?? '';

if ($studentId === '' || $password === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Student ID and password are required.'
    ]);

    exit;
}

try {

    $stmt = $pdo->prepare(
        'SELECT
            student_id,
            name,
            grade,
            class,
            password_hash,
            active
         FROM students
         WHERE student_id = :student_id
         LIMIT 1'
    );

    $stmt->execute([
        ':student_id' => $studentId
    ]);

    $student = $stmt->fetch();

    if (!$student) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid student ID or password.'
        ]);

        exit;
    }

    if ((int)$student['active'] !== 1) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'This student account is inactive.'
        ]);

        exit;
    }

    if (
        empty($student['password_hash']) ||
        !password_verify($password, $student['password_hash'])
    ) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid student ID or password.'
        ]);

        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Login successful.',
        'student' => [
            'student_id' => $student['student_id'],
            'name' => $student['name'],
            'grade' => $student['grade'],
            'class' => $student['class']
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Server error.'
    ]);

    exit;
}