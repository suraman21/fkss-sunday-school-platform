"""Negative protocol negotiation is not an acceptance receipt or readiness claim."""
from datetime import date, timedelta
import json
import os
from pathlib import Path
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid
from test_attendance_phase1 import BASE, _live_ready, db, mobile_token

ROOT = Path(__file__).resolve().parents[2]


def test_negative_receipt_contract_is_before_body_claim_replay_and_mutations():
    source = (ROOT / 'api/v1/routes/attendance.php').read_text()
    guard = source.index('if ($receiptVersionRequested)')
    assert "array_key_exists('HTTP_X_ATTENDANCE_RECEIPT_VERSION', $_SERVER)" in source
    assert source.index('apiRevalidateAuthorizationScope($auth, true)') < guard
    assert source.index('apiRolesAttendance()') < guard
    assert guard < source.index('$year = getCurrentAcademicYear()')
    assert guard < source.index('getBody(') < source.index('apiIdempotencyBegin(')
    assert "'durable_receipt_versions' => []" in source
    assert 'ATTENDANCE_RECEIPT_CONTRACT_UNSUPPORTED' in source


@unittest.skipUnless(os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'Explicit disposable live-guard world required')
class AttendanceReceiptNegotiationLiveTests(unittest.TestCase):
    def setUp(self):
        self.assertIn(urllib.parse.urlparse(BASE).hostname, ('localhost', '127.0.0.1'))
        self.day = (date.today() - timedelta(days=12)).isoformat()
        self.scope = f"class_id=1 AND attendance_date='{self.day}'"
        self.packet = self.scope + " AND submission_type='attendance'"
        self.assertEqual(db(f'SELECT COUNT(*) FROM attendance WHERE {self.scope}'), '0')
        self.assertEqual(db(f'SELECT COUNT(*) FROM grade_submissions WHERE {self.packet}'), '0')
        self.key = 'negotiate-http-' + uuid.uuid4().hex
        self.token = mobile_token('teacher')
        status, data, _ = self.request(f'/api/v1/attendance?class_id=1&date={self.day}', method='GET')
        self.assertEqual(status, 200, data)
        self.records = [{'member_id': int(row['member_id']), 'status':'present'} for row in data['data']['students']]
        self.assertTrue(self.records)
        self.payload = {'class_id':1,'date':self.day,'client_op_id':self.key,'records':self.records}

    def tearDown(self):
        db(f"DELETE FROM attendance_submission_events WHERE department='edu' AND packet_id IN (SELECT id FROM grade_submissions WHERE {self.packet})")
        db(f'DELETE FROM attendance WHERE {self.scope}')
        db(f'DELETE FROM grade_submissions WHERE {self.packet}')
        db(f"DELETE FROM api_idempotency_records WHERE idem_key='{self.key}'")

    def request(self, path, method='POST', payload=None, version=None, token=None, extra_headers=None):
        if token is None: token = self.token
        headers = {'Content-Type':'application/json', 'Idempotency-Key':self.key}
        if token: headers['Authorization'] = 'Bearer ' + token
        if version is not None: headers['X-Attendance-Receipt-Version'] = version
        headers.update(extra_headers or {})
        body = getattr(self, 'payload', {}) if payload is None else payload
        raw = body if isinstance(body, bytes) else json.dumps(body).encode()
        req = urllib.request.Request(BASE + path, headers=headers, method=method,
                                     data=None if method in ('GET','OPTIONS','HEAD') else raw)
        try:
            response = urllib.request.urlopen(req, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            raw_response = response.read()
            return response.status, json.loads(raw_response) if raw_response else None, response.headers

    def snapshot(self):
        return db('CHECKSUM TABLE attendance,grade_submissions,attendance_submission_events,api_idempotency_records,api_sync_attempts,security_rate_limits')

    def refused(self, path, version='1', payload=None):
        before = self.snapshot()
        status, data, headers = self.request(path, version=version, payload=payload)
        self.assertEqual(status, 412, data)
        self.assertEqual(data['code'], 'ATTENDANCE_RECEIPT_CONTRACT_UNSUPPORTED')
        self.assertEqual(data['capabilities'], {'version':1,'durable_receipt_versions':[], 'durable':False,'authorizes_settlement':False})
        self.assertIsNone(headers.get('Idempotency-Replayed'))
        self.assertIn('no-store', headers.get('Cache-Control', ''))
        self.assertEqual(self.snapshot(), before, 'Precondition failure must not write or inspect/replay the original operation')

    def test_required_version_draft_is_refused_without_any_claim(self):
        self.refused('/api/v1/attendance')
        self.assertEqual(db(f"SELECT COUNT(*) FROM api_idempotency_records WHERE idem_key='{self.key}'"), '0')

    def test_submit_empty_unknown_and_future_versions_are_refused_before_parsing(self):
        for version in ('1','','0','999','1,2'):
            with self.subTest(version=version):
                self.refused('/api/v1/attendance/submit', version=version, payload=b'invalid-json')

    def test_capabilities_are_minimal_authenticated_uncached_and_readonly(self):
        before = self.snapshot()
        status, data, headers = self.request('/api/v1/attendance/capabilities', method='GET')
        self.assertEqual(status, 200, data)
        self.assertEqual(data['data'], {'version':1,'durable_receipt_versions':[], 'durable':False,'authorizes_settlement':False})
        self.assertIn('no-store', headers.get('Cache-Control', ''))
        self.assertEqual(self.snapshot(), before)
        self.assertEqual(self.request('/api/v1/attendance/capabilities', method='GET', token='')[0], 401)
        for method in ('POST','PUT','DELETE','PATCH','HEAD'):
            self.assertEqual(self.request('/api/v1/attendance/capabilities', method=method)[0], 405)
        self.assertEqual(self.request('/api/v1/attendance/capabilities/extra', method='GET')[0], 404)
        self.assertEqual(self.snapshot(), before)
        info = mobile_token('info_dept')
        self.assertEqual(self.request('/api/v1/attendance/capabilities', method='GET', token=info)[0], 403)

    def test_old_client_cannot_bypass_current_authority_for_negotiation(self):
        try:
            db('UPDATE users SET is_active=0 WHERE id=3')
            for path, method, version in [('/api/v1/attendance/capabilities','GET',None),('/api/v1/attendance','POST','1'),('/api/v1/attendance/submit','POST','1')]:
                status, data, _ = self.request(path, method=method, version=version)
                self.assertEqual((status,data['code']), (401,'ACCOUNT_DISABLED'))
        finally:
            db('UPDATE users SET is_active=1 WHERE id=3')

    def test_legacy_writes_and_replays_still_work_but_never_satisfy_strict_header(self):
        for path in ('/api/v1/attendance','/api/v1/attendance/submit'):
            with self.subTest(path=path):
                status, data, _ = self.request(path)
                self.assertEqual(status, 201, data)
                replay_status, replay, headers = self.request(path)
                self.assertEqual((replay_status,replay), (201,data))
                self.assertEqual(headers.get('Idempotency-Replayed'), 'true')
                # Advisory monitoring can change on a legitimate legacy replay.
                self.refused(path)
        status, data, _ = self.request('/api/v1/attendance/operation-status?kind=submit', version='1')
        self.assertEqual((status,data['data']['state']), (200,'completed'))
        self.assertIs(data['data']['authorizes_settlement'], False)

    def test_preflight_allows_header_without_expanding_origin_authority(self):
        before = self.snapshot()
        status, data, headers = self.request('/api/v1/attendance', method='OPTIONS', token='',
            extra_headers={'Origin':'https://untrusted.invalid', 'Access-Control-Request-Method':'POST',
                           'Access-Control-Request-Headers':'X-Attendance-Receipt-Version'})
        self.assertEqual(status, 204)
        self.assertIsNone(data)
        self.assertIn('X-Attendance-Receipt-Version', headers.get('Access-Control-Allow-Headers',''))
        self.assertIsNone(headers.get('Access-Control-Allow-Origin'))
        self.assertEqual(self.snapshot(), before)
