# Engineering handover

**Prepared:** 2026-10-22. **Baseline:** `main` = `46fbaa7` in both repositories; `dev` = `1765498`.
**Scope:** documentation only. No code, configuration, schema, CI or history was changed while preparing it.

Every statement either points to evidence (a file, commit or CI run) or is marked **unverified**. Earlier audits, including AI-assisted ones, were treated as leads to re-check, not as facts.

## 1. System overview

FKSS is a PHP 8.2 / MariaDB web application for the Felege Kidusan Sunday School, with a Flutter Android app that works offline.
- **Hosting:** a single shared-hosting account.
- **Deployment:** manual SFTP.
- **Database changes:** applied by hand by the owner.

See [ARCHITECTURE.md](ARCHITECTURE.md) for structure, [API.md](API.md) for interfaces and [DATABASE.md](DATABASE.md) for schema management.

## 2. Sources of truth

| Question | Authoritative source | Not authoritative |
|---|---|---|
| What is the code? | `main` of `suraman21/fkss-sunday-school-platform`. Its commits are identical to `Felege-Kidusan/felegekidusan` `main` | Feature branches, `dev` (beta settings), stale branches |
| What is deployed? | The owner's deployment record (commit, file list, checksum result). Last recorded: `46fbaa7`, 2026-10-22, 89/89 files verified | Anything on the server, git metadata, this repository |
| What is the database schema? | The production database. **Not verified;** see [DATABASE.md §4](DATABASE.md#4-applied-state-in-production-unverified) | `database_schema.sql` (stale); `sql/` describes intent only |
| Which app version is in the field? | The app-release manager configuration (`/api/v1/app/config`) | `pubspec.yaml` on `main` |
| Who may do what? | `admin/access_control.php`, `api/v1/core/acl.php`, in-endpoint checks | Older docs |
| Owner decisions | [Architecture decision records](adr/README.md) | Historical audits |
| Security and privacy findings | The owner's restricted register | This public repository |
| How is the system structured? | [ARCHITECTURE.md](ARCHITECTURE.md), [API.md](API.md), [DATABASE.md](DATABASE.md) | — |

## 3. Branch and change rules

- `dev` is never merged, rebased, cherry-picked or pushed into `main`, and is never pushed to the production repository ([BRANCHING.md](../BRANCHING.md)).
- After each `main` push, `main` is merged into `dev` in the development repository.
- **Changes:** work on a feature or documentation branch, then open a pull request to `main`.
- **Approval:** logic, schema, migration, configuration, deployment and release changes need the owner's explicit approval.
- **Migrations:** applied by the owner.
- **Releases:** deployment and app releases happen only on the owner's explicit instruction.
- The intended policy is protected `main`, a review, and green CI. **None of it is currently enforced** ([PRODUCTION_WORKFLOW.md](PRODUCTION_WORKFLOW.md#current-state-verified-2026-10-22)).

## 4. Verified CI status

On `46fbaa7` (run `39744630068`):
- **Passing:** PHP lint, Flutter tests (657), migration checks and the debug APK build.
- **Failing:** the security and regression suite: 2 failed, 2,793 passed, 79 skipped.
  - Both failures come from tests whose expectations no longer match deliberate code or comment changes.
  - The skips need a seeded server or `openpyxl` in CI.
  - Update 2026-10-24: both failures fixed (test-only); the 79 skips remain, so the suite is still red.
- **Production integrity** and **Branch guard** pass.
- Details: [DEVELOPMENT_AND_TESTING.md §3](DEVELOPMENT_AND_TESTING.md#3-current-ci-status).

## 5. Unknowns that need read-only verification

| # | Unknown | How to verify (read-only) | Who |
|---|---|---|---|
| U-1 | The actual production schema compared with `sql/` | A structure-only export (no data) from phpMyAdmin, diffed against a `provision_env.sh` database | Owner exports; engineer diffs |
| U-2 | Which migrations are applied, including the reverted `047` | Derive from U-1 | Engineer |
| U-3 | Hosting-provider backup frequency, retention and restore procedure | Hosting control panel or provider documentation | Owner |
| U-4 | Production values of `ENABLE_TEST_DATA_TOOLS`, `FEATURE_*` and AI enablement | Owner reads the secrets file (names and booleans only) | Owner |
| U-5 | Whether Telegram alerts and any uptime cron are configured | Owner checks the settings and control panel | Owner |
| U-6 | Which staff roles are in active use (for example finance and material) | A count of users by role (no names) | Owner |
| U-7 | Who holds the Android signing keystore and the backup encryption key, and where | Owner statement | Owner |
| U-8 | How the beta server is updated | Owner statement | Owner |
| U-9 | Whether any caller outside `frontend/` uses `backend/api/*` | Web-server access logs, filtered by path | Owner / engineer |
| U-10 | Current production MariaDB version. The last record is 11.4, from a 2026-10-02 schema dump (`sql/054_legacy_era_columns.sql` line 33) | The *Server version* shown on the phpMyAdmin home page | Owner |

## 6. Risk register (public summary)

**The highest-priority items in the full register are security and privacy items held in the owner's restricted register.** They take precedence over everything below. This public list contains only items whose disclosure does not increase risk.

Severity: Critical / High / Medium / Low. Confidence: Verified (checked against code, CI or listing on 2026-10-22) / Inferred.

| ID | Risk | Severity | Evidence | Impact | Confidence | Owner action | Priority |
|---|---|---|---|---|---|---|---|
| P-1 | CI gate is red and 79 tests are skipped, so a green gate cannot serve as release evidence | High | Run `39744630068`; [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md#31-the-two-failures) | Regressions can ship unnoticed | Verified | Approve two test-only fixes, a seeded server in CI and `openpyxl` in CI | 1 |
| P-2 | Android APK is debug-signed; key custody is undocumented | High | `android/app/build.gradle` fallback; `RELEASE.md` §3 | Losing the keystore blocks updates to every device; changing keys forces reinstalls | Verified | Decide keystore custody and a migration plan, before the device fleet grows | 1 |
| P-3 | Backups are in the same hosting account, with no off-site copy and no restore ever tested | High | `OPERATIONS.md` §4 (listing only) | A loss of the account means total data loss; backups are unproven | Verified | Set up an off-site copy, record key custody, run a restore test | 1 |
| P-4 | No migration ledger; production schema unverified | High | `sql/` inventory; no ledger table | Migrations may be missing or duplicated; deploys can break | Verified | Carry out U-1/U-2, then approve a ledger design | 2 |
| P-5 | Manual deployment with no staging, no automated version record and no rehearsed rollback | Medium | `OPERATIONS.md` §2–3 | Partial or incorrect deploys; slow recovery | Verified | Keep the checklist and log; rehearse a rollback; consider a staging copy | 2 |
| P-6 | No branch protection or required review on production `main` | Medium | GitHub settings (plan limitation); direct pushes since 2026-10-08 | Unreviewed changes can reach production | Verified | Choose: a paid plan, or a written two-person rule | 2 |
| P-7 | Monitoring and incident response are informal; uptime checking may not run | Medium | `monitor/uptime_cron.php` needs cron; U-5 | Outages are found by users; slow response | Inferred | Set up an external uptime check; write a one-page incident plan | 3 |
| P-8 | CI runs PHP 8.4; production runs 8.2 | Medium | `backend-checks.yml` | Code that passes CI can still fail in production | Verified | Approve a PHP 8.2 CI job | 3 |
| P-9 | Three API layers with duplicated business rules | Medium | [ARCHITECTURE.md §3](ARCHITECTURE.md#3-the-three-api-layers-unfinished-consolidation) | Inconsistent behaviour between web and app; costly change | Verified | Commission a consolidation plan during the architecture stage | 3 |
| P-10 | Runtime DDL (Mezmur) | Medium | `MezmurSchemaReconciler::apply` | Schema drift; surprises during requests | Verified | Move it into migrations (needs approval) | 3 |
| P-11 | Very large files (dashboards of about 4,400–4,900 lines; `local_db.dart` about 7,500 lines) | Low | Line counts | Slow, risky change | Verified | Refactor incrementally when touched | 4 |
| P-12 | Mobile 1.7.3+40 is on `main` but not released (the field gets 1.6.10/36) | Low | `/api/v1/app/config` (2026-10-22) | Server and app features diverge | Verified | Decide on the upload | 4 |
| P-13 | Historical documents overstated readiness | Low | See [docs/README.md](README.md) | Misleads new engineers | Verified | Addressed by this documentation pass (banners and index) | Done (pending merge) |

## 7. Remaining stages

| Stage | Purpose | Status | Exit criterion |
|---|---|---|---|
| 1. Baseline | Establish facts about the code, CI, deployment and documentation | **Done:** read-only baseline audit and this documentation pass (2026-10-22) | This document and the restricted register accepted by the owner |
| 2. Requirements and architecture reconstruction | Record the actual business rules per department, and decide the target API layer and schema-management approach | Not started | A written, owner-approved requirements and architecture baseline, including answers to U-1 to U-10 |
| 3. Independent review | An independent security and code review: per-action authorization matrix, dependency audit (`composer audit` has not been run), penetration test | Not started | A review report, with findings added to the restricted register |
| 4. Approved remediation | Fix the agreed items one at a time, each with an approved plan, tests and a deployment record | Not started; the owner has deferred some items | Critical and High items closed or formally accepted |
| 5. Engineering handover | Transfer to the receiving engineers: access, keys, runbooks, walkthrough | Preparation documented here | The receiving team can deploy, roll back and restore independently |

## 8. Where to start (new engineer)

1. Read [README.md](../README.md), this document and [BRANCHING.md](../BRANCHING.md).
2. Build the test environment ([DEVELOPMENT_AND_TESTING.md §2](DEVELOPMENT_AND_TESTING.md#2-local-setup-test-environment-only)).
3. Read [ARCHITECTURE.md](ARCHITECTURE.md), then [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md).
4. Before any change, follow [CONTRIBUTING.md](../CONTRIBUTING.md).
