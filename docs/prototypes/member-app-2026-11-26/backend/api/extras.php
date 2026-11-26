<?php
// =========================================
// FELEGE KIDUSAN - STUDENT EXTRAS API (basic version, for the dashboard developer to extend)
// =========================================
// GET  /api/notifications                  announcements for this student (+ unread count)
// POST /api/notifications/{id}/read        mark one as read
// POST /api/notifications/read-all         mark all as read
// GET  /api/attendance                     this student's attendance records + totals
// GET  /api/permissions                    this student's permission requests
// POST /api/permissions                    create a request {request_date, reason}
// GET  /api/past-papers                    past papers for this student's grade, newest year first
// GET  /api/past-papers/{id}/file          the paper file (signed-in students of that grade only)
//
// The admin/teacher dashboards are expected to WRITE: notifications, attendance, past_papers,
// and to review permission_requests. See docs/HANDOVER_FOR_DEVELOPER.md.
// =========================================

require_once __DIR__ . "/learning.php";

function handleExtrasApi(PDO $pdo, string $uri)
{
    // ---------------------------------
    // NOTIFICATIONS
    // ---------------------------------
    if ($uri === "/api/notifications") {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);
        try {
            $stmt = $pdo->prepare("
                SELECT n.id, n.title, n.body, n.created_at, (r.notification_id IS NOT NULL) AS is_read
                FROM notifications n
                LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.student_id = ?
                WHERE (n.student_id IS NULL OR n.student_id = ?)
                  AND (n.audience_grade IS NULL OR n.audience_grade = ?)
                  AND (n.audience_class IS NULL OR n.audience_class = ?)
                ORDER BY n.created_at DESC, n.id DESC
                LIMIT 30
            ");
            $stmt->execute([$student["student_id"], $student["student_id"], $student["grade"], $student["class"]]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        $unread = 0;
        foreach ($rows as &$r) {
            $r["id"] = (int) $r["id"];
            $r["is_read"] = (int) $r["is_read"] === 1;
            if (!$r["is_read"]) {
                $unread++;
            }
        }
        unset($r);
        fk_send(["success" => true, "unread" => $unread, "notifications" => $rows]);
    }

    if (preg_match("#^/api/notifications/(\d+)/read$#", $uri, $m)) {
        fk_require_method("POST");
        $student = fk_learning_student($pdo);
        try {
            // Only notifications this student can actually see may be marked.
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO notification_reads (student_id, notification_id)
                SELECT ?, n.id FROM notifications n
                WHERE n.id = ?
                  AND (n.student_id IS NULL OR n.student_id = ?)
                  AND (n.audience_grade IS NULL OR n.audience_grade = ?)
                  AND (n.audience_class IS NULL OR n.audience_class = ?)
            ");
            $stmt->execute([$student["student_id"], (int) $m[1], $student["student_id"], $student["grade"], $student["class"]]);
        } catch (PDOException $e) {
            fk_server_error($e);
        }
        fk_send(["success" => true]);
    }

    if ($uri === "/api/notifications/read-all") {
        fk_require_method("POST");
        $student = fk_learning_student($pdo);
        try {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO notification_reads (student_id, notification_id)
                SELECT ?, n.id FROM notifications n
                WHERE (n.student_id IS NULL OR n.student_id = ?)
                  AND (n.audience_grade IS NULL OR n.audience_grade = ?)
                  AND (n.audience_class IS NULL OR n.audience_class = ?)
            ");
            $stmt->execute([$student["student_id"], $student["student_id"], $student["grade"], $student["class"]]);
        } catch (PDOException $e) {
            fk_server_error($e);
        }
        fk_send(["success" => true]);
    }

    // ---------------------------------
    // ATTENDANCE
    // percent = (present + late) / all recorded days. The developer may replace this
    // rule if the school calculates it differently.
    // ---------------------------------
    if ($uri === "/api/attendance") {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);
        try {
            $sum = $pdo->prepare("SELECT status, COUNT(*) c FROM attendance WHERE student_id = ? GROUP BY status");
            $sum->execute([$student["student_id"]]);
            $counts = ["present" => 0, "absent" => 0, "late" => 0, "permission" => 0];
            foreach ($sum->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row["status"]] = (int) $row["c"];
            }

            $rec = $pdo->prepare("SELECT attendance_date, status, note FROM attendance WHERE student_id = ? ORDER BY attendance_date DESC LIMIT 60");
            $rec->execute([$student["student_id"]]);
            $records = $rec->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            fk_server_error($e);
        }

        $total = array_sum($counts);
        fk_send([
            "success" => true,
            "counts"  => $counts,
            "total"   => $total,
            // null (not 0) when nothing has been recorded yet
            "percent" => $total > 0 ? (int) round(($counts["present"] + $counts["late"]) * 100 / $total) : null,
            "records" => $records
        ]);
    }

    // ---------------------------------
    // PERMISSION REQUESTS
    // ---------------------------------
    if ($uri === "/api/permissions") {
        $student = fk_learning_student($pdo);
        $method = $_SERVER["REQUEST_METHOD"] ?? "GET";

        if ($method === "POST") {
            $data = fk_read_json();
            $date = trim((string) ($data["request_date"] ?? ""));
            $reason = trim((string) ($data["reason"] ?? ""));

            $d = DateTime::createFromFormat("Y-m-d", $date);
            if (!$d || $d->format("Y-m-d") !== $date) {
                fk_send(["success" => false, "message" => "Please choose a valid date."], 400);
            }
            if ($reason === "" || mb_strlen($reason, "UTF-8") > 500) {
                fk_send(["success" => false, "message" => "Please write a reason (up to 500 characters)."], 400);
            }

            try {
                $pending = $pdo->prepare("SELECT COUNT(*) FROM permission_requests WHERE student_id = ? AND status = 'pending'");
                $pending->execute([$student["student_id"]]);
                if ((int) $pending->fetchColumn() >= 5) {
                    fk_send(["success" => false, "message" => "You already have 5 pending requests."], 429);
                }
                $ins = $pdo->prepare("INSERT INTO permission_requests (student_id, request_date, reason) VALUES (?, ?, ?)");
                $ins->execute([$student["student_id"], $date, $reason]);
            } catch (PDOException $e) {
                fk_server_error($e);
            }
            fk_send(["success" => true, "message" => "Request sent."], 201);
        }

        fk_require_method("GET");
        try {
            $stmt = $pdo->prepare("
                SELECT id, request_date, reason, status, review_note, created_at, reviewed_at
                FROM permission_requests WHERE student_id = ? ORDER BY created_at DESC, id DESC LIMIT 30
            ");
            $stmt->execute([$student["student_id"]]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            fk_server_error($e);
        }
        fk_send(["success" => true, "requests" => $rows]);
    }

    // ---------------------------------
    // PAST PAPERS
    // ---------------------------------
    if ($uri === "/api/past-papers") {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);
        try {
            $stmt = $pdo->prepare("
                SELECT id, subject, exam_year, title, file_name
                FROM past_papers WHERE grade = ? ORDER BY exam_year DESC, subject ASC, id ASC
            ");
            $stmt->execute([$student["grade"]]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            fk_server_error($e);
        }
        fk_send(["success" => true, "papers" => $rows]);
    }

    if (preg_match("#^/api/past-papers/(\d+)/file$#", $uri, $m)) {
        fk_require_method("GET");
        $student = fk_learning_student($pdo);
        try {
            $stmt = $pdo->prepare("SELECT file_name, file_path FROM past_papers WHERE id = ? AND grade = ? LIMIT 1");
            $stmt->execute([(int) $m[1], $student["grade"]]);
            $paper = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            fk_server_error($e);
        }
        if (!$paper) {
            fk_send(["success" => false, "message" => "File not found."], 404);
        }

        $base = realpath(__DIR__ . "/../../uploads");
        $rel = ltrim(preg_replace("#^/?uploads/#", "", str_replace("\\", "/", (string) $paper["file_path"])), "/");
        $full = $base ? realpath($base . DIRECTORY_SEPARATOR . $rel) : false;
        if (!$full || strncmp($full, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0 || !is_file($full)) {
            fk_send(["success" => false, "message" => "File not found."], 404);
        }

        $types = ["pdf" => "application/pdf", "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png"];
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        session_write_close();
        header("Content-Type: " . ($types[$ext] ?? "application/octet-stream"));
        header("X-Content-Type-Options: nosniff");
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string) $paper["file_name"]));
        header("Content-Length: " . filesize($full));
        readfile($full);
        exit;
    }

    return false;
}
