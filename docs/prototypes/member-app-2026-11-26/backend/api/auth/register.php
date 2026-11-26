<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';


// =========================================
// ONLY POST REQUESTS
// =========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);

    exit;
}


// =========================================
// READ REQUEST DATA
// =========================================

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


// =========================================
// GET VALUES
// =========================================

$studentId = trim($input['student_id'] ?? '');
$password = $input['password'] ?? '';


// =========================================
// VALIDATE
// =========================================

if ($studentId === '' || $password === '') {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Student ID and password are required.'
    ]);

    exit;
}

if (strlen($password) < 8) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Password must be at least 8 characters.'
    ]);

    exit;
}


try {

    // =========================================
    // FIND EXISTING STUDENT
    // =========================================

    $check = $pdo->prepare(
        'SELECT student_id, name, grade, class, password_hash, active
         FROM students
         WHERE student_id = :student_id
         LIMIT 1'
    );

    $check->execute([
        ':student_id' => $studentId
    ]);

    $student = $check->fetch();


    // =========================================
    // STUDENT NOT FOUND
    // =========================================

    if (!$student) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Student was not found.'
        ]);

        exit;
    }


    // =========================================
    // CHECK ACTIVE STATUS
    // =========================================

    if ((int)$student['active'] !== 1) {

        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'This student account is inactive.'
        ]);

        exit;
    }


    // =========================================
    // CHECK WHETHER PASSWORD ALREADY EXISTS
    // =========================================

    if (!empty($student['password_hash'])) {

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'This student already has a password.'
        ]);

        exit;
    }


    // =========================================
    // CREATE PASSWORD HASH
    // =========================================

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    if ($passwordHash === false) {
        throw new Exception('Password hashing failed.');
    }


    // =========================================
    // SAVE PASSWORD
    // =========================================

    $update = $pdo->prepare(
        'UPDATE students
         SET password_hash = :password_hash,
             updated_at = CURRENT_TIMESTAMP
         WHERE student_id = :student_id'
    );

    $update->execute([
        ':password_hash' => $passwordHash,
        ':student_id' => $studentId
    ]);


    // =========================================
    // SUCCESS
    // =========================================

    echo json_encode([
        'success' => true,
        'message' => 'Student account created successfully.',
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
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

}