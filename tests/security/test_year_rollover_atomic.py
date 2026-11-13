"""Rollover handler against synthetic InnoDB data; explicit local socket only.

The real handler runs with a test-only config/session shim, not a real login or
school configuration. No school database, web server, or production data is used.
"""
import json
import os
import re
import secrets
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
SOCKET = os.environ.get('SSMS_ROLLOVER_FIXTURE_SOCKET', '')


def _mysql(sql):
    if SOCKET:
        target = ['--protocol=socket', '--socket', SOCKET, '--user=root']
    else:
        # Exact disposable CI service used by the existing preparation fixtures.
        target = ['--protocol=tcp', '--host=127.0.0.1', '--port=3306',
                  '--user=root', '--password=root']
    return subprocess.run(
        ['mariadb', '--no-defaults', *target, '--batch', '--skip-column-names'],
        input=sql, text=True, capture_output=True, check=True).stdout.strip()


@pytest.fixture
def fixture_db(tmp_path):
    if SOCKET:
        assert re.fullmatch(r'/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock', SOCKET)
        assert Path(SOCKET).is_socket()
        expected_data = str(Path(SOCKET).parent / 'data') + '/'
        assert _mysql('SELECT @@datadir;') == expected_data
    else:
        if os.environ.get('GITHUB_ACTIONS') != 'true':
            pytest.skip('Explicit disposable rollover MariaDB socket required')
        assert os.environ.get('SSMS_AUDIT_TESTING') == '1'
        assert os.environ.get('SSMS_LIVE_GUARDS') == '1'
        assert _mysql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'ssms_comm_e2e';") == '1'
    db = 'ssms_rollover_' + secrets.token_hex(8)
    assert re.fullmatch(r'ssms_rollover_[0-9a-f]{16}', db)
    _mysql(f'CREATE DATABASE `{db}` CHARACTER SET utf8mb4;')
    try:
        _mysql(f'''USE `{db}`;
            CREATE TABLE academic_years (id INT PRIMARY KEY, is_current INT NOT NULL, status VARCHAR(20) NOT NULL, year_name VARCHAR(50) DEFAULT '', ec_year INT NULL, start_date DATE NULL, end_date DATE NULL, s1_weight_pct DECIMAL(5,2) NULL, s2_weight_pct DECIMAL(5,2) NULL) ENGINE=InnoDB;
            CREATE TABLE academic_terms (id INT PRIMARY KEY, academic_year_id INT NOT NULL, term_number INT NOT NULL, KEY (academic_year_id)) ENGINE=InnoDB;
            CREATE TABLE members (id INT PRIMARY KEY, status VARCHAR(20) NOT NULL) ENGINE=InnoDB;
            CREATE TABLE class_enrollments (member_id INT, class_id INT, academic_year_id INT, enrolled_at DATE, status VARCHAR(20), enrolled_by INT, UNIQUE KEY uq_member_year (member_id, academic_year_id)) ENGINE=InnoDB;
            CREATE TABLE users (id INT PRIMARY KEY) ENGINE=InnoDB;
            CREATE TABLE teacher_assignments (id INT AUTO_INCREMENT PRIMARY KEY, teacher_id INT NOT NULL, class_id INT NOT NULL, subject_id INT NULL, subject_key INT GENERATED ALWAYS AS (IFNULL(subject_id,0)) STORED, academic_year_id INT NULL, is_class_teacher INT NOT NULL, is_primary INT NOT NULL, assignment_role VARCHAR(20) NOT NULL, assigned_by INT NULL, assigned_at DATETIME NULL, is_active INT NOT NULL, UNIQUE KEY uq_assignment (teacher_id, class_id, subject_key, academic_year_id), CONSTRAINT fk_fixture_assigner FOREIGN KEY (assigned_by) REFERENCES users(id)) ENGINE=InnoDB;
            INSERT INTO academic_years (id,is_current,status,year_name,start_date,end_date,s1_weight_pct,s2_weight_pct) VALUES (1,1,'active','Old',DATE_SUB(CURDATE(), INTERVAL 30 DAY),DATE_ADD(CURDATE(), INTERVAL 30 DAY),50,50),(2,0,'upcoming','New',DATE_SUB(CURDATE(), INTERVAL 30 DAY),DATE_ADD(CURDATE(), INTERVAL 30 DAY),50,50);
            INSERT INTO academic_terms VALUES (11,1,1),(21,2,1);
            INSERT INTO members VALUES (1,'active');
            INSERT INTO class_enrollments VALUES (1,10,1,CURDATE(),'active',1);
            INSERT INTO teacher_assignments (teacher_id,class_id,subject_id,academic_year_id,is_class_teacher,is_primary,assignment_role,assigned_by,assigned_at,is_active) VALUES (7,10,NULL,1,1,1,'homeroom',NULL,NOW(),1);
        ''')
        project = tmp_path / 'project'
        (project / 'admin/tools').mkdir(parents=True)
        (project / 'admin/backend').mkdir(parents=True)
        shutil.copyfile(ROOT / 'admin/tools/year_rollover.php', project / 'admin/tools/year_rollover.php')
        (project / 'admin/backend/ethiopian_date.php').write_text('<?php\n')
        shutil.copyfile(ROOT / 'admin/backend/academic_year.php', project / 'admin/backend/academic_year.php')
        (project / 'admin/backend/services').mkdir()
        shutil.copyfile(ROOT / 'admin/backend/services/AttendancePreparationReadBoundary.php', project / 'admin/backend/services/AttendancePreparationReadBoundary.php')
        (project / 'admin/config.php').write_text(r'''<?php
if (PHP_SAPI !== 'cli' || getenv('SSMS_AUDIT_TESTING') !== '1') exit(90);
mysqli_report(getenv('ROLLOVER_STRICT') === '1' ? MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);
class FixtureConnection extends mysqli {
    public function begin_transaction(int $flags = 0, ?string $name = null): bool {
        if (getenv('ROLLOVER_FAILURE') === 'begin') return false;
        return parent::begin_transaction($flags, $name);
    }
    public function commit(int $flags = 0, ?string $name = null): bool {
        if (getenv('ROLLOVER_FAILURE') === 'commit_not_sent') return false;
        if (getenv('ROLLOVER_FAILURE') === 'commit_ack_lost') { parent::commit($flags, $name); return false; }
        return parent::commit($flags, $name);
    }
    public function rollback(int $flags = 0, ?string $name = null): bool {
        if (getenv('ROLLOVER_FAILURE') === 'rollback') return false;
        return parent::rollback($flags, $name);
    }
}
$socket = getenv('SSMS_ROLLOVER_FIXTURE_SOCKET') ?: null;
$conn = new FixtureConnection($socket ? 'localhost' : '127.0.0.1', 'root', $socket ? '' : 'root', getenv('ROLLOVER_FIXTURE_DB'), $socket ? 0 : 3306, $socket);
if (getenv('ROLLOVER_LIMIT_WARNINGS') === '1') $conn->query('SET SESSION max_error_count = 1');
$_SESSION = ['admin_logged_in'=>true, 'admin_role'=>getenv('ROLLOVER_ROLE') ?: 'school_admin', 'admin_id'=>1, 'csrf_token'=>'fixture'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action'=>'run','old_year_id'=>(int)(getenv('ROLLOVER_OLD_YEAR') ?: 1),'new_year_id'=>(int)(getenv('ROLLOVER_NEW_YEAR') ?: 2),'mode'=>getenv('ROLLOVER_MODE') ?: 'carry_forward','confirm'=>'ROLLOVER','csrf_token'=>getenv('ROLLOVER_CSRF') ?: 'fixture','confirm_issues'=>getenv('ROLLOVER_CONFIRM_ISSUES') ?: '', 'issues_fingerprint'=>getenv('ROLLOVER_FINGERPRINT') ?: ''];
$_REQUEST = $_POST;
function validateCsrf($value) { return hash_equals($_SESSION['csrf_token'], $value); }
require __DIR__ . '/backend/academic_year.php';
''')
        yield db, project
    finally:
        _mysql(f'DROP DATABASE `{db}`;')


