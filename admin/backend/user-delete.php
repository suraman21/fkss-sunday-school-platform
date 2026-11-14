<?php
/**
 * ============================================================
 * Delete User — with safe cleanup of everything they touched
 * ============================================================
 * Only a super admin can delete a user, and only after re-typing
 * their own password.
 *
 * WHY THIS FILE WAS REWRITTEN
 * ---------------------------
 * The old version ran a single "DELETE FROM users" and stopped.
 * Because the database has no foreign keys on the tables that point
 * at a user, that left broken links everywhere: the deleted teacher's
 * class assignments still existed (pointing at a user that was gone),
 * their recorded attendance/grades lost their author, and tasks/
 * notifications addressed to them became ghosts.
 *
 * This version does the delete inside a single transaction and first
 * tidies up every reference:
 *   - Teaching assignments for that teacher are removed (a role binding
 *     is meaningless once the person is gone).
 *   - Attendance/grade "recorded_by", task and notification links, and
 *     audit-log author fields are set to NULL so the HISTORY is kept
 *     but no longer points at a missing user.
 * Everything succeeds together or nothing changes at all.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/danger_confirm.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ssms_app_url('admin/users.php') . '');
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . ssms_app_url('admin/index.php'));
    exit;
}

if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: ' . ssms_app_url('admin/users.php') . '?error=' . urlencode('Access denied.'));
    exit;
}



requireCsrf();

$deleteUserId       = isset($_POST['delete_user_id']) ? (int)$_POST['delete_user_id'] : 0;
$superadminPassword = isset($_POST['superadmin_password']) ? $_POST['superadmin_password'] : '';

if ($deleteUserId <= 0 || $superadminPassword === '') {
    header('Location: ' . ssms_app_url('admin/users.php') . '?error=' . urlencode('Invalid delete request.'));
    exit;
}

// Danger confirmation (HIGH): the 3-step dialog sends the typed phrase
// DELETE USER. Checked before the password so nothing runs without it.
if (!dc_phrase_ok('users.delete', $_POST)) {
    header('Location: ' . ssms_app_url('admin/users.php') . '?error=' . urlencode('Type DELETE USER in the confirmation dialog to delete a user. User not deleted.') . '&delete_id=' . $deleteUserId);
    exit;
}

// Don't allow deleting your own account
$currentAdminId = (int)($_SESSION['admin_id'] ?? 0);
if ($deleteUserId === $currentAdminId) {
    header('Location: ' . ssms_app_url('admin/users.php') . '?error=' . urlencode('You cannot delete your own account.'));
    exit;
}

/**
 * Run a cleanup statement, ignoring "table/column does not exist" errors
 * so that an optional table missing on some deployments never blocks a delete.
 */
function _cleanupExec(PDO $pdo, string $sql, int $userId): void {
    try {
        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $userId]);
    } catch (PDOException $e) {
        // 42S02 = unknown table, 42S22 = unknown column — safe to ignore.
        $code = $e->getCode();
        if ($code !== '42S02' && $code !== '42S22') {
            // Re-throw anything unexpected so the outer transaction rolls back.
            throw $e;
        }
    }
}

