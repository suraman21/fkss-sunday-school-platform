"""
Attendance alerting — Phase 6 guards (lesson-level Education attendance,
requirements §3 / §5 / §8 / §13; plan ATTENDANCE_PHASE6_LESSON_LEVEL_PLAN,
owner decisions Q6-1 … Q6-9 "continue with recommended", simplicity constraint).

Static pins (always run):
  * sql/076 is additive, idempotent, shared-hosting safe: exactly two tables,
    UNIQUE(class, date, subject, lesson_no) and UNIQUE(session, member);
    the `attendance` table / key / triggers are NOT touched;
  * the service derives the class/day row with the approved precedence
    (present › late › excused › absent), writes it ONLY through
    AttendanceRecordService::mergeSheet, never deletes `attendance` rows,
    and is gated by FEATURE_ATTENDANCE_V2 + table presence (no new flag);
  * every class/day WRITE path (web sheet, Education batch, QR scan, /api/v1
    save + submit) refuses a lesson day with 409;
  * role matrices: teachers write only inside class + subject assignments,
    Education may cancel, HR / Info / School Admin read-only; CSRF on writes;
  * access_control + FeatureGate know the new files; teacher UI has the
    "Whole day (class)" default; every date label is Ethiopian.
Live (skipped unless the local server + sql/076 are present):
  * two lessons → derived day row + submitted packet, precedence, cancel →
    re-derivation, class/day sheet 409, scan 409, mobile 409, history for HR /
    Info / teacher scoping, audit rows, validation errors.
"""
from __future__ import annotations

import json
import re
import unittest
import urllib.parse
from pathlib import Path

from test_attendance_phase1 import BASE, USERS, WebSession, db, _live_ready, mobile, mobile_token  # noqa: E402
from attendance_test_dates import recent  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
USERS.setdefault("attendance_taker", "audit_taker")


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def strip_sql_comments(sql: str) -> str:
    return "\n".join(l for l in sql.splitlines() if not l.strip().startswith("--"))


