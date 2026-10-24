# Architecture

**Status:** canonical. **Verified against:** `main` at `46fbaa7` (2026-10-22), by reading the source code. Runtime behaviour in production was **not** re-tested for this document.

Labels: **Verified** = read in the code at `46fbaa7`. **Unverified** = not confirmed; treat as an open question.

## 1. System context

```
                         ┌──────────────────────────── shared hosting (LiteSpeed, PHP 8.2) ───────────────────────────┐
 Staff browser ───────►  │ admin/*.php pages, department dashboards ──► admin/api_*.php        (session cookie + CSRF) │
                         │ frontend/pages/*.php ──────────────────────► backend/api/*.php ──► admin/api_*.php (shims)   │
 Android app (Flutter) ─►│ api/v1/index.php ──► api/v1/routes/*.php                             (bearer access token)   │
                         │                 \______________ admin/backend/services/* (shared PHP services) ______________/│
                         │ monitor/*  ·  app/ (legacy PWA)  ·  index.php (public site)                                   │
                         └──────────────┬──────────────────────────────┬─────────────────────────────┬────────────────┘
                                        ▼                              ▼                             ▼
                                     MariaDB                 S3-compatible object storage     Telegram Bot API
                                 (single schema)             (Cloudflare R2: Mezmur media)    (failure alerts)
```

Everything runs as one PHP code base in one hosting account. There are no background workers, queues or cron jobs that the application can rely on (see [OPERATIONS.md](OPERATIONS.md)).

## 2. PHP web application (`admin/`)

| Element | Location | Notes |
|---|---|---|
| Bootstrap | `config.php` | Loads secrets from `.fkss_env.php` outside the web root and **fails closed** if it is missing. Opens the database (mysqli) and the session, defines the CSRF helpers and security headers. Verified |
| Page access control | `admin/access_control.php` | Per-page role map plus an impersonation exception. See [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md). Verified |
| Department dashboards | `admin/dashboards/` (`hr-dept.php`, `info-dept.php`, `edu_dept.php`, `super-admin.php`, `school_admin.php`, …); the Mezmur dashboard is `frontend/pages/mezmur_dept.php` | Large single files: `hr-dept.php` is about 4,900 lines; `info-dept.php` and `edu_dept.php` are about 4,400–4,500 each. Verified |
| JSON endpoints | `admin/api_*.php` (46 files) | Session-authenticated. State-changing requests require a CSRF token. Usually dispatch on an `action` parameter. Verified |
| Domain services | `admin/backend/services/*.php` | For example `RefreshTokenService`, `SecurityRateLimiter`, `AdminSessionGuard`, `SecurityAuditService`, `BackupService`, `FailureAlertService`, `MezmurMediaService`, `MemberFileService`. Verified |
| Libraries | `vendor/` (Composer, committed); `admin/backend/pdf/tcpdf`; phpqrcode (`admin/id_cards/libs/`; the duplicate copy in `backend/id_cards/libs/` was removed 2026-10-23) | There is no Composer run on the server. Verified |

### Department model

The organisation is modelled as departments, each with its own role and dashboard:
- `hr_dept`: registration and promotion;
- `info_dept`: member information;
- `edu_dept`: education;
- `mezmur_dept`;
- `finance_dept`;
- `material_dept`.

The `teacher` and `attendance_taker` roles are class-scoped, while `school_admin` and `super_admin` are cross-cutting. The role-to-page mapping is in [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md).

## 3. The three API layers (unfinished consolidation)

| Layer | Consumers | Authentication | Shape | Status |
|---|---|---|---|---|
| `admin/api_*.php` (46) | Admin pages and department dashboards | PHP session cookie; CSRF token on writes | `?action=` dispatch, JSON | Active. Holds most web business logic. Verified |
| `backend/api/*.php` (21) | `frontend/js/*.js`, `frontend/layouts/base.php` | Inherited from the target file | 20 files are shims that `require` an `admin/api_*.php` file (or `groups_api.php`). `init.php` is the exception: it has its own logic and returns the dashboard bootstrap JSON (session user, school, theme, CSRF token, stats) | Transitional. Their header comments say both paths work "during migration". Verified |
| `api/v1` (17 mapped resources) | Flutter app | Bearer access token plus rotating refresh token | `/api/v1/{resource}/{id}/{sub}`, JSON envelope | Active. Used by the mobile app. Verified |

**FACT:** some business rules are implemented twice, once in `admin/api_*.php` and once in `api/v1/routes/*.php`. Examples: grades, attendance, Mezmur and notifications. **No document or code marker declares one layer canonical** for every domain. Before changing a rule, find every implementation, for example with `git grep` on the table or action name. Endpoint details are in [API.md](API.md).

## 4. Mobile application (`Mobile/wbws_flutter_app/`)

