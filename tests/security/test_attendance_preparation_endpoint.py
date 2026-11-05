"""Actual owned-schema/core-route preparation, no HTTP/mobile acceptance claim."""
import json
import os
from pathlib import Path
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[2]
SERVICE = ROOT / 'admin/backend/services/AttendancePreparationService.php'
GRANT = ROOT / 'admin/backend/services/AttendancePreparationGrant.php'
ROUTE = ROOT / 'api/v1/routes/attendance.php'
FIXTURE = ROOT / 'tests/e2e/attendance_preparation_endpoint.php'


def run_native(feature, reporting, mode='success'):
    assert os.environ.get('SSMS_AUDIT_TESTING') == '1', 'Explicit isolated SQL environment required'
    run = subprocess.run(['php', str(FIXTURE), feature, reporting, mode], capture_output=True, text=True, timeout=45)
    assert run.returncode == 0, run.stdout + run.stderr
    assert not run.stderr, run.stderr
    data = json.loads(run.stdout)
    assert data['result'] == 'PASS'
    assert data['checks'] == (56 if mode == 'success' else 55)
    assert data['feature'] == (feature == 'on') and data['mysqli_strict'] == (reporting == 'strict')
    assert data['actual_owned_schema'] and data['full_boundary_and_readers']
    assert data['adapter_doubles'] == ['response only, not HTTP transport']
    assert data['no_mobile_activation'] is True
    assert data['route_mode'] == mode


@pytest.mark.parametrize('feature', ['on', 'off'])
@pytest.mark.parametrize('reporting', ['strict', 'off'])
def test_actual_full_boundary_core_route_and_grants(feature, reporting):
    run_native(feature, reporting)


@pytest.mark.parametrize('mode', ['query', 'post', 'sub', 'unbound', 'revoked', 'scope'])
def test_actual_route_denials(mode):
    run_native('on', 'strict', mode)


def test_preparation_php_syntax():
    for file in [SERVICE, GRANT, ROUTE, FIXTURE]:
        run = subprocess.run(['php', '-l', str(file)], capture_output=True, text=True)
        assert run.returncode == 0, run.stdout + run.stderr


def test_full_boundary_authority_and_dependency_contract():
    source = SERVICE.read_text()
    for token in ['AttendancePreparationReadBoundary::read(', 'AttendancePreparationWorkflow::tables()',
                  'RefreshTokenService::accessSessionIsActive(', 'SELECT id,role,is_active,authorization_version FROM users',
                  'PREPARATION_SCOPE_CHANGED', 'SESSION_REVOKED', 'PREPARATION_WRITE_YEAR_NOT_CURRENT',
                  "WHERE status='active'", 'count($years) !== 1', 'SchoolCalendarService::today()',
                  'SchoolCalendarService::preparationCalendar(', 'EnrollmentService::resolvePreparationRosterYear(',
                  'EnrollmentService::fetchPreparationRosterDates(', 'AttendancePreparationWorkflow::read(',
                  "'deadline'=>min($issuedAt+30*86400,$yearEnd)", 'if ($knownDenial !== null) throw $knownDenial;']:
        assert token in source
    for table in ['users','academic_years','api_refresh_sessions','classes','teacher_assignments',
                  'class_enrollments','members','timetable_class_days','timetable_makeup_classes',
                  'school_day_offs','school_holidays','member_department_periods']:
        assert "'" + table + "'" in source
    assert 'getCurrentAcademicYear(' not in source
    assert '1 AS authorization_version' not in source
    assert '$_GET' not in source and '$_POST' not in source
    assert 'UPDATE ' not in source and 'CREATE TABLE' not in source and 'INSERT INTO' not in source


def test_scope_matches_existing_api_not_client_nomination():
    source = SERVICE.read_text()
    for token in ['ta.teacher_id=? AND ta.is_active=1', 'ta.academic_year_id IS NULL OR ta.academic_year_id=?',
                  "WHERE c.is_active=1 ORDER BY c.id", "'roster_year_id'=>$rosterYear['year_id']", "'write_year_id'=>$yearId"]:
        assert token in source
    route = ROUTE.read_text()
    block = route.split("if ($action === 'preparation')", 1)[1].split('$year = getCurrentAcademicYear();', 1)[0]
    assert "if ($query !== [])" in block
    assert 'apiRolesAttendance(), apiIsClassRestricted($auth)' in block
    assert '(string)SITE_URL' in block and 'API_TOKEN_SECRET' in block
    assert 'HTTP_HOST' not in block and 'HTTP_ORIGIN' not in block
    assert "['code'=>'ATTENDANCE_PREPARATION_UNAVAILABLE']" in block
    assert 'ok($preparation);' in block and '304' not in block.replace('No304', '')
    prefix = route.split('$auth = apiRequireAuth();', 1)[0]
    assert "if ($action === 'write-context') header('Cache-Control: no-store');" in prefix
    assert "if ($action === 'preparation') header('Cache-Control: no-store');" in prefix


def test_grant_integrity_and_permission_are_separate():
    source = GRANT.read_text()
    for token in ['hash_hmac(', 'hash_equals(', 'random_bytes(32)', 'ssms-attendance-preparation:',
                  'JSON_THROW_ON_ERROR', 'JSON_UNESCAPED_UNICODE', 'JSON_UNESCAPED_SLASHES',
                  "'payload_sha256'=>hash('sha256',$payload)", "'family_id'=>$facts['family_id']",
                  "'server_identity'=>$serverIdentity", "'base_digest'=>self::digest($day['records'])",
                  "'roster_digest'=>self::digest($day['member_ids'])", 'Cryptographic provenance ONLY',
                  "'not_before'=>$start->getTimestamp()", "'not_after'=>$notAfter", 'AttendanceRecordService::MAX_RECORDS']:
        assert token in source
    assert "['o','m']" in source
    for token in ["$day['origin_matches_write_year']", "$day['base_roster_compatible']", "!$day['lesson_derived']",
                  "$day['workflow_supported']", "$day['teacher_workflow_open'] || $staff"]:
        assert token in source
    assert "'authorizes_settlement'=>false" in source
    assert 'HTTP_HOST' not in source and '$_GET' not in source
    assert "'secret'=>" not in source


def test_owned_schema_fixture_safety_and_honest_boundaries():
    source = FIXTURE.read_text()
    for token in ["PHP_SAPI!=='cli'", "getenv('SSMS_AUDIT_TESTING')!=='1'", 'SSMS_PREPARATION_TEST_SOCKET',
                  "'ssms_preparation_'", 'random_bytes(6)', '$owned=false', '$owned=true',
                  "CREATE DATABASE `", "if($owned)", 'DROP DATABASE `', 'finally',
                  "'ssms_comm_e2e'", 'Actual core authentication', 'Concurrent real commit',
                  'ALTER TABLE users DROP COLUMN authorization_version', 'ENGINE=MyISAM',
                  'CREATE TEMPORARY TABLE members', 'Staff cannot acquire closed original-year capture permission',
                  'Root signs exact complete payload bytes', 'Unknown workflow never becomes new capture permission',
                  'Future coverage requires future opening, no early capture', 'No result/error doubles'.lower()]:
        assert token in source
    assert 'IF NOT EXISTS' not in source
    assert 'config.php' not in source and 'getenv(\'DB_' not in source
