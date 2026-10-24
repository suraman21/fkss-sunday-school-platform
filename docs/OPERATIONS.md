# Operations: deployment, backups, mobile release, monitoring

**Status:** canonical. **Verified against:** `main` at `46fbaa7` and the deployment of that commit on 2026-10-22.

This repository is public. Host names, ports, accounts, server paths and network addresses are deliberately **omitted**; they are held privately by the maintainers. Never add them here.

## 1. Environments

| Environment | Branch | Notes |
|---|---|---|
| Production | `main` | Shared hosting: LiteSpeed, MariaDB, PHP 8.5 (confirmed on 2026-10-23; the host can change the version without notice). It sits behind a CDN/WAF proxy. File access is SFTP only; there is no shell, Composer or cron for the application, and no Python or Redis |
| Beta | `dev` | A separate server and a separate beta Android app. Only beta settings live on `dev`; see [BRANCHING.md](../BRANCHING.md) |
| Staging for production | — | **None.** Changes are verified locally (section 2) and then deployed |

## 2. Code deployment (manual SFTP)

This is the procedure actually used, most recently for `5b14afb` on 2026-10-24, when 10 files were uploaded and each live file was checked against the commit. **Deploy only with explicit approval from the maintainers.**

1. **Choose the commit.**
   - The commit must be on `main` and pushed to both repositories.
   - Record the CI result honestly (see [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md#3-current-ci-status)).
   - Run the relevant local tests against a test database.
2. **List the changed files.** Run `git diff --name-only <deployed-commit>..<target-commit>`.
   - Exclude files that exist only on the server: the secrets file, server-only `.htaccess` additions, and server-only folders.
   - Exclude anything outside the release, such as docs and tests if they are not served.
3. **Migrations.** If the change includes `sql/` files, a maintainer with production access reviews and applies them in phpMyAdmin. Follow the order stated in the change (usually migrations before code), then record them (see [DATABASE.md](DATABASE.md#4-applied-state-in-production-unverified)).
4. **Back up the server copy.**
   - Download the current server version of **every file that will be overwritten** into a dated local folder.
   - Record each file's checksum, and note which files are new.
5. **Upload** only the listed files over SFTP, preserving paths.
   - **Deleted files are not removed by an upload.** For every file the release deletes (`git diff --name-status` shows `D`), move the server copy into a dated backup folder outside `public_html` instead of deleting it, so it can be put back.
   - Confirm the server folders in [section 2.1](#21-server-folders-that-git-does-not-create) exist.
6. **Verify.**
   - Recompute checksums of the uploaded server files and compare them with the commit; every file must match.
   - Smoke-check the staff login page (`/admin/index.php`) and the mobile configuration endpoint (`/api/v1/app/config`).
   - Check the error monitor.
   - Run `python3 scripts/check_live_urls.py --server production` (see [section 2.2](#22-folder-structure-safety-checks)).
7. **Record** the deployed commit, the file list, the checksum result and any migrations in the [production deployment record](../CHANGELOG.md#production-deployment-record).

### 2.1 Server folders that Git does not create

Some folders are deliberately ignored by Git (`.gitignore`) but the code needs them at runtime. A fresh server, or a server set up by copying files, will not have them. Create them over SFTP with permission `755`, owned by the hosting account.

| Folder | Needed by | What breaks without it |
|---|---|---|
| `admin/id_cards/assets/qr/` | ID-card generation and QR renewal (`admin/id_cards/generate_id_card.php`, `IdentityCodeService`) | ID-card generation answers "ID-card generation is temporarily unavailable" and the error log says "The deployment-managed ID-card QR directory is unavailable". This happened on production on 2026-10-23: the folder had never been created there, and it was created that day |

The wider list of writable folders is in [`audits/DEPLOYMENT_RUNBOOK.md`](audits/DEPLOYMENT_RUNBOOK.md) ("Stage 3 — File permissions"). It has not been re-verified against production.

### 2.2 Folder-structure safety checks

These checks protect the folder clean-up ([RFC 0001](rfc/0001-repository-structure.md)). Run them before and after **any** change that moves, renames or deletes files.

**1. In CI (automatic), part of `tests/security`:**

| Test | What it stops |
|---|---|
| `test_structure_include_paths.py` | A moved or renamed PHP file that other files still `require`/`include`. Every static link must point to an existing file (760 links at `9dd317f`). Links built from variables (41) are pinned in a reviewed list; a new one fails until someone reviews it |
| `test_structure_boundaries.py` | (1) Website code loading files from `tests/`, `sql/`, `docs/`, `Mobile/`, `scripts/`, `tools/` or `.github/`. (2) The root `.htaccess` losing its block on those folders, `vendor/` and repository file types. (3) A command-line or cron script in a website folder without a `PHP_SAPI` guard or a `.htaccess` deny. (4) New `.md`, `.sql`, `.sh`, `.py` and similar files inside `admin/`, `api/v1/` or `app/`, where the root block does not reach (those folders have their own rewrite rules) |
| `test_docs_links.py` | A moved or renamed document that other documents still link to, or a moved document whose own links no longer work. Every relative Markdown link and `#section` anchor must resolve (third-party folders excluded) |

Shared code lives in `tests/security/structure_paths.py`.

**2. On the live sites (manual, read-only):**

```
python3 scripts/check_live_urls.py --server dev
python3 scripts/check_live_urls.py --server production
```

- It requests 43 URLs from `scripts/live_urls.json` and compares only the HTTP status: public pages, staff login, API, blocked folders and blocked scripts.
- It is read-only. It uses GET only, never logs in, never follows redirects and discards page content.
- **Every request has a unique cache-busting query.** A plain request for a private file through the CDN can make the CDN keep a copy for 30 days. Never probe private files with plain URLs.
- **Exit codes:** 0 = all as expected; 1 = a real difference; 2 = only transient errors.
- **Transient errors:** the CDN sometimes answers 520–525 for a single request. The script retries twice and reports leftovers as transient, not as failures.
- **Known server difference:** `/app/` and `/app/manifest.json` answer differently on production, because production's `app/` folder is managed on the server.
- **Baseline:** recorded on 2026-10-23 at `9dd317f`; dev and production both 43 of 43.
- **Changes:** change an expected status only with a reason in the pull request.

**Deployed version:** nothing on the server reliably records what is deployed. The [production deployment record](../CHANGELOG.md#production-deployment-record) is authoritative. Do not infer the deployed version from server-side files.

## 3. Rollback

- **Code:** re-upload the files saved in step 4 and delete any files that were new in the release. Then repeat the verification in step 6.
  - Rollback **has not been rehearsed** as a drill.
  - A rollback package was prepared for the 2026-10-22 deployment but not executed.
- **Database:** migrations are forward-only; there are no down-migrations.
  - Additive migrations usually tolerate a code rollback, but this must be checked for each change.
  - Data changes can only be reversed from a database backup (section 4). No database restore has been tested.
- **Server configuration:** any production-only configuration change must have its prior copy saved before the change, so that it can be put back.

## 4. Backups

| Item | State (2026-10-22) | Evidence level |
|---|---|---|
| Application database backups | `BackupService` creates encrypted dumps (`BACKUP_ENCRYPTION_KEY`) at `BACKUP_STORAGE_PATH`. Recent encrypted backup files exist in the hosting account | Verified: file listing only |
| Location | **The same hosting account as production.** There is **no off-site copy** | Verified |
| Restore test | **Never performed.** No backup is known to be restorable | — |
| Hosting-provider backups | Unknown frequency, retention and restore procedure | Unverified |
| Key custody | Without `BACKUP_ENCRYPTION_KEY` the backups are unusable. Where it is held outside the server is not documented | Unverified |

**Required (not yet done):**
1. Make a regular off-site copy of the encrypted backups.
2. Record where the backup key is held, with two custodians.
3. Run a restore test every quarter, into a disposable database, with the result recorded.

Until a restore succeeds, treat backups as **unproven**.

## 5. Mobile release

1. **Build** with `Mobile/wbws_flutter_app/scripts/build-release.ps1`, which produces the universal APK. Follow `Mobile/wbws_flutter_app/docs/RELEASE.md`.
2. **Publish:** the super admin uploads the APK and sets the update policy in the web app-release manager (`admin/api_app_release.php`). Devices read `/api/v1/app/config`.
3. **Version state:**
   - `main` contains **1.7.3+40**.
   - On 2026-10-22, production served **1.6.10 (build 36)** as the latest app.
   - 1.7.3 has not been uploaded; uploading it needs a release decision.
4. **Signing (risk):**
   - Without `android/key.properties`, Gradle falls back to the **debug** signing configuration. The APK distributed in production is debug-signed.
   - Android only installs an update that has the same signature as the installed app. Losing the build machine's debug keystore therefore stops in-place updates for every device.
   - Switching to a proper release keystore requires every device to uninstall and reinstall. That would also erase unsynced offline data.
   - A key-custody decision and a planned migration are needed. See `RELEASE.md` §3 and [§7.1](#71-known-limitations) (L-2).
5. **Old-app compatibility:** before releasing server changes, check that installed older builds keep working, or are told to update. The legacy-token window for builds below 16 ended on 2026-09-24.

## 6. Monitoring and incident response

| Capability | State |
|---|---|
| Error monitor | `monitor/` (error monitor and dashboard). The dashboard requires a super admin session that is revalidated against the database (`monitor/index.php`) |
| Telegram failure alerts | Implemented (`FailureAlertService`). Production configuration unverified |
| Failure intelligence and mobile telemetry | Super admin dashboards (`api_failure_intelligence.php`, `api_telemetry.php`) |
| Uptime checks | `monitor/uptime_cron.php` needs cron. Whether a cron job is configured is unverified; there may be **no** external uptime monitoring |
| On-call / escalation | None documented |

**Incident response (current, informal):**
1. One maintainer is the single point of contact; there is no on-call rota.
2. Contain the problem by rolling back the code (section 3) or disabling the feature through its `FEATURE_*` flag in the secrets file.
3. Record what happened in `docs/incidents/` (see `docs/incidents/INCIDENT_2026-10-03_TRACKING_SCHEMA.md` for an example).

Security incidents follow [SECURITY.md](SECURITY.md). A written incident-response plan with roles and contact paths does not exist yet.

## 7. Known limitations and open questions

Security and privacy findings are tracked privately and take precedence over the items below (see [SECURITY.md §5](SECURITY.md#5-open-findings)). This list contains only items whose publication does not increase risk.

### 7.1 Known limitations

Rechecked against the code, CI and repository settings on 2026-10-25. Severity: High / Medium / Low.

| ID | Limitation | Severity | Evidence | Impact | Recommended action |
|---|---|---|---|---|---|
| L-1 | The security and regression suite is red because 79 tests are skipped (the job fails on any skip; there are no failing tests) | High | [DEVELOPMENT_AND_TESTING.md §3.2](DEVELOPMENT_AND_TESTING.md#32-the-79-skips) | A green gate cannot yet serve as release evidence | Start a seeded test server in CI and install `openpyxl` so the skipped tests run |
| L-2 | The distributed Android APK is debug-signed; keystore custody is not documented | High | `Mobile/wbws_flutter_app/android/app/build.gradle` (falls back to debug signing without `key.properties`); `RELEASE.md` §3 | Losing the keystore blocks updates on every device; changing keys forces reinstalls | Decide keystore custody and plan the move to a release key before the device fleet grows |
| L-3 | Backups are kept in the same hosting account, with no off-site copy; a restore has never been tested | High | [§4](#4-backups) | Loss of the account means loss of the data; backups are unproven | Off-site copy, recorded key custody, quarterly restore test |
| L-4 | No migration ledger; the applied production schema is unverified | High | [DATABASE.md §4](DATABASE.md#4-applied-state-in-production-unverified) | Migrations may be missing or applied twice; deploys can break | Answer Q-1 and Q-2, then introduce a ledger |
| L-5 | Manual deployment with no staging copy, no automated version record and no rehearsed rollback | Medium | [§2](#2-code-deployment-manual-sftp), [§3](#3-rollback) | Partial or incorrect deploys; slow recovery | Keep the deployment checklist and record; rehearse a rollback; consider a staging copy |
| L-6 | No branch protection on the production repository's `main` (not available on the current GitHub plan). The development repository requires only the *Branch guard* check, with no review | Medium | Repository settings | Unreviewed changes can reach production | Upgrade the plan, or adopt a written two-person review rule |
| L-7 | Monitoring and incident response are informal; uptime checks may not run | Medium | [§6](#6-monitoring-and-incident-response); `monitor/uptime_cron.php` needs cron | Outages are noticed by users first | External uptime check; a one-page incident plan |
| L-8 | The full test suite runs on PHP 8.4; production runs PHP 8.5. A separate CI job lints every PHP file on 8.5 and fails on deprecations | Medium | `.github/workflows/backend-checks.yml` | Behaviour differences between 8.4 and 8.5 are not covered by tests | Run the test suite on PHP 8.5 |
| L-9 | Three API layers with duplicated business rules | Medium | [ARCHITECTURE.md §3](ARCHITECTURE.md#3-the-three-api-layers-unfinished-consolidation) | Inconsistent behaviour between web and app; costly changes | Choose the canonical layer per domain and consolidate behind services |
| L-10 | Runtime schema changes for Mezmur tables | Medium | `MezmurSchemaReconciler::apply` | Schema drift; changes happen during requests | Move these changes into migrations |
| L-11 | Very large files: the HR, Information and Education dashboards (about 4,400–4,900 lines each) and `local_db.dart` (about 7,500 lines) | Low | Line counts | Slow, risky changes | Split by feature, behind tests, when the files are next changed |
| L-12 | Mobile 1.7.3+40 is on `main` but not released; production serves 1.6.10 (build 36) | Low | `pubspec.yaml`; `/api/v1/app/config` (2026-10-25) | Server and app features diverge | Decide when to publish 1.7.3 |

### 7.2 Open questions

Each question can be answered without changing anything. Most need production access.

| ID | Question | How to answer (read-only) |
|---|---|---|
| Q-1 | Does the production schema match `sql/`? | Export the structure only (no data) and diff it against a database built by `tests/e2e/provision_env.sh` |
| Q-2 | Which migrations are applied, including the reverted `047`? | Derive from Q-1 |
| Q-3 | What are the hosting provider's backup frequency, retention and restore procedure? | Hosting control panel or provider documentation |
| Q-4 | What are the production values of `ENABLE_TEST_DATA_TOOLS` and the `FEATURE_*` flags? | Read the secrets file (names and booleans only) |
| Q-5 | Are Telegram alerts and an uptime cron job configured? | Check the settings and the hosting control panel |
| Q-6 | Which staff roles are in active use (for example finance and material)? | Count users by role (no names) |
| Q-7 | Where are the Android signing keystore and the backup encryption key held, and by whom? | Written statement from the key holders |
| Q-8 | How is the beta server updated? | Written description of the current procedure |
| Q-9 | Does any caller outside `frontend/` use `backend/api/*`? | Web-server access logs, filtered by path |
| Q-10 | Which MariaDB version runs in production? The last record is 11.4, from a 2026-10-02 schema dump (`sql/054_legacy_era_columns.sql` line 33) | The server version shown in phpMyAdmin |
