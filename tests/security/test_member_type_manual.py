"""Manual membership is independent of role, position and legacy batch sync."""
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


def read(path):
    return (ROOT / path).read_text(encoding="utf-8")


class ManualMemberTypeTests(unittest.TestCase):
    def test_real_mariadb_manual_type_matrix(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        if os.environ.get("SSMS_AUDIT_TESTING") != "1":
            self.skipTest("Disposable MariaDB world requires SSMS_AUDIT_TESTING=1")
        run = subprocess.run(
            [php, str(ROOT / "tests/e2e/member_type_manual.php")],
            cwd=ROOT, text=True, capture_output=True, timeout=60,
        )
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        result = json.loads(run.stdout.strip().splitlines()[-1])
        self.assertEqual(result["status"], "PASS")
        self.assertGreaterEqual(result["checks"], 1200)

    def test_live_retired_action_and_security_guards(self):
        if os.environ.get("SSMS_AUDIT_TESTING") != "1":
            self.skipTest("Disposable HTTP world requires SSMS_AUDIT_TESTING=1")
        import urllib.parse
        from test_attendance_phase1 import BASE, WebSession, db, _live_ready
        if not _live_ready():
            self.skipTest("Provisioned local test server is required")
        edu = WebSession("edu_dept")
        endpoint = "/admin/api_education.php"
        before = db("SELECT id, member_type FROM members ORDER BY id")
        for phrase in ("", "SYNC TYPES"):
            payload = urllib.parse.urlencode({"action": "sync_member_types", "confirm_phrase": phrase,
                                              "csrf_token": edu.csrf}).encode()
            status, body = edu._open(BASE + endpoint, data=payload)
            self.assertEqual(status, 410, body)
            self.assertEqual(json.loads(body)["code"], "member_type_manual_only")
        status, body = edu.get_json(endpoint + "?action=sync_member_types")
        self.assertEqual(status, 405, body)
        status, body = edu._open(BASE + endpoint, data=b"action=sync_member_types&csrf_token=invalid")
        self.assertEqual(status, 403, body)
        teacher = WebSession("teacher")
        payload = urllib.parse.urlencode({"action": "sync_member_types", "csrf_token": teacher.csrf}).encode()
        status, body = teacher._open(BASE + endpoint, data=payload)
        self.assertEqual(status, 403, body)
        self.assertEqual(before, db("SELECT id, member_type FROM members ORDER BY id"))

    def test_compatibility_helper_has_no_role_policy_or_writes(self):
        source = read("admin/backend/member_sync.php")
        snapshot = source.split("function syncMemberType(", 1)[1].split("function syncMemberTeacherFlag(", 1)[0]
        self.assertIn("SELECT member_type FROM members WHERE id = ?", snapshot)
        self.assertNotRegex(snapshot, r"\b(?:UPDATE|INSERT|DELETE)\b")
        self.assertNotIn("teacher_assignments", snapshot)
        self.assertNotIn("FROM users", snapshot)
        self.assertNotIn("$overrides[", snapshot)
        batch = source.split("function batchSyncMemberTypes(", 1)[1].split("function getMemberRoleSummary(", 1)[0]
        self.assertNotRegex(batch, r"->(?:query|prepare)|syncMemberType\(")
        self.assertIn("['checked' => 0, 'fixed' => 0, 'details' => []]", batch)

    def test_positions_keep_flags_codes_and_audit_but_not_classification(self):
        source = read("admin/backend/services/PositionSyncService.php")
        self.assertNotIn("syncMemberType", source)
        for hook in ["self::deriveFlags($conn, $memberId)", "self::computeCode(",
                     "SecurityAuditService::record(", "IdentityCodeService::regenerateQr("]:
            self.assertIn(hook, source)

    def test_retired_action_keeps_security_gates_and_cannot_call_batch(self):
        source = read("admin/api_education.php")
        case = source.split("case 'sync_member_types':", 1)[1].split("break;", 1)[0]
        self.assertIn("http_response_code(410)", case)
        self.assertIn("'member_type_manual_only'", case)
        self.assertNotIn("batchSyncMemberTypes", case)
        for gate in [r"requirePostActions\(.*?'sync_member_types'",
                     r"\$__manageActions\s*=\s*\[.*?'sync_member_types'",
                     r"\$ayReadonlyBlocked\s*=\s*\[.*?'sync_member_types'"]:
            self.assertRegex(source, re.compile(gate, re.S))
        self.assertIn("validateCsrf($csrfToken)", source)
        self.assertIn("empty($_SESSION['admin_id'])", source)
        self.assertNotIn("'sync_member_types' => 'education.sync_types'", source)
        ui = read("admin/dashboards/edu_dept.php")
        self.assertNotIn("runMemberTypeSync", ui)
        self.assertNotIn("sync_member_types", ui)
        self.assertIn("tb.special_regular", ui)  # counts are still stored-type counts

    def test_upgrade_omission_preserves_type_before_update_is_built(self):
        source = read("admin/hr_register_member.php")
        upgrade = source.split("if ($upgrade_id > 0) {\n        // Remove fields", 1)[1]
        guard = "if (!array_key_exists('member_type', $_POST))"
        self.assertIn(guard, upgrade)
        self.assertIn("unset($data['member_type']);", upgrade)
        self.assertLess(upgrade.index(guard), upgrade.index("foreach ($data as $col => $v)"))
        # Explicit manual editing/defaults remain available, not locked to one type.
        edit = read("admin/info_manage_member.php")
        self.assertIn("field('member_type', $cur['member_type'] ?? 'regular')", edit)
        self.assertIn("registration_type=?, member_type=?, status=?", edit)
        self.assertIn('name="member_type"', edit)
        policy = read("admin/backend/services/MemberRegistrationPolicy.php")
        self.assertIn("['regular', 'special_regular', 'honorary']", policy)
        api = read("api/v1/routes/members.php")
        self.assertIn("member_type", api)


if __name__ == "__main__":
    unittest.main()
