"""Folder-structure safety net: every file link must point to a real file.

PHP files in this codebase load each other with require/include and a
path relative to their own folder. If a folder clean-up moves or renames
a file, the pages that load it break at run time. This test catches that
in CI first.

What it checks:
1. Every require/include written in a static form (see structure_paths.py)
   resolves to a file that exists in the repository.
2. Links built from variables ("dynamic") cannot be checked statically.
   They are pinned in DYNAMIC_ALLOWLIST (file -> count). A new dynamic
   link, or a changed count, fails until a reviewer has checked it and
   updated the list.
3. A sanity floor, so a broken parser cannot "pass" by matching nothing.

Baseline at 9dd317f: 323 files, 760 static links (0 broken), 41 dynamic.
"""
import os
import sys
import unittest
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from structure_paths import ROOT, first_party_php, includes  # noqa: E402

# Reviewed 2026-10-23. Each entry: a link built at run time, usually a
# candidate list (secrets file, vendor/autoload.php, app-release file) or a
# registry of optional services. Update only after reading the new code.
DYNAMIC_ALLOWLIST = {
    # Finite browser registry selects the handler; no request-supplied file path.
    "browser_page.php": 1,
    "admin/api_export_members.php": 1,
    "admin/backend/reporting/ReportExportService.php": 1,
    # 2026-10-28: publication reuses central release loading; two redundant
    # variable includes were removed. Remaining root/core paths reviewed.
    "admin/backend/services/AppReleaseManager.php": 2,
    "admin/backend/services/FailureAlertService.php": 1,
    "admin/backend/services/GuardianMatchService.php": 1,
    "admin/backend/services/TestMemberSeed.php": 1,
    "admin/components/notification_center.php": 1,
    "admin/dashboard.php": 2,
    "admin/dashboards/sections/app_release_section.php": 1,
    "admin/dashboards/sections/app_telemetry_section.php": 1,
    "admin/export_class_report.php": 1,
    "admin/export_executive_report_excel.php": 1,
    "admin/export_filtered_students.php": 1,
    "admin/id_cards/qr_diagnostic.php": 1,
    "admin/leak_detector.php": 1,
    "admin/tools/health_check.php": 3,
    "api/v1/core/app_release.php": 1,
    "api/v1/core/database.php": 4,
    "api/v1/index.php": 1,
    "api/v1/routes/attendance.php": 2,
    "backend/api/mezmur.php": 3,
    "backend/config/loader.php": 2,
    "config.php": 4,
    "frontend/layouts/base.php": 1,
    "frontend/pages/dashboard.php": 1,
    "index.php": 1,
}


class StructureIncludePaths(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.files = first_party_php()
        cls.links = [(f, expr, kind, target)
                     for f in cls.files
                     for expr, kind, target in includes(f)]

    def test_parser_sees_the_codebase(self):
        # Guards against a silent "pass" if the pattern stops matching.
        self.assertGreater(len(self.files), 250)
        static = [l for l in self.links if l[2] in ("static", "plain")]
        self.assertGreater(len(static), 600, "include parser matched too few links")

    def test_every_static_link_points_to_an_existing_file(self):
        broken = []
        for f, expr, kind, target in self.links:
            if kind == "dynamic":
                continue
            if not os.path.isfile(target):
                broken.append(f"{f}: {expr}  ->  {os.path.relpath(target, ROOT)}")
        self.assertEqual(
            broken, [],
            "These require/include targets do not exist. A file was probably "
            "moved or renamed without updating the files that load it:\n  "
            + "\n  ".join(broken))

    def test_static_links_stay_inside_the_repository(self):
        outside = [f"{f}: {expr}" for f, expr, kind, target in self.links
                   if kind != "dynamic"
                   and not Path(target).resolve().is_relative_to(ROOT)]
        self.assertEqual(outside, [], "Static links must not leave the project folder")

    def test_dynamic_links_match_the_reviewed_allowlist(self):
        found = Counter(f for f, _e, kind, _t in self.links if kind == "dynamic")
        self.assertEqual(
            dict(found), DYNAMIC_ALLOWLIST,
            "Run-time (variable) include paths changed. Review each new or "
            "removed one, make sure it cannot break after a folder move, then "
            "update DYNAMIC_ALLOWLIST in this file.")


if __name__ == "__main__":
    unittest.main()
