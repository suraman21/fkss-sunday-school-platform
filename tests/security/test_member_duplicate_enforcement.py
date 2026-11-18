import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class MemberDuplicateEnforcementTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.controller = (ROOT / "admin/hr_register_member.php").read_text()
        cls.service = (
            ROOT / "admin/backend/services/MemberDuplicateService.php"
        ).read_text()
        cls.advisory = (ROOT / "admin/api_check_duplicate.php").read_text()
        cls.hr_ui = (ROOT / "admin/dashboards/hr-dept.php").read_text()
        cls.school_ui = (ROOT / "admin/dashboards/school_admin.php").read_text()
        cls.request_ids = (ROOT / "admin/js/request-id.js").read_text()
        cls.migration = (
            ROOT / "sql/016_member_duplicate_lookup.sql"
        ).read_text()

    def test_identity_normalization_fixture(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        completed = subprocess.run(
            [php, str(ROOT / "tests/fixtures/member_duplicate_policy.fixture"), str(ROOT)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        result = json.loads(completed.stdout.strip().splitlines()[-1])
        self.assertEqual(result["name_spaces"], "Abebe Kebede")
        self.assertEqual(result["name_unicode"], "አበበ ከበደ")
        self.assertEqual(result["phone_local"], "911234567")
        self.assertEqual(result["phone_local"], result["phone_country"])
        self.assertEqual(result["phone_short"], "")

    def test_advisory_and_enforcement_share_one_bounded_service(self):
        self.assertIn("MemberDuplicateService::findAdvisoryMatches", self.advisory)
        self.assertIn("MemberDuplicateService::findStrongMatch", self.controller)
        self.assertIn("LIMIT {$limit}", self.service)
        self.assertIn("WHERE student_name = ? AND father_name = ?", self.service)
        self.assertNotIn("LOWER(TRIM(student_name))", self.advisory + self.service)
        self.assertNotIn("phone_number LIKE", self.advisory + self.service)
        self.assertIn("Cache-Control: no-store, private", self.advisory)
        self.assertIn("requestDuplicateCheck", self.hr_ui)
        self.assertIn("method: 'POST'", self.hr_ui)
        self.assertNotIn("api_check_duplicate.php?' +", self.hr_ui)

    def test_strong_match_is_checked_under_the_write_transaction(self):
        begin = self.controller.index("$conn->begin_transaction()")
        check = self.controller.index("MemberDuplicateService::findStrongMatch")
        insert = self.controller.index("INSERT INTO members")
        commit = self.controller.index("$conn->commit()")
        self.assertLess(begin, check)
        self.assertLess(check, insert)
        self.assertLess(insert, commit)
        self.assertIn("FOR UPDATE", self.service)
        self.assertIn("SELECT GET_LOCK(?, ?)", self.service)
        self.assertIn("SELECT RELEASE_LOCK(?)", self.service)
        lock = self.controller.index("acquireStrongIdentityLock")
        check = self.controller.index("MemberDuplicateService::findStrongMatch")
        self.assertLess(lock, check)
        self.assertIn("DuplicateMemberException", self.controller)
        self.assertIn("'status' => 'duplicate'", self.controller)
        self.assertIn("Member Registration Duplicate Blocked", self.controller)

    def test_override_is_explicit_role_gated_and_audited(self):
        self.assertIn("$registrationPolicy['allow_upgrade']", self.controller)
        self.assertIn("duplicate_override", self.controller)
        self.assertIn("'duplicate_match_id'", self.controller)
        self.assertIn("formDataToSubmit.set('duplicate_override', '1')", self.hr_ui)
        self.assertIn("duplicate_override_reason", self.controller)
        self.assertIn("duplicateOverrideReason", self.hr_ui)
        self.assertNotIn("duplicate_override", self.school_ui)

    def test_registration_retries_are_idempotent(self):
        self.assertIn("ApiIdempotencyService", self.controller)
        self.assertIn("beginRegistrationIdempotency", self.controller)
        self.assertIn("registration_request_id", self.controller)
        self.assertIn("Idempotency-Replayed: true", self.controller)
        self.assertIn("registration_request_id", self.hr_ui)
        # School-admin registration was removed entirely on 2026-11-18:
        # the school dashboard carries no registration request path.
        self.assertNotIn("registration_request_id", self.school_ui)
        self.assertNotIn("/admin/js/request-id.js", self.school_ui)
        self.assertNotIn("quickAddInFlight", self.school_ui)
        self.assertIn("/admin/js/request-id.js", self.hr_ui)
        self.assertIn("crypto.randomUUID", self.request_ids)
        self.assertIn("crypto.getRandomValues", self.request_ids)

    def test_deployment_has_composite_candidate_index(self):
        self.assertIn("idx_members_duplicate_name", self.migration)
        self.assertIn("idx_members_duplicate_advisory", self.migration)
        self.assertIn("`student_name`(63), `father_name`(63), `grandfather_name`(63)", self.migration)
        self.assertIn("information_schema.statistics", self.migration)


if __name__ == "__main__":
    unittest.main()
