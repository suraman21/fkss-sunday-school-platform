"""Strict preparation readers: real temporary SQL tables, explicit isolated world."""
import json
import os
from pathlib import Path
import subprocess
import pytest

ROOT = Path(__file__).resolve().parents[2]


@pytest.mark.parametrize('feature', ['on', 'off'])
@pytest.mark.parametrize('reporting', ['strict', 'off'])
def test_real_sql_preparation_roster(feature, reporting):
    # No config.php or stored credentials: public throwaway CI credentials,
    # loopback only, or the explicitly named private local fixture socket.
    assert os.environ.get('SSMS_AUDIT_TESTING') == '1', 'Explicit isolated SQL test environment required'
    run = subprocess.run(['php', str(ROOT / 'tests/e2e/attendance_preparation_roster.php'),
                          feature, reporting], capture_output=True, text=True, timeout=30)
    assert run.returncode == 0, run.stdout + run.stderr
    assert not run.stderr, run.stderr
    result = json.loads(run.stdout)
    assert result['result'] == 'PASS' and result['checks'] >= 29
    assert result['feature'] == (feature == 'on')
    assert result['mysqli_strict'] == (reporting == 'strict')


def test_strict_reader_is_not_a_route_grant_or_schema_migration():
    route = (ROOT / 'api/v1/routes/attendance.php').read_text()
    assert 'fetchPreparationRoster(' not in route
    assert 'resolvePreparationRosterYear(' not in route
    source = (ROOT / 'admin/backend/services/EnrollmentService.php').read_text()
    block = source.split('public static function resolvePreparationRosterYear(', 1)[1].split('public static function countActive(', 1)[0]
    assert 'LIMIT 1' in block and 'ORDER BY cnt DESC, academic_year_id DESC' in block
    assert "DepartmentStatusService::exclusionSql($conn, 'edu', 'm.id', $date, true)" in block
    assert "ORDER BY m.student_name, m.id" in block
    for forbidden in ['begin_transaction(', '->commit(', '->rollback(', 'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'SELECT *']:
        assert forbidden not in block


def test_sql_fixture_is_cli_only_and_never_drops_permanent_business_tables():
    fixture = (ROOT / 'tests/e2e/attendance_preparation_roster.php').read_text()
    assert "PHP_SAPI !== 'cli'" in fixture
    assert "getenv('SSMS_AUDIT_TESTING') !== '1'" in fixture
    assert "'127.0.0.1'" in fixture and "'ssms_comm_e2e'" in fixture
    assert 'config.php' not in fixture
    assert 'DROP TEMPORARY TABLE IF EXISTS' in fixture
    assert 'DROP TABLE' not in fixture
    assert 'CREATE TEMPORARY TABLE members' in fixture
    assert 'CREATE TEMPORARY TABLE class_enrollments' in fixture
