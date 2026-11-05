"""Source pins plus authenticated HTTP/MariaDB failure-injection regressions."""
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


def test_both_attendance_handlers_record_database_success_before_commit():
    source = (ROOT / 'api/v1/routes/attendance.php').read_text()
    for start, end in [('POST /attendance — Save', 'POST /attendance/submit'),
                       ('POST /attendance/submit', 'GET /attendance/daily-stats')]:
        body = source.split(start, 1)[1].split(end, 1)[0]
        assert body.index('apiRequireClassAccess(') < body.index('apiIdempotencyBegin(')
        assert body.index('apiValidateAttendanceSheet(') < body.index('$conn->begin_transaction()')
        assert body.index('$responseData = [') < body.index('apiIdempotencyCompleteDatabaseSuccess($responseData, 201)') < body.index('$conn->commit()')
        caught = body.split('catch (ApiIdempotencyResponseNotRecorded $error)', 1)[1].split('catch (\\DomainException', 1)[0]
        assert caught.index('apiIdempotencyRollbackResponse($conn)') < caught.index('503')
        assert "'IDEMPOTENCY_RESPONSE_NOT_RECORDED'" in caught
        assert 'ok($responseData, 201)' in body


def test_sender_detaches_before_rollback_and_abandon_requires_confirmation():
    source = (ROOT / 'api/v1/core/middleware.php').read_text()
    body = source.split('function apiIdempotencyRollbackResponse(', 1)[1].split('function apiIdempotencyStore(', 1)[0]
    assert body.index("unset($GLOBALS['_fkss_idem'])") < body.index('if (!$connection->rollback())') < body.index('->abandon(')
    assert "['backend'] ?? '') === 'database'" in body
    strict = source.split('function apiIdempotencyCompleteDatabaseSuccess(', 1)[1].split('function apiIdempotencyRollbackResponse(', 1)[0]
    assert "apiIdempotencyKey() === ''" in strict
    assert "if ($backend === 'file') return false" in strict
    assert 'apiEncodeJsonResponse(apiSuccessPayload($data))' in strict
    assert "!$pack['service']->completeWithinTransaction(" in strict
    assert 'apiIdempotencyCompleteAtomically(' not in strict
    assert 'ApiSyncAttemptMonitorService::' not in strict
    assert 'throw new ApiIdempotencyResponseNotRecorded' in strict


def test_wire_and_precommit_share_success_envelope_and_encoding():
    source = (ROOT / 'api/v1/core/response.php').read_text()
    ok = source.split('function ok(', 1)[1].split('function apiSuccessPayload(', 1)[0]
    sender = source.split('function apiSendJson(', 1)[1].split('function paginated(', 1)[0]
    assert 'apiSendJson(apiSuccessPayload($data), $code)' in ok
    assert '$json = apiEncodeJsonResponse($response)' in sender
    assert 'apiIdempotencyStore($json, $code)' in sender


# Actual authenticated HTTP handler + DB failure injection, not a stand-in write.
@unittest.skipUnless(os.environ.get('SSMS_AUDIT_TESTING') == '1' and _live_ready(),
                     'Explicit disposable live-guard world required')
