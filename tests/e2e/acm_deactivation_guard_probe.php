<?php
/**
 * ACM S8 — staff-guard probe (subprocess helper, NOT a test).
 * ------------------------------------------------------------
 * Called ONLY by acm_deactivation_walkthrough.php: executes the
 * REAL staff endpoint files (user-toggle.php, user-save.php,
 * user-delete.php) in-process with a super_admin session, valid
 * CSRF, and (for delete) the typed phrase + step-up password.
 * Prints the endpoint's JSON (toggle/save); delete redirects,
 * so the walkthrough asserts its side effects on the database.
 *
 * The probe steers config.php at the disposable database by
 * pre-defining the DB_* constants (first definition wins; the
 * env file's later defines no-op for those four and still
 * provide every other secret). The env file is located with
 * config.php's own search order, parsed for the credential
 * literals (never executed before the override is in place).
 *
 * Usage: php tests/e2e/acm_deactivation_guard_probe.php
 *          <dbname> <toggle|save|delete> <targetUserId> <adminUserId> [adminPassword]
 *
 * S10: `toggle0` / `save0` drive the 1→0 direction (staff
 * deactivation closure); the un-suffixed forms keep their S8
 * 0→1 revive-refusal meaning.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dbName = (string)($argv[1] ?? '');
$endpoint = (string)($argv[2] ?? '');
$targetUserId = (int)($argv[3] ?? 0);
$adminUserId = (int)($argv[4] ?? 0);
$adminPassword = (string)($argv[5] ?? '');
if ($dbName === '' || !in_array($endpoint, ['toggle', 'save', 'delete', 'toggle0', 'save0'], true)
    || $targetUserId <= 0 || $adminUserId <= 0
) {
    fwrite(STDERR, "usage: probe.php <dbname> <toggle|save|delete|toggle0|save0> <target> <admin> [password]\n");
    exit(2);
}
if ($targetUserId === $adminUserId) {
    fwrite(STDERR, "target and admin must differ\n");
    exit(2);
}

$root = dirname(__DIR__, 2);

// Locate the env file with config.php's own search order.
$envPath = null;
foreach ([dirname($root), dirname($root, 2), $root] as $dir) {
    foreach (['.fkss_env.php', '.wbws_env.php'] as $name) {
        $cand = $dir . '/' . $name;
        if (is_file($cand)) {
            $envPath = $cand;
            break 2;
        }
    }
}
if ($envPath === null) {
    fwrite(STDERR, "no env file found\n");
    exit(2);
}

// Parse (never execute yet): credential literals only.
$envText = file_get_contents($envPath);
$creds = [];
foreach (['DB_HOST', 'DB_USER', 'DB_PASS'] as $key) {
    if (!preg_match(
        "/define\\(\\s*['\"]" . $key . "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]\\s*\\)/",
        $envText,
        $m
    )) {
        fwrite(STDERR, "cannot parse {$key} from env file\n");
        exit(2);
    }
    $creds[$key] = $m[1];
}

// First definition wins: steer the whole config chain (PDO +
// mysqli) at the disposable database. The env file's own
// DB_* defines below become no-ops; every other secret still
// loads from it.
define('DB_HOST', $creds['DB_HOST']);
define('DB_USER', $creds['DB_USER']);
define('DB_PASS', $creds['DB_PASS']);
define('DB_NAME', $dbName);
// The env file's own DB_* defines warn-and-no-op; swallow ONLY
// those four expected warnings (anything else still surfaces).
set_error_handler(static function (int $no, string $str): bool {
    return strpos($str, 'already defined') !== false;
}, E_WARNING);
require $envPath;
restore_error_handler();

// Minimal POST shape. The AJAX header selects save's JSON path
// (harmless for toggle/delete); the session array starts empty
// so config's revalidation block is skipped at load.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

require $root . '/admin/backend/config.php';

$csrf = bin2hex(random_bytes(16));
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = $adminUserId;
$_SESSION['admin_role'] = 'super_admin';
$_SESSION['csrf_token'] = $csrf;

switch ($endpoint) {
    case 'toggle':
        $_POST['user_id'] = (string)$targetUserId;
        $_POST['action'] = 'activate';
        $_POST['csrf_token'] = $csrf;
        require $root . '/admin/backend/user-toggle.php';
        break;
    case 'toggle0':
        $_POST['user_id'] = (string)$targetUserId;
        $_POST['action'] = 'deactivate';
        $_POST['csrf_token'] = $csrf;
        require $root . '/admin/backend/user-toggle.php';
        break;
    case 'save':
    case 'save0':
        $row = $pdo->prepare('SELECT username, full_name, email, role FROM users WHERE id = :u LIMIT 1');
        $row->execute([':u' => $targetUserId]);
        $user = $row->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            fwrite(STDERR, "target user not found\n");
            exit(2);
        }
        // Resubmit the row unchanged except the 0->1 flip.
        $_POST['user_id'] = (string)$targetUserId;
        $_POST['full_name'] = (string)$user['full_name'];
        $_POST['username'] = (string)$user['username'];
        $_POST['email'] = (string)($user['email'] ?? '');
        $_POST['role'] = (string)$user['role'];
        $_POST['is_active'] = $endpoint === 'save0' ? '0' : '1';
        $_POST['csrf_token'] = $csrf;
        require $root . '/admin/backend/user-save.php';
        break;
    case 'delete':
        if ($adminPassword === '') {
            fwrite(STDERR, "delete needs the admin password\n");
            exit(2);
        }
        $_POST['delete_user_id'] = (string)$targetUserId;
        $_POST['superadmin_password'] = $adminPassword;
        $_POST['confirm_phrase'] = 'DELETE USER';
        $_POST['csrf_token'] = $csrf;
        require $root . '/admin/backend/user-delete.php';
        break;
}
