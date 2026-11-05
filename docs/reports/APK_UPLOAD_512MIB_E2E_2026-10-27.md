# 512-MiB APK upload and end-to-end acceptance — 2026-10-27

## 1. Request
Run end-to-end tests proving upload above 300 MB on a sluggish connection, then merge development GitHub main. Owner explicitly selected a new **512 MiB** maximum. Production promotion is not authorized. This report supersedes the limits/cache tag in the earlier upload-hardening implementation report, while retaining that report as historical evidence.

## 2. Current Problem
**VERIFIED:** The prior client and service capped APKs at 262,144,000 bytes (250 MiB), making the requested size impossible. A fixed 45-second part deadline could also repeatedly abort a valid but very slow transfer. The screenshot's original `Failed to fetch` hosting/network cause is still **UNKNOWN**; these tests are not a diagnosis of that event.

## 3. Root Cause
**VERIFIED in source:** Client/server hard size bounds; 4,096-part bound insufficient for a 512-MiB file using minimum 64-KiB parts; fixed per-part timeout. During review, `getPreflightInfo` was also found to create `.tmp_chunks` before `getChunkDirectory`, which only wrote its deny file when creating the directory itself. Two executable regressions failed against that behavior before its correction.

**POTENTIAL SECURITY CONCERN — REQUIRES VERIFICATION on the host:** Missing deny-file creation may leave temporary files directly accessible if deployment rules do not independently protect the directory. Actual hosted exposure is not proven by this local finding.

## 4. Solution
Raise both file bounds to **536,870,912 bytes**, permit **8,192 parts**, and extend per-part retry deadlines **45 → 90 → 180 → 300 seconds**, capped at 300 seconds with the existing eight-retry limit. Preserve checksum-verified resume, adaptive concurrency, retained parts, ACK-gated assembly and publication receipts from commit `6fb2619`.

Protect temporary storage before creating session data even when preflight has already made the directory; fail closed if the missing deny rules cannot be written. Existing nonmissing rules are preserved, not silently rewritten. Add reproducible real-Chromium tests and a manually dispatched GitHub Actions workflow.

## 5. Files Changed
Follow-up to `6fb2619`:
- `.gitignore` — exclude disposable `.cache` fixtures and generated browser evidence.
- `admin/backend/services/AppReleaseManager.php` — 512-MiB limit and temporary-storage protection fix.
- `admin/backend/services/AppReleaseUploadStore.php` — 8,192-part bound.
- `admin/js/app_release.js` — matching size/count bounds and capped retry deadlines.
- `admin/dashboards/sections/app_release_section.php` — visible maximum; correct obsolete “2MB chunks” label; avoid an unverified “cPanel Safe” guarantee.
- `admin/dashboards/super-admin.php` — cache tag `20261027upload3`.
- `tests/security/test_apk_upload_reliability.py` — full boundary/part-count and protection regressions.
- `tests/security/test_app_release_management.py`, `test_app_release_from_apk.py`, `tests/js/app_release_upload.test.js` — updated bounds/cache contracts and deadline/browser-selection tests.
- `tests/e2e/apk_upload_browser.py`, `apk_slow_link_probe.py`, `requirements.txt`, `README.md` — actual browser/PHP fixture, network profiles, assertions and reproducible setup.
- `.github/workflows/apk-upload-e2e.yml` — explicit/manual synthetic browser acceptance; no server credentials or deployment steps.
- This report, historical-report supersession note and small JSON evidence files under `docs/reports/apk-upload-evidence/`.

## 6. Logic Changed
**VERIFIED:**
- At most 512 MiB, including the exact boundary; boundary + 1 byte is rejected. Minimum 64-KiB parts require 8,192 part descriptors; normal 512-KiB parts require 1,024 for the exact maximum.
- Initial deadlines stay 45 seconds; retries gain a bounded window. Concurrency still falls after transient failures. No unconditional full-file restart or auth/WAF-bypassing base64 fallback.
- In-memory/browser resume metadata contains no APK bytes. Native browser File/Blob slices and incremental PHP assembly avoid a whole-APK PHP allocation.
- Missing deny-file creation is no longer conditional on directory creation. Session/chunk acceptance cannot proceed when protection creation fails.

Evidence: `AppReleaseManager.php:17` (`MAX_APK_BYTES`), `:66` (`getChunkDirectory`); `AppReleaseUploadStore.php:21` (`MAX_PARTS`); `app_release.js:164` (selection bound), `:382` (count bound), `:463` (retry deadline); `super-admin.php:1491` (cache tag). Paths are relative to the repository.