@pytest.mark.parametrize('strict', [False, True])
@pytest.mark.parametrize('case', ['success', 'existing', 'missing_column', 'foreign_key', 'other_unique'])
def test_actual_rollover_copy_is_transactional(fixture_db, strict, case):
    db, project = fixture_db
    if case != 'foreign_key':
        _mysql(f"INSERT INTO `{db}`.users VALUES (1);")
    if case == 'existing':
        _mysql(f"INSERT INTO `{db}`.teacher_assignments (teacher_id,class_id,subject_id,academic_year_id,is_class_teacher,is_primary,assignment_role,assigned_by,assigned_at,is_active) VALUES (7,10,NULL,2,1,1,'homeroom',1,NOW(),1);")
    if case == 'missing_column':
        _mysql(f'ALTER TABLE `{db}`.teacher_assignments DROP COLUMN assigned_at;')
    if case == 'other_unique':
        _mysql(f"INSERT INTO `{db}`.teacher_assignments (teacher_id,class_id,subject_id,academic_year_id,is_class_teacher,is_primary,assignment_role,assigned_by,assigned_at,is_active) VALUES (99,10,1,2,0,1,'primary',1,NOW(),1); ALTER TABLE `{db}`.teacher_assignments ADD UNIQUE KEY uq_other (class_id, academic_year_id);")
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, ROLLOVER_STRICT='1' if strict else '0')
    run = subprocess.run(['php', str(project / 'admin/tools/year_rollover.php')], env=env, text=True, capture_output=True, timeout=20, check=True)
    response = json.loads(run.stdout)
    rows = _mysql(f"SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT academic_year_id,status FROM `{db}`.class_enrollments ORDER BY academic_year_id;").splitlines()
    if case in ('success', 'existing'):
        assert response['status'] == 'success', (response, run.stderr)
        assert rows == ['1\t0\tclosed', '2\t1\tactive', '1\tcompleted', '2\tactive']
        assert response['report']['teacher_assignments_copied'] == (0 if case == 'existing' else 1)
    else:
        assert response['status'] == 'error', (response, run.stderr)
        assert rows == ['1\t1\tactive', '2\t0\tupcoming', '1\tactive']
    count = int(_mysql(f"SELECT COUNT(*) FROM `{db}`.teacher_assignments WHERE teacher_id=7 AND academic_year_id=2;"))
    assert count == (1 if case in ('success', 'existing') else 0)


