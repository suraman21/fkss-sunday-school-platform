<?php
// =========================================
// FELEGE KIDUSAN - SECURITY HELPERS (Stage 1)
// =========================================
// Sessions, roles, the central request "gate",
// and login throttling. Used by api.php and router.php.
// =========================================

function fk_send(array $data, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------
// SESSIONS
// -----------------------------------------
function fk_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";

    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    session_name("FKSESSID");
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "secure"   => $https,
        "httponly" => true,
        "samesite" => "Lax"
    ]);
    session_start();

    // Sign out after 2 hours without activity.
    $now = time();
    if (isset($_SESSION["last_seen"]) && ($now - (int) $_SESSION["last_seen"]) > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION["last_seen"] = $now;
}

// Returns ["role" => student|teacher|admin, "id" => string, "must_change" => bool] or null.
function fk_current_user(): ?array
{
    fk_session_start();
    if (empty($_SESSION["user"]) || !is_array($_SESSION["user"])) {
        return null;
    }
    return $_SESSION["user"];
}

function fk_login_session(string $role, string $id, bool $mustChange): void
{
    fk_session_start();
    session_regenerate_id(true);
    $_SESSION["user"] = [
        "role"        => $role,
        "id"          => $id,
        "must_change" => $mustChange
    ];
    $_SESSION["last_seen"] = time();
}

function fk_logout_session(): void
{
    fk_session_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
}

// -----------------------------------------
// LOGIN THROTTLING (5 failures / 15 minutes per ID + IP)
// If the login_attempts table does not exist yet,
// throttling is skipped and a note is written to the PHP error log.
// -----------------------------------------
function fk_throttle_check(PDO $pdo, string $identifier): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM login_attempts
            WHERE identifier = ?
              AND ip = ?
              AND attempted_at > (NOW() - INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$identifier, $_SERVER["REMOTE_ADDR"] ?? ""]);
        $count = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("login throttle unavailable: " . $e->getMessage());
        return;
    }

    if ($count >= 5) {
        fk_send([
            "success" => false,
            "message" => "Too many failed attempts. Please wait 15 minutes and try again."
        ], 429);
    }
}

function fk_throttle_fail(PDO $pdo, string $identifier): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)");
        $stmt->execute([$identifier, $_SERVER["REMOTE_ADDR"] ?? ""]);
    } catch (PDOException $e) {
        error_log("login throttle unavailable: " . $e->getMessage());
    }
}

function fk_throttle_clear(PDO $pdo, string $identifier): void
{
    try {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ? AND ip = ?");
        $stmt->execute([$identifier, $_SERVER["REMOTE_ADDR"] ?? ""]);
    } catch (PDOException $e) {
        error_log("login throttle unavailable: " . $e->getMessage());
    }
}

// -----------------------------------------
// PASSWORD RULES
// -----------------------------------------
function fk_password_ok(string $password): bool
{
    return mb_strlen($password, "UTF-8") >= 8 && strlen($password) <= 200;
}

// -----------------------------------------
// STAFF SCOPE: may this teacher/admin manage this grade/class?
// $class === "" means "the whole grade".
// -----------------------------------------
function fk_staff_can_manage(PDO $pdo, array $user, string $grade, string $class): bool
{
    if ($user["role"] === "admin") {
        return true;
    }
    if ($user["role"] !== "teacher") {
        return false;
    }

    if ($class === "") {
        // Whole-grade actions need a whole-grade scope row.
        $stmt = $pdo->prepare("
            SELECT 1 FROM staff_scopes
            WHERE staff_id = ? AND grade = ? AND `class` IS NULL
            LIMIT 1
        ");
        $stmt->execute([$user["id"], $grade]);
    } else {
        // A class action is allowed by a whole-grade scope or that exact class.
        $stmt = $pdo->prepare("
            SELECT 1 FROM staff_scopes
            WHERE staff_id = ? AND grade = ? AND (`class` IS NULL OR `class` = ?)
            LIMIT 1
        ");
        $stmt->execute([$user["id"], $grade, $class]);
    }

    return (bool) $stmt->fetchColumn();
}

// -----------------------------------------
// THE GATE
// Runs before any /api/ handler. It decides WHO may call WHAT,
// on the server, regardless of what the frontend shows.
// -----------------------------------------
function fk_gate(PDO $pdo, string $uri): void
{
    fk_session_start();

    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";

    // Public endpoints: the two login routes only.
    if ($uri === "/api/auth/login" || $uri === "/api/auth/staff-login") {
        return;
    }

    $user = fk_current_user();
    if ($user === null) {
        fk_send([
            "success" => false,
            "code"    => "AUTH_REQUIRED",
            "message" => "Please sign in."
        ], 401);
    }

    // Account endpoints: any signed-in user (handlers apply their own rules).
    $accountRoutes = ["/api/auth/me", "/api/auth/logout", "/api/auth/change-password"];
    if (in_array($uri, $accountRoutes, true)) {
        return;
    }

    // A student who still has a temporary password may only change it.
    if ($user["role"] === "student" && !empty($user["must_change"])) {
        fk_send([
            "success" => false,
            "code"    => "PASSWORD_CHANGE_REQUIRED",
            "message" => "Please create your own password first."
        ], 403);
    }

    $isStaff = in_array($user["role"], ["teacher", "admin"], true);
    $forbidden = function () {
        fk_send([
            "success" => false,
            "code"    => "FORBIDDEN",
            "message" => "You do not have permission to do this."
        ], 403);
    };

    // Admin-only area.
    if (str_starts_with($uri, "/api/admin/")) {
        if ($user["role"] !== "admin") {
            $forbidden();
        }
        return;
    }

    // Teacher/admin area.
    if (str_starts_with($uri, "/api/staff/")) {
        if (!$isStaff) {
            $forbidden();
        }
        return;
    }

    // Question bank and import: staff only (includes answer keys).
    if ($uri === "/api/questions" || str_starts_with($uri, "/api/questions/")) {
        if (!$isStaff) {
            $forbidden();
        }
        return;
    }

    // Creating tasks and uploading task attachments: staff only.
    if ($uri === "/api/tasks" && $method !== "GET") {
        if (!$isStaff) {
            $forbidden();
        }
        return;
    }
    if (preg_match("#^/api/tasks/[^/]+/attachments$#", $uri)) {
        if (!$isStaff) {
            $forbidden();
        }
        return;
    }

    // Routes whose last segment is a student ID:
    // a student may only use their OWN ID. (Teacher views of student data come in a later stage.)
    if (preg_match("#^/api/(student|lessons|tasks|quiz/options|quiz/history)/([^/]+)$#", $uri, $m)) {
        $requestedId = urldecode($m[2]);
        if ($user["role"] === "student") {
            if (!hash_equals((string) $user["id"], $requestedId)) {
                $forbidden();
            }
            return;
        }
        if ($user["role"] === "admin") {
            return;
        }
        $forbidden();
    }

    // Quiz actions: students only, and the student_id in the body must be their own.
    if (in_array($uri, ["/api/quiz/start", "/api/quiz/answer", "/api/quiz/complete"], true)) {
        if ($user["role"] !== "student") {
            $forbidden();
        }
        $body = json_decode((string) file_get_contents("php://input"), true);
        $claimed = is_array($body) ? trim((string) ($body["student_id"] ?? $body["studentId"] ?? "")) : "";
        if ($claimed === "") {
            fk_send([
                "success" => false,
                "message" => "student_id is required."
            ], 400);
        }
        if (!hash_equals((string) $user["id"], $claimed)) {
            $forbidden();
        }
        return;
    }
}
