<?php
/**
 * CLI-only cleanup of member/parent accounts that were provisioned but
 * never activated (audit finding 9.7, 2026-11-26).
 *
 * Recommended deployment-owned schedule:
 *   17 4 * * * umask 077 && /usr/local/bin/php "/path/to/SSMS/admin/backend/acm_pending_expiry.php" >> "/home/ACCOUNT/acm_pending_expiry.log" 2>&1
 *
 * THE PROBLEM
 * -----------
 * AccountProvisioner::provision() writes `users.is_active = 0` and a
 * `pending` link in acm_person_accounts. Nothing ever moves that pair
 * unless the holder completes the device ceremony. If they never do, the
 * row is permanent: admin/backend/user-delete.php refuses to hard-delete
 * any user that has an ACM link at all, precisely so devices, sessions and
 * ceremony history cannot be orphaned. So an abandoned or declined
 * activation leaves a `users` row forever.
 *
 * WHY THIS DEACTIVATES RATHER THAN DELETES
 * ----------------------------------------
 * A pending account is indistinguishable from a disabled one at the login
 * gate, so the cleanup has to make a decision rather than tidy a table.
 * Setting `users.is_active = 1` would HAND THE ACCOUNT OUT; nothing else is
 * required to log in. So this job does exactly what a super_admin
 * deactivation does — is_active=0, link ->revoked, history row — which is
 * the terminal state AccountProvisioner::isSuspendedState() refuses to
 * revive, so re-entry is a fresh activation ticket. That preserves the
 * audit trail (089/090/098) instead of shredding it.
 *
 * THE RACE (important)
 * --------------------
 * DeviceService::completeFull() commits the activation transaction
 * (is_active=1 + link pending->active) and only afterwards calls
 * markActivated(), which writes the ticket state. The link flip is
 * deliberately lenient: a 0-row flip only error_logs, it never throws,
 * because bookkeeping must not brick a completed ceremony.
 * DeviceService::userIdFor() resolves members/parents via
 * `status IN ('pending','active')`.
 *
 * Together those mean a link this job revokes between the commit and
 * markActivated() leaves a user who IS active but whose in-flight ceremony
 * has just been flipped to revoked. user-toggle.php and user-save.php both
 * refuse a 0->1 flip on a revoked link, not a 1->0 one, so the account
 * keeps working — but its link says revoked, and a later re-provision would
 * be refused by hasAccount().
 *
 * This job therefore re-checks `users.is_active` for every candidate INSIDE
 * the transaction and skips any account that became active in the meantime.
 * It also skips accounts that are not genuinely untouched: a password
 * change or a recorded failure means a human is still working on it.
 *
 * SAFETY
 * ------
 * - Default is a DRY RUN. Nothing is written without --apply.
 * - --apply implies --commit: one transaction, all rows or none, with
 *   SELECT ... FOR UPDATE so concurrent runs and a concurrent ceremony
 *   serialise against the same rows.
 * - Only links whose status is exactly 'pending' are touched.
 * - An account with a device row of ANY status, a consumed grant, or a
 *   password change since provisioning is never touched.
 * - Every revoked link gets an acm_account_suspensions history row with
 *   action='expired' and an officer-readable reason, so the console shows
 *   why the account went away.
 * - Exit code 0 for a clean run, 1 for any failure, 2 for a refused
 *   argument, so cron mail surfaces real problems only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('WBWS_API_REQUEST', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../acm/autoload.php';

const ACM_EXPIRY_DEFAULT_DAYS = 30;
const ACM_EXPIRY_MAX_DAYS = 365;
const ACM_EXPIRY_DEFAULT_BATCH = 200;
const ACM_EXPIRY_MAX_BATCH = 2000;

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$days = ACM_EXPIRY_DEFAULT_DAYS;
$batch = ACM_EXPIRY_DEFAULT_BATCH;

foreach ($args as $a) {
    if (strpos($a, '--days=') === 0) {
        $raw = trim(substr($a, 7));
        if ($raw === '' || !ctype_digit($raw)) {
            fwrite(STDERR, "Refused: --days must be a positive integer.\n");
            exit(2);
        }
        $days = (int)$raw;
    }
    if (strpos($a, '--batch=') === 0) {
        $raw = trim(substr($a, 8));
        if ($raw === '' || !ctype_digit($raw)) {
            fwrite(STDERR, "Refused: --batch must be a positive integer.\n");
            exit(2);
        }
        $batch = (int)$raw;
    }
    if ($a !== '--apply' && strpos($a, '--days=') !== 0 && strpos($a, '--batch=') !== 0) {
        fwrite(STDERR, "Refused: unknown argument '" . $a . "'.\n");
        exit(2);
    }
}

if ($days < 1 || $days > ACM_EXPIRY_MAX_DAYS) {
    fwrite(STDERR, 'Refused: --days must be between 1 and ' . ACM_EXPIRY_MAX_DAYS . ".\n");
    exit(2);
}
if ($batch < 1 || $batch > ACM_EXPIRY_MAX_BATCH) {
    fwrite(STDERR, 'Refused: --batch must be between 1 and ' . ACM_EXPIRY_MAX_BATCH . ".\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_OFF);
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    fwrite(STDERR, "FATAL: database connection failed.\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

// Pre-ACM deployment: nothing to expire. Exit clean, never fail a cron
// run for a feature that is simply not installed.
$tbl = $conn->query("SELECT 1 FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'acm_person_accounts' LIMIT 1");
if (!$tbl || $tbl->num_rows !== 1) {
    fwrite(STDOUT, json_encode([
        'skipped' => 'acm_person_accounts is absent (pre-ACM deployment)',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}

/**
 * Candidate selection, shared by the dry run and the apply path so the two
 * can never disagree about what "abandoned" means.
 *
 * A candidate is a `pending` link, older than $days, attached to a user
 * that is NOT active, whose password has not been changed since
 * provisioning (a password change means the holder got as far as
 * establishCredentials and may still be working), with no device row of
 * any status, no consumed grant, and no earlier history row.
 */
