"""Destructive browser regression on a freshly seeded, local disposable DB only."""
import os
import re
from pathlib import Path
import subprocess
import sys
from playwright.sync_api import sync_playwright

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: explicitly disposable local audit world required')
BASE = 'http://127.0.0.1:8080'  # intentionally fixed, never hosted
SOCKET = os.environ.get('SSMS_ROLLOVER_FIXTURE_SOCKET', '')
if not re.fullmatch(r'/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock', SOCKET) or not Path(SOCKET).is_socket():
    sys.exit('Refusing: explicit disposable fixture socket required')
# Refuse any server not using this audit's disposable data directory.
def sql(query):
    args=['mariadb','--no-defaults','--socket',SOCKET,'--user=root','--password=root','-N','-B']
    return subprocess.run(args, input=query, text=True, capture_output=True, check=True, timeout=20).stdout.strip()
assert sql('SELECT @@datadir') == str(Path(SOCKET).parent / 'data') + '/'
assert sql("SELECT COUNT(*) FROM ssms_test.academic_years WHERE status='active'") == '1'
old_id = int(sql("SELECT id FROM ssms_test.academic_years WHERE status='active'"))
sql("INSERT INTO ssms_test.academic_years (year_name,is_current,status,start_date,end_date) VALUES ('Synthetic rollout validation',0,'upcoming',NULL,NULL)")
new_id = int(sql("SELECT id FROM ssms_test.academic_years WHERE year_name='Synthetic rollout validation'"))

def snapshot():
    return sql(f"SELECT id,is_current,status FROM ssms_test.academic_years WHERE id IN ({old_id},{new_id}) ORDER BY id")

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={'width': 1366, 'height': 900})
    page.goto(BASE+'/admin/index.php')
    page.fill('#username','audit_school'); page.fill('#password','AuditTest#2026')
    page.click('button[type=submit]'); page.wait_for_load_state('networkidle')
    page.goto(BASE+'/admin/dashboards/school_admin.php'); page.wait_for_load_state('networkidle')
    assert page.locator('#roOld').count() == 1, page.url
    page.evaluate('openRolloverModal()')
    page.wait_for_function("(id)=>[...document.querySelector('#roNew').options].some(o=>o.value==id)", arg=str(new_id))
    page.select_option('#roOld', str(old_id)); page.select_option('#roNew', str(new_id))
    before = snapshot()
    def finish(phrase):
        page.locator('[role=alertdialog]').wait_for(state='visible')
        page.click('[data-dcx-act=next]'); page.click('[data-dcx-act=next]')
        assert page.locator('[data-dcx-act=final]').is_disabled()
        page.fill('.dcx-in',phrase); page.click('[data-dcx-act=final]')
    def first_step():
        page.click('#roRunBtn'); finish('ROLLOVER')
        page.wait_for_function("()=>{let d=document.querySelector('[role=alertdialog]');return d&&d.innerText.includes('with year issues')}")
        page.click('[data-dcx-act=next]')
        body=page.locator('[role=alertdialog]').inner_text().lower()
        assert 'no start/end dates' in body and 'no semesters' in body, body
    first_step()
    page.click('[data-dcx-act=cancel]')
    assert snapshot() == before
    print('PASS invalid target explains dates and semesters; cancellation is non-mutating')
    first_step()
    page.click('[data-dcx-act=next]')
    assert page.locator('[data-dcx-act=final]').is_disabled()
    page.fill('.dcx-in','ACTIVATE ANYWAY')
    sql(f"UPDATE ssms_test.academic_years SET end_date='2030-01-01' WHERE id={new_id}")
    page.click('[data-dcx-act=final]')
    page.wait_for_function("()=>document.body.innerText.includes('Target year settings changed')")
    assert snapshot() == before
    print('PASS stale confirmation rejected after target changed')
    first_step()
    page.click('[data-dcx-act=next]'); page.fill('.dcx-in','ACTIVATE ANYWAY')
    page.click('[data-dcx-act=final]')
    page.wait_for_function("()=>document.body.innerText.includes('Rollover complete')", timeout=15000)
    assert sql(f'SELECT status FROM ssms_test.academic_years WHERE id={old_id}') == 'closed'
    assert sql(f'SELECT status FROM ssms_test.academic_years WHERE id={new_id}') == 'active'
    print('PASS explicitly acknowledged unchanged issues permit rollover')
    browser.close()
