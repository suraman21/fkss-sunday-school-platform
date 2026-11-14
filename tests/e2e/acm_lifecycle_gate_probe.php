<?php
/**
 * ACM S7 — per-request gate probe (subprocess helper, NOT a test).
 * ---------------------------------------------------------------
 * Called ONLY by acm_lifecycle_walkthrough.php: builds the
 * access-token payload for a user and runs the REAL per-request
 * gate (apiRevalidateAuthorizationScope, enforced). Prints
 * GATE_OK when the gate passes; otherwise the gate itself prints
 * its JSON error (e.g. ACCOUNT_DISABLED) and exits. Read-only:
 * suspends nothing, writes nothing.
 *
 * Usage: php tests/e2e/acm_lifecycle_gate_probe.php <dbname> <userId>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dbName = (string)($argv[1] ?? '');
$userId = (int)($argv[2] ?? 0);
if ($dbName === '' || $userId <= 0) {
    fwrite(STDERR, "usage: probe.php <dbname> <userId>\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$envFile = $root . '/.fkss_env.php';
if (!is_file($envFile)) {
    fwrite(STDERR, "missing .fkss_env.php\n");
    exit(2);
}
require $envFile;

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
if ($conn->connect_errno) {
    fwrite(STDERR, "mysqli connect failed\n");
    exit(2);
}

require $root . '/api/v1/core/response.php';
require $root . '/api/v1/core/auth.php';

$stmt = $conn->prepare(
    'SELECT role, username, full_name, authorization_version FROM users WHERE id = ? LIMIT 1'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!is_array($user)) {
    fwrite(STDERR, "user not found\n");
    exit(2);
}

apiRevalidateAuthorizationScope([
    'uid' => $userId,
    'av' => max(1, (int)($user['authorization_version'] ?? 1)),
    'rol' => (string)$user['role'],
    'usr' => (string)$user['username'],
    'nam' => (string)$user['full_name'],
], true);
echo "GATE_OK\n";
