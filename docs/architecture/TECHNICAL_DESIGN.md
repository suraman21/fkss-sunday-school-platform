# FKSS — Technical Design Document (software architecture)

| Field | Value |
|---|---|
| Document | Technical Requirements / Software Architecture Document (TRD/SAD), arc42-style |
| Status | **Reconstructed baseline. Draft for review by the engineering team** |
| Version | 1.0 (2026-10-22) |
| Describes | `main` at `46fbaa7`, read from source. Runtime behaviour in production was not re-tested for this document |
| Detailed references | [ARCHITECTURE.md](../ARCHITECTURE.md) (component detail) · [API.md](../API.md) · [DATABASE.md](../DATABASE.md) · [SECURITY.md](../SECURITY.md) · [OPERATIONS.md](../OPERATIONS.md) · [ROLES_AND_PERMISSIONS.md](../ROLES_AND_PERMISSIONS.md) |
| Requirements | [PRD](../product/PRD.md) · Decisions: [ADR index](../adr/README.md) |

Diagrams use Mermaid and render on GitHub. Statements are verified in code unless marked **Unverified**.

---

## 1. Introduction and goals

FKSS is a school management system for one Sunday school: web dashboards for each department, plus an offline-first Android app for teachers and staff. The requirements are in the [PRD](../product/PRD.md).

**Top quality goals (in priority order)**

| # | Quality goal | Why |
|---|---|---|
| 1 | **Data integrity** of the member register, marks and attendance | These records drive report cards, promotion and pastoral follow-up |
| 2 | **Least-privilege access to personal data** | The register holds children's and families' data |
| 3 | **Works offline and on poor networks** | Teachers record attendance on low-end phones on 2G/3G |
| 4 | **Operable by a very small team on shared hosting** | Budget and skills constraint |
| 5 | **Changeability** | The product has grown weekly; this is currently the weakest goal (section 11) |

## 2. Constraints

| Type | Constraint | Consequence |
|---|---|---|
| Hosting | Shared hosting: LiteSpeed, PHP 8.2, MariaDB. No shell, Composer, cron, Python, Redis or C extensions for the application | Plain PHP; committed `vendor/`; no workers or queues; work runs inside requests ([ADR-0001](../adr/0001-php-monolith-on-shared-hosting.md)) |
| Database changes | The owner applies SQL by hand in phpMyAdmin | Additive, idempotent migrations; no migration ledger ([ADR-0004](../adr/0004-manual-idempotent-sql-migrations.md)) |
| Deployment | Manual SFTP upload of changed files | No atomic deploys; cache-busting by asset version ([OPERATIONS.md](../OPERATIONS.md)) |
| Mobile distribution | No Play Store listing | In-app updater downloads the APK from the school's server ([ADR-0005](../adr/0005-direct-apk-distribution.md)) |
| Locale | Ethiopian calendar for every user-facing date; Amharic content | UTF-8 (`utf8mb4`) end to end; date conversion helpers ([ADR-0008](../adr/0008-ethiopian-calendar-only.md)) |
| Organisation | Owner approval for every schema change, deploy and production action | Changes are planned, reviewed and deployed in explicit steps |

## 3. Context (C4 level 1)

```mermaid
flowchart LR
  staff["Department staff<br/>(HR, Info, Education, Mezmur,<br/>Finance, Material, admins)"]
  teacher["Teachers and<br/>attendance takers"]
  public["Public visitors"]
  fkss["FKSS system<br/>(web + REST API + database)"]
  app["FKSS Android app"]
  r2["Object storage<br/>(Cloudflare R2)"]
  tg["Telegram Bot API"]
  ai["AI providers<br/>(optional)"]
  staff -->|"browser, HTTPS"| fkss
  teacher -->|"browser"| fkss
  teacher --> app
  staff --> app
  app -->|"REST /api/v1, HTTPS"| fkss
  public -->|"public site, gallery,<br/>registration form"| fkss
  fkss -->|"presigned URLs"| r2
  app -->|"audio download (signed)"| r2
  fkss -->|"failure alerts (if enabled)"| tg
  fkss -->|"assistant requests (if enabled)"| ai
```

A CDN/WAF proxy sits in front of the origin. There is no e-mail, SMS or payment integration.

