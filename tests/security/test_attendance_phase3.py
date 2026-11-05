"""
Attendance alerting — Phase 3 guards (2026-10-19).

Requirements §5: "authorized staff may reopen or reject a submission;
reopening and rejection must require a specific reason; audit who
submitted / who reopened or rejected / when / why; after relevant
changes recalculate streaks, risk and open warnings; reopened, rejected,
incomplete or invalid records must not create false absence warnings."

Static part pins the shape (one shared decision path per department,
reason rule, role rule for reopening APPROVED packets, recalculation hook,
append-only history table). Live part drives the real flows against the
local test stack and is skipped when it is not available.
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
import urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from test_attendance_phase1 import (  # noqa: E402
    BASE, WebSession, db, mobile, mobile_token, _live_ready,
)


def read(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


def strip_php_comments(src):
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    return re.sub(r"(?m)^\s*(//|#).*$", "", src)


def _mobile_token(role):
    try:
        return mobile_token(role)
    except urllib.error.HTTPError as e:
        if e.code == 429:
            raise unittest.SkipTest("mobile login rate-limited by earlier suites — rerun later")
        raise


class Phase3StaticGuards(unittest.TestCase):
    def test_sql_073_is_idempotent_and_shared_hosting_safe(self):
        sql = read("sql/073_attendance_submission_events.sql")
        self.assertIn("CREATE TABLE IF NOT EXISTS `attendance_submission_events`", sql)
        code = re.sub(r"(?m)^\s*--.*$", "", sql).upper()
        for forbidden in ("JSON", "CHECK (", "GENERATED", "CREATE EVENT", "CREATE TRIGGER", "FOREIGN KEY"):
            self.assertNotIn(forbidden, code, forbidden)
        self.assertIn("ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", sql)
        for col in ("`department`", "`packet_id`", "`from_status`", "`to_status`", "`actor_id`", "`reason`", "`created_at`"):
            self.assertIn(col, sql)

    def test_event_service_is_append_only_and_best_effort(self):
        src = strip_php_comments(read("admin/backend/services/AttendanceSubmissionEventService.php"))
        self.assertNotIn("DELETE FROM", src)
        self.assertNotIn("UPDATE attendance_submission_events", src)
        self.assertIn("INSERT INTO attendance_submission_events", src)
        # never fails the save / review that triggered it
        self.assertIn("catch (\\Throwable", src)
        self.assertIn("tableExists", src)
        # EC-only timestamps for users
        self.assertIn("ethio_date_format", src)
        self.assertIn("'created_ec'", src)

    def test_policy_gains_reopen_rule_but_stays_neutral(self):
        pol = read("admin/backend/services/ReviewTransitionPolicy.php")
        self.assertIn("public static function reopenError", pol)
        self.assertIn("public static function isReopenable", pol)
        # approving never becomes a reopen; strict rule stays for it
        body = pol[pol.index("public static function reopenError"):pol.index("public static function isReopenable")]
        self.assertIn("$target === self::STATUS_APPROVED", body)
        self.assertIn("$cur === self::STATUS_SUBMITTED || $cur === self::STATUS_APPROVED", body)
        for leak in ("grade_submissions", "mezmur_submissions", "mysqli", "edu_dept", "mezmur_dept", "hr_dept"):
            self.assertNotIn(leak, pol, leak)

    def test_education_has_one_shared_decision_path(self):
        svc = read("admin/backend/services/SubmissionService.php")
        self.assertIn("public static function reviewPacket(", svc)
        body = svc[svc.index("public static function reviewPacket("):svc.index("public static function recalculateAttendanceWarnings")]
        # reason rule, role rule for reopening approved attendance, guarded update, audit, history, recalc
        self.assertIn("mb_strlen($notes) < 3", body)
        self.assertIn("ATTENDANCE_REOPEN_ROLES", body)
        self.assertIn("ReviewTransitionPolicy::reopenError(", body)
        self.assertIn("self::reviewTransitionError($previousStatus, $newStatus)", body)
        self.assertIn("WHERE id = ? AND status = ?", body)
        self.assertIn("$affected < 1", body)
        self.assertIn("SecurityAuditService::record", body)
        self.assertIn("AttendanceSubmissionEventService::record(", body)
        self.assertIn("self::recalculateAttendanceWarnings(", body)
        self.assertIn("public const ATTENDANCE_REOPEN_ROLES = ['edu_dept'];", svc)
        # only ATTENDANCE packets can be reopened from approved — mark lists keep the strict rule
        self.assertIn("$isAttendance\n            && self::normalizeStatus($previousStatus) === self::STATUS_APPROVED", body)
        # web + mobile both delegate (no second copy of the UPDATE)
        web = read("admin/api_communication.php")
        mob = read("api/v1/routes/grades.php")
        for src in (web, mob):
            self.assertIn("SubmissionService::reviewPacket(", src)
            self.assertNotIn("UPDATE grade_submissions\n            SET status = ?, reviewed_by = ?", src)
        self.assertIn("'mobile'", mob[mob.index("submission-review"):])

    def test_mezmur_reopen_rule_role_and_recalc(self):
        svc = read("admin/backend/services/MezmurSubmissionService.php")
        self.assertIn("public const REOPEN_ROLES = ['mezmur_dept'];", svc)
        body = svc[svc.index("public static function reviewPacket("):svc.index("public static function recalculateAttendanceWarnings")]
        self.assertIn("ReviewTransitionPolicy::reopenError(", body)
        self.assertIn("ReviewTransitionPolicy::error(", body)
        self.assertIn("WHERE id = ? AND status = 'approved'", body)
        self.assertIn("WHERE id = ? AND status = 'submitted'", body)
        self.assertIn("AttendanceSubmissionEventService::record(", body)
        self.assertIn("self::recalculateAttendanceWarnings(", body)
        # callers pass the actor role so the server, not the client, decides
        self.assertIn("(string)$mezmurRole,", read("admin/api_mezmur.php"))
        self.assertIn("(string)($auth['rol'] ?? $auth['role'] ?? ''),", read("api/v1/routes/mezmur.php"))

    def test_submission_steps_are_recorded_on_both_departments(self):
        edu = read("admin/backend/services/SubmissionService.php")
        mez = read("admin/backend/services/MezmurSubmissionService.php")
        self.assertGreaterEqual(edu.count("AttendanceSubmissionEventService::record("), 3)  # update, insert, review
        self.assertGreaterEqual(mez.count("AttendanceSubmissionEventService::record("), 3)
        # detail views expose the history + reopen flag for the UIs
        for src in (edu, mez):
            self.assertIn("$packet['events'] = AttendanceSubmissionEventService::forPacket(", src)
            self.assertIn("$packet['can_reopen'] = ReviewTransitionPolicy::isReopenable(", src)

    def test_recalc_never_writes_members_status(self):
        for rel in ("admin/backend/services/AttendanceSubmissionEventService.php",
                    "admin/backend/services/ReviewTransitionPolicy.php"):
            src = strip_php_comments(read(rel))
            self.assertNotIn("UPDATE members", src, rel)
        svc = strip_php_comments(read("admin/backend/services/SubmissionService.php"))
        body = svc[svc.index("public static function reviewPacket("):]
        self.assertNotIn("UPDATE members", body)

    def test_web_uis_offer_reopen_and_history(self):
        edu = read("admin/dashboards/edu_dept.php")
        self.assertIn("CAN_REOPEN_ATTENDANCE", edu)
        self.assertIn("subTabApproved", edu)
        self.assertIn("Reopen for correction", edu)
        self.assertIn("(s.events||[])", edu)
        mez_js = read("frontend/js/mezmur.js")
        self.assertIn("function packetHistoryHtml", mez_js)
        self.assertIn("'approved', 'insights'", mez_js)
        mez_page = read("frontend/pages/mezmur_dept.php")
        self.assertIn("mzSubTabApproved", mez_page)
        self.assertNotIn("style=", mez_page[mez_page.index("mzSubTabApproved") - 200:mez_page.index("mzSubTabApproved") + 300])
        self.assertIn(".packet-history", read("themes/components.css"))

    def test_q1_non_approve_decisions_are_department_only(self):
        """Owner decision Q-1 (2026-10-19): returning / rejecting an
        attendance sheet — submitted OR approved — is the department's call;
        admins may only approve. Mark lists keep the historical roles."""
        edu = read("admin/backend/services/SubmissionService.php")
        self.assertIn("if ($isAttendance && $newStatus !== 'approved' && !in_array($actorRole, self::ATTENDANCE_REOPEN_ROLES, true))", edu)
        self.assertIn("public const ATTENDANCE_REOPEN_ROLES = ['edu_dept'];", edu)
        mez = read("admin/backend/services/MezmurSubmissionService.php")
        self.assertIn("if ($newStatus !== self::STATUS_APPROVED && !in_array($actorRole, self::REOPEN_ROLES, true))", mez)
        self.assertIn("public const REOPEN_ROLES = ['mezmur_dept'];", mez)

    def test_q2_review_bell_targets_one_user_and_deep_links(self):
        """Owner decision Q-2: the teacher / taker gets an in-app bell with
        the reason (target_user_id, never role-broadcast), EC date in the
        text, machine date only in the data block for the deep-link."""
        for rel, fn, dept in (("admin/backend/services/SubmissionService.php", "notifyTeacherOfReview", "edu_dept"),
                              ("admin/backend/services/MezmurSubmissionService.php", "notifyTakerOfReview", "mezmur_dept")):
            src = read(rel)
            self.assertIn("REVIEW_NOTIFICATION_TYPE = 'attendance_submission_review'", src)
            self.assertIn(f"public static function {fn}(", src)
            self.assertIn("VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)", src, rel)   # target_roles NULL, target_user_id bound
            self.assertIn("ethio_date_format(new \\DateTime($date), 'F j, Y')", src)
            self.assertIn(f"$sourceDept = '{dept}';", src)
            self.assertIn("if ($newStatus !== ", src)
            self.assertRegex(src, r"catch \(\\Throwable \$e\) \{\s*error_log\('[A-Za-z ]*review notification failed", "best-effort: never fails the review")
        nc = read("admin/backend/services/NotificationCenterService.php")
        self.assertIn("'kind' => 'attendance_sheet'", nc)
        self.assertIn("=== 'attendance_submission_review'", nc)
        # Flutter: deep-link + reopen bar + history (next APK, owner Q-3)
        fl = ROOT / "Mobile/wbws_flutter_app"
        vm = (fl / "lib/services/inbox_view_model.dart").read_text(encoding="utf-8")
        self.assertIn("Map<String, Object>? sheetTarget(Map<String, dynamic> n)", vm)
        nc_dart = (fl / "lib/screens/notifications/notification_center_screen.dart").read_text(encoding="utf-8")
        self.assertIn("final sheet = sheetTarget(n);", nc_dart)
        self.assertIn("MezmurAttendanceScreen(", nc_dart)
        self.assertIn("initialDate: date.isEmpty ? null : date", nc_dart)
        att = (fl / "lib/screens/attendance/attendance_screen.dart").read_text(encoding="utf-8")
        self.assertIn("final String? initialDate;", att)
        rv = (fl / "lib/screens/reviews/review_inbox_screen.dart").read_text(encoding="utf-8")
        self.assertIn("bool get _canReopenApproved", rv)
        self.assertIn("UserRoles.eduDept", rv)
        self.assertIn("UserRoles.mezmurDept", rv)
        self.assertIn("Widget _reopenBar()", rv)
        self.assertIn("Widget _historyCard(List events)", rv)
        self.assertIn("'Reopen for correction'", rv)
        self.assertIn("_askReasonAndDecide('rejected', reopen: true)", rv)
        # audit A-2 / A-3: admins never see return/reject on attendance sheets
        self.assertIn("bool get _canReturnOrReject", rv)
        self.assertIn("if (open && _canReturnOrReject)", rv)
        mjs = read("frontend/js/mezmur.js")
        self.assertIn("=== 'mezmur_dept';", mjs)
        self.assertIn("opt.disabled = !deptOnly; opt.hidden = !deptOnly;", mjs)
        self.assertIn("version: 1.7.4+41", (fl / "pubspec.yaml").read_text(encoding="utf-8"))

    def test_php_syntax(self):
        php = shutil.which("php")
        if not php:
            self.skipTest("php CLI not available")
        for rel in ("admin/backend/services/AttendanceSubmissionEventService.php",
                    "admin/backend/services/ReviewTransitionPolicy.php",
                    "admin/backend/services/SubmissionService.php",
                    "admin/backend/services/MezmurSubmissionService.php",
                    "admin/api_communication.php", "admin/api_mezmur.php",
                    "api/v1/routes/grades.php", "api/v1/routes/mezmur.php",
                    "api/v1/routes/attendance.php", "admin/dashboards/edu_dept.php"):
            r = subprocess.run([php, "-l", str(ROOT / rel)], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, f"{rel}: {r.stdout}{r.stderr}")


def _phase3_ready():
    if not _live_ready():
        return False
    try:
        return db("SHOW TABLES LIKE 'attendance_warnings'") != "" and db("SHOW TABLES LIKE 'attendance_submission_events'") != ""
    except Exception:
        return False


@unittest.skipUnless(_phase3_ready(), "local test server / sql 072+073 not available")
class Phase3LiveGuards(unittest.TestCase):
    MID = 900002          # class 1 (seed); Phase 2 uses 900001 on other dates
    CLASS_ID = 1
    # Meskerem/Tikimt 2019 EC — all before the Phase 2 window (2026-10-12..18)
    DATES = ["2026-09-28", "2026-09-29", "2026-09-30", "2026-10-01", "2026-10-02"]
    MEZ_DATE = "2026-09-27"
    MEZ_SECTION = "children"
    _sessions = {}

    @classmethod
    def _clean(cls):
        dates = "','".join(cls.DATES)
        db(f"DELETE FROM attendance WHERE class_id={cls.CLASS_ID} AND attendance_date IN ('{dates}')")
        db(f"DELETE FROM grade_submissions WHERE submission_type='attendance' AND class_id={cls.CLASS_ID} AND attendance_date IN ('{dates}')")
        db(f"DELETE FROM attendance_warnings WHERE member_id={cls.MID}")
        db(f"DELETE FROM notifications WHERE type='attendance_warning' AND JSON_EXTRACT(data,'$.member_id') = {cls.MID}")
        db("DELETE FROM notifications WHERE type='attendance_submission_review' AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.reason')) LIKE 'P3-TEST%'")
        db(f"DELETE FROM mezmur_submissions WHERE attendance_date='{cls.MEZ_DATE}' AND section='{cls.MEZ_SECTION}'")
        db("DELETE FROM attendance_submission_events WHERE reason LIKE 'P3-TEST%' OR (department='edu' AND packet_id NOT IN (SELECT id FROM grade_submissions)) OR (department='mezmur' AND packet_id NOT IN (SELECT id FROM mezmur_submissions))")
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
        body = urllib.parse.urlencode({
            "action": "save_attendance", "class_id": self.CLASS_ID, "date": date,
            "records": json.dumps(records), "csrf_token": sess.csrf,
        }).encode()
        status, raw = sess._open(BASE + "/admin/api_attendance.php", data=body, headers={"X-CSRF-Token": sess.csrf})
        data = json.loads(raw.decode("utf-8"))
        self.assertEqual(status, 200, data)
        self.assertEqual(data.get("status"), "success", data)

    def _web_review(self, role, packet_id, new_status, notes):
        sess = self.s(role)
        body = urllib.parse.urlencode({
            "action": "review_submission", "submission_id": packet_id, "new_status": new_status,
            "notes": notes, "csrf_token": sess.csrf,
        }).encode()
        status, raw = sess._open(BASE + "/admin/api_communication.php", data=body, headers={"X-CSRF-Token": sess.csrf})
        try:
            data = json.loads(raw.decode("utf-8"))
        except Exception:
            data = {"raw": raw[:200].decode("utf-8", "replace")}
        return status, data

    def _packet(self, date):
        row = db(f"SELECT id, status FROM grade_submissions WHERE submission_type='attendance' AND class_id={self.CLASS_ID} AND attendance_date='{date}' ORDER BY id DESC LIMIT 1")
        self.assertTrue(row, f"no packet for {date}")
        pid, status = row.split("\t")
        return int(pid), status

    def _warning(self):
        return db(f"SELECT id, kind, stage, streak_length, status, below_threshold FROM attendance_warnings WHERE member_id={self.MID} AND kind='consecutive' ORDER BY id DESC LIMIT 1")

    def _events(self, dept, pid):
        return db(f"SELECT from_status, to_status, actor_id, reason, channel FROM attendance_submission_events WHERE department='{dept}' AND packet_id={pid} ORDER BY id")

    # ── 1. reopen with reason invalidates the warning; resubmit restores it ──
    def test_01_reopen_recalculates_and_history_is_kept(self):
        for d in self.DATES:
            self._web_save(d, {self.MID})
        w = self._warning()
        self.assertTrue(w, "5 submitted absences must open a consecutive warning")
        wid, kind, stage, length, status, below = w.split("\t")
        self.assertEqual((kind, stage, length, status, below), ("consecutive", "standard", "5", "open", "1"))

        pid, pstatus = self._packet(self.DATES[-1])
        self.assertEqual(pstatus, "submitted", "web saves are official (submitted) packets")
        # every web save appended a 'submitted' step
        first = self._events("edu", pid).split("\n")[0]
        self.assertEqual(first.lstrip("\t"), "submitted\t2\t\tweb", first)  # from '' → submitted by edu_dept (web)

        # reason required
        status, data = self._web_review("edu_dept", pid, "revision_needed", "no")
        self.assertEqual(data.get("status"), "error", data)
        self.assertIn("reason", data.get("message", "").lower())
        self.assertEqual(self._packet(self.DATES[-1])[1], "submitted")

        # reopen (return for correction) with a reason
        status, data = self._web_review("edu_dept", pid, "revision_needed", "P3-TEST wrong sheet, please recheck")
        self.assertEqual(status, 200, data)
        self.assertEqual(data.get("status"), "success", data)
        self.assertEqual(self._packet(self.DATES[-1])[1], "revision_needed")
        ev = self._events("edu", pid)
        self.assertIn("submitted\trevision_needed\t2\tP3-TEST wrong sheet, please recheck\tweb", ev)
        # Q-2: the sheet owner got ONE bell with the reason, EC date in the text, deep-link data
        bell = db(f"SELECT target_user_id, target_roles, priority, source_dept, title, message, data FROM notifications WHERE type='attendance_submission_review' AND JSON_EXTRACT(data,'$.submission_id')={pid} ORDER BY id DESC LIMIT 1")
        self.assertTrue(bell, "teacher bell missing")
        tgt, roles, prio, sdept, title, message, data_json = bell.split("\t", 6)
        self.assertEqual(tgt, db(f"SELECT teacher_id FROM grade_submissions WHERE id={pid}"))
        self.assertEqual(roles, "NULL")
        self.assertEqual((prio, sdept), ("normal", "edu_dept"))
        self.assertIn("P3-TEST wrong sheet, please recheck", message)
        self.assertNotRegex(message, r"\b\d{4}-\d{2}-\d{2}\b", "EC only in user-facing text")
        payload = json.loads(data_json)
        self.assertEqual((payload["department"], payload["class_id"], payload["attendance_date"], payload["new_status"]),
                         ("edu", self.CLASS_ID, self.DATES[-1], "revision_needed"))
        self.assertEqual(db(f"SELECT COUNT(*) FROM notifications WHERE type='attendance_submission_review' AND JSON_EXTRACT(data,'$.submission_id')={pid}"), "1")
        # feed exposes the deep-link target to the owner (web + mobile share the service)
        status, feed = self.s("edu_dept").get_json("/admin/api_notifications.php?action=feed&limit=20")
        self.assertEqual(status, 200, feed)
        hit = [r for r in feed.get("rows", []) if isinstance(r, dict) and str(r.get("type")) == "attendance_submission_review"]
        self.assertTrue(hit, feed)
        target = hit[0].get("target") or {}
        self.assertEqual((target.get("kind"), target.get("department"), target.get("class_id"), target.get("attendance_date")),
                         ("attendance_sheet", "edu", self.CLASS_ID, self.DATES[-1]), target)

        # the 5th day no longer counts → streak 4 < 5 → warning flagged, NOT deleted, still open
        w = self._warning()
        wid2, kind, stage, length, status, below = w.split("\t")
        self.assertEqual(wid2, wid, "warning row must be preserved")
        self.assertEqual((status, below), ("open", "0"), w)
        self.assertIn("invalidated", db(f"SELECT GROUP_CONCAT(event) FROM attendance_warning_events WHERE warning_id={wid}"))

        # teacher resubmits the corrected (still absent) sheet from mobile → official again → warning valid again
        teacher = _mobile_token("teacher")
        records = [{"member_id": m, "status": "absent" if m == self.MID else "present"} for m in self._roster(self.DATES[-1])]
        status, data = mobile(teacher, "POST", "/api/v1/attendance/submit", {"class_id": self.CLASS_ID, "date": self.DATES[-1], "records": records})
        self.assertIn(status, (200, 201), data)
        self.assertEqual(self._packet(self.DATES[-1])[1], "submitted")
        self.assertIn("revision_needed\tsubmitted\t3\t\tmobile", self._events("edu", pid))
        w = self._warning()
        self.assertEqual(w.split("\t")[0], wid)
        self.assertEqual(w.split("\t")[-1], "1", "resubmitted official sheet counts again")

        # Q-1 (owner 2026-10-19): admins may no longer return/reject a SUBMITTED attendance sheet
        for role in ("school_admin", "super_admin"):
            for decision in ("revision_needed", "rejected"):
                status, data = self._web_review(role, pid, decision, "P3-TEST admin on submitted")
                self.assertEqual(status, 403, (role, decision, data))
        self.assertEqual(self._packet(self.DATES[-1])[1], "submitted")
        self.assertEqual(db(f"SELECT COUNT(*) FROM notifications WHERE type='attendance_submission_review' AND JSON_EXTRACT(data,'$.submission_id')={pid}"), "1", "refused decisions send no bell")

    # ── 2. approved attendance: only edu_dept may reopen; mobile uses the same rule ──
    def test_02_approved_sheet_reopen_permissions(self):
        pid, pstatus = self._packet(self.DATES[-2])
        if pstatus != "approved":
            status, data = self._web_review("edu_dept", pid, "approved", "")
            self.assertEqual(data.get("status"), "success", data)
        self.assertEqual(self._packet(self.DATES[-2])[1], "approved")

        # school admin: may approve submitted packets but NOT return/reject/reopen attendance (Q-1)
        status, data = self._web_review("school_admin", pid, "rejected", "P3-TEST admin tries")
        self.assertEqual(status, 403, data)
        self.assertEqual(self._packet(self.DATES[-2])[1], "approved")

        # teacher via mobile: review endpoint itself is edu-only
        teacher = _mobile_token("teacher")
        status, data = mobile(teacher, "POST", "/api/v1/grades/submission-review", {"id": pid, "status": "rejected", "notes": "P3-TEST teacher tries"})
        self.assertEqual(status, 403, data)

        # edu_dept via mobile without a reason → 422
        edu = _mobile_token("edu_dept")
        status, data = mobile(edu, "POST", "/api/v1/grades/submission-review", {"id": pid, "status": "rejected", "notes": ""})
        self.assertEqual(status, 422, data)

        # approving an approved sheet is still refused (strict rule kept)
        status, data = mobile(edu, "POST", "/api/v1/grades/submission-review", {"id": pid, "status": "approved", "notes": ""})
        self.assertEqual(status, 422, data)

        # edu_dept via mobile rejects the approved sheet with a reason → official no more
        status, data = mobile(edu, "POST", "/api/v1/grades/submission-review", {"id": pid, "status": "rejected", "notes": "P3-TEST duplicate sheet"})
        self.assertEqual(status, 200, data)
        self.assertTrue(data["data"].get("reopened"), data)
        self.assertEqual(self._packet(self.DATES[-2])[1], "rejected")
        self.assertIn("approved\trejected\t2\tP3-TEST duplicate sheet\tmobile", self._events("edu", pid))
        # detail exposes the history for the UIs
        status, data = self.s("edu_dept").get_json(f"/admin/api_communication.php?action=get_submission_detail&id={pid}")
        self.assertEqual(status, 200, data)
        events = data["submission"]["events"]
        self.assertTrue(any(e["event"] == "rejected" and e["reason"] == "P3-TEST duplicate sheet" for e in events), events)
        self.assertTrue(all(not re.search(r"\b\d{4}-\d{2}-\d{2}\b", e["created_ec"]) for e in events), "EC timestamps only")

    # ── 3. Mezmur: same rule for approved packets ──
    def test_03_mezmur_reopen_rule(self):
        db(f"INSERT INTO mezmur_submissions (attendance_date, section, taker_id, status, member_count, present_count, late_count, absent_count, excused_count, submitted_at) VALUES ('{self.MEZ_DATE}', '{self.MEZ_SECTION}', 8, 'approved', 0, 0, 0, 0, 0, NOW())")
        pid = int(db(f"SELECT id FROM mezmur_submissions WHERE attendance_date='{self.MEZ_DATE}' AND section='{self.MEZ_SECTION}' ORDER BY id DESC LIMIT 1"))

        admin = _mobile_token("school_admin")
        status, data = mobile(admin, "POST", "/api/v1/mezmur/submission-review", {"id": pid, "status": "revision_needed", "notes": "P3-TEST admin tries"})
        self.assertEqual(status, 403, data)

        mez = _mobile_token("mezmur_dept")
        status, data = mobile(mez, "POST", "/api/v1/mezmur/submission-review", {"id": pid, "status": "revision_needed", "notes": "x"})
        self.assertEqual(status, 409, data)  # reason too short (service message, mapped to 409 as before)

        status, data = mobile(mez, "POST", "/api/v1/mezmur/submission-review", {"id": pid, "status": "revision_needed", "notes": "P3-TEST reopen mezmur"})
        self.assertEqual(status, 200, data)
        self.assertEqual(db(f"SELECT status FROM mezmur_submissions WHERE id={pid}"), "revision_needed")
        self.assertIn("approved\trevision_needed\t9\tP3-TEST reopen mezmur\tmobile", self._events("mezmur", pid))
        # Q-2: taker (user 8) gets the bell with section + EC date
        bell = db(f"SELECT target_user_id, source_dept, message, data FROM notifications WHERE type='attendance_submission_review' AND JSON_EXTRACT(data,'$.submission_id')={pid} AND JSON_EXTRACT(data,'$.department')='mezmur' ORDER BY id DESC LIMIT 1")
        self.assertTrue(bell, "taker bell missing")
        tgt, sdept, message, data_json = bell.split("\t", 3)
        self.assertEqual((tgt, sdept), ("8", "mezmur_dept"))
        self.assertIn("P3-TEST reopen mezmur", message)
        self.assertNotRegex(message, r"\b\d{4}-\d{2}-\d{2}\b")
        # A-4: canonical section labels in the text (code stays in data)
        self.assertNotIn(f"for {self.MEZ_SECTION} on", message)
        self.assertIn("Children", message)
        payload = json.loads(data_json)
        self.assertEqual((payload["section"], payload["attendance_date"]), (self.MEZ_SECTION, self.MEZ_DATE))

        # Q-1: a SUBMITTED mezmur packet — admins cannot return/reject it either; the department can
        db(f"UPDATE mezmur_submissions SET status='submitted' WHERE id={pid}")
        status, data = mobile(admin, "POST", "/api/v1/mezmur/submission-review", {"id": pid, "status": "rejected", "notes": "P3-TEST admin on submitted"})
        self.assertEqual(status, 403, data)
        self.assertEqual(db(f"SELECT status FROM mezmur_submissions WHERE id={pid}"), "submitted")
        status, data = mobile(mez, "POST", "/api/v1/mezmur/submission-review", {"id": pid, "status": "rejected", "notes": "P3-TEST mezmur rejects submitted"})
        self.assertEqual(status, 200, data)
        self.assertEqual(db(f"SELECT status FROM mezmur_submissions WHERE id={pid}"), "rejected")

        # web detail shows history
        status, data = self.s("mezmur_dept").get_json(f"/admin/api_mezmur.php?action=submission_detail&id={pid}")
        self.assertEqual(status, 200, data)
        self.assertTrue(any(e["event"] == "reopened" for e in data["item"]["events"]), data["item"]["events"])


if __name__ == "__main__":
    unittest.main()
