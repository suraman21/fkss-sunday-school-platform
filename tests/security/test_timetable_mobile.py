"""
Timetable T-3 — teacher mobile timetable (read-only) guards.

Static part always runs (route registration, dependency guards, Flutter
screen contract: Weekly/Daily/Monthly, Today, Now highlight, EC-only dates,
per-user offline cache wiped on sign-out, FKSS palette, release pin).

Live part needs the local PHP test server + seeded `ssms_test` with sql/077
applied; it skips cleanly otherwise (and when earlier suites used up the
mobile login rate limit).
"""
from __future__ import annotations

import datetime as _dt
import json
import re
import sys
import unittest
import urllib.error
import urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from test_attendance_phase1 import (  # noqa: E402
    BASE, USERS, WebSession, db, mobile, mobile_token, _live_ready,
)

def _addis_today():
    """Africa/Addis_Ababa 'today' — the clock every server-side date rule uses."""
    return (_dt.datetime.now(_dt.timezone.utc) + _dt.timedelta(hours=3)).date()


FLUTTER = ROOT / "Mobile/wbws_flutter_app"
ROUTE = ROOT / "api/v1/routes/timetable.php"
SCREEN = FLUTTER / "lib/screens/teacher/teacher_timetable_screen.dart"
CACHE = FLUTTER / "lib/services/timetable_cache.dart"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


def _token(role):
    try:
        return mobile_token(role)
    except urllib.error.HTTPError as e:
        if e.code == 429:
            raise unittest.SkipTest("mobile login rate-limited by earlier suites — rerun later")
        raise