class Phase6StaticGuards(unittest.TestCase):
    SERVICE = "admin/backend/services/LessonAttendanceService.php"
    API = "admin/api_lesson_attendance.php"
    PAGE = "admin/lesson_attendance.php"
    TEACHER = "admin/dashboards/teacher.php"

    def test_sql_076_is_two_additive_tables_and_leaves_attendance_alone(self):
        sql = strip_sql_comments(read("sql/076_attendance_sessions.sql"))
        self.assertIn("CREATE TABLE IF NOT EXISTS `attendance_sessions`", sql)
        self.assertIn("CREATE TABLE IF NOT EXISTS `attendance_session_records`", sql)
        self.assertEqual(sql.count("CREATE TABLE"), 2, "simplicity: exactly two tables")
        self.assertIn("UNIQUE KEY `uq_session_slot` (`class_id`, `session_date`, `subject_id`, `lesson_no`)", sql)
        self.assertIn("UNIQUE KEY `uq_session_member` (`session_id`, `member_id`)", sql)
        self.assertIn("ENUM('submitted','cancelled')", sql)
        self.assertIn("ENUM('present','absent','late','excused','holiday')", sql)
        up = sql.upper()
        for forbidden in ("TRIGGER", "EVENT", "PROCEDURE", "DROP TABLE", "DELETE FROM", "ALTER TABLE `ATTENDANCE`", "ALTER TABLE ATTENDANCE", "GRADE_SUBMISSIONS"):
            self.assertNotIn(forbidden, up, forbidden)
        self.assertEqual(sql.count("ENGINE=InnoDB"), 2)

    def test_service_derivation_rule_and_write_discipline(self):
        src = read(self.SERVICE)
        body = src.split("final class LessonAttendanceService", 1)[1]
        # approved precedence present › late › excused › absent, holiday only when all holiday
        self.assertIn("foreach (['present', 'late', 'excused'] as $s)", body)
        self.assertIn("if (in_array('absent', $statuses, true)) {\n            return 'absent';", body)
        self.assertIn("return 'holiday';", body)
        self.assertIn("if ($statuses === []) {\n            return null;", body)  # no submitted lesson → no day row
        # day rows only through the shared service; nothing deleted from attendance
        self.assertIn("AttendanceRecordService::mergeSheet($conn, $classId, $date, $yearId, $actorId, $records)", body)
        self.assertNotRegex(body, r"(INSERT\s+INTO|UPDATE)\s+attendance\b(?!_session)", "the service never writes `attendance` rows directly")
        # the only DELETE on `attendance` removes rows THIS service derived (guarded by the derived note)
        self.assertEqual(len(re.findall(r"DELETE\s+FROM\s+attendance\b(?!_session)", body)), 1)
        self.assertIn("notes LIKE \\'derived from %\\' AND member_id IN", body)
        self.assertIn("SubmissionService::STATUS_INCOMPLETE", body)  # no lesson left → day open again
        self.assertNotRegex(body, r"DELETE\s+FROM\s+' \. self::SESSIONS", "lessons are cancelled, never deleted")
        # packet stays the single review surface; warnings re-evaluated after commit
        self.assertIn("'status' => $c['total'] > 0 ? SubmissionService::STATUS_SUBMITTED : SubmissionService::STATUS_INCOMPLETE", body)
        self.assertIn("AttendanceWarningService::safeEvaluateMany($conn, 'edu', $memberIds, $date, $teacherId)", body)
        # gating: existing flag + table presence (no new flag — simplicity)
        self.assertIn("FeatureGate::isEnabled('attendance_v2')", body)
        self.assertIn("SHOW TABLES LIKE '\" . self::RECORDS . \"'", body)
        self.assertNotIn("FEATURE_LESSON_ATTENDANCE", read("admin/backend/services/FeatureGate.php"))
        # roles
        self.assertIn("public const READ_ROLES = ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept'];", body)
        self.assertIn("public const WRITE_ROLES = ['teacher', 'edu_dept'];", body)
        self.assertIn("AND (subject_id = ? OR subject_id IS NULL)", body)  # teacher scope by assignment
        # audit never uses a forbidden detail key
        self.assertNotIn("'session_id' => $sessionId, 'class_id'", body)
        self.assertIn("'Lesson Attendance Saved'", body)
        self.assertIn("'Lesson Attendance Cancelled'", body)
        self.assertIn("if (!self::subjectIsActive($conn, $subjectId)) {", body)
        self.assertIn("AttendanceWarningService::openByMembers($conn, 'edu', $memberIds)", body)  # warning badges on lesson sheets too
        # Ethiopian labels only
        self.assertIn("ethio_date_format($date, 'F j, Y')", body)
        self.assertNotIn("date('Y-m-d'", body)

    def test_every_class_day_write_path_refuses_a_lesson_day(self):
        web = read("admin/api_attendance.php")
        self.assertEqual(web.count("LessonAttendanceService::dayHasLessons($conn, $classId, $date)"), 2, "save_attendance + scan_member")
        self.assertEqual(web.count("'code' => 'LESSON_DAY'"), 2)
        self.assertIn("'lessons' => \\App\\Services\\LessonAttendanceService::sessionsForDay($conn, $classId, $date)", web)
        edu = read("admin/api_education.php")
        self.assertIn("LessonAttendanceService::dayHasLessons($conn, $classId, $attendanceDate)", edu)
        self.assertIn("'code' => 'LESSON_DAY'", edu)
        route = read("api/v1/routes/attendance.php")
        self.assertIn("LessonAttendanceService::assertClassDayWritable($conn, $classId, $date);", route)
        self.assertIn("if (class_exists('\\\\App\\\\Services\\\\LessonAttendanceService')) {", route)
        self.assertIn("admin/backend/services/LessonAttendanceService.php", route)
        # the guard lives in the single helper both save and submit call
        fn = route.split("function apiReplaceAttendanceRows(", 1)[1].split("\n}\n", 1)[0]
        self.assertIn("assertClassDayWritable", fn)

    def test_api_roles_csrf_and_scoping(self):
        api = read(self.API)
        self.assertIn("LessonAttendanceService::READ_ROLES", api)
        self.assertIn("LessonAttendanceService::WRITE_ROLES", api)
        self.assertIn("if (!validateCsrf($token))", api)
        self.assertIn("ay_require_writable($conn)", api)
        self.assertIn("AttendanceRecordService::normalizeCompleteSheet($records, $roster)", api)  # complete roster, same as class/day
        self.assertIn("if ($role !== 'edu_dept') {\n            $fail(403, 'Only the Education department can cancel a lesson.');", api)
        self.assertIn("$scopeIds = $role === 'teacher' ? $teacherClassIds() : null;", api)
        self.assertIn("$fail(403, 'This member is not in one of your classes.');", api)  # no member enumeration by teachers
        self.assertIn("$fail(422, 'A lesson sheet cannot be saved for a future date.');", api)
        self.assertIn("SecurityRateLimiter", api)
        self.assertIn("SecurityAuditActor::fromAuthenticatedContext", api)
        # error disclosure discipline: only DomainException (user-safe) messages reach the client
        self.assertEqual(api.count("$fail(422, $e->getMessage());"), api.count("catch (\\DomainException $e)"))
        self.assertIn("error_log('lesson attendance save failed: ' . $e->getMessage());", api)
        self.assertIn("$fail(500, 'Could not save the lesson sheet.');", api)

    def test_access_control_and_feature_gate_know_the_new_files(self):
        ac = read("admin/access_control.php")
        for f in ("api_lesson_attendance.php", "lesson_attendance.php"):
            self.assertRegex(ac, rf"'{re.escape(f)}' => \['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept'\]")
        fg = read("admin/backend/services/FeatureGate.php")
        self.assertIn("'api_lesson_attendance.php' => 'attendance',", fg)
        self.assertIn("'lesson_attendance.php' => 'attendance',", fg)

    def test_teacher_ui_keeps_whole_day_default_and_history_page_is_a_shell(self):
        t = read(self.TEACHER)
        self.assertIn('<option value="">Whole day (class)</option>', t)
        self.assertIn('id="attLessonWrap" style="display:none;"', t)  # hidden until available
        self.assertIn("api_lesson_attendance.php?action=subjects", t)
        self.assertIn("formData.append('action', lesson ? 'save' : 'save_attendance')", t)
        self.assertIn("attSetLocked(lessons.length > 0", t)
        self.assertIn("const seq = ++attReqSeq;", t)  # out-of-order responses ignored
        self.assertIn("if (saveBtn && saveBtn.disabled) return;", t)  # no double submit / locked day
        taker = read("admin/dashboards/attendance_taker.php")
        self.assertIn("attSetDerivedLock(data.lessons || [])", taker)
        self.assertIn("$packet['lessons'] = LessonAttendanceService::sessionsForDay(", read("admin/backend/services/SubmissionService.php"))
        self.assertIn("Derived from lesson sheets:", read("admin/dashboards/edu_dept.php"))
        page = read(self.PAGE)
        self.assertIn("LessonAttendanceService::READ_ROLES", page)
        self.assertNotIn("$conn", page)
        self.assertNotIn("$pdo", page)
        self.assertNotRegex(page, r"<(?:script|link)[^>]+https?://")
        self.assertIn("ethio_date_format($today, 'F j, Y')", page)
        self.assertIn("lesson_attendance.php?member_id=", read("admin/js/department_status.js"))

    def test_mobile_not_changed_in_this_phase(self):
        flutter = ROOT / "Mobile/wbws_flutter_app/lib"
        hits = [p for p in flutter.rglob("*.dart") if "lesson_attendance" in p.read_text(encoding="utf-8", errors="ignore")]
        self.assertEqual(hits, [], "Phase 6 is web-only (slice 6-D later)")


