"""
Education timetable builder (2026-10-20) — owner-approved layout from the demo:
class → working days → per-class bell schedule → weekly grid → makeup classes.

Static pins (always run):
  * sql/077 is additive and idempotent (no DROP, guarded ALTERs, new tables);
  * api_timetable.php: writes are POST + CSRF + Education-only + year write guard;
    teachers are forced onto their own id; unknown actions rejected;
  * TimetableService: teacher clash uses time overlap across classes, lessons
    refused on breaks / non-working days, every write audited to activity_logs;
  * edu_dept.php mounts #sec-timetable + edu_timetable.js; teacher.php shows the
    read-only "My Timetable" card; the UI module has no CDN and EC dates only.
Live (skipped unless the local server + seed are present):
  * full Education round-trip: days → period (overlap 4xx) → lesson → teacher
    double-booking refused → clear → copy bell → makeup (past date refused,
    weekday clash refused) → delete; teacher my_schedule sees the lesson;
    teacher write 403; CSRF-less write 403; finance 403; audit rows written.
"""
from __future__ import annotations

import json
import re
import unittest
import urllib.parse
from datetime import date, timedelta
from pathlib import Path

from test_attendance_phase1 import BASE, USERS, WebSession, db, _live_ready  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
USERS.setdefault("finance_dept", "audit_fin")


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class TimetableStaticGuards(unittest.TestCase):
    SQL = "sql/077_timetable_builder.sql"
    API = "admin/api_timetable.php"
    SVC = "admin/backend/services/TimetableService.php"
    JS = "admin/js/edu_timetable.js"
    EDU = "admin/dashboards/edu_dept.php"
    TEACHER = "admin/dashboards/teacher.php"

    def test_sql_077_is_additive_and_idempotent(self):
        src = read(self.SQL)
        body = "\n".join(l for l in src.splitlines() if not l.strip().startswith("--"))
        self.assertNotRegex(body.upper(), r"\bDROP\b")
        self.assertNotRegex(body.upper(), r"\bTRUNCATE\b")
        self.assertIn("CREATE TABLE IF NOT EXISTS `timetable_class_days`", body)
        self.assertIn("CREATE TABLE IF NOT EXISTS `timetable_makeup_classes`", body)
        # every ALTER is wrapped in the information_schema guard
        self.assertEqual(body.count("ALTER TABLE"), body.count("NOT EXISTS(SELECT 1 FROM information_schema."))
        self.assertNotIn("ADD COLUMN IF NOT EXISTS", body)

    def test_api_write_actions_are_post_csrf_education_and_year_guarded(self):
        src = read(self.API)
        self.assertIn("$writeActions = ['save_days', 'save_period', 'delete_period', 'copy_bell', 'save_entry', 'clear_entry', 'save_makeup', 'delete_makeup'];", src)
        self.assertIn("requirePostActions($action, $writeActions);", src)
        self.assertIn("if (!validateCsrf($csrf)) {\n        http_response_code(403);", src)
        self.assertIn("$yearId = (int)ay_require_writable($conn);", src)
        self.assertIn("Only Education can edit the timetable.", src)
        self.assertIn("$manageRoles = ['super_admin', 'school_admin', 'edu_dept'];", src)
        # teachers can never read someone else's schedule
        self.assertIn("if ($isTeacher) {\n                $tid = (int)$_SESSION['admin_id'];", src)
        self.assertIn("} elseif (!in_array($action, $readActions, true)) {", src)
        self.assertIn("} catch (Throwable $e) {", src)
        self.assertNotRegex(src, r"\bdie\s*\(")

    def test_service_rules(self):
        src = read(self.SVC)
        # clash = same teacher, same weekday, overlapping period times, other class, same year
        self.assertIn("WHERE e.teacher_id = ? AND e.academic_year_id = ? AND e.day_of_week = ? AND e.class_id <> ?\n               AND p.start_time < ? AND p.end_time > ?", src)
        self.assertIn("Lessons cannot be placed on a break.", src)
        self.assertIn("That weekday is not a working day of this class.", src)
        self.assertIn("Makeup classes must be today or later.", src)
        self.assertIn("Periods may touch but not overlap.", src)
        # every write path audits
        for action in ("Timetable Working Days Saved", "Timetable Period Added", "Timetable Period Updated",
                       "Timetable Period Deleted", "Timetable Bell Schedule Copied", "Timetable Lesson Saved",
                       "Timetable Lesson Cleared", "Timetable Makeup Class Added", "Timetable Makeup Class Removed"):
            self.assertIn(f"'{action}'", src)
        self.assertIn("INSERT INTO activity_logs (user_id, username, action, details, entity_type, entity_id, ip_address)", src)
        # no schema queries at request time
        for stmt in ("CREATE TABLE", "ALTER TABLE", "SHOW TABLES", "SHOW COLUMNS"):
            self.assertNotIn(stmt, src)
        # all SQL is prepared; the only interpolation is an int-cast IN list
        self.assertNotRegex(src, r"\$conn->query\([^)]*\$(?!conn)")

    def test_dashboards_mount_the_builder_and_teacher_card(self):
        edu = read(self.EDU)
        self.assertIn('<div id="sec-timetable" class="sec"></div>', edu)
        self.assertIn('data-sec="timetable"', edu)
        self.assertIn('/admin/js/edu_timetable.js?v=', edu)
        self.assertIn("if(n==='timetable'&&window.EduTimetableInstance)window.EduTimetableInstance.boot();", edu)
        teacher = read(self.TEACHER)
        self.assertIn('id="tt-card"', teacher)
        self.assertIn("/admin/api_timetable.php?action=my_schedule", teacher)

    def test_ui_module_is_local_and_ethiopian_only(self):
        js = read(self.JS)
        self.assertNotRegex(js, r"https?://")
        self.assertIn("WBWSCalendar.formatDate", js)
        # no Gregorian date rendering helpers
        self.assertNotIn("toLocaleDateString", js)
        self.assertNotIn("toDateString", js)
        # every dynamic string goes through esc()
        self.assertIn("function esc(v)", js)
        self.assertIn("data-act=\"save-days\"", js)
        self.assertIn("+ Add period", js)
        self.assertIn("+ Add makeup class", js)
        self.assertIn("Removing a day permanently clears any lessons already scheduled on it.", js)


