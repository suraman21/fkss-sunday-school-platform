# Operations: deployment, backups, mobile release, monitoring

**Status:** maintained operational reference. Release policy updated 2026-10-28 against source baseline `7209278`. Hosting, backup and deployment observations below retain their original dates; they have not been reverified through production access.

This repository is public. Keep private infrastructure host names, ports, accounts, server paths and network addresses out of this document; maintainers must use approved private records. This audit did not verify those records or their custody. Never add private infrastructure details here.

## 1. Environments

| Environment | Repository / branch | Evidence and limits |
|---|---|---|
| Development | Development repository / `main` | Owner's current instruction: merge checked changes here; cron updates the development server; owner tests before any production promotion. Cron execution, installed files and schema must be observed separately |
| Production | Separate production repository / `main` | Requires separate explicit owner authorization after development acceptance. Historical hosting record: shared LiteSpeed hosting behind a CDN/WAF, PHP 8.5 observed 2026-10-23 and SFTP file access. Current hosting tools, cron, configuration and deployed revision are not verified |
| Beta | Development repository / `dev` | Separate beta settings and Android identity; never merge `dev` into `main`. See [BRANCHING.md](../BRANCHING.md) |
| Isolated local tests | Candidate revision with synthetic fixtures | Authorized verification environment for routine work. No production data, credentials or network tests |

The historical statement that there was no staging or application cron does not override the owner's current development-first workflow. This policy is not evidence that a server has already pulled a particular merge.

## 2. Code deployment (manual SFTP)

**Conditional production procedure, not an instruction to deploy now.** The dated record for `5b14afb` on 2026-10-24 describes ten uploaded files with checksum verification. Use this procedure only if the owner separately authorizes production promotion and confirms SFTP is the selected deployment method. Development-main integration and owner acceptance come first; see [PRODUCTION_WORKFLOW.md](PRODUCTION_WORKFLOW.md).

1. **Choose the commit.**
   - Record the exact accepted development SHA, owner acceptance and separate production authorization. Promote only that approved state through the production workflow; do not automatically push every development merge to both repositories.
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

