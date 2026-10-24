# Glossary

Domain, local and technical terms used in the FKSS code and documentation. Code identifiers are given in `backticks`.

## Organisation and naming

| Term | Meaning |
|---|---|
| **FKSS** | Felege Kidusan Sunday School: the organisation, and the product name used in production |
| **`wbws` / `ssms` in identifiers** | Prefixes used in some folder, file, table, CSS-class, test and environment-variable names (for example `wbws_flutter_app`, `wbws_groups`, `.wbws_env.php`, `SSMS_AUDIT_TESTING`). They belong to FKSS; they are **not** separate systems |
| **Department** | An organisational unit with its own role and dashboard: HR, Information, Education, Mezmur, Finance, Material |
| **Maintainers** | The people who approve changes, apply database migrations and deploy to production |

## Calendar and locale

| Term | Meaning |
|---|---|
| **EC (Ethiopian calendar)** | The calendar used for every user-facing date. 13 months: 12 months of 30 days plus Pagume (5 or 6 days). Roughly 7–8 years behind the Gregorian year. Helpers: `admin/backend/ethiopian_date.php`, `backend/core/ethiopian_date.php` |
| **GC (Gregorian calendar)** | Used internally for storage and arithmetic (`DATE` columns). Convert at the edges |
| **EC-only rule** | No Gregorian date may be shown to users or written into exports ([ADR-0008](../adr/0008-ethiopian-calendar-only.md)) |
| **Addis time** | Business dates are evaluated in `Africa/Addis_Ababa` (for example "has this date passed?") |
| **Amharic / Ethiopic** | The working language for names and much of the content (Ethiopic script, Unicode block U+1200 onward). The database and code must be UTF-8 (`utf8mb4`) end to end |

## Members

| Term | Meaning |
|---|---|
| **Member** | A person on the register (`members` table): student or staff. Members have no login |
| **Section / age group** | One concept with three values, defined in `MemberCategory.php`: **A ህጻናት** (Children, 7–13, `7_13`), **B ማዕከላዊያን** (Intermediate, 14–17, `14_17`), **C ወጣቶች** (Youth, 18+, `18_plus`) |
| **Membership tier** | `temporary` or `permanent`. A temporary member is eligible for promotion after 6 months |
| **Registration type** | How the person joined: `waiting` (waiting list), `transfer` (from another Sunday school), `direct` |
| **Member type** | `regular`, `special_regular` (ልዩ መደበኛ), `honorary` |
| **Christian name** | Baptismal name, stored separately from the legal name |
| **Identity code / member code** | `{PREFIX}-{5 digits}`. Students: `A-`, `B-`, `C-`. Staff: a department and position prefix. A smaller `N` marks ordinary (non-head) staff ([`MemberCodeFormat.php`](../../admin/backend/services/MemberCodeFormat.php)) |
| **Promotion (upgrade)** | Converting a temporary member to permanent. Done by HR on the existing record |
| **Archive / restore** | Soft removal of a member by the Information Department; history is kept |
| **Guardian / Family Link** | A parent or guardian record linked to one or more children (`guardians`, `member_guardians`). Matching is typo-tolerant, with a review queue |
| **Address units** | City, sub-city, woreda (district), mender (neighbourhood), block and house number |

## Education