class AttendanceAtomicResponseHttpTests(unittest.TestCase):
    def exercise(self, suffix, sheet_date, expected_status):
        self.assertIn(urllib.parse.urlparse(BASE).hostname, ('localhost', '127.0.0.1'))
        scope = f"class_id=1 AND attendance_date='{sheet_date}'"
        packet_scope = scope + " AND submission_type='attendance'"
        self.assertEqual(db(f'SELECT COUNT(*) FROM attendance WHERE {scope}'), '0', 'Reserved synthetic sheet must be unused')
        self.assertEqual(db(f'SELECT COUNT(*) FROM grade_submissions WHERE {packet_scope}'), '0')
        token = mobile_token('teacher')
        headers = {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token}
        with urllib.request.urlopen(urllib.request.Request(
                BASE + f'/api/v1/attendance?class_id=1&date={sheet_date}', headers=headers), timeout=30) as response:
            roster = json.loads(response.read())['data']['students']
        records = [{'member_id': int(row['member_id']), 'status': 'present'} for row in roster]
        self.assertTrue(records)
        payload = {'class_id': 1, 'date': sheet_date, 'records': records}
        key = 'atomic-http-' + uuid.uuid4().hex
        trigger = 'ssms_atomic_response_' + uuid.uuid4().hex[:12]
        headers['Idempotency-Key'] = key

        def post(body):
            request = urllib.request.Request(BASE + '/api/v1/attendance' + suffix,
                data=json.dumps(body).encode(), method='POST', headers=headers)
            try:
                response = urllib.request.urlopen(request, timeout=30)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, response.read(), response.headers

        try:
            db(f"DELIMITER //\nCREATE TRIGGER {trigger} BEFORE UPDATE ON api_idempotency_records FOR EACH ROW BEGIN IF NEW.idem_key='{key}' AND NEW.record_state='completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic receipt failure'; END IF; END//\nDELIMITER ;")
            status, raw, received = post(payload)
            failed_state = (status,
                db(f'SELECT COUNT(*) FROM attendance WHERE {scope}'),
                db(f'SELECT COUNT(*) FROM grade_submissions WHERE {packet_scope}'))
            self.assertEqual(failed_state, (503, '0', '0'), raw)
            self.assertEqual(json.loads(raw)['code'], 'IDEMPOTENCY_RESPONSE_NOT_RECORDED')
            self.assertIsNone(received.get('Idempotency-Replayed'))
            self.assertEqual(db(f'SELECT COUNT(*) FROM attendance WHERE {scope}'), '0')
            self.assertEqual(db(f'SELECT COUNT(*) FROM grade_submissions WHERE {packet_scope}'), '0')
            self.assertEqual(db(f"SELECT COUNT(*) FROM api_idempotency_records WHERE idem_key='{key}'"), '0')
            db(f'DROP TRIGGER {trigger}')
            status, first, _ = post(payload)
            self.assertEqual(status, 201, first)
            data = json.loads(first)['data']
            self.assertEqual(data['saved'], len(records))
            self.assertEqual(data['submission_status'], expected_status)
            self.assertEqual(db(f'SELECT COUNT(*) FROM attendance WHERE {scope}'), str(len(records)))
            status, replay, received = post(payload)
            self.assertEqual((status, replay), (201, first))
            self.assertEqual(received.get('Idempotency-Replayed'), 'true')
            self.assertEqual(db(f'SELECT COUNT(*) FROM grade_submissions WHERE {packet_scope}'), '1')
            changed = dict(payload, records=[dict(row, status='absent') for row in records])
            status, _, _ = post(changed)
            self.assertEqual(status, 409, 'Same identity with a different payload must conflict')
            self.assertEqual(db(f"SELECT COUNT(*) FROM attendance WHERE {scope} AND status='present'"), str(len(records)))
        finally:
            db(f'DROP TRIGGER IF EXISTS {trigger}')
            db(f"DELETE FROM attendance_submission_events WHERE department='edu' AND packet_id IN (SELECT id FROM grade_submissions WHERE {packet_scope})")
            db(f'DELETE FROM attendance WHERE {scope}')
            db(f'DELETE FROM grade_submissions WHERE {packet_scope}')
            db(f"DELETE FROM api_idempotency_records WHERE idem_key='{key}'")

    def test_real_draft_failure_rollback_retry_and_exact_replay(self):
        self.exercise('', (date.today() - timedelta(days=10)).isoformat(), 'incomplete')

    def test_real_submit_failure_rollback_retry_and_exact_replay(self):
        self.exercise('/submit', (date.today() - timedelta(days=11)).isoformat(), 'submitted')
