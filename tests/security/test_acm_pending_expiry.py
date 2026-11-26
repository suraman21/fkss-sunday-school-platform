"""Pending-activation expiry and the two activation back doors (2026-11-26).

Finding 9.7: a provisioned member/parent account that never completes the
device ceremony is a permanent orphan. `user-delete.php` refuses to
hard-delete anything with an ACM link (so devices, sessions and ceremony
history cannot be orphaned), and nothing ever expired a `pending` link.

While writing the cleanup it became clear the orphan was not the worst part
of that state. A pending account has `users.is_active = 0`, which reads
identically to a deliberately disabled one at the login gate — and BOTH
staff surfaces that can flip that bit (user-toggle.php and the edit path in
user-save.php) guarded only against `revoked`. A `pending` account could
therefore be enabled straight past the device ceremony and would log in
without the keypair binding the ceremony exists to establish.

These are static assertions. They pin the guards and their ORDER; they do
not execute the cron job, which needs MariaDB.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

EXPIRY = ROOT / "admin" / "backend" / "acm_pending_expiry.php"
MIGRATION = ROOT / "sql" / "098_acm_pending_expiry_events.sql"
TOGGLE = ROOT / "admin" / "backend" / "user-toggle.php"
SAVE = ROOT / "admin" / "backend" / "user-save.php"
DELETE = ROOT / "admin" / "backend" / "user-delete.php"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class PendingBackDoorTests(unittest.TestCase):
    """A pending link must not be enableable from staff user administration.
    Static: the guards are string-pinned, not exercised."""

    def test_toggle_refuses_pending(self):
        src = read(TOGGLE)
        self.assertIn("$acmLinkStatus === 'pending'", src)
        self.assertIn("has not finished device activation", src)

    def test_save_refuses_pending(self):
        src = read(SAVE)
        self.assertIn("$acmLinkStatus === 'pending'", src)
        self.assertIn("has not finished device activation", src)

    def test_both_guards_read_the_link_status_once(self):
        """Both files previously called fetchColumn() twice; the guard must
        read the status into a variable so the two branches cannot disagree."""
        for path in (TOGGLE, SAVE):
            src = read(path)
            self.assertIn("$acmLinkStatus = $acmGuard->fetchColumn();", src, path.name)
            self.assertNotIn(
                "if ($acmGuard->fetchColumn() === 'revoked')", src,
                f"{path.name} must not consume the row with a bare fetchColumn()",
            )

    def test_both_guards_stay_inside_the_0_to_1_branch(self):
        """A 1->0 flip must stay unguarded: gating off is always safe."""
        for path in (TOGGLE, SAVE):
            src = read(path)
            guard = src.index("$acmLinkStatus === 'pending'")
            enclosing = src.rindex("if ((int)$prevRow['is_active'] === 0 && (int)$isActive === 1)", 0, guard) \
                if path == SAVE else src.rindex("if ($newStatus === 1)", 0, guard)
            self.assertGreater(guard, enclosing, path.name)

    def test_revoked_guard_survives(self):
        for path in (TOGGLE, SAVE):
            self.assertIn("=== 'revoked'", read(path), path.name)

    def test_delete_still_refuses_any_acm_link(self):
        """The cleanup job deactivates; it never hard-deletes. If this guard
        were relaxed, the expiry job's history would be destroyed."""
        src = read(DELETE)
        self.assertIn("acm_person_accounts", src)
        self.assertIn("Deactivate this account in the ACM console first", src)


class ExpiryMigrationTests(unittest.TestCase):
    def test_action_enum_gains_expired(self):
        sql = read(MIGRATION)
        self.assertIn("'expired'", sql)
        self.assertIn("acm_account_suspensions", sql)

    def test_previous_values_are_preserved(self):
        """An ENUM widen must carry the existing vocabulary forward or the
        ALTER silently drops values already stored."""
        sql = read(MIGRATION)
        for value in ("suspended", "reactivated", "deactivated"):
            self.assertIn(f"''{value}''", sql)

    def test_guarded_and_rerunnable(self):
        sql = read(MIGRATION)
        self.assertIn("information_schema.COLUMNS", sql)
        self.assertIn("NOT LIKE '%expired%'", sql)
        for stmt in ("PREPARE", "EXECUTE", "DEALLOCATE"):
            self.assertIn(stmt, sql)

    def test_no_destructive_statements(self):
        code = re.sub(r"(?m)^\s*--.*$", "", read(MIGRATION)).upper()
        for banned in ("TRUNCATE", "DELETE FROM", "DROP TABLE"):
            self.assertNotIn(banned, code)


