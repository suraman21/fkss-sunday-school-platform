# Database and migrations

**Status:** canonical. **Verified against:** `main` at `46fbaa7` (2026-10-22), from source files only. **No production database was queried for this document.** Statements about production are marked as reported or unverified. This document deliberately contains no ERD, row counts or schema dump, because none could be verified.

## 1. Engine and access

- **Engine:** MariaDB. CI uses the `mariadb:11.4` service image.
- **Production version:**
  - A production schema dump dated 2026-10-02 recorded **MariaDB 11.4**. This is cited in the headers of `sql/054_legacy_era_columns.sql` (line 33) and `sql/066_academic_year_charset_repair.sql` (line 5), and in [INCIDENT_2026-10-03_TRACKING_SCHEMA.md](incidents/INCIDENT_2026-10-03_TRACKING_SCHEMA.md).
  - The current server version was **not re-verified** for this document.
  - "MariaDB 10.6" in older headers (migrations `004`, `048`, `049` and `055`, and the `sql/preflight/` files) is a historical compatibility target, not the production version.
  - Keep new DDL compatible with both.
- **Character set:** new tables use `utf8mb4` / `utf8mb4_unicode_ci`. Legacy tables may differ. For example, `sql/066_academic_year_charset_repair.sql` exists because `academic_years` was `latin1` in production. Whether that repair has been applied is unverified.
- **Access:**
  - Mostly mysqli prepared statements, through the connection opened in `config.php`, with PDO in a few files.
  - There is no ORM and no query builder.
