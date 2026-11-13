"""Real rollover handler: invalid targets need explicit, fresh acknowledgement."""
import pytest
from test_year_rollover_atomic import fixture_db, _mysql
from test_audit_rollover_gaps import run_rollover


def state(db):
    return _mysql(f'SELECT id,is_current,status FROM `{db}`.academic_years ORDER BY id; SELECT academic_year_id,status FROM `{db}`.class_enrollments ORDER BY academic_year_id;')


@pytest.mark.parametrize(('issue', 'fragment'), [
    ('missing_dates', 'no start/end dates'), ('inverted_dates', 'after end date'),
    ('outside_window', 'outside this year'), ('no_semesters', 'no semesters'),
    ('bad_weights', 'weights total')])
def test_invalid_target_requires_explicit_fresh_confirmation(fixture_db, issue, fragment):
    db, project = fixture_db
    _mysql(f'INSERT INTO `{db}`.users VALUES (1);')
    statements = {
        'missing_dates': "UPDATE academic_years SET start_date=NULL WHERE id=2",
        'inverted_dates': "UPDATE academic_years SET start_date='2030-02-01',end_date='2030-01-01' WHERE id=2",
        'outside_window': "UPDATE academic_years SET start_date=DATE_ADD(CURDATE(), INTERVAL 2 YEAR),end_date=DATE_ADD(CURDATE(), INTERVAL 3 YEAR) WHERE id=2",
        'no_semesters': "DELETE FROM academic_terms WHERE academic_year_id=2",
        'bad_weights': "UPDATE academic_years SET s1_weight_pct=60,s2_weight_pct=50 WHERE id=2",
    }
    _mysql(f'USE `{db}`; ' + statements[issue] + ';')
    before = state(db)
    first = run_rollover(db, project)
    assert first.get('code') == 'confirm_rollover_invalid', first
    assert any(fragment in item.lower() for item in first['details']), first
    assert state(db) == before
    missing_phrase = run_rollover(db, project, ROLLOVER_FINGERPRINT=first['issues_fingerprint'])
    assert missing_phrase.get('code') == 'confirm_rollover_invalid' and state(db) == before
    accepted = run_rollover(db, project, ROLLOVER_FINGERPRINT=first['issues_fingerprint'], ROLLOVER_CONFIRM_ISSUES='ACTIVATE ANYWAY')
    assert accepted['status'] == 'success', accepted


def test_stale_acknowledgement_rejected_and_changed_issue_represented(fixture_db):
    db, project = fixture_db
    _mysql(f"INSERT INTO `{db}`.users VALUES (1); UPDATE `{db}`.academic_years SET start_date=NULL WHERE id=2;")
    first = run_rollover(db, project)
    assert first.get('code') == 'confirm_rollover_invalid', first
    before = state(db)
    _mysql(f"UPDATE `{db}`.academic_years SET s1_weight_pct=60 WHERE id=2;")
    second = run_rollover(db, project, ROLLOVER_FINGERPRINT=first['issues_fingerprint'], ROLLOVER_CONFIRM_ISSUES='ACTIVATE ANYWAY')
    assert second.get('code') == 'confirm_rollover_invalid' and second['issues_fingerprint'] != first['issues_fingerprint']
    assert state(db) == before


def test_unavailable_semester_validation_fails_closed(fixture_db):
    db, project = fixture_db
    _mysql(f'INSERT INTO `{db}`.users VALUES (1); DROP TABLE `{db}`.academic_terms;')
    before = state(db)
    result = run_rollover(db, project)
    assert result['status'] == 'error' and state(db) == before


def test_stale_ack_rejected_even_when_wording_is_unchanged(fixture_db):
    db, project = fixture_db
    _mysql(f"INSERT INTO `{db}`.users VALUES (1); UPDATE `{db}`.academic_years SET start_date=NULL,end_date='2030-01-01' WHERE id=2;")
    first = run_rollover(db, project)
    before = state(db)
    _mysql(f"UPDATE `{db}`.academic_years SET end_date='2031-01-01' WHERE id=2;")
    second = run_rollover(db, project, ROLLOVER_FINGERPRINT=first['issues_fingerprint'], ROLLOVER_CONFIRM_ISSUES='ACTIVATE ANYWAY')
    assert second['code'] == 'confirm_rollover_invalid'
    assert first['details'] == second['details'] and first['issues_fingerprint'] != second['issues_fingerprint']
    assert state(db) == before


def test_stale_ack_cannot_authorize_a_different_mode(fixture_db):
    db, project = fixture_db
    _mysql(f"""USE `{db}`;
        INSERT INTO users VALUES (1);
        UPDATE academic_years SET start_date=NULL WHERE id=2;
        ALTER TABLE members ADD current_class_id INT, ADD promoted_at DATE, ADD academic_status VARCHAR(30);
        ALTER TABLE class_enrollments ADD promoted_from INT;
        CREATE TABLE classes (id INT PRIMARY KEY,level_order INT,is_active INT) ENGINE=InnoDB;
        INSERT INTO classes VALUES (10,1,1),(20,2,1);
    """)
    first = run_rollover(db, project)
    before = state(db)
    second = run_rollover(db, project, ROLLOVER_MODE='promote', ROLLOVER_FINGERPRINT=first['issues_fingerprint'], ROLLOVER_CONFIRM_ISSUES='ACTIVATE ANYWAY')
    assert second['code'] == 'confirm_rollover_invalid' and state(db) == before


@pytest.mark.parametrize('denial', ['ROLLOVER_ROLE', 'ROLLOVER_CSRF'])
def test_warning_ack_never_bypasses_role_or_csrf(fixture_db, denial):
    db, project = fixture_db
    _mysql(f"INSERT INTO `{db}`.users VALUES (1); UPDATE `{db}`.academic_years SET start_date=NULL WHERE id=2;")
    first = run_rollover(db, project)
    before = state(db)
    env = {denial: 'teacher' if denial == 'ROLLOVER_ROLE' else 'wrong'}
    response = run_rollover(db, project, ROLLOVER_FINGERPRINT=first['issues_fingerprint'], ROLLOVER_CONFIRM_ISSUES='ACTIVATE ANYWAY', **env)
    assert response['status'] == 'error' and state(db) == before


def test_warning_is_not_offered_when_rollback_is_not_confirmed(fixture_db):
    db, project = fixture_db
    _mysql(f"INSERT INTO `{db}`.users VALUES (1); UPDATE `{db}`.academic_years SET start_date=NULL WHERE id=2;")
    response = run_rollover(db, project, ROLLOVER_FAILURE='rollback')
    assert response['status'] == 'error' and 'outcome could not be confirmed' in response['message']
    assert 'issues_fingerprint' not in response and 'needs_confirmation' not in response