**Deployed version:** the [production deployment record](../CHANGELOG.md#production-deployment-record) is a dated record, not proof of the current runtime. Verify an authorized target against the recorded SHA/file checksums, schema and configuration. Repository equality, server-side Git metadata or one reachable page does not establish full deployment parity. Routine documentation work must not run the live-site commands above without separate authorization.

## 3. Rollback

- **Code:** re-upload the files saved in step 4 and delete any files that were new in the release. Then repeat the verification in step 6.
  - Rollback **has not been rehearsed** as a drill.
  - A rollback package was prepared for the 2026-10-22 deployment but not executed.
- **Database:** migrations are forward-only; there are no down-migrations.
  - Additive migrations usually tolerate a code rollback, but this must be checked for each change.
  - Data changes can only be reversed from a database backup (section 4). No database restore has been tested.
- **Server configuration:** any production-only configuration change must have its prior copy saved before the change, so that it can be put back.

## 4. Backups

**Historical provenance:** the earlier 2026-10-22 documentation reported encrypted files in the hosting account, no off-site copy and no restore test. This audit has not inspected the underlying hosting evidence or reverified those observations. Do not interpret the earlier "Verified" labels as current audit results or infer that no later backup/restore work occurred.

| Item | Current evidence and limit | Evidence level |
|---|---|---|
| Application database backups | `BackupService::create` invokes `EncryptedBackupWriter`; source implementation is not evidence that a hosted backup exists or completed successfully | Source path identified; hosted files UNKNOWN |
| Location | `BackupService::privateStorageRoot` uses a nonempty `BACKUP_STORAGE_PATH` or a sibling `ssms_secure_backups` directory. Actual configured storage and off-site copies were not inspected | Source selection verified; hosted/off-site state UNKNOWN |
| Restore test | No hosted backup restore was executed or its evidence inspected in this audit. Recoverability of a particular backup remains UNKNOWN | DEPENDENCY NOT VERIFIED |
| Hosting-provider backups | Frequency, retention and restore procedure were not inspected | UNKNOWN |
| Key custody | Source selects `BACKUP_ENCRYPTION_KEY` when defined, otherwise `BACKUP_KEY` (`BackupService::encryptionSecret`). Recovery needs the effective key used for the particular backup; actual custody and recoverability have not been verified in this audit | Source selection verified; hosted custody/recovery unverified |

**Required before relying on backups; record completion separately:**
1. Make a regular off-site copy of the encrypted backups.
2. Record where the backup key is held, with two custodians.
3. Run a restore test every quarter, into a disposable database, with the result recorded.

Until a restore succeeds, treat backups as **unproven**.

## 5. Mobile release

**1.7.4+41 is a source checkpoint, not a published APK.** Follow the
[development class-day acceptance checklist](../Mobile/wbws_flutter_app/docs/ATTENDANCE_CLASS_DAY_1_7_4.md).
The owner's current release instruction is development-repository main first
(cron to development), owner testing, then separately approved production
promotion. Do not deploy to both repositories automatically. Phone installation,
release signing and published APK metadata require their own acceptance evidence.

1. **Build** with `Mobile/wbws_flutter_app/scripts/build-release.ps1`, which produces the universal APK. Follow `Mobile/wbws_flutter_app/docs/RELEASE.md`.
2. **Publish:** the super admin uploads the APK and sets the update policy in the web app-release manager (`admin/api_app_release.php`). Devices read `/api/v1/app/config`.
3. **Version state:**
   - Baseline `7209278` contains source checkpoint **1.7.4+41** in `pubspec.yaml` and `lib/utils/config.dart`.
   - The 2026-10-22 record reported **1.6.10 (build 36)** in production release metadata. Current publication and installed-fleet versions are not reverified.
   - A source version, successful CI build or downloaded APK does not prove publication or installation. Record each separately.
4. **Signing (risk):**
   - Without `android/key.properties`, Gradle falls back to the **debug** signing configuration. Earlier release records describe debug-signed distribution; the currently distributed APK's signer has not been reverified.
   - The Gradle `signingConfig` expression establishes the selected build configuration, not the signing identity of previously distributed APKs or installed devices. Historical Gradle comments about fleet trust are not runtime evidence.
   - A different or unavailable signing identity creates an upgrade-compatibility verification requirement. This checkout does not establish that every device must uninstall/reinstall, and that is not an approved migration procedure. Preserve unsent work and obtain owner acceptance of a verified device-specific plan before any destructive step.
   - The owner must verify actual offered/installed certificates, platform compatibility and key custody. See [the release guide](../Mobile/wbws_flutter_app/docs/RELEASE.md#3-signing-and-key-custody) and [§7.1](#71-known-limitations) (L-2).
5. **Old-app compatibility:** before releasing server changes, check that installed older builds keep working, or are told to update. The legacy-token window for builds below 16 ended on 2026-09-24.

## 6. Monitoring and incident response

| Capability | State |
|---|---|
| Error monitor | `monitor/` (error monitor and dashboard). The dashboard requires a super admin session that is revalidated against the database (`monitor/index.php`) |
| Telegram failure alerts | Implemented (`FailureAlertService`). Production configuration unverified |
| Failure intelligence and mobile telemetry | Super admin dashboards (`api_failure_intelligence.php`, `api_telemetry.php`) |
| Uptime checks | `monitor/uptime_cron.php` needs cron. Whether a cron job is configured is unverified; there may be **no** external uptime monitoring |
| On-call / escalation | None documented |

Use the [incident-response procedure](INCIDENT_RESPONSE.md) for authority, private evidence, unsent-work preservation, approved containment and recovery verification. It is a written checklist, **not proof of an operational on-call rota or drill**. The owner must still assign the lead/operator and private contact/escalation paths; these are not published here.

Security incidents follow [private security reporting](SECURITY.md#1-reporting-a-vulnerability). Rollback, feature changes, migrations and production access require the appropriate target-specific approval; do not treat an incident report as permission to perform them. Store only sanitized dated summaries in `docs/incidents/`.

## 7. Known limitations and open questions

Security and privacy findings must be handled privately and take precedence over the items below (see [SECURITY.md §5](SECURITY.md#5-open-findings)). That is policy, not verification of a private register or operational response. This list is not a complete security assessment; keep sensitive evidence out of public documentation.

### 7.1 Known limitations

Historical observations are dated 2026-10-25 unless a row says otherwise. The CI/source correction below is verified on 2026-10-28; production hosting, backup custody and repository-protection settings are not freshly audited. Severity: High / Medium / Low.

| ID | Limitation | Severity | Evidence | Impact | Recommended action |
|---|---|---|---|---|---|
| L-1 | **Resolved 2026-10-28:** the earlier 79-skip CI environment gap | Closed | [Baseline checks](reports/DOCUMENTATION_BASELINE_2026-10-28.md), [historical explanation](DEVELOPMENT_AND_TESTING.md#32-the-79-skips) | All eight baseline checks passed; this does not verify a later SHA or runtime deployment | Retain zero-skip enforcement and run fresh checks for each candidate |
| L-2 | Source falls back to debug signing without private `key.properties`; actual offered/installed certificates and custody are not freshly verified | High | Gradle signing selection; [release guide](../Mobile/wbws_flutter_app/docs/RELEASE.md#3-signing-and-key-custody) | A different/lost signing identity can prevent in-place upgrades; uninstall can destroy unsent work | Owner verifies actual certificate continuity and secure key custody before any publication or planned signer migration |
| L-3 | Earlier documentation reported same-account backups, no off-site copy and no restore test; current storage, copies and recovery evidence were not inspected | High | [Historical provenance and source boundary in §4](#4-backups) | Backup recoverability and resilience to hosting-account loss remain unverified | Owner verifies off-site copies, effective-key custody and a disposable restore; record dated results |
| L-4 | No migration ledger; the applied production schema is unverified | High | [DATABASE.md §4](DATABASE.md#4-applied-state-in-production-unverified) | Migrations may be missing or applied twice; deploys can break | Answer Q-1 and Q-2, then introduce a ledger |
| L-5 | Development-first acceptance is now the owner policy; actual cron/deployment parity and rollback rehearsal remain unverified | Medium | [§1](#1-environments), [§2](#2-code-deployment-manual-sftp), [§3](#3-rollback) | A merge can be mistaken for a tested server deployment | Record the development revision and owner acceptance; separately authorize production; rehearse rollback safely |
| L-6 | Earlier documentation reported limited development protection and no production-main protection. Current protection, plan and review requirements were not verified by this audit | Medium | Historical settings claim; [release-policy boundary](PRODUCTION_WORKFLOW.md#historical-observations) | Source checkout and old settings records do not establish current enforcement | Verify authorized repository settings and required reviews before integration/promotion; do not bypass protection |
| L-7 | Incident checklist exists, but named staffing, private escalation, uptime schedules and drills remain unverified | Medium | [Incident response](INCIDENT_RESPONSE.md); `monitor/uptime_cron.php` needs scheduling | Monitoring/response cannot be assumed operational from documentation alone | Owner assigns contacts and verifies schedules, escalation and a safe drill |
| L-8 | Checked-in backend suite job selects PHP 8.4; the separate PHP 8.5 job performs first-party syntax/deprecation checks with explicit vendor exclusions. Earlier documentation reported PHP 8.5 hosting; current production PHP was not inspected | Medium | `.github/workflows/backend-checks.yml`, `security-tests` and `php-lint-85` | An 8.5 lint result is not execution of the backend suite on 8.5 or verification of hosted PHP | Consider a separately scoped PHP 8.5 suite run; verify target PHP before release |
| L-9 | Three API layers with duplicated business rules | Medium | [ARCHITECTURE.md §3](ARCHITECTURE.md#3-the-three-api-layers-unfinished-consolidation) | Inconsistent behaviour between web and app; costly changes | Choose the canonical layer per domain and consolidate behind services |
| L-10 | Runtime schema changes for Mezmur tables | Medium | `MezmurSchemaReconciler::apply` | Schema drift; changes happen during requests | Move these changes into migrations |
| L-11 | Very large files: the HR, Information and Education dashboards (about 4,400–4,900 lines each) and `local_db.dart` (about 7,500 lines) | Low | Line counts | Slow, risky changes | Split by feature, behind tests, when the files are next changed |
| L-12 | Source checkpoint is 1.7.4+41 at `7209278`; current APK publication and installed-fleet versions are unverified | Low | `pubspec.yaml`, `lib/utils/config.dart`; older release-metadata observation is dated 2026-10-25 | Source and installed clients may expose different features | Owner verifies signed builds, upgrade compatibility and publication separately |

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
