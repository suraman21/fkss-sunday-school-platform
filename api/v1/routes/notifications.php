<?php
/**
 * School API v1 — Notifications (P72 Communication Center, P74 parity)
 *
 *   GET  /notifications/summary            — badge counts + permissions
 *                                            (P74: ETag/304 conditional GET)
 *   GET  /notifications/feed?limit&offset  — alert history (+&unread=1)
 *                                            (P74: +&before_id cursor)
 *   GET  /notifications/announcements      — announcements for me
 *                                            (P74: +&before_id&before_pin cursor,
 *                                             response carries has_more/next_*)
 *   GET  /notifications/threads            — my message threads
 *                                            (P74: +&limit&before_lm&before_id tuple cursor,
 *                                             response carries has_more/next)
 *   GET  /notifications/thread?id=N        — one conversation (marks read)
 *                                            (P74: +&before_id older-page cursor; response
 *                                             carries read_watermark/has_older/oldest_id;
 *                                             ETag/304 — an idle poll writes nothing)
 *   GET  /notifications/targets            — who may I announce to
 *   GET  /notifications/partners           — who may I message
 *   POST /notifications/mark-read          — {id}
 *   POST /notifications/mark-all-read      — {scope: alerts|announcements}
 *   POST /notifications/announcement-read  — {id}
 *   POST /notifications/compose            — announcement (permission-checked)
 *   POST /notifications/thread-start       — {to[], subject, body}
 *   POST /notifications/send-message       — {thread_id, body}
 *   POST /notifications/message-edit       — {message_id, body}   (P74)
 *   POST /notifications/message-delete     — {message_id}         (P74)
 *   POST /notifications/thread-read        — {id}                 (P74)
 *
 * Every staff role may read its own communication; writes go through
 * the same NotificationCenterService the web dashboards use — one
 * writer, one permission matrix, one read-state pivot.
 *
 * P74 compatibility: everything is ADDITIVE — new optional params, new
 * response fields, new actions. Installed app builds keep working;
 * legacy offset pagination stays.
 */

$auth = apiRequireAuth();

// Staff-only: every notification/messaging action is scoped to a staff
// role via NotificationCenterService's ROLE_LABELS/ANNOUNCE_SEND/
// MESSAGE_PARTNERS matrices, all of which contain zero member or parent
// entries. Without this gate those roles fall through the
// "target_roles IS NULL AND target_user_id IS NULL" broadcast branch.
// Allow-list gate — fails closed for any future non-staff role.
apiRequireStaffRole($auth);

$userId = (int)$auth['uid'];
$role = apiRoleOf($auth);

require_once __DIR__ . '/../../../admin/backend/services/NotificationCenterService.php';
use App\Services\NotificationCenterService;

/**
 * P74 — conditional GET for the poll endpoints (same contract as the
 * web admin API). Emits a strong ETag; answers 304 with an EMPTY body
 * when If-None-Match matches (comma lists and weak validators
 * tolerated). MUST only be used with a version that provably covers
 * every byte of the response (summaryVersion/threadVersion). Returns
 * false when the caller should build the full response (ETag header
 * already set for the 200 path).
 */
if (!function_exists('notificationsEtagNotModified')) {
    function notificationsEtagNotModified(string $version, string $prefix): bool
    {
        $etag = '"' . $prefix . '-' . md5($version) . '"';
        $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        foreach (array_map('trim', explode(',', $inm)) as $c) {
            if ($c === '') { continue; }
            if (strcasecmp($c, $etag) === 0 || strcasecmp($c, 'W/' . $etag) === 0) {
                if (!headers_sent()) {
                    http_response_code(304);
                    header('ETag: ' . $etag);
                    header('Cache-Control: private, no-cache');
                }
                exit;   // 304 — no body, no further work
            }
        }
        if (!headers_sent()) {
            header('ETag: ' . $etag);
            header('Cache-Control: private, no-cache');
        }
        return false;
    }
}

$action = $ROUTE['id'] ?? '';
$method = $ROUTE['method'] ?? 'GET';

