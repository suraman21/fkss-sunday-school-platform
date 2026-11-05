"""Normalized strict batch parity, not authorization/preparation readiness."""
import json
import os
from pathlib import Path
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[2]


@pytest.mark.parametrize('feature', ['on', 'off'])
@pytest.mark.parametrize('reporting', ['strict', 'off'])
def test_actual_batched_roster_sql_parity_and_bounded_statements(feature, reporting):
    assert os.environ.get('SSMS_AUDIT_TESTING') == '1', 'Explicit isolated SQL environment required'
    run = subprocess.run(['php', str(ROOT / 'tests/e2e/attendance_preparation_batch.php'), feature, reporting],
                         capture_output=True, text=True, timeout=30)
    assert run.returncode == 0, run.stdout + run.stderr
    assert not run.stderr, run.stderr
    data = json.loads(run.stdout)
    assert data['result'] == 'PASS' and data['checks'] == (187 if feature == 'on' else 199)
    assert data['feature'] == (feature == 'on') and data['mysqli_strict'] == (reporting == 'strict')
    assert set(data['prepared_statements'].values()) == {3 if feature == 'on' else 2}
    assert set(data['schema_probes'].values()) == {1 if feature == 'on' else 0}
    assert data['actual_single_date_strategy_statements'] == (212 if feature == 'on' else 106)
    assert data['instrumentation'] == 'prepare calls forwarded unchanged to real mysqli'
    assert data['boundary_doubles'] == [] and data['authorization_or_grant'] is False


def test_legacy_and_batch_share_one_unchanged_next_day_predicate():
    source = (ROOT / 'admin/backend/services/DepartmentStatusService.php').read_text()
    block = source.split('public static function exclusionSql(', 1)[1].split('public static function inactiveCount(', 1)[0]
    assert 'return self::buildExclusionSql($conn, $dept, $memberIdExpr, $onDate, $strictPreparation, true);' in block
    assert 'self::buildExclusionSql($conn, $dept, $memberIdExpr, $date, true, $probe)' in block
    assert '$probe = false;' in block and '$strictPreparation && $probeColumns' in block
    assert block.count('AND NOT EXISTS (SELECT 1 FROM') == 1
    assert "dsp.started_at < '{$nextSql}'" in block
    assert "dsp.ended_at >= '{$nextSql}'" in block
    assert '$strictPreparation ? self::enabled() : self::available($conn)' in block
    assert 'self::$tableCache' not in block


def test_batch_is_normalized_bounded_opt_in_and_owns_no_transaction():
    source = (ROOT / 'admin/backend/services/EnrollmentService.php').read_text()
    block = source.split('public static function fetchPreparationRosterDates(', 1)[1].split('private static function requirePreparationIds(', 1)[0]
    assert "DepartmentStatusService::preparationExclusions($conn, 'edu', 'm.id', $dates)" in block
    assert 'SchoolCalendarService::BACKDATE_DAYS + SchoolCalendarService::MOBILE_FUTURE_DAYS + 1' in block
    assert "implode(' UNION ALL ', $parts)" in block
    assert 'ORDER BY eligible.on_date, ordered_member.student_name, eligible.member_id' in block
    assert "'member_ids'=>$byDate[$date]" in block
    assert "count($memberSeen) !== count($ids)" in block
    assert "isset($seen[$date][$id])" in block
    for forbidden in ['SELECT *', 'begin_transaction(', '->commit(', '->rollback(', 'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'authorizes_offline_work']:
        assert forbidden not in block
    assert 'fetchPreparationRosterDates(' not in (ROOT / 'api/v1/routes/attendance.php').read_text()


def test_batch_fixture_mutates_only_owned_connection_local_tables():
    source = (ROOT / 'tests/e2e/attendance_preparation_batch.php').read_text()
    assert "PHP_SAPI !== 'cli'" in source and "getenv('SSMS_AUDIT_TESTING') !== '1'" in source
    assert 'config.php' not in source and 'DROP TABLE' not in source and 'DROP DATABASE' not in source
    assert 'CREATE TEMPORARY TABLE members' in source and 'CREATE TEMPORARY TABLE class_enrollments' in source
    assert 'CREATE TEMPORARY TABLE member_department_periods' in source
    assert 'DROP TEMPORARY TABLE IF EXISTS' in source
    assert "'ssms_comm_e2e'" in source and "'127.0.0.1'" in source
    assert 'return parent::prepare($query)' in source  # Instrumentation never fabricates results.