class TimetableMobileStaticGuards(unittest.TestCase):
    def test_route_registered_and_guarded(self):
        index = read(ROOT / "api/v1/index.php")
        self.assertRegex(index, r"'timetable'\s*=>\s*'timetable\.php'")
        src = read(ROUTE)
        self.assertIn("TimetableService.php", src)
        self.assertIn("class_exists('\\\\App\\\\Services\\\\TimetableService')", src)
        self.assertIn("apiRequireAuth()", src)
        self.assertIn("isApiRateLimited('timetable'", src)
        # read-only resource
        self.assertIn("$method !== 'GET'", src)
        self.assertIn("405", src)
        # teacher forced onto own id; only Education roles may pass teacher_id
        self.assertIn("apiRoleIs($auth, apiRolesEducation()) && !empty($_GET['teacher_id'])", src)
        self.assertNotIn("getMessage(", src)
        # EC labels ride along with the stored Gregorian strings
        self.assertIn("today_date_ec", src)
        self.assertIn("lesson_date_ec", src)
        self.assertIn("ethio_date_format", src)

    def test_api_service_has_timetable_call(self):
        api = read(FLUTTER / "lib/services/api_service.dart")
        self.assertIn("getMyTimetableWeek() => get('/timetable/week')", api)

    def test_screen_contract_matches_eduid_layout(self):
        s = read(SCREEN)
        # three views + Today + Back to Today + Now highlight + day strip dots
        self.assertIn("enum _View { weekly, daily, monthly }", s)
        for label in ("'Weekly'", "'Daily'", "'Monthly'", "'Today'", "'Back to Today'", "_pill('Now'"):
            self.assertIn(label, s, label)
        self.assertIn("_weekDayCell(", s)
        self.assertIn("_monthCell(", s)
        self.assertIn("_isNow(", s)
        # makeup classes shown with their own badge
        self.assertIn("_pill('Makeup'", s)
        self.assertIn("makeup_classes", s)
        # empty / offline states
        self.assertIn("Can't load your timetable", s)
        self.assertIn("No timetable yet", s)
        self.assertIn("Offline — showing the timetable last saved on this phone", s)
        # read-only: no POST/PUT/DELETE from this screen
        self.assertNotIn(".post(", s)
        self.assertNotIn(".delete(", s)

    def test_screen_is_ethiopian_calendar_only(self):
        s = read(SCREEN)
        self.assertNotIn("package:intl", s)
        self.assertNotIn("DateFormat(", s)
        self.assertIn("EthiopianDate.fromGregorian", s)
        self.assertIn("monthNameAmharic(", s)
        self.assertIn("daysInEthiopianMonth(", s)
        self.assertIn("ethiopianToGregorian(", s)
        # month grid navigates across the 13 Ethiopian months
        self.assertIn("m = 13;", s)
        self.assertIn("if (m < 1)", s)
        # No Gregorian month names anywhere in user-facing strings
        for g in ("January", "February", "October", "December", "'Jan'", "'Oct'"):
            self.assertNotIn(g, s, g)

    def test_screen_uses_fkss_palette_not_eduid_colors(self):
        s = read(SCREEN)
        self.assertNotIn("AppColors.", s)
        self.assertIn("AppTheme.primary", s)
        self.assertIn("AppTheme.accent", s)
        theme = read(FLUTTER / "lib/utils/theme.dart")
        self.assertIn("Color(0xFF5A1212)", theme)
        self.assertIn("Color(0xFFD4AF37)", theme)

    def test_offline_cache_is_per_user_and_wiped_on_sign_out(self):
        c = read(CACHE)
        self.assertIn("SharedPreferences.getInstance()", c)
        self.assertIn("if (decoded['owner'] != userId) return null;", c)
        self.assertIn("Future<void> clearAll()", c)
        sess = read(FLUTTER / "lib/services/session_service.dart")
        self.assertIn("import 'timetable_cache.dart';", sess)
        self.assertEqual(sess.count("await TimetableCache.instance.clearAll();"), 2)
        # no sqflite schema change was needed for this cache
        self.assertNotIn("timetable", read(FLUTTER / "lib/services/local_db.dart").lower())

    def test_teacher_home_links_to_timetable(self):
        home = read(FLUTTER / "lib/screens/teacher/teacher_home.dart")
        self.assertIn("import 'teacher_timetable_screen.dart';", home)
        self.assertIn("_buildTimetableCard()", home)
        self.assertIn("const TeacherTimetableScreen()", home)

    def test_timetable_is_a_teacher_bottom_nav_tab_with_daily_default(self):
        """Owner 2026-10-20: Timetable also in the bottom nav (Home card kept),
        segments ordered Daily · Weekly · Monthly, Daily by default, and one
        continuous header block instead of AppBar + banner."""
        cfg = read(FLUTTER / "lib/utils/config.dart")
        teacher = cfg.split("case UserRoles.teacher:", 1)[1].split("];", 1)[0]
        self.assertIn("NavTab(id: 'timetable', label: 'Timetable'", teacher)
        ids = re.findall(r"NavTab\(id: '([a-z_]+)'", teacher)
        self.assertEqual(ids, ["home", "attendance", "grades", "timetable", "profile"])
        shell = read(FLUTTER / "lib/screens/shell/app_shell.dart")
        self.assertIn("case 'timetable':", shell)
        self.assertIn("return const TeacherTimetableScreen();", shell)
        self.assertIn("import '../teacher/teacher_timetable_screen.dart';", shell)
        s = read(SCREEN)
        self.assertIn("_View _view = _View.daily;", s)
        order = [m for m in re.findall(r"seg\(_View\.(\w+),", s)]
        self.assertEqual(order, ["daily", "weekly", "monthly"])
        self.assertNotIn("appBar: AppBar(", s)
        self.assertIn("BorderRadius.vertical(bottom: Radius.circular(22))", s)
        self.assertIn("Navigator.of(context).canPop()", s)
        # header calendar icon is a working EC date picker (Monthly grid), not decoration
        self.assertIn("onTap: _openMonthPicker", s)
        self.assertIn("timetable_calendar_button", s)
        # the Home card still exists
        home = read(FLUTTER / "lib/screens/teacher/teacher_home.dart")
        self.assertIn("TeacherTimetableScreen", home)

    def test_release_pin_moved_together(self):
        pubspec = read(FLUTTER / "pubspec.yaml")
        config = read(FLUTTER / "lib/utils/config.dart")
        notes = read(FLUTTER / "RELEASE_NOTES.md")
        rel = read(ROOT / "api/v1/core/app_release.php")
        self.assertRegex(pubspec, r"(?m)^version:\s*1\.7\.4\+41\s*$")
        self.assertIn("appVersion = '1.7.4'", config)
        self.assertIn("appBuild = 41", config)
        self.assertIn("## 1.7.4 (build 41)", notes)
        self.assertIn("My Timetable", notes)
        self.assertRegex(rel, r"'latest_version'\s*=>\s*'1\.7\.4'")
        self.assertRegex(rel, r"'latest_build'\s*=>\s*41")
        # pubspec untouched apart from the version line (no new packages)
        self.assertNotIn("calendar_view", pubspec)
        self.assertNotIn("table_calendar", pubspec)


