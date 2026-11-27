<?php
/**
 * School API v1 — Learning (Members App)
 * ---------------------------------------------------------------------
 * GET  /learning/path                    — the caller's own path: subjects -> units -> lessons
 * GET  /learning/lessons/{id}             — one lesson (only if OPEN to the caller)
 * POST /learning/lessons/{id}/complete    — mark complete (idempotent)
 *
 * WHO THIS SERVES
 *   The `member` role, and only itself. Every endpoint resolves the member id
 *   from the SESSION via apiSelfMemberId() and never accepts an id from the
 *   request — the same rule GET /members/me follows, for the same reason: an
 *   id-taking route that admits the member role is an IDOR waiting for the next
 *   editor to forget the ownership check.
 *
 *   Parents are deliberately NOT served here yet. A parent reading a child's
 *   lesson progress needs a different question answered ("what has my child
 *   done?"), and the verified-link rule in apiParentCanSeeMember decides it.
 *   Adding it later is a new route, not a loosened one.
 *
 * WHY THE UNLOCK RULES ARE NOT IN THIS FILE
 *   They live in App\Services\MembersLearningPath so they can be tested without
 *   a database. This file is an HTTP adapter: parse, authorize, delegate,
 *   respond. See that class for the rule set and the one deliberate
 *   interpretation (unit unlock is an ADDITIONAL gate, not an alternative).
 */

$auth = apiRequireAuth();
apiRequireRole($auth, ['member']);

// Resolve identity from the token, never from the URL.
$selfMemberId = apiSelfMemberId($conn, $auth);
if ($selfMemberId <= 0) {
    // Deny by default: no link, no lesson. The message says nothing about
    // whether the member exists.
    err('Your member record is not linked to this account yet.', 403, ['code' => 'NO_MEMBER_LINK']);
}

if (!class_exists('\App\Services\MembersLearningPath')) {
    $learningPathService = dirname(__DIR__, 3) . '/admin/backend/services/MembersLearningPath.php';
    if (is_file($learningPathService)) {
        require_once $learningPathService;
    }
}
if (!class_exists('\App\Services\MembersLearningPath')) {
    // A missing service is a deployment fault, not a client error. Say so
    // without leaking the path.
    reportInternalError('MembersLearningPath service missing', 'class not found');
    err('The learning service is unavailable.', 503, ['code' => 'SERVICE_UNAVAILABLE']);
}

$learning = new \App\Services\MembersLearningPath($pdo, 2);
$learningId = $ROUTE['id'];

