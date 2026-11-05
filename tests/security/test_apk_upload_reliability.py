"""Executable upload recovery contracts: native PHP/filesystem and the real JS engine.

All data is synthetic in TemporaryDirectory. No deployed server or real release
is modified. Missing runtimes fail explicitly rather than silently self-skipping.
"""
from __future__ import annotations

import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import socket
import time
import urllib.request
import urllib.error

from apk_fixture import write_apk

ROOT = Path(__file__).resolve().parents[2]
HARNESS = r'''<?php
define('ROOT_PATH', $argv[1]);
require ROOT_PATH . '/admin/backend/services/AppReleaseManager.php';
use App\Services\AppReleaseManager as M;
use App\Services\AppReleaseUploadStore as S;
$op = json_decode(stream_get_contents(STDIN), true);
try {
    $id = $op['id'] ?? 'test_upload_123';
    switch ($op['action']) {
        case 'preflight': $out = M::getPreflightInfo(ROOT_PATH); break;
        case 'status': $out = M::uploadStatus(ROOT_PATH, $id, $op['count'], $op['size'], $op['descriptor'] ?? []); break;
        case 'part': $out = M::handleChunkUpload(ROOT_PATH, null, $id, $op['index'], $op['count'], $op['bytes'], $op['size'], $op['b64']); break;
        case 'assemble': $out = M::assembleChunks(ROOT_PATH, $id, $op['count'], $op['size'], 'test.apk', $op['abi'] ?? 'universal'); break;
        case 'cancel': M::cancelUpload(ROOT_PATH, $id); $out = true; break;
        case 'gc': M::cleanupOldChunks(ROOT_PATH, 10); $out = true; break;
        case 'hold': S::locked(ROOT_PATH, $id, function () { echo "LOCKED\n"; flush(); sleep(2); }); $out = true; break;
        default: throw new RuntimeException('Unknown test operation');
    }
    echo json_encode(['ok' => true, 'out' => $out]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage(),
        'code' => $e instanceof \App\Services\AppReleaseUploadException ? $e->errorCode : get_class($e)]);
}
'''


class UploadRecoveryRuntimeTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(shutil.which('php'), 'php-cli must be installed; do not skip upload recovery checks')
        self.temp = tempfile.TemporaryDirectory()
        self.home = Path(self.temp.name)
        self.web = self.home / 'site'
        (self.web / 'admin/backend').mkdir(parents=True)
        (self.web / 'api/v1').mkdir(parents=True)
        (self.web / 'releases').mkdir()
        (self.web / 'admin/backend/services').symlink_to(ROOT / 'admin/backend/services', target_is_directory=True)
        (self.web / 'api/v1/core').symlink_to(ROOT / 'api/v1/core', target_is_directory=True)
        self.harness = self.home / 'harness.php'
        self.harness.write_text(HARNESS)
        self.id = 'test_upload_123'
        self.parts_dir = self.web / 'releases/.tmp_chunks' / self.id
        self.apk = write_apk(self.home / 'new.apk', deflate=False, padding=140000)
        self.payload = self.apk.read_bytes()
        self.part_size = 65536
        self.parts = [self.payload[i:i + self.part_size] for i in range(0, len(self.payload), self.part_size)]
        hashes = [hashlib.sha256(p).hexdigest() for p in self.parts]
        identity = f'{len(self.payload)}:{self.part_size}:universal:' + ''.join(hashes)
        self.descriptor = {'part_size': self.part_size, 'part_hashes': hashes, 'abi': 'universal',
                           'fingerprint': hashlib.sha256(identity.encode()).hexdigest()}
        self.config = self.web / 'api/v1/app_release.local.php'
        self.config.write_text("<?php return ['force_update'=>true,'min_build'=>30,'background_drains_enabled'=>false];")

    def tearDown(self):
        for p in self.home.rglob('*'):
            if not p.is_symlink():
                try: p.chmod(0o755 if p.is_dir() else 0o644)
                except OSError: pass
        self.temp.cleanup()

    def op(self, action, **kwargs):
        values = {'action': action, 'id': self.id, 'count': len(self.parts), 'size': len(self.payload), **kwargs}
        run = subprocess.run(['php', str(self.harness), str(self.web)], input=json.dumps(values),
                             text=True, capture_output=True, timeout=15)
        self.assertEqual(run.returncode, 0, run.stderr + run.stdout)
        self.assertEqual(run.stderr, '', run.stderr)
        return json.loads(run.stdout)

    def begin(self):
        result = self.op('status', descriptor=self.descriptor)
        self.assertTrue(result['ok'], result)
        return result['out']

    def send(self, index, payload=None):
        data = self.parts[index] if payload is None else payload
        return self.op('part', index=index, bytes=len(data), b64=base64.b64encode(data).decode())

    def all_parts(self):
        self.begin()
        for i in range(len(self.parts)):
            result = self.send(i)
            self.assertTrue(result['ok'], result)

    def test_missing_parts_are_retained_for_retry(self):
        # Also works against the old service to demonstrate its destructive failure.
        result = self.send(0)
        self.assertTrue(result['ok'], result)
        failed = self.op('assemble')
        self.assertFalse(failed['ok'])
        self.assertTrue((self.parts_dir / 'chunk_00000').is_file(), 'assembly deleted a valid saved part')
        progress = self.op('status')
        self.assertEqual(progress['out']['received'], [0])

    def test_preflight_created_directory_is_protected_before_session_data(self):
        self.assertTrue(self.op('preflight')['ok'])
        base = self.web / 'releases/.tmp_chunks'
        self.assertTrue(base.is_dir())
        self.begin()
        self.assertIn('Deny from all', (base / '.htaccess').read_text())
        self.assertTrue(self.send(0)['ok'])

    def test_unwritable_missing_protection_fails_before_session_or_chunks(self):
        base = self.web / 'releases/.tmp_chunks'
        base.mkdir()
        base.chmod(0o555)
        failed = self.op('status', descriptor=self.descriptor)
        self.assertFalse(failed['ok'])
        self.assertIn('Cannot protect temporary upload storage', failed['message'])
        self.assertFalse(self.parts_dir.exists())

    def test_512_mib_boundary_and_8192_minimum_size_parts(self):
        size, count, part_size = 536870912, 8192, 65536
        hashes = [hashlib.sha256(bytes(part_size)).hexdigest()] * count
        identity = f'{size}:{part_size}:universal:' + ''.join(hashes)
        descriptor = {'part_size': part_size, 'part_hashes': hashes, 'abi': 'universal',
                      'fingerprint': hashlib.sha256(identity.encode()).hexdigest()}
        accepted = self.op('status', size=size, count=count, descriptor=descriptor)
        self.assertTrue(accepted['ok'], accepted)
        self.assertEqual(len(accepted['out']['missing']), count)
        self.assertFalse(self.op('status', id='over_limit_123', size=size + 1, count=count)['ok'])
        self.assertFalse(self.op('status', id='over_count_123', size=size, count=count + 1)['ok'])

    def test_corrupt_metadata_fails_closed_without_losing_parts(self):
        self.begin()
        self.assertTrue(self.send(0)['ok'])
        (self.parts_dir / 'session.json').write_text('{}')
        result = self.op('status', descriptor=self.descriptor)
        self.assertEqual(result['code'], 'session_corrupt')
        self.assertTrue((self.parts_dir / 'chunk_00000').exists())

    def test_verified_resume_reports_only_checksum_valid_parts(self):
        self.begin()
        self.assertTrue(self.send(0)['ok'])
        path = self.parts_dir / 'chunk_00000'
        path.write_bytes(b'x' * len(self.parts[0]))
        progress = self.op('status', descriptor=self.descriptor)['out']
        self.assertEqual(progress['received'], [])
        self.assertIn(0, progress['missing'])
        self.assertTrue(self.send(0)['ok'])
        self.assertEqual(self.op('status')['out']['received'], [0])

    def test_invalid_chunk_never_overwrites_a_valid_part(self):
        self.begin()
        self.assertTrue(self.send(0)['ok'])
        bad = self.send(0, b'z' * len(self.parts[0]))
        self.assertFalse(bad['ok'])
        self.assertEqual(bad['code'], 'chunk_mismatch')
        self.assertEqual((self.parts_dir / 'chunk_00000').read_bytes(), self.parts[0])
        self.assertEqual(list(self.parts_dir.glob('*.part')), [])

    def test_wrong_length_is_rejected_before_ack(self):
        self.begin()
        self.assertFalse(self.send(0, self.parts[0][:-1])['ok'])
        self.assertFalse((self.parts_dir / 'chunk_00000').exists())

    def test_changed_file_or_abi_cannot_reuse_session(self):
        self.begin()
        other = dict(self.descriptor, abi='arm64-v8a')
        other['fingerprint'] = hashlib.sha256((f'{len(self.payload)}:{self.part_size}:arm64-v8a:' + ''.join(other['part_hashes'])).encode()).hexdigest()
        response = self.op('status', descriptor=other)
        self.assertFalse(response['ok'])
        self.assertEqual(response['code'], 'upload_mismatch')
        self.assertFalse(self.op('status', size=len(self.payload) + 1)['ok'])

    def test_invalid_descriptor_and_unbounded_count_are_rejected(self):
        invalid = dict(self.descriptor, fingerprint='a' * 64)
        self.assertFalse(self.op('status', descriptor=invalid)['ok'])
        self.assertFalse(self.op('status', count=1000000000)['ok'])
        self.assertFalse(self.op('status', id='../bad_path')['ok'])

    def test_missing_tail_can_be_uploaded_then_finalized(self):
        self.begin()
        self.assertTrue(self.send(0)['ok'])
        failed = self.op('assemble')
        self.assertEqual(failed['code'], 'missing_chunks')
        for i in range(1, len(self.parts)):
            self.assertTrue(self.send(i)['ok'])
        success = self.op('assemble')
        self.assertTrue(success['ok'], success)
        dest = self.web / 'releases/fkss.apk'
        self.assertEqual(dest.read_bytes(), self.payload)
        self.assertTrue(success['out']['force_update'])
        self.assertEqual(success['out']['min_build'], 30)
        self.assertFalse(success['out']['background_drains_enabled'])

    def test_lost_success_response_reconciles_to_a_receipt(self):
        self.all_parts()
        first = self.op('assemble')
        self.assertTrue(first['ok'], first)
        config_before = self.config.read_bytes()
        receipt = self.op('status', descriptor=self.descriptor)
        self.assertEqual(receipt['out']['state'], 'published')
        self.assertEqual(list(self.parts_dir.glob('chunk_*')), [])
        again = self.op('assemble')
        self.assertTrue(again['ok'], again)
        self.assertEqual(self.config.read_bytes(), config_before)
        self.assertEqual(again['out']['apk_sha256'], hashlib.sha256(self.payload).hexdigest())

    def test_old_receipt_cannot_overwrite_newer_published_release(self):
        self.all_parts()
        self.assertTrue(self.op('assemble')['ok'])
        write_apk(self.web / 'releases/fkss.apk', version_code=42)
        for suffix in ['.meta', '.manifest.json']:
            (self.web / ('releases/fkss.apk' + suffix)).unlink(missing_ok=True)
        result = self.op('assemble')
        self.assertFalse(result['ok'])
        self.assertEqual(result['code'], 'upload_superseded')

    def test_cancel_blocks_late_parts_and_does_not_unpublish(self):
        self.begin()
        self.send(0)
        self.assertTrue(self.op('cancel')['ok'])
        self.assertFalse(self.send(1)['ok'])
        self.assertEqual(list(self.parts_dir.glob('chunk_*')), [])
        self.id = 'another_upload_123'
        self.all_parts()
        self.assertTrue(self.op('assemble')['ok'])
        self.assertTrue(self.op('cancel')['ok'])
        self.assertTrue((self.web / 'releases/fkss.apk').exists())
        self.assertEqual(self.op('status')['out']['state'], 'published')

    def test_readonly_config_leaves_old_apk_and_parts_untouched(self):
        old = write_apk(self.web / 'releases/fkss.apk', version_code=40).read_bytes()
        self.all_parts()
        self.config.chmod(0o444)
        failed = self.op('assemble')
        self.assertFalse(failed['ok'], failed)
        self.assertEqual((self.web / 'releases/fkss.apk').read_bytes(), old)
        self.assertEqual(len(list(self.parts_dir.glob('chunk_*'))), len(self.parts))

    def test_receipt_write_failure_rolls_back_artifact_and_settings(self):
        old = write_apk(self.web / 'releases/fkss.apk', version_code=40).read_bytes()
        self.all_parts()
        settings = self.config.read_bytes()
        self.parts_dir.chmod(0o555)  # Existing lock readable; new receipt temp file cannot be written.
        failed = self.op('assemble')
        self.assertFalse(failed['ok'], failed)
        self.assertEqual((self.web / 'releases/fkss.apk').read_bytes(), old)
        self.assertEqual(self.config.read_bytes(), settings)
        self.assertEqual(len(list(self.parts_dir.glob('chunk_*'))), len(self.parts))
        self.assertEqual(list((self.web / 'releases').glob('*.rollback.*')), [])

    def test_real_http_multipart_auth_csrf_resume_and_publication_receipt(self):
        """Real endpoint/parser/filesystem, synthetic session bootstrap, no database.

        This verifies endpoint gates, not deployment config.php/session security.
        """
        shutil.copyfile(ROOT / 'admin/api_app_release.php', self.web / 'admin/api_app_release.php')
        (self.web / 'admin/backend/danger_confirm.php').symlink_to(ROOT / 'admin/backend/danger_confirm.php')
        (self.web / 'admin/config.php').write_text("""<?php
            define('ROOT_PATH', dirname(__DIR__));
            session_start();
            $conn = null;
            function validateCsrf($token) { return is_string($token) && hash_equals('synthetic-csrf', $token); }
        """)
        (self.web / 'login.php').write_text("""<?php
            session_start();
            $_SESSION = ['admin_id'=>7,'admin_logged_in'=>true,
                'admin_role'=>($_GET['role'] ?? 'super_admin'),'admin_username'=>'synthetic-test'];
            echo 'ok';
        """)
        # Bind-loop is confined to this test process; no public preview/deployed traffic.
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        with (self.home / 'http.log').open('w+') as log:
            server = subprocess.Popen(['php', '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}',
                                       '-t', str(self.web)], stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        with urllib.request.urlopen(base + '/login.php', timeout=1) as result:
                            cookie = result.headers['Set-Cookie'].split(';')[0]
                        break
                    except (urllib.error.URLError, ConnectionError):
                        if server.poll() is not None: self.fail('PHP HTTP fixture exited early')
                        time.sleep(0.02)
                else: self.fail('PHP HTTP fixture did not start')

                def request(action, fields=None, binary=None, auth=True, csrf='synthetic-csrf', auth_cookie=None):
                    boundary = 'fkssTestMultipartBoundary'
                    fields = {'action': action, 'csrf_token': csrf, 'upload_id': self.id,
                              'total_size': len(self.payload), 'total_chunks': len(self.parts), **(fields or {})}
                    body = bytearray()
                    for name, value in fields.items():
                        body.extend((f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n').encode())
                    if binary is not None:
                        body.extend((f'--{boundary}\r\nContent-Disposition: form-data; name="chunk_file"; filename="part.bin"\r\nContent-Type: application/octet-stream\r\n\r\n').encode())
                        body.extend(binary); body.extend(b'\r\n')
                    body.extend(f'--{boundary}--\r\n'.encode())
                    headers = {'Content-Type': f'multipart/form-data; boundary={boundary}'}
                    if auth: headers['Cookie'] = auth_cookie or cookie
                    req = urllib.request.Request(base + '/admin/api_app_release.php', data=bytes(body), headers=headers)
                    try: result = urllib.request.urlopen(req, timeout=15)
                    except urllib.error.HTTPError as error: result = error
                    with result:
                        self.assertIn('no-store', result.headers.get('Cache-Control', ''))
                        return result.status, json.loads(result.read())

                self.assertEqual(request('upload_status', auth=False)[0], 401)
                self.assertEqual(request('upload_status', csrf='wrong')[0], 403)
                with urllib.request.urlopen(base + '/login.php?role=viewer', timeout=2) as viewer:
                    viewer_cookie = viewer.headers['Set-Cookie'].split(';')[0]
                self.assertEqual(request('upload_status', auth_cookie=viewer_cookie)[0], 403)
                descriptor = {**self.descriptor, 'part_hashes': json.dumps(self.descriptor['part_hashes'])}
                code, start = request('upload_status', descriptor)
                self.assertEqual(code, 200, start)
                self.assertTrue(start['data']['verified'])
                for index, part in enumerate(self.parts):
                    code, ack = request('upload_chunk', {'chunk_index': index, 'chunk_size': len(part)}, part)
                    self.assertEqual(code, 200, ack)
                    self.assertEqual(ack['data']['sha256'], hashlib.sha256(part).hexdigest())
                code, progress = request('upload_status', descriptor)
                self.assertEqual(progress['data']['received'], list(range(len(self.parts))))
                # A duplicate multipart retry is idempotent, not a second append.
                self.assertEqual(request('upload_chunk', {'chunk_index': 0}, self.parts[0])[0], 200)
                # Generic infrastructure errors belong in server logs, never the HTTP body.
                self.config.chmod(0o444)
                code, infrastructure_error = request('assemble_chunks', {'file_name': 'test.apk', 'abi': 'universal'})
                self.assertEqual(code, 500, infrastructure_error)
                self.assertNotIn('read-only', infrastructure_error['message'])
                self.assertNotIn(str(self.web), infrastructure_error['message'])
                self.assertEqual(len(list(self.parts_dir.glob('chunk_*'))), len(self.parts))
                self.config.chmod(0o644)
                code, published = request('assemble_chunks', {'file_name': 'test.apk', 'abi': 'universal'})
                self.assertEqual(code, 200, published)
                self.assertFalse(published['audit_logged'])  # Synthetic fixture intentionally has no mysqli connection.
                self.assertEqual((self.web / 'releases/fkss.apk').read_bytes(), self.payload)
                code, receipt = request('upload_status', descriptor)
                self.assertEqual(receipt['data']['state'], 'published')
                self.assertEqual(receipt['data']['release']['artifacts_detail']['universal']['sha256'], hashlib.sha256(self.payload).hexdigest())
                self.assertEqual(request('assemble_chunks', {'file_name': 'test.apk', 'abi': 'universal'})[0], 200)
            finally:
                server.terminate()
                try: server.wait(timeout=5)
                except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)

    def test_session_lock_prevents_parallel_mutation_and_gc(self):
        self.begin()
        process = subprocess.Popen(['php', str(self.harness), str(self.web)], stdin=subprocess.PIPE,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            process.stdin.write(json.dumps({'action': 'hold', 'id': self.id}))
            process.stdin.close()
            self.assertEqual(process.stdout.readline().strip(), 'LOCKED')
            busy = self.send(0)
            self.assertEqual(busy['code'], 'upload_busy')
            os.utime(self.parts_dir, (1, 1))
            self.assertTrue(self.op('gc')['ok'])
            self.assertTrue(self.parts_dir.is_dir())
        finally:
            process.wait(timeout=5)
            self.assertEqual(process.returncode, 0, process.stderr.read())
            process.stdout.close()
            process.stderr.close()


class UploadBrowserEngineTests(unittest.TestCase):
    def test_actual_javascript_engine_failure_paths(self):
        self.assertIsNotNone(shutil.which('node'), 'Node.js is required; browser recovery tests must not be skipped')
        run = subprocess.run(['node', '--test', str(ROOT / 'tests/js/app_release_upload.test.js')],
                             cwd=ROOT, capture_output=True, text=True, timeout=90)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
