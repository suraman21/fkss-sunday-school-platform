"""Phase 8 — QR-scan attendance hardening contracts.

Pins the governed QR-roster endpoint, the QR carrier parity with the
ID card, and the mobile scan surface (all three departments, Amharic
feedback, instant autosave). See ANALYSIS/16.
"""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
APP = ROOT / "Mobile" / "wbws_flutter_app"


def rd(p):
    return (ROOT / p).read_text(encoding="utf-8", errors="replace")


class QrRosterEndpointTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ep = rd("admin/api_qr_roster.php")
        cls.acl = rd("admin/access_control.php")

    def test_role_map_covers_departments(self):
        self.assertIn("'api_qr_roster.php' =>", self.acl)
        for role in ("edu_dept", "mezmur_dept", "hr_dept"):
            self.assertIn(role, self.acl.split("'api_qr_roster.php'")[1][:200])

    def test_governance_layer(self):
        self.assertIn("SecurityRateLimiter", self.ep)
        self.assertIn("consume('qr_roster_build'", self.ep)
        self.assertIn("SecurityAuditService::record", self.ep)
        self.assertIn("qr_roster_fail('Please sign in again.', 401)", self.ep)
        self.assertIn("permission to print this roster", self.ep)
        self.assertIn("bind_param", self.ep)

    def test_page_budget_and_payload(self):
        self.assertIn("QR_ROSTER_PAGE_SIZE = 200", self.ep)
        # same identifier payload as the ID card (never a credential)
        self.assertIn("'/member.php?code='", self.ep)
        idc = rd("admin/id_cards/generate_id_card.php")
        self.assertIn("'/member.php?code='", idc)

    def test_qr_engine_vendored(self):
        self.assertTrue(
            (ROOT / "admin/backend/pdf/tcpdf/tcpdf_barcodes_2d.php").is_file())
        self.assertIn("write2DBarcode", self.ep)


class MobileScanSurfaceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.edu = rd("Mobile/wbws_flutter_app/lib/screens/attendance/attendance_screen.dart")
        cls.mez = rd("Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_attendance.dart")
        cls.svc = rd("Mobile/wbws_flutter_app/lib/services/qr_attendance.dart")
        cls.wid = rd("Mobile/wbws_flutter_app/lib/widgets/qr_scan_sheet.dart")

    def test_platform_permissions(self):
        self.assertIn("mobile_scanner", rd("Mobile/wbws_flutter_app/pubspec.yaml"))
        self.assertIn(
            "android.permission.CAMERA",
            rd("Mobile/wbws_flutter_app/android/app/src/main/AndroidManifest.xml"),
        )
        self.assertIn(
            "NSCameraUsageDescription",
            rd("Mobile/wbws_flutter_app/ios/Runner/Info.plist"),
        )

    def test_both_live_screens_have_scan_and_autosave(self):
        # HR attendance was retired (2026-10-07) — its screen is gone;
        # Education + Mezmur remain the two scan surfaces.
        for src, save in (
            (self.edu, "saveAttendanceLocal"),
            (self.mez, "saveMezmurLocal"),
        ):
            self.assertIn("QrScanSheet.open", src)
            # Attendance exposes the actual callback for runtime regression
            # tests; Mezmur retains its private name. Verify wiring, not spelling.
            self.assertRegex(src, r"onScan:\s+_?handleQrScan")
            self.assertIn("_autoSaveNow", src)
            self.assertIn("_scheduleAutoSave", src)
            self.assertIn("packetKind: 'draft'", src)
            self.assertIn(save, src)
            # Scan lives in the top header — never a FAB covering data.
            self.assertIn("onPressed: _openQrScan", src)
            self.assertIn("Icons.qr_code_scanner", src)
            self.assertNotIn("FloatingActionButton", src)
            # Interactivity: animated chips + progress strip.
            self.assertIn("AnimatedScale", src)
            self.assertIn("_progressStrip", src)

    def test_payload_parser_formats(self):
        self.assertIn("member.php", self.svc)
        self.assertIn("FKSS1:", self.svc)

    def test_amharic_feedback_is_big_and_complete(self):
        # titles ≥ 30sp / subs ≥ 17sp on the overlay
        self.assertIn("fontSize: 30", self.wid)
        self.assertIn("fontSize: 17", self.wid)
        for token in (
            "\u1270\u1218\u12dd\u130d\u1267\u120d",  # registered
            "\u1240\u12f5\u121e",                       # already (duplicate)
            "\u12e8\u1270\u1233\u1233\u1270 \u12ad\u134d\u120d",  # wrong class
            "\u12a0\u120d\u1270\u1308\u1298\u121d",  # not found
            "\u1295\u1241 \u12a0\u12ed\u12f0\u1208\u121d",  # not active
            "\u120d\u12ad \u12eb\u120d\u1206\u1290 \u12ae\u12f5",  # invalid
            "\u1270\u1246\u120d\u1313\u12a0\u120d",  # locked
        ):
            self.assertIn(token, self.svc, token)

    def test_scanner_is_one_hand_friendly(self):
        # 64dp thumb-zone circles at the bottom, animated viewfinder,
        # Amharic control labels (torch / close) \u2014 all \u2265 48dp rule.
        self.assertIn("width: 64", self.wid)
        self.assertIn("spaceAround", self.wid)
        self.assertIn("_CornerPainter", self.wid)
        self.assertIn("\u1265\u122d\u1203\u1295", self.wid)  # torch label
        self.assertIn("\u12dd\u130b", self.wid)  # close label

    def test_section_picker_reads_server_items_and_labels_unassigned(self):
        # Server emits 'items'; takers must read it (with legacy fallback)
        # and render the em-dash placeholder as a friendly label.
        # (HR's section picker was retired with HR attendance, 2026-10-07.)
        for src in (self.mez,):
            self.assertIn("res.data!['items'] ?? res.data!['sections']", src)
            self.assertIn("_sectionLabel", src)
            self.assertIn("\u12eb\u120d\u1270\u1218\u12f0\u1261", src)

    def test_login_uses_school_logo_asset(self):
        logo = APP / "assets" / "logo_school.png"
        self.assertTrue(logo.is_file() and logo.stat().st_size > 10000)
        login = rd("Mobile/wbws_flutter_app/lib/screens/auth/login_screen.dart")
        self.assertIn("assets/logo_school.png", login)
        self.assertNotIn("Icons.church_rounded", login)

    def test_stale_backend_banner_removed(self):
        self.assertNotIn("older version of the mezmur", self.mez)
        self.assertNotIn("_staleServer", self.mez)

    def test_offline_resolution_helpers(self):
        db = rd("Mobile/wbws_flutter_app/lib/services/local_db.dart")
        self.assertIn("findCachedMemberByCode", db)
        self.assertIn("cachedClassNameOfMember", db)


class ConsolePrintButtonTests(unittest.TestCase):
    def test_each_console_offers_qr_roster_print(self):
        edu = rd("admin/dashboards/edu_dept.php")
        hr = rd("admin/dashboards/hr-dept.php")
        mez = rd("frontend/pages/mezmur_dept.php")
        mezjs = rd("frontend/js/mezmur.js")
        self.assertIn("printQrRoster", edu)
        self.assertIn("api_qr_roster.php?dept=edu", edu)
        # HR's QR-roster button was retired with HR attendance
        # (2026-10-07) — no HR takers scan anymore. The governed
        # endpoint keeps serving Education and Mezmur.
        self.assertNotIn("printHrQrRoster", hr)
        self.assertNotIn("api_qr_roster.php?dept=hr", hr)
        self.assertIn("Mezmur.printQrRoster", mez)
        self.assertIn("api_qr_roster.php?dept=mezmur", mezjs)


if __name__ == "__main__":
    unittest.main()
