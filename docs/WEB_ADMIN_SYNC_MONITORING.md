# Web / Admin Sync Monitoring Foundation

## Contract and truth boundary

`api_sync_attempts` is an operational projection, not the idempotency or business-outcome store. It is populated only when an authenticated API write enters the existing idempotency path with a valid `Idempotency-Key` / `client_op_id`. It records:

- validated client operation id and `X-Client-Attempt-Id`, when supplied;
- validated `X-Client-Attempt-Number` and `X-Execution-Source` for the narrow mobile sync write calls that now send them;
- validated fleet context — `X-App-Version`, `X-App-Build`, and (for sync writes from builds that send it) the anonymous `X-Installation-Id` — so failures can be sliced by build and joined to the telemetry device directory;
- server-generated `X-Request-Id`, authenticated user ID, route/domain, safe allow-listed entity references, HTTP status, safe error category/code, idempotency state, retry classification, and timestamps;
- in-flight, completed, failed, rejected, and replayed lifecycle observations.

The table never stores tokens, authorization headers, passwords, secrets, raw request/response bodies, notes, lyrics, or private response content. `installation_id` is server-observed only for sync writes sent by app builds that transmit `X-Installation-Id` (the same anonymous UUID the telemetry channel uses); attempts from older builds record NULL and display as "not observed".

A monitor row that remains in flight is conservative: it is not converted to success merely because the business transaction may have committed. Existing idempotency completion is reused. The Mezmur atomic route path also attempts monitor completion inside the existing transaction; other routes retain the existing post-commit completion hook. A post-commit worker crash can therefore leave an intentionally ambiguous/stale monitoring row, which is visible rather than falsely resolved.

## Supported server-observed endpoint inventory

The monitoring layer is route-agnostic but only sees writes that call the existing idempotency begin hook. The currently supported sync write families are:

| Domain | Server-observed endpoint family | Correlation status |
|---|---|---|
| Attendance | `POST /api/v1/attendance`, `POST /api/v1/attendance/submit` | Supported; mobile sync sends operation ID, attempt ID, attempt number, source. Since 2026-10-07 drafts merge-upsert partial sheets (no more `INCOMPLETE_SHEET` rejections from updated clients) |
| Grades | `POST /api/v1/grades/save`, `POST /api/v1/grades/submit` | Supported; mobile sync sends operation ID, attempt ID, attempt number, source |
| HR attendance | `POST /api/v1/hr/sheet` | **RETIRED (2026-10-07)** — writes answer 410 `HR_ATTENDANCE_RETIRED`; stale-device attempts surface as honest attention rows and decay as the fleet updates; no new writes originate from current builds |
| Mezmur attendance | `POST /api/v1/mezmur/sheet` | Supported; mobile sync sends operation ID, attempt ID, attempt number, source. Since 2026-10-07 drafts merge-upsert partial sheets |
| Mezmur library outbox writes | idempotent `POST /api/v1/mezmur/*` writes that call `apiIdempotencyBegin` | Server-observed operation/idempotency/request correlation; source/attempt number remain **NOT YET SERVER-OBSERVABLE** for those library calls |
| Other authenticated API writes | Any route using `apiIdempotencyBegin` | Server-observed only when a valid idempotency key is present; do not infer mobile origin |

The following are explicitly **NOT YET SERVER-OBSERVABLE** as local mobile state: unsent/pending outbox rows, the next scheduled retry, local lease ownership, local database contents, and whether a client will retry after a retryable response. Installation/device identity is server-observed only on sync writes from builds that send `X-Installation-Id`. The dashboard displays `—` for pending/retrying instead of inventing zeroes.

## Admin read-only surface

The existing `admin/api_telemetry.php` boundary is extended with. The dashboard no longer presents the older aggregate installation-counter sync percentage as the authoritative sync-health card; the server-observed monitor is the single sync-health surface, while fleet telemetry remains available for installation/version/device context.

The read-only admin API exposes:

- `get_sync_overview`: bounded health counts and the 15-minute stale threshold;
- `get_sync_attempts`: bounded filters, fixed newest-first ordering, and page/limit caps;
- `get_sync_attempt`: safe operation detail by numeric row ID.

The existing telemetry authorization model is retained: `super_admin` and `school_admin` sessions may read this dashboard; no new role or parallel admin architecture is introduced. There are no retry, force-complete, delete, or destructive monitor actions.

## Retention

The monitor retains terminal/replayed/rejected rows for 90 days. Cleanup is best-effort, bounded to 5,000 rows per invocation, and excludes every `in_flight` row. The monitor does not run schema creation or cleanup from a business transaction. Apply `sql/059_api_sync_attempts.sql` during deployment.