| Term | Meaning |
|---|---|
| **Academic year** | A school year with lifecycle `upcoming` → `active` → `closed`. Activating a year makes it the single current year (`is_current`) |
| **Semester** | Two per year. Can be closed and reopened for corrections |
| **Subject duration** | `SEMESTER_ONLY` (runs in one semester) or `FULL_YEAR` (annual score = average of the two semesters) |
| **Assessment / assessment type** | A test, oral exam, project and so on, with a maximum score and a weight. Each semester's weights sum to at most 100% |
| **Mark list** | The scores of one class for one assessment |
| **Did not take** | A blank mark. Shown on report cards and excluded from the total |
| **Submission packet / slot** | A teacher's submitted mark list or attendance sheet, reviewed by the department. One packet per slot |
| **Review** | The maker-checker step: approve or reject (with reason). Shared state machine: `ReviewTransitionPolicy.php` |
| **Report card** | Per-student totals, averages, rank and attendance (`ReportCardService.php`) |
| **Working day** | A weekday on which a class meets, configured per class in the timetable |
| **Bell schedule / period** | A class's lesson time slots |
| **Makeup class** | An extra dated class outside the weekly grid |
| **Holiday / Day Off** | Planned non-school days, and ad-hoc cancelled days, set by Education (`school_holidays`, `school_day_offs`) |
| **Rollover** | End-of-year operation that carries, promotes or graduates students into the next year |

## Attendance

| Term | Meaning |
|---|---|
| **Class-day attendance** | One attendance record per member per class day (`attendance`) |
| **Lesson attendance** | Per-lesson records for Education (`attendance_sessions`, `attendance_session_records`) |
| **Taker** | A volunteer account that may only record attendance: `attendance_taker` (classes), `mezmur_attendance_taker`, `hr_attendance_taker` (department-owned) |
| **Draft / submit / reopen** | Sheet lifecycle. Drafts merge partial saves; submitted sheets are read-only until reopened |
| **Backdating window** | Attendance may be recorded at most 45 days back and never for the future |
| **Department status** | Whether a member is active or inactive in a department (`member_department_periods`) |
| **Attendance warning** | Raised after N consecutive absences (stages: standard, prolonged, critical) or a low monthly rate |
| **Roll-up** | Pre-computed monthly attendance figures (`attendance_month_rollup`) |

## Mezmur

| Term | Meaning |
|---|---|
| **Mezmur (መዝሙር)** | Hymn; also the hymn ministry department |
| **Zemari (pl. zemarian)** | Hymn singer (`mezmur_zemarians`) |
| **Synced lyrics / karaoke** | Lyrics with timing (LRC-style) that highlight during playback |

## Platform and technical

| Term | Meaning |
|---|---|
| **Admin API** | `admin/api_*.php`: session-authenticated JSON endpoints, usually `?action=` |
| **Shim** | A file in `backend/api/` that only `require`s an admin API file |
| **REST v1** | `api/v1/`: the API used by the Android app |
| **Access / refresh token** | A 15-minute signed token, plus a 90-day rotating refresh token. Custom format, **not JWT** ([API.md](../API.md#2-authentication)) |
| **Authorization version** | A per-user counter, increased when role or access changes, which invalidates tokens and sessions (`users.authorization_version`) |
| **Refresh family** | The chain of rotated refresh tokens. Replaying an old token revokes the whole family |
| **Outbox** | An on-device queue of writes waiting to be sent (`pending_attendance`, `pending_grades`, `comm_outbox`, …) |
| **Idempotency key** | A client-generated key (`Idempotency-Key` header) that lets the server de-duplicate retried writes |
| **Change feed** | Server-side revision log (`sync_changes`) that the app reads to update its local copy |
| **Feature flag** | A per-deployment switch (`FEATURE_*`) checked by `FeatureGate` on web pages, API resources and mobile tiles |
| **Impersonation** | A School Admin or Super Admin opening a department view with that department's role |
| **Danger confirmation** | Tiered confirmation for destructive actions; high-risk actions need a typed phrase checked on the server |
| **Runtime DDL** | Schema changes made by application code at request time (for example `MezmurSchemaReconciler`). Known debt |
| **Secrets file** | `.fkss_env.php`, outside the web root; holds database credentials and keys. Never committed |
| **`main` / `dev`** | `main` = production code. `dev` = `main` plus one commit that points the app at the beta server. Never merge `dev` into `main` ([BRANCHING.md](../../BRANCHING.md)) |
| **Build number** | The integer after `+` in the app version (`1.7.3+40`); used for update and compatibility checks (`X-App-Build`) |
