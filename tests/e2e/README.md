# Explicit real-browser APK acceptance test

This is a disposable local integration fixture, **not** a deployment test. It
renders the production dashboard section, loads the production JS, and uses the
actual PHP API/services/parser with a synthetic session/CSRF bootstrap. It does
not call a deployed system or require real administrator credentials. The APK
contains a real binary Android manifest but is synthetic/unsigned and is not
phone/signature acceptance.

```sh
python -m pip install -r tests/e2e/requirements.txt
python -m playwright install --with-deps chromium
python -u tests/e2e/apk_upload_browser.py --size-mib 320 --upload-kib 256 \
  --latency-ms 250 --timeout 1800 --output browser-evidence
```

The default is slightly larger than 320 MiB (335,544,320 bytes of padding plus
ZIP/manifest overhead), well above 300 MB. Chromium CDP caps browser upload at
256 KiB/s (2.097 Mbps), with 250-ms latency. A complete run takes roughly 22–30
minutes; a 16-MiB / 1024-KiB/s smoke run is also supported. Throughput, timer,
browser and exact file measurements are recorded in `result.json`. An elapsed
time sanity check fails if the requested network shaping appears ineffective.

Faults: retryable HTTP 503; one chunk persisted with its ACK dropped; a terminal
interruption followed by an actual page reload and file reselection; final
publication followed by a dropped response. The test requires exact whole-file
SHA-256 equality, unchanged update policy, one finalization request, receipt
reconciliation, no browser exceptions, cleared browser resume record and zero
resends of parts already verified before reload.

A test-only native-fetch wrapper observes just action/index/timestamps and
injects the explicit 503/403 responses. It discards one real chunk response and
the real finalization response **after native Chromium fetch has received
server success**. All actual payload requests, including those lost-ACK cases,
use Chromium networking and the same CDP shaping; the automation driver never
copies whole multipart payloads. Injected 403 is synthetic, not proof of real
session-expiry behavior. Browser metadata is preserved by the harness across
reload only for test assertions; resume itself uses the production localStorage
record, not that test metadata. Actual JS state is destroyed on reload.
The fixture limits PHP to 128 MiB memory, 2-MiB uploaded files per request and
8-MiB POSTs. Multi-hundred-MiB transfer uses bounded requests, not an enormous
single PHP upload. File/part count boundary tests are in the fast security suite.

Large source/staging/parts are created only inside excluded `.cache/` and are
removed on normal exit, including failures. Peak disk usage is approximately
three times the APK size plus browser/tool space. Evidence has no real user data
or credentials. Do not upload the fixture directory or browser profiles as CI
artifacts. Interrupted processes may leave `.cache/apk-browser-*`; clean only
the exact disposable fixture after confirming its process has stopped.

The manually dispatched `APK upload browser acceptance (synthetic)` workflow
runs this same test. This does not replace existing backend CI or owner testing
of hosted quota/WAF/timeouts, deployed PHP parity, real APK signing and a phone.
No guarantee is made for arbitrary network speeds/outages or Unigram feature
parity. Retained sessions expire after four hours; individual part retries stay
bounded (45→90→180→300-second deadlines; eight retries).

## Severe link / real deadline probe

```sh
python -u tests/e2e/apk_slow_link_probe.py --output browser-evidence/very-slow
```

This sends a ~401-KiB synthetic APK at an actual Chromium upload cap of 8 KiB/s
(~65.5 kbps), with 250-ms latency. The first part exceeds its 45-second deadline
and is aborted; the same part must succeed on its 90-second retry. The test
requires exactly two part attempts and exact published SHA-256 equality, and
normally takes about 100 seconds. It does not extrapolate a 320-MiB transfer at
that speed or prove unlimited outage recovery. The browser's native fetch is
wrapped only to record outgoing index/timestamps; requests are not mocked,
fulfilled or forwarded outside Chromium in this probe.

## Exact 512-MiB boundary acceptance

```sh
python -u tests/e2e/apk_upload_browser.py --size-mib 511 --exact-limit \
  --upload-kib 16384 --latency-ms 10 --timeout 600 --output browser-evidence/exact-limit
```

This constructs a structurally valid APK of **exactly 536,870,912 bytes**. It
exercises the same fault/reload/receipt assertions, at a faster 16-MiB/s profile
to test the complete boundary without conflating it with the separate slow-link
run. The fast security suite independently rejects boundary+1 byte and verifies
the 8,192-part worst-case manifest. The manual workflow runs the severe link
probe, exact-limit acceptance, then the configured large slow-link run.

Run the large cases **sequentially**, as the workflow does. Earlier concurrent
runs with binary-request routing hit automation timeouts and are not acceptance
passes. The final harness avoids payload copies through automation and checks
full completion/hash equality rather than counting partial transfer progress.
