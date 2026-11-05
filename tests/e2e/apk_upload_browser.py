"""Real Chromium -> actual dashboard section/JS -> actual PHP API/filesystem.

Synthetic login/CSRF bootstrap, disposable data, no deployed traffic/database.
Run explicitly; not part of fast unit collection. Playwright is required.
CDP shapes native browser traffic. Instrumentation observes only FormData
metadata and discards selected responses after native fetch has committed them;
no binary multipart payload is copied through the automation driver.
"""
from __future__ import annotations
import argparse
import asyncio
import hashlib
import json
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tests/security'))
from apk_fixture import axml_manifest, native_headers
from playwright.async_api import async_playwright


def fixture(home: Path, size_mib: int):
    web = home / 'site'
    (web / 'admin/backend').mkdir(parents=True)
    (web / 'admin/js').mkdir()
    (web / 'api/v1').mkdir(parents=True)
    (web / 'releases').mkdir()
    (web / 'admin/backend/services').symlink_to(ROOT / 'admin/backend/services', target_is_directory=True)
    (web / 'api/v1/core').symlink_to(ROOT / 'api/v1/core', target_is_directory=True)
    for src, dst in [('admin/api_app_release.php', 'admin/api_app_release.php'),
                     ('admin/js/app_release.js', 'admin/js/app_release.js'),
                     ('admin/dashboards/sections/app_release_section.php', 'admin/section.php'),
                     ('admin/backend/danger_confirm.php', 'admin/backend/danger_confirm.php')]:
        shutil.copyfile(ROOT / src, web / dst)
    (web / 'admin/config.php').write_text("""<?php
        define('ROOT_PATH', dirname(__DIR__)); session_start(); $conn = null;
        function validateCsrf($token) { return is_string($token) && hash_equals('synthetic-csrf', $token); }
        function csrfField() { return '<input type="hidden" name="csrf_token" value="synthetic-csrf">'; }
    """)
    (web / 'login.php').write_text("""<?php session_start();
        $_SESSION=['admin_id'=>7,'admin_logged_in'=>true,'admin_role'=>'super_admin','admin_username'=>'browser-fixture'];
        header('Location: /fixture.php');
    """)
    (web / 'fixture.php').write_text("""<?php require __DIR__.'/admin/config.php'; $activeSection='app_release'; ?>
        <!doctype html><html><head><meta charset="utf-8"><title>Local APK recovery test</title>
        <style>body{background:#0f172a;color:#eee;font:16px sans-serif;margin:30px}button{cursor:pointer}</style>
        </head><body><?php require __DIR__.'/admin/section.php'; ?>
        <script>window.SA_BOOT={csrf:'synthetic-csrf',upload_owner:7};</script>
        <script src="/admin/js/app_release.js"></script></body></html>
    """)
    (web / 'api/v1/app_release.local.php').write_text(
        "<?php return ['force_update'=>true,'min_build'=>30,'background_drains_enabled'=>false];")
    apk = home / 'large.apk'
    with zipfile.ZipFile(apk, 'w', zipfile.ZIP_STORED) as z:
        z.writestr('META-INF/MANIFEST.MF', 'Manifest-Version: 1.0\n')
        z.writestr('AndroidManifest.xml', axml_manifest('com.arkeonethiopia.fkss', '1.7.4', 41))
        for name, head in native_headers():
            z.writestr(name, head)
        with z.open('classes.dex', 'w') as f:
            f.write(b'dex\n035\x00')
            block = bytes(1024 * 1024)
            for _ in range(size_mib): f.write(block)
    return web, apk


