# APK upload reliability implementation — 2026-10-27

> Historical first implementation. The later owner-approved 512-MiB size/count/deadline changes and real-browser results supersede this report’s limits/cache tag: see `APK_UPLOAD_512MIB_E2E_2026-10-27.md`.

## 1. Request
Deeply analyze and improve the development dashboard APK uploader to an enterprise-quality standard, keeping changes isolated and production protected. This work hardens transfer, resume, finalization and publication; it is not a certification of the entire platform.

## 2. Current Problem
**VERIFIED:** The supplied screenshot reports Universal part 7/165 failing after eight retries with `Failed to fetch`. The displayed existing 1.6.10/build 36 artifact is not evidence of the selected file's version or size. Retrying previously appeared to restart progress.

**UNKNOWN:** The original browser/network/proxy/hosting cause. Anonymous HTTP checks cannot diagnose an authenticated administrator's session, account quota or protected PHP runtime.

## 3. Root Cause
**VERIFIED, separate reproducible defects:** The old scheduler could enter assembly with an unacknowledged part waiting on a retry timer; the old server assembly failure path deleted retained parts. These defects explain a recoverability risk, not the actual screenshot's unidentified transport failure. Original diagnostic: `/home/user/apk-upload-analysis/browser_scheduler_diagnostic.json`; original analysis: `/home/user/FKSS_APK_Upload_Deep_Analysis.md`.

Source-level correction evidence: `admin/js/app_release.js:441` (`workerLoop`) gates assembly on all acknowledgments, no in-flight requests and no pending retries; `admin/backend/services/AppReleaseManager.php:458` (`assembleChunks`) retains parts on recoverable failure. Runtime tests exercise both behaviors.

## 4. Solution
Implement checksum-bound filesystem sessions, verified resume, attempt-owned asynchronous state, bounded requests/retries, adaptive concurrency, idempotent finalization receipts, and serialized publication with rollback for caught failures. Separate an audit-log failure from an already successful publication.

No automatic binary-to-base64 workaround for authentication, CSRF, size or network failures. The legacy server transport remains supported. Unidentified network failures still require hosting evidence if they recur.

## 5. Files Changed
- `admin/backend/services/AppReleaseUploadStore.php` — new bounded session persistence, metadata validation, locks, checksums and structured errors.
- `admin/backend/services/AppReleaseManager.php` — retained/verified parts, receipts, cancellation tombstones, lock-aware cleanup, publication serialization and rollback; staging/backups moved into protected temporary storage.
- `admin/api_app_release.php` — descriptor integration, structured responses, no-store, independent audit acknowledgment.
- `admin/js/app_release.js` — replacement attempt-owned upload engine; removal of unused legacy client machinery.
- `admin/dashboards/sections/app_release_section.php` — one file-change binding and clearer recovery/publication labels.
- `admin/dashboards/super-admin.php` — numeric administrator storage namespace and cache version `20261027upload2`.
- `tests/security/test_apk_upload_reliability.py` — PHP/filesystem, real HTTP multipart and JavaScript wrapper tests.
- `tests/js/upload_harness.js`, `tests/js/app_release_upload.test.js` — actual production engine exercised with controlled DOM, clock, transport and native Web Crypto.
- `tests/security/test_app_release_management.py`, `tests/security/test_app_release_from_apk.py` — update obsolete client/base64 and cache-tag contracts without removing backend compatibility checks.
- `.github/workflows/backend-checks.yml` — explicitly install Node 22; missing Node fails the new tests instead of skipping.
- This report.

Unchanged source files materialized from HEAD solely to satisfy the sparse checkout: `api/v1/routes/app.php`, `admin/backend/danger_confirm.php`. Original dirty checkout and unrelated work were not modified.

