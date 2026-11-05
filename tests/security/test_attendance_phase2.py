"""
Attendance alerting — Phase 2 guards (calculation engine, warnings, bell,
dashboard card, rollout).

Static part always runs. The live part needs the local PHP test server on
SSMS_TEST_BASE (default http://127.0.0.1:8080), the seeded `ssms_test`
database with sql/071 + sql/072 applied and FEATURE_ATTENDANCE_V2 on; it
skips cleanly otherwise. Reuses the login helpers from test_attendance_phase1.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import unittest
import urllib.error
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from test_attendance_phase1 import (  # noqa: E402
    BASE, WebSession, db, mobile, mobile_token, _live_ready,
)


def _mobile_token(role):
    """mobile_token() that skips (not fails) when earlier suites exhausted the login rate limit."""
    try:
        return mobile_token(role)
    except urllib.error.HTTPError as e:
        if e.code == 429:
            raise unittest.SkipTest("mobile login rate-limited by earlier suites — rerun later")
        raise

GREG = re.compile(r"\b\d{4}-\d{2}-\d{2}\b")


def read(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


def strip_php_comments(src):
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    return "\n".join(l for l in src.splitlines() if not l.strip().startswith(("//", "*", "#")))


class Phase2StaticGuards(unittest.TestCase):
    def test_sql_072_is_idempotent_and_shared_hosting_safe(self):
        sql = read("sql/072_attendance_warnings.sql")
        self.assertEqual(sql.count("CREATE TABLE IF NOT EXISTS"), 2)
        self.assertIn("`attendance_warnings`", sql)
        self.assertIn("`attendance_warning_events`", sql)
        self.assertIn("UNIQUE KEY `uq_aw_dedupe` (`dedupe_key`)", sql)
        self.assertIn("ENUM('contacted','excused','escalated','deactivated','no_action')", sql)
        upper = "\n".join(l for l in sql.splitlines() if not l.strip().startswith("--")).upper()
        for forbidden in [" JSON ", "CHECK (", "GENERATED ALWAYS", "CREATE EVENT", "CREATE TRIGGER"]:
            self.assertNotIn(forbidden, upper, forbidden)
        self.assertIn("ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", sql)

    def test_automation_never_writes_members_status(self):
        for rel in [
            "admin/backend/services/AttendanceWarningService.php",
            "admin/backend/services/AttendanceRiskService.php",
            "admin/api_attendance_warnings.php",
            "api/v1/routes/attendance_warnings.php",
            "scripts/attendance_rollout_2026.php",
            "admin/api_attendance.php",
            "admin/api_education.php",
            "api/v1/routes/attendance.php",
        ]:
            src = strip_php_comments(read(rel)).lower()
            self.assertNotRegex(src, r"update\s+members\s+set\s+status", rel)

    def test_bell_targets_departments_hr_info_only(self):
        svc = read("admin/backend/services/AttendanceWarningService.php")
        self.assertIn("'edu' => 'edu_dept,hr_dept,info_dept'", svc)
        self.assertIn("'mezmur' => 'mezmur_dept,hr_dept,info_dept'", svc)
        roles_block = re.search(r"BELL_ROLES = \[(.*?)\];", svc, re.S).group(1)
        self.assertNotIn("school_admin", roles_block)
        self.assertNotIn("super_admin", roles_block)
        wf = read("admin/backend/workflow.php")
        m = re.search(r"'attendance_warning' => \[(.*?)\]", wf)
        self.assertIsNotNone(m)
        self.assertNotIn("admin", m.group(1))
        self.assertNotIn("'attendance_issue' =>", wf)

    def test_legacy_producers_removed(self):
        api = strip_php_comments(read("admin/api_attendance.php"))
        self.assertNotIn("'attendance_alert'", api)
        self.assertNotIn("Consecutive Absence Alert", api)
        summary = strip_php_comments(read("admin/backend/services/AttendanceSummaryService.php"))
        self.assertNotIn("'attendance_issue'", summary)

    def test_web_save_creates_submitted_packet_and_evaluates(self):
        api = read("admin/api_attendance.php")
        i_replace = api.index("AttendanceRecordService::replaceSheet(")
        i_packet = api.index("SubmissionService::upsertAttendance($conn, [")
        i_commit = api.index("$conn->commit();", i_replace)
        self.assertLess(i_replace, i_packet)
        self.assertLess(i_packet, i_commit, "packet must be written inside the save transaction (T-2)")
        self.assertIn("STATUS_SUBMITTED", api[i_packet:i_commit])
        self.assertIn("'force' => true", api[i_packet:i_commit])
        self.assertIn("AttendanceWarningService::safeEvaluateMany(", api)

    def test_hooks_present_on_every_official_write_path(self):
        self.assertIn("AttendanceWarningService::safeEvaluateMany(", read("admin/api_education.php"))
        mob = read("api/v1/routes/attendance.php")
        submit = mob[mob.index("$action === 'submit'"):mob.index("// GET /attendance/daily-stats")]
        draft = mob[mob.index("$method === 'POST' && ($action === '' || $action === null)"):mob.index("$action === 'submit'")]
        self.assertIn("safeEvaluateMany", submit)
        self.assertNotIn("safeEvaluateMany", draft, "drafts must not feed the risk engine")
        mez = read("admin/api_mezmur.php")
        self.assertIn("$packetStatus === MezmurSubmissionService::STATUS_SUBMITTED", mez)
        self.assertIn("safeEvaluateMany", mez)
        self.assertIn("safeEvaluateMany", read("api/v1/routes/mezmur.php"))
        self.assertIn("safeEvaluate($conn, $dept, $memberId", read("admin/api_department_status.php"))
        self.assertIn("safeEvaluate($conn, $dept, $id", read("api/v1/routes/members.php"))

    def test_access_control_and_feature_gate_cover_new_endpoints(self):
        ac = read("admin/access_control.php")
        self.assertIn("'api_attendance_warnings.php' => ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept']", ac)
        self.assertIn("'attendance_warnings.php' =>", ac)
        fg = read("admin/backend/services/FeatureGate.php")
        self.assertIn("'api_attendance_warnings.php' => 'attendance'", fg)
        self.assertIn("'attendance_warnings.php' => 'attendance'", fg)

    def test_close_permissions_in_service_and_apis(self):
        svc = read("admin/backend/services/AttendanceWarningService.php")
        self.assertIn("CLOSE_ROLES = ['edu' => ['edu_dept', 'hr_dept', 'info_dept'], 'mezmur' => ['mezmur_dept', 'hr_dept', 'info_dept']]", svc)
        self.assertIn("roleMayClose", read("admin/api_attendance_warnings.php"))
        self.assertIn("roleMayClose", read("api/v1/routes/attendance_warnings.php"))

    def test_ui_uses_only_ec_labels(self):
        js = read("admin/js/attendance_warnings.js")
        for key in ["opened_ec", "closed_ec", "start_ec", "reason_am", "absence_dates_ec"]:
            self.assertIn(key, js)
        self.assertNotIn("toISOString", js)
        self.assertNotIn("toLocaleDateString", js)
        page = read("admin/attendance_warnings.php")
        self.assertNotIn("cdn.", page)
        self.assertNotIn("$conn->query", page)

    def test_dashboards_show_open_warnings_card(self):
        for rel in ["admin/dashboards/info-dept.php", "admin/dashboards/hr-dept.php", "admin/dashboards/edu_dept.php", "frontend/pages/mezmur_dept.php"]:
            self.assertIn('id="aw-dashboard-card"', read(rel), rel)
        self.assertNotIn("At-risk Students", read("admin/dashboards/info-dept.php"))
        self.assertNotIn("At-risk Students", read("admin/dashboards/hr-dept.php"))

    def test_php_syntax_and_js_syntax(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        for rel in [
            "admin/backend/services/AttendanceRiskService.php",
            "admin/backend/services/AttendanceWarningService.php",
            "admin/api_attendance_warnings.php",
            "admin/attendance_warnings.php",
            "api/v1/routes/attendance_warnings.php",
            "api/v1/routes/attendance.php",
            "api/v1/routes/members.php",
            "api/v1/routes/mezmur.php",
            "admin/api_attendance.php",
            "admin/api_education.php",
            "admin/api_mezmur.php",
            "admin/dashboards/info-dept.php",
            "admin/dashboards/hr-dept.php",
            "admin/dashboards/edu_dept.php",
            "scripts/attendance_rollout_2026.php",
        ]:
            r = subprocess.run([php, "-l", str(ROOT / rel)], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, rel + ": " + r.stdout)
        node = shutil.which("node")
        if node:
            for rel in ["admin/js/attendance_warnings.js", "admin/js/department_status.js", "frontend/js/mezmur.js"]:
                r = subprocess.run([node, "--check", str(ROOT / rel)], capture_output=True, text=True)
                self.assertEqual(r.returncode, 0, rel + ": " + r.stderr)

    def test_rollout_script_is_cli_only_and_dry_run_by_default(self):
        src = read("scripts/attendance_rollout_2026.php")
        self.assertIn("PHP_SAPI !== 'cli'", src)
        self.assertIn("'source' => 'rollout', 'notify' => false", src)
        self.assertIn("--apply", src)


def _phase2_ready():
    if not _live_ready():
        return False
    try:
        return db("SHOW TABLES LIKE 'attendance_warnings'") != ""
    except Exception:
        return False


@unittest.skipUnless(_phase2_ready(), "local test server / sql 072 not available")
class Phase2LiveGuards(unittest.TestCase):
    MID = 900001          # class 1 (seed)
    ROLLOUT_MID = 900010  # fixture for the rollout script
    CLASS_ID = 1
    # Tikimt 2019 EC, all in the past relative to the sandbox date (2026-10-19)
    DATES = ["2026-10-12", "2026-10-13", "2026-10-14", "2026-10-15", "2026-10-16"]
    MOBILE_DATE = "2026-10-17"
    _sessions = {}

    @classmethod
    def _clean(cls):
        ids = f"{cls.MID}, {cls.ROLLOUT_MID}"
        dates = "','".join(cls.DATES + [cls.MOBILE_DATE, "2026-10-18"])
        db(f"DELETE FROM attendance WHERE member_id IN ({ids}) AND attendance_date BETWEEN '2026-09-01' AND CURDATE()")
        db(f"DELETE FROM attendance WHERE class_id={cls.CLASS_ID} AND attendance_date IN ('{dates}')")
        db(f"DELETE FROM grade_submissions WHERE submission_type='attendance' AND class_id={cls.CLASS_ID} AND attendance_date IN ('{dates}')")
        db(f"DELETE FROM attendance_warnings WHERE member_id IN ({ids})")
        db(f"DELETE FROM notifications WHERE type='attendance_warning' AND JSON_EXTRACT(data,'$.member_id') IN ({ids})")
        db(f"DELETE FROM member_department_periods WHERE member_id IN ({ids})")
        db("UPDATE attendance_policies SET consecutive_threshold=5, prolonged_threshold=8, critical_threshold=10, auto_deactivate=0, low_rate_threshold=75.00, min_sessions=99")

    @classmethod
    def setUpClass(cls):
        cls._clean()

    @classmethod
    def tearDownClass(cls):
        cls._clean()
        db("UPDATE attendance_policies SET min_sessions=3")

    def s(self, role):
        if role not in self._sessions:
            self._sessions[role] = WebSession(role)
        return self._sessions[role]

    def setUp(self):
        status, data = self.s("hr_dept").get_json("/admin/api_attendance_warnings.php?action=counts")
        if not (status == 200 and data.get("available")):
            self.skipTest("FEATURE_ATTENDANCE_V2 is off in this environment")

    # ── helpers ───────────────────────────────────────────────────
    def _roster(self, date):
        status, data = self.s("edu_dept").get_json(f"/admin/api_attendance.php?action=get_class_attendance&class_id={self.CLASS_ID}&date={date}")
        self.assertEqual(status, 200, data)
        return [int(s["member_id"]) for s in data["students"]]

    def _web_save(self, date, absent_ids):
        roster = self._roster(date)
        records = [{"member_id": m, "status": "absent" if m in absent_ids else "present"} for m in roster]
        sess = self.s("edu_dept")
        import urllib.parse
        body = urllib.parse.urlencode({
            "action": "save_attendance", "class_id": self.CLASS_ID, "date": date,
            "records": json.dumps(records), "csrf_token": sess.csrf,
        }).encode()
        status, raw = sess._open(BASE + "/admin/api_attendance.php", data=body, headers={"X-CSRF-Token": sess.csrf})
        data = json.loads(raw.decode("utf-8"))
        self.assertEqual(status, 200, data)
        self.assertEqual(data.get("status"), "success", data)
        return data

    def _warnings(self, mid):
        return db(f"SELECT id, kind, stage, streak_length, status, source FROM attendance_warnings WHERE member_id={mid} ORDER BY id")

    # ── 1. web saves create submitted packets and open a warning ──
    def test_01_web_saves_open_consecutive_warning_and_bell(self):
        before_status = db(f"SELECT status FROM members WHERE id={self.MID}")
        for d in self.DATES[:4]:
            self._web_save(d, {self.MID})
            self.assertEqual(db(f"SELECT status FROM grade_submissions WHERE submission_type='attendance' AND class_id={self.CLASS_ID} AND attendance_date='{d}' ORDER BY id DESC LIMIT 1"), "submitted", d)
        self.assertEqual(self._warnings(self.MID), "", "4 absences must not warn")
        self._web_save(self.DATES[4], {self.MID})
        rows = self._warnings(self.MID)
        self.assertIn("consecutive\tstandard\t5\topen\tlive", rows, rows)
        bells = db(f"SELECT target_roles, priority, message FROM notifications WHERE type='attendance_warning' AND JSON_EXTRACT(data,'$.member_id')={self.MID}")
        self.assertEqual(bells.count("\n") + 1, 1, bells)
        self.assertTrue(bells.startswith("edu_dept,hr_dept,info_dept\thigh\t"), bells)
        self.assertIsNone(GREG.search(bells), bells)
        self.assertIn("Tikimt 2, 2019", bells)
        # idempotent re-save: no duplicate row, no second bell
        self._web_save(self.DATES[4], {self.MID})
        self.assertEqual(self._warnings(self.MID).count("\n"), 0)
        self.assertEqual(db(f"SELECT COUNT(*) FROM notifications WHERE type='attendance_warning' AND JSON_EXTRACT(data,'$.member_id')={self.MID}"), "1")
        self.assertEqual(db(f"SELECT status FROM members WHERE id={self.MID}"), before_status, "members.status must never be automated")
        self.assertEqual(db(f"SELECT COUNT(*) FROM notifications WHERE type='attendance_alert' AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"), "0", "legacy bell must be gone")

    # ── 2. web API scoping and close permissions ──────────────────
    def test_02_list_scoping_and_close_permissions(self):
        if self._warnings(self.MID) == "":
            self.test_01_web_saves_open_consecutive_warning_and_bell()
        wid = db(f"SELECT id FROM attendance_warnings WHERE member_id={self.MID} AND status='open' ORDER BY id DESC LIMIT 1")
        for role in ["edu_dept", "hr_dept", "info_dept", "school_admin", "super_admin"]:
            status, data = self.s(role).get_json(f"/admin/api_attendance_warnings.php?action=list&member_id={self.MID}")
            self.assertEqual(status, 200, (role, data))
            self.assertTrue(any(str(i["id"]) == wid for i in data["items"]), role)
            txt = json.dumps(data["items"])
            m = GREG.search(txt)
            self.assertIsNone(m, (role, txt[max(0, (m.start() if m else 0) - 150):(m.end() if m else 0) + 30]))
        status, data = self.s("mezmur_dept").get_json(f"/admin/api_attendance_warnings.php?action=list&member_id={self.MID}")
        self.assertEqual(status, 200)
        self.assertEqual(data["items"], [], "mezmur_dept must not see Education warnings")
        status, data = self.s("mezmur_dept").get_json("/admin/api_attendance_warnings.php?action=list&department=edu")
        self.assertEqual(status, 403)
        status, _ = self.s("teacher").get_json("/admin/api_attendance_warnings.php?action=list")
        self.assertEqual(status, 403)
        # admins read-only, other department never
        for role in ["super_admin", "school_admin", "mezmur_dept"]:
            status, data = self.s(role).post_json("/admin/api_attendance_warnings.php", {"action": "close", "id": wid, "outcome": "contacted"})
            self.assertEqual(status, 403, (role, data))
        self.assertEqual(db(f"SELECT status FROM attendance_warnings WHERE id={wid}"), "open")
        # detail (EC dates in the warning block)
        status, data = self.s("hr_dept").get_json(f"/admin/api_attendance_warnings.php?action=detail&id={wid}")
        self.assertEqual(status, 200, data)
        self.assertIn("member_summary", data)
        self.assertIsNone(GREG.search(json.dumps({k: v for k, v in data["warning"].items() if k != "risk"})))
        self.assertEqual(len(data["warning"]["absence_dates_ec"]), 5)
        # bad outcome 422; edu_dept closes
        status, data = self.s("edu_dept").post_json("/admin/api_attendance_warnings.php", {"action": "close", "id": wid, "outcome": "bogus"})
        self.assertEqual(status, 422, data)
        status, data = self.s("edu_dept").post_json("/admin/api_attendance_warnings.php", {"action": "close", "id": wid, "outcome": "contacted", "note": "Phase 2 live test"})
        self.assertEqual(status, 200, data)
        self.assertEqual(data["warning"]["status"], "closed")
        self.assertEqual(data["warning"]["outcome_label"], "Contacted")
        self.assertEqual(db(f"SELECT COUNT(*) FROM attendance_warning_events WHERE warning_id={wid} AND event='closed'"), "1")
        self.assertNotEqual(db("SELECT COUNT(*) FROM activity_logs WHERE action='Attendance Warning Closed'"), "0")
        # counts card data
        status, data = self.s("info_dept").get_json("/admin/api_attendance_warnings.php?action=counts")
        self.assertEqual(status, 200)
        self.assertIn("edu", data["counts"])
        self.assertIn("mezmur", data["counts"])

    # ── 3. mobile API ─────────────────────────────────────────────
    def test_03_mobile_routes(self):
        if self._warnings(self.MID) == "":
            self.test_01_web_saves_open_consecutive_warning_and_bell()
        wid = db(f"SELECT id FROM attendance_warnings WHERE member_id={self.MID} ORDER BY id DESC LIMIT 1")
        hr = _mobile_token("hr_dept")
        status, data = mobile(hr, "GET", f"/api/v1/attendance/warnings?member_id={self.MID}&status=all")
        self.assertEqual(status, 200, data)
        self.assertTrue(data["data"]["available"])
        self.assertTrue(any(str(i["id"]) == wid for i in data["data"]["items"]), data)
        status, data = mobile(hr, "GET", f"/api/v1/attendance/warnings/{wid}")
        self.assertEqual(status, 200, data)
        self.assertEqual(data["data"]["warning"]["department"], "edu")
        status, data = mobile(hr, "GET", "/api/v1/attendance/warnings/counts")
        self.assertEqual(status, 200, data)
        status, data = mobile(hr, "GET", f"/api/v1/members/{self.MID}/attendance-risk")
        self.assertEqual(status, 200, data)
        self.assertIn("streak", data["data"]["risk"]["edu"])
        self.assertIn("mezmur", data["data"]["risk"])
        teacher = _mobile_token("teacher")
        status, data = mobile(teacher, "GET", "/api/v1/attendance/warnings")
        self.assertEqual(status, 403, data)
        status, data = mobile(teacher, "GET", f"/api/v1/members/{self.MID}/attendance-risk")
        self.assertEqual(status, 403, data)
        sup = _mobile_token("super_admin")
        status, data = mobile(sup, "POST", f"/api/v1/attendance/warnings/{wid}/close", {"outcome": "contacted"})
        self.assertEqual(status, 403, data)
        mez = _mobile_token("mezmur_dept")
        status, data = mobile(mez, "GET", f"/api/v1/attendance/warnings/{wid}")
        self.assertEqual(status, 403, data)

    # ── 4. mobile submit feeds the engine, drafts do not ──────────
    def test_04_mobile_submit_triggers_evaluation_draft_does_not(self):
        teacher = _mobile_token("teacher")
        roster = self._roster(self.MOBILE_DATE)
        # draft for 10-17: rows + incomplete packet => NOT eligible
        records = [{"member_id": m, "status": "absent" if m == self.MID else "present"} for m in roster]
        status, data = mobile(teacher, "POST", "/api/v1/attendance", {"class_id": self.CLASS_ID, "date": self.MOBILE_DATE, "records": records})
        self.assertIn(status, (200, 201), data)
        # web rows for 10-12..10-16 already exist (5 absences) when test_01 ran; count eligible via risk
        hr = _mobile_token("hr_dept")
        status, data = mobile(hr, "GET", f"/api/v1/members/{self.MID}/attendance-risk?department=edu")
        self.assertEqual(status, 200, data)
        streak_before = data["data"]["risk"]["edu"]["streak"]["length"]
        self.assertNotIn(self.MOBILE_DATE, json.dumps(data["data"]["risk"]["edu"]["streak"]["dates"]), "draft day must not count")
        # submit 10-18 (teacher may write: no packet yet) => eligible
        status, data = mobile(teacher, "POST", "/api/v1/attendance/submit", {"class_id": self.CLASS_ID, "date": "2026-10-18", "records": [{"member_id": m, "status": "absent" if m == self.MID else "present"} for m in self._roster("2026-10-18")]})
        self.assertEqual(status, 201, data)
        status, data = mobile(hr, "GET", f"/api/v1/members/{self.MID}/attendance-risk?department=edu")
        self.assertIn("2026-10-18", json.dumps(data["data"]["risk"]["edu"]["streak"]["dates"]))
        self.assertEqual(data["data"]["risk"]["edu"]["streak"]["length"], streak_before + 1)

    # ── 5. rollout script ─────────────────────────────────────────
    def test_05_rollout_dry_run_then_apply_idempotent(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        for d in ["2026-10-05", "2026-10-06", "2026-10-07", "2026-10-08", "2026-10-09", "2026-10-12"]:
            db(f"INSERT IGNORE INTO attendance (member_id,class_id,attendance_date,status,recorded_by) VALUES ({self.ROLLOUT_MID},1,'{d}','absent',1)")
        env = dict(os.environ, SSMS_AUDIT_TESTING="1", SSMS_DB_NAME="ssms_test")
        bells_before = db("SELECT COUNT(*) FROM notifications WHERE type='attendance_warning'")
        dry = subprocess.run([php, "-d", "error_reporting=24575", str(ROOT / "scripts/attendance_rollout_2026.php"), "--department=edu"], capture_output=True, text=True, env=env, cwd=str(ROOT))
        self.assertEqual(dry.returncode, 0, dry.stderr)
        self.assertIn("DRY RUN", dry.stdout)
        self.assertIn(f"#{self.ROLLOUT_MID}", dry.stdout)
        self.assertIsNone(GREG.search(dry.stdout.split("Academic-year window")[1].split("\n")[0]))
        self.assertEqual(db(f"SELECT COUNT(*) FROM attendance_warnings WHERE member_id={self.ROLLOUT_MID}"), "0", "dry run must not write")
        # --verify is read-only and reports legacy dependence of the candidate
        ver = subprocess.run([php, "-d", "error_reporting=24575", str(ROOT / "scripts/attendance_rollout_2026.php"), "--department=edu", "--verify"], capture_output=True, text=True, env=env, cwd=str(ROOT))
        self.assertIn("VERIFY (read-only data check)", ver.stdout)
        self.assertIn("legacy-dependent member ids: " + str(self.ROLLOUT_MID), ver.stdout)
        self.assertIn("VERDICT:", ver.stdout)
        self.assertEqual(db(f"SELECT COUNT(*) FROM attendance_warnings WHERE member_id={self.ROLLOUT_MID}"), "0", "verify must not write")
        # safeguards: no confirmation on a non-tty → refuse (exit 6); plan above --max-open → abort (exit 5)
        noyes = subprocess.run([php, "-d", "error_reporting=24575", str(ROOT / "scripts/attendance_rollout_2026.php"), "--department=edu", "--apply"], capture_output=True, text=True, env=env, cwd=str(ROOT), stdin=subprocess.DEVNULL)
        self.assertEqual(noyes.returncode, 6, noyes.stdout + noyes.stderr)
        self.assertIn("Refusing to write without confirmation", noyes.stderr)
        capped = subprocess.run([php, "-d", "error_reporting=24575", str(ROOT / "scripts/attendance_rollout_2026.php"), "--department=edu", "--apply", "--yes", "--max-open=0"], capture_output=True, text=True, env=env, cwd=str(ROOT))
        self.assertEqual(capped.returncode, 0, capped.stderr)
        self.assertEqual(db(f"SELECT COUNT(*) FROM attendance_warnings WHERE member_id={self.ROLLOUT_MID}"), "1")
        db(f"DELETE FROM attendance_warning_events WHERE warning_id IN (SELECT id FROM attendance_warnings WHERE member_id={self.ROLLOUT_MID})")
        db(f"DELETE FROM attendance_warnings WHERE member_id={self.ROLLOUT_MID}")
        over = subprocess.run([php, "-d", "error_reporting=24575", str(ROOT / "scripts/attendance_rollout_2026.php"), "--department=edu", "--apply", "--yes", "--max-open=1", f"--after-id={self.ROLLOUT_MID - 1}", "--limit=1"], capture_output=True, text=True, env=env, cwd=str(ROOT))
        self.assertEqual(over.returncode, 0, over.stderr)  # exactly 1 planned == cap → allowed
        db(f"DELETE FROM attendance_warning_events WHERE warning_id IN (SELECT id FROM attendance_warnings WHERE member_id={self.ROLLOUT_MID})")
        db(f"DELETE FROM attendance_warnings WHERE member_id={self.ROLLOUT_MID}")
        # batch cursor: a batch that ends before the member must print the resume cursor and open nothing for it
        batch = subprocess.run([php, "-d", "error_reporting=24575", str(ROOT / "scripts/attendance_rollout_2026.php"), "--department=edu", "--apply", "--yes", "--limit=1", f"--after-id={self.ROLLOUT_MID - 2}"], capture_output=True, text=True, env=env, cwd=str(ROOT))
        self.assertEqual(batch.returncode, 0, batch.stderr)
        self.assertIn(f"--after-id={self.ROLLOUT_MID - 1}", batch.stdout)
        self.assertEqual(db(f"SELECT COUNT(*) FROM attendance_warnings WHERE member_id={self.ROLLOUT_MID}"), "0")
        ap = subprocess.run([php, "-d", "error_reporting=24575", str(ROOT / "scripts/attendance_rollout_2026.php"), "--department=edu", "--apply", "--yes"], capture_output=True, text=True, env=env, cwd=str(ROOT))
        self.assertEqual(ap.returncode, 0, ap.stderr)
        self.assertIn("consecutive\tstandard\t6\topen\trollout", self._warnings(self.ROLLOUT_MID))
        self.assertEqual(db("SELECT COUNT(*) FROM notifications WHERE type='attendance_warning'"), bells_before, "rollout rows never notify")
        again = subprocess.run([php, "-d", "error_reporting=24575", str(ROOT / "scripts/attendance_rollout_2026.php"), "--department=edu", "--apply", "--yes"], capture_output=True, text=True, env=env, cwd=str(ROOT))
        self.assertEqual(again.returncode, 0, again.stderr)
        self.assertEqual(self._warnings(self.ROLLOUT_MID).count("\n"), 0, "idempotent")
        m = re.search(r"already open (\d+)", again.stdout)
        self.assertTrue(m and int(m.group(1)) >= 1, again.stdout)

    # ── 6. page shells ────────────────────────────────────────────
    def test_06_pages_render_card_and_list_shell(self):
        for role in ["hr_dept", "info_dept"]:
            status, body = self.s(role)._open(BASE + "/admin/dashboard.php")
            html = body.decode("utf-8", "replace")
            self.assertIn('id="aw-dashboard-card"', html, role)
            self.assertIn("Open Attendance Warnings", html)
            self.assertNotIn("At-risk Students", html)
        status, body = self.s("edu_dept")._open(BASE + "/admin/attendance_warnings.php")
        self.assertEqual(status, 200)
        self.assertIn("Attendance Warnings", body.decode("utf-8", "replace"))
        status, body = self.s("teacher")._open(BASE + "/admin/attendance_warnings.php")
        self.assertNotIn("window.AW_CONFIG", body.decode("utf-8", "replace"))


if __name__ == "__main__":
    unittest.main()
