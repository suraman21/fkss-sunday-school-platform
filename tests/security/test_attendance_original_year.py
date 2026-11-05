"""Source integration boundaries; actual SQLite behavior runs in Flutter tests."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICES = ROOT / 'Mobile/wbws_flutter_app/lib/services'


def test_original_year_migration_is_additive_and_unknown_by_default():
    migration = (SERVICES / 'attendance_original_year.dart').read_text()
    assert 'ADD COLUMN ' in migration and '$attendanceOriginalYearColumn INTEGER' in migration
    assert 'UPDATE ' not in migration and 'DELETE ' not in migration and 'DROP ' not in migration
    assert 'DateTime' not in migration and 'roster_year_id' not in migration
    db = (SERVICES / 'local_db.dart').read_text()
    assert db.count('await migrateAttendanceOriginalYear(db);') == 2
    assert 'if (oldVersion < 38)' in db


def test_legacy_network_contract_and_receipt_support_remain_unchanged():
    sync = (SERVICES / 'sync_service.dart').read_text()
    assert 'original_academic_year_id' not in sync and 'originalAcademicYearId' not in sync
    route = (ROOT / 'api/v1/routes/attendance.php').read_text()
    assert "'durable_receipt_versions' => []" in route
    screen = (ROOT / 'Mobile/wbws_flutter_app/lib/screens/attendance/attendance_screen.dart').read_text()
    assert 'originalAcademicYearId:' not in screen  # No unsafe roster/current-year adapter.


def test_year_preservation_uses_null_safe_exact_context_and_sibling_fence():
    db = (SERVICES / 'local_db.dart').read_text()
    assert 'AND original_academic_year_id IS ? ' in db
    assert 'OR sibling.original_academic_year_id IS NOT ' in db
    assert 'coherentAttendanceOriginalYear(claimedRows)' in db
    assert 'int? originalAcademicYearId' in (SERVICES / 'legacy_outbox_models.dart').read_text()
