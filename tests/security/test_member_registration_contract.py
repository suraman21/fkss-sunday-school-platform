import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class MemberRegistrationContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.controller = (ROOT / "admin/hr_register_member.php").read_text()
        cls.policy = (
            ROOT / "admin/backend/services/MemberRegistrationPolicy.php"
        ).read_text()
        cls.category_service = (
            ROOT / "admin/backend/services/MemberCategory.php"
        ).read_text()
        cls.school = (ROOT / "admin/dashboards/school_admin.php").read_text()

    def test_role_aware_policy_executes_without_database(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        completed = subprocess.run(
            [
                php,
                str(ROOT / "tests/fixtures/member_registration_policy.fixture"),
                str(ROOT),
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertNotIn("Fatal error", completed.stdout + completed.stderr)
        result = json.loads(completed.stdout.strip().splitlines()[-1])
        self.assertTrue(result["school_rejected"])
        self.assertTrue(result["hr_upgrade"])
        self.assertTrue(result["permanent_coerced"])
        self.assertTrue(result["transfer_kept"])
        self.assertTrue(result["temporary_waiting"])
        self.assertTrue(result["invalid_rejected"])

    def test_school_admin_registration_is_removed_and_tier_type_cannot_split(self):
        # School-admin registration was removed entirely on 2026-11-18:
        # no UI entry, no request path, and the policy rejects the role.
        self.assertNotIn("quickAddModal", self.school)
        self.assertNotIn("quickAddMember", self.school)
        self.assertNotIn("quickAddInFlight", self.school)
        self.assertNotIn("registration_request_id", self.school)
        self.assertNotIn("/admin/js/request-id.js", self.school)
        self.assertNotIn("fetch('/admin/hr_register_member.php'", self.school)
        self.assertIn("$role === 'school_admin'", self.policy)
        self.assertIn("School administrators cannot register members.", self.policy)
        self.assertIn("$quickAdd = false;", self.policy)
        self.assertIn("s.textContent=String(msg||'')", self.school)
        # Promotion consistency: permanent tier is never waiting type.
        self.assertIn(
            "$tierEarly === 'permanent' && $registrationType === 'waiting'",
            self.policy,
        )
        self.assertIn(
            "$data['registration_type'] = 'direct';", self.controller
        )
        self.assertIn(
            "SELECT registration_type FROM members WHERE id = ?", self.controller
        )

    def test_controller_validates_and_audits_inside_registration_transaction(self):
        self.assertIn("MemberRegistrationPolicy::prepare", self.controller)
        self.assertIn("validateCsrf()", self.controller)
        self.assertIn("REQUEST_METHOD'] !== 'POST'", self.controller)
        self.assertIn("SecurityAuditService::record", self.controller)
        self.assertLess(
            self.controller.index("SecurityAuditService::record"),
            self.controller.index("$conn->commit()"),
        )
        self.assertIn("$registrationPolicy['allow_uploads']", self.controller)
        self.assertIn("$registrationPolicy['allow_upgrade']", self.controller)

    def test_member_codes_follow_ministry_category_system(self):
        identity = (
            ROOT / "admin/backend/services/IdentityCodeService.php"
        ).read_text()
        # FORMAT v2 (ANALYSIS/08): {PREFIX}-{random unique 5-digit tail}.
        # Uniqueness comes from the members.member_code UNIQUE key with a
        # bounded indexed probe — race-safe at six-figure rosters without
        # locks or sequential scans (which were retired with the old
        # per-category numbers).
        self.assertNotIn("GET_LOCK", identity)
        self.assertNotIn("MAX(CAST(SUBSTRING(member_code, 2) AS UNSIGNED))", identity)
        self.assertIn("random_int(self::TAIL_MIN, self::TAIL_MAX)", identity)
        self.assertIn("STUDENT_REGEX", identity)
        self.assertIn("HEAD_MARKER = 'H'", identity)
        self.assertIn("ORDINARY_MARKER = 'N'", identity)
        # Registration delegates code issuance to the single position-sync
        # writer (positions + manual category; never guessed).
        self.assertIn("PositionSyncService::applyPositions", self.controller)
        enrollment = (
            ROOT / "admin/backend/services/EnrollmentService.php"
        ).read_text()
        self.assertIn("IdentityCodeService::allocateStudent", enrollment)

    def test_route_authorizes_only_registration_owners(self):
        access = (ROOT / "admin/access_control.php").read_text()
        self.assertIn(
            "'hr_register_member.php'  => ['super_admin', 'hr_dept']",
            access,
        )
        self.assertIn(
            "'api_import_members.php' => ['super_admin', 'hr_dept'],",
            access,
        )


if __name__ == "__main__":
    unittest.main()
