"""Member/parent backend remediation guards (2026-11-26).

Pins the four backend fixes made after the member/parent account audit.
Each test states the defect it prevents from returning:

1. Parent attendance read   — /members/{id} was readable by a parent but
                              /members/{id}/attendance was not, so the one
                              screen a parent app opens first returned 403.
2. Ticket link integrity    — a ticket could name a guardian that
                              contradicted acm_persons.guardian_id, which
                              is the ONLY column parent authorization reads.
3. Task notification target — a task with no department and no user wrote
                              a notification addressed to nobody.
4. repair_user_role drift   — the tool's role whitelist was a hand-copy
                              that had drifted to 10 of 16 roles.

All four are static: they assert the guard exists and has the right SHAPE.
Static-only is a real limitation and is stated where it matters — see each
class docstring.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

MEMBERS = ROOT / "api" / "v1" / "routes" / "members.php"
TICKETS = ROOT / "acm" / "src" / "Accounts" / "AcmTicketService.php"
WORKFLOW = ROOT / "admin" / "backend" / "workflow.php"
REPAIR = ROOT / "admin" / "tools" / "repair_user_role.php"
USER_SAVE = ROOT / "admin" / "backend" / "user-save.php"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class ParentAttendanceReadTests(unittest.TestCase):
    """The attendance branch must consult the same parent resolver as the
    member-record branch. Static only: no runtime 403 is asserted, because
    the live HTTP harness has no seeded parent user."""

    @classmethod
    def setUpClass(cls):
        cls.src = read(MEMBERS)
        # Isolate the attendance branch so the member-record branch's parent
        # clause cannot satisfy these assertions.
        start = cls.src.index("$sub === 'attendance'")
        end = cls.src.index("$sub === 'attendance-risk'") if "$sub === 'attendance-risk'" in cls.src else len(cls.src)
        cls.branch = cls.src[start:end]

    def test_attendance_branch_has_a_parent_clause(self):
        self.assertIn("$canParent = apiRoleIs($auth, ['parent'])", self.branch)

    def test_parent_clause_uses_the_shared_resolver(self):
        """Must reuse apiParentCanSeeMember, not a hand-rolled check — the
        resolver is the deny-by-default, verified-link-aware one."""
        self.assertIn("apiParentCanSeeMember($conn, $auth, $id)", self.branch)

    def test_parent_does_not_bypass_the_denial(self):
        """The parent clause must be ANDed with its resolver, i.e. the
        branch still denies when apiParentCanSeeMember is false."""
        self.assertIn("!($canParent && apiParentCanSeeMember($conn, $auth, $id))", self.branch)

    def test_denial_message_unchanged(self):
        self.assertIn("'You cannot view this attendance history.'", self.branch)


class TicketLinkIntegrityTests(unittest.TestCase):
    """A ticket may restate or fill in a person's link, never contradict it.
    Static: the contradiction cannot be exercised without a database."""

    @classmethod
    def setUpClass(cls):
        cls.src = read(TICKETS)
        cls.create = cls.src[cls.src.index("public function create("):]
        cls.create = cls.create[:cls.create.index("\n    public function get(")]

    def test_member_contradiction_is_rejected(self):
        self.assertIn("$personMember", self.create)
        self.assertIn("memberId !== $personMember", self.create)

    def test_guardian_contradiction_is_rejected(self):
        self.assertIn("$personGuardian", self.create)
        self.assertIn("guardianId !== $personGuardian", self.create)

    def test_check_precedes_the_insert(self):
        insert_at = self.create.index("INSERT INTO acm_activation_tickets")
        for anchor in ("memberId !== $personMember", "guardianId !== $personGuardian"):
            self.assertLess(self.create.index(anchor), insert_at,
                            "link check must run before the ticket is written")

    def test_only_null_safe_comparisons(self):
        """A NULL on either side must not raise a false contradiction:
        filling in a missing link is legitimate."""
        for line in self.create.splitlines():
            if "!==" in line and ("$personMember" in line or "$personGuardian" in line):
                self.assertIn("!== null", line,
                              "comparison must short-circuit on null")

    def test_error_names_the_authoritative_record(self):
        """The message must tell the officer where to fix it, because the
        person record — not the ticket — drives authorization."""
        self.assertIn("Correct the person record instead of the ticket.", self.create)


class TaskNotificationTargetTests(unittest.TestCase):
    """A notification addressed to nobody must never be written.
    Static: the writer's guard is asserted, not its runtime effect."""

    @classmethod
    def setUpClass(cls):
        cls.src = read(WORKFLOW)
        cls.send = cls.src[cls.src.index("function sendNotification("):]
        cls.send = cls.send[:cls.send.index("\nfunction ", 10)]

    def test_writer_refuses_untargeted_notifications(self):
        self.assertIn("$hasRoleTarget", self.send)
        self.assertIn("if (!$hasRoleTarget && $targetUserId === null)", self.send)
        self.assertIn("return false;", self.send)

    def test_refusal_is_logged_not_silent(self):
        self.assertIn("sendNotification refused", self.send)

    def test_guard_runs_before_the_insert(self):
        guard_at = self.send.index("$hasRoleTarget")
        insert_at = self.send.index("INSERT INTO notifications")
        self.assertLess(guard_at, insert_at)

    def test_empty_array_counts_as_no_target(self):
        """The old bug: [] imploded to '' which is not NULL, so the row
        matched nobody. The guard must treat [] as untargeted."""
        self.assertIn("$targetRoles !== []", self.send)

    def test_task_creation_skips_notification_without_a_recipient(self):
        task = self.src[self.src.index("function createDepartmentTask("):]
        task = task[:task.index("\n}\n", 10)]
        self.assertIn("if ($toDept || $toUserId) {", task)
        self.assertLess(task.index("if ($toDept || $toUserId) {"),
                        task.index("sendNotification($conn, 'task_assigned'"))