@pytest.mark.parametrize(('role', 'csrf'), [('teacher', 'fixture'), ('school_admin', 'invalid')])
def test_restricted_role_and_invalid_csrf_never_mutate_year(fixture_db, role, csrf):
    db, project = fixture_db
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db,
               ROLLOVER_ROLE=role, ROLLOVER_CSRF=csrf)
    run = subprocess.run(['php', str(project / 'admin/tools/year_rollover.php')],
                         env=env, text=True, capture_output=True, timeout=20, check=True)
    assert json.loads(run.stdout)['status'] == 'error'
    assert _mysql(f"SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id;").splitlines() == ['1\t1\tactive', '2\t0\tupcoming']
    assert _mysql(f"SELECT academic_year_id,status FROM `{db}`.class_enrollments;") == '1\tactive'


@pytest.mark.parametrize('case', ['missing_old', 'missing_new', 'old_not_active', 'new_closed', 'two_active', 'current_mismatch'])
def test_year_validation_rejects_stale_or_invalid_lifecycle(fixture_db, case):
    db, project = fixture_db
    _mysql(f"INSERT INTO `{db}`.users VALUES (1);")
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, ROLLOVER_STRICT='1')
    if case == 'missing_old': env['ROLLOVER_OLD_YEAR'] = '99'
    if case == 'missing_new': env['ROLLOVER_NEW_YEAR'] = '99'
    if case == 'old_not_active': _mysql(f"UPDATE `{db}`.academic_years SET status='closed',is_current=0 WHERE id=1;")
    if case == 'new_closed': _mysql(f"UPDATE `{db}`.academic_years SET status='closed' WHERE id=2;")
    if case == 'two_active': _mysql(f"INSERT INTO `{db}`.academic_years (id,is_current,status) VALUES(3,1,'active');")
    if case == 'current_mismatch': _mysql(f"UPDATE `{db}`.academic_years SET is_current=0 WHERE id=1;")
    before = _mysql(f"SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT academic_year_id,status FROM `{db}`.class_enrollments ORDER BY academic_year_id;")
    run = subprocess.run(['php', str(project / 'admin/tools/year_rollover.php')], env=env, text=True, capture_output=True, timeout=20, check=True)
    response = json.loads(run.stdout)
    assert response['status'] == 'error'
    assert 'active year' in response['message']
    after = _mysql(f"SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT academic_year_id,status FROM `{db}`.class_enrollments ORDER BY academic_year_id;")
    assert before == after
    assert _mysql(f"SELECT COUNT(*) FROM `{db}`.teacher_assignments WHERE academic_year_id=2;") == '0'


