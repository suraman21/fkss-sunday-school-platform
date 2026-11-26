# Final System Knowledge Report — FKSS Sunday School Platform

**Protocol:** Master Codebase Understanding / System Archaeology Prompt (§32 sections A–V)
**Repository:** `suraman21/fkss-sunday-school-platform`
**Branch / commit analysed:** `arena/47fd4776-fkss-sunday-school-platform` @ `0c653efd8177ae4ce9d969f91f146496fef34012` (`fix(branding): use validateCsrf() and real system_branding columns…`, 2026-11-22)
**Method:** read-only source inspection of the checkout (no runtime, no database, no network, no production access). No file was modified, refactored, or fixed.
**Repository size (VERIFIED, `git ls-files`):** 3 730 tracked files; 2 035 PHP (491 first-party after excluding `vendor/`, nested `vendor/`, `id_cards/libs/` → 222 033 LOC), 272 Dart (88 102 LOC), 263 Python (60 053 LOC), 108 SQL (9 407 LOC in `sql/`), 145 Markdown, 69 JS.

> **STATUS: ANALYSIS INCOMPLETE.**
> The global architecture, every entry-point class, the trust/authorization model, the schema inventory and about a dozen end-to-end workflows are verified from source. The remaining unverified areas are listed explicitly in **§T** and in the **analysis queue** below. Per §36 nothing is silently skipped; anything not traced is marked **UNKNOWN — NOT VERIFIED FROM SOURCE** or **INFERENCE — REQUIRES VERIFICATION**.

---

## 0. Analysis queue (module-by-module completion ledger)

| # | Module | Scope | Status in this report |
|---|---|---|---|
| 1 | Repository structure, tech stack, CI | all of `git ls-files`, `.github/workflows/`, `composer.json` | **COMPLETE** |
| 2 | Bootstrap & configuration | `config.php` (973 L), `school_config.php`, `branding_defaults.php`, `env.example.php`, `.htaccess` ×4 | **COMPLETE** |
| 3 | Web session auth + page authorization | `admin/backend/login.php`, `backend/core/browser.php`, `admin/access_control.php`, `AdminSessionGuard` | **COMPLETE** |
| 4 | Dashboard routing & shells | `admin/dashboard.php`, `admin/dashboards/*` (16), `frontend/pages/*`, `frontend/layouts/base.php` | **COMPLETE (routing/shell level only)** — dashboard internals ~23 100 LOC only spot-read |
| 5 | Mobile REST layer | `api/v1/index.php`, `core/*` (7), `routes/*` (20) | **COMPLETE for routing/auth/envelope**, **PARTIAL for per-action bodies** |
| 6 | Admin AJAX layer | 52 `admin/api_*.php` (21 086 LOC) | **PARTIAL** — 14 files read in detail, the rest classified by dispatch/guards only |
| 7 | Service layer | 111 `admin/backend/services/*.php` (51 393 LOC) | **PARTIAL** — 30 services inspected at symbol/contract level |
| 8 | ACM (activation/device/office) | `acm/src` (32 PHP files, 17 contracts), `admin/api_acm.php`, 8 `admin/acm_*.php` | **PARTIAL** — policy, ticket state machine, provisioner, autoload verified; crypto/tests not audited |
| 9 | Database | `sql/` (98 numbered files), `sql/legacy/`, runtime DDL | **COMPLETE at inventory level** (136 distinct tables recomputed), **NOT VERIFIED as deployed** |
| 10 | Mobile Flutter app | `lib/` (177 Dart files, 18 screen folders, 84 services) | **PARTIAL** — bootstrap, session coordinator, local DB, API client, nav/config verified; per-screen flows not traced |
| 11 | Cron / CLI / operator tooling | `admin/backend/cron_*`, `admin/tools/*`, `monitor/uptime_cron.php`, `scripts/*` | **COMPLETE at entry/guard level** |
| 12 | Integrations | Telegram (13 services + webhook), R2/SigV4, AI providers, APK distribution | **COMPLETE for transport & contract**, PARTIAL for Telegram conversation state machine |
| 13 | Testing & quality gates | `tests/` (342 files), `Mobile/.../test` (94) | **COMPLETE at inventory level**, individual assertions not audited |
| 14 | Documentation consistency | `docs/` (101 files incl. 22 accepted ADRs 0001–0022) vs source | **COMPLETE for the checked claims; 13 mismatches recorded (§U)** |

Confidence vocabulary used below: **VERIFIED** (directly in source) · **HIGH CONFIDENCE** · **INFERENCE** · **UNKNOWN**.

---

## A. Executive System Summary

**What this system actually is (VERIFIED).** A single-tenant, server-rendered PHP monolith deployed as the document root of a shared LiteSpeed/Apache host (`public_html` = repository root; no build step, no framework, no ORM, no Composer step at deploy time — `vendor/` is committed). It is the management system of one Sunday school (ፈለገ ቅዱሳን ሰንበት ትምህርት ቤት, "Felege Kidusan"), plus a public-facing church website, plus a REST API for an Android app.

It serves **four distinct client populations through four different contracts**:

1. **Public web visitors** — `index.php` (marketing site fed by `cms_*` tables), `member.php` (`/verify-member` QR verification landing page), `public_gallery.php` (public photo API that also streams image bytes), `register_submit.php` (public enquiry *leads*, not member creation).
2. **Staff in a browser** — `/admin/*.php` (~99 pages + 52 AJAX endpoints), `/frontend/pages/*` (newer shell pages), `/backend/*` (compatibility shims). One PHP session, one CSRF token, server-side role map.
3. **Android staff app** — `/api/v1/{resource}/{id}/{action}` with a **custom HMAC bearer token** (not JWT) and rotating refresh sessions, plus a large offline-first SQLite layer on the device.
4. **Operators / schedulers** — CLI-only scripts (backup, failure reconciliation, retention, guardian backfill, uptime probes) and one **public webhook** (`api/telegram-webhook.php`) that accepts Telegram updates for a review-only registration intake.

**Business domains actually implemented (VERIFIED by services + routes):** member register/registry (with member codes, tiers, archiving), family-link/guardians, classes & enrolments, teacher assignments, terms/academic years, assessments & grades with a teacher→Education review workflow, day-level and lesson-level attendance (Education) with warnings/policies, Mezmur (choir) hymn library + attendance + audio media on object storage, communications (announcements, threads, notifications), reporting/export (PDF/Excel), finance and materials ledgers, ID cards, photo capture/review pipeline, ACM account activation & device registry, mobile telemetry & crash signatures, error/failure monitoring, AI assistant with pluggable providers, Telegram registration intake, and app release management (chunked APK upload + per-ABI artifacts).

