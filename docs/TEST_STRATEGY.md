# Test strategy

**Status:** proposed baseline, written 2026-10-23 against `main` at `46fbaa7`. It describes how quality is assured today, which risks each kind of test covers, and the agreed path to a green pipeline. Suite inventory, local commands and the current CI result are in [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md); this document does not repeat them.

## 1. Goals

1. **Protect what can hurt people or the school:** personal data of members (many are minors), authentication, role scoping, and the integrity of attendance and grade records.
2. **Keep the field app working offline:** installed Android builds must keep syncing after every server change.
3. **Make releases evidence-based:** a change is releasable when the pipeline, not a person's memory, says so.

## 2. Principles

- **Test data only.** Tests run against provisioned test databases. No test reads, writes or copies production data, and fixtures contain synthetic values only.
- **Risk first.** Effort follows impact: security and data integrity, then offline sync, then workflows, then presentation.
- **Every skip is explained.** A skipped test must state why in the test and in the CI log; unexplained skips fail the pipeline (already enforced by the *Fail if any test skipped* step).
- **Regression tests with fixes.** Every security or data-integrity fix lands with a test that fails without the fix.
- **One rule, one place.** When the admin endpoints and `/api/v1` enforce the same rule, the rule lives in a shared service under `admin/backend/services/`, and the test targets that service.

## 3. Test levels

| Level | What it proves | Where | In CI today |
|---|---|---|---|
| Static checks | Every PHP file parses; migrations are numbered and not silently destructive; no secrets or build artifacts are tracked | `php -l` job, *Migration numbering* job, *Production integrity* workflow | Yes |
| Unit and contract (PHP) | Services and endpoint contracts behave as specified, including role gates and escaping | `tests/security/` (pytest driving PHP and source assertions), `tests/fixtures/` | Yes |
| Unit (Flutter) | App logic: outbox policy, sync execution and recovery, version and update checks, Mezmur playback and download policy | `Mobile/wbws_flutter_app/test/` | Yes |
| Integration (real database) | Idempotent replay and transactional writes against MariaDB | `tests/e2e/idempotency_lifecycle.php` | Yes (this one harness) |
| End-to-end journeys | A user can complete a workflow in the browser or against the API | `tests/e2e/` (Python Playwright journeys, PHP harnesses) | No; run locally against a provisioned server |
| Smoke | A deployed or local instance answers its key pages and endpoints | `tests/smoke/` | No |
| Build | A debug Android APK builds from `main` | *Android debug APK* job | Yes |
| Manual and visual | Layout, language and usability on real devices | Manual check on a phone, a tablet and a desktop before release | Manual |
| Security assessment | External view of the deployed site | Dynamic scans. OWASP ZAP Phase 1 findings were remediated (for example `18c5801`: CORS wildcard removed, CSP and HSTS on the API) | Periodic, manual |

## 4. Coverage by risk area

Examples are real files under `tests/security/` unless stated otherwise.

| Risk area | Main protection | Example tests | Known gap |
|---|---|---|---|
| Authentication and tokens | Token format and lifetime, refresh rotation, login rate limit, CSRF on admin writes | `test_api_rate_limiting.py` and the auth/token tests | Session and token edge cases are covered by unit tests only, not by an end-to-end journey in CI |
| Authorization and personal data | Role gates, teacher scoping, PII field shaping | `test_feature_gate_enforcement.py`, role and scope tests | Per-action rules of `admin/api_*` are not tabulated |
| Destructive actions | Typed confirmation, server-side guards | `test_danger_confirm.py`, `test_destructive_guard.py` | None known |
| Attendance and alerting | Class-day rule, submission workflow, warnings | attendance phase tests, `test_school_calendar.py` | 71 server-dependent tests skip in CI (see §6) |
| Grades and assessments | Score limit, "Did not take", report-card rules | `test_score_limit.py`, `test_education_config_endpoints.py` | One assertion currently fails on a comment (see §6) |
| Members, import, Family Link | Duplicate policy, import rules, guardian linkage | `test_member_import_v2.py`, fixtures `member_duplicate_policy`, `member_registration_policy` | Import tests skip in CI without `openpyxl` |
| Mobile contract and sync | Build compatibility, idempotency, outbox classification | mobile and sync tests, Flutter unit tests, the idempotency harness | No automated test drives an old installed build against a new server |
| Output escaping and file handling | HTML escaping, CSV formula guard, upload and deployment-tool hardening | `test_onclick_arg_escaping.py`, `test_member_report_scaling.py`, `test_deployment_tool_hardening.py` | None known |
| Error disclosure | No internal detail in responses | `test_error_disclosure.py` | None known |

## 5. Quality gates

| Gate | Required |
|---|---|
| Pull request | CI green, or every failure linked to an acknowledged known issue; PR template completed; for schema changes, a reviewed migration |
| Merge to `main` | As above, plus maintainer approval (see [BRANCHING.md](../BRANCHING.md)) |
| Production deployment | The release acceptance policy in [DEVELOPMENT_AND_TESTING.md §6](DEVELOPMENT_AND_TESTING.md#6-release-acceptance-policy) and the deployment steps in [OPERATIONS.md §2](OPERATIONS.md#2-code-deployment-manual-sftp) |
| Android release | Flutter tests green, the build installs over the previous field build, and offline sync is checked on a real device |

## 6. Path to a green pipeline (proposed; each step needs approval)

| # | Step | Effect | Risk |
|---|---|---|---|
| 1 | Decide the intended `per_page` cap (100 or 200), then align the test or the code | Fixes failure 1 | Low |
| 2 | Make the report-card assertion ignore comments | Fixes failure 2 | Low; test-only |
| 3 | Install `openpyxl` in the CI job | Removes 8 skips | Low; CI-only |
| 4 | Start a seeded PHP server in CI with the existing provisioning script | Removes most of the 71 server-dependent skips | Medium; CI time grows |
| 5 | Add `flutter analyze` as a CI step | Catches app issues earlier | Low |
| 6 | Run the e2e smoke journeys nightly against a provisioned CI environment | Workflow-level regression signal | Medium |

Until steps 1–4 are done, releases rely on targeted local runs as evidence, and that evidence is recorded in the pull request.

## 7. Writing a new test

1. Choose the lowest level that proves the behaviour (see §3).
2. Put PHP/endpoint tests in `tests/security/test_<area>.py`; keep fixtures synthetic and place them in `tests/fixtures/`.
3. Name the rule being protected in the test docstring, and link the decision record if one exists (for example `docs/adr/0020-score-limit-and-did-not-take.md`).
4. If the test needs a server or an optional package, skip with an explicit reason rather than failing silently.
5. Run the affected suite locally and attach the result to the pull request.
