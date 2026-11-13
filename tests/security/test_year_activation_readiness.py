"""Real Set Current Year handler in a disposable, local MariaDB fixture."""
import json
import os
import shutil
import subprocess

import pytest
from test_year_rollover_atomic import ROOT, _mysql, fixture_db


@pytest.fixture
def activation_world(fixture_db):
    db, project = fixture_db
    shutil.copytree(ROOT / 'admin/backend', project / 'admin/backend', dirs_exist_ok=True)
    shutil.copyfile(ROOT / 'admin/api_education.php', project / 'admin/api_education.php')
    (project / 'admin/config.php').write_text(r'''<?php
if (PHP_SAPI !== 'cli' || getenv('SSMS_AUDIT_TESTING') !== '1') exit(90);
mysqli_report(getenv('ACT_STRICT') === '1' ? MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);
class ActivationFixtureConnection extends mysqli {
    public function begin_transaction(int $flags = 0, ?string $name = null): bool {
        if (getenv('ACT_FAILURE') === 'begin') return false;
        return parent::begin_transaction($flags, $name);
    }
    public function commit(int $flags = 0, ?string $name = null): bool {
        if (getenv('ACT_FAILURE') === 'commit_ack_lost') { parent::commit($flags, $name); return false; }
        if (getenv('ACT_FAILURE') === 'commit_not_sent') return false;
        return parent::commit($flags, $name);
    }
    public function rollback(int $flags = 0, ?string $name = null): bool {
        if (getenv('ACT_FAILURE') === 'rollback') return false;
        return parent::rollback($flags, $name);
    }
}
$socket = getenv('SSMS_ROLLOVER_FIXTURE_SOCKET') ?: null;
$conn = new ActivationFixtureConnection($socket ? 'localhost' : '127.0.0.1', 'root', $socket ? '' : 'root', getenv('ROLLOVER_FIXTURE_DB'), $socket ? 0 : 3306, $socket);
$_SESSION = ['admin_id'=>1,'admin_role'=>getenv('ACT_ROLE') ?: 'school_admin','csrf_token'=>'fixture'];
$_SERVER['REQUEST_METHOD'] = getenv('ACT_METHOD') ?: 'POST';
$_POST = ['action'=>'set_current_year','year_id'=>(int)(getenv('ACT_YEAR') ?: 2), 'confirm'=>getenv('ACT_CONFIRM') ?: 'SWITCH', 'issues_fingerprint'=>getenv('ACT_FINGERPRINT') ?: '', 'csrf_token'=>getenv('ACT_CSRF') ?: 'fixture'];
$_REQUEST = $_POST;
function validateCsrf($value) { return hash_equals($_SESSION['csrf_token'], $value); }
function requirePostActions($action, $actions) { if (in_array($action, $actions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error']); exit; } }
require __DIR__ . '/backend/academic_year.php';
''')
    try:
        yield db, project
    finally:
        # Each case copies real backend code for the handler. Do not retain
        # dozens of copies in a memory-backed pytest temporary directory.
        shutil.rmtree(project, ignore_errors=True)


def invoke(world, **extra):
    db, project = world
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, **extra)
    p = subprocess.run(['php', str(project / 'admin/api_education.php')], env=env,
                       text=True, capture_output=True, timeout=30)
    assert p.returncode == 0, p.stderr
    return json.loads(p.stdout)


def state(db):
    return _mysql(f'SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id;')


def test_empty_invalid_target_discloses_both_before_any_mutation(activation_world):
    db, _ = activation_world
    _mysql(f"UPDATE `{db}`.academic_years SET start_date=NULL WHERE id=2; DELETE FROM `{db}`.academic_terms WHERE academic_year_id=2;")
    before = state(db)
    first = invoke(activation_world)
    assert first['code'] == 'confirm_activate_invalid', first
    issues = ' '.join(first['details']).lower()
    assert 'no students' in issues and 'no start/end dates' in issues and 'no semesters' in issues
    assert state(db) == before
    assert invoke(activation_world, ACT_CONFIRM='ACTIVATE EMPTY')['status'] != 'success'
    assert state(db) == before
    second = invoke(activation_world, ACT_CONFIRM='ACTIVATE ANYWAY', ACT_FINGERPRINT=first['issues_fingerprint'])
    assert second['status'] == 'success', second


def test_stale_fingerprint_does_not_approve_changed_settings(activation_world):
    db, _ = activation_world
    _mysql(f"UPDATE `{db}`.academic_years SET start_date=NULL,end_date='2030-01-01' WHERE id=2;")
    first = invoke(activation_world); before = state(db)
    _mysql(f"UPDATE `{db}`.academic_years SET end_date='2031-01-01' WHERE id=2;")
    second = invoke(activation_world, ACT_CONFIRM='ACTIVATE ANYWAY', ACT_FINGERPRINT=first['issues_fingerprint'])
    assert second['code'] == 'confirm_activate_invalid' and first['issues_fingerprint'] != second['issues_fingerprint']
    assert state(db) == before


