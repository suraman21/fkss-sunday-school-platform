"""Attendance rework Phase C — default-ABSENT mobile UX (2026-10-07).

Product decision: every student/member loads as ABSENT; the teacher
(or a QR scan) marks presence. Save and Submit are always available
(the sheet is complete by construction); the completeness gate is
gone; the server still enforces the complete roster on submit.

Locked contracts (attendance_screen.dart + mezmur_attendance.dart):
  • Status precedence: local pending edit > server-saved > 'absent'
    (_firstStatus + _orAbsent) — presence is NEVER inferred.
  • _requireCompleteSheet and the "N unmarked" counter are gone; the
    counter reads "N present · M absent" (absent includes the
    default); "All Present" stays and "All Absent" is the reset.
  • Autosave keeps instant-draft behavior and now always persists a
    complete sheet (all roster rows carry explicit statuses).
  • QR scan = present. Duplicate feedback only for an existing
    present/late mark; absent/excused yields to the physical scan.
  • local_db still demands an explicit status per record.
  • HR attendance UI is removed (screen, tab, entry points); the
    outbox drain stays for old queued packets (pinned in
    test_hr_attendance_domain.py).

No Dart toolchain exists in this environment; these are structural
contract pins in the repo's established style (see the phase A/B
rework tests).
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
APP = ROOT / "Mobile" / "wbws_flutter_app" / "lib"


class DefaultAbsentSheetContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.edu = (APP / "screens/attendance/attendance_screen.dart").read_text(encoding="utf-8")
        cls.mez = (APP / "screens/mezmur/mezmur_attendance.dart").read_text(encoding="utf-8")

    def test_default_absent_fallback_exists_in_both_sheets(self):
        for src in (self.edu, self.mez):
            self.assertIn(
                "String _orAbsent(String status) => status.isEmpty ? 'absent' : status;",
                src)
            # Precedence chain stays: pending edit > server-saved > absent.
            self.assertIn("_orAbsent(_firstStatus([pendingMap[", src)

    def test_locked_sheets_display_conservative_absent_too(self):
        # A locked/submitted sheet with a missing row (roster drift)
        # displays absent — it never invents a presence.
        self.assertIn("_orAbsent(_statusOf(s['att_status'])", self.edu)
        self.assertIn("_orAbsent(_statusOf(row['mark'] ?? row['status'])", self.mez)

    def test_completeness_gate_is_gone(self):
        for src in (self.edu, self.mez):
            self.assertNotIn("_requireCompleteSheet", src)
            self.assertNotIn(" unmarked'", src)
            self.assertNotIn("remaining).'", src)

    def test_counter_reads_present_absent(self):
        self.assertIn("'${_students.length} students · '", self.edu)
        self.assertIn("'${_members.length} members · '", self.mez)
        for src in (self.edu, self.mez):
            self.assertIn("_countStatus('present')", src)
            self.assertIn("_countStatus('absent')", src)
            self.assertIn("present · '", src)
            self.assertIn(" absent'", src)

    def test_quick_actions_stay(self):
        # "All Present" is the fast full-attendance path; "All Absent"
        # is the reset back to the default state.
        for src in (self.edu, self.mez):
            self.assertIn("'All Present'", src)
            self.assertIn("'All Absent'", src)
            self.assertIn("_markAll('present')", src)
            self.assertIn("_markAll('absent')", src)

    def test_progress_strip_counts_presence(self):
        for src in (self.edu, self.mez):
            self.assertIn("/ $total present'", src)
            self.assertNotIn("/ $total marked'", src)

    def test_autosave_persists_complete_sheet(self):
        # Default-absent: every row carries an explicit status, so the
        # draft IS the whole roster — the partial-row filter is gone
        # (server drafts merge-upsert; submits still replace).
        for src, save in ((self.edu, "saveAttendanceLocal"),
                          (self.mez, "saveMezmurLocal")):
            self.assertNotIn("'${r['status'] ?? ''}'.isNotEmpty", src)
            # Attendance now returns durable-save success to its transition
            # guard; Mezmur still returns void. Both persist the full roster.
            signature = re.search(r"Future<(?:void|bool)> _autoSaveNow\(\)", src)
            self.assertIsNotNone(signature)
            body = src[signature.start():]
            self.assertIn("final records = _records();", body)
            self.assertIn(save, body)
            self.assertIn("packetKind: 'draft'", body)

    def test_records_map_the_full_roster(self):
        # The server keeps the complete-sheet requirement on submit;
        # the client satisfies it by construction.
        self.assertIn("return _students\n        .map((s) =>", self.edu)
        self.assertIn("return _members\n        .map((m) =>", self.mez)

    def test_qr_scan_marks_present_and_duplicates_only_when_attended(self):
        # Only an existing present/late mark is a duplicate; an
        # absent (default) or excused mark yields to the scan.
        for src in (self.edu, self.mez):
            self.assertIn("existing == 'present' || existing == 'late'", src)
            self.assertIn("return QrFeedback.duplicate(", src)
            self.assertIn("The scan is physical presence — it wins.", src)
        self.assertIn("setState(() => s['status'] = 'present');", self.edu)
        self.assertIn("setState(() => m['status'] = 'present');", self.mez)

    def test_presence_is_never_inferred_anywhere(self):
        for src in (self.edu, self.mez):
            self.assertNotIn("?? 'present'", src)
            self.assertNotIn("|| 'present'", src)


class LocalStoreContracts(unittest.TestCase):
    def test_local_save_still_demands_explicit_status_per_record(self):
        db = (APP / "services/local_db.dart").read_text(encoding="utf-8")
        self.assertIn("Attendance must explicitly mark every student.", db)
        self.assertIn("Attendance must explicitly mark every member.", db)
        self.assertNotIn("?? 'present'", db)
        self.assertNotIn("DEFAULT 'present'", db)


class HrRemovalCrossCheck(unittest.TestCase):
    def test_shell_has_no_hr_attendance_surface(self):
        shell = (APP / "screens/shell/app_shell.dart").read_text(encoding="utf-8")
        self.assertNotIn("hr_attendance", shell)
        self.assertNotIn("HrAttendanceScreen", shell)


if __name__ == "__main__":
    unittest.main()
