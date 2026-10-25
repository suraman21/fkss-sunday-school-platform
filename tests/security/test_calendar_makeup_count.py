"""
School Calendar school view: day text when makeup lessons exist.

A school day shows how many active classes meet on that weekday (saved
timetable working days). Makeup lessons are stored one row per lesson in
timetable_makeup_classes, so the makeup figure must count each class once,
and a day with makeup lessons must still show the regular class count.

Behaviour tests run the real SchoolCalendarService helpers with php-cli (no
database); a static pin checks that schoolDaysForRange() uses them.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICE = ROOT / "admin/backend/services/SchoolCalendarService.php"
ETH = ROOT / "admin/backend/ethiopian_date.php"


def php(expr: str):
    code = (
        f"require '{ETH}'; require '{SERVICE}';"
        f" echo json_encode({expr}, JSON_UNESCAPED_UNICODE);"
    )
    proc = subprocess.run(["php", "-r", code], capture_output=True, text=True, timeout=30)
    if proc.returncode != 0:
        raise AssertionError("php failed: " + (proc.stderr or proc.stdout)[-1500:])
    return json.loads(proc.stdout)


def entry(classes: int, makeups: int) -> dict:
    return php(f"\\App\\Services\\SchoolCalendarService::schoolDayEntry({classes}, {makeups})")


@unittest.skipUnless(shutil.which("php"), "php-cli is required")
class SchoolDayEntryTests(unittest.TestCase):
    def test_makeup_day_keeps_the_regular_count(self):
        e = entry(3, 1)
        self.assertEqual(e["type"], "school")
        self.assertEqual(e["kind"], "makeup")
        self.assertEqual(e["detail"], "3 classes meet · 1 makeup class")
        self.assertEqual(e["classes"], 3)
        self.assertEqual(e["makeup_classes"], 1)

    def test_makeup_only_day(self):
        self.assertEqual(entry(0, 1)["detail"], "1 makeup class")
        self.assertEqual(entry(0, 2)["detail"], "2 makeup classes")
        self.assertEqual(entry(0, 2)["kind"], "makeup")

    def test_regular_day_wording(self):
        e = entry(11, 0)
        self.assertEqual((e["type"], e["kind"], e["detail"]), ("school", "regular", "11 classes meet"))
        self.assertEqual(entry(1, 0)["detail"], "1 class meets")
        self.assertEqual(entry(1, 1)["detail"], "1 class meets · 1 makeup class")

    def test_neutral_day_unchanged(self):
        e = entry(0, 0)
        self.assertEqual((e["type"], e["kind"], e["label"], e["detail"]),
                         ("neutral", "none", "Neutral", "No class meets on this day"))


@unittest.skipUnless(shutil.which("php"), "php-cli is required")
class MakeupClassCountTests(unittest.TestCase):
    def test_each_class_counted_once_per_date(self):
        got = php(
            "\\App\\Services\\SchoolCalendarService::makeupClassCounts("
            "['1:2026-10-28' => true, '2:2026-10-28' => true, '1:2026-11-01' => true])"
        )
        self.assertEqual(got, {"2026-10-28": 2, "2026-11-01": 1})

    def test_empty(self):
        self.assertEqual(php("\\App\\Services\\SchoolCalendarService::makeupClassCounts([])"), [])


class SchoolDaysForRangeWiringTests(unittest.TestCase):
    def test_range_uses_the_helpers(self):
        src = SERVICE.read_text(encoding="utf-8")
        start = src.index("public static function schoolDaysForRange(")
        body = src[start:src.index("public static function weekdayClassCounts(")]
        self.assertTrue("self::makeupClassCounts(self::$range['mkClass'] ?? [])" in body,
                        "makeup figure must count distinct classes")
        self.assertTrue("self::schoolDayEntry($classes, $makeupCounts[$ymd] ?? 0)" in body,
                        "day text must come from schoolDayEntry()")


if __name__ == "__main__":
    unittest.main()