## 6. Logic Changed
**VERIFIED locally:**
- A manifest binds file size, bounded part size, ABI and every part SHA-256. Canonical fingerprint: SHA-256 of `size:partSize:abi:` followed by concatenated part hashes. No whole-APK browser allocation for hashing.
- Session IDs: 8–64 allowed characters; at most 4,096 parts, 1 MiB each; APK maximum remains 250 MiB. Browser normally uses up to 512 KiB, reduced according to preflight PHP limits.
- Session metadata and chunks use temporary-file replacement. Metadata corruption fails closed; invalid chunks never overwrite valid saved chunks. Status hashes saved parts, not just filenames.
- Resume records expire after four hours, are administrator-namespaced, and contain only identity metadata, not APK bytes, filenames, session cookies or CSRF tokens. File content is recomputed before reusing an ID. Blocked localStorage retains same-page recovery in memory.
- Failed/unverified status never silently starts full-file transfer. Progress reflects acknowledged bytes; resumed bytes do not inflate transfer speed.
- Each attempt owns controllers, timers and counters. Old callbacks cannot change a newer attempt. Requests have 45-second deadlines; finalization has a 180-second deadline. Up to eight part retries with bounded backoff; concurrency reduces 4 → 2 → 1 after transient failures.
- No assembly until every expected part is acknowledged and no active/pending retry remains. Missing/corrupt parts survive assembly errors for resend.
- Receipt-based reconciliation distinguishes a lost final response from failed publication; old receipts cannot overwrite a newer artifact. Cancellation writes a tombstone, blocks late chunks and cannot unpublish a completed artifact. Reset retains recoverable parts.
- Publication/config/delete operations share a lock. Upload validates the APK again inside that lock, backs up artifact/config, replaces artifact and settings, writes its receipt, then removes chunks. Caught receipt/config errors attempt rollback. This is **not crash-atomic**, not journaled and not a database transaction.

Evidence entry points: `AppReleaseUploadStore.php:24/41/89/133` (`locked/read/bind/validPart`); `AppReleaseManager.php:94/283/375/458/540/653` (`cleanupOldChunks/handleChunkUpload/uploadStatus/assembleChunks/publishStaged/cancelUpload`); `app_release.js:257/293/354/414/441/484/554` (`readResume/requestJson/startUpload/beginTransfer/workerLoop/handlePartFailure/completeUpload`).

## 7. Database Changes
None. No migrations or data modifications. Existing `activity_logs` writes are retained. Filesystem publication and database audit are explicitly not one transaction.

## 8. API Changes
Existing action names and authorization remain. New client POSTs `upload_status` with `part_size`, JSON `part_hashes`, `fingerprint`, `abi`, `total_size` and `total_chunks`. Status returns `verified`, state and exact valid received/missing indices; published sessions include release information. Chunks acknowledge index, exact size and SHA-256.

Structured errors include `code`, `retryable` and `details` with appropriate HTTP status (including 423 for busy locks). Legacy chunk/base64/resumable parameters remain accepted. Publication/config success includes `audit_logged`; false produces a warning, not a false upload failure. JSON null, malformed receipts and mismatched acknowledgments cannot become success.

A cached old client cannot obtain the new client's checksum-verified resume guarantees; it should be refreshed. Existing chunk-only legacy sessions are not silently upgraded to verified sessions. No bulk deletion or forced restart of stored sessions.

## 9. Security Changes
**VERIFIED in source and synthetic endpoint tests:** Super Admin authorization and POST CSRF checks remain; unauthenticated/incorrect-role/incorrect-CSRF requests are rejected. Existing package/build/ABI/downgrade validation and typed destructive confirmations remain. The HTTP fixture supplies synthetic session/CSRF bootstrap; it does **not** prove the real deployment's login/session configuration.

Strict upload bounds and identity checks; no client WAF/auth bypass, no APK/token persistence in localStorage, no executable staging extension, no new third-party browser dependency. Temporary parts, staging and rollback backups reside under the existing deny-protected `.tmp_chunks` tree rather than placing rollback config copies in public paths.

**DEPENDENCY NOT VERIFIED:** LiteSpeed/Apache must enforce the deployed temporary-storage deny rules, filesystem locks and same-host writable storage. The local PHP built-in server does not enforce `.htaccess`; it is used only as a loopback synthetic HTTP parser fixture. No new hosting configuration, permissions or production settings were changed. Existing broad Super Admin privileges are not a per-user filesystem isolation boundary. This change does not introduce account-wide storage quotas or a new rate-limiting service.

## 10. UI/UX Changes
Retry verifies server progress before sending. File/ABI changes are blocked during an active attempt. Error text preserves HTTP/network evidence and explains retained parts. Acknowledge-based progress does not claim publication at transfer completion. Publication is a distinct validation stage. Selecting a file has a single idempotent event binding. Reset is labeled “Choose file again”; cancellation warns if remote cleanup cannot be confirmed. An audit failure warns after successful publication.

## 11. Testing
**VERIFIED locally, PHP 8.4.27 / Node 20.20.2:**

```text
python3 -m pytest tests/security/test_apk_upload_reliability.py \
  tests/security/test_app_release_management.py \
  tests/security/test_app_release_from_apk.py \
  tests/security/test_apk_manifest.py -q
58 passed

node --test tests/js/app_release_upload.test.js
18 passed, 0 failed, 0 skipped
```

