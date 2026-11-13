"""Destructive UI regression; explicit disposable local MariaDB only."""
import os
import re
import subprocess
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: explicitly disposable local audit world required')
SOCKET = os.environ.get('SSMS_ROLLOVER_FIXTURE_SOCKET', '')
if not re.fullmatch(r'/home/user/\.cache/strict-preparation-db\.[A-Za-z0-9]+/mysql\.sock', SOCKET) or not Path(SOCKET).is_socket():
    sys.exit('Refusing: explicit disposable fixture socket required')

def sql(q):
    return subprocess.run(['mariadb','--no-defaults','--socket',SOCKET,'--user=root','-N','-B'], input=q,
                          text=True,capture_output=True,check=True,timeout=20).stdout.strip()
assert sql('SELECT @@datadir') == str(Path(SOCKET).parent / 'data') + '/'
assert sql("SELECT COUNT(*) FROM ssms_test.academic_years WHERE status='active'") == '1'
old = int(sql("SELECT id FROM ssms_test.academic_years WHERE status='active'"))
sql("INSERT INTO ssms_test.academic_years (year_name,is_current,status,start_date,end_date) VALUES ('Synthetic <img src=x onerror=alert(1)> activation',0,'upcoming',NULL,NULL)")
target = int(sql("SELECT id FROM ssms_test.academic_years WHERE year_name LIKE 'Synthetic <img% activation'"))

def snapshot():
    return sql(f'SELECT id,is_current,status FROM ssms_test.academic_years WHERE id IN ({old},{target}) ORDER BY id')

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={'width':1366,'height':900})
    page.goto('http://127.0.0.1:8080/admin/index.php')
    page.fill('#username','audit_school'); page.fill('#password','AuditTest#2026')
    page.click('button[type=submit]'); page.wait_for_load_state('networkidle')
    page.goto('http://127.0.0.1:8080/admin/dashboards/school_admin.php'); page.wait_for_load_state('networkidle')
    assert page.locator('#roOld').count() == 1, page.url
    page.evaluate('''([id,name])=>{window._yearData=window._yearData||{};window._yearData[id]={id:id,year_name:name,status:'upcoming',is_current:0};}''', [target,'Synthetic <img src=x onerror=alert(1)> activation'])
    before = snapshot()
    def finish(phrase):
        page.locator('[role=alertdialog]').wait_for(state='visible')
        page.click('[data-dcx-act=next]'); page.click('[data-dcx-act=next]')
        assert page.locator('[data-dcx-act=final]').is_disabled()
        page.fill('.dcx-in',phrase); page.click('[data-dcx-act=final]')
    def start():
        page.evaluate('(id)=>{setCurrentYear(id)}',target)
        finish('SWITCH')
        page.wait_for_function("()=>{let d=document.querySelector('[role=alertdialog]');return d&&d.innerText.includes('with warnings')}")
        page.click('[data-dcx-act=next]')
        text=page.locator('[role=alertdialog]').inner_text().lower()
        assert 'no students' in text and 'no start/end dates' in text and 'no semesters' in text, text
        assert page.locator('[role=alertdialog] img').count() == 0
    start(); page.click('[data-dcx-act=cancel]'); assert snapshot()==before
    print('PASS combined empty and structural warnings, escaped and cancellation is nonmutating')
    start(); page.click('[data-dcx-act=next]'); page.fill('.dcx-in','ACTIVATE ANYWAY')
    sql(f"UPDATE ssms_test.academic_years SET end_date='2030-01-01' WHERE id={target}")
    page.click('[data-dcx-act=final]')
    page.wait_for_function("()=>{let d=document.querySelector('[role=alertdialog]');return d&&d.innerText.includes('Year settings or the active year changed')}")
    assert snapshot()==before
    page.click('[data-dcx-act=cancel]')
    print('PASS stale acknowledgement reopens current warnings without mutation')
    start(); page.click('[data-dcx-act=next]'); page.fill('.dcx-in','ACTIVATE ANYWAY'); page.click('[data-dcx-act=final]')
    page.wait_for_function("()=>document.body.innerText.includes('Active year changed')",timeout=15000)
    assert sql(f'SELECT status FROM ssms_test.academic_years WHERE id={target}')=='active'
    assert sql(f'SELECT status FROM ssms_test.academic_years WHERE id={old}')=='closed'
    print('PASS explicit acknowledgement of unchanged warnings permits activation')
    # Actually commit at the server, then drop its HTTP response. The UI must
    # not say the switch failed or encourage a blind retry.
    sql("INSERT INTO ssms_test.academic_years (year_name,is_current,status,start_date,end_date) VALUES ('Synthetic response loss',0,'upcoming',DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 30 DAY))")
    lost = int(sql("SELECT id FROM ssms_test.academic_years WHERE year_name='Synthetic response loss'"))
    sql(f"INSERT INTO ssms_test.academic_terms (academic_year_id,term_name,term_number,is_current) VALUES ({lost},'Synthetic term',1,0)")
    page.evaluate("id=>{window._yearData[id]={id:id,year_name:'Synthetic response loss',status:'upcoming',is_current:0}}",lost)
    forwarded = []
    def drop_response(route):
        req=route.request
        if req.method=='POST' and 'ACTIVATE EMPTY' in (req.post_data or ''):
            response=route.fetch(); forwarded.append(response.status); route.abort('failed')
        else: route.continue_()
    page.route('**/admin/api_education.php', drop_response)
    page.evaluate('(id)=>{setCurrentYear(id)}',lost); finish('SWITCH')
    page.wait_for_function("()=>{let d=document.querySelector('[role=alertdialog]');return d&&d.innerText.includes('Activate EMPTY')}")
    finish('ACTIVATE EMPTY')
    page.wait_for_function("()=>document.body.innerText.includes('year switch outcome is unknown')",timeout=15000)
    assert forwarded == [200]
    assert sql(f'SELECT status FROM ssms_test.academic_years WHERE id={lost}')=='active'
    print('PASS real server commit with lost response reports unknown, not failure')
    browser.close()
