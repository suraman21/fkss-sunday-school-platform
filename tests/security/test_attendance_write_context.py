"""Real route + synthetic boundary doubles; no real auth/SQL/HTTP claims."""
import json
from pathlib import Path
import subprocess
import pytest

ROOT = Path(__file__).resolve().parents[2]
ROUTE = ROOT / 'api/v1/routes/attendance.php'


def invoke(scenario):
    run = subprocess.run(['php', str(ROOT / 'tests/e2e/attendance_write_context.php')],
                         input=json.dumps(scenario), text=True, capture_output=True, timeout=15)
    assert run.returncode == 0, run.stdout + run.stderr
    assert not run.stderr, run.stderr
    return json.loads(run.stdout)


def test_actual_route_exposes_write_dimensions_not_roster_year_or_a_grant():
    result = invoke({})
    assert result['status'] == 200
    assert result['data'] == {
        'context_version': 1, 'owner_user_id': 101, 'authorization_version': 7,
        'class_id': 3, 'academic_year_id': 6, 'date': '2026-11-05',
        'authorizes_offline_work': False, 'authorizes_settlement': False,
    }
    assert result['events'] == ['authenticate', 'current-authority', 'write-year',
                                 ['class-access', 101, 3, 6], 'class-existence']


def test_rollover_does_not_rewrite_a_previously_returned_context():
    old = invoke({'year': 5})['data']
    new = invoke({'year': 6})['data']
    assert old['academic_year_id'] == 5 and new['academic_year_id'] == 6
    assert old['date'] == new['date'] == '2026-11-05'
    assert old['authorizes_offline_work'] is False


@pytest.mark.parametrize('method', ['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'])
def test_other_methods_do_not_parse_or_claim_work(method):
    result = invoke({'method': method})
    assert result['status'] == 405
    assert not any(isinstance(e, list) for e in result['events'])


@pytest.mark.parametrize('scenario,status', [
    ({'anonymous': True}, 401), ({'stale': True}, 401),
    ({'wrong_role': True}, 403), ({'wrong_class': True}, 403),
    ({'missing_class': True}, 404), ({'sub': 'extra'}, 404),
    ({'year': None}, 503), ({'year': 0}, 503), ({'year': -1}, 503),
    ({'year': 9007199254740992}, 503),
])
def test_authority_scope_and_missing_dimensions_fail_closed(scenario, status):
    result = invoke(scenario)
    assert result['status'] == status
    assert 'academic_year_id' not in result['data']


@pytest.mark.parametrize('value', ['', '0', '-1', '03', '+3', '3.0', ' 3',
                                     '3e0', '9007199254740992', '99999999999999999', None, [], 3])
def test_class_id_must_be_an_explicit_exact_positive_query_string(value):
    assert invoke({'query': {'class_id': value, 'date': '2026-11-05'}})['status'] == 422


@pytest.mark.parametrize('value', ['', '2026-02-29', '1900-02-29', '0000-01-01',
                                     '2026-1-05', '2026-11-05T00:00:00Z', None, [], 20261105])
def test_date_is_never_defaulted_or_normalized(value):
    assert invoke({'query': {'class_id': '3', 'date': value}})['status'] == 422


@pytest.mark.parametrize('query', [{}, {'class_id': '3'}, {'date': '2026-11-05'},
    {'class_id':'3', 'date':'2026-11-05', 'academic_year_id':'5'},
    {'class_id':'3', 'date':'2026-11-05', 'owner_user_id':'999'},
    {'class_id':'3', 'date':'2026-11-05', 'roster_year_id':'5'}])
def test_missing_or_client_nominated_scope_is_refused(query):
    assert invoke({'query': query})['status'] == 422


@pytest.mark.parametrize('day', ['2000-02-29', '2024-02-29', '0001-01-01', '9999-12-31'])
def test_exact_gregorian_dates_are_described_not_granted_permission(day):
    data = invoke({'query': {'class_id':'3', 'date':day}})['data']
    assert data['date'] == day and data['authorizes_offline_work'] is False


def test_no_store_precedes_auth_and_existing_write_scope_stays_unchanged():
    source = ROUTE.read_text()
    assert source.index("if ($action === 'write-context') header('Cache-Control: no-store')") < source.index('apiRequireAuth()')
    assert "['operation-status', 'capabilities', 'write-context'], true)" in source
    branch = source.split("if ($action === 'write-context') {", 1)[1].split('/**', 1)[0]
    assert 'resolveRosterYear' in branch  # explicit negative comment
    assert '::resolveRosterYear(' not in branch
    for forbidden in ['getBody(', 'apiIdempotencyBegin(', 'INSERT ', 'UPDATE ', 'DELETE ', 'begin_transaction(']:
        assert forbidden not in branch
    assert source.count('$yearIdOrNull = $year ? (int)$year[\'id\'] : null;') == 2
    assert "'durable_receipt_versions' => []" in source
    sync = (ROOT / 'Mobile/wbws_flutter_app/lib/services/sync_service.dart').read_text()
    screen = (ROOT / 'Mobile/wbws_flutter_app/lib/screens/attendance/attendance_screen.dart').read_text()
    assert 'write-context' not in sync + screen and 'originalAcademicYearId:' not in screen


def test_reserved_path_selector_is_not_an_extra_scope_field():
    result = invoke({'query': {'path':'attendance/write-context',
                               'class_id':'3', 'date':'2026-11-05'}})
    assert result['status'] == 200 and result['data']['class_id'] == 3
