"""Session-binding prerequisite; not full preparation/lost-device acceptance."""
import json
import os
from pathlib import Path
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[2]


@pytest.mark.parametrize('reporting', ['strict', 'off'])
def test_actual_auth_routes_and_bound_session_guard(reporting):
    assert os.environ.get('SSMS_AUDIT_TESTING') == '1', 'Explicit isolated SQL environment required'
    run = subprocess.run(['php', str(ROOT / 'tests/e2e/attendance_preparation_family.php'), reporting],
                         capture_output=True, text=True, timeout=30)
    assert run.returncode == 0, run.stdout + run.stderr
    assert not run.stderr, run.stderr
    data = json.loads(run.stdout)
    assert data['result'] == 'PASS' and data['checks'] == 61
    assert data['mysqli_strict'] == (reporting == 'strict')
    assert data['actual'] == ['login route', 'refresh route', 'logout happy path', 'replay', 'signed access guard', 'SQL missing column']
    assert data['adapter_doubles'] == ['body', 'rate limit', 'audit utility', 'response']
    assert data['not_proven'] == ['HTTP transport', 'revocation write serialization', 'failed logout acknowledgment', 'unbound-token revocation', 'hardware binding', 'offline grant']


def test_binding_is_signed_optional_and_unconditional_for_bound_tokens():
    core = (ROOT / 'api/v1/core/auth.php').read_text()
    route = (ROOT / 'api/v1/routes/auth.php').read_text()
    service = (ROOT / 'admin/backend/services/RefreshTokenService.php').read_text()
    token = core.split('function createToken(', 1)[1].split('function apiRefreshAccessBinding(', 1)[0]
    assert '$refreshBinding = null' in token
    assert "$payload['fid'] = $refreshBinding['family_id']" in token
    assert "$payload['rsid'] = $refreshBinding['session_id']" in token
    assert token.index("$payload['rsid']") < token.index("hash_hmac('sha256'")
    assert route.count('apiRefreshAccessBinding(') == 2 and route.count('            $accessFamily') == 2
    guard = core.split('function apiRequireAuth()', 1)[1].split('function apiRequireRole(', 1)[0]
    assert "array_key_exists('fid', $payload) || array_key_exists('rsid', $payload)" in guard
    assert 'accessSessionIsActive(' in guard and 'HTTP_X_APP_BUILD' not in guard
    assert "['code' => 'AUTH_SESSION_REVALIDATION_UNAVAILABLE']" in guard
    assert "['code' => 'SESSION_REVOKED']" in guard
    assert 'return apiRevalidateAuthorizationScope($payload);' in guard
    block = service.split('private const ACCESS_SESSION_SQL', 1)[1].split('public function revokePresented(', 1)[0]
    assert 'WHERE session_id=? AND family_id=? AND user_id=?' in block
    assert 'revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP' in block
    assert 'consumed_at IS NULL' not in block  # Consumption is not revocation.
    assert "bind_param('ssi'" in block and '$result->num_rows === 1' in block
    assert '->begin_transaction(' not in block and '->commit(' not in block
    assert 'CREATE TABLE' not in block and 'ALTER TABLE' not in block


def test_fixture_is_guarded_temp_only_and_does_not_output_tokens():
    source = (ROOT / 'tests/e2e/attendance_preparation_family.php').read_text()
    assert "PHP_SAPI !== 'cli'" in source and "getenv('SSMS_AUDIT_TESTING') !== '1'" in source
    assert 'config.php' not in source
    assert "'ssms_comm_e2e'" in source and "'127.0.0.1'" in source
    assert 'CREATE TEMPORARY TABLE users' in source
    assert 'DROP TEMPORARY TABLE IF EXISTS' in source
    assert "EXPLAIN ' . $sql" in source and "($plan['key'] ?? '') === 'PRIMARY'" in source
    assert 'DROP TABLE ' not in source and 'DROP DATABASE' not in source
    output = source.split('    echo json_encode(', 1)[1].split('} finally', 1)[0]
    assert "['token']" not in output and "['refresh_token']" not in output
