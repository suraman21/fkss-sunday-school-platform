"""Exercise the actual assessment-copy action using a synthetic SQL/session world."""
import json
import os
from pathlib import Path
import shutil
import subprocess

import pytest

from test_year_rollover_atomic import ROOT, _mysql, fixture_db


@pytest.fixture
def assessment_world(fixture_db):
    db, project = fixture_db
    _mysql(f"""USE `{db}`;
        CREATE TABLE academic_terms (id INT PRIMARY KEY, academic_year_id INT NOT NULL, term_number INT NOT NULL) ENGINE=InnoDB;
        CREATE TABLE assessments (id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL, subject_id INT NOT NULL, academic_year_id INT NOT NULL, term_id INT NULL, assessment_name VARCHAR(100), assessment_type VARCHAR(30), weight_percentage DECIMAL(5,2), max_score DECIMAL(6,2), description TEXT, assessment_order INT, created_by INT, created_at DATETIME) ENGINE=InnoDB;
        INSERT INTO academic_terms VALUES (11,1,1),(21,2,1);
        INSERT INTO assessments (class_id,subject_id,academic_year_id,term_id,assessment_name,assessment_type,weight_percentage,max_score,description,assessment_order,created_by,created_at)
            VALUES (10,3,1,11,'Exam','exam',20.25,79.50,'Synthetic description',1,1,NOW()),(10,3,1,NULL,'Legacy','quiz',10,50,NULL,2,1,NOW());
    """)
    shutil.copytree(ROOT / 'admin/backend', project / 'admin/backend', dirs_exist_ok=True)
    shutil.copyfile(ROOT / 'admin/api_education.php', project / 'admin/api_education.php')
    (project / 'admin/config.php').write_text(r'''<?php
if (PHP_SAPI !== 'cli' || getenv('SSMS_AUDIT_TESTING') !== '1') exit(90);
define('DB_HOST', 'synthetic-only');
mysqli_report(getenv('COPY_STRICT') === '1' ? MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);
$socket = getenv('SSMS_ROLLOVER_FIXTURE_SOCKET') ?: null;
$conn = new mysqli($socket ? 'localhost' : '127.0.0.1', 'root', $socket ? '' : 'root', getenv('ROLLOVER_FIXTURE_DB'), $socket ? 0 : 3306, $socket);
$_SESSION = ['admin_id'=>1,'admin_role'=>getenv('COPY_ROLE') ?: 'edu_dept','csrf_token'=>'fixture'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action'=>'copy_assessments','source_year_id'=>1,'target_year_id'=>2,'confirm'=>'COPY','csrf_token'=>getenv('COPY_CSRF') ?: 'fixture'];
$_REQUEST = $_POST;
function validateCsrf($value) { return hash_equals($_SESSION['csrf_token'], $value); }
function requirePostActions($action, $actions) { if (in_array($action, $actions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') exit(91); }
function ay_year_by_id($conn, $id) { $r=$conn->query('SELECT id,status FROM academic_years WHERE id='.(int)$id); return $r ? $r->fetch_assoc() : null; }
function ay_is_readonly($conn) { return getenv('COPY_READONLY') === '1'; }
''')
    yield db, project


def invoke(db, project, **extra):
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, **extra)
    result = subprocess.run(['php', str(project / 'admin/api_education.php')], env=env,
                            text=True, capture_output=True, check=True, timeout=30)
    return json.loads(result.stdout)


@pytest.mark.parametrize('strict', ['0', '1'])
def test_copy_maps_matching_term_and_preserves_legacy_null_and_is_idempotent(assessment_world, strict):
    db, project = assessment_world
    first = invoke(db, project, COPY_STRICT=strict)
    assert first['status'] == 'success' and first['copied'] == 2 and first['skipped'] == 0, first
    rows = _mysql(f"SELECT assessment_name,COALESCE(term_id,'NULL') FROM `{db}`.assessments WHERE academic_year_id=2 ORDER BY assessment_name;").splitlines()
    assert rows == ['Exam\t21', 'Legacy\tNULL']
    details = _mysql(f"SELECT weight_percentage,max_score,description,assessment_order FROM `{db}`.assessments WHERE academic_year_id=2 AND assessment_name='Exam';")
    assert details == '20.25\t79.50\tSynthetic description\t1'
    second = invoke(db, project, COPY_STRICT=strict)
    assert second['status'] == 'success' and second['copied'] == 0 and second['skipped'] == 2, second


@pytest.mark.parametrize('case', ['missing_target', 'orphan_source', 'duplicate_target'])
@pytest.mark.parametrize('strict', ['0', '1'])
def test_incomplete_or_ambiguous_term_mapping_rolls_back(assessment_world, case, strict):
    db, project = assessment_world
    if case == 'missing_target':
        _mysql(f"DELETE FROM `{db}`.academic_terms WHERE id=21; UPDATE `{db}`.assessments SET id=3 WHERE assessment_name='Exam'; UPDATE `{db}`.assessments SET id=1 WHERE assessment_name='Legacy'; UPDATE `{db}`.assessments SET id=2 WHERE assessment_name='Exam';")
    if case == 'orphan_source': _mysql(f'UPDATE `{db}`.assessments SET term_id=99 WHERE assessment_name=\'Exam\';')
    if case == 'duplicate_target': _mysql(f'INSERT INTO `{db}`.academic_terms VALUES (22,2,1);')
    result = invoke(db, project, COPY_STRICT=strict)
    assert result['status'] == 'error', result
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.assessments WHERE academic_year_id=2;') == '0'


@pytest.mark.parametrize(('role', 'csrf', 'readonly'), [('teacher','fixture','0'), ('edu_dept','bad','0'), ('edu_dept','fixture','1')])
def test_copy_does_not_bypass_role_csrf_or_readonly(assessment_world, role, csrf, readonly):
    db, project = assessment_world
    result = invoke(db, project, COPY_ROLE=role, COPY_CSRF=csrf, COPY_READONLY=readonly)
    assert result['status'] == 'error'
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.assessments WHERE academic_year_id=2;') == '0'


def test_simultaneous_copy_requests_do_not_duplicate_templates(assessment_world):
    db, project = assessment_world
    env = dict(os.environ, SSMS_AUDIT_TESTING='1', ROLLOVER_FIXTURE_DB=db, COPY_STRICT='1')
    children = [subprocess.Popen(['php', str(project / 'admin/api_education.php')], env=env,
                                 text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE) for _ in range(2)]
    outputs = [child.communicate(timeout=30) for child in children]
    assert all(child.returncode == 0 for child in children), outputs
    responses = [json.loads(stdout) for stdout, stderr in outputs]
    assert all(r['status'] == 'success' for r in responses), responses
    assert sorted(r['copied'] for r in responses) == [0, 2]
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.assessments WHERE academic_year_id=2;') == '2'


def test_copy_refuses_to_write_into_closed_year(assessment_world):
    db, project = assessment_world
    _mysql(f"UPDATE `{db}`.academic_years SET status='closed' WHERE id=2;")
    result = invoke(db, project)
    assert result['status'] == 'error' and 'closed' in result['message']
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.assessments WHERE academic_year_id=2;') == '0'


@pytest.mark.parametrize('strict', ['0', '1'])
def test_missing_assessment_column_fails_without_partial_copy(assessment_world, strict):
    db, project = assessment_world
    _mysql(f'ALTER TABLE `{db}`.assessments DROP COLUMN description;')
    result = invoke(db, project, COPY_STRICT=strict)
    assert result['status'] == 'error'
    assert _mysql(f'SELECT COUNT(*) FROM `{db}`.assessments WHERE academic_year_id=2;') == '0'