@unittest.skipUnless(_live_ready(), "live test server / seeded DB not available")
class TimetableMobileLive(unittest.TestCase):
    """Seeds class 1 (Sat/Sun bell + two lessons + one makeup today) for
    teacher audit_teach (id 3) and reads it back through /api/v1/timetable."""

    @classmethod
    def setUpClass(cls):
        cls.tearDownClass()
        db(
            "INSERT INTO timetable_class_days (class_id,academic_year_id,working_days) VALUES (1,1,'6,7')"
            " ON DUPLICATE KEY UPDATE working_days=VALUES(working_days);"
            "INSERT INTO timetable_periods (class_id,academic_year_id,label,start_time,end_time,is_break,sort_order)"
            " VALUES (1,1,'TT-P1','08:00','08:45',0,9901),(1,1,'TT-Break','08:45','09:00',1,9902),(1,1,'TT-P2','09:00','09:45',0,9903);"
            "SET @p1=(SELECT id FROM timetable_periods WHERE sort_order=9901 AND class_id=1);"
            "SET @p2=(SELECT id FROM timetable_periods WHERE sort_order=9903 AND class_id=1);"
            "INSERT INTO timetable_entries (class_id,academic_year_id,period_id,day_of_week,subject_id,teacher_id,room)"
            " VALUES (1,1,@p1,6,1,3,'Room 1'),(1,1,@p2,7,2,3,'Hall');"
            "INSERT INTO timetable_makeup_classes (class_id,academic_year_id,lesson_date,start_time,end_time,subject_id,teacher_id,reason)"
            f" VALUES (1,1,'{_addis_today().isoformat()}','14:00','14:45',1,3,'tt-mobile-test');"
        )
        cls.teacher = _token("teacher")

    @classmethod
    def tearDownClass(cls):
        db(
            "DELETE FROM timetable_makeup_classes WHERE reason='tt-mobile-test';"
            "DELETE FROM timetable_entries WHERE class_id=1 AND period_id IN (SELECT id FROM timetable_periods WHERE sort_order IN (9901,9903));"
            "DELETE FROM timetable_periods WHERE sort_order IN (9901,9902,9903);"
            # restore the seeded default row (do NOT delete it — later tests need it)
            "UPDATE timetable_class_days SET working_days='1,2,3,4,5,6,7' WHERE class_id=1 AND working_days='6,7';"
        )

    def test_week_returns_own_lessons_keyed_by_iso_weekday_with_ec_labels(self):
        code, body = mobile(self.teacher, "GET", "/api/v1/timetable/week")
        self.assertEqual(code, 200, body)
        d = body["data"]
        self.assertEqual(d["teacher_id"], 3)
        self.assertIn("6", d["days"])
        self.assertIn("7", d["days"])
        sat = d["days"]["6"][0]
        self.assertEqual(sat["start_time"], "08:00")
        self.assertEqual(sat["room"], "Room 1")
        self.assertEqual(sat["subject_name_en"], "Holy Bible")
        self.assertEqual(sorted(d["working_days"]), [6, 7])
        # no break rows leak into a teacher's list
        self.assertFalse(any(l["period_label"] == "TT-Break" for l in d["lessons"]))
        # EC labels present, in English month names, 2019 EC for Oct 2026
        self.assertRegex(d["today_date_ec"], r"^[A-Z][a-z]+day, [A-Za-z]+ \d{1,2}, \d{4}$")
        mk = [m for m in d["makeup_classes"] if m["reason"] == "tt-mobile-test"]
        self.assertEqual(len(mk), 1)
        self.assertEqual(mk[0]["lesson_date"], d["today_date"])
        self.assertEqual(mk[0]["lesson_date_ec"], d["today_date_ec"])

    def test_today_filters_to_today(self):
        code, body = mobile(self.teacher, "GET", "/api/v1/timetable/today")
        self.assertEqual(code, 200, body)
        d = body["data"]
        self.assertNotIn("days", d)
        for l in d["lessons"]:
            self.assertEqual(l["day_of_week"], d["today_iso"])
        for m in d["makeup_classes"]:
            self.assertEqual(m["lesson_date"], d["today_date"])

    def test_teacher_cannot_read_another_teachers_schedule(self):
        code, body = mobile(self.teacher, "GET", "/api/v1/timetable/week?teacher_id=1")
        self.assertEqual(code, 200, body)
        self.assertEqual(body["data"]["teacher_id"], 3)

    def test_write_methods_and_unknown_sub_rejected(self):
        code, _ = mobile(self.teacher, "POST", "/api/v1/timetable/week", {"x": 1})
        self.assertEqual(code, 405)
        code, _ = mobile(self.teacher, "GET", "/api/v1/timetable/nope")
        self.assertEqual(code, 404)

    def test_non_education_roles_are_refused(self):
        tok = _token("hr_dept")
        code, _ = mobile(tok, "GET", "/api/v1/timetable/week")
        self.assertEqual(code, 403)


