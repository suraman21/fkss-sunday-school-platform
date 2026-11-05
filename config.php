<?php
/**
 * ============================================================
 * School Management System - UNIFIED Configuration
 * ============================================================
 * 
 * This is the MAIN config file. All other files should use this.
 * Database configuration
 * 
 * Last Updated: December 2025
 * ============================================================
 */

// ============================================================
// PREVENT DIRECT ACCESS (Security)
// ============================================================
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    die('Direct access not allowed');
}

// Canonicalize browser document navigation only when Apache's page routing
// is active. Legacy POST/AJAX/API requests and rewrite-disabled hosts are
// deliberately unchanged. No environment/database access is needed here.
require_once __DIR__ . '/admin/backend/services/BrowserPageRoutes.php';
if (PHP_SAPI !== 'cli') {
    $browserPageLocation = \App\Services\BrowserPageRoutes::navigationRedirect($_SERVER);
    if ($browserPageLocation !== null) {
        header('Cache-Control: no-store');
        header('Location: ' . $browserPageLocation, true, 302);
        exit;
    }
}

// ============================================================
// LOAD SECRETS FROM ENV FILE (outside web root)
// ============================================================
// Secrets file lives ABOVE public_html so it's never web-accessible.
// NOTE: school_config.php isn't loaded yet, so we list candidate env filenames here.
// Checks each name in each location — works for any school deployment.
$_envFileNames = ['.fkss_env.php', '.wbws_env.php'];
$_envBaseDirs = [
    dirname(__DIR__),       // /home/user/ (production)
    dirname(__DIR__, 2),    // two levels up (some hosting layouts)
    __DIR__,                // fallback: same dir (dev only — NOT recommended)
];
$_envPaths = [];
foreach ($_envBaseDirs as $_d) {
    foreach ($_envFileNames as $_n) {
        $_envPaths[] = $_d . '/' . $_n;
    }
}
$_envLoaded = false;
foreach ($_envPaths as $_envPath) {
    if (file_exists($_envPath)) {
        require_once $_envPath;
        $_envLoaded = true;
        break;
    }
}
if (!$_envLoaded) {
    // FAIL CLOSED. We must NOT run with guessable/placeholder secrets:
    // a fixed fallback JWT_SECRET would let anyone forge login tokens, and a
    // placeholder DB password just produces confusing errors. If the secrets
    // file is missing, stop with a clear setup message instead of limping on
    // insecurely. (There is no live data until this file exists.)
    error_log('CRITICAL: secrets env file not found. Refusing to start. Expected one of: ' . implode(', ', $_envPaths));
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    die(
        '<div style="font-family:sans-serif;max-width:640px;margin:3rem auto;padding:1.5rem;border:1px solid #ddd;border-radius:10px">'
        . '<h2 style="color:#b91c1c">Setup required: secrets file missing</h2>'
        . '<p>The system cannot start because its secret configuration file was not found. '
        . 'This file holds the database password and security keys and must live <strong>above</strong> the public web folder.</p>'
        . '<p>Create a file named <code>.fkss_env.php</code> in your account home folder '
        . '(one level above <code>public_html</code>) using the template <code>env.example.php</code> '
        . 'included with this project, then reload this page.</p>'
        . '</div>'
    );
}

// ============================================================
// SCHOOL BRANDING (loaded from school_config.php)
// ============================================================
require_once __DIR__ . '/school_config.php';
// Fail-soft guarded fallbacks: a deployment whose school_config.php drifts
// behind the codebase must degrade gracefully, never throw "undefined
// constant" fatals on PHP 8. Real config values always win.
require_once __DIR__ . '/branding_defaults.php';

