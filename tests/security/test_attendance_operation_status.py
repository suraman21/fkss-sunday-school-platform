"""Read-only replay-cache diagnostics; never a durable settlement receipt."""
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


def test_lookup_is_a_single_select_without_mutating_or_fallback_calls():
    source = (ROOT / 'admin/backend/services/ApiIdempotencyService.php').read_text()
    method = source.split('public function lookup(', 1)[1].split('\n    }\n', 1)[0]
    assert 'SELECT user_id' in method and 'expires_at > CURRENT_TIMESTAMP' in method
    assert '(response_body IS NOT NULL) AS has_response' in method
    for forbidden in ('INSERT ', 'UPDATE ', 'DELETE ', 'CREATE ', 'FOR UPDATE', 'beginFile(', 'legacyReplay(', 'complete(', 'abandon('):
        assert forbidden not in method
    assert "'state' => 'unknown'" in method


def test_status_route_preserves_original_hash_and_current_authorization():
    source = (ROOT / 'api/v1/routes/attendance.php').read_text()
    block = source.split("if ($action === 'operation-status')", 1)[1].split('// GET /attendance?', 1)[0]
    assert 'apiRevalidateAuthorizationScope($auth, true)' in source
    assert block.index('apiRequireClassAccess(') < block.index('->lookup(')
    assert "'POST attendance'" in block and "'/submit'" in block
    assert "apiIdempotencyRequestHash('POST', $scope)" in block
    assert "'authorizes_settlement' => false" in block and "'durable' => false" in block
    assert 'getBody(1048576)' in block
    for forbidden in ('apiIdempotencyBegin(', 'apiReplaceAttendanceRows(', 'apiAssertClassDay(', 'apiValidateAttendanceSheet('):
        assert forbidden not in block


