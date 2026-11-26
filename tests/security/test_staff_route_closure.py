"""Staff-route closure: a role outside the staff catalogue must be refused.

WHY THIS FILE EXISTS
--------------------
The 2026-11-26 member/parent account audit found that three v1 routes were
reachable by *any* authenticated role, including the `member` and `parent`
accounts the ACM subsystem provisions:

    api/v1/routes/classes.php        gated only finance_dept/material_dept
                                     (a two-role deny-list)
    api/v1/routes/dashboard.php      no role gate at all
    api/v1/routes/notifications.php  no role gate at all

A student token could enumerate the whole class list and any class's full
roster; a parent token additionally reached school-wide member counts, a
gender split and the global activity_logs feed. `/notifications/*` reads a
`target_roles IS NULL AND target_user_id IS NULL` broadcast row that no
staff role list ever mentioned.

The existing suite could not catch this, because every route test pins an
allow-list *string* in the routes that have one. Nothing asserted the
inverse: that a role in no allow-list gets refused. The two shapes that
leaked were exactly the two shapes nothing tested — a deny-list, and no
gate.

These are static assertions. They pin the gate's presence and, more
importantly, its SHAPE: allow-list, not deny-list. A future "fix" that
names member/parent in a deny-list would pass a naive presence check and
still admit the next non-staff role, so that shape is asserted directly.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

ACL = ROOT / "api" / "v1" / "core" / "acl.php"
CLASSES = ROOT / "api" / "v1" / "routes" / "classes.php"
DASHBOARD = ROOT / "api" / "v1" / "routes" / "dashboard.php"
NOTIFICATIONS = ROOT / "api" / "v1" / "routes" / "notifications.php"

# The staff catalogue, mirrored from admin/backend/user-save.php $validRoles.
STAFF_ROLES = {
    "super_admin", "school_admin", "info_dept", "hr_dept",
    "photo_importer", "photo_dept", "edu_dept", "finance_dept",
    "material_dept", "mezmur_dept", "teacher", "attendance_taker",
    "content_editor", "mezmur_attendance_taker",
    "hr_attendance_taker", "accounts_office",
}

# Roles provisioned by ACM activation, never by staff user creation.
NON_STAFF_ROLES = {"member", "parent"}


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def strip_php_comments(src: str) -> str:
    """Remove /* */ and // comments so prose about a gate is not mistaken
    for the gate itself (and vice versa)."""
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    return re.sub(r"(?m)^\s*(//|#).*$", "", src)


class StaffCatalogueTests(unittest.TestCase):
    def test_acl_exposes_the_staff_catalogue(self):
        src = read(ACL)
        self.assertIn("function apiRolesStaff(): array", src)
        self.assertIn("function apiRequireStaffRole(array $auth): void", src)

    def test_staff_catalogue_contents_are_staff_only(self):
        """The catalogue must contain every staff role and neither of the
        two ACM-provisioned roles."""
        src = read(ACL)
        m = re.search(r"function apiRolesStaff\(\): array\s*\{\s*return \[(.*?)\];", src, re.S)
        self.assertIsNotNone(m, "apiRolesStaff() body not found")
        listed = set(re.findall(r"'([a-z_]+)'", m.group(1)))
        self.assertEqual(listed, STAFF_ROLES,
                         "apiRolesStaff() drifted from the staff catalogue")
        self.assertFalse(listed & NON_STAFF_ROLES,
                         "member/parent must never be a staff role")

    def test_gate_is_an_allow_list_not_a_deny_list(self):
        """apiRequireStaffRole must fail closed: admit only apiRolesStaff()."""
        src = read(ACL)
        body = src[src.index("function apiRequireStaffRole"):]
        body = body[:body.index("\n}")]
        self.assertIn("apiRoleIs($auth, apiRolesStaff())", body)
        # A deny-list here would invert the meaning and admit everything else.
        for banned in NON_STAFF_ROLES:
            self.assertNotIn(f"'{banned}'", body,
                             "gate must not name individual non-staff roles")

    def test_gate_matches_the_creation_whitelist(self):
        """The staff catalogue and user-save.php's $validRoles are two
        hand-maintained lists. They must not drift: a role creatable
        through the admin UI but missing here would get a silent 403."""
        us = read(ROOT / "admin" / "backend" / "user-save.php")
        m = re.search(r"\$validRoles = \[(.*?)\n\];", us, re.S)
        self.assertIsNotNone(m, "$validRoles not found in user-save.php")
        creatable = set(re.findall(r"'([a-z_]+)'", m.group(1)))
        self.assertEqual(creatable, STAFF_ROLES,
                         "apiRolesStaff() and user-save.php $validRoles diverged")


class RouteClosureTests(unittest.TestCase):
    """Each formerly-open route must call the gate, and call it before any
    data is read."""

    def _assert_gated(self, path: Path, label: str):
        src = read(path)
        self.assertIn("apiRequireStaffRole($auth)", src,
                      f"{label} must call apiRequireStaffRole($auth)")
        # The gate must come before the first route handler.
        gate_at = src.index("apiRequireStaffRole($auth)")
        first_handler = src.find("$method === ")
        if first_handler == -1:
            first_handler = src.find("$action === ")
        if first_handler != -1:
            self.assertLess(gate_at, first_handler,
                            f"{label}: gate must precede the first handler")

    def test_classes_route_is_gated(self):
        self._assert_gated(CLASSES, "classes.php")

    def test_dashboard_route_is_gated(self):
        self._assert_gated(DASHBOARD, "dashboard.php")

    def test_notifications_route_is_gated(self):
        self._assert_gated(NOTIFICATIONS, "notifications.php")

    def test_classes_keeps_its_existing_deny_list(self):
        """The pre-existing finance/material refusal must survive the
        change: mobile has no class view for those departments."""
        src = strip_php_comments(read(CLASSES))
        self.assertIn("'finance_dept', 'material_dept'", src)
        self.assertIn("Classes are not available for this role. Use the website.", src)


class NotificationMatrixTests(unittest.TestCase):
    """The gate alone is not the whole argument for /notifications: the
    service's own matrices must also contain no member/parent entry, or
    removing the gate later would silently re-open it."""

    @classmethod
    def setUpClass(cls):
        cls.svc = read(ROOT / "admin" / "backend" / "services" / "NotificationCenterService.php")

    def _const_roles(self, name: str) -> set:
        m = re.search(name + r"\s*=\s*\[(.*?)\n    \];", self.svc, re.S)
        self.assertIsNotNone(m, f"{name} not found")
        return set(re.findall(r"'([a-z_]+)'\s*=>", m.group(1)))

    def test_role_labels_has_no_member_or_parent(self):
        roles = self._const_roles("ROLE_LABELS")
        self.assertFalse(roles & NON_STAFF_ROLES,
                         "ROLE_LABELS must stay staff-only")

    def test_announce_and_message_matrices_have_no_member_or_parent(self):
        for const in ("ANNOUNCE_SEND", "MESSAGE_PARTNERS"):
            roles = self._const_roles(const)
            self.assertFalse(roles & NON_STAFF_ROLES,
                             f"{const} must stay staff-only")


if __name__ == "__main__":
    unittest.main()