// ============================================================
// SITE CONFIGURATION (legacy aliases — school_config.php defines the real ones)
// ============================================================
if (!defined('SITE_URL'))       define('SITE_URL', 'https://localhost');
if (!defined('ADMIN_URL'))      define('ADMIN_URL', SITE_URL . '/admin');
if (!defined('SITE_NAME'))      define('SITE_NAME', defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School');
if (!defined('SITE_NAME_AMHARIC')) define('SITE_NAME_AMHARIC', defined('SCHOOL_NAME_AMHARIC') ? SCHOOL_NAME_AMHARIC : '');

// ============================================================
// FILE PATHS
// ============================================================
define('ROOT_PATH', __DIR__);
define('ADMIN_PATH', __DIR__ . '/admin');
define('UPLOADS_PATH', __DIR__ . '/admin/uploads');

// ── Asset cache-busting version ─────────────────────────────────────────────
// Appended as ?v= to local stylesheet links (mobile.css chain) so a release
// always invalidates every browser's cached CSS. The Academic-Year modal bug
// (2026-10-08) shipped as fresh HTML + a months-old cached mobile.css: the
// z-index design tokens never loaded and the page chrome painted ABOVE the
// open modal, making its Save button unclickable. Bump this on every release
// that touches any local CSS/JS asset.
define('SSMS_ASSET_VER', '20261008');

require_once ADMIN_PATH . '/backend/services/FeatureGate.php';

if (!function_exists('feature_enabled')) {
    function feature_enabled(string $feature): bool {
        return \App\Services\FeatureGate::isEnabled($feature);
    }
}

// ============================================================
// JWT / API TOKEN SECRET
// ============================================================
// Loaded from env file (outside web root)
// If JWT_SECRET is not defined, the env file failed to load
if (!defined('JWT_SECRET')) {
    error_log('CRITICAL: JWT_SECRET not defined! Check env file');
    define('JWT_SECRET', bin2hex(random_bytes(32))); // emergency random — tokens won't persist across requests
}

// ============================================================
// SESSION CONFIGURATION
// ============================================================
require_once __DIR__ . '/backend/core/browser.php';
$isHttps = ssms_request_is_https();
ssms_start_browser_session();

// Session Timeout (30 minutes = 1800 seconds)
define('SESSION_TIMEOUT', 1800);

/**
 * Detect if this request is an AJAX/fetch() call.
 * When AJAX requests hit session timeout, we MUST return JSON — not a 302 redirect.
 */
function _isAjaxRequest() {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    if (!empty($_SERVER['HTTP_ACCEPT']) && 
        strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) return true;
    if (!empty($_SERVER['CONTENT_TYPE']) && 
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) return true;
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) return true;
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    if (strpos($scriptPath, '/backend/api/') !== false || strpos($scriptPath, '/api/v1/') !== false) return true;
    $script = basename($scriptPath);
    if (strpos($script, 'api_') === 0 || strpos($script, 'info_register') === 0 ||
        strpos($script, 'info_manage') === 0 || strpos($script, 'info_archive') === 0 ||
        strpos($script, 'info_restore') === 0 || strpos($script, 'info_get_') === 0 ||
        strpos($script, 'api_check') === 0) return true;
    return false;
}

// Check for session timeout (only if user is logged in)
// Skip for API requests — they use JWT tokens, not sessions
if (isset($_SESSION['LAST_ACTIVITY']) && isset($_SESSION['admin_logged_in']) && !defined('WBWS_API_REQUEST')) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > SESSION_TIMEOUT) {
        // Session expired
        _invalidateAdminSession();
        
        // Only redirect if not on login-related pages
        $currentPage = basename($_SERVER['PHP_SELF']);
        $loginPages = ['index.php', 'login.php']; // Pages that don't need redirect
        
        if (!in_array($currentPage, $loginPages, true) && _isPrivilegedBrowserArea()) {
            // CRITICAL FIX: Return JSON for AJAX requests instead of redirecting
            if (_isAjaxRequest()) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(401);
                }
                echo json_encode([
                    'status'  => 'session_expired',
                    'message' => 'Your session has expired. Please log in again.',
                    'action'  => 'reload'
                ]);
                exit();
            }
            
            // Normal page request → redirect to login
            header('Location: ' . ssms_app_url('admin/index.php') . '?timeout=1');
            exit();
        }
        ssms_start_browser_session();
    }
}

// Update last activity time (only if logged in)
if (isset($_SESSION['admin_logged_in'])) {
    $_SESSION['LAST_ACTIVITY'] = time();
}

// ============================================================
// TIMEZONE
// ============================================================
date_default_timezone_set('Africa/Addis_Ababa');

// ============================================================
// ERROR REPORTING
// ============================================================
// For Development (shows errors):
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// For Production (hides errors, logs them):
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . '/error.log');