@pytest.mark.parametrize('failure', ['begin', 'commit_not_sent', 'commit_ack_lost', 'rollback'])
def test_database_outcome_reporting_never_claims_unconfirmed_rollback(fixture_db, failure):
    db, project = fixture_db
    _mysql(f"INSERT INTO `{db}`.users VALUES (1);")
    if failure == 'rollback':
        _mysql(f'ALTER TABLE `{db}`.teacher_assignments DROP COLUMN assigned_at;')
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db,
               ROLLOVER_STRICT='1', ROLLOVER_FAILURE=failure)
    run = subprocess.run(['php', str(project / 'admin/tools/year_rollover.php')], env=env,
                         text=True, capture_output=True, timeout=20, check=True)
    response = json.loads(run.stdout)
    assert response['status'] == 'error'
    if failure == 'begin':
        assert 'NOTHING was changed' in response['message']
    else:
        assert 'outcome could not be confirmed' in response['message']
        assert 'Do not retry' in response['message']
    rows = _mysql(f"SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id;").splitlines()
    if failure == 'commit_ack_lost':
        assert rows == ['1\t0\tclosed', '2\t1\tactive']
    else:
        assert rows == ['1\t1\tactive', '2\t0\tupcoming']


def test_truncated_warning_list_never_hides_a_non_duplicate_warning(fixture_db):
    db, project = fixture_db
    _mysql(f"INSERT INTO `{db}`.users VALUES (1);")
    # Two existing assignments cause duplicate warnings, beyond the synthetic
    # server's warning-detail limit. The remainder cannot safely be classified.
    _mysql(f"""INSERT INTO `{db}`.teacher_assignments
        (teacher_id,class_id,subject_id,academic_year_id,is_class_teacher,is_primary,assignment_role,assigned_by,assigned_at,is_active)
        VALUES (8,10,NULL,1,1,1,'homeroom',1,NOW(),1),
               (7,10,NULL,2,1,1,'homeroom',1,NOW(),1),
               (8,10,NULL,2,1,1,'homeroom',1,NOW(),1);""")
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db,
               ROLLOVER_STRICT='1', ROLLOVER_LIMIT_WARNINGS='1')
    run = subprocess.run(['php', str(project / 'admin/tools/year_rollover.php')], env=env,
                         text=True, capture_output=True, timeout=20, check=True)
    assert json.loads(run.stdout)['status'] == 'error'
    assert _mysql(f"SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id;").splitlines() == ['1\t1\tactive', '2\t0\tupcoming']
    assert _mysql(f"SELECT academic_year_id,status FROM `{db}`.class_enrollments;") == '1\tactive'


