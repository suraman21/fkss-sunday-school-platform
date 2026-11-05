"""Live API test for danger-confirmation DC-2 (Education + School Admin + Subjects).
Runs against a LOCAL test server only (default http://127.0.0.1:8080, seeded by
tests/e2e/provision_env.sh). Never point this at production.

usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/danger_confirm_dc2_api.py [base_url]
"""
import os, re, sys, json, time
import requests

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: set SSMS_AUDIT_TESTING=1 (local disposable test DB only).')
BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080').rstrip('/')
if 'felegekidusan' in BASE:
    sys.exit('Refusing to run against production.')
PW = 'AuditTest#2026'
PASS = FAIL = 0


def ck(name, cond, info=''):
    global PASS, FAIL
    if cond:
        PASS += 1; print('PASS', name)
    else:
        FAIL += 1; print('FAIL', name, '::', str(info)[:300])


def login(user, page):
    s = requests.Session()
    h = s.get(BASE + '/admin/index.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
    s.post(BASE + '/admin/backend/login.php', data={'username': user, 'password': PW, 'csrf_token': tok}, allow_redirects=False)
    h = s.get(BASE + page).text
    m = re.search(r"CSRF(?:_TOKEN)?\s*=\s*'([a-f0-9]{20,})'", h)
    s.csrf = m.group(1) if m else ''
    return s


def post(s, url, **data):
    data.setdefault('csrf_token', s.csrf)
    r = s.post(BASE + url, data=data)
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {'raw': r.text[:200]}


def get(s, url):
    r = s.get(BASE + url)
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {'raw': r.text[:200]}


EDU = '/admin/api_education.php'
SUB = '/admin/api_subjects.php'
edu = login('audit_edu', '/admin/dashboards/edu_dept.php')
ck('edu login + csrf', bool(edu.csrf))

# ── delete_class ──
tag = str(int(time.time()))[-6:]
code, d = post(edu, EDU, action='save_class', class_name='DC2 Test ' + tag, class_name_en='DC2 Test ' + tag,
               class_code='DC2' + tag, level_order=99, section='', age_group='', description='', is_active=1)
ck('create throwaway class', d.get('status') == 'success', d)
cid = d.get('id') or d.get('class_id')
if not cid:
    code, lst = get(edu, EDU + '?action=get_classes')
    for c in (lst.get('classes') or lst.get('data') or []):
        if c.get('class_name') == 'DC2 Test ' + tag:
            cid = c['id']
ck('throwaway class id', bool(cid), cid)

code, d = get(edu, EDU + f'?action=impact_delete_class&class_id={cid}')
ck('impact_delete_class (empty class) -> mode delete', d.get('status') == 'success' and d.get('mode') == 'delete', d)
code, d = get(edu, EDU + '?action=impact_delete_class&class_id=1')
ck('impact_delete_class returns counts', d.get('status') == 'success' and isinstance(d.get('counts'), dict), d)

code, d = post(edu, EDU, action='delete_class', class_id=cid, id=cid)
ck('delete_class without phrase -> 409 confirm_phrase_required', code == 409 and d.get('code') == 'confirm_phrase_required', (code, d))
code, d = post(edu, EDU, action='delete_class', class_id=cid, id=cid, confirm_phrase='DELETE')
ck('delete_class wrong phrase -> 409', code == 409, (code, d))
code, d = post(edu, EDU, action='delete_class', class_id=cid, id=cid, confirm_phrase='  delete   class ')
ck('delete_class correct phrase (case/space tolerant) -> success', d.get('status') == 'success', (code, d))

# ── delete_term ──
code, d = get(edu, EDU + '?action=impact_delete_term&term_id=1')
ck('impact_delete_term (seeded term has an assessment) -> blocked', d.get('status') == 'success' and d.get('blocked') is True, d)
code, d = post(edu, EDU, action='delete_term', term_id=1)
ck('delete_term without phrase -> 409', code == 409 and d.get('code') == 'confirm_phrase_required', (code, d))
code, d = post(edu, EDU, action='delete_term', term_id=1, confirm_phrase='DELETE SEMESTER')
ck('F1: delete_term of a used semester refused (term_in_use)', d.get('status') == 'error' and d.get('code') == 'term_in_use', (code, d))

# ── retired sync_member_types: even the old phrase cannot re-enable it ──
for phrase in ('', 'SYNC TYPES'):
    code, d = post(edu, EDU, action='sync_member_types', confirm_phrase=phrase)
    ck(f'sync_member_types retired ({phrase!r}) -> 410 manual-only',
       code == 410 and d.get('code') == 'member_type_manual_only', (code, d))

# ── subjects ──
code, d = post(edu, SUB, action='delete_subject', subject_id=1)
ck('delete_subject without phrase -> 409', code == 409 and d.get('code') == 'confirm_phrase_required', (code, d))
code, d = post(edu, SUB, action='apply_assessment_template', items='[]')
ck('apply_assessment_template without phrase -> 409', code == 409, (code, d))
code, d = post(edu, SUB, action='apply_assessment_template', items='[]', confirm_phrase='APPLY SCHEME')
ck('apply_assessment_template with phrase passes the guard (then normal validation)', code != 409 and d.get('code') != 'confirm_phrase_required', (code, d))

# ── DC-1 timetable rules, live ──
TT = '/admin/api_timetable.php'
code, d = post(edu, TT, **{'action': 'save_days', 'class_id': 1, 'days[]': ['6', '7']}, confirm_phrase='REMOVE DAY')
ck('timetable: set days 6,7 (with phrase)', d.get('status') == 'success', (code, d))
code, d = post(edu, TT, **{'action': 'save_days', 'class_id': 1, 'days[]': ['6', '7', '3']})
ck('timetable: ADDING a day needs no phrase', d.get('status') == 'success', (code, d))
code, d = post(edu, TT, **{'action': 'save_days', 'class_id': 1, 'days[]': ['6', '7']})
ck('timetable: REMOVING a day without phrase -> 409', code == 409 and d.get('code') == 'confirm_phrase_required', (code, d))
code, d = post(edu, TT, **{'action': 'save_days', 'class_id': 1, 'days[]': ['6', '7']}, confirm_phrase='remove day')
ck('timetable: removing a day with phrase -> success', d.get('status') == 'success', (code, d))

# ── role boundaries ──
teach = login('audit_teach', '/admin/dashboards/teacher.php')
code, d = get(teach, EDU + '?action=impact_delete_class&class_id=1')
ck('teacher cannot read impact_delete_class (403)', code == 403, (code, d))
code, d = get(teach, EDU + '?action=impact_delete_term&term_id=1')
ck('teacher cannot read impact_delete_term (403)', code == 403, (code, d))

sch = login('audit_school', '/admin/dashboards/school_admin.php')
ck('school admin login + csrf', bool(sch.csrf))
code, d = post(sch, EDU, action='delete_term', term_id=1)
ck('school admin delete_term without phrase -> 409', code == 409, (code, d))
yname = 'DC2-' + tag
code, d = post(sch, EDU, action='save_academic_year', year_name=yname, ec_year=2090, year_gc='2097/98',
               start_date='2097-09-11', end_date='2098-07-30', is_current=0)
code, yl = get(sch, EDU + '?action=get_academic_years')
yid = next((y['id'] for y in (yl.get('years') or yl.get('data') or []) if y.get('year_name') == yname), None)
ck('create throwaway upcoming year', bool(yid), (d, yl if not yid else ''))
code, d = post(sch, EDU, action='set_current_year', year_id=yid, confirm='NOPE')
ck('set_current_year with wrong phrase refused (server, unchanged)', d.get('status') != 'success', (code, d))
code, d = post(sch, EDU, action='delete_year', year_id=yid)
ck('cleanup: delete throwaway empty year', d.get('status') == 'success', (code, d))

# ── pages render and load the component ──
for s, page in ((edu, '/admin/dashboards/edu_dept.php'), (sch, '/admin/dashboards/school_admin.php')):
    h = s.get(BASE + page).text
    ck(f'{page} loads danger_confirm.js', 'danger_confirm.js' in h)
r = requests.get(BASE + '/admin/js/danger_confirm.js')
ck('component served', r.status_code == 200 and 'DangerConfirm' in r.text)

print(f'\nRESULT: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
