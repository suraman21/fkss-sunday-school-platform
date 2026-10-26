"""
GET /attendance/calendar — offline class-day calendar for the mobile app.

The phone stores this calendar so it can refuse attendance on days without
class while offline. It must give exactly the verdict the save paths give
(SchoolCalendarService::dayType), only for the classes in the user's list,
and answer 304 when nothing changed so phones can check often for free.

The tests build a small school in a dedicated database (ssms_calendar_e2e)
from the real migrations sql/077 and sql/078, then call the real route file
through PHP's built-in web server (login and rate limiting are stubbed).
"""
from __future__ import annotations

import datetime as dt
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[2]
DB = {
    "host": os.environ.get("CAL_TEST_DB_HOST", "127.0.0.1"),
    "user": os.environ.get("CAL_TEST_DB_USER", "ssms"),
    "pass": os.environ.get("CAL_TEST_DB_PASS", "ssms"),
    "name": os.environ.get("CAL_TEST_DB_NAME", "ssms_calendar_e2e"),
}

COMMON = r"""<?php
$db = json_decode(getenv('CAL_TEST_DB'), true);
$root = getenv('CAL_TEST_ROOT');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
$conn->set_charset('utf8mb4');
require_once $root . '/admin/backend/ethiopian_date.php';
"""

CLI = COMMON + r"""
require_once $root . '/admin/backend/services/SchoolCalendarService.php';
use App\Services\SchoolCalendarService as S;
$op = json_decode($argv[1], true);
switch ($op['op']) {
    case 'sql':
        foreach ($op['statements'] as $q) { $conn->query($q); }
        echo json_encode(['ok' => true]);
        break;
    case 'schema':
        foreach ($op['files'] as $f) {
            $conn->multi_query(file_get_contents($root . '/' . $f));
            do { if ($r = $conn->store_result()) { $r->free(); } } while ($conn->more_results() && $conn->next_result());
        }
        echo json_encode(['ok' => true]);
        break;
    case 'parity':
        // Per-day verdicts through the single-date path (no range cache).
        $out = [];
        foreach ($op['classes'] as $cid) {
            $s = '';
            $d = new DateTime($op['from']);
            $end = new DateTime($op['to']);
            while ($d <= $end) {
                S::resetCache();
                $st = S::dayType($conn, (int)$cid, $d->format('Y-m-d'));
                $s .= !empty($st['ok']) ? (!empty($st['is_makeup']) ? 'm' : 'o')
                    : (S::MOBILE_DAY_CODES[$st['code']] ?? 'x');
                $d->modify('+1 day');
            }
            $out[(string)$cid] = $s;
        }
        echo json_encode($out);
        break;
}
"""

ROUTER = COMMON + r"""
require_once $root . '/api/v1/core/response.php';
require_once $root . '/api/v1/core/acl.php';
function apiRequireAuth(): array {
    $raw = $_SERVER['HTTP_X_TEST_AUTH'] ?? '';
    if ($raw === '') { err('Authentication required.', 401); }
    [$uid, $rol] = explode(':', $raw, 2);
    return ['uid' => (int)$uid, 'rol' => $rol];
}
function getCurrentAcademicYear() {
    global $conn;
    $r = $conn->query("SELECT * FROM academic_years WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    return $r ? $r->fetch_assoc() : null;
}
function isApiRateLimited($endpoint, $max = 60) { return false; }
$method = $_SERVER['REQUEST_METHOD'];
$ROUTE = ['id' => $_GET['_id'] ?? ''];
require $root . '/api/v1/routes/attendance.php';
"""

BASE_TABLES = [
    "DROP TABLE IF EXISTS classes, academic_years, teacher_assignments, timetable_class_days,"
    " timetable_makeup_classes, school_day_offs, school_holidays",
    "CREATE TABLE classes (id INT UNSIGNED PRIMARY KEY, class_name VARCHAR(100), class_name_en VARCHAR(100),"
    " level_order INT DEFAULT 0, is_active TINYINT(1) DEFAULT 1)",
    "CREATE TABLE academic_years (id INT UNSIGNED PRIMARY KEY, year_name VARCHAR(20), status VARCHAR(20),"
    " is_current TINYINT(1) DEFAULT 0, start_date DATE NULL, end_date DATE NULL)",
    "CREATE TABLE teacher_assignments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, teacher_id INT UNSIGNED,"
    " class_id INT UNSIGNED, academic_year_id INT UNSIGNED NULL, is_active TINYINT(1) DEFAULT 1)",
]

TEACHER = "77:teacher"
EDU = "5:edu_dept"


def addis_today() -> dt.date:
    return dt.datetime.now(ZoneInfo("Africa/Addis_Ababa")).date()


