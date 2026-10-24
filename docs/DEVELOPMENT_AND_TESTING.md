# Development, testing and CI

**Status:** canonical. **Verified against:** `main` at `46fbaa7` and GitHub Actions run `39744630068` (2026-10-22).

## 1. Prerequisites

| Tool | Version | Why |
|---|---|---|
| PHP CLI | **8.2** to match production; **8.4** to match CI | Extensions used by CI and `provision_env.sh`: mysqli, pdo_mysql, mbstring, curl, xml, gd, zip, bcmath |
| MariaDB | 11.x (CI uses 11.4) | Test databases |
| Python | 3.11 (CI) | `pytest`, `pytest-subtests`. Some suites also need `requests`, `openpyxl` and Python `playwright` |
| Flutter | 3.44.9, stable channel (CI) | Mobile app; `flutter pub get --enforce-lockfile` |
| JDK | 17 (CI) | Android builds |

Composer is **not** needed: dependencies are committed in `vendor/`, and the server cannot run Composer. There is no `package.json`; the JavaScript tests in `tests/js/` are driven from Python.

## 2. Local setup (test environment only)

1. **Secrets file.**
   - `config.php` looks for `.fkss_env.php` (or the legacy `.wbws_env.php`) in, in order: the parent of the repository root, two levels up, and the repository root. If none exists, it **refuses to start**.
   - Create a test-only file from `env.example.php`. The CI job *Provision a test-only .fkss_env.php* shows the minimum keys.
   - Never commit this file. CI rejects tracked `*_env.php` files.
2. **Database.** `bash tests/e2e/provision_env.sh` installs PHP and MariaDB, creates the test database, applies `sql/legacy/database_schema.sql`, then `sql/` in a working order plus the legacy tables, and seeds a test world.
   - It requires Debian or Ubuntu with passwordless `sudo`.
   - It is **destructive**: it resets data. **Never point it at production.**
   - On a freshly provisioned machine it has sometimes stopped partway (observed during earlier test-environment rebuilds; not systematically reproduced). If that happens, rerun it, or apply the remaining steps by hand from the script.
   - Test logins are created by the seed scripts. Their passwords are not documented here.
3. **Serve.** From the repository root, run `php -S 0.0.0.0:8080 -t .`. The script header also shows a variant with workers.
   - E2E tests that call `admin/api_test_seed.php` need `ENABLE_TEST_DATA_TOOLS = true` in the **test** secrets file.
   - The idempotency harness additionally requires `SSMS_AUDIT_TESTING=1`.
4. **Mobile.** In `Mobile/wbws_flutter_app`, run `flutter pub get --enforce-lockfile`, then `flutter test`.
   - The API base URL on `main` is fixed to production in `lib/utils/config.dart`, and the *Branch guard* workflow enforces this.
   - Do not change it on `main`. Beta builds come from the `dev` branch (see [BRANCHING.md](../BRANCHING.md)).

## 3. Current CI status

`main` = `46fbaa7`, workflow *Backend checks*, run `39744630068`. The production repository ran the same commit as run `39744635305`, with the same outcome.

| Job | Result | Notes |
|---|---|---|
| PHP syntax (`php -l`) | pass | 363 first-party files, PHP 8.4 |
| Flutter unit tests | pass | 657 tests |
| Migration numbering | pass | Duplicate-number and destructive-statement checks |
| Android debug APK | pass | Builds a **debug** APK only; no release signing in CI |
| Security and regression suite | **fail** | `pytest tests/security`: **2 failed, 2,793 passed, 79 skipped** (plus 985 passing subtests). The "Fail if any test skipped" step also fails |

*Backend checks* has failed on every `main` push since `b9cbb0f` (2026-10-19). *Production integrity* and *Branch guard* pass.

**Update 2026-10-24:** the 2 failing tests were fixed (test-only change; the code was right). The job still fails because 79 tests are skipped (§3.2). The Flutter test `teacher_timetable_screen_test.dart` also failed every day from 16:00 to 16:40 UTC and from 23:59 to 24:00 because of its test data; fixed the same day.

### 3.1 The two failures

Both were fixed on 2026-10-24 (test-only changes; see the last column).

