"""Opt-in strict calendar read, not a prepared authorization or lease."""
import json
import os
from pathlib import Path
import subprocess
import pytest

ROOT = Path(__file__).resolve().parents[2]


@pytest.mark.parametrize('reporting', ['strict', 'off'])
def test_real_sql_preparation_calendar(reporting):
    assert os.environ.get('SSMS_AUDIT_TESTING') == '1', 'Explicit isolated SQL test environment required'
    run = subprocess.run(['php', str(ROOT / 'tests/e2e/attendance_preparation_calendar.php'), reporting],
                         capture_output=True, text=True, timeout=30)
    assert run.returncode == 0, run.stdout + run.stderr
    assert not run.stderr, run.stderr
    data = json.loads(run.stdout)
    assert data['result'] == 'PASS' and data['checks'] == 41
    assert data['mysqli_strict'] == (reporting == 'strict')
    assert data['boundary_doubles'] == ['legacy temporary-table metadata', 'makeup prepare exception/false']


def test_strict_calendar_is_opt_in_scoped_and_restores_legacy_state():
    source = (ROOT / 'admin/backend/services/SchoolCalendarService.php').read_text()
    block = source.split('public static function preparationCalendar(', 1)[1].split('public static function mobileCalendar(', 1)[0]
    assert 'finally {' in block
    assert 'mysqli_report($previousMode)' in block
    assert 'self::$strictPreparationRead = false' in block
    assert 'self::resetCache()' in block
    assert 'self::mobileCalendar($conn, $classIds, $from, $to)' in block
    assert 'self::BACKDATE_DAYS + self::MOBILE_FUTURE_DAYS + 1' in block
    assert "strlen($row['days']) !== $days" in block
    assert "self::$strictPreparationRead) throw $e" in source
    assert 'preparationCalendar(' not in (ROOT / 'api/v1/routes/attendance.php').read_text()
    for forbidden in ['begin_transaction(', '->commit(', '->rollback(', 'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ']:
        assert forbidden not in block


def test_calendar_fixture_has_no_school_configuration_or_permanent_table_drop():
    source = (ROOT / 'tests/e2e/attendance_preparation_calendar.php').read_text()
    assert "PHP_SAPI !== 'cli'" in source
    assert "getenv('SSMS_AUDIT_TESTING') !== '1'" in source
    assert 'config.php' not in source and 'DROP TABLE' not in source
    assert 'DROP TEMPORARY TABLE IF EXISTS' in source
    assert "'ssms_comm_e2e'" in source and "'127.0.0.1'" in source