| Concern | Implementation (verified at `46fbaa7`) |
|---|---|
| Identity | Package `fkss_app`, version 1.7.3+40; Android `applicationId com.arkeonethiopia.fkss`. The API base URL is in `lib/utils/config.dart` |
| Local storage | SQLite through `sqflite`, file `wbws_offline_v4.db`, schema version 37 (`lib/services/local_schema_v34.dart`). It is **not encrypted at the application level**; the app relies on Android file-based encryption. Some 2026-10-05 sync documents mention a v36 → v37 upgrade path |
| Outboxes | `pending_attendance`, `pending_grades`, `pending_hr`, `pending_mezmur`, `pending_hymn_ops`, `comm_outbox`, `comm_drafts`. Bookkeeping in `sync_attempts`, `sync_state`, `sync_log` |
| Sync | `AttendanceSyncCoordinator` and `BackgroundSyncCoordinator`, plus an Android background producer (`AndroidBackgroundSyncScheduler`). Writes carry `Idempotency-Key` and client-attempt headers, so the server can replay or deduplicate them (`api/v1/core/middleware.php`) |
| Tokens | Kept in `flutter_secure_storage`. Access tokens last 15 minutes; refresh tokens rotate (see [API.md](API.md#2-authentication)) |
| App lock | `local_auth` (PIN/biometric) |
| Updates | The app polls `/api/v1/app/config` and downloads an APK from the server. There is no store listing. See [OPERATIONS.md](OPERATIONS.md#5-mobile-release) |
| Members | Members are never registered from the app; registration is web-only (HR) |

Design history for the offline and sync work is in `docs/MOBILE_OFFLINE_SYNC_*.md` (historical, 2026-10-04 to 2026-10-06).

## 5. Data access and schema management

- There is a single MariaDB schema. Code uses mysqli prepared statements in most places.
- The schema evolves through numbered files in `sql/` that the owner applies by hand. **No migration ledger table exists.** See [DATABASE.md](DATABASE.md).
- **Runtime schema changes exist.** Some code issues DDL at request time. For example, `admin/api_mezmur.php` calls `MezmurSchemaReconciler::apply`, and several services run `CREATE TABLE IF NOT EXISTS`. The actual production schema can therefore differ from what `sql/` implies.
- The committed `sql/legacy/database_schema.sql` (moved from the repository root on 2026-10-23) is a partial, legacy snapshot (11 tables). It is **not** the current schema.

## 6. Media and files

| Kind | Where | Notes |
|---|---|---|
| Mezmur audio and art | S3-compatible object storage on Cloudflare R2. Configured with the `MEZMUR_MEDIA_*` keys; endpoint built in `MezmurMediaService.php` | Uploads use presigned URLs (`audio-presign` → `audio-confirm` in `api/v1/routes/mezmur.php`) |
| Member documents and photos | Private storage path (`MEMBER_PRIVATE_STORAGE_PATH`), served by `MemberFileService` / `admin/member_file.php` after a role check | Unverified: the production storage location relative to the web root |
| APK files | Uploaded through the app-release manager (`admin/api_app_release.php`, super admin only) | — |
| Backups | `BackupService`, encrypted with `BACKUP_ENCRYPTION_KEY`, stored at `BACKUP_STORAGE_PATH` | See [OPERATIONS.md](OPERATIONS.md#4-backups) |

## 7. Notifications and integrations

| Integration | Purpose | Status |
|---|---|---|
| In-app notifications and messages | Announcements, threads, read state (`admin/api_notifications.php`, `admin/api_communication.php`, `api/v1/routes/notifications.php`) | Verified in code. No e-mail or SMS delivery was found |
| Telegram Bot API | Operational failure alerts (`FailureAlertService`, `monitor/error_monitor.php`, `monitor/uptime_cron.php`). Disabled unless `MONITOR_TELEGRAM_ENABLED` is defined as true | Verified in code. Production configuration unverified |
| AI providers (Gemini, Groq, OpenRouter, OpenAI, Anthropic, or an OpenAI-compatible URL) | Optional assistant (`admin/api_ai.php`, `admin/backend/ai_providers.php`). Provider keys are encrypted with `AI_ENC_KEY`; gated by `FEATURE_AI_CHATBOT` | Unverified: whether the feature is enabled in production and what data reaches the providers |
| Uptime monitor | `monitor/uptime_cron.php` needs a cPanel cron job | Unverified: whether a cron job is configured |
| Third-party front-end assets | Tailwind (Play CDN) on some admin pages, Google Fonts, cdnjs (with SRI) | Verified in code |

## 8. Known structural debt (documented, not fixed)

1. Three API layers with duplicated rules (section 3).
2. Very large PHP dashboard files and a 7,500-line `local_db.dart`.
3. Runtime DDL and no migration ledger (section 5).
4. CI tests PHP 8.4 while production runs PHP 8.2.
5. Legacy naming (WBWS/WBSS/SSMS/FKSS) across files, the database file name and the PWA.
6. Duplicated vendored libraries (phpqrcode). Resolved 2026-10-23: one copy remains, in `admin/id_cards/libs/`.

Severity and recommended actions: [OPERATIONS.md §7](OPERATIONS.md#7-known-limitations-and-open-questions).