## 4. Solution strategy

| Problem | Approach |
|---|---|
| Many departments on one register | One database and one PHP code base; per-department dashboards; a central page and endpoint role map (`admin/access_control.php`) |
| Mobile on poor networks | Offline-first Flutter app: SQLite cache, outboxes, idempotent writes, change feed ([ADR-0003](../adr/0003-offline-first-mobile.md)) |
| Secure mobile sessions without server state per request | Short-lived signed access tokens; rotating refresh tokens stored server-side; per-request authorization re-check ([ADR-0002](../adr/0002-mobile-token-authentication.md)) |
| Shared business rules between web and API | Service classes in `admin/backend/services/` (79 files), called from both. Consolidation is incomplete (section 11) |
| No background jobs | Do work inside the triggering request (for example, warnings are evaluated after each attendance write); CLI scripts exist where a cron would be needed |
| Feature rollout without branches per customer | Feature flags in `FeatureGate`; migrations that leave code inert until applied (for example, the class-day rule activates when `sql/078_school_calendar.sql` exists) |

## 5. Building blocks

### 5.1 Containers (C4 level 2)

```mermaid
flowchart TB
  subgraph host["Shared hosting account (one PHP code base)"]
    web["Web dashboards<br/>admin/dashboards/*, admin/*.php,<br/>frontend/pages/*"]
    adminapi["Admin JSON API<br/>admin/api_*.php (46)<br/>session + CSRF"]
    shims["Compatibility shims<br/>backend/api/*.php (21)"]
    rest["REST API v1<br/>api/v1/index.php → routes/* (17 resources)<br/>bearer token"]
    svc["Domain services<br/>admin/backend/services/* (79)"]
    pub["Public website, gallery,<br/>registration form"]
    mon["monitor/* (error and uptime)"]
  end
  db[("MariaDB<br/>single schema")]
  files[("Private file storage<br/>member files, backups, APKs")]
  appc["Android app (Flutter)<br/>SQLite + outboxes"]
  web --> adminapi
  web --> shims --> adminapi
  adminapi --> svc
  rest --> svc
  pub --> db
  svc --> db
  adminapi --> db
  rest --> db
  svc --> files
  appc --> rest
```

