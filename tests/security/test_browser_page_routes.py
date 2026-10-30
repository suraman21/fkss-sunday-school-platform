"""Finite clean browser URLs: real handler identity and legacy compatibility."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def request(base, path, data=None, headers=None):
    opener = urllib.request.build_opener(NoRedirect())
    req = urllib.request.Request(base + path, data=data, headers=headers or {})
    try:
        response = opener.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.code, dict(response.headers), response.read()


class BrowserPageRouteTests(unittest.TestCase):
    def test_executable_policy_registry_and_identity(self):
        php = shutil.which('php')
        if php is None:
            self.skipTest('PHP CLI is required')
        run = subprocess.run([php, str(ROOT / 'tests/fixtures/browser_page_routes.fixture'), str(ROOT)],
                             capture_output=True, text=True, timeout=20)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        result = json.loads(run.stdout)
        self.assertEqual(result['status'], 'PASS')
        self.assertGreaterEqual(result['checks'], 650)
        pages = result['pages']
        self.assertGreaterEqual(len(pages), 35)
        for clean, legacy in pages.items():
            self.assertEqual(Path(clean).parent, Path(legacy).parent)
            self.assertTrue((ROOT / legacy.lstrip('/')).is_file())
            self.assertNotIn('.php', clean)
            if clean != '/':
                self.assertFalse((ROOT / clean.lstrip('/')).exists(), clean)
            self.assertNotIn('/api', clean)
            self.assertNotIn('/backend/', legacy)
            self.assertNotIn('public_gallery', legacy)  # despite name, this is an API

    def test_dispatcher_uses_only_registry_and_restores_identity_before_include(self):
        code = (ROOT / 'browser_page.php').read_text()
        self.assertIn('BrowserPageRoutes::dispatchIdentity', code)
        self.assertIn('http_response_code(404)', code)
        self.assertIn('$_SERVER = $identity', code)
        self.assertIn("chdir(dirname($_SERVER['SCRIPT_FILENAME']))", code)
        self.assertLess(code.index('$_SERVER = $identity'), code.index("require $_SERVER['SCRIPT_FILENAME']"))
        self.assertNotIn('$_GET', code)
        self.assertNotIn('$_POST', code)

    def test_apache_denials_precede_finite_dispatch_not_generic_php_guessing(self):
        for path in ['.htaccess', 'admin/.htaccess']:
            source = (ROOT / path).read_text()
            self.assertIn('Options -Indexes -MultiViews', source)
            self.assertIn('FKSS_BROWSER_ROUTES:1', source)
            self.assertIn('browser_page.php [END]', source)
            self.assertLess(source.index('[F,L'), source.index('browser_page.php [END]'))
            self.assertIn('%{REQUEST_FILENAME} !-f', source)
            self.assertIn('%{REQUEST_FILENAME} !-d', source)
            self.assertNotIn('$1.php', source)
        bootstrap = (ROOT / 'config.php').read_text()
        self.assertLess(bootstrap.index('BrowserPageRoutes::navigationRedirect'), bootstrap.index('$_envFileNames'))
        self.assertIn("header('Cache-Control: no-store')", bootstrap)
        self.assertIn("header('Location: ' . $browserPageLocation, true, 302)", bootstrap)

    def test_live_aliases_keep_role_gates_and_post_csrf(self):
        if os.environ.get('SSMS_AUDIT_TESTING') != '1':
            self.skipTest('Explicit disposable HTTP world required')
        from test_attendance_phase1 import BASE, WebSession, _live_ready
        if not _live_ready():
            self.skipTest('Provisioned local test server required')
        status, headers, _ = request(BASE, '/admin/index.php?next=%2Fadmin%2Fmember&a=1&a=2',
                                     headers={'Accept': 'text/html'})
        self.assertEqual(status, 302)
        self.assertEqual(headers['Location'], '/admin/login?next=%2Fadmin%2Fmember&a=1&a=2')
        for non_document in [{'Accept': 'application/json'},
                             {'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest'}]:
            status, _, _ = request(BASE, '/admin/index.php', headers=non_document)
            self.assertEqual(status, 200)  # no document canonicalization
        status, _, body = request(BASE, '/admin/login', headers={'Accept': 'text/html'})
        self.assertEqual(status, 200, body[:200])
        self.assertIn(b'action="backend/login.php"', body)
        self.assertNotIn(b'<?php', body)
        teacher = WebSession('teacher')
        for clean, old in [('/admin/dashboards/education', '/admin/dashboards/edu_dept.php'),
                           ('/admin/member?id=900001', '/admin/info_manage_member.php?id=900001'),
                           ('/admin/users', '/admin/users.php'),
                           ('/frontend/pages/finance', '/frontend/pages/finance_dept.php')]:
            headers = {'Accept': 'application/json'}
            legacy_status, _ = teacher._open(BASE + old, headers=headers)
            alias_status, body = teacher._open(BASE + clean, headers=headers)
            self.assertEqual(alias_status, legacy_status, body[:200])
            self.assertEqual(alias_status, 403, clean)
        info = WebSession('info_dept')
        status, body = info._open(BASE + '/admin/member?id=900001')
        self.assertEqual(status, 200, body[:200])
        self.assertIn(b'name="member_type"', body)
        rejected = []
        for target in ['/admin/member?id=900001', '/admin/info_manage_member.php?id=900001']:
            status, body = info._open(BASE + target, data=b'csrf_token=invalid')
            result = json.loads(body)
            self.assertEqual(result['status'], 'error')
            self.assertIn('Security token expired', result['message'])
            rejected.append((status, result))
        self.assertEqual(rejected[0], rejected[1])  # preserve the established envelope/status

    def test_live_unknown_or_internal_aliases_do_not_dispatch(self):
        if os.environ.get('SSMS_AUDIT_TESTING') != '1':
            self.skipTest('Explicit disposable HTTP world required')
        from test_attendance_phase1 import BASE, _live_ready
        if not _live_ready():
            self.skipTest('Provisioned local test server required')
        for path in ['/admin/not-a-page', '/admin/config', '/admin/get-schema',
                     '/frontend/pages/api-finance', '/browser_page.php?file=admin/users.php']:
            status, _, _ = request(BASE, path)
            self.assertEqual(status, 404, path)
        status, _, _ = request(BASE, '/api/v1/ping')
        self.assertEqual(status, 200)


if __name__ == '__main__':
    unittest.main()