def _ec_from_gregorian(ymd: str):
    """Independent EC conversion (JDN epoch 1723856) used to cross-check the API labels."""
    d = _dt.date.fromisoformat(ymd)
    a = (14 - d.month) // 12
    y = d.year + 4800 - a
    m = d.month + 12 * a - 3
    jdn = d.day + (153 * m + 2) // 5 + 365 * y + y // 4 - y // 100 + y // 400 - 32045
    e = 1723856
    r = (jdn - e) % 1461
    n = r % 365 + 365 * (r // 1460)
    year = 4 * ((jdn - e) // 1461) + r // 365 - r // 1460
    return year, n // 30 + 1, n % 30 + 1


EC_MONTHS = ["Meskerem", "Tikimt", "Hidar", "Tahsas", "Tir", "Yekatit", "Megabit",
             "Miyazya", "Ginbot", "Sene", "Hamle", "Nehase", "Pagume"]


@unittest.skipUnless(_live_ready(), "live test server / seeded DB not available")
class TimetableWebToMobileEndToEnd(unittest.TestCase):
    """Education builds class 2 on the WEB builder (real CSRF session, real
    api_timetable.php actions) → the teacher reads it on the PHONE API.
    Everything Education placed must come back, nothing else, with EC labels
    that match an independent conversion; Education / admins can look at the
    teacher; non-education roles cannot. Cleans up through the web API too."""

    CLASS = 2

    @classmethod
    def setUpClass(cls):
        if db("SHOW TABLES LIKE 'timetable_class_days'") == "":
            raise unittest.SkipTest("sql/077 not applied")
        cls._wipe()
        cls.edu = WebSession("edu_dept")
        cls.T = int(db("SELECT id FROM users WHERE username='audit_teach'"))
        subs = [int(x) for x in db("SELECT id FROM subjects WHERE is_active=1 ORDER BY id LIMIT 2").split()]
        cls.S1, cls.S2 = subs[0], subs[-1]
        cls.created = {"periods": [], "makeups": []}

    @classmethod
    def tearDownClass(cls):
        cls._wipe()

    @classmethod
    def _wipe(cls):
        db(f"DELETE FROM timetable_entries WHERE class_id={cls.CLASS}")
        db(f"DELETE FROM timetable_periods WHERE class_id={cls.CLASS}")
        # restore the seeded default row instead of deleting it
        db(f"UPDATE timetable_class_days SET working_days='1,2,3,4,5,6,7' WHERE class_id={cls.CLASS}")
        db(f"DELETE FROM timetable_makeup_classes WHERE class_id={cls.CLASS}")

    def web(self, action, payload):
        payload = dict(payload)
        payload["csrf_token"] = self.edu.csrf
        st, body = self.edu._open(
            BASE + "/admin/api_timetable.php?action=" + action,
            data=urllib.parse.urlencode(payload, doseq=True).encode(),
            headers={"Content-Type": "application/x-www-form-urlencoded"},
        )
        d = json.loads(body.decode("utf-8"))
        self.assertEqual(d.get("status"), "success", (action, d))
        return d

    def test_end_to_end(self):
        C, T = self.CLASS, self.T
        today = _addis_today()
        today_iso = today.isoweekday()
        other_iso = 1 if today_iso != 1 else 2

        # ── 1. Education builds the class on the web ──────────────────
        self.web("save_days", {"class_id": C, "days[]": [today_iso, other_iso], "confirm_phrase": "REMOVE DAY"})  # typed confirmation (danger-confirm DC-1)
        p1 = self.web("save_period", {"class_id": C, "label": "E2E P1", "start_time": "07:30", "end_time": "08:15"})["period_id"]
        pb = self.web("save_period", {"class_id": C, "label": "E2E Break", "start_time": "08:15", "end_time": "08:30", "is_break": 1})["period_id"]
        p2 = self.web("save_period", {"class_id": C, "label": "E2E P2", "start_time": "08:30", "end_time": "09:15"})["period_id"]
        self.web("save_entry", {"class_id": C, "period_id": p1, "day_of_week": today_iso, "subject_id": self.S1, "teacher_id": T, "room": "E2E Room A"})
        self.web("save_entry", {"class_id": C, "period_id": p2, "day_of_week": other_iso, "subject_id": self.S2, "teacher_id": T, "room": ""})
        # a lesson of ANOTHER teacher in the same class must not leak into ours
        # (the seed has a single teacher account, so this row is seeded directly)
        year_id = int(db("SELECT id FROM academic_years WHERE status='active' ORDER BY id DESC LIMIT 1"))
        db(f"INSERT INTO timetable_entries (class_id,academic_year_id,period_id,day_of_week,subject_id,teacher_id,room)"
           f" VALUES ({C},{year_id},{p2},{today_iso},{self.S2},1,'X')")
        mk_today = self.web("save_makeup", {"class_id": C, "lesson_date": today.isoformat(), "start_time": "16:00", "end_time": "16:40",
                                             "subject_id": self.S1, "teacher_id": T, "reason": "E2E today"})
        nxt = today + _dt.timedelta(days=3)
        self.web("save_makeup", {"class_id": C, "lesson_date": nxt.isoformat(), "start_time": "16:00", "end_time": "16:40",
                                            "subject_id": self.S2, "teacher_id": T, "reason": "E2E later"})

        # ── 2. Teacher reads it on the phone ──────────────────────────
        tok = _token("teacher")
        code, body = mobile(tok, "GET", "/api/v1/timetable/week")
        self.assertEqual(code, 200, body)
        d = body["data"]
        self.assertEqual(d["teacher_id"], T)
        self.assertEqual(d["today_date"], today.isoformat())
        self.assertEqual(d["today_iso"], today_iso)

        mine = [l for l in d["lessons"] if l["class_id"] == C]
        self.assertEqual(len(mine), 2, mine)
        by_day = {l["day_of_week"]: l for l in mine}
        self.assertEqual(by_day[today_iso]["period_label"], "E2E P1")
        self.assertEqual(by_day[today_iso]["start_time"], "07:30")
        self.assertEqual(by_day[today_iso]["end_time"], "08:15")
        self.assertEqual(by_day[today_iso]["room"], "E2E Room A")
        self.assertEqual(by_day[today_iso]["subject_id"], self.S1)
        self.assertEqual(by_day[other_iso]["period_label"], "E2E P2")
        self.assertEqual(by_day[other_iso]["room"], "")
        # the break period and the other teacher's lesson never appear
        self.assertFalse(any(l["period_id"] == pb for l in d["lessons"]))
        self.assertFalse(any(l["room"] == "X" for l in d["lessons"]))
        # days{} mirrors lessons[] and working_days lists only days with lessons
        for iso, l in by_day.items():
            self.assertIn(l, d["days"][str(iso)])
        self.assertTrue({today_iso, other_iso} <= set(d["working_days"]))

        mks = {m["reason"]: m for m in d["makeup_classes"] if m["class_id"] == C}
        self.assertEqual(set(mks), {"E2E today", "E2E later"})
        self.assertEqual(mks["E2E today"]["lesson_date"], today.isoformat())
        self.assertEqual(mks["E2E later"]["lesson_date"], nxt.isoformat())
        self.assertEqual(mks["E2E today"]["start_time"], "16:00")

        # ── 3. Ethiopian labels cross-checked independently ───────────
        for ymd, label in ((d["today_date"], d["today_date_ec"]),
                           (nxt.isoformat(), mks["E2E later"]["lesson_date_ec"])):
            ey, em, ed = _ec_from_gregorian(ymd)
            dow = _dt.date.fromisoformat(ymd).strftime("%A")
            self.assertEqual(label, f"{dow}, {EC_MONTHS[em - 1]} {ed}, {ey}", (ymd, label))
        self.assertNotRegex(d["today_date_ec"], r"\b20(2[0-9])\b")  # no Gregorian year in the EC label

        # ── 4. /today = only today's weekday + today's makeups ────────
        code, body = mobile(tok, "GET", "/api/v1/timetable/today")
        self.assertEqual(code, 200, body)
        t = body["data"]
        self.assertEqual([l["period_label"] for l in t["lessons"] if l["class_id"] == C], ["E2E P1"])
        self.assertEqual([m["reason"] for m in t["makeup_classes"] if m["class_id"] == C], ["E2E today"])

        # ── 5. Education / admin may look at this teacher; others cannot ─
        for role in ("edu_dept", "school_admin"):
            rtok = _token(role)
            code, body = mobile(rtok, "GET", f"/api/v1/timetable/week?teacher_id={T}")
            self.assertEqual(code, 200, (role, body))
            self.assertEqual(body["data"]["teacher_id"], T)
            self.assertEqual(len([l for l in body["data"]["lessons"] if l["class_id"] == C]), 2)
            # without teacher_id they get their own (normally empty) schedule, never an error
            code, body = mobile(rtok, "GET", "/api/v1/timetable/week")
            self.assertEqual(code, 200)
            self.assertNotEqual(body["data"]["teacher_id"], T)
        code, body = mobile(_token("info_dept"), "GET", "/api/v1/timetable/week")
        self.assertEqual(code, 403, body)

        # ── 6. Education clears a lesson + removes a makeup on the web → phone follows ─
        self.web("clear_entry", {"class_id": C, "period_id": p1, "day_of_week": today_iso})
        self.web("delete_makeup", {"class_id": C, "makeup_id": mk_today["makeup_id"]})
        code, body = mobile(tok, "GET", "/api/v1/timetable/week")
        self.assertEqual(code, 200, body)
        d = body["data"]
        self.assertEqual([l["period_label"] for l in d["lessons"] if l["class_id"] == C], ["E2E P2"])
        self.assertEqual([m["reason"] for m in d["makeup_classes"] if m["class_id"] == C], ["E2E later"])
        # the web audit trail recorded Education's work (no mobile write path exists)
        self.assertGreaterEqual(int(db("SELECT COUNT(*) FROM activity_logs WHERE entity_type='timetable' AND action LIKE 'Timetable%'")), 6)


if __name__ == "__main__":
    unittest.main()

class SchemaGuardStatic(unittest.TestCase):
    """The timetable module must degrade gracefully before sql/077 is run.

    Production runs this code from the moment the owner pulls, but the
    builder tables only exist after sql/077_timetable_builder.sql is pasted
    into phpMyAdmin. Every public entry point of TimetableService funnels
    through schemaReady() so a pre-077 database gets empty data / a clean
    refusal instead of a 500 (mobile /timetable/week and /today were the
    confirmed fatals in the 2026-10-20 audit)."""

    def test_schema_ready_guard_present(self):
        svc = read(ROOT / "admin/backend/services/TimetableService.php")
        self.assertIn("function schemaReady", svc)
        # both reads and all eight writes must check the guard
        self.assertGreaterEqual(svc.count("self::schemaReady($conn)"), 10)
        self.assertIn("function ensureSchema", svc)  # compatibility hook stays
        self.assertIn("sql/077_timetable_builder.sql", svc)

    def test_mobile_route_funnels_through_guarded_service(self):
        route = read(ROUTE)
        self.assertIn("TimetableService::teacherSchedule", route)
        svc = read(ROOT / "admin/backend/services/TimetableService.php")
        # teacherSchedule carries the guard itself (covers week + today)
        head = svc.split("function teacherSchedule", 1)[1].split("function", 1)[0]
        self.assertIn("schemaReady", head)

    def test_branch_guard_installs_pytest(self):
        wf = read(ROOT / ".github/workflows/branch-guard.yml")
        self.assertIn("pip install", wf)
        self.assertLess(wf.index("pip install"), wf.index("pytest tests/security/test_mobile_target_environment.py"))