The 58 Python tests include one wrapper that executes those 18 JavaScript cases; do not count the wrapper as independent browser acceptance. New suite: 15 PHP/service/HTTP cases plus the Node wrapper. Tests cover checksum corruption, incomplete assembly retention, resend, descriptor mismatch, invalid bounds/ID, policies, duplicate/lost finalization, superseded receipts, cancellation, read-only settings, receipt-write rollback, session lock/GC, and corrupt metadata.

Real HTTP multipart test runs the actual API/parser/service with synthetic login configuration on loopback: 401/403 gates, binary uploaded-file handling, duplicate part, progress, publication and idempotent receipt. There is no database in that fixture; `audit_logged:false` is verified.

JavaScript cases include pending-retry assembly gate, stale completion, adaptive concurrency, status refusal, reload resume, same-size different-file rejection, 401/403/413 without base64, JSON null, lost final response, cancellation, deadlines, malformed ACK/receipt, blocked localStorage, bounded retry exhaustion and idempotent binding.

PHP syntax checks for all five touched PHP files, `node --check` and `git diff --check` pass. CI now specifies Node 22, but **that CI runtime has not been executed here**. No full platform suite or real browser/device acceptance was performed in this sparse checkout. Historical unrelated skips are not resolved by these scoped results.

## 12. Regression Check
Existing release-management, APK-derived version/policy and manifest suites pass alongside the new failure tests. Legacy server base64 support remains tested. Upload leaves Force Update/minimum build/background-sync policy unchanged; invalid/older/wrong-package APK validation remains. No mobile source, database, attendance, finance or unrelated modules changed. Original worktree is preserved; no production repository/server operations.

## 13. Remaining Risks
- **UNKNOWN:** Original `Failed to fetch` cause. Authenticated hosting preflight, browser request evidence, proxy/WAF/access/error logs, quota and timeouts remain needed if it recurs.
- **DEPENDENCY NOT VERIFIED:** Deployed PHP parity/runtime, Web Crypto/HTTPS, storage protections, quota/capacity, locks and filesystem rename semantics. Full-size (~82 MiB selected incident file) uploads, mobile/background tab interruption, real browser reload and signed APK acceptance need owner testing.
- Caught-error rollback is tested; process kill/power loss between artifact/config/receipt writes is **not** crash-safe. Operators must inspect settings/artifact/retained backup files after such an event. Temporary cleanup may expire old sessions/backups; do not treat it as a disaster-recovery system. Downloads can briefly observe artifact/config changes separately; cross-file publication is not fully atomic.
- Checksums verify transport identity, not APK signing provenance. Owner builds/signs and verifies the APK on the device.
- Audit failure remains a compliance gap requiring operator follow-up, even though publication is not falsely reported failed. No durable audit outbox was introduced.
- Browser storage allows one resumable descriptor per administrator/browser origin; choosing another file can replace that hint. Server parts expire through ordinary cleanup. No service-worker/background upload or cross-device resume discovery.
- Remote push, CI, main integration, deployment and owner acceptance must be reported separately; local tests are not production readiness certification.

Development acceptance sequence: review/push branch → CI/review → authorized development-main integration → cron deployment verification → hard refresh → inspect authenticated preflight → signed APK upload with deliberate interruption/reload/retry → verify receipt, published SHA/version and unchanged policy → owner phone test. Production promotion requires a separate explicit owner confirmation.

Rollback: pause active development uploads, preserve required APK/config/backup evidence, revert this scoped change on development main, redeploy the complete PHP+JS set together and hard refresh. No database rollback. Do not delete active sessions or replace the owner’s release files blindly. New protocol clients deliberately reject unverified older status endpoints; avoid mixed-version deployment windows.

## 14. Assumptions
The source base is development main `105007b9afb6c5bbfe803b369f59a21830a6b5aa`, not proof of deployed PHP parity. Existing single-host local storage architecture and authorization model are retained. PHP 8.4 was tested; deployed runtime compatibility must be checked. No SSH/server access or authenticated owner browser session is available. The original supplied access token is not present in this continuation and must not be recovered by searching secret storage or written into remotes/reports.

## 15. Final Status
**COMPLETE — PARTIALLY VERIFIED** for the scoped local implementation and executable regression tests. **Release is not complete:** remote push/CI, development integration/deployment and owner acceptance remain unverified. Production is untouched and not authorized for promotion.

Working branch: `fix/apk-upload-reliability`; isolated worktree: `/home/user/fkss-upload-hardening`. Commit and remote publication outcomes are reported separately after the repository operations, not fabricated in advance. Workspace measured before this report: approximately 3,660 nonexcluded entries / 54.2 MiB, excluding Git administration and tool/cache directories (not an exact snapshot guarantee).
