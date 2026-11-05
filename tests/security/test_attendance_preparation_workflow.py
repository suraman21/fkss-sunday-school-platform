"""Opt-in strict workflow facts; real SQL acceptance lives in guarded CLI fixture."""
from pathlib import Path
import subprocess
import json
import os

import pytest

ROOT = Path(__file__).resolve().parents[2]
SERVICE = ROOT / 'admin/backend/services/AttendancePreparationWorkflow.php'
FIXTURE = ROOT / 'tests/e2e/attendance_preparation_workflow.php'


@pytest.mark.parametrize('feature', ['on', 'off'])
@pytest.mark.parametrize('reporting', ['strict', 'off'])
def test_actual_strict_workflow_sql(feature, reporting):
    assert os.environ.get('SSMS_AUDIT_TESTING') == '1', 'Explicit isolated SQL environment required'
    run = subprocess.run(['php', str(FIXTURE), feature, reporting], capture_output=True, text=True, timeout=30)
    assert run.returncode == 0, run.stdout + run.stderr
    assert not run.stderr, run.stderr
    data = json.loads(run.stdout)
    assert data['result'] == 'PASS' and data['checks'] == 62
    assert data['feature'] == (feature == 'on') and data['mysqli_strict'] == (reporting == 'strict')
    assert data['statements'] == data['one_date_statements'] == data['full106_statements'] == (4 if feature == 'on' else 2)
    assert data['actual_temp_sql'] is True and data['result_or_failure_doubles'] == []
    assert data['authorization_or_capture_grant'] is False
    assert data['legacy_unknown_status_teacher_open'] is True
    assert data['legacy_foreign_origin_staff_helper_open'] is True


def test_workflow_syntax():
    for file in [SERVICE, FIXTURE]:
        result = subprocess.run(['php', '-l', str(file)], capture_output=True, text=True)
        assert result.returncode == 0, result.stdout + result.stderr


def test_workflow_has_no_legacy_permissive_reads_or_writes():
    source = SERVICE.read_text()
    for forbidden in ['ensureTable(', 'attendancePacketStatus(', 'attendanceHasRows(',
                      'dayHasLessons(', 'available(', 'begin_transaction(', 'commit(',
                      'rollback(', 'INSERT INTO', 'UPDATE attendance', 'DELETE FROM', 'CREATE TABLE']:
        assert forbidden not in source
    assert 'SubmissionService::normalizeStatus(' in source
    assert 'SubmissionService::statusIsOpen(' in source
    assert 'status_supported' in source and 'workflow_supported' in source


def test_workflow_explicit_scope_and_parameter_binding():
    source = SERVICE.read_text()
    for token in ['array_is_list($dates)', 'checkdate(', 'isset($days[$date])',
                  'SchoolCalendarService::BACKDATE_DAYS', 'SchoolCalendarService::MOBILE_FUTURE_DAYS',
                  "array_fill(0, count($dates), '?')", "array_merge([$classId], $dates)",
                  'bind_param($types, ...$params)', 'if (!$result)', 'finally { $result->free(); }']:
        assert token in source
    assert '$_GET' not in source and '$_POST' not in source


def test_workflow_origins_never_infer_or_restamp():
    source = SERVICE.read_text()
    assert "self::originYear($row['academic_year_id'])" in source
    assert 'origin_unknown' in source and 'origin_year_ids' in source
    assert '$years === [] || $years === [$writeYearId]' in source
    assert "'academic_year_id'=>$year" in source
    assert "ORDER BY attendance_date,id DESC" in source
    assert 'ALL packet origins matter' in source
    assert 'staffCanOverride' not in source
    assert 'authorizes_offline_work' not in source


def test_workflow_feature_dependency_and_strict_failures():
    source = SERVICE.read_text()
    assert source.count("FeatureGate::isEnabled('attendance_v2')") == 2
    for table in ['attendance', 'grade_submissions', 'attendance_sessions', 'attendance_session_records']:
        assert "'" + table + "'" in source
    assert "if ($lessonsEnabled)" in source
    assert "['submitted','cancelled']" in source
    assert "if ($row['status'] === 'cancelled') continue;" in source
    assert "throw new \\RuntimeException('Preparation workflow is unavailable.', 0, $error)" in source


def test_real_fixture_safety_and_coverage():
    source = FIXTURE.read_text()
    for token in ["PHP_SAPI!=='cli'", "getenv('SSMS_AUDIT_TESTING')!=='1'", 'CREATE TEMPORARY TABLE',
                  'DROP TEMPORARY TABLE IF EXISTS', 'finally', 'array_reverse($tables)',
                  'SSMS_PREPARATION_TEST_SOCKET', 'actual_temp_sql', 'result_or_failure_doubles',
                  'ALTER TABLE attendance DROP COLUMN notes', 'ALTER TABLE grade_submissions DROP COLUMN academic_year_id',
                  'ALTER TABLE attendance_sessions DROP COLUMN session_date',
                  'ALTER TABLE attendance_session_records DROP COLUMN status',
                  'Full existing calendar budget bounded SQL', 'Same member different year retained read-only',
                  'Earlier foreign packet origin outranks latest open packet', 'Unknown original year preserved',
                  'Unknown status cannot normalize into permission', 'Reporting mode preserved']:
        assert token in source
    assert '/config' not in source
    assert 'class WorkflowConnection extends mysqli' in source
    assert 'return parent::prepare($query)' in source
