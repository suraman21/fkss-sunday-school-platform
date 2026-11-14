"""
Attendance alerting — Phase 4 guards (monthly roll-up + shared "Monthly
attendance" report + unification of every at-risk consumer).

Static pins (always run) — the formula lives in ONE place:
  * sql/074 is idempotent and shared-hosting safe;
  * AttendanceMonthRollupService uses AttendanceRiskService constants only
    (no 90 / 75 / 50 / 0.5 literals), resets rows instead of deleting them and
    is refreshed from AttendanceWarningService::safeEvaluateMany;
  * the API is GET-only, uses READ_ROLES + departmentsForRole, Ethiopian export;
  * the four dashboards link the report; the Mezmur page stays style-free;
  * Reporting Centre bands, Education triage and the Information "At-risk"
    pane no longer carry their own thresholds / formulas.

Live (skipped unless the local server + sql/074 are present):
  * roll-up numbers equal the hand-computed weighted rate (rejected packets,
    holidays and Pagume handled), partial refresh after a packet changes,
  * role scoping (403), POST → 405, month validation, EC-only CSV export,
  * the Information at-risk endpoint returns exactly the roll-up's below rows.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

from test_attendance_phase1 import BASE, WebSession, db, _live_ready  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def strip_sql_comments(sql: str) -> str:
    return "\n".join(l for l in sql.splitlines() if not l.strip().startswith("--"))


class Phase4StaticGuards(unittest.TestCase):
    SERVICE = "admin/backend/services/AttendanceMonthRollupService.php"
    API = "admin/api_attendance_monthly.php"
    PAGE = "admin/attendance_monthly.php"
    JS = "admin/js/attendance_monthly.js"

    def test_sql_074_is_idempotent_and_shared_hosting_safe(self):
        sql = strip_sql_comments(read("sql/074_attendance_month_rollup.sql"))
        self.assertIn("CREATE TABLE IF NOT EXISTS `attendance_month_rollup`", sql)
        self.assertIn("PRIMARY KEY (`department`, `member_id`, `ec_year`, `ec_month`)", sql)
        for col in ("sessions", "present_eq", "absent_eq", "rate", "band", "below_threshold", "computed_at"):
            self.assertIn(f"`{col}`", sql)
        for forbidden in ("TRIGGER", "EVENT", "PROCEDURE", "DROP TABLE", "DELETE FROM"):
            self.assertNotIn(forbidden, sql.upper())
        self.assertIn("ENGINE=InnoDB", sql)

    def test_rollup_service_uses_the_shared_constants_only(self):
        src = read(self.SERVICE)
        self.assertIn("AttendanceRiskService::LATE_WEIGHT", src)
        for c in ("BAND_EXCELLENT", "BAND_GOOD", "BAND_FAIR"):
            self.assertIn(f"AttendanceRiskService::{c}", src)
        body = src.split("final class AttendanceMonthRollupService", 1)[1]
        for literal in (">= 90", ">= 75", ">= 50", "0.5 *", "* 0.5", "< 60", "< 50"):
            self.assertNotIn(literal, body, f"literal {literal!r} must come from AttendanceRiskService")
        # policy threshold + min_sessions come from AttendancePolicyService
        self.assertIn("AttendancePolicyService::for($conn, $dept)", src)
        self.assertIn("low_rate_threshold", src)
        self.assertIn("min_sessions", src)
        # same eligibility as the risk service
        self.assertIn("legacyRowsEligible()", src)
        self.assertIn("('submitted','approved')", src)
        self.assertIn("a.status IN ('present','absent','late','excused')", src)
        self.assertIn("member_department_periods", src)
        self.assertIn("DATE(dp.ended_at) > ", src)
        # never delete — reset in place
        self.assertNotIn("DELETE FROM", src)
        self.assertIn("band = 'no_data'", src)
        self.assertIn("begin_transaction", src)

    def test_warning_engine_refreshes_the_rollup(self):
        src = read("admin/backend/services/AttendanceWarningService.php")
        self.assertIn("require_once __DIR__ . '/AttendanceMonthRollupService.php'", src)
        fn = src.split("public static function safeEvaluateMany", 1)[1].split("public static function", 1)[0]
        self.assertIn("AttendanceMonthRollupService::safeRefreshForDate($conn, $dept, $ids, $date)", fn)

    def test_api_is_read_only_and_server_scoped(self):
        src = read(self.API)
        self.assertIn("AttendanceWarningService::READ_ROLES", src)
        self.assertIn("AttendanceWarningService::departmentsForRole($role)", src)
        self.assertIn("$fail(405, 'This report is read-only.')", src)
        self.assertIn("$fail(403, 'You cannot view '", src)
        self.assertIn("$m < 1 || $m > 13", src)  # Pagume allowed, 14 rejected
        self.assertIn("SecurityRateLimiter", src)
        self.assertIn("SecurityAuditService::record($conn, 'attendance_monthly_export'", src)
        for action in ("months", "summary", "list", "groups", "member", "export"):
            self.assertIn(f"case '{action}':", src)
        self.assertNotIn("$_POST", src)
        self.assertNotIn("->query(", src)  # all data through the service

    def test_export_is_ethiopian_only(self):
        src = read(self.API)
        export = src.split("case 'export':", 1)[1]
        self.assertIn("ethio_export_datetime", export)
        self.assertIn("month_label_am", export)
        # no Gregorian date formatting in the export body (file name uses EC year/month, not date())
        self.assertNotIn("date('Y-m-d'", export)
        self.assertNotIn("->format('Y-m-d')", export)
        self.assertNotIn("->format('d/m/Y')", export)
        self.assertIn("spreadsheetSafe", export)

    def test_page_shell_has_no_data_access_and_js_never_formats_dates(self):
        page = read(self.PAGE)
        self.assertIn("AttendanceWarningService::READ_ROLES", page)
        self.assertIn("window.AM_CONFIG", page)
        self.assertNotIn("$conn->", page)
        self.assertNotIn("$pdo", page)
        self.assertNotIn("cdn.", page)
        js = read(self.JS)
        for forbidden in ("toLocaleDateString", "new Date(", "getFullYear", "Intl.DateTimeFormat"):
            self.assertNotIn(forbidden, js)
        self.assertIn("api_attendance_monthly.php", js)
        self.assertIn("p.action = 'export'", js)

    def test_four_dashboards_link_the_report(self):
        for rel in ("admin/dashboards/edu_dept.php", "admin/dashboards/hr-dept.php",
                    "admin/dashboards/info-dept.php", "frontend/pages/mezmur_dept.php"):
            src = read(rel)
            self.assertIn('id="am-dashboard-card"', src, rel)
            self.assertIn("/admin/attendance_monthly.php", src, rel)
        self.assertEqual(read("frontend/pages/mezmur_dept.php").count("style="), 0)
        for rel in ("admin/dashboards/edu_dept.php", "admin/dashboards/hr-dept.php", "admin/dashboards/info-dept.php"):
            self.assertIn("AttendanceMonthRollupService::dashboardCard($conn", read(rel), rel)
        self.assertIn("api_attendance_monthly.php?action=summary&department=mezmur", read("frontend/js/mezmur.js"))

    # ── unification ───────────────────────────────────────────────

    def test_reporting_centre_bands_use_the_shared_formula(self):
        src = read("admin/backend/reporting/ReportRegistry.php")
        self.assertIn("require_once dirname(__DIR__) . '/services/AttendanceRiskService.php'", src)
        self.assertIn("AttendanceRiskService::LATE_WEIGHT", src)
        self.assertIn("SQL_ATTENDED_WEIGHTED_EDU", src)
        self.assertIn("SQL_ATTENDED_WEIGHTED_MEZMUR", src)
        self.assertNotIn("SUM(a.status IN ('present','late')) AS attended", src)
        self.assertNotIn("SUM(x.status IN ('present','late')) AS attended", src)
        for literal in (">= 90 THEN 'excellent'", ">= 75 THEN 'good'", ">= 50 THEN 'fair'", "ea.sessions < 50)"):
            self.assertNotIn(literal, src, literal)
        self.assertIn("self::BAND_EXCELLENT", src)
        self.assertIn("self::BAND_FAIR", src)

    def test_education_triage_uses_policy_threshold(self):
        rc = read("admin/backend/services/ReportCardService.php")
        self.assertIn("public static function attendanceRiskThreshold(\\mysqli $conn): float", rc)
        self.assertIn("AttendancePolicyService::for($conn, 'edu')", rc)
        self.assertIn("FeatureGate::isEnabled('attendance_v2')", rc)
        perf = rc.split("public static function filterStudentsPerformance", 1)[1]
        # histogram bins (<60 → '50-59') are not thresholds; the at-risk comparisons must use $attRisk
        self.assertNotIn("$stAtt < 60.0", perf)
        self.assertNotIn("$stAtt >= 60.0", perf)
        self.assertIn("$stAtt < $attRisk", perf)
        self.assertIn("'attendance_threshold' => $attRisk", perf)
        ea = read("admin/backend/services/EducationAnalyticsService.php")
        self.assertIn("public static function buildTriageRoster(array $students, ?float $attThreshold = null)", ea)
        self.assertNotIn("Attendance < 60%", ea)
        self.assertNotIn("$att < self::AT_RISK_ATT", ea)
        self.assertIn("ReportCardService::LEGACY_AT_RISK_ATT", ea)
        js = read("admin/js/advanced_analytics.js")
        self.assertIn("attendance_threshold", js)
        self.assertNotIn("(<60% Att)", js)
        edu = read("admin/dashboards/edu_dept.php")
        self.assertIn("ReportCardService::attendanceRiskThreshold($conn)", edu)
        self.assertNotIn("&lt;60% attendance", edu)
        self.assertNotIn("Attendance Alert (&lt;60%)", edu)

    def test_information_at_risk_pane_reads_the_rollup(self):
        api = read("admin/api_attendance_info.php")
        block = api.split("case 'at_risk_members':", 1)[1].split("case 'daily_report':", 1)[0]
        self.assertIn("AttendanceMonthRollupService::listMonth($conn, $riskDept, $riskYear, $riskMonth, ['below' => true", block)
        self.assertIn("AttendanceMonthRollupService::ensureFresh", block)
        for forbidden in ("threshold'] ?? 50", "HAVING rate", "strtotime(\"-$days days\")", "members.status IN", "m.status IN ('active','warning')"):
            self.assertNotIn(forbidden, block, forbidden)
        self.assertNotIn("$conn->query(", block)
        page = read("admin/dashboards/info-dept.php")
        self.assertNotIn("attRiskDays", page)
        self.assertNotIn("attRiskThresh", page)
        self.assertIn('id="attRiskDept"', page)
        self.assertIn('id="attRiskMonth"', page)
        self.assertIn("function loadAtRiskMonths()", page)
        self.assertIn("api_attendance_monthly.php?action=months", page)

    def test_no_consumer_keeps_a_private_at_risk_threshold(self):
        """Attendance at-risk literals may exist ONLY in AttendanceRiskService / policy defaults / legacy fallback."""
        offenders = []
        pat = re.compile(r"(attendance_rate|\$att|\$stAtt|rate)\s*<\s*(50|60|70|75)(\.0)?\b")
        for rel in ("admin/backend/services/EducationAnalyticsService.php",
                    "admin/backend/services/ReportCardService.php",
                    "admin/api_attendance_info.php",
                    "admin/backend/services/AttendanceMonthRollupService.php",
                    "admin/api_attendance_monthly.php"):
            for i, line in enumerate(read(rel).splitlines(), 1):
                if pat.search(line) and "LEGACY_AT_RISK_ATT" not in line:
                    offenders.append(f"{rel}:{i}: {line.strip()[:100]}")
        self.assertEqual(offenders, [], "private at-risk thresholds:\n" + "\n".join(offenders))

    def test_php_syntax(self):
        for rel in (self.SERVICE, self.API, self.PAGE, "admin/api_attendance_info.php",
                    "admin/backend/reporting/ReportRegistry.php", "admin/backend/services/ReportCardService.php",
                    "admin/backend/services/EducationAnalyticsService.php", "admin/dashboards/edu_dept.php",
                    "admin/dashboards/hr-dept.php", "admin/dashboards/info-dept.php", "frontend/pages/mezmur_dept.php"):
            try:
                r = subprocess.run(["php", "-l", str(ROOT / rel)], capture_output=True, text=True)
            except FileNotFoundError:
                self.skipTest("php CLI not available")
            self.assertEqual(r.returncode, 0, f"{rel}: {r.stdout}{r.stderr}")


# ─────────────────────────────────────────────────────────────────
# live
# ─────────────────────────────────────────────────────────────────

def _phase4_ready():
    if not _live_ready():
        return False
    try:
        return db("SHOW TABLES LIKE 'attendance_month_rollup'") != ""
    except Exception:
        return False


@unittest.skipUnless(_phase4_ready(), "local test server / sql 074 not available")
class Phase4LiveGuards(unittest.TestCase):
    """Fixture: Meskerem 2019 EC (2026-09-11 .. 2026-10-10) — fixed direct-DB
    dates, always older than the rolling Phase 2/3/6 bands (attendance_test_dates)."""
    CLASS_ID = 1
    EDU_A = 900000      # class 1 (seed)
    EDU_B = 900001      # class 1 (seed)
    MEZ = 900005        # ህጻናት (seed age_group 7_13)
    EC_YEAR, EC_MONTH = 2019, 1
    DATES = ["2026-09-14", "2026-09-15", "2026-09-16", "2026-09-17"]
    _sessions: dict = {}

    @classmethod
    def _clean(cls):
        dates = "','".join(cls.DATES)
        db(f"DELETE FROM attendance WHERE class_id={cls.CLASS_ID} AND attendance_date IN ('{dates}')")
        db(f"DELETE FROM grade_submissions WHERE submission_type='attendance' AND class_id={cls.CLASS_ID} AND attendance_date IN ('{dates}')")
        db(f"DELETE FROM mezmur_attendance WHERE member_id={cls.MEZ} AND attendance_date IN ('{dates}')")
        db(f"DELETE FROM mezmur_submissions WHERE attendance_date IN ('{dates}')")
        db(f"DELETE FROM attendance_month_rollup WHERE ec_year={cls.EC_YEAR} AND ec_month={cls.EC_MONTH} AND member_id IN ({cls.EDU_A},{cls.EDU_B},{cls.MEZ})")

    @classmethod
    def setUpClass(cls):
        cls._clean()
        d = cls.DATES
        rows = [
            (cls.EDU_A, d[0], "present"), (cls.EDU_A, d[1], "late"), (cls.EDU_A, d[2], "absent"), (cls.EDU_A, d[3], "present"),
            (cls.EDU_B, d[0], "absent"), (cls.EDU_B, d[1], "excused"), (cls.EDU_B, d[2], "holiday"), (cls.EDU_B, d[3], "absent"),
        ]
        values = ",".join(f"({m},{cls.CLASS_ID},1,'{dt}','{st}')" for m, dt, st in rows)
        db(f"INSERT INTO attendance (member_id,class_id,academic_year_id,attendance_date,status) VALUES {values}")
        # packets: day0 submitted, day1 approved, day2 (none → legacy eligible), day3 REJECTED (excluded)
        db(f"INSERT INTO grade_submissions (teacher_id,class_id,academic_year_id,attendance_date,submission_type,status) VALUES "
           f"(3,{cls.CLASS_ID},1,'{d[0]}','attendance','submitted'),(3,{cls.CLASS_ID},1,'{d[1]}','attendance','approved'),(3,{cls.CLASS_ID},1,'{d[3]}','attendance','rejected')")
        db(f"INSERT INTO mezmur_attendance (member_id,attendance_date,status) VALUES ({cls.MEZ},'{d[0]}','absent'),({cls.MEZ},'{d[1]}','late'),({cls.MEZ},'{d[2]}','absent')")

    @classmethod
    def tearDownClass(cls):
        cls._clean()

    def s(self, role):
        if role not in self._sessions:
            self._sessions[role] = WebSession(role)
        return self._sessions[role]

    def _list(self, role, dept, **extra):
        q = "&".join(f"{k}={v}" for k, v in extra.items())
        return self.s(role).get_json(f"/admin/api_attendance_monthly.php?action=list&department={dept}&ec_year={self.EC_YEAR}&ec_month={self.EC_MONTH}&refresh=1&limit=500&{q}")

    def setUp(self):
        status, data = self.s("hr_dept").get_json("/admin/api_attendance_monthly.php?action=months&department=edu")
        if not (status == 200 and data.get("available")):
            self.skipTest("FEATURE_ATTENDANCE_V2 is off in this environment")

    def test_01_rollup_matches_the_weighted_formula(self):
        status, data = self._list("hr_dept", "edu")
        self.assertEqual(status, 200, data)
        rows = {r["member_id"]: r for r in data["items"]}
        a, b = rows[self.EDU_A], rows[self.EDU_B]
        # A: present, late, absent (legacy, eligible), present(rejected → out) → 3 sessions, 1 + 0.5 = 1.5 → 50 %
        self.assertEqual((a["sessions"], a["present"], a["late"], a["absent"], a["excused"]), (3, 1, 1, 1, 0))
        self.assertAlmostEqual(a["present_eq"], 1.5)
        self.assertAlmostEqual(a["rate"], 50.0)
        self.assertEqual(a["band"], "fair")
        # B: absent, excused, holiday(out), absent(rejected → out) → 2 sessions, 1 → 50 % ; below needs ≥ min_sessions (3) → False
        self.assertEqual((b["sessions"], b["present"], b["excused"], b["absent"]), (2, 0, 1, 1))
        self.assertAlmostEqual(b["rate"], 50.0)
        self.assertFalse(b["below_threshold"])
        self.assertTrue(a["below_threshold"], "3 sessions at 50 % is below the 75 % policy")
        self.assertEqual(data["summary"]["month_label_am"], "መስከረም 2019")
        self.assertEqual(data["summary"]["threshold"], 75.0)
        self.assertEqual(data["summary"]["min_sessions"], 3)

    def test_02_mezmur_rollup_and_rejected_packet(self):
        status, data = self._list("mezmur_dept", "mezmur")
        self.assertEqual(status, 200, data)
        row = {r["member_id"]: r for r in data["items"]}[self.MEZ]
        self.assertEqual((row["sessions"], row["absent"], row["late"]), (3, 2, 1))
        self.assertAlmostEqual(row["rate"], 16.67)
        self.assertEqual(row["band"], "low")
        self.assertEqual(row["group"], "ህጻናት")
        # reject day1 sheet for the canonical section → late row disappears
        db(f"INSERT INTO mezmur_submissions (attendance_date, section, taker_id, status, member_count, present_count, late_count, absent_count, excused_count) VALUES ('{self.DATES[1]}', 'ህጻናት', 8, 'rejected', 0, 0, 0, 0, 0)")
        status, data = self._list("mezmur_dept", "mezmur")
        row = {r["member_id"]: r for r in data["items"]}[self.MEZ]
        self.assertEqual((row["sessions"], row["absent"], row["late"]), (2, 2, 0))
        self.assertAlmostEqual(row["rate"], 0.0)

    def test_03_partial_refresh_resets_members_without_sessions(self):
        db(f"UPDATE grade_submissions SET status='rejected' WHERE class_id={self.CLASS_ID} AND submission_type='attendance' AND attendance_date IN ('{self.DATES[0]}','{self.DATES[1]}')")
        db(f"DELETE FROM attendance WHERE member_id={self.EDU_B} AND class_id={self.CLASS_ID} AND attendance_date='{self.DATES[2]}'")
        # B has no eligible rows left: day0/day1 rejected, day2 deleted, day3 rejected → reset to no_data, row kept
        status, data = self._list("hr_dept", "edu")
        rows = {r["member_id"]: r for r in data["items"]}
        self.assertIn(self.EDU_B, rows, "rows are reset, never deleted")
        self.assertEqual(rows[self.EDU_B]["band"], "no_data")
        self.assertEqual(rows[self.EDU_B]["sessions"], 0)
        self.assertIsNone(rows[self.EDU_B]["rate"])
        # A keeps only the legacy day2 row → 1 session absent → 0 %
        self.assertEqual((rows[self.EDU_A]["sessions"], rows[self.EDU_A]["rate"]), (1, 0.0))
        # restore for later tests
        db(f"UPDATE grade_submissions SET status='submitted' WHERE class_id={self.CLASS_ID} AND submission_type='attendance' AND attendance_date='{self.DATES[0]}'")
        db(f"UPDATE grade_submissions SET status='approved' WHERE class_id={self.CLASS_ID} AND submission_type='attendance' AND attendance_date='{self.DATES[1]}'")
        # (direct SQL edits bypass the save/review hooks — force the recompute the hooks would have done)
        status, data = self._list("hr_dept", "edu")
        self.assertEqual(status, 200, data)

    def test_04_role_scoping_and_read_only(self):
        status, data = self.s("edu_dept").get_json("/admin/api_attendance_monthly.php?action=list&department=mezmur")
        self.assertEqual(status, 403, data)
        status, data = self.s("mezmur_dept").get_json("/admin/api_attendance_monthly.php?action=list&department=edu")
        self.assertEqual(status, 403, data)
        for role in ("hr_dept", "info_dept", "school_admin", "super_admin"):
            status, data = self.s(role).get_json("/admin/api_attendance_monthly.php?action=months&department=mezmur")
            self.assertEqual(status, 200, (role, data))
            self.assertEqual(data["departments"], ["edu", "mezmur"])
        status, data = self.s("teacher").get_json("/admin/api_attendance_monthly.php?action=months")
        self.assertIn(status, (401, 403), data)
        sess = self.s("hr_dept")
        status, raw = sess._open(BASE + "/admin/api_attendance_monthly.php?action=list", data=b"x=1", headers={"X-CSRF-Token": sess.csrf})
        self.assertEqual(status, 405, raw[:200])

    def test_05_month_validation_pagume_ok_14_rejected(self):
        status, data = self.s("hr_dept").get_json("/admin/api_attendance_monthly.php?action=list&department=edu&ec_year=2019&ec_month=13")
        self.assertEqual(status, 200, data)
        self.assertEqual(data["summary"]["month_label_am"], "ጳጉሜ 2019")
        status, data = self.s("hr_dept").get_json("/admin/api_attendance_monthly.php?action=list&department=edu&ec_year=2019&ec_month=14")
        self.assertEqual(status, 400, data)
        status, data = self.s("hr_dept").get_json("/admin/api_attendance_monthly.php?action=list&department=edu&ec_year=2019&ec_month=0")
        self.assertEqual(status, 400, data)

    def test_06_csv_export_is_ethiopian_only(self):
        sess = self.s("info_dept")
        status, raw = sess._open(BASE + f"/admin/api_attendance_monthly.php?action=export&department=edu&ec_year={self.EC_YEAR}&ec_month={self.EC_MONTH}&format=csv")
        self.assertEqual(status, 200)
        text = raw.decode("utf-8-sig")
        self.assertIn("መስከረም 2019", text)
        self.assertIn("Meskerem 2019", text)
        self.assertIsNone(re.search(r"\b20\d\d-\d\d-\d\d\b", text), "Gregorian ISO date leaked into the export")
        self.assertIsNone(re.search(r"\b(January|February|March|April|May|June|July|August|September|October|November|December)\b", text))
        header = [l for l in text.splitlines() if l.startswith("#,")][0]
        self.assertIn("Rate %", header)
        self.assertIn("Below 75%", header)

    def test_07_information_at_risk_pane_equals_rollup_below_rows(self):
        status, risk = self.s("info_dept").get_json(f"/admin/api_attendance_info.php?action=at_risk_members&department=edu&ec_year={self.EC_YEAR}&ec_month={self.EC_MONTH}")
        self.assertEqual(status, 200, risk)
        self.assertTrue(risk["available"])
        self.assertEqual(risk["threshold"], 75.0)
        self.assertEqual(risk["month_label_am"], "መስከረም 2019")
        status, data = self._list("info_dept", "edu", below=1)
        expected = sorted(r["member_id"] for r in data["items"])
        self.assertEqual(sorted(m["id"] for m in risk["members"]), expected)
        self.assertIn(self.EDU_A, expected)
        self.assertNotIn(self.EDU_B, expected)
        self.assertTrue(risk["report_url"].startswith("/admin/attendance_monthly.php?department=edu"))

    def test_08_dashboard_cards_render(self):
        for role, path, needle in (("edu_dept", "/admin/dashboards/edu_dept.php", 'id="am-dashboard-card"'),
                                   ("hr_dept", "/admin/dashboards/hr-dept.php", 'id="am-dashboard-card"'),
                                   ("info_dept", "/admin/dashboards/info-dept.php", 'id="am-dashboard-card"')):
            status, raw = self.s(role)._open(BASE + path)
            text = raw.decode("utf-8", "replace")
            self.assertEqual(status, 200, (role, path))
            self.assertIn(needle, text, (role, path))
            self.assertNotIn("Fatal error", text)
        status, raw = self.s("hr_dept")._open(BASE + "/admin/attendance_monthly.php?department=mezmur")
        self.assertEqual(status, 200)
        self.assertIn("window.AM_CONFIG", raw.decode("utf-8", "replace"))


if __name__ == "__main__":
    unittest.main()