class ExpiryJobTests(unittest.TestCase):
    """Pins the safety properties the job's docblock promises."""

    @classmethod
    def setUpClass(cls):
        cls.src = read(EXPIRY)

    def test_cli_only(self):
        self.assertIn("if (PHP_SAPI !== 'cli')", self.src)
        self.assertIn("http_response_code(404)", self.src)

    def test_dry_run_is_the_default(self):
        self.assertIn("$apply = in_array('--apply', $args, true);", self.src)
        # The dry-run branch must return before any write.
        dry = self.src[self.src.index("if (!$apply) {"):self.src.index("// ── Apply:")]
        for write in ("UPDATE ", "INSERT INTO", "UPDATE`"):
            self.assertNotIn(write, dry, "dry run must not write")

    def test_apply_is_a_single_transaction(self):
        self.assertIn("$conn->begin_transaction();", self.src)
        self.assertIn("$conn->commit();", self.src)
        self.assertIn("$conn->rollback();", self.src)

    def test_candidates_lock_rows_when_applying(self):
        self.assertIn("FOR UPDATE", self.src)
        self.assertIn("acm_expiry_candidates($conn, $days, $batch, true)", self.src)

    def test_race_is_rechecked_inside_the_transaction(self):
        """DeviceService commits the activation transaction before
        markActivated(), so a link can go active mid-run."""
        self.assertIn("SELECT is_active FROM users WHERE id = ? LIMIT 1", self.src)
        self.assertIn("skipped_still_active", self.src)
        self.assertIn("skipped_race", self.src)

    def test_only_pending_links_are_touched(self):
        self.assertIn("pa.status = 'pending'", self.src)
        self.assertIn("WHERE id = ? AND status = 'pending'", self.src)

    def test_only_inactive_users_are_touched(self):
        self.assertIn("(u.is_active IS NULL OR u.is_active = 0)", self.src)
        self.assertIn("(is_active IS NULL OR is_active = 0)", self.src)

    def test_skips_accounts_a_human_is_still_working_on(self):
        # a completed device registration
        self.assertIn("FROM acm_devices d WHERE d.user_id = pa.user_id", self.src)
        # a consumed activation grant
        self.assertIn("g.consumed_at IS NOT NULL", self.src)
        # a password change since provisioning
        self.assertIn("password_changed_at", self.src)

    def test_optional_column_is_guarded(self):
        """password_changed_at is conditionally present; naming it
        unconditionally would break a database that predates it."""
        self.assertIn("acm_expiry_column_exists($conn, 'users', 'password_changed_at')", self.src)
        self.assertIn("$pwGuard", self.src)

    def test_writes_history_not_deletion(self):
        self.assertIn("INSERT INTO acm_account_suspensions", self.src)
        self.assertIn("'expired'", self.src)
        code = re.sub(r"(?m)^\s*//.*$|^\s*\*.*$", "", self.src).upper()
        for banned in ("DELETE FROM", "TRUNCATE", "DROP TABLE"):
            self.assertNotIn(banned, code)

    def test_pre_acm_deployment_exits_clean(self):
        self.assertIn("pre-ACM deployment", self.src)
        self.assertIn("exit(0);", self.src)

    def test_bad_arguments_exit_2(self):
        """Five refusal paths: non-numeric --days, non-numeric --batch,
        unknown argument, --days out of range, --batch out of range.
        exit(2) rather than exit(1) so a typo in a crontab line is
        distinguishable from a real failure in cron mail."""
        self.assertEqual(5, self.src.count("exit(2);"))

    def test_days_and_batch_are_bounded(self):
        self.assertIn("ACM_EXPIRY_MAX_DAYS", self.src)
        self.assertIn("ACM_EXPIRY_MAX_BATCH", self.src)

    def test_single_rollback(self):
        """Exactly one rollback attempt: the first draft had two, and the
        second was unreachable."""
        self.assertEqual(1, self.src.count("$conn->rollback();"))

    def test_never_sets_is_active_to_one(self):
        """The whole point: this job may only deactivate."""
        code = re.sub(r"(?m)^\s*//.*$|^\s*\*.*$", "", self.src)
        self.assertNotRegex(code, r"is_active\s*=\s*1")


if __name__ == "__main__":
    unittest.main()
