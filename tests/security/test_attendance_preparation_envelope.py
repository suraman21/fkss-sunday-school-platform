"""Shared synthetic issuer vector, not HTTP, client auth or device proof."""
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def test_shared_envelope_is_actual_server_provenance():
    program = r'''
require 'admin/backend/services/AttendancePreparationGrant.php';
use App\Services\AttendancePreparationGrant as Grant;
$v = json_decode(file_get_contents('tests/fixtures/attendance_preparation_envelope_v1.json'), true, 32, JSON_THROW_ON_ERROR);
$e = $v['envelope'];
$key = str_repeat('fixture-only-not-a-production-key-', 2);
$r = Grant::inspect($e['grant'], 'root', $key);
$raw = base64_decode(strtr($e['payload'], '-_', '+/'), true);
$p = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
if ($e['preparation_version'] !== 1 || $e['authorizes_settlement'] !== false
    || $r['payload_sha256'] !== hash('sha256', $raw)
    || $p['complete'] !== true || $p['classes'] !== [] || $p['members'] !== []) exit(2);
foreach (['preparation_id','server_identity','owner_user_id','authorization_version','role','family_id','issued_at','deadline'] as $k) {
    if ($r[$k] !== $p[$k]) exit(3);
}
$fresh = Grant::issue($p, $r['server_identity'], $key);
$facts = json_decode(base64_decode(strtr($fresh['payload'], '-_', '+/'), true), true, 32, JSON_THROW_ON_ERROR);
unset($p['preparation_id'], $facts['preparation_id']);
if ($p !== $facts) exit(4);
echo "PASS\n";
'''
    result = subprocess.run(["php", "-r", program], cwd=ROOT,
                            capture_output=True, text=True, check=True)
    assert result.stdout == "PASS\n"


def test_decoder_is_not_activated_by_this_slice():
    lib = ROOT / 'Mobile/wbws_flutter_app/lib'
    callers = [p.relative_to(lib).as_posix() for p in lib.rglob('*.dart')
               if 'attendance_preparation_envelope.dart' in p.read_text()]
    assert callers == []
    fixture = json.loads((ROOT / 'tests/fixtures/attendance_preparation_envelope_v1.json').read_text())
    assert fixture['envelope']['authorizes_settlement'] is False
