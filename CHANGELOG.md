# Changelog

A curated history of notable changes to FKSS, newest first.

- **Complete record:** the Git history of `main` (658 commits, 2026-08-15 to 2026-10-22). Use `git log --oneline` or `git show <hash>`. Commit hashes below can be looked up the same way.
- **How this file was made:** reconstructed on 2026-10-22 from the commit history, release commits and dated documents. From now on, every pull request that changes behaviour should add a line under **Unreleased** (see [CONTRIBUTING.md](CONTRIBUTING.md)).
- **Versioning:** the Android app uses `MAJOR.MINOR.PATCH+BUILD` (`pubspec.yaml`). The web application has no version numbers; it is identified by the deployed `main` commit.
- **Production deployments** are listed only where a deployment record exists. Earlier deployments were not recorded in the repository.

## Unreleased

- Fixed: 27 more lines in 10 files had the same mistake as the `q` warning: a request value was checked with a default but read without one, so PHP wrote "Undefined array key" warnings whenever the value was left out (seen on production for the notifications `type` and four telemetry filters). The values are unchanged: the read now uses the same default (`(string)($_GET['x'] ?? '')`), proven identical for every input type, with no warnings. Files: `admin/api_app_release.php`, `api_communication.php`, `api_education.php`, `api_failure_intelligence.php`, `api_guardians.php`, `api_identity.php`, `api_notifications.php`, `api_subjects.php`, `api_telemetry.php`, and `api/v1/routes/notifications.php`. These warnings also showed up as "Warning at ..." issues in Failure Intelligence, so that list gets less noise. Test: `tests/security/test_request_reads_are_guarded.py` scans every PHP file for this pattern.
- Fixed: on the Failure Intelligence screen (super admin), pressing Acknowledge, Resolve or Reopen erased the issue's saved remediation notes (cause, steps, link), and the screen silently fell back to the generic catalog advice. The status buttons send only the status, but `admin/api_failure_intelligence.php` passed all three note fields to the service, which treats a present field as "set this value". The endpoint now passes a note field only when the request sent it. Saving and clearing notes work as before; a request that sends nothing to change is now refused ("Nothing to update") instead of clearing the notes. No database change. Test: `tests/security/test_failure_issue_remediation_preserved.py`.
- Fixed: opening a class's student list in Academic Tracking without a search word wrote a PHP warning ("Undefined array key q", `admin/api_education.php` line 2591) to the error log on every call. One-line fix; the answer is unchanged.
- Tests: fixed the 2 long-standing CI failures and a daily time trap, test files only. `test_page_size_is_bounded` now expects the intended roster cap of 200 (paging change `71caa8c`); the report-card check no longer mistakes the comment `// 2026-10-22` for a division by two, but still catches a real one. The Flutter timetable test no longer fails from 16:00 to 16:40 UTC (its make-up class overlapped "now") or at 23:59 (its all-day lesson ended one minute early). No app or website code changed. The Security suite stays red until the 79 skipped tests can run in CI.
- Removed the old static home page `index.html` (previous school name, unchanged since the first import). The server never showed it at `/` (that is `index.php`), the October access logs show no requests to it, and nothing refers to it; the matching `Allow: /index.html` line in `robots.txt` is gone too. The stale page could have replaced the real home page if the host's default file order changed.
- Repository structure, Option 1 Step 2d ([RFC 0001](docs/rfc/0001-repository-structure.md#8-option-1-tidy-without-moving-web-files)): short guides added to `scripts/`, `sql/`, `tests/` and `Mobile/` (what is inside, how to use it safely); the README folder map now lists every top-level folder and marks the website folders; new CONTRIBUTING §9 "Naming and where files go". Documentation only; no file moved or renamed.
- Repository structure, Option 1 Step 2c ([RFC 0001](docs/rfc/0001-repository-structure.md#8-option-1-tidy-without-moving-web-files)): 33 historical records moved from the top of `docs/` into topic folders (`mobile_offline_sync/`, `academic_tracking/`, `communication/`, `responsive_ui/`, `attendance/`, `incidents/`, `audits/`), with names and contents unchanged; the 16 main guides stay where they were. Links in the index and guides updated. New CI test `tests/security/test_docs_links.py` checks every relative Markdown link. No code changed.
- Repository structure, Option 1 Step 2b ([RFC 0001](docs/rfc/0001-repository-structure.md#8-option-1-tidy-without-moving-web-files)): the 19 old PHP migration scripts in `admin/migrations/` and `backend/migrations/` moved unchanged to `sql/legacy/php-migrations/`, next to the legacy schema. They can no longer run from there (by design). Tests and test-setup notes updated. No website file changed; the old web paths stay blocked.
- Repository structure, Option 1 Step 2a ([RFC 0001](docs/rfc/0001-repository-structure.md#8-option-1-tidy-without-moving-web-files)): the three command-line scripts in `tools/` (`attendance_rollout_2026.php`, `find_stored_mojibake.php`, `fix_mojibake.py`) moved to `scripts/`, so all operator scripts live in one folder. Tests and usage notes updated. No website file changed; both folders stay blocked from the web.
- Repository structure, Option 1 Step 1 ([RFC 0001](docs/rfc/0001-repository-structure.md)): safety checks before any folder tidy-up. Two CI tests (`tests/security/test_structure_include_paths.py`, `test_structure_boundaries.py`) catch broken `require`/`include` paths, website code that depends on non-website folders, a weakened root `.htaccess` block, unguarded command-line scripts, and private file types in folders the root block does not reach. A read-only live checker (`scripts/check_live_urls.py` and `scripts/live_urls.json`) compares 43 key URLs on dev or production. No application file changed.
- PHP 8.5 compatibility: replaced constants deprecated in PHP 8.4/8.5 (`PDO::MYSQL_ATTR_*` in `config.php` and the member export/report services, `E_STRICT` in the error monitor), so production no longer logs a deprecation notice on every request; added a PHP 8.5 lint job and guard tests. Operations guide: server folders that Git does not create (`admin/id_cards/assets/qr/`) and handling of deleted files during SFTP deploys.
- Fixed: the Branding card editor showed an empty front footer and back disclaimer (editor preview only; real cards were correct).
- Repository structure, Phase 1 ([RFC 0001](docs/rfc/0001-repository-structure.md)): the superseded 2026-09-27 audit report moved to `docs/audits/`; `database_schema.sql` moved to `sql/legacy/`; removed the unused copy `public_website_frontend/`, the duplicate root `leak_detector.php` (the working copy stays in `admin/`), and the duplicate QR library `backend/id_cards/libs/` (also closes a publicly runnable demo script). No URL used by the site, the API or the app changed.
- Documentation: engineering handover, reconciled system knowledge report, reconstructed PRD, technical design, decision records, glossary, changelog and contributing guide (documentation pull requests; not merged at the time of writing).

## Production deployment record

| Date | Commit | Notes |
|---|---|---|
| 2026-10-22 | `46fbaa7` | Score limit, "Did not take", paging; 89 of 89 files checksum-verified |
| Not recorded (on or after 2026-10-21) | `a64bc93` | Report Cards dashboard styles (owner-reported deployment) |
| Not recorded (on or after 2026-10-21) | `b26467f` | Member import v2 (owner-reported deployment) |
| 2026-10-07 | `dfb01d4` | Preparation for the production domain cutover (the commit, not a deployment record) |

Manual SFTP deployment; see [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Android app releases (build history)

| Version | Date | Highlights | Commit |
|---|---|---|---|
| 1.7.3+40 | 2026-10-22 | Live mark limit, "Did not take", view-only lock, paging. **Not yet released**; the field runs 1.6.10+36 | `d854591` |
| 1.7.2+39 | 2026-10-20 | Teacher timetable (Daily / Weekly / Monthly) | `7162d42` |
| 1.7.1+38 | 2026-10-19 | Attendance reopen and return flows | `780bfd6` |
| 1.7.0+37 | 2026-10-19 | Attendance warnings screens and roster markers | `9719fb3` |
| 1.6.10+36 | 2026-10-08 | Report card: foldable A5 booklet, totals only. **Current field version** | `d85cdbf` |
| 1.6.2–1.6.9 | 2026-10-08 | Sync-instance epoch; academic-year fixes; grade-entry semester fence; semester close and reopen; 100% weight budget; detailed and redesigned report cards | `a3b1bde` … `1af56df` |
| 1.6.1+27 | 2026-10-07 | Production cutover build | `dfb01d4` |
| 1.6.0+26 | 2026-10-06 | Attendance rework (default-absent, honest drafts) | `d2c3558` |
| 1.5.1+25 | 2026-09-28 | App lock rate limiting | `8e0cde8` |
| 1.5.0+24 | 2026-09-24 | Authorization-scope reconciliation, owner-bound outboxes, sync recovery centre | `08b43b7` |
| 1.4.0+23 | 2026-09-18 | Offline-first messaging with exactly-once sends | `7f1e28b` |
| 1.3.0+22 | 2026-09-15 | Communication UX audit, actionable alerts | `5df6477` |
| 1.2.0+21 | 2026-09-13 | Notifications and messaging parity | `d03b712` |
| 1.1.17+20 | 2026-09-09 | Hymn cover art | `1f6d615` |
| 1.1.16+19 | 2026-09-06 | Low-end device compatibility, optional-update banner | `abb0598` |
| 1.1.9 – 1.1.16 | 2026-08-21 to 2026-08-25 | Outbox sync fixes, view-only submitted work, production hardening | `b94a6cf` … `bf0bd0d` |
| 1.1.0 – 1.1.8 | 2026-08-19 | In-app updates without a store; cache-first screens for 2G/3G; draft/submit; one score per student | `09f46ec` … `85890dd` |
| 1.0.0+1 | 2026-08-15 | First build | `2e2e7c5` |

---

## 2026-10-16 to 2026-10-22: governance, attendance alerting, timetable, readiness

### Added
- **Score limits and paging** (2026-10-22): marks above the maximum are refused (HTTP 422); "Did not take" is shown and excluded from totals; Previous/Next and "Load more" on every capped list (`524ab7f`, `d854591`, `205d398`, `292581a`, `71caa8c`).
- **Danger confirmation** DC-1 to DC-4 (2026-10-21): shared three-step type-to-confirm dialog across all departments (`20fc748`, `1070b57`, `c8f02fd`, `3ed188d`).
- **Member import v2** (2026-10-21): all-or-nothing Excel sync, Ethiopian-calendar birth dates, age sections, strict classes (`b26467f`).
- **Class-day-only attendance, yearly school calendar and "Day Off" days** (2026-10-20) (`a5e8fee`, `b2901fe`, `6f901c9`).
- **Education timetable builder** (web) and the **teacher mobile timetable** (2026-10-20) (`5751f86`, `7162d42`, `b64df5e`).
- **Attendance alerting** phases 0–6 (2026-10-19): department status, attendance policies, warning engine, reopen/reject with reason, Ethiopian-calendar monthly roll-up, assessment follow-ups, lesson-level Education attendance (`b260f0f` … `3ad51cd`).
- **Reporting Centre V2-0 to V2-3** (2026-10-18/19): governed registry, cross-domain fields, export log, saved views (`5d10294`, `3d44f1c`, `6629350`, `072005e`).
- **Family Link** (2026-10-17): guardian and sibling linkage with typo-tolerant matching (`695830c`, `b46513b`).
- **Education structure management** (2026-10-17): subject delete, restore and purge; class toggle with audit trail (`bd3d09e`).
- **New Members tracking and promotion workflow** for HR (2026-10-16) (`8092cae`, `207e610`).

### Changed
- HR attendance removed completely; shared Education and Mezmur attendance analytics hub for Information (2026-10-18, `d098afc`).
- Ethiopian calendar in every export and display (2026-10-19, `6fa65df`).
- Rebrand: new app icon, splash and mobile login logo (2026-10-17, `a506fb7`, `31fc12c`, `67f4af2`).
- APK upload made resumable and chunked (2026-10-17, `e6e88fb`, `3ec85fb`).
- Branching policy: `main` and `dev`, with a branch guard in CI (2026-10-20, `4c52494`, `d86b56f`).

### Security
- Production-readiness audit fixes F1–F13 and passes 2–3 (A1–A15): teacher class scope, future-date refusal, finance ledger audit trail, output escaping (2026-10-19/20, `d843a12`, `41cd716`, `201557f`, `5800095`, `46fbaa7`).
- Security phase 1: directory browsing disabled, API security headers, CORS wildcard removed, Subresource Integrity on CDN assets (2026-10-17, `6a58f3c`, `18c5801`, `0f9248e`, `248835d`).
- Registration hardening: role gating on member edit and registration, input length caps (2026-10-16/17, `01b2cf3`, `9fc8ffb`, `d28d7ef`).

### Removed
- Member registration from the mobile app (2026-10-17, `f5098cb`).

## 2026-10-01 to 2026-10-08: academic model, sync platform, production cutover

### Added
- **Semester model:** subject duration (semester-only or full-year), semester close and reopen, a 100% weight budget per semester, the annual score as a plain average, and a report-card redesign to an A5 booklet (2026-10-02 to 10-08; releases 1.6.2–1.6.10).
- **Academic tracking** phases 0–5: student, teacher, subject and class scopes (2026-10-03, `f592ff8` … `4967e1f`).
- **Sync platform:** shared change feed, operation and attempt lineage, request correlation, idempotency completed inside the business transaction, background sync and Android background producer (2026-10-03 to 10-05, `5ac8a1e`, `1604010`, `86fdf15`, `e91c345`, `58647e3`).
- **Failure intelligence** phases 0–5: crash signatures, issue engine, symptom-based alerting (2026-10-06, `675eee9` … `1bc3098`).
- **Server sync monitoring and telemetry integrity** (2026-10-05, `abac3c3`, `3d81975`).
- CI: PHP 8.4 with a real database and failure on skipped tests; Flutter test baseline; repository integrity gate (2026-10-02 to 10-06, `cb2e4ef`, `326a5a1`, `c997cd9`).

### Changed
- **Attendance rework:** default-absent sheets, merged drafts, HR attendance retired (writes return 410) (2026-10-06, `e2184d1`, `eec830c`, `9ba54ee`, `4b0124f`).
- Production cutover preparation (2026-10-07, `dfb01d4`).

### Removed
- "Visual Intelligence" dashboards and engine (2026-10-02, `c332227` … `2e926cb`).

### Fixed
- Tracking schema incident: queries tolerate a pre-`056` database (2026-10-03, `36432b0`, `0189f30`; [incident record](docs/incidents/INCIDENT_2026-10-03_TRACKING_SCHEMA.md)).
- Academic-year save on a `latin1` table (2026-10-08, `2d8af60`, `sql/066_academic_year_charset_repair.sql`).

## 2026-09-12 to 2026-09-28: communication, offline-first, profiles, analytics

### Added
- **Communication Center:** notifications, announcements and messaging with read receipts, edit and delete; ETag/304 and cursor pagination; mobile parity (2026-09-12/13, `6edeff3`, `46febd6` … `39e6ce5`, `44f7262` … `d03b712`).
- **Offline-first** messaging, members, notifications, reviews and education screens on mobile (2026-09-15 to 09-23, `15385e4` … `95f6fdb`).
- **Versioned authorization scope**, owner-bound outboxes and a sync recovery centre (2026-09-23/24, `eb39756`, `3ed96b7`, `d830354`).
- **Profile management** on web and mobile (2026-09-25/26, `5f2e6ae` … `6337013`).
- **Assessments** centralised in Education, with dynamic assessment types (2026-09-26, `ebcbf65`, `062ba2e`).
- **Education analytics hub** (2026-09-27, `a74e8f7`).
- **App release manager**, chunked APK upload and first-party app telemetry (2026-09-28, `d706d26`, `fc0e376`, `a069610`).
- Section and age group as a single source of truth (2026-09-12, `9395f15`).

### Reverted
- Account self-service component and related PRs, reverted the same day (2026-09-22, `491b9ed` and others). Migration `047` was removed with it.

## 2026-09-01 to 2026-09-11: Mezmur library and media, responsive web

### Added
- Mezmur hymn taxonomy, typo-tolerant Amharic search with a server word index, lyrics editor, subcategories and cover art (2026-09-01/02, P22–P37).
- Mezmur audio on object storage with presigned uploads, web and mobile players, offline downloads, synced (karaoke) lyrics (2026-09-03 to 09-06, `b3f0e51` … `7702d21`).
- Low-end device compatibility package (2026-09-06, `8f77285`).
- CI/CD architecture and Git guide for the team (2026-09-05, `3ec43e8`).

### Changed
- Responsive mobile web app shell across departments; pinch zoom allowed (WCAG) (2026-09-09, `4544372` … `8a27fb7`).

### Reverted
- Karaoke v2 redesign, rolled back to the previous player (2026-09-06, `071f06c`).

## 2026-08-15 to 2026-08-31: foundation

### Added
- Initial system: HR Department (registration moved from Information), two-tier registration (temporary and permanent), Excel import and export with EC/GC conversion, attendance with barcode scanning (2026-08-15/16).
- Teacher ↔ subject ↔ class assignments, report cards with totals and rank, class report Excel, ID card designer, public gallery (2026-08-18/19).
- Android app with in-app updates, cache-first screens, outbox sync, draft and submit (2026-08-18 to 08-21).
- Phase-4 production hardening: security services, schema ownership, refresh-token rotation, idempotency (2026-08-24, `6388586`).
- Ministry identity coding system (A/B/C categories, staff codes) (2026-08-25/27, `67f09bc`, `0bbc25e`).
- Mezmur Department: hymn library, session attendance, department-owned takers (2026-08-27/28).
- PDF report engine (TCPDF with an Ethiopic font), Information analytics hub, QR-scan attendance for all departments, app lock (2026-08-28).

### Security
- Phase-1 fix batch: impersonation-aware session guard, member page enumeration and PII hardening, identity-code integrity (2026-08-26, `0870a32`, `7a2a4cd`, `4b9748e`).
- Teacher scope on web grade APIs, phone numbers removed for restricted roles, hex-escaped JSON embeds (2026-08-31, `165c996`, `ba1d7ca`, `9f97c24`).
