"""Real 8-KiB/s Chromium probe: exceed 45 s, then succeed with extended deadline.

Run after installing tests/e2e/requirements.txt and Chromium. Synthetic local
bootstrap and unsigned APK only; no deployed traffic or credentials.
"""
import argparse
import asyncio
import hashlib
import json
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import zipfile
from apk_upload_browser import ROOT, fixture
from playwright.async_api import async_playwright


async def run(output):
    output.mkdir(parents=True, exist_ok=True)
    cache = ROOT / '.cache'; cache.mkdir(exist_ok=True)
    report = {'status': 'RUNNING', 'upload_kib_per_second': 8, 'latency_ms': 250}
    with tempfile.TemporaryDirectory(prefix='apk-slow-probe-', dir=cache) as tmp:
        web, apk = fixture(Path(tmp), 0)
        with zipfile.ZipFile(apk, 'a', zipfile.ZIP_STORED) as archive:
            archive.writestr('slow-probe.bin', bytes(400 * 1024))
        with apk.open('rb') as f: expected = hashlib.file_digest(f, 'sha256').hexdigest()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        started = time.monotonic()
        requests = []
        browser = None
        with (output / 'php-fixture.log').open('w') as log:
            server = subprocess.Popen(['php', '-d', 'memory_limit=128M', '-S', f'127.0.0.1:{port}', '-t', str(web)], stdout=log, stderr=log)
            try:
                async with async_playwright() as p:
                    browser = await p.chromium.launch(headless=True, args=['--no-sandbox'])
                    context = await browser.new_context()
                    page = await context.new_page()
                    for _ in range(100):
                        try: await context.request.get(base + '/fixture.php', timeout=1000); break
                        except Exception:
                            if server.poll() is not None: raise RuntimeError('PHP fixture exited')
                            await asyncio.sleep(.02)
                    else: raise RuntimeError('Fixture startup failed')
                    await page.goto(base + '/login.php')
                    cdp = await context.new_cdp_session(page)
                    await cdp.send('Network.enable')
                    await cdp.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 250,
                        'downloadThroughput': 1048576, 'uploadThroughput': 8192, 'connectionType': 'cellular2g'})
                    await page.evaluate('''() => {
                        window.__slowProbe = [];
                        const nativeFetch = window.fetch;
                        window.fetch = function(url, options) {
                            if (options && options.body instanceof FormData && options.body.get('action') === 'upload_chunk') {
                                window.__slowProbe.push({time: performance.now() / 1000, chunk_index: options.body.get('chunk_index')});
                            }
                            return nativeFetch.apply(this, arguments);
                        };
                    }''')
                    await page.locator('#upload-apk-input').set_input_files(apk)
                    await page.locator('#btn-start-upload').click()
                    await page.locator('#upload-success-state').wait_for(state='visible', timeout=200000)
                    requests = await page.evaluate('window.__slowProbe')
                    assert len(requests) == 2, f'Expected initial timeout and one successful retry, got {len(requests)}'
                    assert all(request['chunk_index'] == '0' for request in requests)
                    spacing = requests[1]['time'] - requests[0]['time']
                    assert 44 <= spacing <= 65, spacing
                    with (web / 'releases/fkss.apk').open('rb') as f: actual = hashlib.file_digest(f, 'sha256').hexdigest()
                    assert expected == actual
                    assert await page.evaluate('localStorage.getItem("fkss-apk-upload:v2:7")') is None
                    elapsed = time.monotonic() - started
                    assert elapsed > 90, f'Expected real slow transfer, got {elapsed}s'
                    await page.screenshot(path=str(output / 'success.png'), full_page=True)
                    report.update(status='PASS', size_bytes=apk.stat().st_size, duration_seconds=round(elapsed, 2),
                        chunk_attempts=2, retry_spacing_seconds=round(spacing, 2),
                        expected_sha256=expected, actual_sha256=actual, browser=browser.version,
                        result='45-second native abort recovered with the 90-second retry deadline',
                        limitations=['Synthetic localhost bootstrap/APK, not hosted or signed-device acceptance'])
                    await browser.close(); browser = None
            except BaseException as error:
                report.update(status='FAIL', error=repr(error), duration_seconds=round(time.monotonic()-started, 2))
                raise
            finally:
                if browser:
                    try: await browser.close()
                    except Exception: pass  # Preserve the test failure and always terminate PHP.
                server.terminate()
                try: server.wait(timeout=5)
                except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)
                (output / 'result.json').write_text(json.dumps(report, indent=2) + '\n')
                print(json.dumps(report, indent=2), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path, required=True)
    asyncio.run(run(parser.parse_args().output))