def test_empty_only_also_requires_fresh_fingerprint(activation_world):
    db, _ = activation_world
    before = state(db); first = invoke(activation_world)
    assert first['code'] == 'confirm_activate_empty', first
    assert invoke(activation_world, ACT_CONFIRM='ACTIVATE EMPTY')['status'] != 'success'
    assert state(db) == before
    assert invoke(activation_world, ACT_CONFIRM='ACTIVATE EMPTY', ACT_FINGERPRINT=first['issues_fingerprint'])['status'] == 'success'


def test_reopen_invalid_year_requires_reopen_then_fresh_issue_confirmation(activation_world):
    db, _ = activation_world
    _mysql(f"UPDATE `{db}`.academic_years SET status='closed',start_date=NULL WHERE id=2;")
    before = state(db)
    assert invoke(activation_world)['code'] == 'confirm_reopen'
    first = invoke(activation_world, ACT_CONFIRM='REOPEN')
    assert first['code'] == 'confirm_activate_invalid', first
    assert state(db) == before
    assert invoke(activation_world, ACT_CONFIRM='ACTIVATE ANYWAY', ACT_FINGERPRINT=first['issues_fingerprint'])['status'] == 'success'


@pytest.mark.parametrize('table', ['academic_terms', 'class_enrollments', 'teacher_assignments'])
def test_unavailable_validation_fails_closed(activation_world, table):
    db, _ = activation_world
    _mysql(f'DROP TABLE `{db}`.`{table}`;')
    before = state(db)
    result = invoke(activation_world)
    assert result['status'] == 'error' and not result.get('needs_confirmation'), result
    assert state(db) == before


@pytest.mark.parametrize('strict', ['0', '1'])
def test_valid_nonempty_target_switches_without_warning(activation_world, strict):
    db, _ = activation_world
    _mysql(f"INSERT INTO `{db}`.class_enrollments VALUES (1,10,2,CURDATE(),'active',1);")
    result = invoke(activation_world, ACT_STRICT=strict)
    assert result['status'] == 'success', result
    assert state(db).splitlines() == ['1\t0\tclosed', '2\t1\tactive']


def test_no_warning_after_change_does_not_reuse_old_override(activation_world):
    db, _ = activation_world
    first = invoke(activation_world); before = state(db)
    _mysql(f"INSERT INTO `{db}`.class_enrollments VALUES (1,10,2,CURDATE(),'active',1);")
    result = invoke(activation_world, ACT_CONFIRM='ACTIVATE EMPTY', ACT_FINGERPRINT=first['issues_fingerprint'])
    assert result['status'] == 'error' and state(db) == before
    assert invoke(activation_world)['status'] == 'success'


def test_active_year_change_invalidates_pending_confirmation(activation_world):
    db, _ = activation_world
    first = invoke(activation_world)
    _mysql(f"UPDATE `{db}`.academic_years SET status='closed',is_current=0 WHERE id=1; INSERT INTO `{db}`.academic_years (id,is_current,status,year_name) VALUES (3,1,'active','Different');")
    before = state(db)
    second = invoke(activation_world, ACT_CONFIRM='ACTIVATE EMPTY', ACT_FINGERPRINT=first['issues_fingerprint'])
    assert second['code'] == 'confirm_activate_empty' and second['issues_fingerprint'] != first['issues_fingerprint']
    assert state(db) == before


def test_corrected_warning_requires_new_review_not_old_phrase(activation_world):
    db, _ = activation_world
    _mysql(f"UPDATE `{db}`.academic_years SET start_date=NULL WHERE id=2;")
    first = invoke(activation_world)
    _mysql(f"UPDATE `{db}`.academic_years SET start_date=DATE_SUB(CURDATE(), INTERVAL 30 DAY) WHERE id=2;")
    before = state(db)
    second = invoke(activation_world, ACT_CONFIRM='ACTIVATE ANYWAY', ACT_FINGERPRINT=first['issues_fingerprint'])
    assert second['code'] == 'confirm_activate_empty' and second['issues_fingerprint'] != first['issues_fingerprint']
    assert state(db) == before


@pytest.mark.parametrize('table', ['academic_years','academic_terms','class_enrollments','teacher_assignments'])
def test_nontransactional_table_never_allows_switch(activation_world, table):
    db, _ = activation_world
    # The fixture assignment FK must be removed before converting that table.
    if table == 'teacher_assignments': _mysql(f'ALTER TABLE `{db}`.`teacher_assignments` DROP FOREIGN KEY fk_fixture_assigner;')
    _mysql(f'ALTER TABLE `{db}`.`{table}` ENGINE=MyISAM;')
    before = state(db); result = invoke(activation_world)
    assert result['status'] == 'error' and not result.get('needs_confirmation'), result
    assert state(db) == before


