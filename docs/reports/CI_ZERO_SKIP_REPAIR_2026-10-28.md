# CI zero-skip repair — 2026-10-28

> **Historical intermediate report.** Its pre-push status below is preserved. PR #41 subsequently merged as `bf924d7`; all eight checks on later baseline `7209278` passed. See [dated follow-up evidence](DOCUMENTATION_BASELINE_2026-10-28.md). This is not a current red-CI notice.

## 1. Request
Repair the remaining checks, commit, push, and merge development PR #41 into main without another routine approval. Production is excluded.

## 2. Current Problem
The previous remote head `85aff523d1bba47ca70df2c0b6138a249e5ae119` had 2,900 passed / 79 skipped / zero test failures; the mandatory zero-skip gate correctly blocked merging. This repair now passes the full backend suite locally with zero skips. Authentication was restored and the initial repair pushed. Its real GitHub run passed the zero-skip gate but exposed one report-card subprocess still selecting the default comm DB; that was reproduced and fixed in this follow-up. Fresh remote verification remains mandatory before merge.

## 3. Root Cause
**VERIFIED:** the workflow supplied databases for independent harnesses but did not provision/start the shared `ssms_test` HTTP world. It lacked openpyxl, and legacy `sudo mariadb` helpers expected a local socket whereas CI uses a TCP container. Real execution also exposed cross-test login rate-bucket accumulation, a rollout subprocess inheriting the wrong isolated database, and missing audited-role fixtures.

**Correction:** `tests/e2e/provision_env.sh` IS tracked. Earlier claims that it was missing were incorrect, caused by the sparse local checkout. The existing canonical script was reused, not replaced with an invented schema.

## 4. Solution
Install the test dependencies; bridge the root CLI client to the existing disposable MariaDB 11.4 service; provision the canonical schema/seed in `ssms_test`; add the missing test roles; start the guarded local PHP router; isolate login buckets between live tests; and explicitly select the rollout fixture DB. Keep the existing fail-on-any-skip gate intact.

## 5. Files Changed
- `.github/workflows/backend-checks.yml`: test-only dependency/client/database/server setup and explicit test-mode/timezone configuration.
- `tests/e2e/live_guard_seed.php`: additional roles, loopback/`ssms_test` mutation guard, required-table assertions.
- `tests/e2e/live_guard_router.php`: explicit test-only PHP-server API rewrite adapter.
- `tests/security/conftest.py`: opt-in cleanup of prior live tests' browser/mobile login buckets.
- `tests/security/test_attendance_phase2.py` and `test_attendance_phase5.py`: explicit `SSMS_DB_NAME=ssms_test` for rollout/report-card subprocesses.
- `tests/security/test_live_guard_environment.py`: eight regression cases for fixture safety/isolation.
- This report and `docs/reports/apk-upload-evidence/zero-skip-ci-repair-local.json`.

## 6. Logic Changed
Only test infrastructure. Cleanup deletes named login-rate buckets between live tests; it does not raise limits, disable authentication, remove assertions, or clear state during a test. The attendance flag is enabled only for the disposable `ssms_test` world; deployment defaults remain unchanged.

## 7. Database Changes
CI creates/seeds the isolated `ssms_test` database using existing migrations; no migration files or production/development application data were changed. The disposable CI service timezone is aligned with the app/test timezone. Local databases were synthetic and isolated.

## 8. API Changes
No application API contract changes. The local test router emulates the existing `/api/v1/` rewrite for the PHP development server.

## 9. Security Changes
Zero-skip enforcement and branch/check protection are not bypassed. Seeder refuses non-CLI/non-audit execution and mutation outside loopback `ssms_test`; router refuses non-test execution. CI-only `/root/.my.cnf` contains the already-public disposable container credentials, not a real repository/server secret. No PAT was retained in repository files, remotes, reports, or credential stores.

## 10. UI/UX Changes
None.

## 11. Testing
**VERIFIED locally:** full suite **2,987 passed, 949 subtests passed, zero failures, zero skips**, 159.80 seconds; F-20 idempotency lifecycle **67/67 checks pass**. Focused setup/include tests **13 passed** (overlap with the full suite; do not add the counts). PHP lint, workflow YAML parsing, every security-job shell-step syntax check, and `git diff --check` pass.

Local environment: PHP 8.4.27, MariaDB 11.8.6, Python 3.13. The final full run used the actual tracked-file inventory and a throwaway env matching CI's comm-default database; the web server independently selected ssms_test. This also fixes a local-fixture mismatch that hid the report-card failure on the first local run. Earlier exploratory runs without that metadata/env were invalid environment attempts and are not represented as successful runs.

**DEPENDENCY NOT VERIFIED:** the patched GitHub job uses MariaDB 11.4 / Python 3.11; the initial repair ran with zero skips but one failure (2,984 passed / 949 subtests). That specific failure was reproduced locally before changing the subprocess DB. The corrected follow-up still needs a fresh remote run.

## 12. Regression Check
All four APK runtime files match the committed SHA-256 evidence from the passing final-source 512-MiB browser test. No APK runtime logic changed in this CI-only repair. Full suite covers the previously skipped 79 tests plus eight added setup/isolation tests.

## 13. Remaining Risks
Remote CI/check/merge verification is outstanding. Hosted authenticated/device/hosting-limit acceptance remains separate and unverified. Production remains untouched.

## 14. Assumptions
User authorization to commit/push/check/merge development main remains valid; no new approval is requested. The initial transient credential was unavailable after session resumption; the user restored access and `96aec67` was pushed. Credentials remain transient and are not persisted. The next follow-up will be pushed and merged only after all actual GitHub checks pass.

## 15. Final Status
**INCOMPLETE — ADDITIONAL WORK REQUIRED** at this pre-push report checkpoint: the local follow-up passes all 2,987 tests with zero skips, but fresh remote checks and merge verification remain pending. Initial repair run: https://github.com/suraman21/fkss-sunday-school-platform/actions/runs/40591951500 (zero skips, one subsequently reproduced/fixed database-selection failure). PR https://github.com/suraman21/fkss-sunday-school-platform/pull/41 remains open until the exact passing head is merged. Final remote/merge evidence is recorded in the PR and delivered status report; no production operations.
