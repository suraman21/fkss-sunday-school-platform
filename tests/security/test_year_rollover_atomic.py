"""Real handler + temporary MariaDB tables; never production or real data."""
import json
import os
from pathlib import Path
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[2]


@pytest.mark.parametrize('reporting', ['strict', 'off'])
@pytest.mark.parametrize('scenario', ['normal', 'duplicate', 'duplicate_null', 'other_unique', 'prepare_error', 'data_warning'])
def test_real_rollover_is_atomic_on_copy_errors_and_warnings(scenario, reporting):
    assert os.environ.get('SSMS_AUDIT_TESTING') == '1', 'Explicit isolated SQL environment required'
    env = dict(os.environ, SSMS_DB_NAME='ssms_comm_e2e')
    run = subprocess.run(['php', str(ROOT / 'tests/e2e/year_rollover_atomic.php'), scenario, reporting],
                         cwd=ROOT, env=env, capture_output=True, text=True, timeout=30)
    assert run.returncode == 0, run.stdout + run.stderr
    result = json.loads(run.stdout)
    assert result['scenario'] == scenario and result['reporting'] == reporting
    is_failure = scenario in ('prepare_error', 'data_warning', 'other_unique')
    assert result['response']['status'] == ('error' if is_failure else 'success')
    if is_failure:
        assert 'NOTHING was changed' in result['response']['message']
        assert [(r['status'], int(r['is_current'])) for r in result['years']] == [('active', 1), ('upcoming', 0)]
        assert [(int(r['academic_year_id']), r['status']) for r in result['enrollments']] == [(1, 'active')]
        assert [int(r['academic_year_id']) for r in result['assignments']] == ([1, 2] if scenario == 'other_unique' else [1])
    else:
        assert [(r['status'], int(r['is_current'])) for r in result['years']] == [('closed', 0), ('active', 1)]
        assert [(int(r['academic_year_id']), r['status']) for r in result['enrollments']] == [(1, 'completed'), (2, 'active')]
        assert [int(r['academic_year_id']) for r in result['assignments']] == [1, 2]
        assert result['response']['report']['teacher_assignments_copied'] == (0 if scenario in ('duplicate', 'duplicate_null') else 1)


def test_fixture_only_uses_connection_local_temporary_tables():
    source = (ROOT / 'tests/e2e/year_rollover_atomic.php').read_text()
    assert "getenv('SSMS_AUDIT_TESTING') !== '1'" in source
    assert "DB_HOST !== '127.0.0.1' || DB_NAME !== 'ssms_comm_e2e'" in source
    assert source.count('CREATE TEMPORARY TABLE ') == 5
    assert 'DROP TABLE' not in source and 'DROP DATABASE' not in source
