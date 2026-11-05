# APK upload CI follow-up — 2026-10-28

> **Historical intermediate report.** The reported failures and sparse-checkout uncertainty are preserved, not current status. The [later zero-skip repair](CI_ZERO_SKIP_REPAIR_2026-10-28.md) corrects the provisioning-file claim; PR #41 subsequently merged as `bf924d7`. See [successful later baseline checks](DOCUMENTATION_BASELINE_2026-10-28.md).

## 1. Request
Push and merge the authorized development update without repeated routine approval. Production still requires separate explicit authorization.
## 2. Current Problem
PR #41 was pushed successfully; security CI reported two new assertions and the existing 79-skipped-tests gate.
## 3. Root Cause
**VERIFIED:** structured upload errors used direct exception-message serialization, triggering the diagnostic-disclosure guard. Centralized release loading removed two dynamic includes, leaving the reviewed inventory stale. CI also lacks the live server/seed world referenced by 79 tests (including an untracked/missing `tests/e2e/provision_env.sh` reference), openpyxl and ssms_test access. Those environment gaps predate this upload patch; they are not passing tests.
## 4. Solution
Separate explicit user-safe domain messages from generic infrastructure exceptions; log infrastructure diagnostics privately and return a generic retry-safe HTTP message. Review/update the dynamic-include inventory from four to two without weakening its equality guard. Do not disable the zero-skip gate.
## 5. Files Changed
`admin/api_app_release.php`; `admin/backend/services/AppReleaseUploadStore.php`; `tests/security/test_apk_upload_reliability.py`; `tests/security/test_structure_include_paths.py`; this report.
## 6. Logic Changed
Typed domain exceptions expose an explicit readonly `clientMessage`; generic failures no longer echo raw server diagnostics. The real multipart test now induces a read-only settings failure, verifies a generic HTTP 500 with retained chunks, restores permissions in its disposable fixture, and successfully publishes afterward. Include counts reflect reviewed source; no include is added.
## 7. Database Changes
None. No server/database provisioning or migration performed.
## 8. API Changes
Domain error codes/status/retryability remain. Generic infrastructure error text is intentionally sanitized; detailed diagnostics remain in server logs.
## 9. Security Changes
No diagnostic-disclosure test exemption or skip introduced; no branch protections or CI policy weakened. Credentials used transiently for authorized repository operations, not persisted in a remote or report.
## 10. UI/UX Changes
Generic infrastructure failures direct the administrator to server logs while confirming retained parts. No layout or transfer behavior change.
## 11. Testing
**VERIFIED locally:** 69 targeted tests pass, including the error-disclosure and full structure/include guards; new real multipart HTTP 500 retention/privacy assertions pass. PHP lint/diff checks pass. Final-source exact-512-MiB Chromium/PHP rerun also passes in 47.96 seconds: 1,024 parts, 1,027 attempts, one finalization, zero resends of the 40 saved parts, matching whole-file SHA-256 `f69440a77228d9bf1dcf87dfb82c6e265fd183c3c31c831a5faf65380697cf45`. JSON and source hashes are committed. Initial GitHub result: 2 failures, 2898 passed, 79 skipped, 949 subtests passed. Follow-up CI result is reported separately, not assumed.
## 12. Regression Check
Bounds, hashing, successful publication, policy preservation and native retry mechanics are unchanged. No unrelated business modules modified. Unchanged PHP source was materialized into the sparse worktree to execute the full include-path checks; those files are not content changes.
## 13. Remaining Risks
The existing 79 skipped integration tests still block the zero-skip job unless their independent CI environment is repaired. Do not merge by pretending the job passed or silently removing the gate. Missing bootstrap/seed provenance needs source-first analysis before extending this patch into attendance/calendar/import CI infrastructure. Hosted acceptance remains unverified.
## 14. Assumptions
Development repository only; owner has authorized routine commit/push/main merge, but this is not authorization to conceal a failed gate or silently redesign unrelated test environments.
## 15. Final Status
**INCOMPLETE — ADDITIONAL WORK REQUIRED** for main integration until CI is green. PR: https://github.com/suraman21/fkss-sunday-school-platform/pull/41. No production operations. Actual follow-up commit and CI outcomes are recorded in the delivered status/PR comments.
