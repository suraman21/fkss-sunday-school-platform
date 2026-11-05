# Development, testing and CI

**Status:** maintained reference. **Source baseline:** development main `7209278` (2026-10-28). Workflow definitions, not old test totals, determine the current gates. Runtime hosting and installed-device versions have not been reverified.

## 1. Prerequisites

| Tool | Checked-in configuration | Purpose |
|---|---|---|
| PHP CLI | 8.4 for backend tests and syntax; a separate 8.5 syntax/deprecation job | Server code and PHP fixture harnesses |
| MariaDB | 11.4 service in CI | Disposable integration databases |
| Python | 3.11 in CI | `pytest`, `pytest-subtests`, `openpyxl`; additional browser suites have their own requirements |
| Node.js | 22 in the backend security job | JavaScript regression harnesses |
| Flutter | 3.44.9, stable channel | Mobile unit tests and debug APK build |
| JDK | 17 in CI | Android build |

See [backend-checks.yml](../.github/workflows/backend-checks.yml) for exact extensions, packages, Android SDK components and build steps. Production PHP 8.5 was observed on 2026-10-23; that is historical evidence, not a live version check. Dependencies are committed in `vendor/`; routine setup does not require a server-side Composer step. Do not upgrade dependencies while investigating a documentation or wording change.

## 2. Local setup (test environment only)

**Use an isolated machine or container and synthetic records.** Provisioning and several harnesses delete and recreate tables. Never reuse a production database, connection file, server or data export.

1. **Review the fixture scripts.** Read [provision_env.sh](../tests/e2e/provision_env.sh), [live_guard_seed.php](../tests/e2e/live_guard_seed.php) and the workflow before execution. The provisioning script expects Debian/Ubuntu and passwordless `sudo`. If setup fails, inspect the failing step and resulting schema; do not repeatedly rerun destructive commands without understanding their effects.
2. **Create test-only configuration.** `config.php` searches for `.fkss_env.php` or the legacy `.wbws_env.php` above and inside the repository. Ensure no higher-priority configuration points at a real school database. Use [env.example.php](../env.example.php) and the workflow's throwaway configuration as references. Never commit environment files or use real credentials in examples.
3. **Provision the disposable databases.** Follow the workflow's *Create test databases* and *Provision and start the isolated live-guard world* steps. `ssms_test` is the shared seeded HTTP world; independent harnesses use separate databases so their resets cannot corrupt each other. The workflow provisions the canonical schema, adds audited-role fixtures and enables attendance v2 only in that isolated world.
4. **Start the test-only router.** The CI server uses `tests/e2e/live_guard_router.php` with `SSMS_AUDIT_TESTING=1`, `SSMS_DB_NAME=ssms_test` and `TZ=Africa/Addis_Ababa`. It binds to loopback in CI. Use a sandbox-only network binding when a local browser preview is needed; never expose test seeding publicly. A plain PHP server without the router does not reproduce the `/api/v1` rewrite.
5. **Run the suites.** The backend command additionally sets `SSMS_LIVE_GUARDS=1` and uses the independent comm database as its default. Match the workflow's environment rather than changing assertions to accommodate the wrong database.
6. **Set up mobile tests.** In `Mobile/wbws_flutter_app`, run `flutter pub get --enforce-lockfile`, then `flutter test`. Verify that `pubspec.yaml` and `pubspec.lock` remain unchanged.

The API address in a `main` mobile build targets production and is enforced by the branch guard. Unit tests do not authorize making real API requests. Use the existing test injection/fixtures for isolated tests; beta configuration belongs to `dev`. See [BRANCHING.md](../BRANCHING.md).

## 3. Current CI status

All **eight** check runs on baseline `7209278b44dd35f0e2cf9ec94d198ee968bb10dc` completed successfully, rechecked through the public GitHub checks API on 2026-10-28. See [baseline evidence](reports/DOCUMENTATION_BASELINE_2026-10-28.md) for the exact jobs and links. This result belongs to that SHA; a new branch or merge requires its own successful run.