def _phase6_ready():
    try:
        return _live_ready() and db("SHOW TABLES LIKE 'attendance_session_records'") != ""
    except Exception:
        return False


@unittest.skipUnless(_phase6_ready(), "live server + sql/076 required")
class Phase6LiveGuards(unittest.TestCase):
    """Fixture: class 1 (members 900000, 900001, 900002, 900006; teacher user 3
    assigned class 1 + subject 1) on a rolling date no other phase uses."""
    CLASS_ID = 1
    SUBJECT_ID = 1
    DATE = recent(32)
    MEMBERS = (900000, 900001, 900002, 900006)
    _sessions: dict = {}

    @classmethod
    def _clean(cls):
        db(f"DELETE FROM attendance_sessions WHERE class_id={cls.CLASS_ID} AND session_date='{cls.DATE}'")
        db(f"DELETE FROM attendance WHERE class_id={cls.CLASS_ID} AND attendance_date='{cls.DATE}'")
        db(f"DELETE FROM grade_submissions WHERE class_id={cls.CLASS_ID} AND attendance_date='{cls.DATE}' AND submission_type='attendance'")
        db("DELETE FROM activity_logs WHERE action LIKE 'Lesson Attendance%'")

    @classmethod
    def setUpClass(cls):
        cls._clean()

    @classmethod
    def tearDownClass(cls):
        cls._clean()

    def s(self, role):
        if role not in self._sessions:
            self._sessions[role] = WebSession(role)
        return self._sessions[role]

    def _records(self, by_member):
        return [{"member_id": m, "status": by_member.get(m, "present"), "note": ""} for m in self.MEMBERS]

    def _save(self, role, lesson_no, by_member, subject_id=None):
        return self.s(role).post_json("/admin/api_lesson_attendance.php", {
            "action": "save", "class_id": self.CLASS_ID, "subject_id": subject_id or self.SUBJECT_ID,
            "date": self.DATE, "lesson_no": lesson_no, "records": self._records(by_member)})

    def _day_rows(self):
        out = {}
        for line in db(f"SELECT member_id,status FROM attendance WHERE class_id={self.CLASS_ID} AND attendance_date='{self.DATE}'").splitlines():
            m, s = line.lstrip("\t").split("\t")
            out[int(m)] = s
        return out

    def test_01_two_lessons_derive_the_day_with_precedence(self):
        t = self.s("teacher")
        st, sheet = t.get_json(f"/admin/api_lesson_attendance.php?action=sheet&class_id={self.CLASS_ID}&subject_id={self.SUBJECT_ID}&date={self.DATE}&lesson_no=1")
        self.assertEqual(st, 200, sheet)
        self.assertTrue(sheet["available"])
        self.assertIsNone(sheet["session"])
        self.assertEqual(len(sheet["students"]), 4)
        self.assertRegex(sheet["date_ec"], r"^[A-Za-z]+ \d{1,2}, 20\d\d$")
        # L1: 900000 present, 900001 absent, 900002 absent, 900006 late
        st, r = self._save("teacher", 1, {900001: "absent", 900002: "absent", 900006: "late"})
        self.assertEqual(st, 200, r)
        self.assertEqual((r["present"], r["absent"], r["late"]), (1, 2, 1))
        self.assertEqual(self._day_rows(), {900000: "present", 900001: "absent", 900002: "absent", 900006: "late"})
        # L2: 900001 excused, 900002 absent, 900006 absent → day = present / excused / absent / late
        st, r = self._save("teacher", 2, {900001: "excused", 900002: "absent", 900006: "absent"})
        self.assertEqual(st, 200, r)
        self.assertEqual(self._day_rows(), {900000: "present", 900001: "excused", 900002: "absent", 900006: "late"})
        packet = db(f"SELECT status,student_count,present_count,absent_count FROM grade_submissions WHERE class_id={self.CLASS_ID} AND attendance_date='{self.DATE}' AND submission_type='attendance'").lstrip("\t")
        self.assertEqual(packet, "submitted\t4\t1\t1")
        notes = db(f"SELECT DISTINCT notes FROM attendance WHERE class_id={self.CLASS_ID} AND attendance_date='{self.DATE}'").lstrip("\t")
        self.assertEqual(notes, "derived from 2 lesson(s)")
        self.assertEqual(db("SELECT COUNT(*) FROM activity_logs WHERE action='Lesson Attendance Saved'").lstrip("\t"), "2")

    def test_02_class_day_writes_are_refused_on_a_lesson_day(self):
        t = self.s("teacher")
        data = urllib.parse.urlencode({"action": "save_attendance", "class_id": self.CLASS_ID, "date": self.DATE,
                                       "records": json.dumps(self._records({})), "csrf_token": t.csrf}).encode()
        st, body = t._open(BASE + "/admin/api_attendance.php", data=data)
        self.assertEqual(st, 409)
        self.assertIn(b'"code":"LESSON_DAY"', body)
        edu = self.s("edu_dept")
        data = urllib.parse.urlencode({"action": "batch_attendance", "class_id": self.CLASS_ID, "attendance_date": self.DATE,
                                       "records": json.dumps(self._records({})), "csrf_token": edu.csrf}).encode()
        st, body = edu._open(BASE + "/admin/api_education.php", data=data)
        self.assertEqual(st, 409, body[:200])
        code = db("SELECT member_code FROM members WHERE id=900000").lstrip("\t")
        data = urllib.parse.urlencode({"action": "scan_member", "class_id": self.CLASS_ID, "date": self.DATE, "member_code": code, "csrf_token": t.csrf}).encode()
        st, body = t._open(BASE + "/admin/api_attendance.php", data=data)
        self.assertEqual(st, 409, body[:200])
        # class/day GET carries the lessons so the sheet locks itself
        st, body = t._open(BASE + f"/admin/api_attendance.php?action=get_class_attendance&class_id={self.CLASS_ID}&date={self.DATE}")
        j = json.loads(body)
        self.assertEqual(len(j["lessons"]), 2)
        self.assertTrue(j["lesson_attendance_available"])
        # mobile: same day refused (packet lock or lesson guard — both 409)
        tok = mobile_token("teacher")
        st, j = mobile(tok, "POST", "/api/v1/attendance/submit", {"class_id": self.CLASS_ID, "date": self.DATE, "records": self._records({})})
        self.assertEqual(st, 409, j)
        self.assertIn(j.get("code"), ("ALREADY_SUBMITTED", "WORKFLOW_REJECTED"))
        self.assertEqual(self._day_rows(), {900000: "present", 900001: "excused", 900002: "absent", 900006: "late"}, "nothing changed")

    def test_03_roles_scoping_and_validation(self):
        t = self.s("teacher")
        st, r = self._save("teacher", 1, {}, subject_id=2)
        self.assertEqual(st, 403, r)  # teacher 3 has subject 1 only in class 1
        st, r = t.post_json("/admin/api_lesson_attendance.php", {"action": "save", "class_id": self.CLASS_ID, "subject_id": self.SUBJECT_ID,
                                                                  "date": self.DATE, "lesson_no": 9, "records": self._records({})})
        self.assertEqual(st, 422, r)
        st, r = t.post_json("/admin/api_lesson_attendance.php", {"action": "save", "class_id": self.CLASS_ID, "subject_id": self.SUBJECT_ID,
                                                                  "date": self.DATE, "lesson_no": 3, "records": self._records({})[:2]})
        self.assertEqual(st, 422, r)  # incomplete roster
        self.assertEqual(t.get_json(f"/admin/api_lesson_attendance.php?action=sheet&class_id=1&subject_id=1&date=2026-13-40&lesson_no=1")[0], 400)
        self.assertEqual(t._open(BASE + "/admin/api_lesson_attendance.php", data=urllib.parse.urlencode({"action": "save"}).encode())[0], 403)  # no CSRF
        for role in ("hr_dept", "info_dept"):
            st, r = self.s(role).get_json("/admin/api_lesson_attendance.php?action=member&member_id=900001")
            self.assertEqual(st, 200, r)
            self.assertEqual(r["total"], 2)
            self.assertEqual(r["member"]["id"], 900001)
            self.assertEqual({i["status"] for i in r["items"]}, {"absent", "excused"})
            self.assertTrue(all(re.match(r"^[A-Za-z]+ \d{1,2}, 20\d\d$", i["date_ec"]) for i in r["items"]))
            self.assertTrue(all(i["teacher_name"] and i["subject_name"] for i in r["items"]))
            self.assertEqual(self.s(role).post_json("/admin/api_lesson_attendance.php", {"action": "save", "class_id": 1, "subject_id": 1, "date": self.DATE, "lesson_no": 1, "records": self._records({})})[0], 403)
            self.assertEqual(self.s(role).post_json("/admin/api_lesson_attendance.php", {"action": "cancel", "session_id": 1, "reason": "not allowed here"})[0], 403)
            st, body = self.s(role)._open(BASE + "/admin/lesson_attendance.php?member_id=900001")
            self.assertEqual(st, 200)
            self.assertIn(b"Lesson Attendance", body)
        # teacher sees own classes only
        st, r = t.get_json("/admin/api_lesson_attendance.php?action=member&member_id=900001")
        self.assertEqual(r["total"], 2)
        outside = db("SELECT member_id FROM class_enrollments WHERE class_id NOT IN (SELECT class_id FROM teacher_assignments WHERE teacher_id=3) LIMIT 1").lstrip("\t")
        if outside:
            self.assertEqual(t.get_json(f"/admin/api_lesson_attendance.php?action=member&member_id={outside}")[0], 403)
        # future date refused; unknown subject refused
        st, r = t.post_json("/admin/api_lesson_attendance.php", {"action": "save", "class_id": self.CLASS_ID, "subject_id": self.SUBJECT_ID,
                                                                  "date": "2099-01-01", "lesson_no": 1, "records": self._records({})})
        self.assertEqual(st, 422, r)
        st, r = self.s("edu_dept").post_json("/admin/api_lesson_attendance.php", {"action": "save", "class_id": self.CLASS_ID, "subject_id": 999999,
                                                                                   "date": self.DATE, "lesson_no": 1, "records": self._records({})})
        self.assertEqual(st, 422, r)
        try:
            taker = self.s("attendance_taker")
        except AssertionError:
            taker = None
        if taker is not None:
            self.assertEqual(taker.get_json("/admin/api_lesson_attendance.php?action=member&member_id=900001")[0], 403)

    def test_04_education_cancel_re_derives_and_keeps_history(self):
        edu = self.s("edu_dept")
        st, day = edu.get_json(f"/admin/api_lesson_attendance.php?action=day&class_id={self.CLASS_ID}&date={self.DATE}")
        self.assertEqual(st, 200, day)
        l2 = [x for x in day["sessions"] if x["lesson_no"] == 2][0]
        self.assertEqual(edu.post_json("/admin/api_lesson_attendance.php", {"action": "cancel", "session_id": l2["id"], "reason": "x"})[0], 422)
        st, r = edu.post_json("/admin/api_lesson_attendance.php", {"action": "cancel", "session_id": l2["id"], "reason": "Lesson did not take place"})
        self.assertEqual(st, 200, r)
        # only L1 counts now
        self.assertEqual(self._day_rows(), {900000: "present", 900001: "absent", 900002: "absent", 900006: "late"})
        self.assertEqual(db(f"SELECT status FROM attendance_sessions WHERE id={l2['id']}").lstrip("\t"), "cancelled")
        self.assertEqual(db(f"SELECT COUNT(*) FROM attendance_session_records WHERE session_id={l2['id']}").lstrip("\t"), "4", "rows kept")
        st, h = self.s("hr_dept").get_json("/admin/api_lesson_attendance.php?action=member&member_id=900001")
        self.assertEqual({(i["lesson_no"], i["session_status"]) for i in h["items"]}, {(1, "submitted"), (2, "cancelled")})
        self.assertEqual(db("SELECT COUNT(*) FROM activity_logs WHERE action='Lesson Attendance Cancelled'").lstrip("\t"), "1")
        # the teacher may save the cancelled slot again → it re-opens
        st, r = self._save("teacher", 2, {})
        self.assertEqual(st, 200, r)
        self.assertEqual(db(f"SELECT status FROM attendance_sessions WHERE id={l2['id']}").lstrip("\t"), "submitted")
        self.assertEqual(self._day_rows(), {m: "present" for m in self.MEMBERS})
        # packet owner stays a teacher even after an Education cancel
        self.assertEqual(db(f"SELECT teacher_id FROM grade_submissions WHERE class_id={self.CLASS_ID} AND attendance_date='{self.DATE}' AND submission_type='attendance'").lstrip("\t"), "3")

    def test_05_cancelling_every_lesson_reopens_the_day(self):
        edu = self.s("edu_dept")
        st, day = edu.get_json(f"/admin/api_lesson_attendance.php?action=day&class_id={self.CLASS_ID}&date={self.DATE}")
        for l in day["sessions"]:
            if l["status"] != "cancelled":
                st, r = edu.post_json("/admin/api_lesson_attendance.php", {"action": "cancel", "session_id": l["id"], "reason": "No lessons took place"})
                self.assertEqual(st, 200, r)
        self.assertEqual(self._day_rows(), {}, "derived rows removed when no submitted lesson is left")
        self.assertEqual(db(f"SELECT COUNT(*) FROM attendance_session_records r JOIN attendance_sessions s ON s.id=r.session_id WHERE s.class_id={self.CLASS_ID} AND s.session_date='{self.DATE}'").lstrip("\t"), "8", "lesson rows kept for history")
        self.assertEqual(db(f"SELECT status FROM grade_submissions WHERE class_id={self.CLASS_ID} AND attendance_date='{self.DATE}' AND submission_type='attendance'").lstrip("\t"), "incomplete")
        # the class/day sheet is open again
        t = self.s("teacher")
        data = urllib.parse.urlencode({"action": "save_attendance", "class_id": self.CLASS_ID, "date": self.DATE,
                                       "records": json.dumps(self._records({900002: "absent"})), "csrf_token": t.csrf}).encode()
        st, body = t._open(BASE + "/admin/api_attendance.php", data=data)
        self.assertEqual(st, 200, body[:200])
        self.assertEqual(self._day_rows()[900002], "absent")
        # … and a new lesson sheet overrides the manual row again (derived, not deleted)
        st, r = self._save("teacher", 1, {})
        self.assertEqual(st, 200, r)
        self.assertEqual(self._day_rows(), {m: "present" for m in self.MEMBERS})


if __name__ == "__main__":
    unittest.main()