// ============================================================
// SECURITY HEADERS
// ============================================================
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    // Disable the obsolete browser XSS auditor; context-aware encoding and CSP
    // are reliable, while legacy auditors could create their own bypasses.
    header('X-XSS-Protection: 0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cross-Origin-Opener-Policy: same-origin');

    // This initial enforceable policy blocks plugin content, hostile base tags,
    // cross-site framing, and off-site form posts without breaking the legacy
    // inline scripts/styles. Script/style nonces can be tightened page by page.
    $contentSecurityPolicy = "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'";
    if (!empty($isHttps)) {
        $contentSecurityPolicy .= '; upgrade-insecure-requests';
        header('Strict-Transport-Security: max-age=31536000');
    }
    header('Content-Security-Policy: ' . $contentSecurityPolicy);
}

// ============================================================
// DATABASE CONNECTIONS
// ============================================================

// --- MySQLi Connection (used by most pages) ---
$conn = null;
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        throw new Exception('MySQLi connection failed: ' . $conn->connect_error);
    }
    
    // Use UTF-8 for Amharic support
    $conn->set_charset('utf8mb4');
    
} catch (Exception $e) {
    error_log($e->getMessage());
    // Don't die here - let individual pages handle errors
}

// --- PDO Connection (used by some pages like users.php) ---
$pdo = null;
// PHP 8.4+ exposes the MySQL driver constants on Pdo\Mysql; the PDO::MYSQL_ATTR_*
// aliases are deprecated since PHP 8.5. Same value on every version.
$ssmsMysqlInitCommand = class_exists('Pdo\Mysql', false)
    ? \Pdo\Mysql::ATTR_INIT_COMMAND
    : constant('PDO::MYSQL_ATTR_INIT_COMMAND');
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            $ssmsMysqlInitCommand => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
} catch (PDOException $e) {
    error_log("PDO connection failed: " . $e->getMessage());
    // Don't die here - let individual pages handle errors
}

/** End a privileged browser session and expire its cookie. */
function _invalidateAdminSession() {
    ssms_destroy_browser_session();
}

/** True for routes that must stop after an invalid admin session is cleared. */
function _isPrivilegedBrowserArea() {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return strpos($script, '/admin/') !== false || strpos($script, '/monitor/') !== false
        || strpos($script, '/backend/') !== false || strpos($script, '/frontend/') !== false;
}