| Container | Technology | Responsibility | Detail |
|---|---|---|---|
| Web dashboards | PHP-rendered HTML, vanilla JS, CSS | One workspace per role | [ARCHITECTURE.md §2](../ARCHITECTURE.md#2-php-web-application-admin) |
| Admin JSON API | PHP, mysqli | Web business operations; `?action=` dispatch | [API.md](../API.md) |
| Compatibility shims | PHP | Older front-end entry points; 20 delegate to the admin API, `init.php` returns dashboard bootstrap data | [ARCHITECTURE.md §3](../ARCHITECTURE.md#3-the-three-api-layers-unfinished-consolidation) |
| REST API v1 | PHP router, JSON envelope | Mobile app contract | [API.md §2–4](../API.md#2-authentication) |
| Domain services | PHP classes, namespace `App\Services` | Business rules shared by web and REST | [ARCHITECTURE.md §2](../ARCHITECTURE.md#2-php-web-application-admin) |
| Database | MariaDB (InnoDB, utf8mb4) | All state | [DATABASE.md](../DATABASE.md) |
| Android app | Flutter, sqflite, provider | Offline-first client for 12 roles | [ARCHITECTURE.md §4](../ARCHITECTURE.md#4-mobile-application-mobilewbws_flutter_app) |

### 5.2 Key components (C4 level 3, PHP)

| Concern | Component(s) |
|---|---|
| Bootstrap and configuration | `config.php` (secrets file, DB, session, CSRF helpers, security headers), `school_config.php`, `FeatureGate` |
| Access control | `admin/access_control.php` (web), `AdminSessionGuard` (session revalidation), `api/v1/core/{auth,acl}.php` (REST) |
| Members | `MemberRegistrationPolicy`, `MemberDuplicateService`, `MemberCategory`, `IdentityCodeService`, `MemberFileService`, `GuardianMatchService` |
| Education | `SubjectDurationPolicy`, `AssessmentTypeService`, `SubmissionService`, `ReviewTransitionPolicy`, `ReportCardService`, `TimetableService`, `SchoolCalendarService`, `AcademicTrackingService` |
| Attendance | `AttendanceRecordService`, `AttendanceSummaryService`, `AttendanceRiskService`, `AttendanceWarningService`, `AttendancePolicyService`, `AttendanceMonthRollupService`, `LessonAttendanceService` |
| Mezmur | `Mezmur*Service`, `MezmurMediaService`, `MezmurSchemaReconciler` (runtime DDL) |
| Reporting | `admin/backend/reporting/ReportRegistry.php` and the governed query and export services |
| Mobile platform | `RefreshTokenService`, `ApiIdempotencyService`, `SyncChangeFeedService`, `AppReleaseManager`, `AppTelemetryService`, `Failure*Service` |
| Cross-cutting | `SecurityRateLimiter`, `SecurityAuditService`, `NotificationCenterService`, `BackupService`, `admin/backend/danger_confirm.php` |

## 6. Runtime views

### 6.1 Web request (staff dashboard)

```mermaid
sequenceDiagram
  participant B as Browser
  participant P as admin/api_x.php
  participant C as config.php
  participant G as access_control.php / AdminSessionGuard
  participant S as Service
  participant D as MariaDB
  B->>P: POST action=save (session cookie, csrf_token)
  P->>C: require config.php
  C->>G: role map check for this file
  G->>D: revalidate role, active, authorization_version
  G-->>P: allowed, or 302/401/403
  P->>P: requireCsrf()
  P->>S: business operation
  S->>D: prepared statements (transaction where needed)
  P-->>B: JSON {status, data | message}
```

### 6.2 Mobile login and token refresh

```mermaid
sequenceDiagram
  participant A as Android app
  participant R as /api/v1/auth
  participant D as MariaDB
  A->>R: POST /auth/login (username, password)
  R->>D: verify password, active, rate limit
  R-->>A: access token (15 min) + refresh token (90 days)
  Note over A: tokens kept in flutter_secure_storage
  A->>R: any request, Authorization: Bearer access
  R->>D: re-check role, active, authorization_version
  A->>R: POST /auth/refresh-token (refresh)
  R->>D: rotate: mark old token used, issue new pair
  Note over R,D: replaying a used refresh token revokes the whole family
```

### 6.3 Offline attendance capture and sync

```mermaid
sequenceDiagram
  participant T as Teacher
  participant L as App SQLite (outbox)
  participant API as /api/v1/attendance
  participant SV as AttendanceRecordService
  participant W as AttendanceWarningService
  T->>L: save sheet (offline OK)
  L-->>T: shown immediately from local DB
  L->>API: send when online, Idempotency-Key + X-App-Build
  API->>API: idempotency reservation (replay returns stored response)
  API->>SV: validate class-day rule, scope, lock state
  SV->>W: re-evaluate warnings for affected members
  API-->>L: result, outbox row settled
  L->>API: GET /sync/changes?entity=attendance&cursor=N
  API-->>L: items, next_cursor (change feed)
```

### 6.4 App update

The app polls `GET /api/v1/app/config`, compares its build number with the configured latest and minimum builds, and offers an optional update or requires a forced one. It then downloads the APK from `GET /api/v1/app/download`. The Super Admin uploads APKs through the release manager.

## 7. Deployment view

```mermaid
flowchart LR
  dev["Developer workstation"] -->|"git push / PR"| gh["GitHub: suraman21/fkss-sunday-school-platform<br/>(main, dev) + CI"]
  gh -->|"same commits"| prodrepo["GitHub: Felege-Kidusan/felegekidusan<br/>(main, private)"]
  dev -->|"owner-approved SFTP upload<br/>of changed files"| prod["Production shared hosting<br/>(LiteSpeed, PHP 8.2, MariaDB)"]
  owner["Owner"] -->|"phpMyAdmin: apply sql/NNN"| prod
  cdn["CDN / WAF proxy"] --> prod
  users["Browsers, Android app"] --> cdn
  beta["Beta server (from dev)"]:::muted
  classDef muted fill:#eee,stroke:#999,color:#555
```

| Environment | Source | Notes |
|---|---|---|
| Production | `main` | Manual deploy. Last recorded: `46fbaa7`, 2026-10-22, with checksums verified. Detail: [OPERATIONS.md](../OPERATIONS.md) |
| Beta | `dev` (`main` + one commit that re-targets the app) | Update mechanism **unverified** ([OPERATIONS.md §7.2](../OPERATIONS.md#72-open-questions), Q-8) |
| CI | GitHub Actions | PHP 8.4, MariaDB 11.4, Python 3.11, Flutter 3.44.9 ([DEVELOPMENT_AND_TESTING.md](../DEVELOPMENT_AND_TESTING.md)) |

## 8. Cross-cutting concepts

| Concept | Design |
|---|---|
| Authentication | Web: PHP session, 30-minute idle timeout, rate-limited login. Mobile: custom HMAC-SHA256 two-part access token plus rotating refresh tokens |
| Authorization | Role map per file name; in-endpoint checks for department ownership and class assignment; REST role helpers (`acl.php`); `authorization_version` invalidates stale sessions and tokens |
| Input and output safety | Prepared statements; HTML escaping helpers (`e()`, `esc()`); hex-escaped JSON in script contexts; CSRF on web writes |
| Personal data | Role-scoped fields (`apiRolesPii()`, reporting field permissions); private file storage; audited exports |
| Auditing | `activity_logs`, `SecurityAuditService`, domain audit tables (attendance, Mezmur, finance ledger, exports) |
| Idempotency and concurrency | `Idempotency-Key` reservations completed inside the business transaction; locking reads for duplicate checks; one packet per slot |
| Error handling | Errors logged, not displayed; REST returns a stable JSON envelope and typed error codes (for example `AUTH_SCOPE_CHANGED`) |
| Localisation | Ethiopian-calendar conversion at the edges; Amharic labels from single sources (`MemberCategory`) |
| Feature flags | `FEATURE_*` constants, defaults in `school_config.php`, enforced by `FeatureGate` on pages, API resources and mobile tiles |
| Observability | Error monitor, uptime script (needs cron), mobile telemetry, crash signatures, failure issues and alerts (Telegram optional) |
| Destructive actions | Tiered confirmation; server-side phrase check for high-risk actions ([ADR-0013](../adr/0013-tiered-danger-confirmation.md)) |

## 9. Architecture decisions

See the [decision record index](../adr/README.md). These records were reconstructed in October 2026 from the code, the commit history and the owner's planning documents.

## 10. Quality requirements

| Quality | Scenario | Current state |
|---|---|---|
| Integrity | A teacher's phone retries a submission 3 times on a flaky network → exactly one record | Met (idempotency keys; CI runs the lifecycle harness) |
| Security | A disabled staff account → no further web or API access on the next request | Met (per-request revalidation) |
| Security | A teacher requests another class's marks → refused | Met for the paths covered by `tests/security` (teacher scope tests) |
| Offline | A teacher records attendance with no network → visible at once, sent later | Met |
| Performance | Member lists for 5,000+ students stay responsive | Design target: capped page sizes and indexes. **No load test on record** |
| Recoverability | Restore production after data loss | **Not met:** restore never tested; backups in the same account |
| Changeability | A new engineer changes a grading rule in one place | **Partly met:** rules are duplicated across API layers |

## 11. Risks and technical debt

The full list, with evidence and recommended actions, is in [OPERATIONS.md §7](../OPERATIONS.md#7-known-limitations-and-open-questions). Architecture-level items:

1. **Three API layers with duplicated rules.** Decide the canonical layer per domain, then consolidate behind services.
2. **Very large files.** Dashboards of about 4,400–4,900 lines; `local_db.dart` about 7,500 lines. Split by feature, behind tests.
3. **Schema management.** No migration ledger, a stale `sql/legacy/database_schema.sql`, and runtime DDL. Introduce a ledger and a verified baseline.
4. **Runtime parity.** CI runs PHP 8.4 while production runs 8.2.
5. **Delivery pipeline.** Manual deploys, a red CI gate and a debug-signed APK.
6. **Repository layout.** It mixes the web app, API, mobile app, tests and legacy folders at the root. A target structure should be agreed before any move, because paths are referenced by the server, the mobile app and the tests.

## 12. Glossary

See [GLOSSARY.md](../product/GLOSSARY.md).