function acm_expiry_candidates(mysqli $conn, int $days, int $batch, bool $forUpdate): array
{
    // Only reference password_changed_at when the column actually exists;
    // naming it in the WHERE clause unconditionally would fail on a
    // database that predates it, even with a NULL alias in the SELECT.
    $pwGuard = '';
    if (acm_expiry_column_exists($conn, 'users', 'password_changed_at')) {
        $pwGuard = ' AND (u.password_changed_at IS NULL OR u.password_changed_at <= pa.created_at)';
    }
    $lock = $forUpdate ? ' FOR UPDATE' : '';

    $sql = "SELECT pa.id AS link_id, pa.user_id, pa.person_id, pa.account_type, pa.created_at
              FROM acm_person_accounts pa
              JOIN users u ON u.id = pa.user_id
             WHERE pa.status = 'pending'
               AND pa.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
               AND (u.is_active IS NULL OR u.is_active = 0)$pwGuard
               AND NOT EXISTS (
                     SELECT 1 FROM acm_devices d WHERE d.user_id = pa.user_id
                   )
               AND NOT EXISTS (
                     SELECT 1 FROM acm_activation_tickets t
                      WHERE t.person_id = pa.person_id
                        AND EXISTS (
                              SELECT 1 FROM acm_activation_grants g
                               WHERE g.ticket_id = t.id AND g.consumed_at IS NOT NULL
                            )
                   )
               AND NOT EXISTS (
                     SELECT 1 FROM acm_account_suspensions s
                      WHERE s.user_id = pa.user_id
                   )
             ORDER BY pa.created_at ASC
             LIMIT $batch$lock";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the candidate query: ' . $conn->error);
    }
    $stmt->bind_param('i', $days);
    if (!$stmt->execute()) {
        throw new RuntimeException('Candidate query failed: ' . $stmt->error);
    }
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function acm_expiry_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $exists;
}

$reason = 'Automatic expiry: activation was provisioned but never completed within '
    . $days . ' days. Re-entry requires a fresh activation ticket.';

try {
    if (!$apply) {
        $candidates = acm_expiry_candidates($conn, $days, $batch, false);
        fwrite(STDOUT, json_encode([
            'mode' => 'dry-run',
            'days' => $days,
            'batch' => $batch,
            'candidates' => count($candidates),
            'would_expire' => array_map(static function (array $r): array {
                return [
                    'link_id' => (int)$r['link_id'],
                    'user_id' => (int)$r['user_id'],
                    'account_type' => (string)$r['account_type'],
                    'pending_since' => (string)$r['created_at'],
                ];
            }, $candidates),
            'note' => 'Nothing was written. Re-run with --apply to expire these accounts.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(0);
    }

    // ── Apply: one transaction, all rows or none ──────────────────
    $conn->begin_transaction();
    $expired = 0;
    $skippedActive = 0;
    $skippedRace = 0;

    $candidates = acm_expiry_candidates($conn, $days, $batch, true);

    $revoke = $conn->prepare(
        "UPDATE acm_person_accounts SET status = 'revoked'
          WHERE id = ? AND status = 'pending'"
    );
    $deactivate = $conn->prepare(
        'UPDATE users SET is_active = 0 WHERE id = ? AND (is_active IS NULL OR is_active = 0)'
    );
    $history = $conn->prepare(
        'INSERT INTO acm_account_suspensions (user_id, person_id, account_type, action, reason, actor_id)'
        . ' VALUES (?, ?, ?, ?, ?, NULL)'
    );
    if (!$revoke || !$deactivate || !$history) {
        throw new RuntimeException('Could not prepare the expiry statements: ' . $conn->error);
    }

    foreach ($candidates as $row) {
        $userId = (int)$row['user_id'];
        $linkId = (int)$row['link_id'];

        // Re-check inside the transaction: the row may have activated
        // between the candidate query and this point (see THE RACE).
        $live = $conn->prepare('SELECT is_active FROM users WHERE id = ? LIMIT 1');
        $live->bind_param('i', $userId);
        $live->execute();
        $state = $live->get_result()->fetch_assoc();
        $live->close();

        if (!is_array($state) || (int)$state['is_active'] === 1) {
            $skippedActive++;
            continue;
        }

        $revoke->bind_param('i', $linkId);
        $revoke->execute();
        if ($revoke->affected_rows !== 1) {
            // Someone else moved the link (cancelled, activated, revoked).
            $skippedRace++;
            continue;
        }

        $deactivate->bind_param('i', $userId);
        $deactivate->execute();

        $accountType = (string)$row['account_type'];
        $personId = (int)$row['person_id'];
        $action = 'expired';
        $history->bind_param('iiiss', $userId, $personId, $accountType, $action, $reason);
        $history->execute();

        $expired++;
    }

    $revoke->close();
    $deactivate->close();
    $history->close();

    $conn->commit();

    fwrite(STDOUT, json_encode([
        'mode' => 'apply',
        'days' => $days,
        'batch' => $batch,
        'expired' => $expired,
        'skipped_still_active' => $skippedActive,
        'skipped_race' => $skippedRace,
        'reason' => $reason,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    // One rollback attempt; mysqli throws if no transaction is open, which
    // is expected on a failure before begin_transaction(). Swallowed
    // deliberately so the real error is what reaches the operator.
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // nothing to roll back
    }
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