| Job | Baseline result | What it establishes |
|---|---|---|
| PHP syntax (`php -l`) | Success | First-party PHP parses on 8.4 |
| PHP 8.5 syntax and deprecations | Success | First-party PHP parses without compile-time deprecations on 8.5 |
| Security and regression suite | Success | The configured synthetic backend suites and zero-skip enforcement passed |
| Flutter unit tests | Success | The configured mobile tests passed, not phone installation acceptance |
| Migration numbering | Success | Numbering and destructive-statement guards passed |
| Android debug APK | Success | Debug build and configured manifest/dex checks passed; not release signing |
| Repository integrity (secrets, artifacts, history) | Success | Configured repository-integrity checks passed; not a security certification |
| main must build the production app | Success | Branch and mobile configuration constraints passed; not a deployment |

### 3.1 Earlier failures (fixed)

On 2026-10-24, tests were corrected to expect the implemented roster cap of 200 and to stop interpreting a JavaScript date comment as division by two. These were test corrections, not changes to the roster or report-card business rules. Keep historical test outcomes tied to their tested commits.

### 3.2 The 79 skips

**Historical, resolved by PR #41 (`bf924d7`, 2026-10-28).** Earlier runs lacked the seeded HTTP world and `openpyxl`; 71 server-dependent tests and eight import tests skipped. The workflow now installs `openpyxl`, provisions the canonical isolated schema/roles, starts the guarded PHP router, bridges legacy database clients to the disposable CI service and isolates live-test login buckets. Database-specific subprocesses select the correct fixture database.

The *Fail if any test skipped* step remains active: **any skip fails this job**, even if explained. Do not treat a skipped test as a pass. [The repair report](reports/CI_ZERO_SKIP_REPAIR_2026-10-28.md) preserves the local result and the intermediate failed-run context; [baseline evidence](reports/DOCUMENTATION_BASELINE_2026-10-28.md) records the later successful checks. Historical reports are not current gate status.

## 4. Test suites

| Suite | Location | Execution and boundary |
|---|---|---|
| Security and regression | `tests/security/` | `python3 -m pytest tests/security -q -rs`; use the provisioned databases/server and workflow environment for a meaningful full run |
| Integration and browser journeys | `tests/e2e/` | Follow each harness's header. The backend job executes live guards and the separate idempotency lifecycle harness; it does not run every browser journey |
| APK browser acceptance | `tests/e2e/apk_upload_browser.py`, `apk_slow_link_probe.py` | Separate manually dispatched synthetic workflow; exercises slow transfers, reload recovery and the exact size boundary |
| Smoke | `tests/smoke/` | Read each PHP script before running; disposable fixtures only for routine development |
| Audit regression | `tests/audit/` | Follow [its guide](../tests/audit/README.md); not implicitly included in `tests/security` |
| Mutation | `tests/mutation/` | Deliberate fault-injection work, not a routine release command |
| JavaScript | `tests/js/` | Node harnesses invoked by relevant Python tests |
| Flutter | `Mobile/wbws_flutter_app/test/` | `flutter test`; includes service, storage, policy and UI tests |
| Documentation and structure | `tests/security/test_docs_links.py`, `test_structure_include_paths.py`, `test_structure_boundaries.py` | Relative links/anchors, include paths and served/nonserved boundaries |

`flutter analyze` is not an enforced backend workflow step. [analyze_flutter.sh](../tests/e2e/analyze_flutter.sh) is available for local analysis. A passing targeted run is not a substitute for an unexecuted full suite; report them separately.

## 5. Runtime parity gap

Backend behavioral tests use PHP 8.4; PHP 8.5 has a separate lint/deprecation job. Lint does not prove behavioral parity. Production's current PHP, MariaDB, feature flags, schema, deployed files and installed APKs require separately authorized evidence. Checkout equality and successful CI do not establish any of those facts.

## 6. Release acceptance policy