try {
    // Verify the super admin's own password before doing anything.
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $currentAdminId]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($superadminPassword, $admin['password_hash'])) {
        header('Location: ' . ssms_app_url('admin/users.php') . '?error=' . urlencode('Incorrect password. User not deleted.') . '&delete_id=' . $deleteUserId);
        exit;
    }

    // Make sure the target actually exists (nicer message than a silent no-op).
    $chk = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $chk->execute([':id' => $deleteUserId]);
    if (!$chk->fetch()) {
        header('Location: ' . ssms_app_url('admin/users.php') . '?error=' . urlencode('That user no longer exists.'));
        exit;
    }

    // S8: never hard-delete an ACM-managed account. The cleanup
    // below is staff-domain only and would orphan the ACM link,
    // devices, sessions, and ceremony history. Deactivate in the
    // ACM console instead (terminal, attribution-preserving).
    // Runs after the password step-up (auth first), before the
    // transaction (refuse without touching anything).
    try {
        $acmLink = $pdo->prepare('SELECT status FROM acm_person_accounts WHERE user_id = :uid LIMIT 1');
        $acmLink->execute([':uid' => $deleteUserId]);
        if ($acmLink->fetchColumn() !== false) {
            header('Location: ' . ssms_app_url('admin/users.php') . '?error=' . urlencode('Deactivate this account in the ACM console first — hard delete would orphan its devices, sessions, and ceremony history.') . '&delete_id=' . $deleteUserId);
            exit;
        }
    } catch (PDOException $e) {
        // 42S02/42S22: pre-ACM deployment, no link table — proceed.
        if ($e->getCode() !== '42S02' && $e->getCode() !== '42S22') {
            throw $e;
        }
    }

    // Everything below is all-or-nothing.
    $pdo->beginTransaction();

    // 1. Remove this teacher's class/subject assignments (role bindings).
    _cleanupExec($pdo, "DELETE FROM teacher_assignments WHERE teacher_id = :uid", $deleteUserId);

    // 2. Keep the records, drop the broken author links (set to NULL).
    _cleanupExec($pdo, "UPDATE attendance        SET recorded_by   = NULL WHERE recorded_by   = :uid", $deleteUserId);
    _cleanupExec($pdo, "UPDATE academic_records  SET recorded_by   = NULL WHERE recorded_by   = :uid", $deleteUserId);
    // M4 FIX: grade_submissions has NO submitted_by column (the old
    // statement was a silent no-op). The reviewer link is nullable and is
    // detached here. teacher_id is NOT NULL and intentionally kept: grade
    // submissions remain attributed to the (deleted) teacher's id so no
    // academic record is destroyed by a user deletion.
    _cleanupExec($pdo, "UPDATE grade_submissions SET reviewed_by   = NULL WHERE reviewed_by   = :uid", $deleteUserId);
    _cleanupExec($pdo, "UPDATE member_changes    SET changed_by_user = NULL WHERE changed_by_user = :uid", $deleteUserId);
    _cleanupExec($pdo, "UPDATE activity_logs     SET user_id       = NULL WHERE user_id       = :uid", $deleteUserId);

    // 3. Detach notifications and tasks tied to this user.
    _cleanupExec($pdo, "UPDATE notifications SET source_user_id = NULL WHERE source_user_id = :uid", $deleteUserId);
    // A private notification with both targets NULL becomes a public
    // broadcast. Remove single-recipient messages rather than publishing
    // them accidentally when their recipient account is deleted.
    _cleanupExec($pdo, "DELETE FROM notifications WHERE target_user_id = :uid AND (target_roles IS NULL OR target_roles = '')", $deleteUserId);
    _cleanupExec($pdo, "UPDATE notifications SET target_user_id = NULL WHERE target_user_id = :uid", $deleteUserId);
    _cleanupExec($pdo, "UPDATE department_tasks SET from_user_id = NULL WHERE from_user_id = :uid", $deleteUserId);
    _cleanupExec($pdo, "UPDATE department_tasks SET to_user_id   = NULL WHERE to_user_id   = :uid", $deleteUserId);
    _cleanupExec($pdo, "UPDATE department_tasks SET completed_by = NULL WHERE completed_by = :uid", $deleteUserId);

    // 4. Finally remove the user.
    $del = $pdo->prepare("DELETE FROM users WHERE id = :id LIMIT 1");
    $del->execute([':id' => $deleteUserId]);

    $pdo->commit();

    header('Location: ' . ssms_app_url('admin/users.php') . '?success=' . urlencode('User deleted and all their links cleaned up.'));
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("User delete failed for id {$deleteUserId}: " . $e->getMessage());
    header('Location: ' . ssms_app_url('admin/users.php') . '?error=' . urlencode('Error deleting user. No changes were made. Please try again.') . '&delete_id=' . $deleteUserId);
    exit;
}