**What it is not.** There is no message broker, no queue worker, no Redis, no scheduler inside the app, no ORM, no dependency-injection container, no PSR-4 autoloader for first-party classes (classes are `require_once`-ed by hand; the only registered namespace is `SSMS\Acm\`); `vendor/predis` is present but **unused by first-party code** (VERIFIED: no `Predis\` reference outside `vendor/`).

---

## B. Technology Stack (verified)

| Layer | Verified fact | Evidence |
|---|---|---|
| Language | PHP, procedural + namespaced service classes (`App\Services`), `declare(strict_types=1)` only in some files | `admin/backend/services/*.php`, `api/telegram-webhook.php` |
| Runtime | CI behavioural tests on **PHP 8.4**, lint job 8.4 + 8.5; `composer.json` platform pinned to **8.2.0**; root `.htaccess` installs `application/x-httpd-ea-php82`; workflow comment states "Production runs PHP 8.4.25" *and* "Production was observed running PHP 8.5 from 2026-10-23"; README says 8.5 | `.github/workflows/backend-checks.yml` L41, L80; `.htaccess` L65–67; `README.md` L27 |
| Database | MariaDB (CI service `mariadb:11.4`), single schema, utf8mb4, no ORM | `backend-checks.yml` `services.mariadb`, `config.php` L262–295 |
| DB access | **mysqli** for most pages/services (~150 files) + **PDO** (login, `MemberDirectoryService`, `AdminSessionGuard`, `SecurityRateLimiter`, `acm/`) — two connections opened on *every* request | `config.php` `$conn`, `$pdo` |
| Web server | Apache/LiteSpeed with `.htaccess` per directory (root, `admin/`, `api/`, `api/v1/`, `monitor/`, `admin/uploads/*`) | the five `.htaccess` files |
| Front end | Server-rendered PHP + vanilla ES5-style JS (`admin/js` 40 files incl. 2 vendored, `frontend/js` 8 files), no bundler; CSS design system in `themes/` | `frontend/js/core.js`, `themes/design-system.css` |
| Third-party CSS/JS at runtime | Tailwind **Play CDN** (`cdn.tailwindcss.com`) in 16 first-party PHP files, cdnjs (Font Awesome, xlsx, jspdf — with SRI), Google Fonts in 22 files | `index.php`, `frontend/pages/finance_dept.php` L33–36 |
| Composer libs (committed) | `phpoffice/phpspreadsheet ^5.9`, `openspout/openspout ^4`, `teamtnt/tntsearch ^5.3`, `edgaras/strsim ^1.2`, `ext-openssl`; dev `phpunit ^11`; PSR-4 only `SSMS\Acm\ => acm/src/` | `composer.json`, `vendor/composer/autoload_psr4.php` |
| Bundled (not Composer) | TCPDF and phpqrcode vendored inside `admin/backend/pdf/tcpdf`, `admin/id_cards/libs` | `admin/.htaccess` denies their PHP |
| Mobile | Flutter, `version: 1.8.5+48`; `sqflite` (`wbws_offline_v4.db`, `localDatabaseSchemaVersion = 43`), `flutter_secure_storage`, `local_auth`, `shared_preferences`, `just_audio` + `just_audio_background`, `camera`, `google_mlkit_face_detection`/`pose_detection`, `http` | `Mobile/wbws_flutter_app/pubspec.yaml`, `lib/utils/config.dart` L16–18, `lib/services/local_schema_v34.dart` L5 |
| Tests | 224 Python files in `tests/security/`, 26 in `tests/e2e/`, 9 `tests/smoke/`, PHP harnesses, 94 Flutter tests | directory listing |
| CI | 4 workflows: `backend-checks` (php-lint 8.4, php-lint-85, security-tests with MariaDB, flutter-tests, acm-contracts, migration-hygiene, android-build), `production-integrity`, `branch-guard`, `apk-upload-e2e` | `.github/workflows/` |
| Timezone / locale | `Africa/Addis_Ababa`; **Ethiopian calendar is a first-class concern** (`ethiopian_date.php`, `calendar_system.php`, `dob_ec_*` columns, ADR-0008) | `config.php` L213 |
| Secrets | `.fkss_env.php` / `.wbws_env.php` **outside** the web root; fail-closed 500 if absent | `config.php` L40–95 |

---

## C. Repository Architecture

```
/ (web root = public_html)
├── config.php                 unified bootstrap (env → session → headers → mysqli+PDO → monitor → academic year → branding → access_control)
├── school_config.php          ACTIVE_THEME, school identity, SITE_DOMAIN/SITE_URL, 13 FEATURE_* constants
├── branding_defaults.php      default palette/asset paths
├── env.example.php            template for the out-of-tree secrets file
├── index.php                  public website (reads cms_* tables, degrades gracefully)
├── member.php                 QR/member verification landing page (enumeration-hardened)
├── public_gallery.php         public gallery JSON API + image byte streaming (WBWS_API_REQUEST)
├── register_submit.php        public enquiry form → cms_registration_submissions
├── browser_page.php           finite slug dispatcher → BrowserPageRoutes
├── admin/                     staff web app (809 files)
│   ├── index.php login screen · dashboard.php role router · access_control.php guard
│   ├── api_*.php              52 AJAX/JSON endpoints (21 086 LOC)
│   ├── dashboards/            16 role dashboards (23 108 LOC; hr-dept 4 974, info-dept 4 504, edu_dept 4 426)
│   ├── backend/               login, cron*, workflow.php, academic_year.php, calendar, ethiopian_date,
│   │   ├── services/          111 service classes (51 393 LOC) + services/Acm/ (4)
│   │   └── pdf/tcpdf          vendored PDF engine
│   ├── components/ js/ css/ id_cards/ tools/ uploads/ var/search/ acm_*.php (7)
├── backend/                   compatibility layer: api/*.php shims (21) → admin/, auth/, core/browser.php
├── api/
│   ├── index.php              404 JSON (no listing)
│   ├── telegram-webhook.php   public webhook, secret-header authenticated
│   └── v1/                    mobile REST: index.php router, core/ (7), routes/ (20), tests/
├── frontend/                  newer shells: pages/ (6), layouts/base.php, js/ (8)
├── app/                       retired PWA tombstone (index.php/html, kill-switch sw.js)
├── themes/                    fkss (active), wbss (fallback), design-system.css, components.css
├── monitor/                   error_monitor.php (auto-included by config), index.php (step-up gated), uptime_cron.php
├── acm/                       SSMS\Acm\ package: contracts/, Activation/, Device/, Accounts/, Office/, Policy/, tests
├── sql/                       98 numbered migrations 001–097 (+ gaps/duplicates), preflight/, legacy/
├── tests/                     342 files: security/ (224 py), e2e/ (Playwright + PHP walkthroughs), smoke/, audit/
├── docs/                      101 files: ADRs 0001–0022, ARCHITECTURE, API, DATABASE, ROLES_AND_PERMISSIONS, SECURITY, OPERATIONS, reports/
├── scripts/                   operator CLI (live URL checks, migration numbering, mojibake, deploy)
├── Mobile/wbws_flutter_app/   Flutter app (487 files)
└── vendor/                    committed Composer libs (1 669 files)
```

**Structural observations (VERIFIED).**
- Four *parallel* HTTP surfaces for the same business rules (`admin/api_*.php`, `backend/api/*.php` shims, `frontend/` shells calling both, `api/v1/`). `docs/ARCHITECTURE.md` and `AGENTS.md` both state the consolidation is unfinished and that no layer is canonical.
- `backend/api/*.php` are one-line `require_once` shims (`backend/api/members.php` → `admin/api_list_members.php`) — the *only* exception is `backend/api/init.php`, a real dashboard-bootstrap endpoint.
- `frontend/pages/` holds only two live pages (`finance_dept.php`, `mezmur_dept.php`) plus a router, a login page and two **302 stubs** (`edu_teachers.php`, `edu_assignments.php` → `admin/dashboards/edu_dept.php?section=teachers`).
- `app/` is a tombstone: `app/index.php` tells users the phone website is closed; `app/sw.js` is a service-worker kill switch.

---

## D. Application Architecture

### D.1 Request lifecycle — browser (VERIFIED)

```
Apache/.htaccess (deny rules, slug rewrite) 
  → admin/<page>.php  or  browser_page.php → BrowserPageRoutes → real script identity
  → require config.php
      ├ env load (fail-closed) ── JWT_SECRET fallback ── session (hardened cookie params)
      ├ inactivity check (SESSION_TIMEOUT 1800s on LAST_ACTIVITY)
      ├ error_reporting E_ALL / display_errors 0 / error_log = <root>/error.log
      ├ security headers + CSP
      ├ mysqli + PDO open (failures logged, not fatal)
      ├ AdminSessionGuard::revalidate() every 300s, absolute 8h  → 401/503 JSON or redirect
      ├ monitor/error_monitor.php (if FEATURE_MONITOR)
      ├ admin/backend/academic_year.php (ay_* resolver)
      ├ BrandingAssetService (ssms_school_logo_url/seal_url)
      └ admin/access_control.php  (CLI skip · WBWS_API_REQUEST skip · only /admin/ + /backend/)
            → login? → PUBLIC_PAGES? → ROLE_MAP[basename]? → FeatureGate
  → page HTML (+ window.APP JSON bridge on frontend shells)
  → fetch() → admin/api_<domain>.php  (session + CSRF + role checks + services + mysqli/PDO)
```

### D.2 Request lifecycle — mobile (VERIFIED)

```
https://<host>/api/v1/<resource>/<id>/<action>
  → api/v1/.htaccess rewrite → index.php?_route=...
  → ping short-circuit (no DB) 
  → core/response.php, database.php (defines WBWS_API_REQUEST, loads config.php), auth.php, acl.php, middleware.php
  → set_exception_handler + register_shutdown_function → stable JSON 500
  → handleCors() (origin allowlist SITE_URL / http://domain / https://www.domain; no wildcard; OPTIONS→204)
  → $routeMap[resource] (18) → FeatureGate::forApiResource (attendance|grades|mezmur) → routes/<file>.php
  → apiRequireAuth() → verifyTokenState() (HMAC + exp) → apiRevalidateAuthorizationScope (role, authorization_version, username, full_name)
  → route-specific role helpers (acl.php) → services (shared with web) → ok()/err()/paginated()
```

### D.3 Shared-core shape (VERIFIED)

- **One** bootstrap (`config.php`) is included by *every* PHP surface, including the mobile API (via `api/v1/core/database.php`) and the public pages. There is no separate "app kernel".
- **Two** DB handles coexist for the whole request. Services pick one: `MemberDirectoryService`, `AdminSessionGuard`, `SecurityRateLimiter`, `acm/*`, `AccountProvisioner` use PDO; attendance/grades/Mezmur/ledgers use mysqli.
- Business logic sits in `admin/backend/services/*` and is called by *all three* HTTP layers — that is the only real de-duplication boundary. Rules that were duplicated (e.g. review transitions) were later collapsed into shared policy classes (`ReviewTransitionPolicy`, `AttendanceRecordService`, `SubmissionService`) with explicit comments documenting the earlier drift.
- **Feature suppression** is centralised in `FeatureGate` (13 flags) and enforced in three places: page guard (`access_control.php` → `forAdminRequest`), API router (`forApiResource`), dashboard router (`forRoleDashboard`); mobile only *hides* tiles and is explicitly not authoritative.

### D.4 Layering artefacts worth knowing (VERIFIED, all real in source)

| Artefact | Effect |
|---|---|
| `browser_page.php` + `BrowserPageRoutes::dispatchIdentity` | Rewrites `$_SERVER` to the **real** legacy script before bootstrap, so role maps/feature gates see the true file name; unknown slugs → 404, never "append `.php`". |
| `WBWS_API_REQUEST` constant | Exempts a request from the browser session bootstrap, the admin guard and gzip. Set by `api/v1/core/database.php`, CLI scripts, and `public_gallery.php`. |
| `ay_resolve()/ay_active_year()/ay_require_writable()` | Per-user "view a past academic year (read-only)" context; writes are refused with `year_readonly`. |
| `window.APP` | The single PHP→JS bridge on `frontend/layouts/base.php` (csrf, role, user, school, theme, api base path). |
| `backend/api/init.php` | Dashboard bootstrap JSON (user, school, theme, CSRF, optional stats) — used by the older dashboards. |

---

## E. Module-by-Module

Each module: responsibility → key files/symbols → verified behaviour → gaps.

### E.1 Configuration & secrets (`config.php`, `school_config.php`, `env.example.php`, `.htaccess` ×5)
- Env search order: `dirname(__DIR__)/`, two levels up, then `__DIR__` for `.fkss_env.php` then `.wbws_env.php` (L40). Missing → HTTP 500 HTML setup message and `error_log('CRITICAL: secrets env file not found…')` — **fail-closed** (VERIFIED L67–95).
- `JWT_SECRET` fallback: if the env file loaded but the constant is absent, a **random per-request** secret is generated (L134–137) → all tokens invalidate on next request. Deliberate degradation, logged as CRITICAL.
- Required constants: `DB_HOST/NAME/USER/PASS`, `JWT_SECRET`, `BACKUP_KEY`, `HEALTH_KEY`, `MONITOR_SECRET_KEY`, `TELEMETRY_HASH_SECRET`, `MEZMUR_MEDIA_*` (`env.example.php`). Optional `.telegram_env.php` for `TELEGRAM_BOT_TOKEN` / `TELEGRAM_WEBHOOK_SECRET`.
- Branding: `ACTIVE_THEME='fkss'` (fallback `wbss` when the theme CSS is missing), school names in Amharic/English, parish identity, `SITE_DOMAIN`, `SITE_URL`, `ADMIN_URL`, 13 `FEATURE_*` constants — **all `true` except `FEATURE_ATTENDANCE_V2` and `FEATURE_TELEGRAM_REGISTRATION` which default `false`** (`school_config.php` L202–219).
- Error/logging: `error_reporting(E_ALL)`, `display_errors=0`, `error_log` → `ROOT_PATH . '/error.log'` (L219–226).
- Response compression: `ob_gzhandler` for non-API HTML (L900–920).

### E.2 Session, login, guard (web)
- `backend/core/browser.php`: DB-independent session helpers. `ssms_start_browser_session()` sets `httponly`, `use_only_cookies`, `use_strict_mode`, `use_trans_sid=0`, `secure` when HTTPS, `lifetime=0`, `SameSite=Lax`; PHP ≥ 8.4 skips the deprecated `sid_length`/`sid_bits_per_character` inis (VERIFIED).
- `admin/backend/login.php`: POST only; CSRF via `validateCsrf()`; rejects array inputs before casting; length caps (254/4096); **dual rate limit** `admin-login-ip` 20/300 s and `admin-login-account` 5/300 s with `Retry-After`; PDO lookup by username **or** email; `password_verify`; `is_active` gate; `session_regenerate_id(true)`; `$_SESSION = []` then set `admin_id/username/role/full_name/LAST_ACTIVITY/AUTH_STARTED_AT/AUTH_REVALIDATED_AT/AUTH_PASSWORD_VERSION/PROFILE_ACCOUNT_CONTEXT`; `activity_logs` insert whose failure cannot block login; JSON for fetch clients, 302 otherwise.
- `AdminSessionGuard` (`admin/backend/services/AdminSessionGuard.php`): `REVALIDATE_INTERVAL_SECONDS = 300`, `ABSOLUTE_SESSION_SECONDS = 28800`; DB reload of role/full_name/password-hash-version; impersonation branch re-anchors `original_admin_role` to the base account and invalidates on `impersonation_tampered` / `impersonation_base_revoked`.
- `admin/logout.php`: destroys session without DB contact.
- `admin/app_session.php`: **410 retired** phone↔web bridge.
- `admin/api_mobile.php`: **410 retired** legacy token API (7 statements; does *not* load `config.php`, so the `WBWS_API_REQUEST` comment in `access_control.php` L22/L54 is stale — see §U).

### E.3 Page authorization (`admin/access_control.php`, 360 L)
Order of checks (VERIFIED): CLI skip → `WBWS_API_REQUEST` skip → only `/admin/` and `/backend/` paths guarded → deployment-only denial (`migrations/`, `get_schema.php`, `qr_diagnostic.php`, `leak_detector.php` → 404) → `PUBLIC_PAGES` (index, login, logout, app_session, theme, manifest) → login required → `ROLE_MAP[basename]` membership → `FeatureGate::forAdminRequest()`.
- `ROLE_MAP` has **116 entries** covering **16 roles** (recomputed); every key maps to an existing tracked file. All 99 `admin/*.php` page files were cross-checked against the map or against in-file role logic: `ROLE_MAP` covers **32 of 47** non-`api_` pages and **44 of 52** `api_*` files. The remaining 15 non-`api_` files were each read: 5 public (`index.php`, `dashboard.php` self-guards by role, `logout.php`, `app_session.php` 410, `theme.php`), 2 never served directly (`config.php`, `access_control.php`), 2 denied by guard + `.htaccess` (`get_schema.php`, `leak_detector.php`), and 6 with their own role logic (`account.php`, `school_calendar.php` via `SchoolCalendarService::READ_ROLES/WRITE_ROLES`, `telegram_registration.php` SA/HR, `telegram_attachment.php` HR only, `acm_staff_enroll.php` via `AcmPolicy::consoleRoles()`, `profile_image.php` login-only self-service). **Reachability conclusion: no `admin/` page was found reachable by an arbitrary authenticated role without a further check** — a per-file claim, not a centrally enforced invariant (§S item 5, §T.5).
- **8 `api_*` files are intentionally not in `ROLE_MAP`**: `api_ai`, `api_guardians`, `api_mobile`, `api_notifications`, `api_school_calendar`, `api_settings`, `api_telegram_registration`, `api_year_context`. Each was checked for in-file role logic: `api_guardians` (write: SA/AD/HR, read: +INFO), `api_school_calendar` (`SchoolCalendarService::READ_ROLES/WRITE_ROLES`), `api_notifications` (role list + `NotificationCenterService::canAnnounce/canMessage`), `api_ai` (`super_admin` for config actions, role-scoped insights), `api_settings` (`$settingsPrivilegedActions` limited to SA/AD/INFO/HR), `api_telegram_registration` (SA/HR + action allowlists), `api_year_context` (self-service, writes blocked while viewing a past year). `api_mobile` is retired. **Net: none of these relies on "logged-in only"; each was read, but there is no central test that would catch a future regression.**
- `_ac_deny()` returns 401 `session_expired` JSON for AJAX and a redirect otherwise.

### E.4 Dashboards & shells
- `admin/dashboard.php` = role switch: direct `require` for super-admin, school_admin, hr-dept, info-dept, edu_dept, material_department, teacher, attendance_taker, dept_taker, content_editor, photo_dept (+legacy alias `photo_importer`), `accounts_office` → `acm_overview.php`, unknown → "dashboard not ready" page; **`finance_dept` and `mezmur_dept` 302 to `/frontend/pages/…`**; `FeatureGate::forRoleDashboard` blocks 403 when the module is off; impersonation banner is injected via output buffer + shutdown hook so it cannot overlay content.
- `frontend/layouts/base.php` = the only shell: `requireAuth()`, optional `$requiredRoles` (used only by `finance_dept.php` and `mezmur_dept.php`), optional `$requiredFeature`, theme resolution with `wbss` fallback, CSRF, `window.APP`, Ethiopian date bridge.
- **`frontend/pages/dashboard.php` is a dead map (VERIFIED):** its `$dashboardMap` names nine files (`super_admin.php`, `info_dept.php`, …) none of which exist in `frontend/pages/`; `file_exists()` therefore always fails and it redirects to `/admin/dashboard.php`. This is an intentional-looking migration stub but it currently can never serve a page. **INFERENCE:** it is leftover scaffolding.

### E.5 Mobile REST (`api/v1/`)
- Router (`index.php`, 170 L): route from `_route` → `path` → `PATH_INFO`; `ping` answers without DB; unknown resource → 404 listing available resources; feature-gated resources → 403; missing route file → 501; unmatched method/action → 404.
- Envelope (`core/response.php`): `ok()` → `{"status":"success","data":…}`; `err()` → `{"status":"error","message":…, …extra}`; `apiSendJson` adds `X-Request-Id` (and echoes `X-Client-Attempt-Id`); `paginated()`; `getBody($maxBytes)` with 413 guard applied to JSON **and** form fallback.
- Auth (`core/auth.php`): `createToken` builds `base64url(json).hash_hmac('sha256', …, API_TOKEN_SECRET)`; `API_TOKEN_EXPIRY 900`, `API_REFRESH_EXPIRY 90 days`, `API_LEGACY_TOKEN_EXPIRY 30 days`; `verifyTokenState` compares with `hash_equals`; `apiRevalidateAuthorizationScope` re-reads `users` and fails 503 `AUTH_REVALIDATION_UNAVAILABLE` / 401 `ACCOUNT_REMOVED` / 401 `ACCOUNT_DISABLED` / 401 `AUTH_SCOPE_REFRESH_REQUIRED` / 401 `AUTH_SCOPE_CHANGED` / 409 `PROFILE_CLAIMS_CHANGED`.
- ACL (`core/acl.php`, 284 L): role sets + class-scope helpers (`apiUserAssignedToClass`, `apiRequireClassAccess`, `apiTeacherCanSeeMember`), parent-scope helpers (`apiParentCanSeeMember`, `apiParentChildren`), and row projections (`apiMemberSafeRow`, `apiMemberStaffRow`, `apiRosterStudentRow`, `apiPhotoUrl`).
- Middleware: origin-allowlisted CORS (no wildcard, no blanket ACAO), `Access-Control-Allow-Headers` enumerating the app's custom headers, `X-API-Version: 1.0`, CSP `frame-ancestors 'none'`, plus idempotency helpers (`apiIdempotencyKey/Begin`) and rate-limit helper.
- 18 resources: `auth, acm, members, attendance, classes, dashboard, teachers, enrollment, subjects, users, grades, app, telemetry, mezmur, hr, notifications, sync, timetable`. `attendance_warnings.php` and `assessment_followups.php` exist as files but are included by `attendance.php` / `grades.php` (not routed directly).

### E.6 Admin AJAX layer (52 endpoints)
Dispatch style is per-file `switch ($action)` over `$_REQUEST`/`$_POST` with `requirePostActions()` + `requireCsrfForPost()` helpers from `config.php` (L571–611). Representative verified contracts:
- `api_attendance.php` — 7 actions (`get_class_attendance`, `save_attendance`, `scan_member`, `get_attendance_summary`, `get_class_history`, `export_history`, `get_daily_stats`); closures enforce teacher class scope (`teacher_assignments`, `is_active=1 OR status='active'`), refuse future dates (422), and require a **class day** via `SchoolCalendarService::classDayStatus` (45-day backdate window, day-off, holiday, configured working days).
- `api_education.php` (2 800 L) — the widest surface: action allowlists, `requirePostActions`, per-action feature gates, three authorization tiers (year lifecycle = SA/AD only; term lifecycle = SA/AD/EDU; class/enrolment management = SA/AD/EDU; teachers get read + `record_grade`/`record_attendance`), and explicit `hr_dept` restriction to `get_classes`.
- `api_finance.php` / `api_material.php` — ledger CRUD behind `LedgerValidation` (text/integer/optionalId/money/choice), table-existence probes → 503 with a migration hint, referential validation of category/member, `activity_logs` audit that never blocks the ledger.
- `api_mezmur.php` (1 193 L) — 30+ actions incl. `schema`/`migrate` (drift report + guarded DDL, SA/AD only), taxonomy, hymn CRUD/status, `audio_presign/confirm/remove`, `lyrics_synced_save`, days/sheets.
- `api_app_release.php` — `get_release`, `save_config`, `upload_preflight`, `upload_chunk`, `upload_status`, `assemble_chunks`, `cancel_upload`, `upload_apk`, `delete_apk` (all SA via `ROLE_MAP`; typed "DELETE APK"/"FORCE UPDATE" phrases).
- `api_telegram_registration.php` / `admin/telegram_registration.php` — SA (setup) / HR (review) split, 12+ actions, schema probes on `information_schema`, `telegram_audit_log` writes.

### E.7 Service layer (`admin/backend/services/`, 111 classes)
Grouped by concern (all VERIFIED to exist; contract level verified for the ones named):
- **Identity & members:** `MemberRegistrationPolicy`, `MemberDirectoryService`, `MemberDuplicateService`, `MemberCanonicalIdentityService`, `AmharicIdentityNormalizer`, `IdentityCodeService`, `MemberCodeFormat`, `MemberTypeService`, `MemberCategory`, `MemberAge`, `MemberSearchService` (TNTSearch, file index under `admin/var/search`, `$directoryCutover` kill switch), `EthiopicSearchTokenizer`, `MemberImportService`, `MemberExportService`, `MemberFileService`, `MemberReportService`/`MemberReportRenderer`, `GuardianService`/`GuardianMatchService` (family link, merge, verification, siblings), `IdentityMigrationService`.
- **Academic:** `EnrollmentService`, `AssignmentService`, `TimetableService`, `SubjectDurationPolicy`, `AssessmentTypeService`, `AssessmentFollowupService`, `AcademicYearActivation`, `SchoolCalendarService`, `EducationAnalyticsService`, `AcademicIntelligenceService`, `AcademicTrackingService`, `ReportCardService`, `EducationLabels`.
- **Attendance & submissions:** `AttendanceRecordService` (normalise / complete-sheet contract, `MAX_RECORDS 2000`, statuses `present|absent|late|excused`), `AttendanceSummaryService`, `AttendanceMonthRollupService`, `AttendanceWarningService`, `AttendancePolicyService`, `DepartmentStatusService`, `LessonAttendanceService`, `AttendancePreparationService`/`Workflow`/`Grant`/`ReadBoundary`, `AttendanceOperationManifest`, `SubmissionService` (2 264 L; packet lifecycle + score-limit rule), `ReviewTransitionPolicy` (single review state machine shared by Education/HR/Mezmur), `AttendanceSubmissionEventService`, `HrAttendanceService`/`HrSubmissionService` (retired-domain support), `MezmurAttendanceService`/`MezmurSubmissionService`.
- **Mezmur media:** `MezmurHymnService`, `MezmurMediaService` (hand-rolled SigV4 presign, `audio_status` machine `none→pending→ready|rejected`), `MezmurArtService`, `MezmurSchemaReconciler`.
- **Comms:** `NotificationCenterService` (feed, announcements, threads, messages, versioned summaries for ETag/conditional GET, `canAnnounce/canMessage/mayMessageRole`).
- **Platform:** `FeatureGate`, `AdminSessionGuard`, `RefreshTokenService`, `ProfileService`, `ProfileImageService`, `AccountCredentialService`, `PasswordPolicy`, `ApiIdempotencyService`, `ApiSyncAttemptMonitorService`, `ApiSyncAttemptAdminService`, `SecurityRateLimiter`, `SecurityAuditService`, `BackupService` (+`EncryptedBackupWriter` in the same file), `BrandingAssetService`, `BrowserPageRoutes`, `AppReleaseManager`, `AppReleaseUploadStore`, `AppTelemetryService`, `SyncChangeFeedService`, `SyncInstanceService`, `MonitorAccessService`, `FailureAlertService`, `FailureIssueService`, `GalleryService`, `PhotoUploadService`, `PhotoDevicePairing`, `PhotoImageProcessor`, `ExcelExportService`, `ExcelColumnMap`, `PdfReportService`, `InfoAnalyticsService`, `LedgerValidation`, `TestMemberSeed`, and 13 `Telegram*` services.
- **ACM adapters:** `admin/backend/services/Acm/{AcmOfficeService, AcmPersonService, …}` bridge the `SSMS\Acm\` package into the web layer.

### E.8 ACM package (`acm/`, 41 files)
`SSMS\Acm\` with Contracts (17 value types/interfaces), `Activation/` (`ActivationService`, `CodeAlphabet`, `IdempotencyStore`, `ActivationException`), `Device/` (`DeviceCrypto`, `DeviceService`, `DeviceRefreshProofs`), `Accounts/` (`AcmTicketService` — ticket state machine; `AccountProvisioner` — creates `users` with role `member`/`parent` only), `Office/` (`AcmOfficeService`), `OfficeNetwork/`, `Identity/` (`AcmPersonService`), `Policy/` (`AcmPolicy`, `PolicyAuthorizer`), `Enrollment/` (`StaffEnrollmentRequestService`), plus 7 PHPUnit test classes.
**Deliberate deviation from Composer norms (VERIFIED):** `acm/autoload.php` requires every file explicitly with `__DIR__`-relative literals because the repo forbids SPL autoloader registration outside `vendor/` (a security test enforces the closure). Callers are the 8 `admin/acm_*.php` pages, `admin/api_acm.php`, `api/v1/routes/acm.php`, and the two `Acm*Service` adapters.

### E.9 Public web surface
- `index.php`: loads `school_config.php`, then `admin/config.php` **guarded by `file_exists` + `$conn instanceof mysqli`**, and wraps every CMS read in a `try/catch` helper so a missing table cannot break the homepage. Tailwind Play CDN.
- `member.php`: rate-limited per IP (`member-verify-ip`, 30/600 s) with a **neutral** "temporarily unavailable" page for throttled requests (identical for existing/non-existing codes), `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, and deliberate PII reduction (city/sub-city only; no woreda/house number/street).
- `public_gallery.php`: defines `WBWS_API_REQUEST`, exposes `boot/albums/items` JSON plus `action=img|file` byte streaming; GET only; 405 otherwise; 503 when the DB is down; calls `GalleryService::ensureSchema` (currently a **no-op**) and `rescueStrayFiles`.
- `register_submit.php`: honeypot (`website` field) returning success without storing; `LedgerValidation` input typing; `validatePhone`; atomic `SecurityRateLimiter` (5/3600 s per IP); prepared INSERT into `cms_registration_submissions`; explicit comment that these are **leads, not member accounts**.

### E.10 Monitoring, failure intelligence, telemetry
- `monitor/error_monitor.php` is auto-included from `config.php` when `FEATURE_MONITOR`; class `ArkeonErrorMonitor` captures errors/exceptions, logs privacy-minimised context to `arkeon_error_log` and the local log file, Telegram alerts capped per hour/request, `MONITOR_AUTO_FIX_ENABLED=false` hard-coded policy.
- `monitor/index.php`: requires a DB-revalidated super-admin session **plus** a throttled password step-up (`MonitorAccessService`), POST+CSRF for state changes.
- `admin/backend/failure_alert_check.php`, `failure_issue_reconcile.php` (CLI-only, `WBWS_API_REQUEST`, `mysqli_report(MYSQLI_REPORT_OFF)`), `FailureAlertService` (Telegram `sendMessage` via cURL, silently returning when unconfigured).
- Mobile telemetry: `POST /api/v1/telemetry[/heartbeat|/event]` public but dual rate-limited per IP *and* per installation, bounded 8 KiB body, typed events; retention by CLI (`mobile_telemetry_retention.php`).

### E.11 Files, storage, exports
- Upload roots: `admin/uploads/{members,profiles,branding}` each with its own `.htaccess` deny; `admin/id_cards/assets/qr/` tracked via a `.htaccess` so `git clone` creates it (documented production incident 2026-10-23).
- `MemberFileService`, `PhotoUploadService` (SHA-256 receipt/idempotency, conflict detection), `GalleryService` (rescue + stream), `BrandingAssetService`.
- Exports: `ExcelExportService` (PhpSpreadsheet/OpenSpout), `PdfReportService` (TCPDF), plus on-page xlsx/jspdf via cdnjs in `finance_dept.php`.
- `BackupService::create()`: `REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`, batched INSERT dump → gzip → **libsodium `secretstream_xchacha20poly1305`**, filename `backup_YYYY-MM-DD_HH-MM-SS_<hex8>.sql.gz.ssb`, `KEEP_COUNT 7`, `flock` guard, key derived via `sodium_crypto_generichash` from `BACKUP_KEY`; explicit failure if libsodium is missing.

### E.12 Mobile app (`Mobile/wbws_flutter_app`)
- `main.dart` bootstrap order is deliberate and commented: `JustAudioBackground.init` (tolerant) → orientation/UI → `LocalDb().database` (open/migrate **before** credentials are interpreted) → `SessionCoordinator().bootstrap()` → `AppLockService().lockAtColdStartIfConfigured()` → on failure a recovery UI (`OfflineDataProtectionFailureApp`, `migrationRecoveryRequired`) instead of the app.
- `SessionCoordinator` (`services/session_service.dart`) is the **single owner** of session state: it wires `ApiService.onAuthExpired`, `onAuthorizationScopeChanged`, generation providers into `SyncService`, `CommOutboxService`, `NotificationService`, `CatalogService`, `WarmStore`, `HymnStore`. A session exists only after credentials + durable owner metadata + authorization scope reconcile and an `active` marker is persisted.
- `ApiService` carries structured outcomes (`ApiFailureKind`, `AuthRefreshOutcome`, `requestGeneration`, `sessionSuperseded`, `etag`, `serverRequestId`, `idempotencyReplayed`), timeouts 45/45/60 s for slow Ethiopian mobile data (`utils/config.dart`).
- Offline: SQLite v43 with `_privateReadCacheTables` registry, per-account private work vs shared hymn caches, `AttendanceOperationManifest` (canonical operation identity), `attendance/grades_preparation_*` (grant-bound preparation datasets), outboxes with claim/idempotency, `interrupted_database_migration` recovery.
- Role navigation is data-driven (`utils/config.dart` `_baseTabsForRole`), with `UserRoles` for 12 roles; Finance/Materials are **"use the website" notices**, not CRUD clients (`AGENTS.md`).

### E.13 SQL migrations (`sql/`)
98 numbered files `001`–`097` with gaps (`047` reverted, `061` never existed) and duplicate prefixes `081`, `082`, `091` (one ACM + one Telegram each, tolerated by CI). Re-computed from source: **138 `CREATE TABLE` statements, 136 distinct table names, in 56 files** (comment-stripped). Two tables are created twice (`activity_logs`, `telegram_candidate_decisions`). `sql/preflight/uniqueness_preflight.sql` verifies the submission-slot uniques. `scripts/check_migration_numbering.py` + `migration-hygiene` CI job enforce the numbering/ledger discipline. **The directory is not linearly applicable to an empty database** (`003` indexes columns added by `012`; `014/017/021/029` assume legacy tables); the executable baseline is `tests/e2e/provision_env.sh`.

---

## F. File-Level Map (important files)

| File | Responsibility (verified) | LOC |
|---|---|---|
| `config.php` | Single bootstrap: env→session→headers→DB×2→guard→monitor→ay_*→branding→helpers (`sanitize/e/esc/validate*/require*/isLoggedIn/hasRole/jsonResponse/reportInternalError/respondApi*`)→access_control | 973 |
| `school_config.php` | Branding, domain, 13 `FEATURE_*` flags, CORS helper list | 356 |
| `env.example.php` | Secrets template (DB, JWT, BACKUP, HEALTH, MONITOR, TELEMETRY, MEZMUR_MEDIA) | 145 |
| `backend/core/browser.php` | `ssms_app_url`, `ssms_request_is_https`, `ssms_start_browser_session`, `ssms_destroy_browser_session` | 77 |
| `admin/access_control.php` | Central page authorization (ROLE_MAP 116 entries + FeatureGate) | 360 |
| `admin/backend/login.php` | Web login (CSRF, dual rate limit, PDO, session init) | 120 |
| `admin/backend/services/AdminSessionGuard.php` | 5-min DB revalidation, 8-h absolute, impersonation integrity | 119 |
| `admin/api_education.php` | Education mega-endpoint (classes/enrolment/years/terms/grades/attendance) | 2 800 |
| `admin/api_mezmur.php` | Mezmur endpoint incl. schema drift/apply | 1 193 |
| `admin/api_attendance.php` | Day-level attendance + class-day rules | 604 |
| `admin/api_finance.php` / `api_material.php` | Ledgers | 353 / 277 |
| `admin/api_app_release.php` | APK chunked upload/release policy | 382 |
| `admin/backend/services/SubmissionService.php` | Packet lifecycle for attendance + marklists | 2 264 |
| `admin/backend/services/ReviewTransitionPolicy.php` | The ONE review state machine (Edu/HR/Mezmur) | 167 |
| `admin/backend/services/AttendanceRecordService.php` | Sheet normalisation/invariants | 311 |
| `admin/backend/services/MemberSearchService.php` | TNTSearch index + MySQL fallback + cutover flag | 635 |
| `admin/backend/services/MezmurMediaService.php` | SigV4 presign/confirm for R2 | 903 |
| `admin/backend/services/NotificationCenterService.php` | Feed/announcements/threads/messages/versions | 1 300+ |
| `admin/backend/services/BackupService.php` | Consistent snapshot dump + libsodium encryption + retention | 609 |
| `admin/backend/services/FeatureGate.php` | 13 flags, per-script/per-resource/per-role mapping, mobile tile filter | 187 |
| `admin/backend/services/BrowserPageRoutes.php` | 41-entry slug registry, strict path matcher, identity rewrite | 130 |
| `api/v1/index.php` | Mobile router (18 resources, global handlers) | 170 |
| `api/v1/core/auth.php` | Token issue/verify/revalidate | 400 |
| `api/v1/core/acl.php` | Role/class/parent scoping + row projections | 284 |
| `api/v1/core/response.php` | Envelope, request id, pagination, body bounds | 169 |
| `api/v1/core/middleware.php` | CORS/headers/methods, idempotency helpers | 519 |
| `api/v1/routes/attendance.php` | Sheet read/save/submit, write-context, preparation, capabilities, operation-status | 832 |
| `api/v1/routes/grades.php` | Assessment/save/submit/review/results/followups | 1 486 |
| `api/v1/routes/hr.php` | Retired domain → 410 `HR_ATTENDANCE_RETIRED` | 46 |
| `api/telegram-webhook.php` | Public webhook: secret header, JSON-only, dedup, private-chat-only | 128 |
| `frontend/layouts/base.php` | Only shell: auth + optional role/feature + theme + `window.APP` | 225 |
| `monitor/error_monitor.php` | Error capture + Telegram + DB log | 32 866 B |
| `monitor/index.php` | Step-up-gated monitor dashboard | 29 806 B |
| `acm/autoload.php` | Explicit manifest require (no SPL autoload) | 65 |
| `admin/backend/academic_year.php` | `ay_resolve/ay_active_year/ay_require_writable`, activation/close/reopen | 463 |
| `admin/backend/danger_confirm.php` | Typed-phrase registry (17 HIGH-risk actions) | 95 |

---

## G. Function / Class / Component Map (selected, with traced behaviour)

### G.1 `config.php` helpers (VERIFIED by reading each)
- `feature_enabled(string)` → `FeatureGate::isEnabled()`; ambiguous strings fail closed (only literal `true` counts).
- `generateCsrfToken()` → 32-byte hex in session; `validateCsrf($t)` → `hash_equals` against the session token, false on empty/non-string; `requireCsrf()`/`requireCsrfForPost()` exit with 403 JSON.
- `requirePostActions($action, $writeActions)` → 405 for a GET on a write action (removes CSRF-by-GET surface).
- `isRateLimited/recordAttempt/clearRateLimit` (**legacy**, session/table based) alongside the newer `SecurityRateLimiter` class — *two* rate-limiting mechanisms coexist (see §S).
- `reportInternalError($context, $error)` → 12-hex `error_ref`, newline-stripped, 2 000-char truncation, `[req:<X-Request-Id>] [attempt:<id>]` correlation, `error_log('[SSMS:<ref>] …')`.
- `mapDbErrorForApi(Throwable)` → maps `Data too long`/`1406`/`22001`→422 `field_too_long`; `Duplicate entry`/`1062`/`23000`→409 `duplicate`; FK `1451/1452`→409 `linked_records`; deadlock `1213`→503 `retry`; else 500 `server_error` — **message-text sniffing, not error codes** (see §S).
- `respondApiThrowable($ctx,$e)` → logs + emits `{status,code,message,error_ref}`.
- `_isAjaxRequest()`, `_invalidateAdminSession()`, `_isPrivilegedBrowserArea()` (`/admin/`, `/monitor/`, `/backend/`, **`/frontend/`**).

### G.2 `api/v1/core/auth.php`
- `createToken($user, …)` L47: payload `{uid,usr,nam,rol,fid?,av,...}` → `base64url(json) . '.' . hmac_sha256` (L82). **Not JWT**: no `alg`, no header, no `iss/aud`.
- `createRefreshToken` L101 → same construction with `jti`/`fid` and 90-day TTL, persisted through `RefreshTokenService`.
- `verifyTokenState($token)` L134: split on `.`, `hash_equals` MAC (L142), decode, expiry check → state array or error state.
- `apiRequireAuth()` L303: extracts bearer (`getTokenFromRequest`), validates, then `apiRevalidateAuthorizationScope()` — DB hit per request when scope enforcement applies.
- `apiAccessTokenExpiryForClient()` L36 and `apiAuthorizationScopeEnforcedForClient()` L203 implement the **build-number cohort** logic (legacy 30-day tokens for old builds until 2026-09-24).

### G.3 `RefreshTokenService` (verified contract)
`issue($user,$ip,$ua,$acmDeviceId=null,$bound=false)` → `issueWithinTransaction`; `rotate($presented,$payload,…,$dpopProof,$proofVerifier)` → `rotateTracked` (when `jti`+`fid` present) **or** `exchangeLegacy` (one-time entry for pre-rotation tokens). Per-request memoised column probes (`acm_device_id`, `acm_bound`) make a mid-deploy schema skew safe: missing columns read as "unbound/legacy" instead of failing. Bound rotation **requires** a DPoP verifier; a silent downgrade is refused with `InvalidArgumentException`.

### G.4 `AttendanceRecordService`
`normalizeCompleteSheet()` → `normalizeSheet($records,$roster,true)`; `normalizeSheet(...,false)` allows **partial drafts** but submissions require the complete roster. Enforces: non-empty, `MAX_RECORDS 2000`, every record an object with an integer `member_id ≥ 1`, duplicate rejection, `VALID_STATUSES = present|absent|late|excused`, `MAX_NOTE_LENGTH 500`, and roster membership. Throws `AttendanceSheetInvalid` carrying stable codes `INCOMPLETE_SHEET` / `ROSTER_MISMATCH` so sync monitoring can distinguish workflow state from contract violation.

### G.5 `SubmissionService` (2 264 L) — packet lifecycle
Statuses: `incomplete` (teacher sent, still working), `submitted` (complete, awaiting review), `approved`, `rejected`, `revision_needed`, `draft` (legacy row, normalised to `incomplete`). Key symbols verified: `invalidScores()` (whole-or-nothing score rule; blank = "no score"/"Did not take"; ≤2 decimals; ≤ assessment max; rejects `(float)"abc"`), `normalizeStatus()`, `teacherMayWriteAttendance()`, `attendanceHasRows()`, `reviewTransitionError()` → delegates to `ReviewTransitionPolicy`, `ensureTable()`/`hardenUniques()` (both **no-ops**, DDL deliberately removed after finding J — the slot uniques live in `sql/053`), event emission via `AttendanceSubmissionEventService`, `entersReviewQueue` computed only on the `→submitted` edge.

### G.6 `ReviewTransitionPolicy`
Rule: a reviewer decision is valid **only** on `status='submitted'`; reopenable only from `submitted|approved`; `raceLostMessage()` for the "UPDATE matched 0 rows" conflict (another reviewer decided first) — explicitly department-neutral (no table, no role, no connection) so HR/Mezmur share it without importing Education.

### G.7 `FeatureGate`
`CONSTANTS` map of 13 features; `isEnabled()` returns `constant($c) === true` (strings fail closed); `telegram_registration` additionally consults `TELEGRAM_REGISTRATION_MANAGED_ENABLED`; `forAdminRequest($script)` (basename map incl. `/id_cards/` prefix rule); `forApiResource()` (only `attendance|grades|mezmur`); `mobileCapabilities()` (9 features + `background_outbox_drain`); `filterMobileTiles()` (whitelist `attendance,grades,finance,material,groups,id_cards,reports,warnings`).

### G.8 `BrowserPageRoutes`
`PAGES` registry of **41** slugs → legacy scripts; `path()` rejects control bytes/backslash and does no normalisation; `dispatchIdentity()` sets `SCRIPT_NAME/PHP_SELF/SCRIPT_FILENAME` to the **real** script so the guard's `ROLE_MAP` and FeatureGate apply to the true file; `navigationRedirect()` only for GET/HEAD document navigations (`Sec-Fetch-Dest`/`Accept`), never for AJAX or API. Explicit header comment: *"This registry is not an authorization policy."*

### G.9 `AcmTicketService` / `AccountProvisioner` / `AcmPolicy`
Ticket states: `draft → submitted → in_review → {approved → code_issued → grant_verified → account_pending_device → active}` with branches `changes_requested`, `rejected`, `expired`, `revoked`, `activation_failed`, `provisioned`, `archived`, `canceled`. Immutable `self_approved` flag recorded when the same officer both submits and decides. `AccountProvisioner::provision()` inserts into `users` with `role = ticket.account_type` — the SQL enum is `('member','parent')` (`sql/079` L66), and the insert sets `is_active = 0` (pending device) with a bounded 5-attempt username-collision retry on `23000`, degrading a lost email race to `NULL`; it then links `acm_person_accounts` with `status='pending'`. `suspend`/`deactivate` refuses any account whose `role` is not `member` or `parent` (L398) — the staff-suspension path is therefore out of reach of this class by construction. `AcmPolicy::consoleRoles() = ['super_admin','accounts_office']`.

### G.10 Flutter `SessionCoordinator`
Single instance (`ChangeNotifier`); owns `SessionRoot`, `LocalSessionRecord` (state+generation), `LocalDataInventory`, `CredentialLoadState`; increments `_generation` on every credential/scope transition and injects a `sessionGenerationProvider` everywhere, so a response from an older generation cannot drive state or persist server data (`ApiResponse.sessionSuperseded`).

---

## H. Database Architecture

### H.1 Engine & access (VERIFIED)
MariaDB 11.4 (CI), utf8mb4 everywhere, no ORM, no foreign-key-complete schema (68 declared FKs across 136 tables; many relations are code-enforced). Two drivers per request; `PDO::ATTR_EMULATE_PREPARES => false`, `ERRMODE_EXCEPTION`, `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`.

### H.2 Tables by domain (recomputed + docs cross-checked)
- **Identity/access/audit (7):** `users`, `activity_logs`, `wbws_audit_log`, `security_rate_limits`, `api_refresh_sessions`, `api_refresh_legacy_exchanges`, (+`api_idempotency`, `api_idempotency_records` in the mobile group).
- **Members (9):** `members` (central entity, ~60 columns; legacy DDL in `sql/legacy/database_schema.sql`), `departments`, `staff_positions`, `member_staff_positions`, `member_changes`, `member_code_migrations`, `member_code_sequences` (*unreferenced*), `member_type_settings`, `member_department_periods`.
- **Family link (4):** `guardians`, `member_guardians`, `guardian_aliases`, `guardian_link_suggestions` (`sql/069`).
- **Academic (8):** `academic_years`, `academic_terms`, `classes`, `class_enrollments`, `class_subjects`, `subjects`, `teacher_assignments`, `dept_settings`.
- **Timetable/calendar (6):** `timetable_entries`, `timetable_periods`, `timetable_class_days`, `timetable_makeup_classes`, `school_holidays`, `school_day_offs`.
- **Assessments/grades (6):** `assessments`, `assessment_types`, `academic_records`, `grade_submissions`, `assessment_followups`, `assessment_makeup_attempts`.
- **Attendance/alerting (10):** `attendance`, `attendance_summary`, `attendance_rollup`, `attendance_month_rollup`, `attendance_sessions`, `attendance_session_records`, `attendance_submission_events`, `attendance_warnings`, `attendance_warning_events`, `attendance_policies`.
- **Mezmur (13 + 1 quarantine):** `mezmur_hymns`, `mezmur_categories`, `mezmur_hymn_categories`, `mezmur_hymn_words`, `mezmur_zemarians`, `mezmur_hymn_zemarians`, `mezmur_days`, `mezmur_sessions`, `mezmur_attendance`, `mezmur_attendance_audit`, `mezmur_submissions`, `mezmur_play_stats`, `mezmur_user_favorites`, `migration_055_mezmur_link_quarantine`.
- **HR retired archive (3):** `hr_attendance`, `hr_attendance_audit`, `hr_submissions` — *kept untouched, nothing reads them*.
- **Communication (8):** `notifications`, `notification_reads`, `announcements`, `message_threads`, `message_thread_participants`, `messages`, `department_tasks`, `shared_documents` (*unreferenced*).
- **Reporting (1):** `report_definitions`.
- **Mobile/sync/telemetry (10):** `api_idempotency`, `api_idempotency_records`, `api_sync_attempts`, `app_installations`, `app_telemetry_events`, `app_crash_signatures`, `app_downloads`, `sync_changes`, `sync_feed_state`.
- **Monitoring/failure (6):** `arkeon_error_log`, `arkeon_uptime_log`, `failure_issues`, `failure_reports`, `failure_alerts`, `failure_remediation_catalog`.
- **Platform/AI (5):** `system_settings`, `system_branding`, `ai_provider_configs`, `ai_chat_history`, `cache_storage` (*unreferenced*).
- **CMS (7):** `cms_programs`, `cms_schedule`, `cms_teachers`, `cms_registration_submissions`, `cms_gallery_categories`, `cms_gallery_photos`, `cms_social_links`.
- **Finance/materials (7, legacy PHP migration):** `finance_transactions`, `finance_categories`, `finance_member_fees`, `finance_budgets` (*unreferenced*), `material_items`, `material_categories`, `material_transactions`, `material_requests` (+ photo pipeline: `photo_import_batches`, `photo_import_items`, `photo_*` receipt tables, `acm_*` 12+).

> The exact per-table counts (113 in `docs/DATA_DICTIONARY.md`, 136 recomputed here) differ because the dictionary is a *dated* `46fbaa7` inventory and the 136 figure only covers `sql/*.sql`. Neither equals the deployed schema.

### H.3 Migration & schema-truth limits (VERIFIED)
- No migration ledger table. `sql/README.md` + ADR-0004: migrations are **manual, idempotent, applied by a human in phpMyAdmin**.
- Not linearly applicable to an empty DB; `tests/e2e/provision_env.sh` is the executable baseline.
- **Runtime DDL still exists** in one place: `MezmurSchemaReconciler::apply()` via `POST api_mezmur.php action=migrate` (SA/AD only, audited). Everything else that used to do DDL is now an explicit no-op (`SubmissionService::ensureTable/hardenUniques`, `AssignmentService::ensureSchema`, `GalleryService::ensureSchema`, `TimetableService::ensureSchema`).
- **Capability probes** replace DDL assumptions: `RefreshTokenService` column probes, `TimetableService::schemaReady()` (information_schema), `api_mezmur.php` table/column drift report, `api_finance`/`api_material` `SELECT 1 … LIMIT 0` probes → 503 with an operator hint.
- **Applied production state: UNKNOWN.** No ledger, no dump in the repo, and `docs/DATABASE.md` itself marks it unverified.

### H.4 Data-flow highlights
- Academic scoping is by `academic_year_id` on nearly every transactional table, resolved through `ay_resolve()`; the *user-visible* year can be a past (read-only) year.
- Attendance is **class-day only** for day sheets (`SchoolCalendarService`): future dates 422, 45-day backdate window, day-off/holiday refusal, configured working days required.
- Grades are packet-scoped (`grade_submissions` + uniques `uq_gs_attendance_slot`/`uq_gs_marklist_slot` from `sql/053`).
- Mezmur audio bytes **never touch PHP/MySQL**: presigned direct PUT to R2, then a signed HEAD confirm, then metadata on `mezmur_hymns` with `revision++` for the mobile delta feed.
- Deletes are mostly soft (`members.status='archived'`); user deletion nulls references (`user-delete.php` updates `member_changes.changed_by_user`, `department_tasks.from/to_user_id`).

---

## I. API Map

### I.1 Mobile REST `/api/v1` (18 resources; contract level verified)
| Resource | Methods / actions (from route files) | Auth |
|---|---|---|
| `auth` | `login`, `refresh-token`, `logout` (POST); `verify` (GET) | public + rate-limited |
| `acm` | ticket/person/device/office actions | bearer + `AcmPolicy` |
| `members` | list, `{id}`, `{id}/attendance`, `{id}/attendance-risk`, `{id}/department-status`, `PUT {id}`, `POST {id}/photo` — **no create** | role-projected |
| `attendance` | sheet GET/POST, `submit`, `summary`, `daily-stats`, `calendar`, `write-context`, `preparation`, `capabilities`, `operation-status`, `warnings/*` | `apiRolesAttendance` + class scope |
| `classes` | list, `{id}`, `{id}/students` | education/attendance roles |
| `dashboard` | `stats`, `recent` | any authenticated |
| `teachers` | list, `{id}` | education roles |
| `enrollment` | `overview`, `search`, POST | `apiRolesEducation` |
| `subjects` | list | education/teacher |
| `users` | `me` (GET/POST/PUT/PATCH + aliases), `me/profile-image` (GET/POST/DELETE), `change-password`, `me/children` | self-service (+`parent`) |
| `grades` | `bootstrap`,`subjects`,`students`,`assessments`(GET/POST),`assessment-types`,`types`,`save`,`submit`,`submission`,`submissions`,`submission-review`,`student-results`,`summary`,`followups`,`preparation` | `apiRolesGrades` + class scope |
| `app` | `config` (public), `download` (APK) | public |
| `telemetry` | `POST /telemetry`, `/heartbeat`, `/{event}` | public, dual rate limit |
| `mezmur` | hymns/hymn/hymn-status/changes/categories/category(-status/-image/-image-remove)/zemarian-image/audio(presign,confirm,remove)/audio/{id}/lyrics-synced/sections/days/sheet/submission(s)/submission-review/analytics | `$MEZMUR_ROLES`, writes `$MEZMUR_LIBRARY_WRITE_ROLES` |
| `hr` | **all paths → 410 `HR_ATTENDANCE_RETIRED`** | bearer required |
| `notifications` | `summary`,`feed`,`announcements`,`announcement-read`,`compose`,`targets`,`partners`,`threads`,`thread`,`thread-start`,`thread-read`,`send-message`,`message-edit`,`message-delete`,`mark-read`,`mark-all-read` | role-aware (`canAnnounce`/`canMessage`) |
| `sync` | `changes`, `state` — entity allowlist = **`attendance` only** | attendance roles + FeatureGate |
| `timetable` | `week`, `today` | authenticated |

Response envelope is uniform; `X-Request-Id` on every response; 404/405 semantics are explicit per action; several responses are `Cache-Control: no-store` by design (write-context, preparation, operation-status, app config).

### I.2 Admin AJAX (`admin/api_*.php`, 52 files)
Contract: `$_REQUEST['action']` switch, POST-only writes enforced by `requirePostActions`, CSRF by `requireCsrfForPost`, JSON `{status,message,…}`. Notable action families are enumerated in §E.6. There is **no OpenAPI** for this layer (documented gap).

### I.3 Compatibility layer
`backend/api/*.php` (21 files): 20 are one-line `require_once` shims; `init.php` is real. `frontend/js/core.js` prefers `APP.api = '/backend/api/'` and falls back to `/admin/`.

### I.4 Documented contract artefacts
`docs/api/openapi.yaml` (100 935 bytes) exists and is the only machine-readable contract; it covers `/api/v1` **only**. `docs/API.md` describes it as OpenAPI 3.1 with 117 operations and *summarised* bodies — that count is a documentation claim I did not independently recount.

---

## J. Authentication & Authorization

### J.1 Browser (VERIFIED end-to-end)
1. `admin/index.php` renders the login form + `generateCsrfToken()`.
2. POST → `backend/auth/login.php` → `admin/backend/login.php` (see §G.1/E.2): CSRF → type/length validation → rate limits → PDO lookup → `password_verify` → `is_active` → `session_regenerate_id(true)` → session claims → audit row.
3. Every subsequent request: `config.php` inactivity check (1800 s) → `AdminSessionGuard` (300 s DB revalidation, 8 h absolute, password-hash-version match, impersonation integrity) → `access_control.php` (login + `ROLE_MAP` + `FeatureGate`).
4. Impersonation: only `super_admin`/`school_admin` may `POST api_impersonate.php action=switch` with a target role from a **9-role** allowlist; `original_admin_role` is stashed; every page except the impersonate/restore endpoints authorizes by the *assumed* role; each switch/restore writes `activity_logs`.

### J.2 Mobile (VERIFIED end-to-end)
Access token (15 min, HMAC) → per-request DB revalidation (`role`, `is_active`, `authorization_version`, `username`, `full_name`) → route role helpers → class/parent scoping → FeatureGate. Refresh (90 d) rotates atomically with replay detection and family revocation; optional ACM device link (`acm_device_id`) and **DPoP binding** (`acm_bound`) for bound families. Rate limits: login 10/min, refresh/logout 30/min (per `routes/auth.php`).

### J.3 Authorization matrix summary
- Page-level: `ROLE_MAP` 116 entries × 16 roles (see §E.3). **Fail-safe = login-only** for any file not listed — documented as a hazard, with the 8 open `api_*` files individually justified.
- Action-level: implemented per endpoint (e.g. `api_education.php` three tiers; `api_guardians` write/read split; `api_settings` privileged actions; `api_acm` `AcmPolicy::can($role,$res,$verb)`). **Not centrally tabulated and not covered by a contract test.**
- Class-scope: `teacher_assignments` for `teacher` / `attendance_taker` in both web (`api_attendance.php` closure) and mobile (`apiRequireClassAccess`).
- PII scope: `apiRolesPii() = info_dept|school_admin|super_admin`; `apiCanBrowseMembers()` adds `edu_dept`; row projections strip phone/address for others (`apiMemberSafeRow`).
- Data-boundary: sensitive report datasets restricted to Information (ADR-0012), enforced inside `api_reports_v2.php` (not tabulated).
- Role inventory drift: `users.role` became `VARCHAR(50)` (`sql/012` L309) so any string can exist; **`member` and `parent` roles are provisioned only by ACM**, never listed in `$validRoles` or `ROLE_MAP`, and `AdminSessionGuard::KNOWN_ROLES` does **not** include them (affects impersonation validation only — see §S).

---

## K. State Management

### K.1 Server-side session (browser)
`$_SESSION` keys: `admin_logged_in/id/username/role/full_name`, `LAST_ACTIVITY`, `AUTH_STARTED_AT`, `AUTH_REVALIDATED_AT`, `AUTH_PASSWORD_VERSION`, `PROFILE_ACCOUNT_CONTEXT`, `csrf_token`, plus feature-scoped keys (`original_admin_role`, `original_admin_full_name`, academic-year view context, monitor step-up).

### K.2 Mobile app state (device)
- **Durable:** SQLite `wbws_offline_v4.db` (schema 43) — private read caches (28-table registry), unsent attendance/grade packets, outboxes (comm/hymn/photo), sync log, sync attempts; secure storage for access/refresh tokens and profile; app passcode.
- **Ephemeral:** `SessionCoordinator` (root/generation/inventory/credential state), `ApiService` request generation, per-screen widget state.
- **Lifecycle invariants (verified in comments + code):** tokens are *inputs*, not authority; an `active` marker must be persisted before `isActive`; logout offers Cancel / Keep work & sign out / Discard permanently; preservation enters same-account re-auth and keeps the passcode; authorization-scope change pauses old-scope work with count-only visibility; shared hymn caches survive account changes by design.

### K.3 Documented state machines
`grade_submissions`/`mezmur_submissions` packet lifecycle; `academic_years.status` `upcoming|active|closed` with `is_current` as a *derived mirror* (`admin/backend/academic_year.php` L11–12, with a `status`-first, `is_current`-fallback resolver); `members.status` `active|warning|inactive|archived`; `membership_tier` `temporary|permanent`; `mezmur_hymns.audio_status` `none|pending|ready|rejected`; ACM ticket states; `material_requests.status` `pending|approved|denied|fulfilled`; `finance_transactions.status` `confirmed|pending|cancelled`; `photo_import_items.decision` `pending|accepted|conflict|failed|skipped`.

---

## L. Business Rules (extracted from source)

| # | Rule | Evidence |
|---|---|---|
| L1 | Attendance is a record of what happened: **no future dates** | `api_attendance.php` `$refuseFutureDate` (422); `routes/attendance.php` (422 `FUTURE_DATE`) |
| L2 | Day attendance only on **class days** — inside a **45-day** backdate window, not a day-off/holiday, working days configured | `SchoolCalendarService::BACKDATE_DAYS = 45`, `classDayStatus()`; `apiAssertClassDay` |
| L3 | A **submission** must be the complete roster; a **draft** may be partial | `AttendanceRecordService::normalizeSheet($requireComplete)` |
| L4 | A mark list is accepted whole or not at all; blank = "no score"; ≤2 decimals; ≤ assessment max | `SubmissionService::invalidScores()` |
| L5 | Reviewer decisions only on `submitted`; reopen only from `submitted|approved`; concurrent decision → conflict | `ReviewTransitionPolicy` |
| L6 | Teachers/takers are limited to their **assigned classes** in the current year | `apiRequireClassAccess`; `teacher_assignments` closure |
| L7 | **Only `edu_dept`** writes the school calendar (holidays and day-offs); SA/AD/EDU/teacher/taker/HR/INFO may *read* date statuses | `SchoolCalendarService::WRITE_ROLES = ['edu_dept']` (L30), `READ_ROLES` (L32), enforced in `admin/api_school_calendar.php` L49/L73 |
| L8 | Academic **year** lifecycle = School Admin/Super Admin only; **semester** = Education + admins | `api_education.php` TIER 1a / TIER 1b |
| L9 | An **active** year cannot be deleted; a **closed** year is permanently protected; an `upcoming` year only if it holds no year-scoped records | `api_education.php` (`delete_year`) |
| L10 | Registration/promotion belong to **HR**; Information is viewer/updater | `ROLE_MAP` (hr_register_member, api_import_members, api_check_duplicate = SA/AD/HR) |
| L11 | **HR attendance is retired** (410 for every action) but `hr_*` tables are silently archived | `api_hr_attendance.php`, `routes/hr.php` |
| L12 | Members are **never created through the mobile API** (ADR-0017) | `routes/members.php` has no POST create |
| L13 | Mezmur audio bytes never transit PHP/MySQL; metadata only; public URL rebuilt from key | `MezmurMediaService` header + `audio_presign/confirm` |
| L14 | Section equals age group (ADR-0007); Ethiopian calendar only (ADR-0008); semester-based grading (ADR-0010); lesson-level attendance (ADR-0018) | ADRs + `dob_ec_*`, `academic_terms`, `attendance_sessions` |
| L15 | HIGH-risk actions require a typed phrase (17 phrases) | `admin/backend/danger_confirm.php::dc_phrases()` |
| L16 | Financial amounts: ≤2 decimals, > 0 (or ≥ 0 when allowed), ≤ 99 999 999.99 | `LedgerValidation::money()` |
| L17 | Deleting a user nulls references instead of cascading | `admin/backend/user-delete.php` |
| L18 | Archived members are excluded from default listings, reports and search (`status='archived'` filter) unless explicitly requested | `MemberDirectoryService` L94–98, `MemberReportService` |
| L19 | Member codes come from a bounded, high-entropy generator with a unique lookup — no sequential MAX/90 000 ceiling | `hr_register_member.php` header; `IdentityCodeService` |
| L20 | Feature flags default ON except attendance-v2 and Telegram registration | `school_config.php` L202–219 |

---

## M. Workflow Maps (traced end-to-end)

### M.1 Member registration (HR) — VERIFIED
`admin/hr_register_member.php` (POST, CSRF, ROLE_MAP SA/AD/HR) → idempotency reservation keyed on `registration_request_id` (`ApiIdempotencyService`, 16–80 chars, `[A-Za-z0-9._-]`, payload canonicalised, files fingerprinted) → `MemberRegistrationPolicy` (tier `temporary|permanent`, registration type; permanent may not be `waiting`) → `MemberDuplicateService` + `AmharicIdentityNormalizer` + `MemberCanonicalIdentityService` (advisory override possible, reason recorded) → `IdentityCodeService` allocation → INSERT + files via `MemberFileService` → `onMemberRegistered()` (`workflow.php`) → `GuardianService` link/ensure-primary → optional `createDepartmentTask()` for permanent eligibility → `jsonExit()` completes or abandons the idempotency reservation.

### M.2 Day attendance (mobile, offline-first) — VERIFIED at contract level
Login (ACM-aware) → `GET /attendance?class_id&date` (sheet) → local draft in SQLite (`client_op_id` via `AttendanceOperationManifest`) → optional `GET /attendance/write-context` (read-only dimensions, explicitly *not* a write permission) → `POST /attendance` (draft) or `/attendance/submit` → server: `apiRequireClassAccess` → `apiAssertClassDay` → `apiIdempotencyBegin` → rate limit (20) → `apiValidateAttendanceSheet(requireComplete=true)` → `SubmissionService::teacherMayWriteAttendance` (409 `ALREADY_SUBMITTED` if locked) → `AttendanceRecordService` + transaction → `SubmissionService` packet row (`submitted`) + `attendance_submission_events` → `attendance_summary/rollup` writers → warnings evaluated (`AttendanceWarningService`, gated by `attendance_v2`) → receipt/`ok()`. Outbox classifies 4xx as "needs attention", never silent loss.

### M.3 Teacher marks → Education review — VERIFIED
`POST /grades/preparation` (grant) → `GET /grades/assessments|students` → `POST /grades/save` (per-assessment, score rule) → `POST /grades/submit` → `grade_submissions.status='submitted'` → Education: `GET /grades/submissions` → `POST /grades/submission-review` (`decision = approve|return|reject`, note) → `ReviewTransitionPolicy::guard` → guarded UPDATE (`WHERE status='submitted'`) → 409 `raceLostMessage` on 0 rows → `approved` unlocks report cards / `revision_needed` reopens for the teacher.

### M.4 Mezmur hymn + audio publishing — VERIFIED
`POST /mezmur/hymn` (write roles) → taxonomy reconcile (`MezmurHymnService`, `taxonomy_reconcile.dart` on the client) → `POST /mezmur/audio-presign` (SA/AD/MEZ; SigV4 URL) → device/browser PUTs bytes straight to R2 → `POST /mezmur/audio-confirm` (signed HEAD, size/format check) → `audio_status = ready` (or `rejected`) → `revision`/`updated_at` bump → mobile delta `GET /mezmur/hymns/changes?cursor=` converges.

### M.5 Mezmur attendance + review — VERIFIED at contract level
`GET /mezmur/days` → `ensureDay()` → `GET /mezmur/sheet` (section) → `saveSectionSheet` (taker; locked once submitted unless `WRITE_OVERRIDE_ROLES`) → `MezmurSubmissionService` packet → `submission-review` (`REVIEW_ROLES = mezmur_dept|school_admin|super_admin`) → `attendance` + audit rows; `analytics` endpoints restricted to `$MEZMUR_ANALYTICS_ROLES`.

### M.6 ACM account activation — VERIFIED at state-machine level
Person registered (`AcmPersonService`) → ticket `submitted` → office review (`in_review`) → `approved`/`changes_requested`/`rejected` → activation code issued (`CodeAlphabet`, hashed, expiry, burn) → grant verified → **device binding** (DPoP registration) → `account_pending_device` → `active`; `AccountProvisioner` creates the `users` row with `role=member|parent` and the `acm_person_accounts` link; deactivation cascades revocation of grants, codes and refresh families.

### M.7 App release — VERIFIED
`admin/api_app_release.php` (SA): `upload_preflight` → `upload_chunk` (resumable, idempotent chunk receipts) → `upload_status` → `assemble_chunks` (APK inspected: `fkssApkManifest`/`fkssAxmlManifestInfo` parse the binary manifest) → config `.fkss_app_release.php` (`min_build`, `force_update`, banner, `background_drains_enabled`) → mobile `GET /app/config` decides update policy (`fkssPhoneUpdatePolicy` never forces without a downloadable file) → `GET /app/download` streams the correct per-ABI artifact.

### M.8 Public enquiry → member (documented gap)
`register_submit.php` → `cms_registration_submissions`. **No code path was found** that converts a submission into a member; the docs call them "reviewed before becoming members" (`docs/DATA_DICTIONARY.md`) but the review/conversion UI was not located (see §T.4).

---

## N. Status / State Transition Maps

1. **Submission packet** (attendance, marklist; Education, HR-retired, Mezmur): `incomplete ⇄ draft(legacy) → submitted → approved | rejected | revision_needed → (reopen) submitted`, with `submitted|approved` reopenable and `raceLostMessage()` for concurrent review.
2. **Academic year:** `upcoming → active → closed`, `closed → active` only through explicit *Reopen* (`needs_reopen` error otherwise); `is_current` mirrors `status='active'`; activation writes a prior-year close in the same transaction (`AcademicYearActivation`).
3. **Member:** `active → warning → inactive → archived` with reasons (`left_school|graduated|transferred|inactive_long|failed_observation|deceased|other`) and a restore path (`info_restore_member.php`).
4. **Membership tier:** `temporary → permanent` (promotion), with `registration_type` defaulted from the tier.
5. **Mezmur audio:** `none → pending → ready | rejected`.
6. **ACM ticket:** as in §G.9; terminal set includes `rejected|provisioned|archived|active|canceled`.
7. **Finance transaction:** `pending → confirmed | cancelled`. **Material request:** `pending → approved|denied|fulfilled`. **Announcement priority:** `normal|high|urgent` (invalid → `normal`).
8. **Photo import item:** `pending → accepted | conflict | skipped | failed`.
9. **Mobile session:** `anonymous_clean → active → (reauthentication_required | authorization_scope_changed) → active`, with generation counter invalidating stale in-flight work.

---

## O. External Integrations

| Integration | Direction | Contract verified | Notes / limits |
|---|---|---|---|
| **Telegram Bot API** | outbound (cURL) + inbound webhook | `TelegramBotApiService` (`call`, `getFile` with path/`file_id` regex, size caps ≤5 MiB, HTTPS-only, no redirects), `TelegramKeyboard`, `TelegramBotMessages` (message catalogue), 13 services | Webhook: `X-Telegram-Bot-Api-Secret-Token` compared with `hash_equals`, JSON-only, 262 144 B cap, `INSERT IGNORE telegram_processed_updates` dedup, **private-chat-only**, answers in the HTTP response to avoid a second round-trip. Feature flag default OFF. |
| **Cloudflare R2 (S3-compatible)** | outbound | Hand-rolled SigV4 presign + signed HEAD in `MezmurMediaService`; key layout `mz/audio/{hymn_id}/{uuid32}.{ext}`; public base from `MEZMUR_MEDIA_PUBLIC_BASE` | No AWS SDK by design. Config absent → service reports "not configured", rest of app unaffected. |
| **AI providers** | outbound | `admin/backend/ai_providers.php` — `ai_chat()` façade with three adapters: `openai`-compatible (OpenAI/Groq/OpenRouter), `gemini`, `anthropic`; API keys **AES-256-CBC encrypted at rest** (`ai_provider_configs`) with a key derived from env secrets; keys returned masked | Feature-gated (`FEATURE_AI_CHATBOT`), SA-only config actions. |
| **CDNs** | inbound (browser) | Tailwind Play CDN (16 files), cdnjs with SRI (Font Awesome, xlsx, jspdf), Google Fonts (22 files) | No CSP `script-src`/`style-src` restrictions are set, so these load unconditionally; a CDN outage degrades styling/export, not core function. |
| **APK distribution** | outbound (self-hosted) | `api/v1/core/app_release.php` + `apk_manifest.php`; chunked admin upload | No Play Store. `.gitignore` forbids committing APKs. |
| **Error monitoring** | internal + Telegram | `ArkeonErrorMonitor`, `arkeon_error_log`, `arkeon_uptime_log`, `monitor/uptime_cron.php` | Uptime config in `monitor/uptime_cron.php` is a **hard-coded literal** (`'telegram_enabled' => false`, placeholder token/chat id) — the DB-backed cron is authoritative (see §S). |
| **Email** | **none** | No `mail()`, no SMTP, no PHPMailer outside `vendor/` (VERIFIED: only `normalizeEmail`/`validateEmail` matches) | All notifications are in-app or Telegram. Password reset by email does not exist. |
| **Redis** | **none** | `vendor/predis` installed but no first-party reference | Dead dependency weight. |

---

## P. Configuration (verified)

| Key | Type | Where | Effect |
|---|---|---|---|
| `DB_HOST/NAME/USER/PASS` | string | env file (out of tree) | both connections |
| `JWT_SECRET` | string | env file | `API_TOKEN_SECRET` HMAC key; random fallback per request if missing |
| `BACKUP_KEY` | string | env | libsodium key derivation for `.sql.gz.ssb` |
| `HEALTH_KEY`, `MONITOR_SECRET_KEY`, `TELEMETRY_HASH_SECRET` | string | env | health endpoint, monitor, telemetry hashing |
| `MEZMUR_MEDIA_ACCOUNT_ID/KEY/SECRET/BUCKET/PUBLIC_BASE` | string | env | R2 presign |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_WEBHOOK_MAX_BYTES` | string/int | env (optional file) | webhook + outbound bot |
| `ACTIVE_THEME` | const `'fkss'` | `school_config.php` | theme resolution with `wbss` fallback |
| 13 `FEATURE_*` | bool | `school_config.php` L202–219, env-overridable | module availability |
| `SESSION_TIMEOUT` | const 1800 | `config.php` L147 | inactivity logout |
| `REVALIDATE_INTERVAL_SECONDS` / `ABSOLUTE_SESSION_SECONDS` | const 300 / 28800 | `AdminSessionGuard` | DB revalidation cadence / hard cap |
| `API_TOKEN_EXPIRY` / `API_REFRESH_EXPIRY` / `API_LEGACY_TOKEN_EXPIRY` | 900 / 7 776 000 / 2 592 000 | `api/v1/core/auth.php` L11–13 | token lifetimes |
| `MAX_RECORDS`, `MAX_NOTE_LENGTH`, `BACKDATE_DAYS`, `KEEP_COUNT`, `RETENTION_DAYS` | 2 000 / 500 / 45 / 7 / 30 | services | domain limits |
| `$directoryCutover` | static bool true | `MemberSearchService` | one-line rollback of the TNTSearch directory cutover |
| `ENABLE_TEST_DATA_TOOLS` | bool | env | `api_test_seed.php` gate (must stay off in production) |
| `PHP_SAPI` guards | — | 6 cron/tool files | CLI-only enforcement |
| `.htaccess` host handler | — | root L65–67 | `ea-php82` |

**Missing/absent dependencies (VERIFIED absent):** no mail transport, no Redis client use, no message queue, no job scheduler inside the app, no Composer install step at deploy (vendor committed), no `.env` loader beyond the two `*_env.php` files, no migration ledger.

---

## Q. Error Handling (actual behaviour)

- **Global browser bootstrap**: DB failures are logged and *not* fatal (`config.php` L262–295) — each page decides. Missing secrets **are** fatal (500 + setup page).
- **Session/guard failure**: privileged areas get `401 {status:'session_expired', action:'reload'}` for XHR, a redirect for navigation, and **503** when revalidation itself is unavailable (`revalidation_unavailable`) — fail-closed.
- **Public bootstrap**: `ping` and `app/config` degrade gracefully; `public_gallery.php` answers `{"status":"error"}` with `503` headers when the DB is down.
- **Mobile API**: `set_exception_handler` + `register_shutdown_function` guarantee a JSON `500` envelope with `X-Request-Id`; DB errors are mapped by `mapDbErrorForApi()` (message-text heuristics) to 422/409/503/500; per-action 4xx codes are stable strings (`FUTURE_DATE`, `ALREADY_SUBMITTED`, `OPERATION_STATUS_UNAVAILABLE`, `AUTH_SCOPE_CHANGED`, `HR_ATTENDANCE_RETIRED`, …).
- **Admin AJAX**: per-file try/catch; several files use `respondApiThrowable()` for a structured `error_ref`; others emit ad-hoc `{status:'error',message}` — **two conventions coexist** (see §S).
- **Internal logging**: `reportInternalError()` → `error_log('[SSMS:<12hex>] [req:…] [attempt:…] <context>: <detail≤2000>')`; `error.log` path is `ROOT_PATH/error.log`; the monitor also persists to `arkeon_error_log` with privacy minimisation.
- **Client-side (mobile)**: typed `ApiFailureKind`, `AuthRefreshOutcome`, retry-after parsing, `idempotencyReplayed`, `notModified`, session-superseded detection; a failed drain is classified, never silently dropped; reinstall/clear-data warnings are explicit.
- **Silent-failure surfaces found**: 119 `@`-suppressed filesystem calls; several `catch (Exception $e) {}` blocks that swallow errors (e.g. `api_education.php` teacher lookup, `backend/api/init.php` stats); `AdminSessionGuard` downgrades its own exception to `error_log('Admin session revalidation failed.')` — deliberately fail-closed at the caller.

---

## R. Dependency Graph (critical paths)

```
config.php ─┬─ env file (secrets) ─┬─ DB (mysqli/PDO)
            ├─ backend/core/browser.php ─ session
            ├─ admin/backend/services/AdminSessionGuard.php ─ PDO ─ users
            ├─ admin/backend/services/FeatureGate.php ─ school_config FEATURE_*
            ├─ monitor/error_monitor.php ─ arkeon_* tables, Telegram
            ├─ admin/backend/academic_year.php ─ academic_years
            ├─ admin/backend/services/BrandingAssetService.php ─ system_branding/uploads
            └─ admin/access_control.php ─ ROLE_MAP ─ pages

pages ── services ── mysqli/PDO ── MariaDB
  ├─ SubmissionService ── ReviewTransitionPolicy, AttendanceSubmissionEventService
  ├─ AttendanceRecordService ── (pure validation)
  ├─ AttendancePreparationService ── AttendancePreparationGrant, Workflow, ReadBoundary
  ├─ Mezmur* ── MezmurMediaService ── R2 ; MezmurSchemaReconciler ── DDL
  ├─ NotificationCenterService ── notifications/messages/announcements
  ├─ Member* ── MemberSearchService ── TNTSearch (admin/var/search), MemberDirectoryService (PDO)
  └─ Telegram* ── TelegramBotApiService ── api.telegram.org

api/v1 ── core/{response,database,auth,acl,middleware} ── same services
acm/autoload.php ── vendor/autoload.php + explicit manifest ── admin/acm_*.php, api/v1/routes/acm.php
browser_page.php ── BrowserPageRoutes ── real legacy script (identity rewrite)
```

**High-fan-in nodes (breakage blast radius):** `config.php` (everything), `FeatureGate` (all gated modules), `access_control.php` (all admin pages), `AttendanceRecordService` (web + mobile attendance), `SubmissionService` (attendance + grades + review UI), `ReviewTransitionPolicy` (three departments), `MemberDirectoryService`/`MemberSearchService` (all member lists/reports/exports), `RefreshTokenService` (every mobile request), `BrandingAssetService` (every page header).

---

## S. Potential Problems (documented, not fixed)

**Security-adjacent**
1. **POTENTIAL SECURITY CONCERN — REQUIRES VERIFICATION:** `admin/backend/login.php` L71 looks up `WHERE username = :ue1 OR email = :ue2 LIMIT 1`. Two different accounts (`username` of one, `email` of another) can match the same input; the first row wins and the *other* account's password is then compared. Enumeration/ambiguity risk is bounded by rate limits but the collision behaviour is undefined by any test I found.
2. **POTENTIAL SECURITY CONCERN — REQUIRES VERIFICATION:** `error.log` is written to `ROOT_PATH/error.log` (`config.php` L226). The root `.htaccess` blocks `*.sql|md|sh|py|lock|yml|dist` and the folders, but has **no `.log` rule** and no `error.log` `FilesMatch`; `admin/.htaccess` blocks `\.(sql|zip|gz|tar|bak|log)$` *within `admin/`*. Whether the host serves `/error.log` therefore depends on server configuration, not on repository rules. (`.gitignore` excludes it from VCS, which is not a serving control.)
3. **POTENTIAL SECURITY CONCERN — REQUIRES VERIFICATION:** `CSP` in `config.php` L241–246 and root `.htaccess` sets only `base-uri/object-src/frame-ancestors/form-action` — **no `script-src`/`style-src`**, and 16 first-party pages load the Tailwind **Play CDN** (which injects styles dynamically) and 31 load cdnjs. Any HTML injection therefore executes. XSS defence rests entirely on output encoding (`e()/esc()/htmlspecialchars`), which is used widely but not universally verifiable page-by-page in this pass.
4. **POTENTIAL SECURITY CONCERN — REQUIRES VERIFICATION:** `mapDbErrorForApi()` classifies DB failures by **matching message substrings** (`'1406'`, `'Duplicate entry'`, `'SQLSTATE'`…). A message that mentions those tokens for another reason (e.g. a user-supplied value echoed in an error) can mislabel an error class; and the mapping is only as stable as MariaDB's wording.
5. **Design weakness (VERIFIED) — the central guard fails "safe" only to *logged in*.** `access_control.php` grants any authenticated role access to any `/admin/` or `/backend/` file whose basename is absent from `ROLE_MAP`. **Reachability today (VERIFIED per file):** every `admin/` page is either mapped, public/self-guarding, or one of the 8 `api_*` files with in-file checks, so no page-level exposure was found. **Not proven:** that each of those 8 endpoints applies an equally strict rule to *every* action it dispatches (§T.5). There is also **no test that fails when a new `admin/` file is added without a `ROLE_MAP` entry** — the real regression risk. The existing `ROLE_MAP` tests (`tests/security/test_dashboard_role_routing.py`, `test_dept_takers.py`, `test_mezmur_module.py`, `test_reporting_centre_v2.py`, `test_academic_tracking.py`) pin *specific* entries, not the closure property.
6. **Impersonation role lists are consistent today but drift-prone (VERIFIED, corrected):** four role vocabularies exist — `$validRoles` (16), `ROLE_MAP` role set (16), `admin/api_impersonate.php` switch allowlist (9: `school_admin, hr_dept, info_dept, edu_dept, finance_dept, material_dept, mezmur_dept, teacher, attendance_taker`) and `AdminSessionGuard::KNOWN_ROLES` (10: the 9 plus `super_admin`) with `PRIVILEGED_ROLES = [super_admin, school_admin]`. **The switch allowlist is a strict subset of `KNOWN_ROLES`, and both originator roles are in `PRIVILEGED_ROLES`, so every *reachable* impersonation path passes the tamper check.** The risk is future drift: a new switchable role absent from `KNOWN_ROLES` would be invalidated as `impersonation_tampered` after the first 300 s revalidation, and `content_editor`/`photo_dept`/`accounts_office`/`mezmur_attendance_taker`/`hr_attendance_taker` can never be impersonated at all (not in the allowlist) even though their dashboards exist.
7. **Rate limiting is tri-modal:** session-based `isRateLimited/recordAttempt` (legacy), DB/`security_rate_limits` via `SecurityRateLimiter`, and ad-hoc per-file counters. Which mechanism protects a given action is not centrally visible.
8. **`api_mobile.php` comment rot:** `access_control.php` L22/L54 claims the mobile bridge "defines `WBWS_API_REQUEST` before loading config.php"; the file no longer does (it is a 7-line 410 stub). Harmless today, misleading for the next maintainer.

**Correctness / data**
9. **Verified dead route:** `frontend/pages/dashboard.php` maps nine dashboard files that do not exist in that directory and therefore always redirects to `/admin/dashboard.php`. No test appears to assert otherwise.
10. **Verified documentation drift in `README.md`:** it states the mobile source checkpoint is **1.7.5+42** while `pubspec.yaml` is **1.8.5+48** and the app README says **1.8.4+47** (§U).
11. **Raw SQL string interpolation** (14 occurrences in `admin/*.php`), mostly `(int)`-cast ids and allow-listed column names — e.g. `api_education.php` L840/L849/L1396 (`(int)` ids, `$tbl` from a literal list), `api_reports.php` L210 (`$f` from a literal list), `api_attendance_info.php` (`$monthAgo` from `date()`), `api_finance.php` L88 (`$m1/$m2` from `date()`), `api_mezmur.php` L192/L221 (`$tbl`/`$col` with `real_escape_string`). **No injection was demonstrated**, but the pattern is one careless edit away from one; there is no lint rule forbidding it.
12. **`api_photo_zip_import.php` builds INSERT/UPDATE SQL by concatenation** including `real_escape_string` and `$decision` (allow-listed internally) — L218–283. Same class of risk.
13. **Duplicate/serialised schema truth:** 136 tables but `docs/DATA_DICTIONARY.md` says 113; two tables defined twice; no ledger; runtime DDL still possible from a web endpoint (`action=migrate`). Applied production state **UNKNOWN**.
14. **`attendance_rollup` vs `attendance_month_rollup` vs `attendance_summary`** all exist with overlapping purpose; `attendance_summary` has two historical writers collapsed into `AttendanceSummaryService` (comment in that file). Whether all three are still maintained consistently is **INFERENCE — REQUIRES VERIFICATION**.
15. **Unreferenced tables (5)**: `member_code_sequences`, `cache_storage`, `shared_documents`, `finance_budgets`, `migration_055_mezmur_link_quarantine` — candidates for review, not deletion.
16. **`api_education.php` probes a `submissions` table that no migration creates** (L1395 list includes `'submissions'`) — the `try/catch` hides it, so `delete_year` record counting silently under-counts if that table was intended.

**Architecture / debt**
17. **Four HTTP surfaces, three implementations of role checks.** `docs/ARCHITECTURE.md` and `AGENTS.md` both admit the consolidation is unfinished. Each new rule must be added in `ROLE_MAP`, `api/v1/core/acl.php`, and per-file `in_array($role, …)` lists.
18. **No first-party autoloader.** 890 `require_once` statements; adding a class means editing every caller and satisfying `test_structure_include_paths`. `acm/autoload.php` exists precisely because SPL registration is forbidden.
19. **`config.php` is 973 lines doing 12 jobs** (env, session, headers, DB, guard, monitor, year, branding, helpers, output compression, access control). Every request pays all of it, including two DB handshakes.
20. **Two DB connections per request** (mysqli + PDO) — connection-count pressure on shared hosting, and split transaction domains (a service using PDO cannot join a mysqli transaction).
21. **Tailwind Play CDN in a production admin** (16 files) — runtime compilation, no CSP integrity, and a hard external dependency for layout.
22. **`vendor/predis` unused**, TCPDF/phpqrcode vendored inside `admin/`, `api/v1/tests/test_version.py` living inside a web-served folder (worked around by `KNOWN_INTERNAL_FILES_IN_OWN_REWRITE_DIRS`).
23. **Hard-coded literals in `monitor/uptime_cron.php`** (`telegram_enabled => false`, `YOUR_BOT_TOKEN_HERE`) despite a comment saying "set these up later" — the effective alert path is `FailureAlertService`, so monitor Telegram alerts are inert.
24. **Two decades of "retired" surfaces kept alive deliberately**: `app/` PWA tombstone, `admin/api_mobile.php` 410, `admin/app_session.php` 410, `api/v1/routes/hr.php` 410, `hr_*` tables, `dept_taker.php` landing for retired `hr_attendance_taker`. Each is documented; together they dominate "what is this?" questions in review.
25. **Large single files**: `admin/dashboards/hr-dept.php` 4 974 L, `info-dept.php` 4 504 L, `edu_dept.php` 4 426 L, `api_education.php` 2 800 L, `SubmissionService.php` 2 264 L — high merge-conflict and review cost.
26. **No contract tests between layers**: `api/openapi.yaml` covers `/api/v1` only, is "summarised", and no test asserts `admin/api_*` behaviour matches the mobile routes that share the same services.
27. **CI/runtime parity gap** is acknowledged in-repo: tests on PHP 8.4, lint 8.4 + 8.5, deployment handler `ea-php82`, workflow comment claiming 8.4.25 *and* 8.5 — four versions in one story.

---

## S.1 Highest-value verified findings (condensed reading aid for §S and §U)

The fifteen findings below are each reproduced from source in this session and are the ones most likely to matter to a maintainer. They are a *reading aid*, not a replacement for the evidence in §S and §U.

1. **Role-name vocabulary is inconsistent across four lists.** `$validRoles` (16), `ROLE_MAP` role set (16), `api/api_impersonate.php` target allowlist (9), `AdminSessionGuard::KNOWN_ROLES` (10). `docs/ROLES_AND_PERMISSIONS.md` additionally documents `registrar` and `account_activator`, which exist nowhere in PHP except as a UI label in `admin/telegram_registration.php` (VERIFIED). **Practical effect today: none — the lists are consistent in effect.** The 9 roles the switch API accepts are a strict subset of `AdminSessionGuard::KNOWN_ROLES` (10, including `super_admin`), so no reachable impersonation can be flagged as `impersonation_tampered`. The hazard is *maintenance*: adding a role to the switch allowlist without adding it to `KNOWN_ROLES` would silently invalidate that session after the first 300 s revalidation.
2. **Central page guard fails "safe" only to *logged in*** (§S item 5). Every current `admin/` file was individually checked; the residual risk is future files.
3. **`frontend/pages/dashboard.php` can never serve a dashboard** — its map targets nine files that do not exist in that folder, so it always 302s to `/admin/dashboard.php` (§U/§S item 9).
4. **`error.log` sits at the web root without a `.log` deny rule** in the root `.htaccess` (VERIFIED: 0 matches for `log`); `admin/.htaccess` denies `*.log` only inside `admin/`. Whether the host serves it is INFERENCE-dependent (§S item 2).
5. **No `script-src`/`style-src` CSP**, while 16 first-party pages load the Tailwind Play CDN — XSS mitigation rests entirely on output encoding (§S item 3).
6. **Four HTTP surfaces, three authorization implementations.** The same rule must be added to `ROLE_MAP`, `api/v1/core/acl.php` and per-file `in_array($role, …)` lists; there is no contract test tying them together (§S item 17).
7. **Two DB handshakes per request** (mysqli + PDO) with split transaction domains — a PDO-based service cannot join a mysqli transaction (§S item 20).
8. **Runtime DDL is not fully gone**: `MezmurSchemaReconciler::apply()` remains reachable from `POST admin/api_mezmur.php action=migrate` (SA/AD, audited); every other former DDL site is now an explicit no-op (§H.3).
9. **136 `CREATE TABLE` targets in `sql/*.sql` across 56 files, two defined twice, no ledger, non-linear applicability** — the deployed schema is UNKNOWN (§H.3).
10. **Retired surfaces are deliberately retained and documented**: `app/` PWA tombstone + SW kill switch, `admin/api_mobile.php` 410, `admin/app_session.php` 410, `api/v1/routes/hr.php` 410, `hr_*` tables archived, `hr_attendance_taker` role kept for account compatibility (§E.9/§S item 24).
11. **`config.php` is a 973-line universal kernel** included by the browser pages, the mobile API (`api/v1/core/database.php`), and the public pages; any change there has repo-wide blast radius (§R).
12. **Secrets fail closed, tokens fail open-ish**: missing env file → 500; missing `JWT_SECRET` (with env present) → random per-request secret, so every mobile token silently invalidates on the next request (§E.1).
13. **Mezmur audio is a genuine zero-PHP-byte pipeline** (presign → direct PUT → signed HEAD verify with size contract → `audio_status`), and health is surfaced to operators through `action=schema` drift reporting rather than silent failure (§O, `MezmurMediaService::confirmUpload`).
14. **The attendance/grade correctness rules are unusually well-instrumented**: complete-sheet vs partial-draft split, whole-or-nothing score validation, single shared review state machine with a race-loss message, class-day/backdate enforcement, and stable machine codes for offline clients (§L1–L5).
15. **The mobile client treats tokens as input, not authority** — `SessionCoordinator` owns generation, ownership inventory and reauthentication; unsent work survives auth expiry by design and cannot be inherited by another account (§K.2).

---

## T. Unknowns (cannot be verified from the repository)

1. **Deployed schema state.** No migration ledger, no production dump, runtime DDL possible. Which of the 136 tables/columns exist in production is **UNKNOWN**.
2. **Deployed code revision, host config, PHP version actually serving, cron schedules actually installed.** `docs/OPERATIONS.md` records these as dated observations, not facts.
3. **Whether any external caller uses `backend/api/*` shims** besides `frontend/` (`docs/API.md` §6 says undetermined). I searched first-party callers only.
4. **The CMS lead → member conversion path.** `cms_registration_submissions` is written by `register_submit.php`; no reviewed UI or service was located that consumes it. **UNKNOWN — NOT VERIFIED FROM SOURCE.**
5. **Per-action authorization for every endpoint.** `docs/ROLES_AND_PERMISSIONS.md` explicitly declines to tabulate it; this report covers ~30 endpoints in detail.
6. **ACM cryptography correctness.** `DeviceCrypto`, `DeviceRefreshProofs`, DPoP verification and the ACM PHPUnit suite were read at contract level only — no crypto review was performed.
7. **Flutter per-screen behaviour and offline race behaviour** beyond `SessionCoordinator`, `ApiService`, `LocalDb`, `main.dart` and `utils/config.dart`. 177 Dart files exist; ~20 were read.
8. **Test suite green/red state.** I did not execute any test (no DB, no Flutter toolchain in this session); CI status is not observable from the checkout.
9. **Data volumes / performance characteristics.** No query plans, no index audit beyond the declared keys, no slow-query evidence.
10. **Mobile release state** (published APK, signer continuity, installed fleet). `docs/SECURITY.md` flags debug-signing fallback when `android/key.properties` is absent; the actual signing identity is **UNKNOWN**.
11. **Telegram conversation state machine** (`TelegramRegistrationService`) — 13 services, only the webhook transport and the admin console were traced.
12. **`api/v1/routes/grades.php` preparation-grant semantics** — the grant/prepared-dataset protocol is verified at surface level; the server-side acceptance rules inside `GradesPreparationService` were not fully traced.

---

## U. Documentation ↔ Implementation Mismatches

| # | Documentation claim | Source reality | Verdict |
|---|---|---|---|
| U1 | `README.md` L32/L114: mobile source checkpoint **1.7.5+42** | `pubspec.yaml` `version: 1.8.5+48`; app README **1.8.4+47** | **MISMATCH** (three values) |
| U2 | `docs/API.md` L62: "The **17** resources below are mapped in `api/v1/index.php`" | `$routeMap` has **18** (`acm` missing from the table) | **MISMATCH** |
| U3 | `docs/API.md` L88: "The **46** files" of `admin/api_*.php` | **52** files on disk | **MISMATCH** |
| U4 | `docs/ROLES_AND_PERMISSIONS.md` L11: `$validRoles` "accepts **15** roles (13 + 2 ACM seats)", with a table that lists `registrar` and `account_activator` | `$validRoles` has **16** entries: the doc's table omits `photo_dept`, `photo_importer` and `accounts_office`, and includes `registrar`/`account_activator`, which appear in no PHP role list | **MISMATCH (count *and* set)** |
| U5 | `docs/ROLES_AND_PERMISSIONS.md` L47: "`ROLE_MAP`, **101** entries" | **116** entries | **MISMATCH** |
| U6 | `docs/ROLES_AND_PERMISSIONS.md` lists roles `registrar` / `account_activator` as real roles | Neither appears in `$validRoles`, `ROLE_MAP`, `AcmPolicy`, nor any PHP file except the Telegram console's *label* "Bot users / registrars" | **MISMATCH** (documented roles do not exist in code) |
| U7 | `README.md` L27 + `backend-checks.yml` L80: production PHP **8.5**; same workflow L41: production **8.4.25**; `.htaccess` handler `ea-php82` | Three self-contradicting statements in the repo | **INTERNAL INCONSISTENCY** |
| U8 | `admin/access_control.php` L22/L54: `api_mobile.php` "defines `WBWS_API_REQUEST` before loading config.php" | `api_mobile.php` no longer loads config or defines the constant (410 stub) | **STALE COMMENT** |
| U9 | `docs/DATA_DICTIONARY.md`: **113** table definitions | **136** distinct `CREATE TABLE` names in `sql/*.sql` (both self-labelled as lexical, different baselines) | **KNOWN DIVERGENCE** (documented as such) |
| U10 | `docs/DATA_DICTIONARY.md`: `cms_registration_submissions` "reviewed before becoming members" | No review/conversion consumer found | **UNPROVEN CLAIM** |
| U11 | `SubmissionService::hardenUniques()` previously claimed "unique keys are deployment-managed by migration 013" | Migration 013 declares **no UNIQUE key**; uniques live in `sql/053`. The comment now documents the correction | **HISTORIC MISMATCH (already corrected in source)** |
| U12 | `monitor/uptime_cron.php` config block ("Telegram — set these up later") | Values are hard-coded placeholders; `telegram_enabled` is `false` | **DEAD CONFIG** |
| U13 | `docs/ARCHITECTURE.md`/`AGENTS.md`: shims are transitional | Shims are still the path used by `frontend/js/core.js` (`APP.api = '/backend/api/'`) | **STILL ACTIVE** (consistent with docs, but the "migration" is not progressing) |

*(Docs that held up on inspection: `docs/API.md` auth description — custom HMAC, not JWT — matches `core/auth.php`; ADR-0002 lifetimes match the constants; `docs/ROLES_AND_PERMISSIONS.md`'s HR-retirement and page-level matrix claim matches `ROLE_MAP` semantics; `docs/DATABASE.md`'s 98-file/136-target count matches my independent recomputation.)*

---

## V. Change Impact Map

Use this before any modification (Prompt §37). For each area: **direct** files → **indirect** consumers → **must-not-break** invariants.

| Change area | Direct | Indirect (verify each) | Invariants to preserve |
|---|---|---|---|
| Session / login / guard | `config.php`, `admin/backend/login.php`, `AdminSessionGuard` | every `/admin/`, `/frontend/`, `/backend/` page; monitor step-up; impersonation | 1800 s inactivity, 300 s revalidation, 8 h absolute, password-version match, JSON-vs-redirect contract, audit row never blocking login |
| Page authorization | `admin/access_control.php` (`ROLE_MAP`) | 116 keys; `browser_page.php` slugs (`BrowserPageRoutes::PAGES` must stay in sync with real files) | unmapped-but-authenticated fail-safe; deployment-only denials; impersonation exception set |
| Feature flags | `school_config.php`, `FeatureGate` | `forAdminRequest`, `forApiResource`, `forRoleDashboard`, `filterMobileTiles`, `mobileCapabilities`, `app/config` tiles | server authority; ambiguous strings fail closed; mobile UI is convenience only |
| Attendance rules | `AttendanceRecordService`, `SchoolCalendarService`, `api_attendance.php`, `routes/attendance.php`, mobile `attendance_*` services | `AttendanceSummaryService`, `AttendanceWarningService`, `SubmissionService`, report cards, dashboards, preparation grants | 45-day window, class-day-only, complete-sheet-on-submit, `MAX_RECORDS 2000`, teacher class scope, `ALREADY_SUBMITTED` 409 |
| Submission/review lifecycle | `SubmissionService`, `ReviewTransitionPolicy`, `AttendanceSubmissionEventService` | Education, Mezmur, (retired HR), mobile review inbox, report cards | single state machine; `submitted`-only decisions; guarded UPDATE + race message; score-limit whole-or-nothing |
| Grades | `api_education.php`, `routes/grades.php`, `AssessmentTypeService`, `AssessmentFollowupService`, `ReportCardService`, `ScoreLimit` JS/Dart | report cards, exports, followups, mobile `teacher_grades` | max-score validation, term scoping, `assessment_types` resolution |
| Members / directory / search | `MemberDirectoryService`, `MemberSearchService`, `MemberDuplicateService`, `api_list_members.php`, `api_search*.php` | every list/report/export, mobile `members` route, pickers, dashboards | role projection (PII stripping), archived-exclusion defaults, TNTSearch fallback + `$directoryCutover` |
| Registration | `hr_register_member.php`, `MemberRegistrationPolicy`, `IdentityCodeService`, `MemberFileService`, `ApiIdempotencyService` | guardians auto-link, `onMemberRegistered`, ID cards, Telegram intake, ACM person links | idempotent `registration_request_id`, tier/registration-type validity, code uniqueness, no mobile create |
| Ledgers (finance/material) | `api_finance.php`, `api_material.php`, `LedgerValidation` | `frontend/js/finance.js`, `material_department.php`, reports, exports, mobile "use the website" notices | referential validation, `activity_logs` best-effort, status enums, money/decimal rules |
| Mezmur media | `MezmurMediaService`, `MezmurSchemaReconciler`, `routes/mezmur.php`, `admin/api_mezmur.php` | mobile download manager, lyrics/LRC, player, `audio_status` consumers | bytes never through PHP; presign→confirm; `revision` bump for delta sync; migrate action SA/AD-only + audited |
| Communications | `NotificationCenterService`, `api_notifications.php`, `routes/notifications.php`, `admin/js/comm.js`, mobile comm outbox | bell widget, dashboards, unread summaries, ETag/version caches | `canAnnounce`/`canMessage`/`mayMessageRole`; version strings for conditional GET; no cross-role leaks |
| Refresh/auth tokens | `core/auth.php`, `RefreshTokenService`, `routes/auth.php`, mobile `session_service.dart` | every mobile request, ACM device binding, outboxes | HMAC format, 900 s/90 d/30 d TTLs, one-time legacy exchange, family revocation on reuse, DPoP for bound families, build-number cohorts |
| ACM | `acm/src/**`, `admin/api_acm.php`, `admin/acm_*.php`, `routes/acm.php`, `Acm*Service` adapters | parent/member accounts, device binding, refresh families, session guard role sets | maker–checker (`registrar` cannot approve — as a *designation*, not a role); ticket state machine; `role=member|parent` only; explicit manifest in `acm/autoload.php` |
| Schema migrations | `sql/NNN_*.sql` | **every** service query, CI `migration-hygiene`, `tests/e2e/provision_env.sh`, phpMyAdmin procedure | idempotency, numbering, uniques from `sql/053`, no runtime DDL except Mezmur reconciler, non-linear applicability |
| Bootstrap | `config.php` | literally everything, incl. `api/v1` and public pages | env fail-closed, `WBWS_API_REQUEST` semantics, guard ordering, `reportInternalError` ref format, output compression for non-API |
| APK release | `AppReleaseManager`, `api_app_release.php`, `core/app_release.php`, `apk_manifest.php` | mobile update banner/gate, `app/config`, download streaming, CI android-build | never force an update without a downloadable artifact; per-ABI fallback to `universal`; legacy fields kept forever |
| Docs/tests | `docs/**`, `tests/**` | `test_docs_links`, `test_structure_boundaries`, `test_structure_include_paths`, `test_documentation_source_facts` | every relative link resolves; no `.md/.sql/.sh/.py` inside rewrite-owning folders; listed exceptions only |

**Standing hazards for any change (VERIFIED):** a new PHP file under `admin/` is *unprotected by role* until added to `ROLE_MAP`; a new class needs `require_once` at every call site; a new page must keep `BrowserPageRoutes` and `ROLE_MAP` keyed by the **real** basename; and any rule implemented in a route file must be mirrored in the compatibility shims that the same UI can still call.

---

## Appendix — Reproduction commands (read-only, no network, no DB)

```bash
git -C . log -1 --format='%H %ad %s' --date=iso
git ls-files | wc -l
git ls-files '*.php' | grep -v '^vendor/' | grep -v '/vendor/' | grep -v 'id_cards/libs/' | wc -l
grep -c "define('FEATURE" school_config.php                  # 13
python3 - <<'PY'                                             # ROLE_MAP size
import re;s=open('admin/access_control.php').read();b=s[s.index('$ROLE_MAP = ['):s.index('\n        ];',s.index('$ROLE_MAP = ['))]
print(len(re.findall(r"^\s*'([^']+)'\s*=>\s*\[",b,re.M)))    # 116
PY
grep -c "=>" api/v1/index.php                                # route-map lines (18 entries)
ls admin/api_*.php | wc -l                                   # 52
python3 -c "import re;s=open('admin/backend/user-save.php').read();m=re.search(r'\\\$validRoles = \\[(.*?)\\];',s,re.S);print(len(re.findall(r\"'([a-z_]+)'\",m.group(1))))"  # 16
```

**Final status: ANALYSIS INCOMPLETE — COMPLETE FOR GLOBAL ARCHITECTURE, BOOTSTRAP, AUTH/AUTHZ MODEL, SCHEMA INVENTORY, INTEGRATION CONTRACTS AND 12 END-TO-END WORKFLOWS; PARTIAL FOR PER-ACTION AUTHORIZATION, DASHBOARD INTERNALS, ACM CRYPTOGRAPHY, TELEGRAM CONVERSATION STATE AND THE FLUTTER SCREEN LAYER.** No file in the repository was modified.