class RepairRoleWhitelistTests(unittest.TestCase):
    """The repair tool must derive its catalogue, not copy it."""

    @classmethod
    def setUpClass(cls):
        cls.repair = read(REPAIR)

    def test_catalogue_is_derived_from_user_save(self):
        self.assertIn("backend/user-save.php", self.repair)
        self.assertIn("$validRoles", self.repair)
        self.assertIn("preg_match", self.repair)

    def test_no_second_hardcoded_role_list(self):
        """A literal $CANONICAL_ROLES = [ ... ] array is the drift bug."""
        self.assertNotRegex(self.repair, r"\$CANONICAL_ROLES\s*=\s*\[")

    def test_derivation_fails_loudly(self):
        """If user-save.php cannot be read or parsed, the tool must stop —
        a silently empty catalogue would reject every role."""
        self.assertIn("cannot read admin/backend/user-save.php", self.repair)
        self.assertIn("could not parse any roles", self.repair)

    def test_derivation_matches_user_save_role_count(self):
        """Mirrors the tool's own extraction in Python against the real
        file, so the regex and the source list are proven compatible."""
        us = read(USER_SAVE)
        m = re.search(r"\$validRoles\s*=\s*\[(.*?)\n\];", us, re.S)
        self.assertIsNotNone(m)
        body = re.sub(r"/\*.*?\*/", "", m.group(1), flags=re.S)
        body = re.sub(r"(?m)^\s*//.*$", "", body)
        roles = re.findall(r"'([a-z_]+)'", body)
        self.assertEqual(16, len(set(roles)))
        self.assertNotIn("member", roles)
        self.assertNotIn("parent", roles)


class PendingActivationMessageTests(unittest.TestCase):
    """An ACM-provisioned account awaiting device setup must not be told to
    "contact an administrator" — nobody at the office can activate it.
    Static: the helper is read-only and its deny-safe path is asserted."""

    @classmethod
    def setUpClass(cls):
        cls.auth_route = read(ROOT / "api" / "v1" / "routes" / "auth.php")
        cls.acl = read(ROOT / "api" / "v1" / "core" / "acl.php")

    def test_helper_exists(self):
        self.assertIn("function apiAccountPendingActivation($conn, int $userId): bool", self.acl)

    def test_helper_reads_only_boolean_state(self):
        """It must not leak a username, account id or ACM detail into a
        pre-auth response — only a boolean drives the message choice."""
        body = self.acl[self.acl.index("function apiAccountPendingActivation"):]
        body = body[:body.index("\n}\n")]
        self.assertIn("SELECT 1 FROM acm_person_accounts", body)
        self.assertIn("status = 'pending'", body)
        # No selection of identifying columns.
        for column in ("username", "user_id,", "person_id,", "guardian_id,", "member_id,"):
            self.assertNotIn(column, body.replace("user_id = ?", ""),
                             f"helper must not select {column}")

    def test_helper_fails_closed(self):
        """The try/catch wraps the prepare, so a missing acm_person_accounts
        table (pre-ACM database) degrades to the generic message rather than
        a 500 on login."""
        body = self.acl[self.acl.index("function apiAccountPendingActivation"):]
        body = body[:body.index("\n}\n") + 3]
        self.assertIn("try {", body)
        self.assertIn("catch (\\mysqli_sql_exception $e)", body)
        self.assertIn("return false;", body)
        # The catch must precede the prepare it guards.
        self.assertLess(body.index("try {"), body.index("$conn->prepare"))
        self.assertLess(body.index("$conn->prepare"), body.index("catch ("))

    def test_login_distinguishes_pending_from_disabled(self):
        self.assertIn("apiAccountPendingActivation($conn, (int)$user['id'])", self.auth_route)
        self.assertIn("activation_pending", self.auth_route)
        # The generic message must survive as the fallback.
        self.assertIn("'Your account is inactive. Contact an administrator.'", self.auth_route)

    def test_pending_check_runs_after_password_verification(self):
        """The pending lookup must not be reachable without valid
        credentials, or it becomes an account-existence oracle."""
        self.assertLess(self.auth_route.index("password_verify($password"),
                        self.auth_route.index("apiAccountPendingActivation($conn,"))

    def test_pending_branch_stays_a_403(self):
        seg = self.auth_route[self.auth_route.index("apiAccountPendingActivation($conn,"):]
        seg = seg[:seg.index("activation_pending") + 60]
        self.assertIn("403", seg)


if __name__ == "__main__":
    unittest.main()