- **Credentials:** `DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASS` are read from `.fkss_env.php` (see [SECURITY.md](SECURITY.md#3-configuration-and-secrets)).

## 2. Sources of schema truth, and their limits

| Source | What it is | Reliability |
|---|---|---|
| `sql/NNN_*.sql` (76 files, `001`–`078`) | Hand-written, mostly additive and idempotent migrations, applied by hand in phpMyAdmin | Authoritative for **intended** changes. Applied state unknown (section 4) |
| `sql/legacy/database_schema.sql` | Legacy snapshot with **11** tables (`users`, `members`, `wbws_groups`, `wbws_group_leaders`, `cache_storage`, `departments`, `staff_positions`, `member_staff_positions`, `member_code_migrations`, `member_code_sequences`, `member_type_settings`) | **Stale.** Line 10 says it "documents all tables", which is incorrect. Its `users.role` enum lists 6 roles, while the application accepts 13. Not the current schema; used only as the first step of `tests/e2e/provision_env.sh`. Moved from the repository root on 2026-10-23 ([RFC 0001](rfc/0001-repository-structure.md)) |
| `sql/legacy/php-migrations/admin/*.php`, `sql/legacy/php-migrations/backend/*.php` | Early PHP migration scripts, including `auto_fix_database.php` in both folders. Moved from `admin/migrations/` and `backend/migrations/` on 2026-10-23 ([RFC 0001](rfc/0001-repository-structure.md) step 2b), content unchanged | Historical records. They no longer run: their settings-file links point at the old folders, so they stop before touching a database. The web server denies `sql/`, and each file also refuses web requests. Do not run them against production |
| `admin/get_schema.php` | Former schema-dump page | **Retired.** CLI-only stub; returns 404 over HTTP. Not a schema source |
| Runtime DDL | `MezmurSchemaReconciler::apply`, called from `admin/api_mezmur.php`, can create or alter Mezmur tables at request time | Production can drift from `sql/` |
| `tests/e2e/provision_env.sh` | Encodes the apply order that produces a working test database | The best available **executable** description of the schema; test-only |

Distinct table names created across `sql/`: **103**, counted from `CREATE TABLE` statements on 2026-10-22. Tables created before `sql/` existed, or by legacy scripts, are not included in that count.

## 3. Migration rules in force

- **Shared hosting:** use plain SQL that is safe for MariaDB. Migrations must be additive and idempotent where possible (`IF NOT EXISTS`, information_schema guards). There is no server-side tooling.
- **CI checks (`backend-checks.yml`):**
  - *No duplicate migration numbers*;
  - *Migrations are not silently destructive*: it flags lines that start with `TRUNCATE` or `DELETE FROM`.
- **Preflight queries:** `sql/preflight/` holds read-only checks that are run before some migrations: `auth_outbox_*`, `failure_intelligence_preflight`, `uniqueness_preflight`, `mobile_fleet_telemetry_*`, `guardian_profiling_pack`.
- **Pre-056 schema in CI:** `tests/security/test_schema_conformance.py` runs tracking endpoints against a deliberately pre-`056` schema (database `ssms_pre056_e2e`). Queries that name columns production may lack therefore fail in CI.
- **Fresh databases:** `sql/` is **not linearly applicable** to an empty database (header of `tests/e2e/provision_env.sh`).
  - `003` indexes columns that `012` adds.
  - `014`, `017`, `021` and `029` assume legacy tables from the PHP-migration era.
  - Use `provision_env.sh` for test databases.

## 4. Applied state in production: unverified

- **No migration ledger.** There is no `schema_migrations`-style table, so which files have been applied cannot be determined from the repository.
- **Reported (not verified):** migrations `070`–`078` were applied manually in October 2026.
- **Gaps in numbering:**
  - `047` (`047_account_self_service.sql`) was added in `3ba18f7` and removed by the revert `491b9ed` (2026-09-22). Two tests mention that it "landed later via server-side" (`tests/security/test_p1a_members_local_first.py`, `test_p1b_notification_local_first.py`). Whether it is applied in production is **unknown**.
  - `061` never existed in history.
- **Read-only verification needed:** see [OPERATIONS.md §7.2](OPERATIONS.md#72-open-questions) (Q-1, Q-2): export the production schema (structure only, no data) and diff it against a database built by `provision_env.sh`.

## 5. Migration inventory (source control only)

The summaries are based on each file's header comment. "Tables created" lists `CREATE TABLE` targets (truncated after six). **This is not a record of what production contains.**

| File | Added | Summary | Tables created |
|---|---|---|---|
| `001_production_patch.sql` | 2026-08-15 | Production patch: database migration | activity_logs |
| `002_cms_schema.sql` | 2026-08-15 | Website content management (CMS) tables | cms_gallery_categories, cms_gallery_photos, cms_programs, cms_registration_submissions, cms_schedule, cms_social_links … |
| `003_production_hardening.sql` | 2026-08-15 | Database hardening for go-live (5,000+ students) | academic_records_archive, activity_logs_archive, attendance_archive |
| `004_year_lifecycle.sql` | 2026-08-15 | Academic-year lifecycle states (upcoming, active, closed) |  |
| `005_roster_indexes.sql` | 2026-08-18 | Indexes for the Education roster and member search |  |
| `006_assignment_hardening.sql` | 2026-08-18 | Teacher, subject and class assignments | class_subjects, teacher_assignments |
| `007_timetable.sql` | 2026-08-18 | Bell schedule (periods) and weekly class grid | timetable_entries, timetable_periods |
| `008_security_rate_limits.sql` | 2026-08-24 | Shared, atomic authentication throttling for multi-instance deployments | security_rate_limits |
| `009_api_idempotency.sql` | 2026-08-24 | Atomic request reservations and bounded response replay for API writes | api_idempotency, api_idempotency_records |
| `010_refresh_token_rotation.sql` | 2026-08-24 | One-time refresh-token rotation, replay detection and family revocation | api_refresh_legacy_exchanges, api_refresh_sessions |
| `011_monitor_schema.sql` | 2026-08-24 | Error and uptime monitor schema | arkeon_error_log, arkeon_uptime_log |
| `012_runtime_schema_baseline.sql` | 2026-08-24 | Move shared schema repair out of request handling | academic_years, activity_logs, classes, notifications, system_branding |
| `013_application_schema_completion.sql` | 2026-08-24 | Application tables and compatibility columns | academic_records, academic_terms, ai_chat_history, ai_provider_configs, attendance, attendance_summary … |
| `014_member_directory_scaling.sql` | 2026-08-24 | Member directory and export indexes |  |
| `015_explicit_attendance_status.sql` | 2026-08-24 | Attendance must always be an explicit user or integration decision |  |
| `016_member_duplicate_lookup.sql` | 2026-08-24 | Bounded member duplicate lookup |  |
| `017_identity_codes.sql` | 2026-08-25 | Three-category member identity; configurable department and staff-position codes | departments, member_code_migrations, member_staff_positions, staff_positions |
| `018_code_sequences_and_year_uniqueness.sql` | 2026-08-26 | Identity-code sequences and unique academic-year names | member_code_sequences |
| `019_member_types_and_under6_removal.sql` | 2026-08-27 | Membership types; removal of the under-6 category | member_type_settings |
| `020_identity_v2.sql` | 2026-08-27 | Identity code format, version 2 |  |
| `021_mezmur_department.sql` | 2026-08-27 | Mezmur department: hymn library and staff-position entry | mezmur_hymns |
| `022_mezmur_attendance.sql` | 2026-08-27 | Mezmur attendance and analytics | mezmur_attendance, mezmur_attendance_audit, mezmur_sessions |
| `023_mezmur_date_attendance.sql` | 2026-08-27 | Date-based Mezmur attendance, grouped by section | mezmur_days |
| `024_mezmur_submissions.sql` | 2026-08-27 | Mezmur submission packets (same workflow as Education) | mezmur_submissions |
| `025_mezmur_hymn_offline.sql` | 2026-08-28 | Offline Mezmur hymns on mobile | mezmur_categories |
| `026_hr_attendance.sql` | 2026-08-28 | HR department attendance (section-based) | hr_attendance, hr_attendance_audit, hr_submissions, only |
| `027_attendance_rollup.sql` | 2026-08-28 | Attendance analytics read model | attendance_rollup |
| `028_analytics_scale.sql` | 2026-08-28 | Scale hardening for the analytics hub (100,000+ rows) |  |
| `029_subject_codes_and_limits.sql` | 2026-08-31 | Subject codes that survive Amharic names; safe field limits |  |
| `030_mezmur_taxonomy.sql` | 2026-08-31 | Mezmur hymn taxonomy (multiple categories, singers, type) | mezmur_hymn_categories, mezmur_hymn_zemarians, mezmur_zemarians |
| `031_mezmur_hymn_title_unique.sql` | 2026-09-01 | Case-insensitive unique hymn titles |  |
| `032_mezmur_hymn_words.sql` | 2026-09-01 | Word index for hymn search | mezmur_hymn_words |
| `033_mezmur_single_title.sql` | 2026-09-02 | Single Amharic hymn title |  |
| `034_mezmur_subcategories.sql` | 2026-09-02 | Two-level hymn taxonomy: main categories and sub-categories |  |
| `035_mezmur_category_gradient.sql` | 2026-09-02 | Per-category cover gradient |  |
| `036_mezmur_gradient_alpha.sql` | 2026-09-02 | Gradient opacity (alpha) for category covers |  |
| `037_zemarian_images.sql` | 2026-09-02 | Singer (zemarian) cover images |  |
| `038_mezmur_audio_media.sql` | 2026-09-03 | Mezmur audio media and synced lyrics | mezmur_play_stats, mezmur_user_favorites |
| `039_mezmur_word_index_fold.sql` | 2026-09-05 | Rebuild the Mezmur word index after homophone folding |  |
| `040_mezmur_hymn_art.sql` | 2026-09-09 | Per-hymn cover image |  |
| `041_section_age_source_of_truth.sql` | 2026-09-12 | Section and age group: single source of truth |  |
| `042_notification_center.sql` | 2026-09-12 | Communication Center: per-user notification read state | announcements, message_thread_participants, message_threads, messages, notification_reads |
| `043_message_read_receipts.sql` | 2026-09-12 | Read receipts for messaging |  |
| `044_message_edit_delete.sql` | 2026-09-12 | Message edit and delete |  |
| `045_comm_poll_indexes.sql` | 2026-09-12 | Poll and pagination indexes |  |
| `046_message_client_tag.sql` | 2026-09-18 | Exactly-once message sends from the offline outbox |  |
| `048_user_authorization_scope.sql` | 2026-09-23 | Monotonic authorization-scope versions for mobile live revalidation |  |
| `049_user_profile_image.sql` | 2026-09-25 | Private profile-image reference for authenticated user profiles |  |
| `050_assessment_types.sql` | 2026-09-26 | Assessment types managed by the Education Department | assessment_types |
| `051_app_telemetry.sql` | 2026-09-28 | First-party mobile app telemetry and fleet analytics | app_downloads, app_installations, app_telemetry_events |
| `052_roster_scale_indexes.sql` | 2026-10-02 | Scale indexes for roster duplicate checks and unassigned-member listing |  |
| `053_grade_submission_slot_uniqueness.sql` | 2026-10-02 | One Education submission packet per slot |  |
| `054_legacy_era_columns.sql` | 2026-10-02 | Columns the application depends on that no earlier migration creates |  |
| `055_mezmur_orphan_link_cleanup.sql` | 2026-10-02 | Mezmur link-table orphan clean-up | migration_055_mezmur_link_quarantine |
| `056_subject_duration_and_semester_weights.sql` | 2026-10-02 | Subject duration (semester only or full year) and per-year semester weights |  |
| `057_sync_change_feed.sql` | 2026-10-03 | Shared synchronization change feed | sync_changes, sync_feed_state |
| `058_sync_feed_scope_date.sql` | 2026-10-03 | Attendance date on change-feed entries |  |
| `059_api_sync_attempts.sql` | 2026-10-05 | Read-only, server-observed sync monitoring | api_sync_attempts |
| `060_telemetry_integrity_hardening.sql` | 2026-10-05 | Telemetry integrity hardening |  |
| `062_sync_attempt_context.sql` | 2026-10-06 | Sync attempt fleet context |  |
| `063_crash_signatures.sql` | 2026-10-06 | Crash signatures | app_crash_signatures |
| `064_failure_intelligence.sql` | 2026-10-06 | Failure Intelligence | failure_issues, failure_remediation_catalog, failure_reports |
| `065_failure_alerts.sql` | 2026-10-06 | Failure alerting state | failure_alerts |
| `066_academic_year_charset_repair.sql` | 2026-10-08 | Fix: academic_years is latin1 on the production database |  |
| `067_semester_reopen_flag.sql` | 2026-10-08 | Semester reopen-for-corrections flag |  |
| `068_member_permanent_eligibility_notified.sql` | 2026-10-16 | Permanent-eligibility notification state for temporary members |  |
| `069_guardian_linkage.sql` | 2026-10-17 | Family Link: guardians, member–guardian links, aliases and review queue | guardian_aliases, guardian_link_suggestions, guardians, member_guardians |
| `070_report_definitions.sql` | 2026-10-19 | Reporting Centre: saved views (private, department, shared) and pinned standing reports | report_definitions |
| `071_department_status_and_policy.sql` | 2026-10-19 | Attendance alerting: department status periods and policy | attendance_policies, member_department_periods |
| `072_attendance_warnings.sql` | 2026-10-19 | Attendance alerting: attendance warnings | attendance_warning_events, attendance_warnings |
| `073_attendance_submission_events.sql` | 2026-10-19 | Attendance alerting: submission event history | attendance_submission_events |
| `074_attendance_month_rollup.sql` | 2026-10-19 | Attendance monthly roll-up | attendance_month_rollup |
| `075_assessment_followups.sql` | 2026-10-19 | Assessment follow-ups on Education reactivation | assessment_followups, assessment_makeup_attempts |
| `076_attendance_sessions.sql` | 2026-10-19 | Lesson-level Education attendance | attendance_session_records, attendance_sessions |
| `077_timetable_builder.sql` | 2026-10-20 | Education timetable builder (per-class working days and bell schedule, weekly grid, make-up classes) | timetable_class_days, timetable_makeup_classes |
| `078_school_calendar.sql` | 2026-10-20 | School calendar: planned holidays and "Day Off" days | school_day_offs, school_holidays |

## 6. Recommended next steps (documented, not implemented)

1. Introduce a migration ledger table and a one-off back-fill based on the schema comparison in section 4.
2. Replace `sql/legacy/database_schema.sql` with a generated structure-only baseline from a verified database, or delete it after review.
3. Move `MezmurSchemaReconciler` DDL into numbered migrations.
4. Add a CI job that builds a database from a baseline plus `sql/` and compares it with the expected structure.