// Reconcile privileged session claims with the current database account at a
// bounded interval. Public requests merely lose a stale admin cookie; admin and
// monitor requests fail closed with the response format they expect.
if (!defined('WBWS_API_REQUEST') && !empty($_SESSION['admin_logged_in'])) {
    $guardResult = ['valid' => false, 'reason' => 'revalidation_unavailable'];
    if ($pdo instanceof PDO) {
        try {
            require_once ROOT_PATH . '/admin/backend/services/AdminSessionGuard.php';
            $guard = new \App\Services\AdminSessionGuard($pdo);
            $guardResult = $guard->revalidate($_SESSION);
        } catch (Throwable $error) {
            error_log('Admin session revalidation failed.');
        }
    }

    if (empty($guardResult['valid'])) {
        $isPrivilegedArea = _isPrivilegedBrowserArea();
        $isJsonRequest = function_exists('_isAjaxRequest') && _isAjaxRequest();
        $unavailable = ($guardResult['reason'] ?? '') === 'revalidation_unavailable';
        _invalidateAdminSession();

        if ($isPrivilegedArea) {
            if ($isJsonRequest) {
                if (!headers_sent()) {
                    http_response_code($unavailable ? 503 : 401);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode([
                    'status' => $unavailable ? 'error' : 'session_expired',
                    'message' => $unavailable
                        ? 'Authentication verification is temporarily unavailable.'
                        : 'Your session is no longer valid. Please sign in again.',
                    'action' => 'reload',
                ]);
                exit;
            }
            header('Location: ' . ssms_app_url('admin/index.php') . '?error=' . rawurlencode(
                $unavailable
                    ? 'Authentication verification is temporarily unavailable.'
                    : 'Your session changed or expired. Please sign in again.'
            ));
            exit;
        }

        // Public pages may still need a fresh anonymous CSRF session.
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Sanitize user input to prevent XSS attacks
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(strip_tags(trim($data ?? '')), ENT_QUOTES, 'UTF-8');
}

/**
 * Safe escape function for output (handles NULL values - PHP 8+ compatible)
 * Use this instead of htmlspecialchars() directly
 * 
 * @param mixed $value The value to escape
 * @param string $default Default value if null/empty
 * @return string Escaped string safe for HTML output
 */
function e($value, $default = '') {
    if ($value === null || $value === '') {
        return $default;
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Safe escape with fallback display text
 * e.g., esc($member['phone'], 'Not provided')
 */
function esc($value, $fallback = '---') {
    if ($value === null || trim((string)$value) === '') {
        return $fallback;
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// CSRF PROTECTION
// ============================================================

/**
 * Generate CSRF token and store in session
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Get hidden input field with CSRF token
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
}

/**
 * Validate CSRF token from POST request
 * @param string|null $token Optional token to validate (if null, reads from $_POST['csrf_token'])
 */
function validateCsrf($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }
    if (!is_string($token) || $token === '' || !is_string($_SESSION['csrf_token'] ?? null) || $_SESSION['csrf_token'] === '') {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate a hidden CSRF token input field for forms.
 * Call inside <form> tags: <?= csrf_field() ?>
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8')
        . '">';
}

/**
 * Require valid CSRF token (dies on failure)
 */
function requireCsrf() {
    if (!validateCsrf()) {
        http_response_code(403);
        die(json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh and try again.']));
    }
}

// ============================================================
// INPUT VALIDATION HELPERS
// ============================================================

/**
 * Validate date format (YYYY-MM-DD)
 * Returns the date string if valid, or $default if invalid/empty
 */
function validateDate($input, $default = null) {
    if ($input === null || trim((string)$input) === '') return $default;
    $d = trim((string)$input);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $default;
    $parts = explode('-', $d);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) return $default;
    return $d;
}

/**
 * Validate month format (YYYY-MM)
 */
function validateMonth($input, $default = null) {
    if ($input === null || trim((string)$input) === '') return $default;
    $d = trim((string)$input);
    if (!preg_match('/^\d{4}-\d{2}$/', $d)) return $default;
    $parts = explode('-', $d);
    if ((int)$parts[1] < 1 || (int)$parts[1] > 13) return $default; // 13 for Ethiopian calendar
    return $d;
}

/**
 * Validate and sanitize a positive monetary amount
 * Returns float if valid, null if invalid
 */
function validateAmount($input) {
    if ($input === null || trim((string)$input) === '') return null;
    $val = filter_var($input, FILTER_VALIDATE_FLOAT);
    if ($val === false || $val < 0 || $val > 99999999.99) return null;
    return round($val, 2);
}

/**
 * Validate password strength through the shared account-domain policy.
 * Returns array of error messages (empty = valid).
 */
function validatePassword($password) {
    require_once ROOT_PATH . '/admin/backend/services/PasswordPolicy.php';
    return \App\Services\PasswordPolicy::errors($password);
}

/**
 * Validate username format
 * Only letters, numbers, underscores, dots. 3-50 chars.
 */
function validateUsername($username) {
    $username = trim($username);
    if (strlen($username) < 3 || strlen($username) > 50) {
        return 'Username must be 3-50 characters.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
        return 'Username can only contain letters, numbers, dots and underscores.';
    }
    return null; // valid
}

/**
 * Validate Ethiopian phone number (09xxxxxxxx or +2519xxxxxxxx)
 * Returns cleaned number or null if invalid
 */
function validatePhone($input) {
    if ($input === null || trim((string)$input) === '') return null;
    $phone = preg_replace('/[\s\-\(\)]+/', '', trim((string)$input));
    // Accept: 09xxxxxxxx, +2519xxxxxxxx, 2519xxxxxxxx
    if (preg_match('/^(?:\+?251|0)(9\d{8})$/', $phone, $m)) {
        return '0' . $m[1]; // Normalize to 09xxxxxxxx
    }
    // Also accept non-Ethiopian formats loosely (7+ digits)
    if (preg_match('/^\+?\d{7,15}$/', $phone)) {
        return $phone;
    }
    return null;
}

/**
 * Validate email (loose — just basic format check)
 */
function validateEmail($input) {
    if ($input === null || trim((string)$input) === '') return null;
    $email = trim((string)$input);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/**
 * Validate enum/whitelist value
 * Returns the value if in the allowed list, or $default
 */
function validateEnum($input, array $allowed, $default = null) {
    $val = trim((string)($input ?? ''));
    return in_array($val, $allowed, true) ? $val : $default;
}

/**
 * Safe integer from user input (returns $default if not numeric)
 */
function safeInt($input, $default = 0) {
    if ($input === null || $input === '') return $default;
    $val = filter_var($input, FILTER_VALIDATE_INT);
    return ($val !== false) ? $val : $default;
}

/**
 * Validate and enforce CSRF for API POST requests
 * Call at the top of any API file that handles POST data
 * Checks both form field and X-CSRF-TOKEN header
 */
/** Enforce the HTTP method independently of CSRF for state-changing actions. */
function requirePostActions(string $action, array $writeActions): void {
    if (in_array($action, $writeActions, true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        jsonResponse(['status' => 'error', 'message' => 'This action requires POST.'], 405);
    }
}

function requireCsrfForPost() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '';
    if (!$token && !empty($_SERVER['HTTP_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_CSRF_TOKEN'];
    }
    if (!$token && !empty($_SERVER['REDIRECT_HTTP_X_CSRF_TOKEN'])) {
        $token = $_SERVER['REDIRECT_HTTP_X_CSRF_TOKEN'];
    }
    if (!$token) {
        $raw = @file_get_contents('php://input');
        if ($raw) {
            $json = @json_decode($raw, true);
            if (is_array($json) && !empty($json['csrf_token'])) {
                $token = $json['csrf_token'];
            }
        }
    }
    if (!validateCsrf($token)) {
        http_response_code(403);
        die(json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh the page.']));
    }
}

// ============================================================
// RATE LIMITING (Simple file-based for login attempts)
// ============================================================

/**
 * Check if IP is rate limited
 * @param string $action Action name (e.g., 'login')
 * @param int $maxAttempts Max attempts allowed
 * @param int $windowSeconds Time window in seconds
 * @return bool True if rate limited (blocked), false if allowed
 */
function isRateLimited($action = 'login', $maxAttempts = 5, $windowSeconds = 300) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = ROOT_PATH . '/admin/uploads/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    
    $file = $cacheDir . '/rate_' . md5($action . '_' . $ip) . '.json';
    $data = ['attempts' => 0, 'first_attempt' => time()];
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
        
        // Reset if window has passed
        if (time() - $data['first_attempt'] > $windowSeconds) {
            $data = ['attempts' => 0, 'first_attempt' => time()];
        }
    }
    
    return $data['attempts'] >= $maxAttempts;
}

/**
 * Record an attempt for rate limiting
 */
function recordAttempt($action = 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = ROOT_PATH . '/admin/uploads/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    
    $file = $cacheDir . '/rate_' . md5($action . '_' . $ip) . '.json';
    $data = ['attempts' => 0, 'first_attempt' => time()];
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
        if (time() - $data['first_attempt'] > 300) {
            $data = ['attempts' => 0, 'first_attempt' => time()];
        }
    }
    
    $data['attempts']++;
    file_put_contents($file, json_encode($data));
}

/**
 * Clear rate limit for IP (call on successful login)
 */
function clearRateLimit($action = 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = ROOT_PATH . '/admin/uploads/cache';
    $file = $cacheDir . '/rate_' . md5($action . '_' . $ip) . '.json';
    if (file_exists($file)) @unlink($file);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Get current user's role
 */
function getUserRole() {
    return $_SESSION['admin_role'] ?? null;
}

/**
 * Check if user has specific role(s)
 */
function hasRole($allowedRoles) {
    if (!isLoggedIn()) {
        return false;
    }
    $allowedRoles = (array)$allowedRoles;
    return in_array($_SESSION['admin_role'], $allowedRoles);
}

/**
 * Require user to be logged in (redirect if not, JSON if AJAX)
 */
function requireAuth() {
    if (!isLoggedIn()) {
        if (function_exists('_isAjaxRequest') && _isAjaxRequest()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(401);
            }
            echo json_encode(['status' => 'session_expired', 'message' => 'Not authenticated.', 'action' => 'reload']);
            exit;
        }
        header('Location: ' . ssms_app_url('admin/index.php'));
        exit;
    }
}

/**
 * Require specific role(s)
 */
function requireRole($allowedRoles) {
    requireAuth();
    if (!hasRole($allowedRoles)) {
        header('Location: ' . ssms_app_url('admin/index.php') . '?error=access_denied');
        exit;
    }
}

/**
 * Redirect helper
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * JSON response helper for AJAX requests
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Record diagnostic detail server-side without exposing it in HTTP responses.
 * Returns a short correlation ID suitable for support logs.
 *
 * @param mixed $error Throwable, database error string, or null
 */
function reportInternalError($context, $error = null) {
    try {
        $reference = bin2hex(random_bytes(6));
    } catch (Throwable $ignored) {
        $reference = substr(hash('sha256', uniqid('', true)), 0, 12);
    }

    $context = preg_replace('/[\r\n]+/', ' ', (string)$context);
    $detail = $error instanceof Throwable ? $error->getMessage() : (string)$error;
    $detail = preg_replace('/[\r\n]+/', ' ', $detail);
    if (strlen($detail) > 2000) {
        $detail = substr($detail, 0, 2000) . '…';
    }
    // S1 correlation: when this runs inside an API v1 request, join the log
    // line to the request the client saw (X-Request-Id) and to the client's
    // own attempt id. Both are opaque, bounded and validated at the edge, so
    // neither can inject log content. Admin/non-API callers are unaffected.
    $correlation = '';
    if (function_exists('apiRequestId')) {
        $correlation = ' [req:' . apiRequestId() . ']';
        if (function_exists('apiClientAttemptId')) {
            $attemptId = apiClientAttemptId();
            if ($attemptId !== '') {
                $correlation .= ' [attempt:' . $attemptId . ']';
            }
        }
    }

    error_log('[SSMS:' . $reference . ']' . $correlation . ' ' . $context . ($detail !== '' ? ': ' . $detail : ''));
    return $reference;
}

/**
 * Get user-friendly error message
 */
function getErrorMessage($code) {
    $messages = [
        'access_denied' => 'You do not have permission to access this page.',
        'timeout' => 'Your session has expired. Please login again.',
        'invalid_credentials' => 'Invalid username or password.',
        'account_inactive' => 'Your account is inactive. Contact administrator.',
    ];
    return $messages[$code] ?? 'An error occurred.';
}

// ============================================================
// STRUCTURED API ERROR HANDLING (audit patch 8)
// Enterprise pattern: every failure returns a stable JSON envelope
//   { status:'error', code:'machine_readable', message:'friendly',
//     error_ref:'SSMS:xxxxxxxxxxxx' (server errors only), details?:{...} }
// plus a correct HTTP status. The message is safe to show to any user;
// internals go to the log under error_ref so support can correlate.
// ============================================================

/**
 * Emit a structured API error and stop.
 *
 * @param string $message user-safe, actionable message
 * @param int    $http    HTTP status (422 validation, 409 conflict, 500 server)
 * @param string $code    machine-readable code for clients
 * @param array  $details optional extra context (e.g. ['field'=>'subject_name'])
 */
function respondApiError(string $message, int $http = 422, string $code = 'validation_error', array $details = []): void
{
    if (!headers_sent()) {
        http_response_code($http);
    }
    $payload = ['status' => 'error', 'code' => $code, 'message' => $message];
    if ($details !== []) {
        $payload['details'] = $details;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Map a database/storage failure to a user-safe message + HTTP status.
 * Never leaks SQL, table or column names to the client.
 *
 * @return array{message:string, http:int, code:string}
 */
function mapDbErrorForApi(Throwable $e): array
{
    $msg = $e->getMessage();
    if ($e instanceof \mysqli_sql_exception || stripos($msg, 'mysqli') !== false || stripos($msg, 'SQLSTATE') !== false) {
        if (stripos($msg, 'Data too long') !== false || stripos($msg, '1406') !== false || stripos($msg, '22001') !== false) {
            return [
                'message' => 'One of the fields is too long for the system. Please shorten the longest entries and try again.',
                'http' => 422, 'code' => 'field_too_long',
            ];
        }
        if (stripos($msg, 'Duplicate entry') !== false || stripos($msg, '1062') !== false || stripos($msg, '23000') !== false) {
            return [
                'message' => 'A record with the same unique value already exists. Please use a different name or code.',
                'http' => 409, 'code' => 'duplicate',
            ];
        }
        if (stripos($msg, 'foreign key') !== false || stripos($msg, '1451') !== false || stripos($msg, '1452') !== false) {
            return [
                'message' => 'This record is linked to other data, so it cannot be changed this way. Remove the links first.',
                'http' => 409, 'code' => 'linked_records',
            ];
        }
        if (stripos($msg, 'Deadlock') !== false || stripos($msg, '1213') !== false) {
            return [
                'message' => 'The server was busy for a moment. Please try again — your data was not saved twice.',
                'http' => 503, 'code' => 'retry',
            ];
        }
    }
    return [
        'message' => 'Server error. Please try again.',
        'http' => 500, 'code' => 'server_error',
    ];
}

/**
 * Uniform handler for the top-level catch of an admin API endpoint:
 * logs internals with a correlation reference and answers with the
 * structured envelope (friendly message + error_ref for support).
 */
function respondApiThrowable(string $context, Throwable $e): void
{
    $ref = reportInternalError($context, $e);
    $mapped = mapDbErrorForApi($e);
    if (!headers_sent()) {
        http_response_code($mapped['http']);
    }
    echo json_encode([
        'status' => 'error',
        'code' => $mapped['code'],
        'message' => $mapped['message'],
        'error_ref' => 'SSMS:' . $ref,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// ROLE DEFINITIONS (for reference)
// ============================================================
/*
Available roles:
- super_admin    : Full system access
- school_admin   : School-level admin
- info_dept      : Information department (member management)
- edu_dept       : Education department
- finance_dept   : Finance department
- material_dept  : Material department
*/

// ============================================================
// DATABASE SCHEMA OWNERSHIP
// ============================================================
// Runtime requests never inspect or mutate schema. Apply the versioned SQL
// migrations during deployment (including sql/012_runtime_schema_baseline.sql)
// before serving the updated application.

// ============================================================
// OUTPUT COMPRESSION (for slow internet connections)
// ============================================================
// Skip gzip for API endpoints (they handle their own output)
// Detection: check script path, not headers (fetch() sends Accept:*/* not application/json)
$_currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
$_isApiRequest = (
    strpos($_currentScript, '/backend/') !== false ||
    strpos($_currentScript, '_api') !== false ||
    strpos($_currentScript, 'api_') !== false ||
    strpos($_currentScript, '/api/') !== false ||
    defined('WBWS_API_REQUEST')
);

if (!$_isApiRequest) {
    if (!ob_start("ob_gzhandler")) {
        ob_start();
    }
}

// ============================================================
// CONFIGURATION COMPLETE
// ============================================================

// ============================================================
// ERROR MONITORING SYSTEM (Arkeon Monitor)
// ============================================================
// Catches all PHP errors, logs to DB, sends Telegram alerts
// Dashboard: {SITE_URL}/monitor/
$_monitorPath = ROOT_PATH . '/monitor/error_monitor.php';
if (feature_enabled('monitor') && file_exists($_monitorPath)) {
    require_once $_monitorPath;
}

// ============================================================
// ACADEMIC YEAR CONTEXT (single source of truth for "which year")
// ============================================================
// Defines ay_resolve()/ay_active_year()/ay_require_writable()/etc.
// Loaded here so every page and API can resolve the effective year.
$_ayPath = ROOT_PATH . '/admin/backend/academic_year.php';
if (file_exists($_ayPath)) {
    require_once $_ayPath;
}

// ============================================================
// CENTRALIZED ACCESS CONTROL (must run AFTER auth helpers above)
// ============================================================
// This single guard enforces "who may open which admin page".
// It only acts on /admin/ and /backend/ scripts; the public site,
// the mobile API, and cron jobs are exempt inside the guard itself.
$_accessControlPath = ROOT_PATH . '/admin/access_control.php';
if (file_exists($_accessControlPath)) {
    require_once $_accessControlPath;
}

