"""A displayed workflow lock is not a receipt for this phone's operation.

This source guard covers every production Dart caller, not SQLite settlement
semantics. Widget tests exercise the attendance load with a destructive fake.
Exact upload settlement and explicit recovery/discard are separate contracts.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
LIB = ROOT / "Mobile/wbws_flutter_app/lib"


def cleanup_call_lines(source):
    call = re.compile(r"\bdropPending(?:Attendance|Grades|Mezmur|Hr)\s*\(")
    declaration = re.compile(
        r"^\s*Future<void>\s+dropPending(?:Attendance|Grades|Mezmur|Hr)\s*\("
    )
    return [
        number
        for number, line in enumerate(source.splitlines(), 1)
        if call.search(line) and not declaration.search(line)
    ]


class LockPreservesPendingOperations(unittest.TestCase):
    def test_no_production_caller_uses_unconfirmed_lock_cleanup(self):
        callers = []
        for path in sorted(LIB.rglob("*.dart")):
            for number in cleanup_call_lines(path.read_text(encoding="utf-8")):
                callers.append(f"{path.relative_to(ROOT)}:{number}")
        self.assertEqual(
            callers, [],
            "Workflow locks cannot delete pending operations; settle only an "
            "exact operation receipt. Unsafe callers: " + ", ".join(callers),
        )

    def test_guard_catches_qualified_and_internal_calls(self):
        source = "\n".join([
            "  Future<void> dropPendingAttendance(int id, String day) async {",
            "    await dropPendingGrades(7);",
            "    await db.dropPendingMezmur(day, section);",
            "    await db.dropPendingHr(day, section);",
            "  }",
        ])
        self.assertEqual(cleanup_call_lines(source), [2, 3, 4])

    def test_lock_gates_and_exact_settlement_remain_present(self):
        for name in (
            "screens/attendance/attendance_screen.dart",
            "screens/teacher/teacher_grades.dart",
            "screens/mezmur/mezmur_attendance.dart",
        ):
            with self.subTest(screen=name):
                source = (LIB / name).read_text(encoding="utf-8")
                self.assertIn("PacketLock.isLocked(", source)
        source = (LIB / "services/local_db.dart").read_text(encoding="utf-8")
        self.assertIn("settleLegacyOperation", source)
        self.assertIn("_legacyExactWhere", source)


if __name__ == "__main__":
    unittest.main()
