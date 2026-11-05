"""Opt-in real storage boundary, not authorization/lease/readiness evidence."""
import json
import os
from pathlib import Path
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[2]


@pytest.mark.parametrize('reporting', ['strict', 'off'])
def test_real_preparation_boundary(reporting):
    assert os.environ.get('SSMS_AUDIT_TESTING') == '1', 'Explicit isolated SQL environment required'
    run = subprocess.run(['php', str(ROOT / 'tests/e2e/attendance_preparation_boundary.php'), reporting],
                         capture_output=True, text=True, timeout=30)
    assert run.returncode == 0, run.stdout + run.stderr
    assert not run.stderr, run.stderr
    data = json.loads(run.stdout)
    assert data['result'] == 'PASS' and data['checks'] == 51
    assert data['mysqli_strict'] == (reporting == 'strict')
    assert data['real_connections'] == 2 and data['real_metadata'] and data['real_concurrency']
    assert data['bounded_snapshot'] == [1, 1, 1]
    assert data['unbounded_split'] == [2, 3] and data['baseline_writer_commit'] == [3, 3]
    assert data['boundary_doubles'] == ['begin false/exception', 'commit false/exception', 'rollback false/exception']


def test_boundary_stays_opt_in_and_does_not_certify_authority():
    source = (ROOT / 'admin/backend/services/AttendancePreparationReadBoundary.php').read_text()
    assert 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' in source
    assert 'SET SESSION' not in source
    assert 'MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT' in source
    assert "'SHOW CREATE TABLE `'" in source
    assert "'BASE TABLE'" in source and "'InnoDB'" in source
    assert source.index('SET TRANSACTION') < source.index('->begin_transaction(')
    assert source.index('self::requireTransactionalTable($conn, $table)') < source.index('$value = $reader($conn)')
    assert source.index('if (!$conn->commit())') < source.index('return $value;')
    assert 'if (!$conn->rollback())' in source and 'connection discarded.' in source
    assert 'mysqli_report(' not in source
    assert 'AttendancePreparationReadBoundary' not in (ROOT / 'api/v1/routes/attendance.php').read_text()
    for forbidden in ['ALTER TABLE', "query('CREATE TABLE", 'INSERT INTO', 'DELETE FROM', 'UPDATE users', 'authorizes_offline_work']:
        assert forbidden not in source


def test_fixture_owns_only_fresh_synthetic_objects():
    source = (ROOT / 'tests/e2e/attendance_preparation_boundary.php').read_text()
    assert "PHP_SAPI !== 'cli'" in source and "getenv('SSMS_AUDIT_TESTING') !== '1'" in source
    assert 'config.php' not in source
    assert "'ssms_comm_e2e'" in source and "'127.0.0.1'" in source
    assert "'prep_boundary_' . getmypid() . '_' . bin2hex(random_bytes(6))" in source
    assert "CREATE TABLE IF NOT EXISTS" not in source
    assert "foreach (array_reverse($owned, true)" in source
    assert "str_starts_with($table, $prefix . '_')" in source
    assert 'DROP DATABASE' not in source
