# Test strategy

**Status:** maintained risk and gate reference, updated 2026-10-28 against source baseline `7209278`. Exact setup, workflow definitions and dated check results are in [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md).

## 1. Goals

1. Protect member/family personal data, authentication, role scope and the integrity of attendance and grade records.
2. Preserve installed-client compatibility and owner-bound offline work across server, session and authorization changes.
3. Base integration and release decisions on evidence for the exact candidate, not a previous run or an unsupported coverage claim.

## 2. Principles

- **Synthetic, isolated data only.** Provision disposable databases. Do not copy, query or modify real school records for routine tests.
- **Risk first.** Prioritize security and data integrity, then sync/state transitions, workflows and presentation.
- **Zero skips in the backend gate.** The configured job fails on any skip, even if explained. Fix missing prerequisites rather than reporting skipped tests as passes.
- **Regression tests accompany changes.** Prefer a test that fails for the original defect and passes with the correction; preserve meaningful assertions.
- **Trace all consumers.** Web endpoints, compatibility shims and REST routes may enforce similar rules separately. A test of one service does not prove every caller uses it correctly.
- **Name the evidence level.** Source assertions, unit tests, real-database integration, browser journeys, device acceptance and hosted checks prove different things.

## 3. Test levels

| Level | What it establishes | Execution boundary |
|---|---|---|
| Static and structure | PHP parses; migration/repository guards pass; links, includes and served-folder boundaries resolve | Configured automatic CI jobs and `tests/security` structure tests |
| PHP service/contract | Validation, role gates, transaction and response rules covered by the specific fixture/assertions | `tests/security/`, `tests/fixtures/`; source-only assertions do not execute a request |
| Flutter service/storage/UI | Covered client policies, queue transitions, cache behavior and widget interactions | `Mobile/wbws_flutter_app/test/`; not a real Android installation |
| Real-database integration | Covered requests, idempotency and writes against disposable MariaDB fixtures | Backend live-guard environment and separate lifecycle harness; not every browser journey |
| Browser acceptance | The exercised browser workflow works against its local test server | `tests/e2e/`; APK-transfer acceptance has a separate manual workflow |
| Build | Configured Android debug build and manifest/dex checks succeed | Backend CI; no release signing or installed-fleet acceptance |
| Manual/visual/device | Layout, text scaling, keyboard/touch/accessibility and signed upgrade behavior on tested devices | Record device, build, scenarios and observed results; do not infer from CI |
| Hosted acceptance | An explicitly authorized environment matches its acceptance criteria | Separate owner/operator action; never run production checks under ordinary local-test authorization |

## 4. Coverage by risk area

| Risk | Existing protection/examples | Remaining evidence boundary |
|---|---|---|
| Authentication, authorization and PII | Auth/rate-limit/CSRF tests, `test_feature_gate_enforcement.py`, role and class-scope fixtures | No complete reviewed per-action web/REST permission matrix; inspect action-level guards |
| Destructive actions | `test_danger_confirm.py`, `test_destructive_guard.py` | Confirm entity/scope and forward-only data effects in each release |
| Attendance and calendar | Attendance phase tests, `test_school_calendar.py`, offline-calendar/class-day tests | Source/runtime flags, clock/calendar boundaries and installed-device acceptance remain separate |
| Grades and assessments | `test_score_limit.py`, `test_education_config_endpoints.py`, Flutter grade tests | Delayed responses, approval locks and old-client compatibility need scenario-specific evidence |
| Members/import/Family Link | `test_member_import_v2.py`, duplicate/registration/guardian fixtures | Successful fixtures do not establish correctness of real imported school data |
| Mobile sync and sessions | Outbox/recovery/session tests and idempotency lifecycle | No claim that every installed older build has been exercised against the candidate server |
| Output/files/diagnostics | Escaping, report scaling, upload, error-disclosure and deployment-tool tests | A public user message or code comment promising privacy does not establish redaction of every diagnostic |
| APK transfer/update | Upload fixtures, slow-link/exact-size browser harnesses, Flutter update/UI tests | Browser upload recovery is different from mobile APK download resume; signing and installer acceptance require a phone |
| Documentation | Link/anchor, include-path and folder-boundary tests; documentation review contract | Link validity and a source inventory do not prove that every control's behavior is documented or tested |

A passing test is evidence for the assertions it executes, not a complete security certification or exhaustive user-journey guarantee.

## 5. Quality gates

| Stage | Required evidence |
|---|---|
| Pull request | Reviewed scoped diff, applicable tests and all configured checks green for the exact head; no bypassed or accepted failures |
| Development-main merge | Authorized review/merge, successful head checks, then verification of the merged revision and its checks |
| Development acceptance | Independently observed development-server update and owner test results; merge does not prove cron execution |
| Production promotion | Separate explicit owner authorization after development acceptance; production review/checks and [operational safeguards](OPERATIONS.md) |
| Android release | Signed build, compatible signer, installation over the prior field build, preserved offline work and tested sync/accessibility scenarios on the owner's phone |

No production approval is implied by green CI. [PRODUCTION_WORKFLOW.md](PRODUCTION_WORKFLOW.md) defines repository/environment separation.

## 6. Path to a green pipeline (historical repair and remaining work)

The earlier roster-cap/comment assertions were repaired on 2026-10-24. PR #41 then installed the missing import dependency, provisioned/started the guarded synthetic HTTP world and fixed fixture database selection. All eight baseline checks on `7209278` passed on 2026-10-28; see [baseline evidence](reports/DOCUMENTATION_BASELINE_2026-10-28.md).

Remaining improvements require scoped review; they are not already implemented merely because they appear here:

- Run backend behavioral suites on PHP 8.5, not only its lint job.
- Evaluate an enforced Flutter analysis step using the existing analysis baseline.
- Expand automated browser/device journeys and installed-old-client compatibility coverage.
- Complete the source-reviewed role/screen/control manual and per-action permission ledger.

Do not weaken the current zero-skip policy or fold unrelated infrastructure changes into a documentation patch.

## 7. Writing a new test

1. Identify the workflow, affected roles, shared consumers and failure/edge conditions before choosing a level.
2. Use existing isolated fixtures and the lowest level that proves the rule. Add integration or UI coverage where a source assertion cannot establish the outcome.
3. Name the protected rule and link the relevant ADR. Keep fixtures synthetic and test-only.
4. If prerequisites are missing locally, report an incomplete environment explicitly. An optional local skip must state its reason, but it still cannot satisfy the backend zero-skip release gate.
5. Run affected and neighboring regressions, then the applicable full suites. Attach commands, versions, exit codes and counts without adding overlapping targeted/full totals together.
6. Report unexecuted scenarios, runtime dependencies and phone/hosting gates separately.