@unittest.skipUnless(_live_ready(), "live server + seeded DB required")
class TimetableLive(unittest.TestCase):
    CLASS_A = 1
    CLASS_B = 2

    @classmethod
    def setUpClass(cls):
        if db("SHOW TABLES LIKE 'timetable_class_days'") == "":
            raise unittest.SkipTest("sql/077 not applied")
        cls.edu = WebSession("edu_dept")
        cls.teacher = WebSession("teacher")
        cls.teacher_id = int(db("SELECT id FROM users WHERE username='audit_teach'"))
        cls.subject_id = int(db("SELECT MIN(id) FROM subjects WHERE is_active=1"))
        cls.year_id = int(db("SELECT id FROM academic_years WHERE status='active' ORDER BY id DESC LIMIT 1") or 0)
        cls._clean()

    @classmethod
    def tearDownClass(cls):
        cls._clean()

    @classmethod
    def _clean(cls):
        for c in (cls.CLASS_A, cls.CLASS_B):
            db(f"DELETE FROM timetable_entries WHERE class_id={c}")
            db(f"DELETE FROM timetable_periods WHERE class_id={c}")
            # restore the seeded default row (deleting it would break later
            # tests and the next run — class-day rules need it)
            db(f"INSERT INTO timetable_class_days (class_id,academic_year_id,working_days) "
               f"VALUES ({c},{cls.year_id},'1,2,3,4,5,6,7') "
               "ON DUPLICATE KEY UPDATE working_days=VALUES(working_days)")
            db(f"DELETE FROM timetable_makeup_classes WHERE class_id={c}")
        db("DELETE FROM activity_logs WHERE entity_type='timetable'")

    def post(self, sess, action, payload, csrf=True):
        payload = dict(payload)
        if csrf:
            payload["csrf_token"] = sess.csrf
        status, body = sess._open(
            BASE + "/admin/api_timetable.php?action=" + action,
            data=urllib.parse.urlencode(payload, doseq=True).encode(),
            headers={"Content-Type": "application/x-www-form-urlencoded"},
        )
        try:
            return status, json.loads(body.decode("utf-8"))
        except Exception:
            return status, {"raw": body[:300].decode("utf-8", "replace")}

    def test_01_education_round_trip(self):
        edu = self.edu
        A, B, T, S = self.CLASS_A, self.CLASS_B, self.teacher_id, self.subject_id

        st, d = edu.get_json("/admin/api_timetable.php?action=classes")
        self.assertEqual(st, 200); self.assertEqual(d["status"], "success"); self.assertTrue(d["classes"])

        # Exercise the editor's unconfigured preset, not the seeded seven-day row.
        # _clean() restores that seed with an upsert even if this test fails early.
        db(f"DELETE FROM timetable_class_days WHERE class_id IN ({A},{B}) AND academic_year_id={self.year_id}")
        st, d = edu.get_json(f"/admin/api_timetable.php?action=class_setup&class_id={A}")
        self.assertEqual(d["status"], "success")
        self.assertEqual(d["working_days"], [6, 7])  # default Saturday + Sunday
        self.assertEqual(d["periods"], [])

        # working days: Wednesday + Sunday for class A
        st, d = self.post(edu, "save_days", {"class_id": A, "days[]": [3, 7]})
        self.assertEqual(d.get("code"), "confirm_phrase_required", d)
        st, d = self.post(edu, "save_days", {"class_id": A, "days[]": [3, 7], "confirm_phrase": "REMOVE DAY"})
        self.assertEqual(d["status"], "success", d); self.assertEqual(d["working_days"], [3, 7])

        # periods
        st, d = self.post(edu, "save_period", {"class_id": A, "label": "Period 1", "start_time": "08:00", "end_time": "08:45"})
        self.assertEqual(d["status"], "success", d); p1 = d["period_id"]
        st, d = self.post(edu, "save_period", {"class_id": A, "label": "Period 2", "start_time": "08:45", "end_time": "09:30"})
        self.assertEqual(d["status"], "success", d); p2 = d["period_id"]  # back-to-back OK
        st, d = self.post(edu, "save_period", {"class_id": A, "label": "Bad", "start_time": "09:00", "end_time": "09:20"})
        self.assertEqual(d["status"], "error"); self.assertIn("Overlaps with Period 2", d["message"])
        st, d = self.post(edu, "save_period", {"class_id": A, "label": "Break", "start_time": "09:30", "end_time": "09:45", "is_break": 1})
        self.assertEqual(d["status"], "success", d); pb = d["period_id"]

        # lesson rules
        st, d = self.post(edu, "save_entry", {"class_id": A, "period_id": p1, "day_of_week": 6, "subject_id": S, "teacher_id": T})
        self.assertEqual(d["status"], "error"); self.assertIn("not a working day", d["message"])
        st, d = self.post(edu, "save_entry", {"class_id": A, "period_id": pb, "day_of_week": 7, "subject_id": S, "teacher_id": T})
        self.assertEqual(d["status"], "error"); self.assertIn("break", d["message"].lower())
        st, d = self.post(edu, "save_entry", {"class_id": A, "period_id": p1, "day_of_week": 7, "subject_id": S, "teacher_id": T, "room": "Room 1"})
        self.assertEqual(d["status"], "success", d)

        # class B: default days (Sat+Sun), period overlapping 08:00-08:45 on Sunday → teacher clash
        st, d = self.post(edu, "save_period", {"class_id": B, "label": "Period 1", "start_time": "08:15", "end_time": "09:00"})
        self.assertEqual(d["status"], "success", d); bp1 = d["period_id"]
        st, d = self.post(edu, "save_entry", {"class_id": B, "period_id": bp1, "day_of_week": 7, "subject_id": S, "teacher_id": T})
        self.assertEqual(d["status"], "error"); self.assertIn("cannot be in two classes", d["message"])
        # same teacher on Saturday is fine (class A has no Saturday)
        st, d = self.post(edu, "save_entry", {"class_id": B, "period_id": bp1, "day_of_week": 6, "subject_id": S, "teacher_id": T})
        self.assertEqual(d["status"], "success", d)
        # a period of another class cannot be used
        st, d = self.post(edu, "save_entry", {"class_id": A, "period_id": bp1, "day_of_week": 7, "subject_id": S, "teacher_id": T})
        self.assertEqual(d["status"], "error"); self.assertIn("not found for this class", d["message"])

        # teacher sees own schedule (2 lessons) — teacher_id param is ignored for teachers
        st, d = self.teacher.get_json(f"/admin/api_timetable.php?action=teacher_schedule&teacher_id={T + 1000}")
        self.assertEqual(d["status"], "success"); self.assertEqual(d["teacher_id"], T)
        self.assertEqual(len(d["lessons"]), 2)
        st, d = self.teacher.get_json("/admin/api_timetable.php?action=my_schedule")
        self.assertEqual(len(d["lessons"]), 2)
        self.assertEqual(d["lessons"][0]["room"] or d["lessons"][1]["room"], "Room 1")

        # removing Sunday from class A clears its Sunday lesson
        st, d = self.post(edu, "save_days", {"class_id": A, "days[]": [3], "confirm_phrase": "REMOVE DAY"})  # removing a day needs the typed phrase (DC-1)
        self.assertEqual(d["status"], "success", d); self.assertEqual(d["cleared"], 1)
        self.assertEqual(db(f"SELECT COUNT(*) FROM timetable_entries WHERE class_id={A}"), "0")

        # copy bell schedule B → A (A gets B's days + periods)
        st, d = self.post(edu, "copy_bell", {"class_id": A, "from_class_id": B})
        self.assertEqual(d.get("code"), "confirm_phrase_required", d)
        st, d = self.post(edu, "copy_bell", {"class_id": A, "from_class_id": B, "confirm_phrase": "REPLACE SCHEDULE"})
        self.assertEqual(d["status"], "success", d)
        st, d = edu.get_json(f"/admin/api_timetable.php?action=class_setup&class_id={A}")
        self.assertEqual(d["working_days"], [6, 7]); self.assertEqual(len(d["periods"]), 1)
        self.assertEqual(d["periods"][0]["start_time"], "08:15")

        # delete period cascades lessons (class B has 1 lesson on bp1)
        st, d = self.post(edu, "delete_period", {"class_id": B, "period_id": bp1})
        self.assertEqual(d.get("code"), "confirm_phrase_required", d)
        st, d = self.post(edu, "delete_period", {"class_id": B, "period_id": bp1, "confirm_phrase": "DELETE PERIOD"})
        self.assertEqual(d["status"], "success", d); self.assertIn("1 scheduled lesson", d["message"])

        # makeup classes
        yesterday = (date.today() - timedelta(days=1)).isoformat()
        st, d = self.post(edu, "save_makeup", {"class_id": A, "lesson_date": yesterday, "start_time": "14:00", "end_time": "14:45", "subject_id": S, "teacher_id": T})
        self.assertEqual(d["status"], "error"); self.assertIn("today or later", d["message"])
        # next Saturday: teacher now teaches class A (copied period 08:15-09:00 has no lesson yet) → place one, then makeup clash
        ap = d_setup = edu.get_json(f"/admin/api_timetable.php?action=class_setup&class_id={A}")[1]["periods"][0]["id"]
        st, d = self.post(edu, "save_entry", {"class_id": A, "period_id": ap, "day_of_week": 6, "subject_id": S, "teacher_id": T})
        self.assertEqual(d["status"], "success", d)
        today = date.today()
        next_sat = today + timedelta(days=(5 - today.weekday()) % 7 or 7)
        st, d = self.post(edu, "save_makeup", {"class_id": B, "lesson_date": next_sat.isoformat(), "start_time": "08:30", "end_time": "09:15", "subject_id": S, "teacher_id": T})
        self.assertEqual(d["status"], "error"); self.assertIn("every Saturday", d["message"])
        st, d = self.post(edu, "save_makeup", {"class_id": B, "lesson_date": next_sat.isoformat(), "start_time": "10:00", "end_time": "10:45", "subject_id": S, "teacher_id": T, "reason": "holiday cover"})
        self.assertEqual(d["status"], "success", d); mk = d["makeup_id"]
        st, d = self.teacher.get_json("/admin/api_timetable.php?action=my_schedule")
        self.assertEqual(len(d["makeups"]), 1); self.assertEqual(d["makeups"][0]["reason"], "holiday cover")
        st, d = self.post(edu, "delete_makeup", {"makeup_id": mk})
        self.assertEqual(d["status"], "success", d)

        # audit trail
        n = int(db("SELECT COUNT(*) FROM activity_logs WHERE entity_type='timetable'"))
        self.assertGreaterEqual(n, 10)
        self.assertEqual(db("SELECT COUNT(*) FROM activity_logs WHERE entity_type='timetable' AND action='Timetable Makeup Class Removed'"), "1")

    def test_02_access_control(self):
        A, T, S = self.CLASS_A, self.teacher_id, self.subject_id
        # teacher cannot write
        st, d = self.post(self.teacher, "save_days", {"class_id": A, "days[]": [6]})
        self.assertEqual(st, 403)
        # teacher cannot read the builder
        st, d = self.teacher.get_json(f"/admin/api_timetable.php?action=class_setup&class_id={A}")
        self.assertEqual(st, 403)
        # CSRF missing → 403
        st, d = self.post(self.edu, "save_days", {"class_id": A, "days[]": [6]}, csrf=False)
        self.assertEqual(st, 403)
        # GET on a write action → 405
        st, d = self.edu.get_json(f"/admin/api_timetable.php?action=clear_entry&class_id={A}&period_id=1&day_of_week=6")
        self.assertEqual(st, 405)
        # finance has no access at all
        fin = WebSession("finance_dept")
        st, d = fin.get_json("/admin/api_timetable.php?action=classes")
        self.assertEqual(st, 403)
        # unknown action
        st, d = self.edu.get_json("/admin/api_timetable.php?action=nope")
        self.assertEqual(d["message"], "Unknown action")

    def test_03_pages_render(self):
        st, body = self.edu._open(BASE + "/admin/dashboards/edu_dept.php?section=timetable")
        html = body.decode("utf-8", "replace")
        self.assertEqual(st, 200)
        self.assertIn('id="sec-timetable"', html)
        self.assertIn("edu_timetable.js", html)
        self.assertNotIn("<b>Warning</b>", html)
        st, body = self.teacher._open(BASE + "/admin/dashboards/teacher.php")
        html = body.decode("utf-8", "replace")
        self.assertEqual(st, 200)
        self.assertIn('id="tt-card"', html)
        self.assertNotIn("<b>Warning</b>", html)
        st, body = self.edu._open(BASE + "/admin/js/edu_timetable.js")
        self.assertEqual(st, 200)


if __name__ == "__main__":
    unittest.main()
