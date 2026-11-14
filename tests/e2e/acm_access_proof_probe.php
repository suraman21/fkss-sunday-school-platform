<?php
/**
 * ACM S9 — access-proof probe (subprocess helper, NOT a test).
 * ---------------------------------------------------------------
 * Called ONLY by acm_access_proof_walkthrough.php: runs the REAL
 * apiRequireAuth() for one synthetic request (method + URI +
 * Bearer token + optional DPoP header) and reports the verdict.
 * Prints PROBE_OK uid=<id> when the gate passes; otherwise the
 * gate itself prints its JSON error (e.g. ACCESS_PROOF_INVALID)
 * and exits (err() always exits 0, so the walkthrough parses
 * stdout -- a line starting with '{' -- never the exit code).
 *
 * The probe steers config.php at the disposable database by
 * pre-defining the DB_* constants (first definition wins; the
 * S8 guard-probe trick). The env file is located with
 * config.php's own search order, parsed for the credential
 * literals (never executed before the override is in place).
 *
 * Usage: php tests/e2e/acm_access_proof_probe.php
 *          <dbname> <bearerToken> <dpopProof|-> <method> <uri>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dbName = (string)($argv[1] ?? '');
$token = (string)($argv[2] ?? '');
$dpop = (string)($argv[3] ?? '-');
$method = strtoupper((string)($argv[4] ?? 'GET'));
$uri = (string)($argv[5] ?? '/');
if ($dbName === '' || $token === '' || $method === '' || $uri === '') {
    fwrite(STDERR, "usage: probe.php <dbname> <token> <dpop|-> <method> <uri>\n");
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
// mysqli) at the disposable database.
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

// One synthetic request (production require set: index.php
// loads config + response + database + auth before routing).
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
if ($dpop !== '-') {
    $_SERVER['HTTP_DPOP'] = $dpop;
}

require $root . '/admin/backend/config.php';
require_once $root . '/api/v1/core/response.php';
require_once $root . '/api/v1/core/database.php';
require_once $root . '/api/v1/core/auth.php';

$auth = apiRequireAuth();
echo "\nPROBE_OK uid=" . (int)($auth['uid'] ?? 0) . "\n";
