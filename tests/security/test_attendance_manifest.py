"""Pure PHP manifest codec + independent cross-language golden vectors."""
import hashlib
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]


def test_real_php_manifest_codec():
    run = subprocess.run(['php', str(ROOT / 'tests/e2e/attendance_manifest.php')],
                         capture_output=True, text=True, timeout=30)
    assert run.returncode == 0, run.stdout + run.stderr
    assert 'MANIFEST-VERDICT: PASS' in run.stdout


def test_independent_reference_frames_and_hashes():
    fixture = json.loads((ROOT / 'tests/fixtures/attendance_manifest_v1.json').read_text())
    def frames(values):
        return b''.join(str(len(b)).encode() + b':' + b + b','
                        for v in values for b in [str(v).encode('utf-8')])
    vectors = list(fixture['vectors'])
    maximum = dict(vectors[0]['input'])
    maximum['records'] = [{'member_id': i, 'status': 'present', 'notes': fixture['max_case']['notes']}
                          for i in range(1, 2001)]
    vectors.append({'input': maximum, 'expected': fixture['max_case']['expected']})
    for vector in vectors:
        p = vector['input']
        ordered = sorted(p['records'], key=lambda r: r['member_id'])
        raw = frames(['ssms.attendance.records.v1', len(ordered)] +
                     [v for row in ordered for v in (row['member_id'], row['status'], row['notes'])])
        if 'records_bytes_hex' in vector:
            assert raw.hex() == vector['records_bytes_hex']
        digest = hashlib.sha256(raw).hexdigest()
        assert digest == vector['expected']['records_sha256']
        context = frames(['ssms.attendance.manifest.v1', p['owner_user_id'], p['client_op_id'],
                          p['packet_kind'], p['class_id'], p['academic_year_id'], p['date'], len(ordered), digest])
        if 'manifest_bytes_hex' in vector:
            assert context.hex() == vector['manifest_bytes_hex']
        assert hashlib.sha256(context).hexdigest() == vector['expected']['manifest_sha256']


def test_codec_is_not_activated_as_a_receipt_or_legacy_hash_replacement():
    route = (ROOT / 'api/v1/routes/attendance.php').read_text()
    assert "'durable_receipt_versions' => []" in route
    assert 'AttendanceOperationManifest' not in route
    sync = (ROOT / 'Mobile/wbws_flutter_app/lib/services/sync_service.dart').read_text()
    assert 'AttendanceOperationManifest' not in sync