@pytest.mark.parametrize('kwargs', [
    {'ACT_ROLE':'teacher'}, {'ACT_ROLE':'edu_dept'}, {'ACT_CSRF':'wrong'}, {'ACT_METHOD':'GET'},
])
def test_role_csrf_and_method_remain_enforced(activation_world, kwargs):
    db, _ = activation_world
    first = invoke(activation_world); before = state(db)
    result = invoke(activation_world, ACT_CONFIRM='ACTIVATE EMPTY', ACT_FINGERPRINT=first['issues_fingerprint'], **kwargs)
    assert result['status'] == 'error' and state(db) == before


def test_bad_initial_phrase_and_missing_target_are_nonmutating(activation_world):
    db, _ = activation_world; before = state(db)
    assert invoke(activation_world, ACT_CONFIRM='NOPE')['code'] == 'confirm_switch'
    assert invoke(activation_world, ACT_YEAR='999')['status'] == 'error'
    assert state(db) == before


def test_invalid_lifecycle_state_is_rejected(activation_world):
    db, _ = activation_world
    _mysql(f"INSERT INTO `{db}`.academic_years (id,is_current,status,year_name) VALUES (3,1,'active','Duplicate');")
    before = state(db)
    result = invoke(activation_world)
    assert result['status'] == 'error' and not result.get('needs_confirmation')
    assert state(db) == before


def test_warning_not_returned_if_rollback_is_uncertain(activation_world):
    db, _ = activation_world
    result = invoke(activation_world, ACT_FAILURE='rollback')
    assert result['status'] == 'error' and 'outcome could not be confirmed' in result['message']
    assert not result.get('needs_confirmation') and 'issues_fingerprint' not in result


@pytest.mark.parametrize('failure,committed', [('commit_not_sent',False), ('commit_ack_lost',True)])
def test_uncertain_commit_is_never_reported_as_unchanged(activation_world, failure, committed):
    db, _ = activation_world
    first = invoke(activation_world)
    result = invoke(activation_world, ACT_CONFIRM='ACTIVATE EMPTY', ACT_FINGERPRINT=first['issues_fingerprint'], ACT_FAILURE=failure)
    assert result['status'] == 'error' and 'outcome could not be confirmed' in result['message'], result
    assert ("2\t1\tactive" in state(db)) is committed


def test_concurrent_distinct_switches_cannot_reuse_stale_confirmation(activation_world):
    db, project = activation_world
    _mysql(f"INSERT INTO `{db}`.academic_years (id,is_current,status,year_name,start_date,end_date,s1_weight_pct,s2_weight_pct) VALUES (3,0,'upcoming','Other',DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 30 DAY),50,50); INSERT INTO `{db}`.academic_terms VALUES (31,3,1);")
    first = invoke(activation_world); second = invoke(activation_world, ACT_YEAR='3')
    base = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, ACT_CONFIRM='ACTIVATE EMPTY')
    children=[]
    for year, challenge in [('2',first),('3',second)]:
        children.append(subprocess.Popen(['php',str(project/'admin/api_education.php')], env=dict(base, ACT_YEAR=year, ACT_FINGERPRINT=challenge['issues_fingerprint']), text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE))
    results=[]
    for c in children:
        out, err=c.communicate(timeout=30); assert c.returncode==0, err; results.append(json.loads(out))
    assert sorted(r['status'] for r in results)==['error','success'], results
    assert [r.get('code') for r in results if r['status']=='error']==['confirm_activate_empty']
    assert _mysql(f"SELECT COUNT(*) FROM `{db}`.academic_years WHERE status='active' AND is_current=1;")=='1'


def test_pending_empty_roster_decision_locks_out_concurrent_insert(activation_world):
    import time
    db, project = activation_world
    first = invoke(activation_world)
    _mysql(f"CREATE TRIGGER `{db}`.activation_slow BEFORE UPDATE ON `{db}`.academic_years FOR EACH ROW DO SLEEP(2);")
    env=dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db,
             ACT_CONFIRM='ACTIVATE EMPTY', ACT_FINGERPRINT=first['issues_fingerprint'])
    child=subprocess.Popen(['php',str(project/'admin/api_education.php')],env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    try:
        deadline=time.monotonic()+10
        while time.monotonic()<deadline:
            if _mysql(f"SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE STATE='User sleep' AND DB='{db}';") != '0': break
            time.sleep(.05)
        else: pytest.fail('Activation did not reach the synthetic pause')
        start=time.monotonic()
        _mysql(f"INSERT INTO `{db}`.class_enrollments VALUES (2,10,2,CURDATE(),'active',1);")
        elapsed=time.monotonic()-start
        out,err=child.communicate(timeout=15); assert child.returncode==0,err
        result=json.loads(out)
        assert result['status']=='success',result
        assert elapsed>1, f'Concurrent insert was not held until activation completed ({elapsed:.2f}s)'
        assert _mysql(f"SELECT status FROM `{db}`.academic_years WHERE id=2")=='active'
    finally:
        if child.poll() is None:
            child.kill(); child.communicate()