## 7. Database Changes
None. Browser fixture uses synthetic session/CSRF bootstrap and no database. It intentionally receives `audit_logged:false`; publication is still confirmed. Real audit database availability remains an independent deployment requirement.

## 8. API Changes
No new production endpoint or action. Existing chunk/status/finalize requests accept the higher bounded total size/count. Individual browser part requests remain at most 512 KiB, so a 512-MiB APK does **not** require setting PHP single-request uploads to 512 MiB. Tests used PHP **128M memory**, **2M upload_max_filesize**, and **8M post_max_size** for the large-file cases. Hosting/proxy limits can still be more restrictive and must be verified on the actual host.

## 9. Security Changes
Existing Super Admin/POST-CSRF/APK package/build/ABI/downgrade guards remain. No credentials, real users, deployments or production endpoints are used in browser tests. Protection-file generation is now regression-tested for pre-existing directories and read-only storage; effective Apache/LiteSpeed HTTP denial is still **DEPENDENCY NOT VERIFIED**, because PHP's loopback test server does not enforce `.htaccess`.

No blanket server configuration or permissions changes. Test browser/tools and OS browser libraries were installed only in the disposable sandbox. Large fixtures live under excluded `.cache` and are removed at normal completion. Production credentials were not searched, stored or changed.

## 10. UI/UX Changes
Display “Maximum 512 MiB (536,870,912 bytes)” and “Verified Parts (up to 512 KiB).” Existing interruption/retry/reselect/publication states remain. Loading the new dashboard requests cache version `20261027upload3`.

The browser tests render the actual dashboard section and use its actual file input/start button/error/success states. They do not replace the uploader with a test implementation. Only a test-native-fetch wrapper observes small metadata and injects named faults; normal and lost-response payloads all traverse native Chromium networking and the actual PHP endpoint.

## 11. Testing
**VERIFIED locally:** PHP 8.4.27, Node 20.20.2, Playwright 1.64.1, Chromium 156.0.8078.4.

### Large sluggish-link acceptance — PASS
- Exact file size: **335,545,212 bytes** (320 MiB plus ZIP/manifest overhead; above both 300 MB and 300 MiB).
- Chromium upload cap: **256 KiB/s** (2.097 Mbps); simulated latency: **250 ms**.
- Wall time: **1,309.59 seconds — 21m 49.59s**; real elapsed network shaping, not an instantaneous mocked clock.
- **641 parts**, **644 attempts**, **one** finalization request.
- Real page reload/reselection retained **40 verified parts**, **zero** of which were resent.
- Injected retryable 503, one persisted chunk whose response was discarded, terminal interruption/reload, and persisted publication whose final response was discarded.
- Original and published SHA-256 both `a9df5915f67614f930462193151a96b56bbdc4b5d56b3e3f8c5d0682146d0aad`.
- No browser JS errors; resume record cleared; published receipt saved; chunks removed only after publication; minimum build/Force Update/background-sync policy preserved.

### Exact maximum acceptance — PASS
The exact **536,870,912-byte (512-MiB)** APK was uploaded through Chromium and actual PHP, with the same interruption/reload/lost-response assertions. The final rerun after the storage-protection correction completed in **48.40 seconds** at a separate **16-MiB/s, 10-ms** profile. **1,024 parts, 1,027 attempts, one finalization**, zero resends of the 40 previously verified parts, zero JS errors, matching full SHA-256:

`b43110a3b0976f58b8a8474dbb63986321645b56e03159359fd8695576b6582c`

This is a boundary/capacity test, not a claim that 512 MiB was uploaded at the slow 256-KiB/s profile. The 320-MiB shaped run preceded only the final deny-file guard; upload bounds, transfer/retry code and publication logic were unchanged. The final 512-MiB rerun and all fast regressions include that guard.

### Severe link / real deadline probe
A **410,596-byte** APK at **8 KiB/s** (~65.5 kbps), 250-ms latency, exceeded the initial native 45-second deadline and succeeded on its 90-second retry. The first successful probe measured **98.21 seconds**, two attempts, **45.71-second retry spacing**, matching whole-file hash. The final-source rerun also passed in **98.41 seconds**, with **45.70-second** retry spacing and exactly two attempts; original/published SHA-256 both `0ea55fec6e33f1e866cd594dc36a3490b4edbe4eb461fea1e3c680a4521c34d2`. Both runs are kept distinct; final-source JSON evidence is committed. This does **not** extrapolate a multi-hundred-MiB transfer at that speed or unlimited outage recovery.

