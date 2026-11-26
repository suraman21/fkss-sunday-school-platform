"""Regression tests for Fix 13: finance-dept login must land on the
finance dashboard, never the school-admin dashboard.

A finance account that lands on the School Admin dashboard can only mean
its stored users.role is wrong — every routing layer dispatches strictly
by role. These tests pin all four routing layers plus the creation path so
the chain can never silently diverge again:

  1. admin/dashboard.php            — finance_dept → /frontend/pages/finance_dept.php
  2. frontend/pages/dashboard.php   — finance_dept → finance_dept.php
  3. frontend/pages/finance_dept.php — $requiredRoles admits finance_dept
  4. admin/access_control.php       — finance pages admit finance_dept;
                                      school_admin.php stays super/school only
  5. admin/backend/login.php        — session role copied verbatim from users.role
  6. admin/backend/user-save.php    — finance_dept in the canonical whitelist
  7. admin/tools/repair_user_role.php — CLI-only diagnostic/repair tool
"""

from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class DashboardRoleRoutingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin_router = (ROOT / "admin/dashboard.php").read_text(encoding="utf-8")
        cls.front_router = (ROOT / "frontend/pages/dashboard.php").read_text(encoding="utf-8")
        cls.finance_page = (ROOT / "frontend/pages/finance_dept.php").read_text(encoding="utf-8")
        cls.base_layout = (ROOT / "frontend/layouts/base.php").read_text(encoding="utf-8")
        cls.access_control = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")
        cls.login = (ROOT / "admin/backend/login.php").read_text(encoding="utf-8")
        cls.user_save = (ROOT / "admin/backend/user-save.php").read_text(encoding="utf-8")
        cls.repair_tool = (ROOT / "admin/tools/repair_user_role.php").read_text(encoding="utf-8")

    # ── Layer 1: legacy admin router ────────────────────────────────────
    def test_admin_router_sends_finance_to_frontend_page(self):
        self.assertIn("case 'finance_dept':", self.admin_router)
        self.assertIn("header('Location: /frontend/pages/finance_dept.php');", self.admin_router)
        # Only school_admin may fall into the school-admin dashboard.
        self.assertIn("case 'school_admin':", self.admin_router)
        # The school_admin case must not be reachable from finance_dept:
        # exactly one branch requires school_admin.php.
        self.assertEqual(self.admin_router.count("dashboards/school_admin.php"), 1)

    def test_admin_router_sends_mezmur_to_frontend_page(self):
        self.assertIn("case 'mezmur_dept':", self.admin_router)
        self.assertIn("header('Location: /frontend/pages/mezmur_dept.php');", self.admin_router)

    # ── Layer 2: frontend router ────────────────────────────────────────
    def test_frontend_router_maps_finance_dept(self):
        self.assertIn("'finance_dept'     => 'finance_dept.php',", self.front_router)
        self.assertIn("'school_admin'     => 'school_admin.php',", self.front_router)
        self.assertIn("'mezmur_dept'      => 'mezmur_dept.php',", self.front_router)
        # Unknown roles fall back to the legacy router, never silently to
        # another role's dashboard.
        self.assertIn("header('Location: /admin/dashboard.php');", self.front_router)

    # ── Layer 3: finance page role gate ─────────────────────────────────
    def test_finance_page_admits_finance_dept_role(self):
        self.assertIn(
            "$requiredRoles = ['super_admin', 'school_admin', 'finance_dept'];",
            self.finance_page,
        )
        self.assertIn("$requiredFeature = 'finance';", self.finance_page)

    def test_base_layout_enforces_required_roles(self):
        self.assertIn("$requiredRoles", self.base_layout)
        self.assertIn("in_array($__role, $requiredRoles, true)", self.base_layout)

    # ── Layer 4: central access control ─────────────────────────────────
    def test_access_control_finance_pages_admit_finance_dept(self):
        self.assertIn("'finance.php'       => ['super_admin', 'school_admin', 'finance_dept'],", self.access_control)
        self.assertIn("'finance_dept.php'  => ['super_admin', 'school_admin', 'finance_dept'],", self.access_control)

    def test_access_control_school_admin_dashboard_excludes_finance(self):
        # finance_dept must never appear in the school_admin.php entry.
        line = next(
            (l for l in self.access_control.splitlines() if "'school_admin.php'" in l),
            None,
        )
        self.assertIsNotNone(line, "school_admin.php missing from ROLE_MAP")
        self.assertIn("'super_admin'", line)
        self.assertIn("'school_admin'", line)
        self.assertNotIn("'finance_dept'", line)

    # ── Layer 5: login session role is the stored role, verbatim ────────
    def test_login_copies_role_verbatim_from_database(self):
        flat = " ".join(self.login.split())  # normalise alignment whitespace
        self.assertIn("$_SESSION['admin_role'] = $user['role'];", flat)
        # No role remapping/normalising between the DB and the session.
        self.assertNotIn("str_replace('_dept'", self.login)

    # ── Layer 6: creation path accepts finance_dept ─────────────────────
    def test_user_save_whitelist_includes_finance_dept(self):
        self.assertIn("'finance_dept',", self.user_save)
        self.assertIn("if (!in_array($role, $validRoles, true))", self.user_save)

    # ── Layer 7: diagnostic/repair tool ─────────────────────────────────
    def test_repair_tool_is_cli_only(self):
        self.assertIn("PHP_SAPI !== 'cli'", self.repair_tool)
        self.assertIn("http_response_code(404)", self.repair_tool)

    def test_repair_tool_uses_prepared_statements_and_whitelist(self):
        self.assertIn("$conn->prepare(", self.repair_tool)
        self.assertIn("CANONICAL_ROLES", self.repair_tool)
        self.assertIn("in_array($roleArg, $CANONICAL_ROLES, true)", self.repair_tool)
        # Dry-run by default: --apply must be requested explicitly.
        self.assertIn("--apply", self.repair_tool)

    def test_repair_tool_catalogue_matches_user_save(self):
        # The two catalogues must AGREE — that was always the intent.
        #
        # This test used to enforce the agreement by requiring a hardcoded
        # `$CANONICAL_ROLES = [` literal in the repair tool and checking
        # ten role names appeared in both files. That required the duplicate
        # list, and only sampled 10 of the 16 roles, so it stayed green
        # while the copy drifted: by 2026-11-26 the tool listed 10 roles
        # against user-save.php's 16, and could not repair an account stored
        # as accounts_office, content_editor, photo_importer, photo_dept,
        # mezmur_attendance_taker or hr_attendance_taker.
        #
        # The tool now DERIVES its catalogue from user-save.php, so there is
        # no copy to drift. This asserts the derivation instead of the
        # duplication, and pins completeness by comparing against the real
        # $validRoles rather than a sample.
        self.assertNotIn("$CANONICAL_ROLES = [", self.repair_tool,
                         "the repair tool must derive its role catalogue, not copy it")
        self.assertIn("backend/user-save.php", self.repair_tool)
        self.assertIn("$validRoles", self.repair_tool)

        m = re.search(r"\$validRoles\s*=\s*\[(.*?)\n\];", self.user_save, re.S)
        self.assertIsNotNone(m, "$validRoles not found in user-save.php")
        body = re.sub(r"/\*.*?\*/", "", m.group(1), flags=re.S)
        body = re.sub(r"(?m)^\s*//.*$", "", body)
        creatable = set(re.findall(r"'([a-z_]+)'", body))

        # Every creatable role must be repairable, or an account created
        # through the admin UI cannot be fixed when its role is wrong.
        self.assertEqual(16, len(creatable), f"expected 16 staff roles, got {sorted(creatable)}")
        for role in creatable:
            self.assertIn(f"'{role}'", self.user_save)
        # member/parent are provisioned only by ACM activation, never here.
        self.assertNotIn("member", creatable)
        self.assertNotIn("parent", creatable)

    # ── Syntax gates ────────────────────────────────────────────────────
    def test_routers_lint_clean(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        for rel in [
            "admin/dashboard.php",
            "frontend/pages/dashboard.php",
            "admin/tools/repair_user_role.php",
            "admin/api_mezmur.php",
            "backend/api/mezmur.php",
            "frontend/pages/mezmur_dept.php",
        ]:
            proc = subprocess.run(
                [php, "-l", str(ROOT / rel)],
                capture_output=True, text=True, timeout=30,
            )
            self.assertEqual(proc.returncode, 0, f"php -l failed for {rel}: {proc.stdout}{proc.stderr}")


if __name__ == "__main__":
    unittest.main()