async def run(args):
    args.output.mkdir(parents=True, exist_ok=True)
    cache = ROOT / '.cache'
    cache.mkdir(exist_ok=True)
    # Large synthetic files must never enter the persisted repository/snapshot.
    with tempfile.TemporaryDirectory(prefix='apk-browser-', dir=cache) as tmp:
        home = Path(tmp)
        web, apk = fixture(home, args.size_mib)
        if args.exact_limit:
            name = 'boundary.bin'
            padding = 536870912 - apk.stat().st_size - (76 + 2 * len(name))
            assert 0 < padding < 1048576
            with zipfile.ZipFile(apk, 'a', zipfile.ZIP_STORED) as archive:
                archive.writestr(name, bytes(padding))
            assert apk.stat().st_size == 536870912
        size = apk.stat().st_size
        assert size > args.size_mib * 1048576
        with apk.open('rb') as source:
            expected_sha = hashlib.file_digest(source, 'sha256').hexdigest()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        started = time.monotonic()
        events, requests, chunk_counts = [], {}, {}
        state = {'page_errors': []}
        report = {'status': 'RUNNING', 'size_bytes': size, 'upload_kib_per_second': args.upload_kib,
                  'latency_ms': args.latency_ms, 'source_sha256': expected_sha}
        php_log = args.output / 'php-fixture.log'
        with php_log.open('w') as log:
            server = subprocess.Popen(['php', '-d', 'memory_limit=128M', '-d', 'upload_max_filesize=2M',
                '-d', 'post_max_size=8M', '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', '-t', str(web)], stdout=log, stderr=log)
            browser = None
            try:
                async with async_playwright() as p:
                    browser = await p.chromium.launch(headless=True, args=['--no-sandbox'])
                    context = await browser.new_context(viewport={'width': 1280, 'height': 1000})
                    page = await context.new_page()
                    page.on('pageerror', lambda error: state['page_errors'].append(str(error)))
                    for _ in range(100):
                        try:
                            await context.request.get(base + '/fixture.php', timeout=1000)
                            break
                        except Exception:
                            if server.poll() is not None: raise RuntimeError('PHP fixture exited')
                            await asyncio.sleep(.02)
                    else: raise RuntimeError('PHP fixture not ready')
                    await page.goto(base + '/login.php')
                    await page.wait_for_function('!!window.AppReleaseUI')
                    cdp = await context.new_cdp_session(page)
                    await cdp.send('Network.enable', {'maxPostDataSize': 0})
                    await cdp.send('Network.emulateNetworkConditions', {'offline': False,
                        'latency': args.latency_ms, 'downloadThroughput': args.upload_kib * 1024,
                        'uploadThroughput': args.upload_kib * 1024, 'connectionType': 'cellular3g'})

                    async def instrument(seed=None):
                        await page.evaluate(r"""({seed, stopAt}) => {
                            const nativeFetch = window.fetch;
                            const log = window.__e2e = seed || {phase:1, descriptor:null, faults:[], events:[], counts:{}, chunks:{}, phase2:[]};
                            window.fetch = async function(url, options) {
                                const body = options && options.body;
                                if (!(body instanceof FormData)) return nativeFetch.apply(this, arguments);
                                const action = body.get('action');
                                log.counts[action] = (log.counts[action] || 0) + 1;
                                const once = name => { if (log.faults.includes(name)) return false; log.faults.push(name); return true; };
                                if (action === 'upload_status') log.descriptor = Object.fromEntries(body.entries());
                                if (action === 'upload_chunk') {
                                    const index = Number(body.get('chunk_index'));
                                    log.chunks[index] = (log.chunks[index] || 0) + 1;
                                    if (log.phase === 2) log.phase2.push(index);
                                    if (index === 7 && once('503')) {
                                        log.events.push('Injected retryable HTTP 503 on part 8');
                                        return new Response(JSON.stringify({status:'error',retryable:true,message:'Synthetic temporary rejection'}), {status:503});
                                    }
                                    if (index === 13 && once('lost_chunk_ack')) {
                                        const result = await nativeFetch.apply(this, arguments);
                                        if (!result.ok) throw new Error('Lost-ACK setup did not store the chunk');
                                        await result.text();
                                        log.events.push('Server stored part 14; its acknowledgment was discarded');
                                        throw new TypeError('Synthetic lost chunk response');
                                    }
                                    if (index === stopAt && once('stop')) {
                                        log.events.push('Injected terminal stop before reload');
                                        return new Response(JSON.stringify({status:'error',retryable:false,message:'Synthetic session interruption; reload and resume'}), {status:403});
                                    }
                                }
                                if (action === 'assemble_chunks') {
                                    if (!once('lost_finalize_ack')) throw new Error('Finalization was unexpectedly repeated');
                                    const result = await nativeFetch.apply(this, arguments);
                                    if (!result.ok) throw new Error('Finalize setup failed: ' + await result.text());
                                    await result.text();
                                    log.events.push('Server published APK; final HTTP response was discarded');
                                    throw new TypeError('Synthetic lost publication response');
                                }
                                return nativeFetch.apply(this, arguments);
                            };
                        }""", {'seed': seed, 'stopAt': min(40, max(3, args.size_mib // 2))})

                    await instrument()
                    await page.locator('#upload-apk-input').set_input_files(apk)
                    await page.locator('#btn-start-upload').click()
                    await page.locator('#upload-error-state').wait_for(state='visible', timeout=240000)
                    assert 'Synthetic session interruption' in await page.locator('#upload-error-message').inner_text()
                    observed = await page.evaluate('window.__e2e')
                    descriptor = observed['descriptor']
                    assert descriptor
                    progress = await context.request.post(base + '/admin/api_app_release.php', multipart=descriptor, timeout=30000)
                    saved = await progress.json()
                    assert progress.status == 200, saved
                    received = set(saved['data']['received'])
                    assert received, 'No valid parts survived interruption'
                    report['verified_parts_before_reload'] = len(received)
                    print(f'INTERRUPTION: {len(received)} verified parts retained; reload next', flush=True)
                    await page.screenshot(path=str(args.output / 'interrupted.png'), full_page=True)
                    # Real page reload destroys JS state. Origin storage and session cookie remain.
                    await page.reload()
                    await page.wait_for_function('!!window.AppReleaseUI')
                    observed['phase'] = 2
                    await instrument(observed)
                    await page.wait_for_timeout(3000)
                    await page.locator('#upload-apk-input').set_input_files(apk)
                    await page.locator('#btn-start-upload').click()
                    deadline = time.monotonic() + args.timeout
                    last_print = 0
                    while not await page.locator('#upload-success-state').is_visible():
                        if await page.locator('#upload-error-state').is_visible():
                            raise AssertionError(await page.locator('#upload-error-message').inner_text())
                        if time.monotonic() > deadline: raise TimeoutError('Large browser upload exceeded test deadline')
                        if time.monotonic() - last_print >= 30:
                            label = await page.locator('#upload-pct-display').inner_text()
                            metrics = await page.evaluate('({attempts:Object.values(window.__e2e.chunks).reduce((a,b)=>a+b,0),events:window.__e2e.events})')
                            events = metrics['events']
                            print(f'PROGRESS: {label}; elapsed={time.monotonic()-started:.1f}s; part requests={metrics["attempts"]}', flush=True)
                            last_print = time.monotonic()
                        await page.wait_for_timeout(500)
                    observed = await page.evaluate('window.__e2e')
                    events = observed['events']
                    requests, chunk_counts = observed['counts'], observed['chunks']
                    assert not received.intersection(observed['phase2']), 'Reload resent already-verified parts'
                    assert {'503', 'lost_chunk_ack', 'stop', 'lost_finalize_ack'} == set(observed['faults']), observed['faults']
                    assert requests['assemble_chunks'] == 1
                    artifact = web / 'releases/fkss.apk'
                    with artifact.open('rb') as f: actual_sha = hashlib.file_digest(f, 'sha256').hexdigest()
                    assert actual_sha == expected_sha
                    assert artifact.stat().st_size == size
                    current = await (await context.request.get(base + '/admin/api_app_release.php?action=get_release')).json()
                    release = current['data']
                    assert release['artifacts_detail']['universal']['sha256'] == expected_sha
                    assert release['latest_build'] == 41 and release['latest_version'] == '1.7.4'
                    assert release['force_update'] is True and release['min_build'] == 30
                    assert release['background_drains_enabled'] is False
                    assert not state['page_errors'], state['page_errors']
                    assert await page.evaluate('localStorage.getItem("fkss-apk-upload:v2:7")') is None
                    receipt = json.loads((web / 'releases/.tmp_chunks' / descriptor['upload_id'] / 'session.json').read_text())
                    assert receipt['state'] == 'published' and receipt['sha256'] == expected_sha
                    assert list((web / 'releases/.tmp_chunks' / descriptor['upload_id']).glob('chunk_*')) == []
                    elapsed = time.monotonic() - started
                    # Detect an accidentally ineffective throughput profile, rather than claiming a shaped run.
                    assert elapsed >= size / (args.upload_kib * 1024) * .65, f'Throttle ineffective: {elapsed}s'
                    await page.screenshot(path=str(args.output / 'success.png'), full_page=True)
                    report.update(status='PASS', duration_seconds=round(elapsed, 2), browser=browser.version,
                        published_sha256=actual_sha, total_parts=int(descriptor['total_chunks']),
                        chunk_requests=sum(chunk_counts.values()), finalize_requests=requests['assemble_chunks'],
                        resumed_already_verified_parts_resent=0, events=events,
                        page_errors=state['page_errors'], php_memory_limit='128M', policy_preserved=True,
                        limitations=['Synthetic login bootstrap/no real database', 'Loopback server; no hosting quota/WAF parity',
                                     'All actual payload requests use native Chromium/CDP; only explicit synthetic rejections/lost responses are injected',
                                     'Synthetic unsigned structurally valid APK; not owner device/signature acceptance'])
                    await browser.close(); browser = None
            except BaseException as error:
                report.update(status='FAIL', error=repr(error), duration_seconds=round(time.monotonic()-started, 2), events=events)
                raise
            finally:
                if browser:
                    try: await browser.close()
                    except Exception: pass  # Preserve the test failure and always terminate PHP.
                server.terminate()
                try: server.wait(timeout=5)
                except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)
                (args.output / 'result.json').write_text(json.dumps(report, indent=2) + '\n')
                print(json.dumps(report, indent=2), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--size-mib', type=int, default=320)
    parser.add_argument('--exact-limit', action='store_true', help='Generate an exact 512-MiB APK; requires size-mib 511')
    parser.add_argument('--upload-kib', type=int, default=512)
    parser.add_argument('--latency-ms', type=int, default=250)
    parser.add_argument('--timeout', type=int, default=1800)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if not (16 <= args.size_mib <= 511 and args.upload_kib > 0 and args.latency_ms >= 0):
        parser.error('size-mib must be 16..511; upload-kib positive; latency nonnegative')
    if args.exact_limit and args.size_mib != 511:
        parser.error('--exact-limit requires --size-mib 511')
    asyncio.run(run(args))
