"""Independent synthetic regressions for rollover and assessment storage integrity."""
import json
import os
import subprocess
import pytest
from test_year_rollover_atomic import _mysql, fixture_db
from test_copy_assessments_atomic import assessment_world, invoke


def run_rollover(db, project, **kwargs):
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, **kwargs)
    p = subprocess.run(['php', str(project / 'admin/tools/year_rollover.php')], env=env,
                       capture_output=True, text=True, check=True, timeout=20)
    return json.loads(p.stdout)


@pytest.mark.parametrize('conflict', ['inactive_teacher', 'inactive_enrollment'])
def test_no_rollover_success_when_destination_authority_is_inactive(fixture_db, conflict):
    db, project = fixture_db
    _mysql(f'INSERT INTO `{db}`.users VALUES (1);')
    if conflict == 'inactive_teacher':
        _mysql(f"INSERT INTO `{db}`.teacher_assignments (teacher_id,class_id,subject_id,academic_year_id,is_class_teacher,is_primary,assignment_role,assigned_by,assigned_at,is_active) VALUES (7,10,NULL,2,1,1,'homeroom',1,NOW(),0);")
    else:
        _mysql(f"INSERT INTO `{db}`.class_enrollments VALUES (1,10,2,CURDATE(),'withdrawn',1);")
    before = _mysql(f'SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT academic_year_id,status FROM `{db}`.class_enrollments ORDER BY academic_year_id;')
    result = run_rollover(db, project)
    assert result['status'] == 'error', result
    assert before == _mysql(f'SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT academic_year_id,status FROM `{db}`.class_enrollments ORDER BY academic_year_id;')


def test_nontransactional_year_table_is_rejected_before_writes(fixture_db):
    db, project = fixture_db
    _mysql(f'INSERT INTO `{db}`.users VALUES (1); ALTER TABLE `{db}`.academic_years ENGINE=MyISAM; ALTER TABLE `{db}`.teacher_assignments DROP COLUMN assigned_at;')
    before = _mysql(f'SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id;')
    result = run_rollover(db, project)
    assert result['status'] == 'error'
    assert before == _mysql(f'SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id;')


def test_nontransactional_assessment_table_is_rejected_before_copy(assessment_world):
    db, project = assessment_world
    _mysql(f"DELETE FROM `{db}`.academic_terms WHERE id=21; UPDATE `{db}`.assessments SET id=3 WHERE assessment_name='Exam'; UPDATE `{db}`.assessments SET id=1 WHERE assessment_name='Legacy'; UPDATE `{db}`.assessments SET id=2 WHERE assessment_name='Exam'; ALTER TABLE `{db}`.assessments ENGINE=MyISAM;")
    result = invoke(db, project, COPY_STRICT='1')
    assert result['status'] == 'error'
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.assessments WHERE academic_year_id=2;') == '0'


def test_inactive_promotion_destination_rolls_back_all_changes(fixture_db):
    db, project = fixture_db
    _mysql(f"""USE `{db}`;
        INSERT INTO users VALUES (1);
        ALTER TABLE members ADD current_class_id INT, ADD promoted_at DATE, ADD academic_status VARCHAR(30);
        UPDATE members SET current_class_id=10;
        ALTER TABLE class_enrollments ADD promoted_from INT;
        CREATE TABLE classes (id INT PRIMARY KEY, level_order INT, is_active INT) ENGINE=InnoDB;
        INSERT INTO classes VALUES (10,1,1),(20,2,1);
        INSERT INTO class_enrollments (member_id,class_id,academic_year_id,status) VALUES (1,20,2,'withdrawn');
    """)
    before = _mysql(f'SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT current_class_id FROM `{db}`.members; SELECT academic_year_id,status FROM `{db}`.class_enrollments ORDER BY academic_year_id;')
    result = run_rollover(db, project, ROLLOVER_MODE='promote')
    assert result['status'] == 'error' and 'enrollment' in result['message']
    assert before == _mysql(f'SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT current_class_id FROM `{db}`.members; SELECT academic_year_id,status FROM `{db}`.class_enrollments ORDER BY academic_year_id;')


@pytest.mark.parametrize('table', ['academic_years', 'members', 'class_enrollments', 'teacher_assignments'])
def test_each_nontransactional_rollover_table_is_rejected(fixture_db, table):
    db, project = fixture_db
    _mysql(f'INSERT INTO `{db}`.users VALUES (1);')
    if table == 'teacher_assignments':
        _mysql(f'ALTER TABLE `{db}`.teacher_assignments DROP FOREIGN KEY fk_fixture_assigner;')
    _mysql(f'ALTER TABLE `{db}`.`{table}` ENGINE=MyISAM;')
    result = run_rollover(db, project)
    assert result['status'] == 'error' and 'InnoDB' in result['message'], result
    assert _mysql(f'SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id;').splitlines() == ['1\t1\tactive', '2\t0\tupcoming']
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.class_enrollments WHERE academic_year_id=2;') == '0'


@pytest.mark.parametrize('table', ['academic_years', 'academic_terms', 'assessments'])
def test_each_nontransactional_assessment_table_is_rejected(assessment_world, table):
    db, project = assessment_world
    _mysql(f'ALTER TABLE `{db}`.`{table}` ENGINE=MyISAM;')
    result = invoke(db, project)
    assert result['status'] == 'error' and 'InnoDB' in result['message'], result
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.assessments WHERE academic_year_id=2;') == '0'


def test_assignment_trigger_side_effect_cannot_escape_rollback(fixture_db):
    db, project = fixture_db
    # Migration 048 updates users from the teacher-assignment INSERT trigger.
    # This reproduces a nontransactional trigger target even though the four
    # directly written rollover tables are all InnoDB.
    _mysql(f"""USE `{db}`;
        ALTER TABLE teacher_assignments DROP FOREIGN KEY fk_fixture_assigner;
        ALTER TABLE users ADD authorization_version INT NOT NULL DEFAULT 1;
        ALTER TABLE users ENGINE=MyISAM;
        INSERT INTO users (id) VALUES (1),(7);
        CREATE TRIGGER assignment_scope AFTER INSERT ON teacher_assignments FOR EACH ROW
            UPDATE users SET authorization_version=authorization_version+1 WHERE id=NEW.teacher_id;
        CREATE TRIGGER reject_closure BEFORE UPDATE ON class_enrollments FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure after teacher copy';
    """)
    result = run_rollover(db, project)
    assert result['status'] == 'error'
    assert _mysql(f'SELECT authorization_version FROM `{db}`.users WHERE id=7;') == '1'
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.teacher_assignments WHERE academic_year_id=2;') == '0'
