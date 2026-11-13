"""Disconnected-response UX, against the explicitly disposable local world only."""
import os
import sys
from playwright.sync_api import sync_playwright

if os.getenv('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: disposable local audit world required')
base = 'http://127.0.0.1:8080'  # intentionally not overridable
with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page()
    page.goto(base + '/admin/index.php')
    page.fill('#username', 'audit_school')
    page.fill('#password', 'AuditTest#2026')
    page.click('button[type=submit]')
    page.wait_for_load_state('networkidle')
    page.goto(base + '/admin/dashboards/school_admin.php')
    page.wait_for_load_state('networkidle')
    assert page.locator('#roOld').count() == 1, 'Synthetic school admin login failed: ' + page.url
    page.route('**/admin/tools/year_rollover.php', lambda r: r.abort() if r.request.method == 'POST' else r.continue_())
    page.route('**/admin/api_education.php', lambda r: r.abort() if r.request.method == 'POST' else r.continue_())
    page.evaluate('''() => {
      window.dcAsk = async (options) => { window.auditOptions = options; return true; };
      window.toast = (message) => { window.auditToast = message; };
      for (const id of ['roOld','roNew','caSource','caTarget']) {
        document.getElementById(id).innerHTML = '<option value="1">Old</option><option value="2">New</option>';
      }
      document.getElementById('roNew').value = '2';
      document.getElementById('caTarget').value = '2';
    }''')
    for function, button, expected in [('runRollover', 'roRunBtn', 'years and enrollments'),
                                        ('runCopyAssessments', 'caRunBtn', 'target assessments')]:
        page.evaluate(f'async () => await {function}()')
        message = page.evaluate('window.auditToast')
        assert 'outcome may be unknown' in message and 'Do not retry' in message and expected in message, message
        assert not page.locator('#' + button).is_disabled()
        print('PASS', function, 'response-loss warning and button recovery')
    page.evaluate("document.querySelector('input[name=roMode][value=promote]').checked = true")
    page.evaluate('async () => await runRollover()')
    options = page.evaluate('window.auditOptions')
    assert 'manual placement' in options['summary']
    assert any('graduate' in item and 'skipped' in item and 'manual placement' in item for item in options['consequences'])
    print('PASS promotion confirmation explains graduation and skipped placements')
    browser.close()