@unittest.skipUnless(os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'Explicit disposable live-guard world required')
class AttendanceOperationStatusLiveTests(unittest.TestCase):
    def setUp(self):
        self.assertIn(urllib.parse.urlparse(BASE).hostname, ('127.0.0.1', 'localhost'))
        self.day = (date.today() - timedelta(days=12)).isoformat()
        self.scope = f"class_id=1 AND attendance_date='{self.day}'"
        self.packet = self.scope + " AND submission_type='attendance'"
        self.assertEqual(db(f'SELECT COUNT(*) FROM attendance WHERE {self.scope}'), '0')
        self.assertEqual(db(f'SELECT COUNT(*) FROM grade_submissions WHERE {self.packet}'), '0')
        self.key = 'status-http-' + uuid.uuid4().hex
        self.token = mobile_token('teacher')
        self.payload = {'class_id': 1, 'date': self.day, 'client_op_id': self.key,
                        'records': [{'member_id': 900001, 'status': 'present', 'note': 'SYNTHETIC-PRIVATE-NOTE'}]}

    def tearDown(self):
        db(f"DELETE FROM attendance_submission_events WHERE department='edu' AND packet_id IN (SELECT id FROM grade_submissions WHERE {self.packet})")
        db(f'DELETE FROM attendance WHERE {self.scope}')
        db(f'DELETE FROM grade_submissions WHERE {self.packet}')
        db(f"DELETE FROM api_idempotency_records WHERE idem_key='{self.key}'")

    def request(self, path, payload=None, token=None, method='POST', key=None, content_type='application/json'):
        headers = {'Content-Type': content_type, 'Idempotency-Key': self.key if key is None else key}
        if token is None: token = self.token
        if token: headers['Authorization'] = 'Bearer ' + token
        body = self.payload if payload is None else payload
        raw = body if isinstance(body, bytes) else json.dumps(body).encode()
        req = urllib.request.Request(BASE + path, data=raw if method != 'GET' else None,
                                     method=method, headers=headers)
        try:
            response = urllib.request.urlopen(req, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            data = response.read()
            self.assertNotIn(b'SYNTHETIC-PRIVATE-NOTE', data)
            return response.status, json.loads(data), response.headers

    def snapshot(self):
        return db('CHECKSUM TABLE attendance,grade_submissions,api_idempotency_records,api_sync_attempts')

    def inspect(self, expected, **kwargs):
        before = self.snapshot()
        status, result, headers = self.request('/api/v1/attendance/operation-status?kind=draft', **kwargs)
        self.assertEqual(status, 200, result)
        self.assertEqual(self.snapshot(), before, 'Inspection must not mutate business/replay/monitor tables')
        evidence = result['data']
        self.assertEqual(evidence['state'], expected)
        self.assertEqual(evidence['authority'], 'legacy_replay_cache')
        self.assertIs(evidence['durable'], False)
        self.assertIs(evidence['authorizes_settlement'], False)
        self.assertIsNone(headers.get('Idempotency-Replayed'))
        self.assertLessEqual(set(evidence), {'version','authority','durable','authorizes_settlement','state','cached_http_status'})
        return evidence

    def save(self):
        status, data, _ = self.request('/api/v1/attendance')
        self.assertEqual(status, 201, data)

    def test_unknown_completed_processing_and_expiry_never_claim_or_mutate(self):
        self.inspect('unknown')
        self.save()
        self.assertEqual(self.inspect('completed')['cached_http_status'], 201)
        db(f"UPDATE api_idempotency_records SET record_state='processing',status_code=NULL,response_body=NULL,lease_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE idem_key='{self.key}'")
        self.inspect('processing')
        db(f"UPDATE api_idempotency_records SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE idem_key='{self.key}'")
        self.inspect('unknown')
        self.assertEqual(db(f'SELECT COUNT(*) FROM attendance WHERE {self.scope}'), '1')
        self.assertEqual(db(f"SELECT COUNT(*) FROM api_idempotency_records WHERE idem_key='{self.key}'"), '1', 'Expired proof must not be deleted/reset')

    def test_payload_owner_and_route_are_isolated(self):
        self.save()
        self.inspect('payload_mismatch', payload=dict(self.payload, records=[{'member_id':900001,'status':'absent'}]))
        self.inspect('unknown', token=mobile_token('edu_dept'))
        before = self.snapshot()
        status, data, _ = self.request('/api/v1/attendance/operation-status?kind=submit')
        self.assertEqual((status, data['data']['state']), (200, 'unknown'))
        self.assertEqual(self.snapshot(), before)
        self.inspect('payload_mismatch', payload=dict(self.payload, date='2020-01-01'))

    def test_current_class_role_and_auth_gates_are_not_bypassed(self):
        self.save()
        before = self.snapshot()
        self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', token='')[0], 401)
        self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', token=mobile_token('info_dept'))[0], 403)
        self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', payload=dict(self.payload,class_id=3))[0], 403)
        self.assertEqual(self.snapshot(), before)
        assignments = db('SELECT id,is_active FROM teacher_assignments WHERE teacher_id=3 AND class_id=1')
        try:
            db('UPDATE teacher_assignments SET is_active=0 WHERE teacher_id=3 AND class_id=1')
            status, result, _ = self.request('/api/v1/attendance/operation-status?kind=draft')
            self.assertEqual((status, result['code']), (401, 'AUTH_SCOPE_CHANGED'))
            fresh = mobile_token('teacher')
            self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', token=fresh)[0], 403)
        finally:
            for row in assignments.splitlines():
                identifier, active = row.split('\t')
                db(f'UPDATE teacher_assignments SET is_active={int(active)} WHERE id={int(identifier)}')
        self.assertEqual(self.snapshot(), before)
        self.token = mobile_token('teacher')
        try:
            db('UPDATE users SET is_active=0 WHERE id=3')
            status, result, _ = self.request('/api/v1/attendance/operation-status?kind=draft')
            self.assertEqual((status, result['code']), (401, 'ACCOUNT_DISABLED'))
        finally:
            db('UPDATE users SET is_active=1 WHERE id=3')
        self.assertEqual(self.snapshot(), before)

    def test_method_kind_key_date_and_body_budget_fail_without_mutations(self):
        before = self.snapshot()
        for method in ('GET','PUT','DELETE','PATCH'):
            self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', method=method)[0], 405)
        for kind in ('','bad','draft[]'):
            self.assertEqual(self.request('/api/v1/attendance/operation-status?kind='+urllib.parse.quote(kind))[0], 400)
        self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', key='different')[0], 400)
        self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', payload=dict(self.payload,date='2026-02-31'))[0], 400)
        self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', payload={}, key='')[0], 400)
        self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', content_type='text/plain')[0], 415)
        self.assertEqual(self.request('/api/v1/attendance/operation-status?kind=draft', payload=b' '*1048577)[0], 413)
        self.assertEqual(self.snapshot(), before)

    def test_missing_schema_is_unavailable_without_fallback_or_schema_creation(self):
        backup = 'status_backup_' + uuid.uuid4().hex[:12]
        before = self.snapshot()
        db(f'RENAME TABLE api_idempotency_records TO {backup}')
        try:
            status, result, _ = self.request('/api/v1/attendance/operation-status?kind=draft')
            self.assertEqual(status, 503, result)
            self.assertEqual(result['code'], 'OPERATION_STATUS_UNAVAILABLE')
            self.assertIs(result['lookup']['authorizes_settlement'], False)
            self.assertEqual(db("SHOW TABLES LIKE 'api_idempotency_records'"), '')
        finally:
            db(f'RENAME TABLE {backup} TO api_idempotency_records')
        self.assertEqual(self.snapshot(), before)
