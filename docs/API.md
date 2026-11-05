# API reference (overview)

**Status:** maintained API overview. Original route review: `46fbaa7` (2026-10-22/23); router, token constants and specification inventory rechecked at `7e28157` (2026-10-28). This maps entry points, authentication and conventions, not every field or action-level permission. [OpenAPI 3.1](api/openapi.yaml) exists: 118 listed operations with deliberately summarized bodies. Read the named route/service for field-level behavior; specification presence does not establish complete contract conformance or live deployment.

## Source-reviewed browser ledger references

At baseline `591e23d` (2026-10-28), the [Finance action reference](api/FINANCE_CONTRACT.md) and [Materials action reference](api/MATERIALS_CONTRACT.md) document each switch action in those two handlers individually. The matching [permission/gate reference](api/LEDGER_PERMISSIONS.md) separates bootstrap/session, role, feature, method, CSRF, confirmation and record checks. The [ledger column reference](database/LEDGER_COLUMNS.md) preserves defining DDL and later source constraints without claiming applied-schema parity. This is a bounded browser-API review, not full-system or `/api/v1` conformance.

## 1. Entry points

| Layer | Base path | Router | Used by |
|---|---|---|---|
| REST v1 | `/api/v1/` | `api/v1/index.php` (`$routeMap`) → `api/v1/routes/<resource>.php` | Flutter app |
| Admin JSON endpoints | `/admin/api_<name>.php` | Each file dispatches on `action` (query or body) | Admin pages and dashboards |
| Compatibility shims | `/backend/api/<name>.php` | `require` of the matching `admin/api_*.php` file. The exception is `init.php`, which has its own logic (dashboard bootstrap JSON) | `frontend/js/*` |

