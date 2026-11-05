"""Bounded source guards; real SQLite and widget tests prove behavior."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
LIB = ROOT / 'Mobile/wbws_flutter_app/lib'


class AttendanceWriteSessionFence(unittest.TestCase):
    def test_attendance_requires_and_forwards_original_session(self):
        source = (LIB / 'services/local_db.dart').read_text()
        attendance = source.split('Future<LegacyOperationRef> saveAttendanceLocal(', 1)[1]
        attendance = attendance.split('Future<List<Map<String, dynamic>>> getPendingAttendance()', 1)[0]
        self.assertIn('required LegacyWriteSession expectedSession', attendance)
        self.assertIn('expectedSession: expectedSession', attendance)

    def test_session_guard_is_transactional_and_precedes_replacement(self):
        source = (LIB / 'services/local_db.dart').read_text()
        helper = source.split('Future<LegacyOperationRef> _replaceLegacyOperation({', 1)[1]
        helper = helper.split('// PENDING ATTENDANCE', 1)[0]
        self.assertLess(helper.index('await db.transaction'), helper.index('!await activeSessionMatches('))
        self.assertIn('executor: txn', helper)
        self.assertIn('!expectedSession.isValid', helper)
        self.assertLess(helper.index('throw const StaleLegacyWriteSession()'), helper.index('await txn.delete('))

    def test_all_attendance_capture_paths_use_sheet_not_new_api_identity(self):
        screen = (LIB / 'screens/attendance/attendance_screen.dart').read_text()
        self.assertEqual(screen.count('expectedSession: _sheetWriteSession'), 3)
        capture = screen.split('LegacyWriteSession get _sheetWriteSession', 1)[1].split('bool get _canEdit', 1)[0]
        self.assertIn('ownerUserId: _sheetOwner!', capture)
        self.assertIn('authorizationVersion: _sheetAuthorizationVersion!', capture)
        self.assertIn('runtimeGeneration: _sheetGeneration!', capture)
        self.assertNotIn('_api.', capture)
        self.assertIn('error is StaleLegacyWriteSession', screen)


if __name__ == '__main__':
    unittest.main()
