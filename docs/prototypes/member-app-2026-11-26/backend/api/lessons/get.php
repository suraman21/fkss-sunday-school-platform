<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

        http_response_code(405);

        echo json_encode([
            'success' => false,
            'message' => 'Only GET requests are allowed.'
        ]);

        exit;
    }

    $grade = trim($_GET['grade'] ?? '');

    if ($grade === '') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Grade is required.'
        ]);

        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            s.id AS subject_id,
            s.name AS subject_name,
            s.grade,

            c.id AS unit_id,
            c.chapter_number AS unit_number,
            c.title AS unit_title,

            l.id AS lesson_id,
            l.lesson_number,
            l.title AS lesson_title,
            l.description,
            l.status,

            CASE
                WHEN lu.id IS NOT NULL THEN 1
                ELSE 0
            END AS is_unlocked,

            lu.unlocked_at

        FROM subjects s

        INNER JOIN chapters c
            ON c.subject_id = s.id

        INNER JOIN lessons l
            ON l.chapter_id = c.id

        LEFT JOIN lesson_unlocks lu
            ON lu.lesson_id = l.id
            AND lu.grade = s.grade

        WHERE s.grade = ?
          AND l.status = 'published'

        ORDER BY
            s.id,
            c.sort_order,
            l.sort_order
    ");

    $stmt->execute([$grade]);

    $lessons = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'grade' => $grade,
        'lessons' => $lessons
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error.'
    ]);
}