### Fast regressions
```text
python3 -m pytest tests/security/test_apk_upload_reliability.py \
  tests/security/test_app_release_management.py \
  tests/security/test_app_release_from_apk.py tests/security/test_apk_manifest.py -q
61 passed

node --test tests/js/app_release_upload.test.js
19 passed; zero failures/skips
```
The Python total includes the Node wrapper; do not double-count it as independent browser acceptance. PHP lint, JS syntax, Python compilation and diff-whitespace checks pass. The browser workflow is manual and has **not** run remotely; full platform CI is not proven by these scoped tests.

### Failed runs are not acceptance
Initial concurrent large-browser runs using binary multipart interception hit automation DOM timeouts: ~963.66 seconds for the 320-MiB attempt, ~236.24 seconds for the 512-MiB attempt. They **did not complete** and are retained as failures in evidence. An initial severe-link observer also failed because it could not retrieve `chunk_index` from the automation request representation after the uploader succeeded; corrected metadata-only observation was retested.

The final harness avoids copying binary multipart bodies through the automation driver and runs large cases sequentially. **HIGH CONFIDENCE:** the old instrumentation/concurrent resource pressure contributed; the exact initial automation-timeout mechanism is **UNKNOWN**. Do not recast those failures as proof of an APK service fault, the original hosted incident cause, or successful tests.

## 12. Regression Check
Existing release/policy/manifest suites still pass. No mobile code, attendance, finance, database, real release files or unrelated application modules changed. Prior local commit `6fb2619` remains in branch history. The original dirty checkout is preserved. Full HTTP auth/CSRF and legacy server base64 contracts from that hardening remain covered. The final end-to-end flows verify file integrity, receipt reconciliation and unchanged policy, not merely a displayed 100%.

## 13. Remaining Risks
- **UNKNOWN:** Hosted browser/WAF/proxy/network cause of the original `Failed to fetch`.
- **DEPENDENCY NOT VERIFIED:** Actual server version/parity, hosting account quota/free storage, proxy/input/processing timeouts, private/temporary HTTP protections and filesystem lock/rename semantics. Large assembly may need roughly twice an APK's storage plus an existing artifact/rollback; plan quota conservatively. System `disk_free_space` is not cPanel account quota.
- Owner-built signed APK and physical-phone acceptance are not performed; fixture APKs are structurally valid but synthetic/unsigned.
- No promise of Unigram desktop feature parity, autonomous background upload, recovery at every conceivable speed/outage, or crash-atomic multi-file publication. Retained sessions expire with inactivity; requests/retries are deliberately bounded.
- Synthetic localhost auth bootstrap tests the real endpoint checks but not the real host's login/session configuration or activity-log database.
- Remote push/CI/main merge/deployment remain independent gates; local results do not imply a deployed fix. Production remains protected and unmodified.

Development release sequence after access restoration: push feature branch → required CI/review → merge/fast-forward authorized development main without force → verify cron checkout and PHP+JS parity → hard refresh → owner authenticated hosting preflight and real signed-APK upload/interruption/reload/download SHA verification → phone acceptance. Production promotion still needs a separate explicit owner confirmation.

Rollback: preserve needed APK/config/session evidence, revert the scoped development commits and deploy matching PHP+JS together; no database rollback. An older 250-MiB client will reject larger files; mixed-version deployments must not be presented as supported. Do not indiscriminately delete active parts or real release artifacts.

## 14. Assumptions
Development main publicly fetched at `105007b9afb6c5bbfe803b369f59a21830a6b5aa`. This is not proof of hosted PHP parity. User authorized development-main integration after testing; no production authorization. No usable HTTPS write credential is currently configured, and the GitHub connector reports unsupported in this environment. No earlier token was recovered from secret storage. Authentication must be restored before remote repository changes can be made.

## 15. Final Status
**BLOCKED — REQUIRED INFORMATION MISSING** for the complete request including GitHub-main merge: repository write access is missing. The scoped 512-MiB implementation and local real-browser tests are verified subject to the explicit limits above. Commit/push outcomes and final severe-link measurement are appended to the delivered report after those operations; they are not fabricated here.
