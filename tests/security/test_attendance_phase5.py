"""
Attendance alerting — Phase 5 guards (assessment follow-ups + make-up
attempts on Education reactivation; requirements §11–§12, plan §Phase 5, T-8).

Static pins (always run):
  * sql/075 is idempotent, additive and shared-hosting safe; two tables,
    UNIQUE(member_id, period_id, assessment_id), attempts linked to the
    follow-up + nullable original_record_id;
  * the service never writes academic_records (no automatic zero), is hooked
    ONLY on an Education inactive → active change after the commit, and
    notifies teachers per user + edu_dept by role;
  * the web API + mobile route use READ_ROLES / WRITE_ROLES, CSRF on writes,
    teacher scoping by teacher_assignments; HR / Info read-only;
  * access_control + FeatureGate know the new files (and the Phase 4 ones);
  * the report card applies make-up precedence without touching originals;
  * every date in the payload is an Ethiopian label.

Live (skipped unless the local server + sql/075 are present):
  * detection window (due_date inside the inactive period), bell rows,
    no duplicates on repeated reactivation, decision transitions (note
    required for exempt / N/A, lock after an attempt), attempt linking,
    report-card precedence, role scoping on web and mobile.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

from test_attendance_phase1 import BASE, WebSession, db, _live_ready, mobile, mobile_token  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def strip_sql_comments(sql: str) -> str:
    return "\n".join(l for l in sql.splitlines() if not l.strip().startswith("--"))


class Phase5StaticGuards(unittest.TestCase):
    SERVICE = "admin/backend/services/AssessmentFollowupService.php"
    API = "admin/api_assessment_followups.php"
    PAGE = "admin/assessment_followups.php"
    JS = "admin/js/assessment_followups.js"
    ROUTE = "api/v1/routes/assessment_followups.php"

    def test_sql_075_is_additive_idempotent_and_shared_hosting_safe(self):
        sql = strip_sql_comments(read("sql/075_assessment_followups.sql"))
        self.assertIn("CREATE TABLE IF NOT EXISTS `assessment_followups`", sql)
        self.assertIn("CREATE TABLE IF NOT EXISTS `assessment_makeup_attempts`", sql)
        self.assertIn("UNIQUE KEY `uq_followup` (`member_id`, `period_id`, `assessment_id`)", sql)
        self.assertIn("ENUM('pending','makeup_required','exempt','not_applicable')", sql)
        self.assertIn("`period_id`     BIGINT UNSIGNED NOT NULL", sql)  # member_department_periods.id is BIGINT
        self.assertIn("`original_record_id` INT UNSIGNED DEFAULT NULL", sql)
        self.assertIn("REFERENCES `assessment_followups` (`id`)", sql)
        for forbidden in ("TRIGGER", "EVENT", "PROCEDURE", "DROP TABLE", "DELETE FROM", "ALTER TABLE `ASSESSMENTS`", "ALTER TABLE `ACADEMIC_RECORDS`"):
            self.assertNotIn(forbidden, sql.upper())
        self.assertEqual(sql.count("ENGINE=InnoDB"), 2)

    def test_service_never_writes_academic_records_and_is_idempotent(self):
        src = read(self.SERVICE)
        body = src.split("final class AssessmentFollowupService", 1)[1]
        self.assertNotRegex(body, r"(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+academic_records", "originals are never written — no automatic zero")
        self.assertNotIn("DELETE FROM", body)
        self.assertIn("INSERT IGNORE INTO ' . self::TABLE", body)
        self.assertIn("WHERE f.member_id = ? AND f.assessment_id = a.id", body)  # no re-open across periods
        self.assertIn("ar.score IS NOT NULL", body)
        self.assertIn("a.due_date BETWEEN ? AND ?", body)
        self.assertIn("t.start_date <= ? AND t.end_date >= ?", body)  # term window when due_date is NULL
        self.assertIn("$period['department'] !== 'edu' || $period['status'] !== 'inactive'", body)
        self.assertIn("FeatureGate::isEnabled('attendance_v2')", body)
        self.assertIn("public const DECISIONS = ['pending', 'makeup_required', 'exempt', 'not_applicable'];", body)
        self.assertIn("public const WRITE_ROLES = ['edu_dept', 'teacher'];", body)
        self.assertIn("public const READ_ROLES = ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept'];", body)
        # decision lock after an attempt + reason required for exempt / N/A
        self.assertIn("$row['attempt_count'] > 0 && $decision !== 'makeup_required'", body)
        self.assertIn("in_array($decision, ['exempt', 'not_applicable'], true) && $note === ''", body)
        self.assertIn("$row['decision'] !== 'makeup_required'", body)
        # attempt links the original row, never edits it
        self.assertIn("originalRecordId($conn, (int)$row['member_id'], (int)$row['assessment_id'])", body)
        # teacher scoping = teacher_assignments class + subject (or class-wide)
        self.assertIn("(subject_id = ? OR subject_id IS NULL)", body)
        self.assertIn("(ta.subject_id = f.subject_id OR ta.subject_id IS NULL)", body)
        # bell: per-teacher rows + edu_dept role row; audit on every write
        self.assertIn("target_roles, target_user_id)\n                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)", body)
        self.assertIn("$roles = 'edu_dept';", body)
        for action in ("Assessment Follow-ups Opened", "Assessment Follow-up Decided", "Assessment Make-up Recorded"):
            self.assertIn(action, body)
        # EC-only presentation
        self.assertIn("ethio_date_format(", body)
        self.assertNotIn("date('Y-m-d', strtotime", body)

    def test_hook_runs_only_for_education_reactivation_after_commit(self):
        src = read("admin/backend/services/DepartmentStatusService.php")
        self.assertIn("require_once __DIR__ . '/AssessmentFollowupService.php';", src)
        hook = "$dept === 'edu' && $status === 'active' && $previous === 'inactive' && $current"
        self.assertIn(hook, src)
        self.assertIn("AssessmentFollowupService::safeOnEducationReactivation($conn, $memberId, (int)$current['id'], $actorId, $auditActor)", src)
        # after the audit (i.e. after the commit), never inside the transaction
        self.assertGreater(src.index(hook), src.index("'Department Status Changed'"))
        self.assertIn("'followups' => $followups", src)

    def test_web_api_roles_csrf_and_scoping(self):
        src = read(self.API)
        self.assertIn("AssessmentFollowupService::READ_ROLES", src)
        self.assertIn("AssessmentFollowupService::roleMayWrite($role)", src)
        self.assertIn("validateCsrf($token)", src)
        self.assertIn("$writeActions = ['decide', 'attempt'];", src)
        self.assertIn("if ($role === 'teacher') {\n        $f['teacher_id'] = $adminId;", src)
        self.assertIn("teacherMayHandle($conn, $adminId, (int)$item['class_id'], (int)$item['subject_id'])", src)
        self.assertIn("SecurityRateLimiter", src)
        self.assertNotIn("getMessage()", src.split("catch (\\DomainException $e)")[0])

    def test_mobile_route_is_mounted_and_scoped(self):
        grades = read("api/v1/routes/grades.php")
        self.assertIn("if ($action === 'followups') {", grades)
        self.assertIn("require __DIR__ . '/assessment_followups.php';", grades)
        self.assertLess(grades.index("if ($action === 'followups')"), grades.index("No active academic year"), "historical assessments reachable without an active year (§12)")
        src = read(self.ROUTE)
        self.assertIn("require_once dirname(__DIR__, 3) . '/admin/backend/services/AssessmentFollowupService.php';", src)
        self.assertIn("class_exists('\\\\App\\\\Services\\\\AssessmentFollowupService')", src)
        self.assertIn("READ_ROLES", src)
        self.assertIn("roleMayWrite($afRole)", src)
        self.assertIn("$extra['teacher_id'] = $afUid;", src)
        self.assertIn("teacherMayHandle($conn, $afUid", src)
        self.assertIn("isApiRateLimited('assessment_followups'", src)
        self.assertIn("in_array($afTail, ['decide', 'attempt'], true)", src)

    def test_access_control_and_feature_gate_know_phase4_and_phase5_files(self):
        ac = read("admin/access_control.php")
        fg = read("admin/backend/services/FeatureGate.php")
        for f in ("api_attendance_monthly.php", "attendance_monthly.php", "api_assessment_followups.php", "assessment_followups.php"):
            self.assertIn(f"'{f}' =>", ac)
            self.assertIn(f"'{f}' => 'attendance'", fg)
        self.assertIn("'api_assessment_followups.php' => ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept']", ac)
        self.assertNotIn("'api_assessment_followups.php' => ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept', 'mezmur_dept'", ac)

    def test_report_card_makeup_precedence_keeps_originals(self):
        src = read("admin/backend/services/ReportCardService.php")
        self.assertIn("require_once __DIR__ . '/AssessmentFollowupService.php';", src)
        self.assertIn("return self::applyMakeupPrecedence($conn, $classId, $yearId, $termId, $out);", src)
        self.assertIn("AssessmentFollowupService::latestMakeupsForClass($conn, $classId)", src)
        self.assertIn("$out[$mid][$sid][$i]['original_score'] = $row['score'];", src)
        self.assertIn("'is_makeup' => !empty($rec['is_makeup']) ? 1 : 0,", src)
        body = src.split("private static function applyMakeupPrecedence", 1)[1]
        self.assertNotIn("UPDATE", body.split("private static function", 1)[0])

    def test_page_js_and_dashboards(self):
        page = read(self.PAGE)
        self.assertIn("AssessmentFollowupService::READ_ROLES", page)
        self.assertIn("'csrf' => generateCsrfToken()", page)
        self.assertIn("ethio_date_format($today, 'F j, Y')", page)
        self.assertNotIn("$conn->query", page)
        self.assertNotIn("https://cdn", page)
        js = read(self.JS)
        self.assertIn("'X-CSRF-Token': cfg.csrf", js)
        self.assertNotIn("toLocaleDateString", js)
        self.assertNotIn("new Date(", js)
        self.assertIn("due_date_ec", js)
        self.assertIn("inactive_from_ec", js)
        edu = read("admin/dashboards/edu_dept.php")
        self.assertIn('id="af-dashboard-card" href="/admin/assessment_followups.php"', edu)
        self.assertIn("AssessmentFollowupService::counts($conn)", edu)
        teacher = read("admin/dashboards/teacher.php")
        self.assertIn('id="af-dashboard-card" href="/admin/assessment_followups.php"', teacher)
        self.assertIn("AssessmentFollowupService::counts($conn, ['teacher_id' => (int)$userId])", teacher)

    def test_php_lint(self):
        for rel in (self.SERVICE, self.API, self.PAGE, self.ROUTE, "admin/backend/services/DepartmentStatusService.php", "admin/backend/services/ReportCardService.php"):
            r = subprocess.run(["php", "-l", str(ROOT / rel)], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


def _phase5_ready():
    if not _live_ready():
        return False
    try:
        return db("SHOW TABLES LIKE 'assessment_makeup_attempts'") != ""
    except Exception:
        return False


@unittest.skipUnless(_phase5_ready(), "local test server / sql 075 not available")
class Phase5LiveGuards(unittest.TestCase):
    """Fixture: member 900006 (class 1, seed) — not used by Phases 1–4 — with
    one assessment due 2026-08-20 (class 1 / subject 1; teacher user 3 is
    assigned class 1 + subject 1) and one due 2026-05-05 (outside the window).
    The inactive period is backdated to 2026-08-10 .. reactivation."""
    MID = 900006
    CLASS_ID = 1
    SUBJECT_ID = 1
    _sessions: dict = {}
    inside_id = 0
    outside_id = 0

    @classmethod
    def _clean(cls):
        db(f"DELETE FROM assessment_followups WHERE member_id={cls.MID}")
        db(f"DELETE FROM academic_records WHERE member_id={cls.MID}")
        db("DELETE FROM assessments WHERE assessment_name IN ('P5 Inside Window','P5 Outside Window')")
        db(f"DELETE FROM member_department_periods WHERE member_id={cls.MID}")
        db(f"DELETE FROM notifications WHERE type='assessment_followup'")

    @classmethod
    def setUpClass(cls):
        cls._clean()
        db("INSERT INTO assessments (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, weight_percentage, max_score, due_date, created_by) "
           f"VALUES ({cls.CLASS_ID},{cls.SUBJECT_ID},1,1,'P5 Inside Window','test',10,20,'2026-08-20',2),"
           f"({cls.CLASS_ID},{cls.SUBJECT_ID},1,1,'P5 Outside Window','quiz',10,10,'2026-05-05',2)")
        cls.inside_id = int(db("SELECT id FROM assessments WHERE assessment_name='P5 Inside Window'").strip().lstrip("\t"))
        cls.outside_id = int(db("SELECT id FROM assessments WHERE assessment_name='P5 Outside Window'").strip().lstrip("\t"))

    @classmethod
    def tearDownClass(cls):
        cls._clean()

    def s(self, role):
        if role not in self._sessions:
            self._sessions[role] = WebSession(role)
        return self._sessions[role]

    def _set_status(self, status, reason):
        hr = self.s("hr_dept")
        st, data = hr.post_json("/admin/api_department_status.php",
                                {"action": "set", "member_id": self.MID, "department": "edu", "status": status, "reason": reason})
        self.assertEqual(st, 200, data)
        return data

    def _reactivate_after_backdated_inactive(self, reason):
        self._set_status("inactive", reason + " off")
        pid = db(f"SELECT id FROM member_department_periods WHERE member_id={self.MID} AND department='edu' AND ended_at IS NULL ORDER BY id DESC LIMIT 1").strip().lstrip("\t")
        db(f"UPDATE member_department_periods SET started_at='2026-08-10 09:00:00' WHERE id={int(pid)}")
        return self._set_status("active", reason + " back")

    def _followups(self):
        return db(f"SELECT id, assessment_id, decision, period_id FROM assessment_followups WHERE member_id={self.MID} ORDER BY id")

    def test_01_detection_window_and_bells(self):
        data = self._reactivate_after_backdated_inactive("phase5 detect")
        rows = [l.split("\t") for l in self._followups().strip().splitlines() if l.strip()]
        rows = [[c for c in r if c != ""] for r in rows]
        self.assertEqual(len(rows), 1, rows)
        self.assertEqual(int(rows[0][1]), self.inside_id)
        self.assertEqual(rows[0][2], "pending")
        self.assertNotIn(str(self.outside_id), [r[1] for r in rows])
        bells = db("SELECT target_user_id, target_roles FROM notifications WHERE type='assessment_followup' ORDER BY id")
        self.assertIn("3", bells)          # teacher user 3 (class 1 + subject 1)
        self.assertIn("edu_dept", bells)   # Education department by role
        # no score was written anywhere
        self.assertEqual(db(f"SELECT COUNT(*) FROM academic_records WHERE member_id={self.MID}").strip().lstrip("\t"), "0")

    def test_02_repeated_reactivation_does_not_duplicate(self):
        before = self._followups()
        self._reactivate_after_backdated_inactive("phase5 repeat")
        self.assertEqual(self._followups(), before)
        self.assertEqual(db(f"SELECT COUNT(*) FROM assessment_followups WHERE member_id={self.MID}").strip().lstrip("\t"), "1")

    def _fid(self):
        raw = db(f"SELECT id FROM assessment_followups WHERE member_id={self.MID} LIMIT 1").strip().lstrip("\t")
        if raw == "":
            # test_01 creates the fixture through the HR web session; when that
            # login was rate-limited (20 logins / 5 min per IP in long runs)
            # test_01 is skipped and there is nothing to assert on here.
            raise unittest.SkipTest("follow-up fixture missing — test_01 was skipped (login rate limit); rerun standalone")
        return int(raw)

    def test_03_teacher_sees_it_and_readers_are_scoped(self):
        fid = self._fid()
        st, data = self.s("teacher").get_json("/admin/api_assessment_followups.php?action=list&open=1")
        self.assertEqual(st, 200, data)
        self.assertTrue(data["available"] and data["can_write"])
        ids = [i["id"] for i in data["items"]]
        self.assertIn(fid, ids)
        item = next(i for i in data["items"] if i["id"] == fid)
        self.assertEqual(item["decision"], "pending")
        self.assertRegex(item["due_date_ec"], r"^[A-Za-z]+ \d{1,2}, \d{4}$")
        self.assertRegex(item["inactive_from_ec"], r"^[A-Za-z]+ \d{1,2}, \d{4}$")
        self.assertNotRegex(json.dumps(data), r"\d{4}-\d{2}-\d{2}")  # no Gregorian in the payload
        for role in ("hr_dept", "info_dept", "super_admin", "school_admin", "edu_dept"):
            st, d = self.s(role).get_json(f"/admin/api_assessment_followups.php?action=detail&id={fid}")
            self.assertEqual(st, 200, (role, d))
            self.assertEqual(d["can_write"], role == "edu_dept", role)
        for role in ("mezmur_dept",):
            st, d = self.s(role).get_json("/admin/api_assessment_followups.php?action=counts")
            self.assertEqual(st, 403, (role, d))

    def test_04_decisions_and_attempt(self):
        fid = self._fid()
        t = self.s("teacher")
        # HR / Info may not write
        for role in ("hr_dept", "info_dept", "super_admin"):
            st, d = self.s(role).post_json("/admin/api_assessment_followups.php", {"action": "decide", "id": fid, "decision": "exempt", "note": "x"})
            self.assertEqual(st, 403, (role, d))
        # exempt without a note → 422
        st, d = t.post_json("/admin/api_assessment_followups.php", {"action": "decide", "id": fid, "decision": "exempt", "note": ""})
        self.assertEqual(st, 422, d)
        # attempt before make-up decision → 422
        st, d = t.post_json("/admin/api_assessment_followups.php", {"action": "attempt", "id": fid, "score": 15})
        self.assertEqual(st, 422, d)
        # make-up required
        st, d = t.post_json("/admin/api_assessment_followups.php", {"action": "decide", "id": fid, "decision": "makeup_required", "note": "sit again"})
        self.assertEqual(st, 200, d)
        self.assertEqual(d["item"]["decision"], "makeup_required")
        self.assertEqual(d["item"]["decided_by"], 3)
        # out-of-range score → 422
        st, d = t.post_json("/admin/api_assessment_followups.php", {"action": "attempt", "id": fid, "score": 25})
        self.assertEqual(st, 422, d)
        st, d = t.post_json("/admin/api_assessment_followups.php", {"action": "attempt", "id": fid, "score": 15, "remarks": "make-up sat"})
        self.assertEqual(st, 200, d)
        self.assertEqual(d["item"]["attempt_count"], 1)
        self.assertEqual(d["item"]["latest_score"], 15)
        self.assertEqual(d["item"]["latest_max_score"], 20)
        self.assertIsNone(d["item"]["attempts"][0]["original_record_id"])  # no original row existed
        # locked after an attempt
        st, d = t.post_json("/admin/api_assessment_followups.php", {"action": "decide", "id": fid, "decision": "exempt", "note": "changed my mind"})
        self.assertEqual(st, 422, d)
        # originals untouched; attempt is a separate row
        self.assertEqual(db(f"SELECT COUNT(*) FROM academic_records WHERE member_id={self.MID}").strip().lstrip("\t"), "0")
        self.assertEqual(db(f"SELECT COUNT(*) FROM assessment_makeup_attempts WHERE followup_id={fid}").strip().lstrip("\t"), "1")
        # audit rows (web actor)
        audit = db("SELECT action FROM activity_logs WHERE action IN ('Assessment Follow-up Decided','Assessment Make-up Recorded','Assessment Follow-ups Opened') ORDER BY id")
        self.assertIn("Assessment Follow-ups Opened", audit)
        self.assertIn("Assessment Follow-up Decided", audit)
        self.assertIn("Assessment Make-up Recorded", audit)

    def test_05_report_card_shows_the_makeup_and_keeps_the_original(self):
        fid = self._fid()
        php = (
            "define('SSMS_AUDIT_TESTING',1); "
            f"require {json.dumps(str(ROOT / 'admin/config.php'))};"
            f"require_once {json.dumps(str(ROOT / 'admin/backend/services/ReportCardService.php'))};"
            f"$c=\\App\\Services\\ReportCardService::getCard($conn,{self.MID},{self.CLASS_ID},1,0);"
            "$o=[];foreach(($c['subjects']??[]) as $s){foreach(($s['assessments']??[]) as $a){$o[]=$a;}}"
            "echo json_encode($o);"
        )
        out = subprocess.run(["php", "-d", "error_reporting=24575", "-r", php], capture_output=True, text=True,
                             env={"SSMS_AUDIT_TESTING": "1", "SSMS_DB_NAME": "ssms_test", "PATH": "/usr/bin:/bin"})
        self.assertEqual(out.returncode, 0, out.stderr)
        self.assertTrue(out.stdout.strip(), out.stderr)
        rows = json.loads(out.stdout.strip().splitlines()[-1])
        mk = [a for a in rows if a["assessment_name"] == "P5 Inside Window"]
        self.assertEqual(len(mk), 1, rows)
        self.assertEqual(mk[0]["score"], 15)
        self.assertEqual(mk[0]["max_score"], 20)
        self.assertEqual(mk[0]["is_makeup"], 1)
        # a non-make-up assessment reports is_makeup 0
        self.assertTrue(all(a["is_makeup"] == 0 for a in rows if a["assessment_name"] != "P5 Inside Window"))
        self.assertEqual(db(f"SELECT COUNT(*) FROM assessment_makeup_attempts WHERE followup_id={fid}").strip().lstrip("\t"), "1")

    def test_06_mobile_route(self):
        fid = self._fid()
        tt = mobile_token("teacher")
        st, d = mobile(tt, "GET", "/api/v1/grades/followups?open=1")
        self.assertEqual(st, 200, d)
        self.assertIn(fid, [i["id"] for i in d["data"]["items"]])
        st, d = mobile(tt, "GET", "/api/v1/grades/followups/counts")
        self.assertEqual(st, 200, d)
        self.assertGreaterEqual(d["data"]["counts"]["makeup_required"], 1)
        st, d = mobile(tt, "GET", f"/api/v1/grades/followups/{fid}")
        self.assertEqual(st, 200, d)
        self.assertEqual(d["data"]["item"]["attempt_count"], 1)
        ht = mobile_token("hr_dept")
        st, d = mobile(ht, "POST", f"/api/v1/grades/followups/{fid}/decide", {"decision": "pending"})
        self.assertEqual(st, 403, d)
        st, d = mobile(ht, "GET", "/api/v1/grades/followups")
        self.assertEqual(st, 200, d)
        self.assertFalse(d["data"]["can_write"])
        mt = mobile_token("mezmur_dept")
        st, d = mobile(mt, "GET", "/api/v1/grades/followups")
        self.assertEqual(st, 403, d)


if __name__ == "__main__":
    unittest.main()