def test_concurrent_rollovers_recheck_active_year_under_lock(fixture_db):
    db, project = fixture_db
    _mysql(f"INSERT INTO `{db}`.users VALUES (1);")
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, ROLLOVER_STRICT='1')
    first = subprocess.Popen(['php', str(project / 'admin/tools/year_rollover.php')], env=env,
                             text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    second = subprocess.Popen(['php', str(project / 'admin/tools/year_rollover.php')], env=env,
                              text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    results = [p.communicate(timeout=20) for p in (first, second)]
    assert first.returncode == second.returncode == 0
    responses = [json.loads(stdout)['status'] for stdout, _ in results]
    assert sorted(responses) == ['error', 'success']
    assert _mysql(f"SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id;").splitlines() == ['1\t0\tclosed', '2\t1\tactive']
    assert _mysql(f"SELECT academic_year_id,status FROM `{db}`.class_enrollments ORDER BY academic_year_id;").splitlines() == ['1\tcompleted', '2\tactive']


@pytest.mark.parametrize('case', ['enrollment_foreign_key', 'enrollment_other_unique', 'year_update_failure'])
@pytest.mark.parametrize('strict', ['0', '1'])
def test_other_rollover_sql_failures_do_not_commit_partial_work(fixture_db, case, strict):
    db, project = fixture_db
    if case != 'enrollment_foreign_key': _mysql(f'INSERT INTO `{db}`.users VALUES (1);')
    if case == 'enrollment_foreign_key':
        _mysql(f'UPDATE `{db}`.class_enrollments SET enrolled_by=NULL; ALTER TABLE `{db}`.class_enrollments ADD CONSTRAINT fk_fixture_enroller FOREIGN KEY (enrolled_by) REFERENCES `{db}`.users(id);')
    if case == 'enrollment_other_unique':
        _mysql(f"INSERT INTO `{db}`.class_enrollments VALUES (99,10,2,CURDATE(),'active',1); ALTER TABLE `{db}`.class_enrollments ADD UNIQUE KEY uq_other (class_id,academic_year_id);")
    if case == 'year_update_failure':
        _mysql(f"CREATE TRIGGER `{db}`.reject_year_update BEFORE UPDATE ON `{db}`.academic_years FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic update failure';")
    before = _mysql(f"SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT member_id,academic_year_id,status FROM `{db}`.class_enrollments ORDER BY member_id,academic_year_id;")
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, ROLLOVER_STRICT=strict)
    run = subprocess.run(['php', str(project / 'admin/tools/year_rollover.php')], env=env,
                         text=True, capture_output=True, timeout=20, check=True)
    assert json.loads(run.stdout)['status'] == 'error'
    after = _mysql(f"SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT member_id,academic_year_id,status FROM `{db}`.class_enrollments ORDER BY member_id,academic_year_id;")
    assert after == before
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.teacher_assignments WHERE academic_year_id=2;') == '0'


@pytest.mark.parametrize('case', ['promotion', 'existing_target', 'other_unique_collision', 'ambiguous', 'graduate'])
def test_promote_mode_preserves_existing_placement_rules_and_rolls_back_bad_insert(fixture_db, case):
    db, project = fixture_db
    _mysql(f"""USE `{db}`;
        INSERT INTO users VALUES (1);
        ALTER TABLE members ADD current_class_id INT, ADD promoted_at DATE, ADD academic_status VARCHAR(30);
        UPDATE members SET current_class_id=10;
        ALTER TABLE class_enrollments ADD promoted_from INT;
        CREATE TABLE classes (id INT PRIMARY KEY,level_order INT,is_active INT) ENGINE=InnoDB;
        INSERT INTO classes VALUES (10,1,1);
    """)
    if case != 'graduate': _mysql(f'INSERT INTO `{db}`.classes VALUES (20,2,1);')
    if case == 'ambiguous': _mysql(f'INSERT INTO `{db}`.classes VALUES (21,2,1);')
    if case == 'existing_target':
        _mysql(f"INSERT INTO `{db}`.class_enrollments (member_id,class_id,academic_year_id,enrolled_at,status,enrolled_by) VALUES (1,20,2,CURDATE(),'active',1);")
    if case == 'other_unique_collision':
        _mysql(f"INSERT INTO `{db}`.class_enrollments (member_id,class_id,academic_year_id,enrolled_at,status,enrolled_by) VALUES (99,20,2,CURDATE(),'active',1); ALTER TABLE `{db}`.class_enrollments ADD UNIQUE KEY uq_other (class_id,academic_year_id);")
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, ROLLOVER_MODE='promote')
    run = subprocess.run(['php', str(project / 'admin/tools/year_rollover.php')], env=env,
                         text=True, capture_output=True, timeout=20, check=True)
    result = json.loads(run.stdout)
    if case == 'other_unique_collision':
        assert result['status'] == 'error'
        assert _mysql(f'SELECT current_class_id FROM `{db}`.members WHERE id=1;') == '10'
        assert _mysql(f"SELECT status FROM `{db}`.academic_years WHERE id=1;") == 'active'
    else:
        assert result['status'] == 'success', (result, run.stderr)
        report = result['report']
        assert report['promoted'] == (1 if case in ('promotion', 'existing_target') else 0)
        assert report['skipped'] == (1 if case == 'ambiguous' else 0)
        assert report['graduated'] == (1 if case == 'graduate' else 0)
        if case in ('promotion', 'existing_target'):
            assert _mysql(f'SELECT current_class_id FROM `{db}`.members WHERE id=1;') == '20'
            assert _mysql(f'SELECT COUNT(*) FROM `{db}`.class_enrollments WHERE member_id=1 AND class_id=20 AND academic_year_id=2;') == '1'
