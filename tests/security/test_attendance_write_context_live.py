"""Opt-in real HTTP/auth/SQL coverage in the existing isolated CI world only."""
import json
import os
import unittest
import uuid
import urllib.error
import urllib.parse
import urllib.request
from test_attendance_phase1 import BASE, _live_ready, db, mobile_token


@unittest.skipUnless(os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'Explicit disposable live-guard world required')
class AttendanceWriteContextLiveTests(unittest.TestCase):
    def setUp(self):
        self.assertIn(urllib.parse.urlparse(BASE).hostname, ('localhost', '127.0.0.1'))
        self.token = mobile_token('teacher')
        self.path = '/api/v1/attendance/write-context?class_id=1&date=2026-11-05'

    def request(self, path=None, method='GET', token=None):
        if token is None:
            token = self.token
        headers = {'X-App-Build': '1'}  # strict current authority, even old builds
        if token:
            headers['Authorization'] = 'Bearer ' + token
        req = urllib.request.Request(BASE + (path or self.path), headers=headers, method=method)
        try:
            response = urllib.request.urlopen(req, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            raw = response.read()
            return response.status, json.loads(raw) if raw else None, response.headers

    def snapshot(self):
        return db('CHECKSUM TABLE attendance,grade_submissions,attendance_submission_events,api_idempotency_records,api_sync_attempts')

    def test_real_server_dimensions_no_store_no_business_mutation(self):
        before = self.snapshot()
        status, body, headers = self.request()
        self.assertEqual(status, 200, body)
        data = body['data']
        year = db("SELECT id FROM academic_years WHERE status='active' ORDER BY id DESC LIMIT 1")
        if not year:
            year = db('SELECT id FROM academic_years WHERE is_current=1 ORDER BY id DESC LIMIT 1')
        self.assertTrue(year)
        self.assertEqual(data, {
            'context_version': 1, 'owner_user_id': 3,
            'authorization_version': int(db('SELECT authorization_version FROM users WHERE id=3')),
            'class_id': 1, 'academic_year_id': int(year), 'date': '2026-11-05',
            'authorizes_offline_work': False, 'authorizes_settlement': False,
        })
        self.assertIn('no-store', headers.get('Cache-Control', ''))
        self.assertEqual(self.snapshot(), before)

    def test_real_method_input_subroute_and_identity_denials(self):
        before = self.snapshot()
        for method in ['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD']:
            with self.subTest(method=method):
                status, _, headers = self.request(method=method)
                self.assertEqual(status, 405)
                self.assertEqual(headers.get('Allow'), 'GET')
                self.assertIn('no-store', headers.get('Cache-Control', ''))
        for suffix in ['&academic_year_id=5', '&owner_user_id=99', '&roster_year_id=5']:
            self.assertEqual(self.request(self.path + suffix)[0], 422)
        self.assertEqual(self.request('/api/v1/attendance/write-context')[0], 422)
        self.assertEqual(self.request('/api/v1/attendance/write-context/extra?class_id=1&date=2026-11-05')[0], 404)
        self.assertEqual(self.request(token='')[0], 401)
        self.assertEqual(self.request(token=mobile_token('info_dept'))[0], 403)
        self.assertEqual(self.request(self.path.replace('class_id=1', 'class_id=999999'))[0], 403)
        self.assertEqual(self.snapshot(), before)

    def test_disabled_account_cannot_discover_context_with_an_old_build(self):
        before = self.snapshot()
        try:
            db('UPDATE users SET is_active=0 WHERE id=3')
            status, body, headers = self.request()
            self.assertEqual((status, body['code']), (401, 'ACCOUNT_DISABLED'))
            self.assertIn('no-store', headers.get('Cache-Control', ''))
        finally:
            db('UPDATE users SET is_active=1 WHERE id=3')
        self.assertEqual(self.snapshot(), before)

    def test_changed_authorization_cannot_discover_context_with_an_old_token(self):
        old = int(db('SELECT authorization_version FROM users WHERE id=3'))
        try:
            db('UPDATE users SET authorization_version=authorization_version+1 WHERE id=3')
            status, body, _ = self.request()
            self.assertEqual((status, body['code']), (401, 'AUTH_SCOPE_CHANGED'))
        finally:
            db(f'UPDATE users SET authorization_version={old} WHERE id=3')

    def test_real_roster_fallback_cannot_replace_the_server_write_year(self):
        marker = 'ctx-' + uuid.uuid4().hex
        year = cls = None
        try:
            year = int(db(f"INSERT INTO academic_years (year_name,status,is_current) VALUES ('{marker}','closed',0); SELECT LAST_INSERT_ID()"))
            cls = int(db(f"INSERT INTO classes (class_name,class_code) VALUES ('{marker}','{marker[:28]}'); SELECT LAST_INSERT_ID()"))
            active = int(db("SELECT id FROM academic_years WHERE status='active' ORDER BY id DESC LIMIT 1"))
            db(f"INSERT INTO teacher_assignments (teacher_id,class_id,academic_year_id,is_active) VALUES (3,{cls},{active},1)")
            db(f"INSERT INTO class_enrollments (member_id,class_id,academic_year_id,enrolled_at,status) VALUES (1,{cls},{year},'2000-01-01','active')")
            status, sheet, _ = self.request(f'/api/v1/attendance?class_id={cls}&date=2026-11-05')
            self.assertEqual(status, 200, sheet)
            self.assertIs(sheet['data']['roster_fallback'], True)
            self.assertEqual(sheet['data']['roster_year_id'], year)
            path = f'/api/v1/attendance/write-context?class_id={cls}&date=2026-11-05'
            # Assignment triggers rotate authority. Unlike legacy sheet reads,
            # discovery must refuse the token minted before this new scope.
            stale_status, stale, _ = self.request(path)
            self.assertEqual((stale_status, stale['code']), (401, 'AUTH_SCOPE_CHANGED'))
            self.token = mobile_token('teacher')
            status, body, _ = self.request(path)
            self.assertEqual(status, 200, body)
            self.assertEqual(body['data']['academic_year_id'], active)
            self.assertNotEqual(body['data']['academic_year_id'], sheet['data']['roster_year_id'])
            self.assertIs(body['data']['authorizes_offline_work'], False)
        finally:
            if cls is not None:
                db(f'DELETE FROM class_enrollments WHERE class_id={cls}; DELETE FROM teacher_assignments WHERE class_id={cls}; DELETE FROM classes WHERE id={cls}')
            if year is not None:
                db(f'DELETE FROM academic_years WHERE id={year}')