// ============================================================
// GET /learning/path
// ============================================================
if ($method === 'GET' && $learningId === null && $ROUTE['sub'] === null) {
    if (isApiRateLimited('learning_path', 90)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    $subjects = $learning->pathForMember($selfMemberId);

    // Summary counts so the app can paint a progress ring without walking the
    // whole tree. Computed server-side because the client should not be the
    // authority on how much work is done.
    $totalLessons = 0;
    $completedLessons = 0;
    foreach ($subjects as $subject) {
        foreach ($subject['units'] as $unit) {
            foreach ($unit['lessons'] as $lesson) {
                $totalLessons++;
                if ($lesson['completed']) {
                    $completedLessons++;
                }
            }
        }
    }

    ok([
        'subjects' => $subjects,
        'summary' => [
            'subjects' => count($subjects),
            'lessons_total' => $totalLessons,
            'lessons_completed' => $completedLessons,
            'percent' => $totalLessons > 0
                ? (int)round(($completedLessons / $totalLessons) * 100)
                : 0,
        ],
    ]);
}

// ============================================================
// GET /learning/lessons/{id}
// ============================================================
if ($method === 'GET' && is_int($learningId) && $ROUTE['sub'] === null) {
    if (isApiRateLimited('learning_lesson', 120)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    // The server decides whether this lesson is open. The client's copy of the
    // `open` flag is a convenience and is never trusted.
    if (!$learning->isLessonOpen($selfMemberId, $learningId)) {
        err(
            'This lesson is not open yet. Finish the earlier lessons, or ask your teacher to unlock it.',
            403,
            ['code' => 'LESSON_LOCKED']
        );
    }

    $stmt = $pdo->prepare(
        'SELECT l.id, l.number, l.title_am, l.title_en, l.body_am, l.est_minutes,'
        . ' u.id AS unit_id, u.number AS unit_number, u.title_am AS unit_title_am,'
        . ' s.id AS subject_id, s.code AS subject_code, s.name_am AS subject_name_am'
        . ' FROM members_lessons l'
        . ' JOIN members_units u ON u.id = l.unit_id'
        . ' JOIN members_subjects s ON s.id = u.subject_id'
        . ' WHERE l.id = ? AND l.is_active = 1 LIMIT 1'
    );
    if (!$stmt) {
        err('The lesson could not be loaded.', 503);
    }
    $stmt->bind_param('i', $learningId);
    $stmt->execute();
    $lesson = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$lesson) {
        // Deliberately the same status as a locked lesson would be if it were
        // hiding existence — but a 404 here is honest and the id is not secret
        // to the member who is walking their own path.
        err('That lesson was not found.', 404, ['code' => 'LESSON_NOT_FOUND']);
    }

    $progress = $pdo->prepare(
        "SELECT status, started_at, completed_at FROM members_lesson_progress"
        . " WHERE member_id = ? AND lesson_id = ? LIMIT 1"
    );
    $progressState = null;
    if ($progress) {
        $progress->bind_param('ii', $selfMemberId, $learningId);
        $progress->execute();
        $row = $progress->get_result()->fetch_assoc();
        $progress->close();
        if ($row) {
            $progressState = [
                'status' => (string)$row['status'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
            ];
        }
    }

    ok([
        'lesson' => [
            'id' => (int)$lesson['id'],
            'number' => (int)$lesson['number'],
            'title_am' => (string)$lesson['title_am'],
            'title_en' => $lesson['title_en'] !== null ? (string)$lesson['title_en'] : null,
            'body_am' => $lesson['body_am'] !== null ? (string)$lesson['body_am'] : null,
            'est_minutes' => $lesson['est_minutes'] !== null ? (int)$lesson['est_minutes'] : null,
            'unit' => [
                'id' => (int)$lesson['unit_id'],
                'number' => (int)$lesson['unit_number'],
                'title_am' => (string)$lesson['unit_title_am'],
            ],
            'subject' => [
                'id' => (int)$lesson['subject_id'],
                'code' => (string)$lesson['subject_code'],
                'name_am' => (string)$lesson['subject_name_am'],
            ],
        ],
        'progress' => $progressState,
    ]);
}

// ============================================================
// POST /learning/lessons/{id}/complete
// ============================================================
if ($method === 'POST' && is_int($learningId) && $ROUTE['sub'] === 'complete') {
    if (isApiRateLimited('learning_complete', 60)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    if (!$learning->isLessonOpen($selfMemberId, $learningId)) {
        err('This lesson is not open yet.', 403, ['code' => 'LESSON_LOCKED']);
    }

    // IDEMPOTENT ON PURPOSE.
    // The unique key (member_id, lesson_id) makes a retry safe, and a retry is
    // what a 2G connection produces: the write lands, the response is lost, the
    // app retries. Turning that into a 409 would tell a student their correctly
    // completed lesson failed.
    $stmt = $pdo->prepare(
        "INSERT INTO members_lesson_progress (member_id, lesson_id, status, completed_at)"
        . " VALUES (?, ?, 'completed', NOW())"
        . " ON DUPLICATE KEY UPDATE status = 'completed',"
        . " completed_at = COALESCE(completed_at, NOW())"
    );
    if (!$stmt) {
        err('The lesson could not be marked complete.', 503);
    }
    $stmt->bind_param('ii', $selfMemberId, $learningId);
    if (!$stmt->execute()) {
        $stmt->close();
        err('The lesson could not be marked complete.', 503);
    }
    $affected = $stmt->affected_rows;
    $stmt->close();

    // affected_rows: 1 = inserted, 2 = updated, 0 = already exactly this row.
    // 0 is success, not failure — that is the whole point of COALESCE above.
    ok([
        'lesson_id' => $learningId,
        'completed' => true,
        'already_completed' => $affected === 0,
        'message' => 'Lesson marked complete.',
    ]);
}

err(
    'No handler matched for ' . $method . ' /learning/' . ($ROUTE['id'] ?? '') . '.',
    404
);
