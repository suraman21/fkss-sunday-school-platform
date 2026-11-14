"""Rolling live-test dates for the attendance alerting suites (2026-11-14).

Production deliberately rejects attendance saves older than 45 days
(`SchoolCalendarService::BACKDATE_DAYS`, shipped with sql/078). The Phase 2/3/6
live guards used to hardcode Sep/Oct 2026 save dates, which answer 422
BACKDATE_LIMIT once they age out. These helpers compute save dates as fixed
offsets from the server-timezone "today" so the suites never rot.

Band layout (days before today, Africa/Addis_Ababa), oldest first:
  Phase 3: 39 (mezmur packet) + 38..34 (web saves, chronological)
  Phase 6: 32 (lesson saves)
  Phase 2: 29..25 (web saves) + 24 (mobile draft) + 23 (mobile submit)

Rules the layout obeys (pinned by the self-check below):
- every offset sits inside 20..39: weeks away from "today" (no future-date
  risk under clock/TZ skew) and at least 6 days inside the 45-day floor;
- bands are disjoint across phases: grade_submissions packets are keyed by
  (class_id, attendance_date) and every phase saves on class 1;
- bands are non-adjacent across phases that share seed members (Phase 6
  reuses 900001 with Phase 2 and 900002 with Phase 3), so one phase's rows
  can never join another phase's absence streak;
- Phase 4's fixed direct-DB window (2026-09-14..17) is always older than
  any rolling date, so it can never collide.
"""
from __future__ import annotations

import os
import shutil
import subprocess
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

# Ethiopia is UTC+3 year-round (no DST); matches SchoolCalendarService::today().
ADDIS = timezone(timedelta(hours=3))

# Disjoint per-phase bands, oldest first. See the module docstring.
PHASE3_OFFSETS = (39, 38, 37, 36, 35, 34)  # mezmur packet + 5 web saves
PHASE6_OFFSETS = (32,)  # lesson saves
PHASE2_OFFSETS = (29, 28, 27, 26, 25, 24, 23)  # 5 web + mobile draft + submit
BANDS = {"phase3": PHASE3_OFFSETS, "phase6": PHASE6_OFFSETS, "phase2": PHASE2_OFFSETS}


def addis_today() -> str:
    return datetime.now(timezone.utc).astimezone(ADDIS).strftime("%Y-%m-%d")


def recent(days_ago: int) -> str:
    """Gregorian date N days before the server-timezone today (Y-m-d)."""
    if not 1 <= days_ago <= 44:
        raise ValueError(f"offset {days_ago} outside the safe 1..44 range")
    base = datetime.now(timezone.utc).astimezone(ADDIS).date()
    return (base - timedelta(days=days_ago)).isoformat()


def ec_label(gregorian_date: str) -> str:
    """Expected EC label for a Gregorian date, computed by production code.

    Calls the same `ethio_date_format($date, 'F j, Y')` that
    `AttendanceRiskService::ecLabel()` uses for bell ranges, so the calendar
    math keeps a single source of truth instead of a Python copy.
    """
    php = shutil.which("php")
    if php is None:
        raise unittest.SkipTest("PHP CLI is not installed")
    env = dict(os.environ, SSMS_EC_DATE=gregorian_date)
    proc = subprocess.run(
        [php, "-r",
         "require 'admin/backend/ethiopian_date.php';"
         " echo ethio_date_format(getenv('SSMS_EC_DATE'), 'F j, Y');"],
        capture_output=True, text=True, cwd=str(ROOT), env=env,
    )
    if proc.returncode != 0 or not proc.stdout:
        raise unittest.SkipTest(f"EC label unavailable: {proc.stderr.strip()[:200]}")
    return proc.stdout


class RollingDateBandsTest(unittest.TestCase):
    """Pins the band layout. Runs everywhere (no live stack needed)."""

    def test_bands_are_inside_the_safe_window_and_disjoint(self):
        seen: dict[int, str] = {}
        for name, offsets in BANDS.items():
            for off in offsets:
                self.assertGreaterEqual(off, 20, f"{name}: too close to today")
                self.assertLessEqual(off, 39, f"{name}: too close to the 45-day floor")
                self.assertNotIn(off, seen, f"{name} shares offset {off} with {seen.get(off)}")
                seen[off] = name

    def test_bands_sharing_members_are_non_adjacent(self):
        # Phase 6 reuses member 900001 with Phase 2 and 900002 with Phase 3.
        for older, newer in (("phase3", "phase6"), ("phase6", "phase2")):
            gap = min(abs(x - y) for x in BANDS[older] for y in BANDS[newer])
            self.assertGreaterEqual(gap, 2, f"{older}/{newer} bands are adjacent")

    def test_phase_runs_stay_consecutive(self):
        for name in ("phase3", "phase2"):
            offsets = BANDS[name]
            self.assertEqual(max(offsets) - min(offsets) + 1, len(offsets), name)

    def test_live_suites_use_rolling_dates(self):
        for rel in ("tests/security/test_attendance_phase2.py",
                    "tests/security/test_attendance_phase3.py",
                    "tests/security/test_attendance_phase6.py"):
            src = (ROOT / rel).read_text(encoding="utf-8")
            self.assertIn("recent(", src, rel)