def next_weekday(start: dt.date, iso: int) -> dt.date:
    d = start
    while d.isoweekday() != iso:
        d += dt.timedelta(days=1)
    return d


def db_available() -> bool:
    if not shutil.which("php"):
        return False
    code = (f"mysqli_report(MYSQLI_REPORT_OFF); $c = @new mysqli('{DB['host']}', '{DB['user']}', '{DB['pass']}', "
            f"'{DB['name']}'); echo $c->connect_errno ? 'no' : 'yes';")
    try:
        out = subprocess.run(["php", "-r", code], capture_output=True, text=True, timeout=20).stdout
    except Exception:
        return False
    return out.strip() == "yes"


@unittest.skipUnless(db_available(), "MariaDB test database ssms_calendar_e2e is required")
class OfflineCalendarTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = Path(tempfile.mkdtemp())
        (cls.tmp / "cli.php").write_text(CLI)
        (cls.tmp / "router.php").write_text(ROUTER)
        cls.env = dict(os.environ, CAL_TEST_DB=json.dumps(DB), CAL_TEST_ROOT=str(ROOT))
        with socket.socket() as s:
            s.bind(("127.0.0.1", 0))
            cls.port = s.getsockname()[1]
        cls.server = subprocess.Popen(["php", "-S", f"127.0.0.1:{cls.port}", str(cls.tmp / "router.php")],
                                      cwd=cls.tmp, env=cls.env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1", cls.port), timeout=0.2):
                    break
            except OSError:
                time.sleep(0.05)

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=10)
        shutil.rmtree(cls.tmp, ignore_errors=True)

    # ── helpers ─────────────────────────────────────────────────────────
    def cli(self, **op):
        proc = subprocess.run(["php", str(self.tmp / "cli.php"), json.dumps(op)], capture_output=True,
                              text=True, timeout=60, env=self.env)
        self.assertEqual(proc.returncode, 0, proc.stderr + proc.stdout)
        return json.loads(proc.stdout)

    def sql(self, *statements):
        self.cli(op="sql", statements=list(statements))

    def get(self, auth=EDU, etag=None):
        req = urllib.request.Request(f"http://127.0.0.1:{self.port}/?_id=calendar")
        if auth:
            req.add_header("X-Test-Auth", auth)
        if etag:
            req.add_header("If-None-Match", etag)
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                body = r.read().decode()
                return r.status, r.headers.get("ETag"), (json.loads(body) if body else None)
        except urllib.error.HTTPError as e:
            body = e.read().decode()
            return e.code, e.headers.get("ETag"), (json.loads(body) if body.strip() else None)

    def setUp(self):
        self.today = addis_today()
        self.sql(*BASE_TABLES)
        self.cli(op="schema", files=["sql/077_timetable_builder.sql", "sql/078_school_calendar.sql"])
        t = self.today
        self.sun = next_weekday(t + dt.timedelta(days=1), 7)            # Day Off (cancels makeups)
        self.sat_h = next_weekday(self.sun + dt.timedelta(days=1), 6)   # holiday Sat..Sun
        self.wed = next_weekday(t + dt.timedelta(days=1), 3)            # weekday makeup
        self.sat_d2 = self.sat_h + dt.timedelta(days=7)                 # Day Off keeping makeups
        self.past_sun = next_weekday(t - dt.timedelta(days=20), 7)      # Day Off then reopened
        self.sql(
            "INSERT INTO academic_years VALUES (5, '2019', 'active', 1, NULL, NULL)",
            "INSERT INTO classes VALUES (1,'Grade 1','Grade 1',1,1),(2,'Grade 2','Grade 2',2,1),(3,'Old','Old',3,0)",
            "INSERT INTO teacher_assignments (teacher_id,class_id,academic_year_id,is_active)"
            " VALUES (77,1,5,1),(77,2,5,0),(88,2,NULL,1)",
            "INSERT INTO timetable_class_days (class_id,academic_year_id,working_days) VALUES (1,5,'6,7'),(3,5,'1,2,3,4,5,6,7')",
            f"INSERT INTO school_day_offs (day_date,is_off,cancel_makeup,reason) VALUES ('{self.sun}',1,1,'Feast')",
            f"INSERT INTO school_day_offs (day_date,is_off,cancel_makeup,reason) VALUES ('{self.sat_d2}',1,0,'')",
            f"INSERT INTO school_day_offs (day_date,is_off,cancel_makeup,reason) VALUES ('{self.past_sun}',1,1,'x')",
            f"INSERT INTO school_day_offs (day_date,is_off,cancel_makeup,reason) VALUES ('{self.past_sun}',0,1,'reopen')",
            f"INSERT INTO school_holidays (title,title_am,date_from,date_to) VALUES ('Holiday','በዓል','{self.sat_h}',"
            f"'{self.sat_h + dt.timedelta(days=1)}')",
            "INSERT INTO timetable_makeup_classes (class_id,academic_year_id,lesson_date,start_time,end_time) VALUES "
            f"(1,5,'{self.wed}','09:00','10:00'),(1,5,'{self.sat_h}','09:00','10:00'),"
            f"(1,5,'{self.sat_d2}','09:00','10:00'),(1,5,'{self.sun}','09:00','10:00')",
        )

    def code(self, payload, class_id, date):
        days = next(c["days"] for c in payload["classes"] if c["class_id"] == class_id)
        return days[(date - dt.date.fromisoformat(payload["from"])).days]

    # ── content ─────────────────────────────────────────────────────────
    def test_window_and_shape(self):
        status, etag, body = self.get()
        self.assertEqual(status, 200)
        data = body["data"]
        self.assertEqual(data["today"], self.today.isoformat())
        self.assertEqual(data["from"], (self.today - dt.timedelta(days=45)).isoformat())
        self.assertEqual(data["to"], (self.today + dt.timedelta(days=60)).isoformat())
        self.assertEqual(data["backdate_days"], 45)
        self.assertTrue(data["enforced"])
        self.assertEqual(etag, data["etag"])
        for c in data["classes"]:
            self.assertEqual(len(c["days"]), 106)

    def test_codes_follow_the_class_day_rule(self):
        data = self.get()[2]["data"]
        self.assertEqual(self.code(data, 1, self.sun), "d", "Day Off cancels the makeup")
        self.assertEqual(self.code(data, 1, self.sat_h), "m", "makeup punches through a holiday")
        self.assertEqual(self.code(data, 1, self.sat_h + dt.timedelta(days=1)), "h")
        self.assertEqual(self.code(data, 1, self.wed), "m", "weekday makeup")
        self.assertEqual(self.code(data, 1, self.sat_d2), "m", "Day Off that keeps makeups")
        self.assertEqual(self.code(data, 1, self.past_sun), "o", "reopened Day Off")
        self.assertEqual(self.code(data, 1, self.wed + dt.timedelta(days=1)), "n", "Thursday is not a class day")
        self.assertEqual(self.code(data, 2, self.wed), "c", "working days not set")
        self.assertEqual(self.code(data, 2, self.sat_d2), "d")
        self.assertEqual(data["school"][self.sun.isoformat()], "Day Off: Feast")
        self.assertEqual(data["school"][(self.sat_h + dt.timedelta(days=1)).isoformat()], "Holiday: በዓል")

    def test_every_day_matches_the_save_rule(self):
        data = self.get()[2]["data"]
        ids = [c["class_id"] for c in data["classes"]]
        expected = self.cli(op="parity", classes=ids, **{"from": data["from"], "to": data["to"]})
        for c in data["classes"]:
            self.assertEqual(c["days"], expected[str(c["class_id"])], f"class {c['class_id']}")

    # ── who sees what ───────────────────────────────────────────────────
    def test_teacher_gets_only_assigned_classes(self):
        data = self.get(auth=TEACHER)[2]["data"]
        self.assertEqual([c["class_id"] for c in data["classes"]], [1])
        other = self.get(auth="88:attendance_taker")[2]["data"]
        self.assertEqual([c["class_id"] for c in other["classes"]], [2], "year NULL assignment counts")

    def test_other_attendance_roles_get_all_active_classes(self):
        for role in ("5:edu_dept", "1:super_admin", "2:school_admin"):
            data = self.get(auth=role)[2]["data"]
            self.assertEqual([c["class_id"] for c in data["classes"]], [1, 2], role)

    def test_non_attendance_roles_and_anonymous_are_refused(self):
        self.assertEqual(self.get(auth="9:hr_dept")[0], 403)
        self.assertEqual(self.get(auth="9:info_dept")[0], 403)
        self.assertEqual(self.get(auth=None)[0], 401)

    # ── conditional requests ────────────────────────────────────────────
    def test_unchanged_calendar_answers_304(self):
        status, etag, _ = self.get()
        self.assertEqual(status, 200)
        self.assertEqual(self.get(etag=etag)[0], 304)
        self.assertIsNone(self.get(etag=etag)[2])
        self.assertEqual(self.get(etag="W/" + etag[:-1] + '-gzip"')[0], 304, "mod_deflate / weak form")
        self.assertEqual(self.get(etag='"cal1-other"')[0], 200)

    def assert_change_detected(self, *statements, auth=EDU):
        _, before, _ = self.get(auth=auth)
        self.sql(*statements)
        status, after, body = self.get(auth=auth, etag=before)
        self.assertEqual(status, 200, f"change not detected: {statements}")
        self.assertNotEqual(after, before)
        self.assertEqual(body["data"]["etag"], after)

    def test_every_rule_input_changes_the_etag(self):
        d = self.today + dt.timedelta(days=3)
        self.assert_change_detected(f"INSERT INTO school_day_offs (day_date,is_off,reason) VALUES ('{d}',1,'new')")
        self.assert_change_detected(f"INSERT INTO school_day_offs (day_date,is_off,reason) VALUES ('{d}',0,'reopen')")
        self.assert_change_detected("UPDATE school_day_offs SET reason = 'Feast day' WHERE reason = 'Feast'")
        self.assert_change_detected("UPDATE school_day_offs SET cancel_makeup = 0 WHERE reason = 'Feast day'")
        self.assert_change_detected(f"UPDATE school_day_offs SET day_date = '{d}' WHERE reason = 'Feast day'")
        self.assert_change_detected("UPDATE school_holidays SET title_am = 'ሌላ'")
        self.assert_change_detected("UPDATE school_holidays SET title = 'Other'")
        self.assert_change_detected(f"UPDATE school_holidays SET date_to = '{self.sat_h + dt.timedelta(days=3)}'")
        self.assert_change_detected(f"UPDATE school_holidays SET date_from = '{self.sat_h - dt.timedelta(days=1)}'")
        self.assert_change_detected("DELETE FROM school_holidays")
        self.assert_change_detected("UPDATE timetable_class_days SET working_days = '7' WHERE class_id = 1")
        self.assert_change_detected("DELETE FROM timetable_class_days WHERE class_id = 3")
        self.assert_change_detected(f"UPDATE timetable_makeup_classes SET lesson_date = '{d}' WHERE lesson_date = '{self.wed}'")
        self.assert_change_detected(f"UPDATE timetable_makeup_classes SET class_id = 2 WHERE lesson_date = '{d}'")
        self.assert_change_detected("UPDATE timetable_class_days SET academic_year_id = 6 WHERE class_id = 1")
        self.assert_change_detected("DELETE FROM timetable_makeup_classes WHERE class_id = 1 LIMIT 1")
        self.assert_change_detected("UPDATE academic_years SET end_date = '2030-01-01' WHERE id = 5")
        self.assert_change_detected("UPDATE classes SET is_active = 0 WHERE id = 2")
        self.assert_change_detected("UPDATE teacher_assignments SET is_active = 1 WHERE teacher_id = 77 AND class_id = 2",
                                    auth=TEACHER)

    def test_unrelated_edits_keep_the_etag(self):
        status, before, _ = self.get()
        self.sql("UPDATE timetable_makeup_classes SET room = 'Hall', start_time = '08:00'",
                 "UPDATE classes SET class_name = 'Grade One' WHERE id = 1")
        self.assertEqual(self.get(etag=before)[0], 304)

    def test_before_the_calendar_migration_everything_is_allowed(self):
        self.sql("DROP TABLE school_day_offs, school_holidays")
        status, etag, body = self.get()
        self.assertEqual(status, 200)
        data = body["data"]
        self.assertFalse(data["enforced"])
        self.assertTrue(all(set(c["days"]) == {"o"} for c in data["classes"]))
        self.assertEqual(self.get(etag=etag)[0], 304)


