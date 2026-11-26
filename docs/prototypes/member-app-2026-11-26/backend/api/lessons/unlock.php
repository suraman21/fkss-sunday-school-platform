<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

try {

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

    $lessonId = $input['lesson_id'] ?? null;
    $grade = trim($input['grade'] ?? '');
    $teacherId = trim($input['teacher_id'] ?? '');

    if (!$lessonId || $grade === '' || $teacherId === '') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'lesson_id, grade and teacher_id are required.'
        ]);

        exit;
    }

    // Check that the lesson exists
    $stmt = $pdo->prepare("
        SELECT
            l.id,
            l.title,
            c.id AS chapter_id,
            c.title AS chapter_title,
            s.id AS subject_id,
            s.name AS subject_name,
            s.grade
        FROM lessons l
        INNER JOIN chapters c
            ON l.chapter_id = c.id
        INNER JOIN subjects s
            ON c.subject_id = s.id
        WHERE l.id = ?
          AND s.grade = ?
        LIMIT 1
    ");

    $stmt->execute([
        $lessonId,
        $grade
    ]);

    $lesson = $stmt->fetch();

    if (!$lesson) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Lesson not found for this grade.'
        ]);

        exit;
    }

    // Check whether it is already unlocked
    $stmt = $pdo->prepare("
        SELECT id, unlocked_at
        FROM lesson_unlocks
        WHERE lesson_id = ?
          AND grade = ?
          AND teacher_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $lessonId,
        $grade,
        $teacherId
    ]);

    $existingUnlock = $stmt->fetch();

    if ($existingUnlock) {

        echo json_encode([
            'success' => true,
            'message' => 'Lesson is already unlocked.',
            'already_unlocked' => true,
            'unlock' => [
                'id' => $existingUnlock['id'],
                'lesson_id' => $lessonId,
                'grade' => $grade,
                'teacher_id' => $teacherId,
                'unlocked_at' => $existingUnlock['unlocked_at']
            ]
        ]);

        exit;
    }

    // Create unlock
    $stmt = $pdo->prepare("
        INSERT INTO lesson_unlocks
            (lesson_id, grade, teacher_id)
        VALUES
            (?, ?, ?)
    ");

    $stmt->execute([
        $lessonId,
        $grade,
        $teacherId
    ]);

    $unlockId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Lesson unlocked successfully.',
        'already_unlocked' => false,
        'unlock' => [
            'id' => $unlockId,
            'lesson_id' => $lessonId,
            'grade' => $grade,
            'teacher_id' => $teacherId,
            'unlocked_at' => date('Y-m-d H:i:s')
        ],
        'lesson' => [
            'id' => $lesson['id'],
            'title' => $lesson['title'],
            'chapter_id' => $lesson['chapter_id'],
            'chapter_title' => $lesson['chapter_title'],
            'subject_id' => $lesson['subject_id'],
            'subject_name' => $lesson['subject_name'],
            'grade' => $lesson['grade']
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error.'
    ]);
}