// ── GET endpoints ────────────────────────────────────────────
if ($method === 'GET') {
    if (isApiRateLimited('notifications_read', 480)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    if ($action === 'summary') {
        // P74: idle polls answer 304 with no body. The version covers
        // every signal the summary depends on; when it cannot be read
        // we skip the shortcut and recompute fully — never a stale 304.
        $sv = NotificationCenterService::summaryVersion($conn, $userId, $role);
        if ($sv !== null && notificationsEtagNotModified($sv, 'ncsum')) {
            exit;   // 304 already sent
        }
        $summary = NotificationCenterService::unreadSummary($conn, $userId, $role);
        $summary['can_announce'] = NotificationCenterService::canAnnounce($role);
        $summary['can_message'] = NotificationCenterService::canMessage($role);
        ok(['status' => 'success', 'summary' => $summary]);
    }

    if ($action === 'feed') {
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $unreadOnly = (($_GET['unread'] ?? '') === '1');
        $type = is_string($_GET['type'] ?? '') ? (string)($_GET['type'] ?? '') : '';
        $beforeId = (int)($_GET['before_id'] ?? 0) ?: null;   // P74 cursor
        $feed = NotificationCenterService::feed($conn, $userId, $role, $limit, $offset, $unreadOnly, $type, $beforeId);
        ok(['status' => 'success'] + $feed);
    }

    if ($action === 'announcements') {
        // P74: cursor paging (before_pin + before_id = the last row's
        // own sort tuple). has_more/next_* tell the UI when a "Load
        // older" control is available. Legacy offset still supported.
        $ann = NotificationCenterService::listAnnouncements(
            $conn, $userId, $role,
            min(100, max(1, (int)($_GET['limit'] ?? 30))), max(0, (int)($_GET['offset'] ?? 0)),
            (int)($_GET['before_id'] ?? 0) ?: null,
            isset($_GET['before_pin']) ? (int)$_GET['before_pin'] : null
        );
        ok([
            'status' => 'success',
            'announcements' => $ann['rows'],
            'has_more' => $ann['has_more'],
            'next_before' => $ann['next_before'],
            'next_pin' => $ann['next_pin'],
        ]);
    }

    if ($action === 'threads') {
        // P74: cursor paging by (last_message_at, id) DESC — the tuple
        // is correct even when two threads share a timestamp.
        $before = null;
        if ((int)($_GET['before_id'] ?? 0) > 0 && is_string($_GET['before_lm'] ?? '')) {
            $before = [(string)$_GET['before_lm'], (int)$_GET['before_id']];
        }
        $t = NotificationCenterService::threadsFor(
            $conn, $userId, min(100, max(1, (int)($_GET['limit'] ?? 50))), $before
        );
        ok([
            'status' => 'success',
            'threads' => $t['threads'],
            'has_more' => $t['has_more'],
            'next' => $t['next'],           // [last_message_at, id] or null
        ]);
    }

    if ($action === 'thread') {
        $threadId = (int)($_GET['id'] ?? 0);
        $beforeId = (int)($_GET['before_id'] ?? 0) ?: null;   // P74 older page
        // P74: the open-conversation poll gets a 304 when nothing in
        // the thread changed. The window variant (newest vs before_id
        // page) is part of the seed — a version match must never 304 a
        // DIFFERENT window. threadVersion is participation-gated: a
        // non-participant gets no ETag and no 304 oracle.
        $tv = NotificationCenterService::threadVersion($conn, $userId, $threadId);
        if ($tv !== null && notificationsEtagNotModified($tv . '|w' . (int)$beforeId, 'ncthr')) {
            exit;   // 304 already sent — zero-write poll
        }
        $result = NotificationCenterService::threadMessages($conn, $userId, $threadId, 200, $beforeId);
        if (!$result['ok']) {
            err($result['error'] ?? 'Not found.', 404);
        }
        // markThreadRead runs ONLY on full responses, so an idle poll
        // performs zero writes.
        NotificationCenterService::markThreadRead($conn, $userId, $threadId);
        ok([
            'status' => 'success',
            'messages' => $result['messages'],
            // read receipts (P73 web, P74 mobile): highest message id read by others
            'read_watermark' => (int)($result['read_watermark'] ?? 0),
            // P74 window metadata for "Load older"
            'has_older' => (bool)($result['has_older'] ?? false),
            'oldest_id' => (int)($result['oldest_id'] ?? 0),
        ]);
    }

    if ($action === 'targets') {
        ok(['status' => 'success'] + NotificationCenterService::announceTargets($conn, $role, $userId));
    }

    if ($action === 'partners') {
        ok(['status' => 'success', 'partners' => NotificationCenterService::messagePartners($conn, $role, $userId)]);
    }

    err('Unknown notifications action.', 404);
}

// ── POST endpoints ───────────────────────────────────────────
if ($method === 'POST') {
    if (isApiRateLimited('notifications_write', 120)) {
        err('Too many requests. Please wait a moment.', 429);
    }
    $body = getBody();

    if ($action === 'mark-read') {
        $id = (int)($body['id'] ?? 0);
        if ($id > 0 && NotificationCenterService::markRead($conn, $userId, $role, $id)) {
            ok(['status' => 'success']);
        }
        err('Could not mark as read.');
    }

    if ($action === 'mark-all-read') {
        $scope = (string)($body['scope'] ?? 'alerts');
        $ok = $scope === 'announcements'
            ? NotificationCenterService::markAllAnnouncementsRead($conn, $userId, $role)
            : NotificationCenterService::markAllRead($conn, $userId, $role);
        if ($ok) {
            ok(['status' => 'success']);
        }
        err('Could not mark all as read.');
    }

    if ($action === 'announcement-read') {
        $id = (int)($body['id'] ?? 0);
        if ($id > 0 && NotificationCenterService::markAnnouncementRead($conn, $userId, $id)) {
            ok(['status' => 'success']);
        }
        err('Could not mark the announcement read.');
    }

    if ($action === 'compose') {
        $roles = [];
        foreach ((array)($body['roles'] ?? []) as $r) {
            $r = trim((string)$r);
            if ($r !== '') { $roles[] = $r; }
        }
        $userIds = [];
        foreach ((array)($body['user_ids'] ?? []) as $u) {
            $u = (int)$u;
            if ($u > 0) { $userIds[] = $u; }
        }
        $result = NotificationCenterService::postAnnouncement(
            $conn, $userId, $role,
            (string)($body['title'] ?? ''),
            (string)($body['body'] ?? ''),
            (string)($body['priority'] ?? 'normal'),
            (string)($body['audience'] ?? 'roles'),
            $roles, $userIds
        );
        if ($result['ok']) {
            ok(['status' => 'success', 'id' => $result['id']]);
        }
        err($result['error'] ?? 'Could not publish.');
    }

    if ($action === 'thread-start') {
        $to = [];
        foreach ((array)($body['to'] ?? []) as $u) {
            $u = (int)$u;
            if ($u > 0) { $to[] = $u; }
        }
        $result = NotificationCenterService::startThread(
            $conn, $userId, $role,
            (string)($body['subject'] ?? ''),
            $to,
            (string)($body['body'] ?? '')
        );
        if ($result['ok']) {
            ok(['status' => 'success', 'id' => $result['id']]);
        }
        err($result['error'] ?? 'Could not start the conversation.');
    }

    if ($action === 'send-message') {
        // O4 (046): client_tag rides along for exactly-once; absent on
        // web + pre-1.4.0 clients (null → tagless insert, same as ever).
        $result = NotificationCenterService::sendMessage(
            $conn, $userId, (int)($body['thread_id'] ?? 0), (string)($body['body'] ?? ''),
            isset($body['client_tag']) ? (string)$body['client_tag'] : null
        );
        if ($result['ok']) {
            // 'replayed' tells the phone's outbox the duplicate was
            // absorbed — same success, no second bubble.
            ok(isset($result['replayed']) && $result['replayed']
                ? ['status' => 'success', 'replayed' => true]
                : ['status' => 'success']);
        }
        $sendCode = (string)($result['code'] ?? 'MESSAGE_SEND_UNAVAILABLE');
        $sendStatuses = [
            'MESSAGE_EMPTY' => 422,
            'MESSAGE_TOO_LONG' => 422,
            'THREAD_FORBIDDEN' => 403,
            'MESSAGE_SEND_UNAVAILABLE' => 500,
        ];
        if (!isset($sendStatuses[$sendCode])) {
            $sendCode = 'MESSAGE_SEND_UNAVAILABLE';
        }
        err($result['error'] ?? 'Could not send.', $sendStatuses[$sendCode],
            ['code' => $sendCode]);
    }

    if ($action === 'message-edit') {
        // P74 — edit one of MY messages (ownership enforced in the
        // service, atomically in the UPDATE's WHERE clause).
        $result = NotificationCenterService::editMessage(
            $conn, $userId, (int)($body['message_id'] ?? 0), (string)($body['body'] ?? '')
        );
        if ($result['ok']) {
            ok(['status' => 'success']);
        }
        err($result['error'] ?? 'Could not edit the message.');
    }

    if ($action === 'message-delete') {
        // P74 — soft-delete one of MY messages; participants see a
        // tombstone, the body is never returned again.
        $result = NotificationCenterService::deleteMessage(
            $conn, $userId, (int)($body['message_id'] ?? 0)
        );
        if ($result['ok']) {
            ok(['status' => 'success']);
        }
        err($result['error'] ?? 'Could not delete the message.');
    }

    if ($action === 'thread-read') {
        // P74 — explicit mark-read without refetching the conversation.
        if (NotificationCenterService::markThreadRead($conn, $userId, (int)($body['id'] ?? 0))) {
            ok(['status' => 'success']);
        }
        err('Not your conversation.', 404);
    }

    err('Unknown notifications action.', 404);
}

err('Method not allowed.', 405);