| Test | Cause (observed) | Required work |
|---|---|---|
| `tests/security/test_academic_tracking.py:200` (`test_page_size_is_bounded`) | `admin/api_education.php` now caps `per_page` at **200** (paging change `71caa8c`, 2026-10-22). The test still expects **100** | **Fixed 2026-10-24:** 200 is the intended cap (the shared pager offers 25/50/100/200). The test now expects 200 and still fails if the cap is removed |
| `tests/security/test_education_config_endpoints.py:485` (`test_the_report_card_does_not_weight_semesters_itself`) | The forbidden substring `"/ 2"` matches the comment text `// 2026-10-22` in `admin/js/report_card.js` (added in `524ab7f`). No arithmetic was introduced | **Fixed 2026-10-24:** a "/" straight after another "/" (a JavaScript comment) is no longer counted; a real `/ 2` still fails the test |

### 3.2 The 79 skips

Every skip has a stated reason. They fall into two groups:
- **71 skips:** the test needs a running local server with a seeded database, and CI does not start one.
  - Affected: `test_school_calendar` 10, `test_attendance_phase0` 9, `test_production_audit_pass2` 8, `test_attendance_phase4` 8, `test_timetable_mobile` 6, `test_attendance_phase1`/`2`/`5` 6 each, `test_attendance_phase6` 5, `test_timetable_builder` 3, `test_attendance_phase3` 3, `test_score_limit` 1.
- **8 skips:** `test_member_import_v2` needs `openpyxl`, which the CI job does not install.

## 4. Test suites

| Suite | Location | How it runs | In CI? |
|---|---|---|---|
| Security and regression | `tests/security/` (153 files) | `python3 -m pytest tests/security -q -rs` | Yes |
| End-to-end and harnesses | `tests/e2e/` (44 files: Python Playwright journeys, PHP harnesses, shell helpers) | Against a local server; see `tests/e2e/run_smoke.sh` and each file's header | Only `idempotency_lifecycle.php` |
| Smoke | `tests/smoke/` (PHP) | `php tests/smoke/<file>.php` against a test database | No |
| Production-audit regression | `tests/audit/` | See `tests/audit/README.md` | No. The CI command collects only `tests/security` |
| Mutation | `tests/mutation/` | Excluded from routine runs | No |
| JavaScript | `tests/js/` | Driven from Python tests | Via `tests/security` where imported |
| Flutter | `Mobile/wbws_flutter_app/test/` (48 files) | `flutter test` | Yes |

`flutter analyze` is not a CI step; `tests/e2e/analyze_flutter.sh` exists for local use.

## 5. Runtime parity gap

CI runs PHP **8.4**; production runs PHP **8.2**. Syntax or behaviour that is valid only on 8.3 or later would pass CI and fail in production. A PHP 8.2 lint/test job is recommended.

## 6. Release acceptance policy

**Intended policy (not yet met):** a commit is releasable only when all of these hold:
1. *Backend checks* is green. That means zero failures **and** zero unexplained skips. Every remaining skip must be justified in the test and visible in the CI log.
2. *Production integrity* and *Branch guard* are green.
3. Any migration in the change has been reviewed, applied by the owner and recorded.
4. The deployment checklist in [OPERATIONS.md](OPERATIONS.md) is complete.

**Current practice:** releases have been deployed while *Backend checks* was red, with targeted local test runs used as evidence. Until the skips are resolved (the two failures were fixed on 2026-10-24; for the skips, for example by starting a seeded server in CI and installing `openpyxl`), a green gate cannot be used as release evidence. This document records that state; it does not change CI.

## 7. Workflows (`.github/workflows/`)

| Workflow | Trigger | Purpose |
|---|---|---|
| `backend-checks.yml` | Push to `main` and `audit/**`; every pull request; manual | Lint, security suite, idempotency lifecycle, Flutter tests, migration checks, debug APK build |
| `production-integrity.yml` | Push to `main`; every pull request; manual | Rejects tracked credential and artifact files, secret-shaped strings and oversized files |
| `branch-guard.yml` | Push to `main`; pull requests into `main`; manual | Refuses PRs from `dev`; enforces production values in the mobile app on `main`; ensures the beta guide does not exist on `main` |