class OfflineCalendarWiringTests(unittest.TestCase):
    def test_route_sits_behind_the_attendance_role_gate(self):
        src = (ROOT / "api/v1/routes/attendance.php").read_text(encoding="utf-8")
        gate = src.index("if (!apiRoleIs($auth, apiRolesAttendance()))")
        route = src.index("if ($method === 'GET' && $action === 'calendar')")
        self.assertLess(gate, route)
        self.assertTrue("apiAttendanceClassIds($conn, $auth, $yearId)" in src[route:route + 1500])

    def test_unknown_verdicts_are_left_to_the_server(self):
        # dayType() has no other failure codes today; a future one must reach
        # the phone as "x" (server decides), never as an allowed day.
        src = (ROOT / "admin/backend/services/SchoolCalendarService.php").read_text(encoding="utf-8")
        body = src[src.index("public static function mobileCalendar"):src.index("public static function calendarRevision")]
        self.assertTrue("self::MOBILE_DAY_CODES[$st['code'] ?? ''] ?? 'x';" in body)

    def test_ci_creates_the_calendar_test_database(self):
        wf = (ROOT / ".github/workflows/backend-checks.yml").read_text(encoding="utf-8")
        self.assertTrue("ssms_calendar_e2e" in wf)


if __name__ == "__main__":
    unittest.main()
