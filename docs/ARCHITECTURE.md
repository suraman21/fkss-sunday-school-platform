# Architecture

**Status:** maintained architecture overview. Original component review: `46fbaa7` (2026-10-22); hosting-policy, router, mobile schema and recovery/update clarifications reviewed at `7e28157` (2026-10-28). Source descriptions are not live deployment certification.

Evidence labels: **VERIFIED** means supported by the cited source/date, not a runtime guarantee; **UNKNOWN / DEPENDENCY NOT VERIFIED** marks unobserved environment behavior. Historical “Verified” entries below retain their original source boundary. See [documentation standards](DOCUMENTATION_STANDARD.md).

## 1. System context

```
                         ┌──────────────────────────── shared hosting (LiteSpeed, PHP 8.5) ───────────────────────────┐
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

The server is a PHP monolith with shared services and database access; the mobile client has durable SQLite queues and Android scheduling. Do not read the hosting diagram as a verified current deployment. The repository includes CLI maintenance/reconciliation scripts that require operator scheduling. The owner's current workflow uses a development-server cron pull; actual hosting schedules and service activation remain unverified. See [OPERATIONS.md](OPERATIONS.md) and [the development-first release decision](adr/0021-development-acceptance-before-production.md).

## 2. PHP web application (`admin/`)

| Element | Location | Notes |
|---|---|---|
| Bootstrap | `config.php` | Loads secrets from `.fkss_env.php` outside the web root and **fails closed** if it is missing. Opens the database (mysqli) and the session, defines the CSRF helpers and security headers. Verified |
| Page access control | `admin/access_control.php` | Per-page role map plus an impersonation exception. See [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md). Verified |
| Department dashboards | `admin/dashboards/` (`hr-dept.php`, `info-dept.php`, `edu_dept.php`, `super-admin.php`, `school_admin.php`, …); the Mezmur dashboard is `frontend/pages/mezmur_dept.php` | Large single files: `hr-dept.php` is about 4,900 lines; `info-dept.php` and `edu_dept.php` are about 4,400–4,500 each. Verified |
| JSON endpoints | `admin/api_*.php` (46 files) | Session-authenticated. State-changing requests require a CSRF token. Usually dispatch on an `action` parameter. Verified |
| Domain services | `admin/backend/services/*.php` | For example `RefreshTokenService`, `SecurityRateLimiter`, `AdminSessionGuard`, `SecurityAuditService`, `BackupService`, `FailureAlertService`, `MezmurMediaService`, `MemberFileService`. Verified |
| Libraries | `vendor/` (Composer, committed); `admin/backend/pdf/tcpdf`; phpqrcode (`admin/id_cards/libs/`; the duplicate copy in `backend/id_cards/libs/` was removed 2026-10-23) | There is no Composer run on the server. Verified |

### Browser page addresses (development change, 2026-10-30)

The finite registry in [BrowserPageRoutes.php](../admin/backend/services/BrowserPageRoutes.php) maps browser pages to same-directory aliases, such as `/admin/login`, `/admin/member?id=123` and `/admin/dashboards/education`. Root/admin rewrite rules send only the supported route shapes through [browser_page.php](../browser_page.php); the dispatcher rejects unknown paths and restores the actual legacy PHP script identity and working directory **before** existing authentication, role and feature checks. A rewrite-server environment variable preserves subdirectory mount prefixes under Apache Alias. No arbitrary `.php` inference or file parameter is accepted.

The shared bootstrap temporarily redirects mapped legacy **document GET/HEAD navigation** only when rewrite support is signalled. Query strings are preserved; POST, AJAX, JSON and non-navigation fetch requests remain compatible with their original endpoints. API/mobile routing, exports, internal handlers and authorization policies are not replaced. Existing relative links remain valid; following an old PHP document link adds a redirect. Legacy POST responses can still show their original PHP address, intentionally avoiding unsafe submission redirects. The homepage and existing directory entry points remain available.

This is URL presentation, not a security boundary. Local Apache evidence does not verify the hosted LiteSpeed/Apache configuration or deployment; production promotion requires separate approval. See [routing acceptance checks](DEVELOPMENT_AND_TESTING.md#browser-url-routing-acceptance).

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

| Concern | Implementation (original baseline plus dated clarifications above) |
|---|---|
| Identity | Package `fkss_app`, source checkpoint 1.7.4+41 at `7209278` (not proof of publication); Android `applicationId com.arkeonethiopia.fkss`. The API base URL is in `lib/utils/config.dart` |
| Local storage | SQLite through `sqflite`, file `wbws_offline_v4.db`, schema version 37 (`localDatabaseSchemaVersion` in `lib/services/local_schema_v34.dart`). Stores private read caches **and unsent durable work**. No application-level SQLCipher encryption; app-private/OS storage protection depends on the device. Owner/scope recovery and explicit discard are documented in [the mobile storage guide](../Mobile/wbws_flutter_app/README.md) |
| Outboxes | `pending_attendance`, `pending_grades`, `pending_hr`, `pending_mezmur`, `pending_hymn_ops`, `comm_outbox`, `comm_drafts`. Bookkeeping in `sync_attempts`, `sync_state`, `sync_log` |
| Sync | `AttendanceSyncCoordinator` and `BackgroundSyncCoordinator`, plus an Android background producer (`AndroidBackgroundSyncScheduler`). Writes carry `Idempotency-Key` and client-attempt headers, so the server can replay or deduplicate them (`api/v1/core/middleware.php`) |
| Tokens | Kept in `flutter_secure_storage`. Access tokens last 15 minutes; refresh tokens rotate (see [API.md](API.md#2-authentication)) |
| App lock | `AppLockService`: separate 4–8 digit local PIN, with optional device biometric authentication through `local_auth`; not the online account password |
| Updates | The app polls `/api/v1/app/config` and downloads an APK from the server. There is no store listing. See [OPERATIONS.md](OPERATIONS.md#5-mobile-release) |
| Members | Members are never registered from the app; registration is web-only (HR) |

Current recovery concepts and limitations are in [the mobile README](../Mobile/wbws_flutter_app/README.md), [sync/recovery guide](user_manual/SYNC_AND_RECOVERY.md), [sync-monitoring contract](WEB_ADMIN_SYNC_MONITORING.md) and [build-24 rollout record](audits/AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md). Older untracked phase-note filenames are not maintained references. [App updates](../Mobile/wbws_flutter_app/docs/PROFILE_APP_UPDATES.md) distinguishes current transfer/verification/installer behavior from unimplemented durable download resume.

## 5. Data access and schema management

- There is a single MariaDB schema. Code uses mysqli prepared statements in most places.
- The schema evolves through numbered files in `sql/` that are applied by hand. **No migration ledger table exists.** See [DATABASE.md](DATABASE.md).
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
2. Large PHP dashboards and a multi-responsibility `local_db.dart`; historical line counts are not current size guarantees.
3. Runtime DDL and no migration ledger (section 5).
4. CI runs behavioral tests on PHP 8.4 and lint on PHP 8.5. The last documented production PHP observation is dated, not freshly verified; behavioral parity with that environment remains unproven.
5. Older identifiers (for example the `wbws_` prefix in table, folder and file names, including the local database file) differ from the product name FKSS.
6. Duplicated vendored libraries (phpqrcode). Resolved 2026-10-23: one copy remains, in `admin/id_cards/libs/`.

Severity and recommended actions: [OPERATIONS.md §7](OPERATIONS.md#7-known-limitations-and-open-questions).