1. Every applicable check must pass for the **exact candidate SHA**, including zero skips in the backend suite. Do not bypass protections or accept an acknowledged failure as green.
2. Review the complete diff, permissions, data boundaries and compatibility with installed clients. Include tests appropriate to the change and record what was not exercised.
3. Merge into the **development repository's main** only after the checks and authorized review requirements are satisfied.
4. The owner's development-server cron update and subsequent owner acceptance are separate runtime gates. Record the observed revision and results; a merge does not prove the cron executed.
5. Promote to the production repository or hosting only after **separate explicit owner authorization**. Review migration order, backups, rollback and release signing before any release action. Follow [OPERATIONS.md](OPERATIONS.md) and [PRODUCTION_WORKFLOW.md](PRODUCTION_WORKFLOW.md).

## 7. Workflows (`.github/workflows/`)

| Workflow | Configured trigger | Purpose |
|---|---|---|
| `backend-checks.yml` | Push to `main` and `audit/**`; pull request; manual dispatch | PHP lint, backend zero-skip suite, idempotency lifecycle, Flutter tests, migration checks and debug APK build |
| `production-integrity.yml` | Push to `main`; pull request; manual dispatch | Repository secret/artifact/history guards |
| `branch-guard.yml` | Push to `main`; pull request into `main`; manual dispatch | Refuse `dev` merges and enforce the main mobile configuration |
| `apk-upload-e2e.yml` | Manual dispatch | Synthetic Chromium/PHP APK-transfer acceptance and evidence artifacts |

Workflow presence and branch-protection settings are different facts. Verify current repository settings before a release; the dated settings observations in older documents are not a fresh permissions audit.

## Browser URL routing acceptance

The page registry and dispatcher are described in [ARCHITECTURE.md](ARCHITECTURE.md#browser-page-addresses-development-change-2026-10-30). Inspect [the registry](../admin/backend/services/BrowserPageRoutes.php) for the complete supported aliases; do not add generic extension stripping or map APIs/download handlers as pages.

Run `php tests/fixtures/browser_page_routes.fixture "$PWD"` for the standalone policy/identity matrix. Run `python -m pytest tests/security/test_browser_page_routes.py -q -rs` in the fully provisioned disposable environment described above. Its HTTP tests require `SSMS_AUDIT_TESTING=1` and `SSMS_TEST_BASE` (default `http://127.0.0.1:8080`); the normal CI router supports the finite aliases, but **is not Apache rewrite verification**. The full security suite and zero-skip gate remain required.

Before accepting a hosted development rollout, verify these separately on the actual web server:

- Apache-compatible rewrite support (`[END]` included), root **and** admin `.htaccess` activation, `AllowOverride` for the existing directives and `Options -MultiViews`; preserve the existing cPanel PHP handler. A host with rewrite support disabled must continue serving old PHP URLs without redirecting them to unavailable aliases.
- Root and any real deployment subdirectory: all registry aliases, legacy GET/HEAD document redirects, exact query preservation, restored legacy handler identity, no redirect loops, and relative assets/forms/fetch/redirect targets. Unknown, encoded, traversal, internal and protected paths must remain denied or 404; API paths must retain their existing routing.
- Legacy and clean write methods, raw bodies and multipart uploads; JSON/XHR/non-navigation requests must not acquire document redirects. Check actual allowed/denied roles, feature-off paths, CSRF rejection and retained manual member types with synthetic records only. Do not replace an established handler's error status/envelope merely to match a new route test.
- Temporary 302 rather than permanent bookmark changes. PHP requests `Cache-Control: no-store`; the existing Apache policy may override it with `no-cache, must-revalidate`, requiring revalidation before reuse. Do not claim a no-store response unless the hosted headers show it.
- Browser acceptance for login/logout, department navigation, assets, edits and browser back/refresh; installed mobile clients still use their existing endpoints. Old explicit POST targets may retain a PHP URL for that response; do not hide it by replaying a write. Old document links add one redirect rather than being bulk rewritten.

Rollback is a normal reviewed revert of the routing change: original page files, database schema and APIs remain in place. Do not copy only the dispatcher or only one `.htaccess`; deploy/revert the accepted change atomically. Development acceptance does not authorize production promotion or prove server deployment.