Why there are three layers is explained in [ARCHITECTURE.md §3](ARCHITECTURE.md#3-the-three-api-layers-unfinished-consolidation).

## 2. Authentication

### REST v1 (`api/v1/core/auth.php`, `admin/backend/services/RefreshTokenService.php`)

- **Token format:**
  - The token is a **custom two-part token**: `<b64>.<sig>`, where `b64 = base64(JSON payload)` and `sig = hex(HMAC-SHA256(b64, API_TOKEN_SECRET))` (`createToken`, `verifyTokenState` in `api/v1/core/auth.php`). **VERIFIED.**
  - Payload claims: `uid`, `usr`, `rol`, `nam`, `av` (authorization version), `iat`, `exp`, `typ`, `jti`.
  - It is **not a JWT**: there is no header segment and no `alg` field, and the encoding is standard base64 rather than base64url. Generic JWT tools cannot parse it.
  - `API_TOKEN_SECRET` is set from `JWT_SECRET` (`api/v1/core/auth.php`, line 9); the name `JWT_SECRET` is historical. The derived fallback on that line cannot be reached in practice, because `config.php` always defines `JWT_SECRET` first. If the secrets file loads without `JWT_SECRET`, `config.php` logs a critical error and uses a random per-request value, so every token fails verification (fail-safe).
- **Transport:** the token is sent only in the `Authorization: Bearer <token>` header. Query-string tokens are rejected deliberately.
- **Access token lifetime:** 900 seconds (`API_TOKEN_EXPIRY`).
  - App builds older than 16 got a 30-day legacy token until 2026-09-24 (`API_LEGACY_CLIENT_COMPAT_UNTIL`).
  - That window has passed, so every client now receives 15-minute tokens.
- **Refresh:**
  - `POST /api/v1/auth/refresh-token` performs a one-time rotation, stored in `api_refresh_sessions` (migration `010`).
  - Replaying an old refresh token revokes the whole token family.
  - Refresh sessions last 90 days.
- **Authorization:**
  - Role helpers are in `api/v1/core/acl.php`.
  - Teachers and attendance takers are restricted to their assigned classes (`teacher_assignments`).
  - Phone numbers and addresses are shaped out of responses for roles without PII access.
  - `users.authorization_version` (migration `048`) supports invalidating changed scope. In `api/v1/core/auth.php`, build 24+ is scope-revalidated; older/unknown builds depend on the deployment-controlled `API_AUTHZ_LEGACY_COMPAT_UNTIL` deadline, whose source default is `PHP_INT_MAX`. Actual deployment settings were not inspected. Do not promise immediate scope revocation for every legacy client or change that policy as a documentation workaround.
- **Login throttling:** `SecurityRateLimiter` (table `security_rate_limits`, migration `008`).

### Admin endpoints (`admin/api_*.php`)

- They use the PHP session, which is regenerated at login.
- Page and endpoint role checks come from `admin/access_control.php`.
- State-changing requests must carry the CSRF token defined in `config.php`.
- Some actions have extra checks inside the endpoint, such as typed confirmation phrases for destructive actions. These return `409 confirm_phrase_required`.

## 3. REST v1 conventions

- **URL shape:** `/api/v1/{resource}/{id}/{sub}`. In many routes `{id}` is an action name rather than a numeric id; for example, `grades/submit` and `attendance/daily-stats`.
- **Success envelope:** `{"status":"success","data":…}`. If the handler returns a message, it is lifted into a `"message"` field (`api/v1/core/response.php`).
- **Error envelope:** `{"status":"error","message":…}` plus optional extra fields. Domain error codes include `422 INVALID_SCORES` and `409 confirm_phrase_required`.
- **Pagination:** `page` and `limit`, with `limit` capped at 100 by default (`getPagination`). Responses use the `paginated()` helper.
- **Client headers:** `X-App-Build`, `X-App-Version`, `X-Installation-Id`, `X-Client-Attempt-Number`, `X-Execution-Source` and `Idempotency-Key`. They are used for compatibility decisions, sync monitoring and idempotent replay (`api/v1/core/middleware.php`; tables from migration `009`).
- **CORS:** only an allow-list of the project's own origins is honoured. Native app requests carry no `Origin` header.
- **Versioning:** there is a single `v1`. Compatibility is managed by app build number (`X-App-Build`) and the server-side update policy, not by URL versions.

## 4. REST v1 resources

The 17 resources below are mapped in `api/v1/index.php`. `attendance_warnings.php` and `assessment_followups.php` are not mapped directly; `attendance.php` and `grades.php` include them.

| Resource | Actions / sub-paths found in the route file |
|---|---|
| `auth` | `login`, `logout`, `refresh-token`, `verify` |
| `members` | GET list and detail, PUT, `attendance`, `attendance-risk`, `department-status` (GET/POST); field shaping by role. There is no POST: members are never registered through the API (the route file's header comment still lists one) |
| `attendance` | `submit`, `summary`, `daily-stats`, `write-context` (read-only dimensions, not offline authority); warnings via `attendance_warnings.php` |
| `classes` | GET |
| `dashboard` | `stats`, `recent` |
| `teachers` | GET |
| `enrollment` | `overview`, `search`, POST |
| `subjects` | GET |
| `users` | Self-service only: `me` (GET; PATCH/PUT/POST to update, aliases `profile-update` and `update`), `me/profile-image` (GET/POST/DELETE, alias `profile-image`), `change-password` |
| `grades` | `bootstrap`, `subjects`, `students`, `assessments`, `assessment-types`, `types`, `save`, `submit`, `submission`, `submissions`, `submission-review`, `student-results`, `summary`, `followups` |
| `app` | `config` (update policy), `download` (APK) |
| `telemetry` | POST only: `/telemetry`, `heartbeat`, `event` (public, rate limited) |
| `mezmur` | `hymns`, `hymn`, `hymn-status`, `categories`, `category`, `category-status`, `category-image`, `category-image-remove`, `lyrics-synced`, `audio-presign`, `audio-confirm`, `audio`, `audio-remove`, `art`, `art-remove`, `sections`, `days`, `sheet`, `submission`, `submissions`, `submission-review`, `analytics` |
| `hr` | **Retired.** Every path answers `410 HR_ATTENDANCE_RETIRED` (decisions of 2026-10-07 and 2026-10-18) |
| `notifications` | `feed`, `summary`, `announcements`, `announcement-read`, `compose`, `targets`, `partners`, `threads`, `thread`, `thread-start`, `thread-read`, `send-message`, `message-edit`, `message-delete`, `mark-read`, `mark-all-read` |
| `sync` | `changes`, `state` |
| `timetable` | `week`, `today` (GET only) |

The table was re-checked against the route code on 2026-10-23 (each `if ($method …)` branch, not only header comments). The full list of 117 operations is in [`api/openapi.yaml`](api/openapi.yaml). Some routes branch on the HTTP method without an action name. **The route file is the source of truth.**

## 5. Admin endpoints (`admin/api_*.php`)

The 46 files, grouped by domain:

| Domain | Files |
|---|---|
| Members and HR | `api_list_members`, `api_check_duplicate`, `api_import_members`, `api_export_members`, `api_guardians`, `api_identity`, `api_identity_migration`, `api_hr_attendance`, `api_hr_reports`, `api_qr_roster` |
| Education | `api_education`, `api_subjects`, `api_teachers`, `api_assignments`, `api_timetable`, `api_school_calendar`, `api_year_context`, `api_assessment_followups`, `api_lesson_attendance` |
| Attendance | `api_attendance`, `api_attendance_info`, `api_attendance_monthly`, `api_attendance_policy`, `api_attendance_warnings`, `api_department_status`, `api_dept_takers` |
| Reporting | `api_reports`, `api_reports_v2`, `api_report_views`, `api_info_reports`, `api_info_analytics` |
| Communication | `api_notifications`, `api_communication` |
| Departments | `api_mezmur`, `api_finance`, `api_material` |
| Platform and admin | `api_settings`, `api_branding`, `api_cms`, `api_impersonate`, `api_app_release`, `api_mobile`, `api_telemetry`, `api_failure_intelligence`, `api_ai`, `api_test_seed` |

`api_test_seed.php` is restricted to `super_admin` and refuses to run unless `ENABLE_TEST_DATA_TOOLS` is `true`. That flag must stay off in production.

### App-release administration is a separate web contract

`admin/api_app_release.php` uses the super-admin/session/CSRF boundary, not the mobile download contract. Its actions include `get_release`, `save_config`, `upload_preflight`, `upload_chunk`, `upload_status`, `assemble_chunks`, `cancel_upload`, the legacy `upload_apk` path and `delete_apk`. Upload receipts/finalization and release policy are different concerns: do not repeat finalization or change minimum-build/force policy merely because a client lost a response. See [the upload evidence](reports/APK_UPLOAD_512MIB_E2E_2026-10-27.md), [isolated browser harness](../tests/e2e/README.md) and [mobile release guide](../Mobile/wbws_flutter_app/docs/RELEASE.md). Web chunk-upload recovery does not mean mobile APK downloads support resume.

## 6. Unresolved and open items

- A machine-readable contract for `/api/v1` now exists: [`api/openapi.yaml`](api/openapi.yaml) (OpenAPI 3.1, added 2026-10-23). It covers paths, methods, authentication and envelopes; most bodies are summarised. There is still no OpenAPI for `admin/api_*`, and no contract tests between the two layers for shared rules.
- The deprecation plan for `backend/api/*` shims is undocumented. Unknown: whether any external caller uses them besides `frontend/`.
- The per-action authorization inside each `admin/api_*.php` file is not tabulated (see [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md)).


## Attendance write-context discovery (development)

`GET /api/v1/attendance/write-context?class_id=1&date=2026-11-05` returns a
point-in-time description, not a prepared dataset or permission to write. It
requires current server authorization (including old client builds), attendance
role and class access, an existing class and a positive server-resolved write
year. `class_id` must be an exact positive decimal query string within the
portable integer limit; `date` must be explicit exact Gregorian YYYY-MM-DD.
Missing/malformed/extra scope inputs are 422; reserved `_route` / `path` routing
keys are not scope inputs. Other methods are 405 (`Allow: GET`); extra subpaths
are 404; missing write year is 503 `ATTENDANCE_WRITE_YEAR_UNAVAILABLE`.
Authentication/scope denial retains the existing 401/403/409/503 contracts.

Success uses the existing envelope, with these `data` fields:

```json
{
  "context_version": 1,
  "owner_user_id": 3,
  "authorization_version": 7,
  "class_id": 1,
  "academic_year_id": 6,
  "date": "2026-11-05",
  "authorizes_offline_work": false,
  "authorizes_settlement": false
}
```

IDs above are synthetic. Owner/version come from current validated server
authority, not query parameters. Year comes from the SAME `getCurrentAcademicYear`
resolver used by legacy draft/submit stamping (including its legacy `is_current`
fallback), never the displayed roster's fallback year. No roster or student data
is returned. API responses are `no-store`; no business mutation, operation claim,
receipt or authorization lease is created.

A successful description does **not** check calendar/day-off/backdate/future-date,
packet lock, complete roster, snapshot consistency or upload acceptance. A valid
date may describe a day on which writes will be refused. Authorization/year can
change immediately after the read. Never use this endpoint alone to unlock
offline work, overwrite saved operation provenance, backfill unknown work or
settle an outbox item. Existing GET/save/submit contracts and all mobile producers
are unchanged. Full prepared-context binding and exact receipts remain separate
work; see [offline-first ADR](adr/0003-offline-first-mobile.md).
