# ADR-0003: Offline-first Android app with outboxes, idempotent writes and a change feed

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-08-19 to 2026-10-05 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Teachers record attendance and marks on low-end phones over 2G/3G or with no network. Lost or duplicated records are unacceptable.

## Decision

- Screens render from an on-device SQLite database first.
- Writes go to per-domain **outboxes** and are sent when online, with an `Idempotency-Key`. The server completes the idempotency record inside the business transaction, so a retry returns the original result.
- Outbox rows are bound to their owner and survive re-login.
- The app pulls updates from a server **change feed** (`sync_changes`, revision cursor).

## Consequences

- **Positive:** works without network; no duplicate records from retries.
- **Negative:** high complexity (`local_db.dart` is about 7,500 lines; schema version 37); conflicts and stale local data must be handled per domain; the local database is not encrypted at the application level.

## Evidence

`sql/009_api_idempotency.sql`, `sql/046_message_client_tag.sql`, `sql/057_sync_change_feed.sql`; `api/v1/core/middleware.php`; commits `86fdf15`, `3ed96b7`, `5ac8a1e`; `docs/MOBILE_OFFLINE_SYNC_*.md`.


## Locked-sheet preservation

A cached or freshly fetched packet lock proves workflow status, not receipt of
this phone's exact pending operation. Attendance, Mezmur and grade load paths
must keep their lock/read-only gates **without deleting local pending work**.
Only operation-specific settlement can establish upload acceptance; a locked
sheet must not silently acknowledge an unattempted draft.

The legacy `LocalDb.dropPendingAttendance`, `dropPendingGrades`,
`dropPendingMezmur` and `dropPendingHr` cleanup entry points are deprecated and
have no production callers. They are retained temporarily; their deletion
semantics are not safe automatic lock reconciliation. A source guard prevents
new production callers, and attendance widget tests verify locked responses
preserve the original pending payload and identity.

This is a bounded preservation fix, not a complete offline-first rollout.
Immutable attempted generations, exact business/receipt atomicity, final
reconciliation UX and the approved authorization/logout/device policy remain
separate implementation work. Existing explicit discard paths are unchanged
by this fix. No new database migration, API, role grant or encryption is added.


## Interrupted historical migration files

Before a writable SQLite open, inspect the database directory without following
links for historical plaintext-backup/encrypted-staging files and their
WAL/SHM/journal siblings. If any recognized artifact exists, keep all candidate
files unchanged and stop in the storage-protection screen. Directory inspection
errors also fail closed. A clean first install may create its parent directory;
ordinary main-only databases and their active SQLite WAL/SHM/journal are allowed.

Do not infer format, integrity, freshness or identical operation contents from
a backup filename. There is no automatic backup promotion, main deletion or
staging cleanup. Retry repeats inspection; it does not select or remove a copy.
The screen explains that files were preserved and warns against reinstalling
or clearing app data. This can intentionally block an installation with old
migration artifacts, even when its main database appears usable, until a safe
recovery decision is available.

This guard replaces the unsafe historical self-heal path; it does **not**
implement candidate comparison, full historical recovery, exclusive encrypted
conversion, SQLCipher or lost-key recovery. Those remain separate, tested design
work. Synthetic filesystem tests plus a real SQLite/LocalDb fixture verify that
a newer main database is not replaced by an older backup and that staging-only
survivors cannot authorize a fresh empty database.


## Orphaned ordinary SQLite sidecars

The same pre-open guard also blocks when `-wal`, `-shm` or `-journal` exists
without a regular main database file. A directory or symlink under the main
filename is not accepted as a regular main for this check. Preserve all files;
do not create a new main, pair/reconstruct survivors, checkpoint or delete them.
The file-review screen covers both historical migration and ordinary orphan
sidecar errors, with the same no-clear-data/no-reinstall guidance.

A regular main with its ordinary sidecars remains allowed: SQLite, not this
metadata guard, owns normal crash recovery. Enumeration must finish before
judging main absence because directory order is unspecified. This is not an
exclusive file-lock protocol or a diagnosis of corruption/format/unknown names.
An external writer after inspection remains a separate race boundary.

Synthetic regression evidence includes a committed update isolated in a WAL:
opening a missing main can create an unrelated database and discard that WAL.
Standalone sidecars therefore cannot justify a clean first-install decision.
See [SQLite corruption guidance](https://www.sqlite.org/howtocorrupt.html),
sections 1.2–1.4, for why main and recovery journals must stay together. Actual
recovery of incomplete survivor sets remains separate reviewed work.


## Recovery Retry lifecycle

The storage-protection screen permits one bootstrap attempt at a time and
restores Retry after an attempt completes if the screen is still mounted.
Handled storage failures can replace the root with another instance of the
same widget type and retain its State; a new error detail is not evidence that
the busy flag was reset. Successful root replacement or disposal must not
cause a later setState on the old screen. Unexpected bootstrap errors still
propagate; busy cleanup is not error suppression.

Production Retry still invokes the existing runBootstrap and all storage,
session and lock checks. An injectable bootstrap callback supports isolated
widget lifecycle tests, not a new UI bypass. No automatic retry, file recovery,
discard/reset action, worker or authorization policy is added.


## Attendance capture-session write fence

Manual save, submit and autosave supply an immutable snapshot of the loaded
sheet's owner, authorization version and runtime session generation. This is
capture identity, not an authorization grant. Attendance's internal save API
requires it; no unchecked default is supplied. Class/date/record arguments are
also materialized synchronously before the serialized save can await.

Inside the same SQLite transaction, before any pending-row replacement, reuse
the durable active-session predicate with that expected identity. A mismatch,
inactive session or invalid capture raises a typed stale-save error and leaves
already-durable rows unchanged. A delayed capture is never rebound to a later
active account. The UI explicitly reports that these edits were not saved,
keeps dirty/rendered state when still mounted, and does not report success.

This fence covers attendance only. Other-domain writers, cache-application
fences, immutable attempted generations and account-transition quiescence
remain separate work. Rejecting a stale, uncommitted save does not persist it
for recovery; preservation of such captures across screen disposal still needs
the lifecycle/durability design. No claim of full unsent-work recovery or P2
completion is made. No schema, API, authorization-duration or lock-policy change.


## Attendance download-session fence

The feed coordinator captures owner, authorization version and runtime
generation once per pull, reusing `LegacyWriteSession`. Before requests and
after responses it checks both the runtime identity and durable active session.
`applyAttendanceDelta`, bootstrap reset/completion, and scoped attendance status
writes require that original identity inside the same SQLite transaction before
mutation. Status recording reads its existing cursor inside that transaction.
A stale download raises `StaleAttendanceSyncSession`; it must not write a failure
status into a later account. Error reporting remains best-effort and the running
gate is released on failure. No new queue, schema or server authority is added.

This is not complete preparation or a repaired bootstrap contract. The existing
current-session bootstrap still clears cached sheets and adopts a server cursor;
complete authorized datasets, preservation-first staging and coverage validation
remain release blockers. Direct screen/WarmStore cache writers, read ownership,
inter-isolate cursor ordering and account-transition quiescence remain separate
work. The fence protects against obsolete download mutations, not all cache
races or recovery of uncommitted edits.


## Attempted attendance generation preservation

Attendance saves retain previously attempted or non-pristine generations in the
existing pending table. Only whole pristine pending generations with known IDs,
zero attempts, no attempt time and no ledger evidence may coalesce. Attempt or
non-pristine evidence in any sibling row protects the entire generation. New edits still save a complete generation
with a fresh stable ID. No schema or parallel queue is required.

Attendance claims respect same-owner/class/date insertion order across unresolved
generations, including older authorization versions. An in-flight, backed-off,
paused, review or unknown older operation blocks later sending for that sheet;
other sheets may progress. Exact accepted or resolved-conflict outcomes release
the next generation. This is local ordering, not cross-device conflict authority.

Attendance read projections show the latest complete persisted generation, not
a union of historical member rows. The latest-generation selection includes
synced history so older terminal rows cannot reappear after newer acceptance.
Undo targets only that newest generation and refuses all evidence of a previous
attempt; an unexpected partial undo rolls back. Exact settlement and attempt
closure remain scoped to their original operation ID.

Other packet domains, complete preparation/bootstrap retention, exact server
receipts/partial acceptance, cross-device ordering, workflow-lock reconciliation,
account-transition quiescence and auth/logout/revocation remain separate release
blockers. An older paused authorization generation may intentionally hold newer
sending until authorized reconciliation; no destructive override is introduced.

Attendance eligibility is shared by claims, due-work checks, retry wakeups and
status inventory. Newer blocked generations count as dependencies, not due work;
they cannot cause repeated immediate rescans or earlier useless retry wakeups.
Recovery retry preserves attendance attempt counters/timestamps so the next
transmission uses the next ledger attempt number, not a reused attempt 1.

Seven-day cleanup retains only the newest accepted attendance generation while
older same-owner/authorization/sheet unsynced history needs it as a projection
checkpoint. This also applies while an even newer edit remains unsynced.
Unneeded/intermediate accepted generations follow the existing cleanup rule;
unsynced rows are never removed by cleanup. No new retention period is invented.

Existing destructive discard/reset/logout paths are not approved implementations
of the confirmed no-override policy. Their safe replacement/guarding, legacy
inconsistent-ledger repair and malformed-operation quarantine remain release
blockers. This change neither adds nor authorizes such bypasses.


## Attendance recovery requires exact server disposition

The attendance recovery UI no longer offers destructive Discard or Acknowledge.
Both previously reached the same unsynced-row deletion. A local rejected/review/
resolved-conflict label and explicit user consent are not an exact receipt or a
terminal server-confirmed disposition. LocalDb now rejects attendance manual
recovery deletion with a typed confirmation-required result. The deprecated
natural-key attendance drop fails closed too; there is no force/receipt boolean
bypass. A stale action explains why saved attendance is retained.

This deliberately holds attendance history until protected authoritative
reconciliation exists. It does not implement that receipt/review contract or
claim that the existing upload settlement response proves full acceptance.
Normal settlement and accepted-row cleanup are unchanged. Other-domain recovery,
account reset/logout/private cleanup and revocation remain separate release
blockers; their existing destructive paths are not approved policy by analogy.

## Database-backed attendance success-response atomicity

Attendance draft/submit now build their unchanged success envelope and complete
an acquired database-backed replay reservation inside the existing business
transaction, before commit. The wire sender shares envelope/JSON encoding. A
failed completion/encoding raises a typed precommit failure; rollback must
succeed before the processing reservation can be abandoned. The ordinary
response sender is detached BEFORE rollback so even an outer exception-handler
500 cannot replace an uncertain outcome. The resulting fixed 503 is retryable, not stored
as a permanent replay. An unconfirmed rollback does not abandon anything.
Unknown commit outcomes never use this precommit cleanup path.

Existing unkeyed and known file-backed legacy compatibility behavior remains;
it is NOT strict response atomicity. Other endpoints and the permissive generic
helper are unchanged. This is not the full exact receipt contract: replay expiry
is still seven days, authenticated long-lived status lookup/manifest binding,
capability negotiation, audit delivery, cross-device ordering and installed
transactional-schema verification remain release blockers. No schema, TTL,
permission, URL, mobile settlement or rollout policy changes are implied.

The strict attendance path invokes the existing replay service directly, not
the permissive wrapper that also runs advisory monitor SQL. Monitoring remains
in the ordinary postcommit sender. An advisory deadlock must not silently roll
back business/receipt writes after their final checkpoint; a lost response may
leave stale advisory monitoring, but cannot invalidate the committed receipt.

## Read-only attendance replay-cache inspection (development only)

`POST /api/v1/attendance/operation-status?kind=draft|submit` inspects the existing
shared database replay cache. Send the unchanged original JSON and original
operation key (header or `client_op_id`; conflicting keys are rejected). JSON
requests are bounded to 1 MiB for this diagnostic endpoint only; larger original
uploads cannot use this diagnostic and must remain unresolved. Existing upload
limits and clients are unchanged. Hashing uses the existing canonical request
helper with the ORIGINAL `POST attendance` or `POST attendance/submit` scope,
not the lookup URL. The signed current user supplies the owner identity.

Current account/role/authorization-version checks are mandatory even for clients
outside the legacy authorization rollout cohort. The shared scope verifier has
an optional server-only current-scope requirement; its existing callers retain
their compatibility behavior. Current attendance role, class assignment and
academic-year access must pass. This is not a historical/revoked-scope access
exception. Inspection does not rerun roster, class-day or backdate WRITE rules.

Every result identifies version 1, authority `legacy_replay_cache`, `durable:
false` and `authorizes_settlement: false`. States are `unknown`, `processing`,
`payload_mismatch`, or `completed` (only `cached_http_status` is exposed).
Unavailable backend/schema produces fixed 503 `OPERATION_STATUS_UNAVAILABLE`
with the same non-settlement flags. No cached response, request hash, lease or
owner token is disclosed. A completed cached 500 is not a business rejection.

The service performs one indexed SELECT. It never claims work, recovers an
expired lease, extends expiry, sweeps/deletes rows, reads legacy/file caches,
creates schema or invokes attendance mutations. Uncommitted completion is not
visible from another connection. Expired completion is `unknown`, NOT evidence
that nothing committed; an expired processing lease is still `processing`
while its cache record is unexpired. All observations preserve unsent work and
provide neither retry authority nor permission for cleanup/logout/settlement.

This endpoint is deliberately not wired into mobile settlement. Durable receipt
lifetime, manifest coverage, negotiation, safe reconciliation, immediate
access/refresh lost-device revocation and installed-schema/device evidence
remain separate blockers. This is development-source evidence, not deployment
or full P5 acceptance.

## Negative durable-receipt negotiation (development only)

`GET /api/v1/attendance/capabilities` is an authenticated, current-scope,
attendance-role-only protocol declaration: version 1,
`durable_receipt_versions: []`, `durable: false`,
`authorizes_settlement: false`. It exposes no roster, assignments, backend
status or installed-schema readiness. It is not a complete offline-readiness
capability and does not claim strict receipts are available. GET only; no
nested paths; no-store. Existing global OPTIONS preflight remains unchanged.

An attendance draft/submit POST carrying **any present**
`X-Attendance-Receipt-Version` header fails with 412
`ATTENDANCE_RECEIPT_CONTRACT_UNSUPPORTED`, the same negative capability flags
and no-store. Empty, unknown and future versions also fail. Current account,
role and authorization scope are checked even outside the legacy rollout
cohort. The precondition guard precedes body parsing, reservation/replay,
write-rate accounting, workflow and attendance mutations. Even an existing
legacy success cache entry is not replayed to satisfy this header.
The header is permitted by CORS without expanding origin/credential policy.
Header-free legacy writes/replays keep their prior behavior and envelopes.

This is server-side **negative negotiation infrastructure**, not implementation
of any durable receipt version or a mobile handshake. No existing client is
switched onto it. Future clients must discover an explicitly supported known
version before transmitting strict operations: old servers may ignore unknown
headers and commit without receipts. A ping, successful login, cached
capability, old replay, or mixed-server rollout is not adequate compatible
receipt proof. The 412 describes this attempt's unsupported precondition, not
a terminal business disposition of this operation or proof that earlier
attempts did not commit. Preserve all unresolved work; never use these flags
or responses to authorize settlement, cleanup, retry or logout.

No receipt schema, lifetime/compaction, conflict arbitration, mobile settlement,
revocation exception, owner signing or production deployment is enabled here.
Those contracts and their positive end-to-end acceptance tests remain required.

## Mobile non-settlement response guard

ApiResponse preserves typed `nonSettlement` evidence from the response root,
normalized data, or the known `lookup`/`capabilities` protocol containers.
Presence of `authorizes_settlement` (including true or malformed values),
`durable_receipt_versions`, or authority `legacy_replay_cache` is not validated
operation receipt proof. Arbitrary member/record payloads are not recursively
searched; ordinary `durable:false` alone does not change legacy behavior.
Generation/refresh response copies retain the evidence. The unsupported receipt
contract code is independently recognized regardless of HTTP success/status.

The existing outbox policy holds this evidence as needs-attention, never as
accepted, successful replay or resolved conflict, and does not consume five
ordinary unknown retries. Session supersession and definitive authentication/
scope outcomes retain their precedence. A transient refresh result cannot turn
non-settlement evidence into retry authority. The attempt ledger describes held
observations as protocol errors/user action required, not successful execution.
Saved payloads remain owned by their exact operation and generation; newer
local edits are not erased. Recovery/cleanup permissions are unchanged.

The worker explains that server confirmation is incomplete and saved work is
kept. Passes containing needs-attention work no longer promise automatic retry;
they direct the user to Sync Center, which retains its confirmation-required
attendance deletion guard. No manual acknowledgement/discard is enabled.

This is a negative-evidence guard, not positive receipt validation: marker-free
legacy upload acceptance remains unchanged. No new endpoint call, strict upload
header, capability handshake, durable receipt version, schema migration,
release activation or production deployment is introduced. Exact manifests,
long-lived atomic receipt storage, positive receipt validation and full safe
reconciliation remain release blockers. Fake transport plus real coordinator/
SQLite regressions prove this boundary, not installed-phone crash recovery.

## Exact attendance request-manifest codec (internal, not activated)

`AttendanceOperationManifest` in PHP and Dart is a pure, versioned identity
building block. Neither existing uploads nor outbox workers call it. Strict
receipt versions remain unsupported; the diagnostic cache and legacy request
hash are unchanged. A fingerprint is NOT acceptance, retry authority, a roster
snapshot, authorization, encryption, a durable receipt or permission to settle,
clean up, switch accounts or log out.

The canonical input has exactly `owner_user_id`, `client_op_id`, `packet_kind`,
`class_id`, `academic_year_id`, `date`, and `records`. Each record has exactly
`member_id`, `status`, `notes`. Extra or missing fields, including the ambiguous
legacy `note` alias, fail closed. Packet kind is exactly `draft` or `submit`;
a future legacy adapter must explicitly map `submitted`, not guess it. IDs are
actual positive integers at most 9007199254740991, not strings, booleans or
floats. Operation keys are 1–80 ASCII letters/digits/period/underscore/hyphen,
without trimming. Date is exact Gregorian YYYY-MM-DD, years 0001–9999. Original
class, year and date are mandatory, never today's replacements. Existing legacy
claim snapshots lack original academic-year provenance: they cannot be silently
upgraded to this contract. Capturing that provenance atomically in the existing
local intent remains separate future work, not a second queue.

Records number 1–2000, with unique member IDs and exact lowercase status
`present`, `absent`, `late`, or `excused`. Notes are valid Unicode strings of at
most 500 scalar values. Invalid UTF-8 and unpaired UTF-16 surrogates are rejected,
not replaced. No trimming, newline conversion or Unicode normalization occurs;
NUL, CRLF, non-BMP text and distinct combining forms retain their exact identity.
These are internal codec constraints aligned with the current attendance
service's limits, NOT changes to its legacy coercion/normalization rules.
Partial drafts may be hashed; submit completeness and date/roster/permission
rules are still the authoritative server's responsibility.

Each frame is the UTF-8 netstring `ASCII decimal byte length:bytes,`, with no
leading zeros. Records are sorted by NUMERIC member ID without changing the
caller's input. All integers are canonical unsigned decimal; hashes are
lowercase hexadecimal SHA-256. The exact frame sequences are:

1. Records: `ssms.attendance.records.v1`, record count, then member ID, exact
   status, exact notes for each sorted record.
2. Manifest: `ssms.attendance.manifest.v1`, owner ID, operation key, packet kind,
   original class ID, original academic-year ID, original date, record count,
   records SHA-256 (64 lowercase ASCII hex characters, not 32 binary bytes).

Hashing is incremental, without assembling a full encoded preimage. Validation
and sorting are bounded to 2000 records / 500 note scalars per record (at most
4,000,000 note UTF-8 bytes). There is no I/O or clock/session access. JSON/map
field ordering, retries, connection headers, current authority and mutable
attempt metadata do not enter the digest. Such metadata must never become
unhashed accepted request fields; a changed semantic contract needs a new
version/domain separator, not silent extension of v1.

The result is `manifest_version: 1`, `domain: attendance`, original owner/key/
kind/class/year/date, `record_count`, `records_sha256`, `manifest_sha256`. It
contains no raw roster, marks or notes, nor a positive settlement flag. Dart
returns an immutable map of scalar values; PHP returns a value array. Context
and digests remain sensitive metadata: hashing is not anonymization and does
not permit public logging/disclosure.

This binds the ORIGINAL canonical request DTO, not normalized business rows.
Future server integration must bind that original identity before legacy note
trimming or other permitted effect normalization; never recompute the original
request fingerprint from normalized rows to manufacture a match. Receipt
storage, atomic terminal outcome, retention, normalized-effects evidence,
authoritative conflict handling and positive client validation are NOT supplied
here. Owner ID in this pure DTO is not trusted authentication: a future handler
must bind it to the signed current owner and original operation, and preserve
all unresolved legacy work whose provenance cannot be proven.

Shared fixtures contain independently Python-generated expected netstring bytes
and SHA-256 vectors, executed against both real codecs. They include numeric
ordering, draft/submit, Unicode/delimiters, bounds and a full 2000-record / 500
non-BMP-scalar notes case. Malformed/missing context and records fail closed.
Synthetic unit and backend tests are not deployed-schema, phone, cold-restart,
receipt-lifecycle or production acceptance evidence.

## Original attendance year storage (local schema 38; producer not activated)

`pending_attendance.original_academic_year_id` is nullable INTEGER metadata on
existing operation rows, not a second outbox. The repeat-safe migration runs
on create and upgrade, never backfills a year or rewrites/deletes old work.
Unexpected existing column shape or SQL failure propagates; it cannot trigger
a database replacement or authorize a reset. Old unknown provenance stays NULL.
As with other forward schema changes, do not downgrade an installed database
or restore an older copy to make an older app open it.

`saveAttendanceLocal` accepts an optional explicit `originalAcademicYearId`.
A supplied value must be an actual positive integer within the manifest's
portable exact-ID bound. Metadata and marks commit in the existing replacement
transaction under the original owner/session fence. Never-attempted generations
may coalesce only with the SAME original year, including NULL-to-NULL. Different
known years, or known/unknown provenance, retain the complete older generation.
A mixed-provenance sibling set is never partially deleted. Existing protections
for attempted, held and in-flight work remain unchanged.

The immutable operation reference exposes the original year; a claimed packet
exposes it only if every captured row agrees on a valid integer. Empty, missing,
invalid or mixed provenance is unknown, never selected from the first row or
coerced from a string. This projection does not change legacy claim/upload
eligibility and is not authorization or receipt evidence. Undo/retry retain the
stored provenance; later edits cannot change an already captured snapshot.

IMPORTANT: this package does not supply a year from the current UI, login,
calendar, cache or HTTP response. GET attendance's existing `roster_year_id`
can be a PREVIOUS-year roster fallback; it is not proven original WRITE scope.
No existing producer passes the new optional argument. Therefore existing user
workflows continue to store unknown provenance, not a manufactured strict-ready
operation. Trusted server-attested write-scope capture and complete durable
prepared snapshots, including protection against relabeling pre-refresh edits,
remain a separate integration step. Keep unknown legacy work recoverable.

No wire body/header, server schema/API, manifest construction, strict receipt
support, positive settlement, account/logout permission, cleanup override or
production deployment is activated. Tests execute the real migration and
LocalDb with synthetic SQLite data, including actual minimal-v37 open/upgrade,
rollback, committed-state reading, different-year preservation and immutable
claims. These do not establish deployed-schema or installed-phone acceptance.


## Server write-context discovery (development; no mobile activation)

The additive [API discovery contract](../API.md#attendance-write-context-discovery-development)
separates the server write year from `roster_year_id`. The read requires current
server scope even for legacy builds, the attendance role and current class
assignment; owner/version cannot be supplied by a client. It returns minimal
point-in-time owner/version/class/year/date dimensions and explicit false flags
for offline work and settlement. It creates neither a lease nor a complete
prepared snapshot. It performs no roster query, write or operation claim.

The discovery response is NOT yet an original operation context. A future
producer must bind a complete consistent authorized snapshot to its owner,
version, class, date and write year BEFORE editing; store the captured original
year atomically with the marks; and retain that original on retry, rollover,
roster refresh, navigation and authorization changes. Re-fetching discovery must
never relabel earlier saved work. Unknown legacy years remain unknown. Current
authority/calendar/workflow must be checked again at upload; historical scope
reconciliation requires server rules and durable exact receipts, not client
substitution of today's year. Rolling 30-day offline authorization, complete
preparation, revocation and account/logout rules are not implemented by this read.

Synthetic route tests execute the real adapter with boundary doubles. Separate
isolated HTTP tests exercise actual authorization/class checks, including real
assignment-version invalidation and a previous-roster-year fallback. These are
not hosted deployment, phone, server-race or offline acceptance evidence.

## Atomic shared roster-cache refresh (preparation prerequisite only)

`LocalDb.cacheStudents` captures incoming cache rows before its first await and
replaces one class's roster in a single SQLite transaction. The delete and all
inserts either commit together or roll back together; a late SQL/duplicate/input
failure must not erase the previous complete roster. Successful empty responses
retain their existing meaning and replace only that class. The legacy ID alias,
field defaults and all three established callers remain unchanged.

This fixes cache-integrity failure, not authorized preparation: rows are not a
lease, scope-bound snapshot, original-year proof, complete roster-plus-sheet
commit or offline-readiness grant. Server consistency and atomic owner/context/
calendar/sheet binding remain required before new offline activation. Synthetic
real-SQLite tests cover rollback and an independent committed-state reader, not
process-death or owner-phone acceptance. No schema or wire contract changes.

## Atomic attendance read-cache pair and durable session fence

Online attendance refresh now calls `LocalDb.cacheAttendanceSnapshot` instead
of independently committing its roster and class/day sheet. The new method
captures JSON and roster scalars before its first await, then checks the
captured owner, authorization version, runtime generation and active durable
session inside the same SQLite transaction that replaces both existing caches.
A failed roster or sheet write rolls both back. Other classes/dates and pending
attendance are not deleted. Old standalone cache APIs remain for compatibility.

The screen reports cache-storage refusal without clearing edits or pending work.
A durable-session refusal blocks further editing through the existing access
gate; a superseded callback cannot update a newer view. This is not a new server
snapshot, lease, original-year producer, receipt or offline-readiness grant.
Legacy UI publication timing and other consumers' independent cache writes do
not establish complete authorized preparation. Server-consistent datasets and
atomic calendar/context/readiness publication remain separate work.

## Strict minimal preparation roster reads (opt-in primitives)

`EnrollmentService.resolvePreparationRosterYear` preserves the existing
preferred-year / unscoped / rollover-fallback rule but throws on query failure
instead of interpreting it as zero. Its preferred write year remains separate
from the resolved roster year. `fetchPreparationRoster` reads only the member
identity, student/father names and member code used by attendance UI/QR, under
that explicit server-resolved scope and date. Invalid, orphaned or duplicate
identities fail rather than silently produce an apparently complete roster.

The opt-in strict argument to `DepartmentStatusService.exclusionSql` requires
an explicit valid date and probes all eligibility columns when attendance v2
is enabled. It does not trust the legacy permissive availability cache. Feature
OFF remains OFF; all existing callers retain the old default behavior. Both
mysqli reporting modes fail closed in the new read primitives, with no slim
query or error-to-empty fallback. A genuinely empty successful query is valid.

These helpers do not authenticate, grant offline work, own/commit transactions,
or activate a route. Their future preparation caller must bind current authority,
class access, write year, strict calendar and workflow facts inside one consistent
read transaction. Server snapshot publication and atomic mobile calendar/context/
readiness binding remain unfinished. No schema, lease or policy activation.

## Strict calendar preparation read (opt-in primitive)

`SchoolCalendarService.preparationCalendar` reuses the established calendar
business-rule evaluator, but verifies every required read column and rejects
SQL/schema failures, mismatched captured write year, duplicate class schedules,
invalid working-day tokens and incomplete/unsupported class/day coverage. It
accepts an explicit unique server-authorized class list and a bounded existing
mobile-calendar span; it does not select or broaden permission scope.

The strict read resets connection/snapshot-unbound caches before and afterward.
Only after actual schema probes succeed are readiness caches seeded for that
read. The method scopes and restores mysqli reporting mode and strict-read state
in `finally`; makeup read errors cannot become partial range-cache success.
Legacy callers keep existing missing-schema/deployment behavior and calendar
precedence. Constants, calendar IDs/assets/colors and policies are unchanged.

This primitive does not authenticate, grant a lease, activate a route, publish
mobile readiness or own/commit a transaction. The future preparation caller must
verify transactional schema/engines and bind current authority, class scope,
write year, roster, calendar and strict workflow facts in one consistent read
transaction. Those endpoint/publication/readiness obligations remain unfinished.

## Consistent preparation storage boundary (opt-in prerequisite)

`AttendancePreparationReadBoundary.read` owns a new READ ONLY REPEATABLE READ
consistent snapshot. It rejects autocommit-off and active outer transactions
before BEGIN, without committing or rolling back caller-owned work. Isolation
is set for the next transaction only; session defaults/reporting mode are not
changed. An uncertain BEGIN after configuration discards the connection rather
than leaking pending transaction characteristics. Commit must succeed before a
value is returned; reader/storage failures require confirmed rollback, otherwise
the connection is discarded.

Every declared dependency is touched to hold its metadata lock until completion,
then checked as a real InnoDB base table. SHOW CREATE inspects the actual resolved
object as well as information_schema: views, absent/nontransactional tables and
both InnoDB/MyISAM temporary shadows are refused. Real two-connection synthetic
fixtures show unbounded split reads, coherent reads despite concurrent commits,
read-only write refusal, preservation of caller-owned transactions, and a real
concurrent ALTER lock timeout followed by success after completion. BEGIN,
COMMIT and failed-rollback return/exception boundaries are supplemental doubles,
not claimed network-failure experiments. Fixtures create only fresh randomized
owned synthetic objects and clean up only successfully created objects.

This is not a SQL sandbox, authorization, completeness validator, endpoint,
lease or mobile readiness. Trusted callbacks must declare EVERY dependency, use
strict readers, and not manage transactions/DDL. The next endpoint still needs
current authority/class/write-year validation and strict roster/calendar/
workflow/origin facts in this boundary, then atomic mobile publication/fencing.
The owner's confirmed rule is closed years read-only, without a new correction
override; older queued work keeps its original year for audited server decision,
never restamping into the active year. Phase1 and all eight workstreams remain
incomplete. No legacy caller, schema, deployment flag or production is changed.

## Signed access session/family binding for future preparation

New login and refresh responses bind the signed access payload to both the
persistent refresh family (`fid`, stable across rotation) and its issuing refresh
session (`rsid`). Binding is extracted only from this server's valid signed,
owner-matching tracked refresh token. Existing six-argument token helper calls
and unbound-token compatibility remain distinct; unbound tokens do not establish
a family/device preparation grant.

Every bound access request checks its issuing session regardless of client build
header or chosen legacy URL. The existing migration010 primary key provides a
point lookup; a real EXPLAIN of the exact production SQL verifies PRIMARY/one-row
access instead of scanning accumulated family rotation history. Owner, family,
issuing session, revocation and expiry must match. A consumed refresh session is
not revoked: legitimate rotation must not falsely invalidate still-unexpired
access. Family revocation marks all sessions, including consumed ancestors.
Partial/malformed signed binding cannot fall back to unbound compatibility.
Query/schema failure is explicit503, not authorization or false revoked state.

Guarded synthetic TEMP SQL executes actual login/refresh/logout route branches,
refresh rotation/replay/revocation and access authentication in both mysqli modes.
Transport body, rate limit, audit utility and response adapters are explicitly
doubled; this is not HTTP/phone proof. No token values are printed. Real logout
happy path and replay revoke bound access before its natural expiry, independent
families survive, and build-header changes cannot bypass binding.

This prerequisite does NOT complete preparation or lost-device workstream6.
Pre-write authentication alone does not serialize mutation against revocation;
new mutators still need strict checks/locks in their mutation transaction. Failed
logout revocation acknowledgment, lost-device management, old unbound cohort
transition and real deployed/device acceptance remain unfinished. Family/session
identifiers are not hardware identity or encryption, and no offline grant,
preparation endpoint, readiness, mobile producer or schema is activated here.

## Strict normalized multi-date roster preparation (opt-in)

`EnrollmentService.fetchPreparationRosterDates` takes explicit server-resolved
class/roster-year/date scope and returns member names/father/code once plus exact
ordered member-ID membership for every requested date, including complete empty
dates. Roster fallback remains distinct from original attendance write year.
Eligible duplicate/orphan/invalid identities and incomplete member coverage fail;
there is no silent deduplication or member/global-status policy change.

Department eligibility uses the SAME existing next-day predicate through one
private builder behind the unchanged public legacy signature. The strict batch
compiler probes enabled eligibility columns once per call without a static cache
or feature override. Feature OFF stays OFF. SQL date literals and identity lists
are validated; class/year scope is bound. Query construction is capped at the
existing106-date calendar budget, not a new school permission/window policy.
The strict query helper supports checked zero-parameter identity reads and still
requires matching parameter counts and checked binds for parameterized reads.

Real synthetic TEMP SQL compares all106 dates with the unchanged actual strict
single-date reader:212 prepared statements with eligibility ON/106 OFF versus
3/2 for the normalized batch, including one/zero eligibility probes. These are
forwarded real prepared-SQL statement counts, not a claim about network latency,
large-school performance or phones. One/four/full-span cases have the same
bounded count. Tests cover independent next-day/reactivation boundary expectations,
DB name/id ordering, minimal fields, fallback/unscoped years, complete empty,
invalid/oversized scope, duplicate/orphan identities, missing member/eligibility
columns, failure recovery and both mysqli modes. Instrumentation forwards actual
SQL unchanged; no fabricated results or SQL-error doubles in this fixture.

No route/grant/readiness/producer or transaction ownership is added. The eventual
endpoint still must authorize ALL requested server-defined scope and bind current
authority, write year, calendar, workflow/lesson/origin facts in the consistent
storage boundary, then atomic mobile publication/fencing. A normalized roster is
not a complete authorized preparation or offline lease. Closed years remain
read-only; original unknown/older work is preserved, never restamped. Phase1 and
all eight workstreams remain incomplete.

### Strict workflow and original-origin facts (opt-in, no activation)

`AttendancePreparationWorkflow` reads explicit bounded class/date facts without
legacy failure-to-empty behavior. It exposes minimal saved marks/notes and their
actual nullable original academic year, all attendance-packet origins, latest-id
packet workflow and feature-enabled submitted lesson locks/origins. Unknown/zero
years remain unknown; different original years remain distinct. Neither date nor
current write/roster year repairs an old origin. All origins must be known and
match the separately supplied write year for `origin_matches_write_year`; an
empty day is an explicit complete empty, not a guessed older context.

Known workflow normalization/open rules reuse `SubmissionService`; unknown latest
statuses are explicitly unsupported and never gain permission through its legacy
normalization-to-incomplete fallback. Latest-id workflow does not hide foreign or
unknown older packet origins. The helper makes no staff/closed-year override.
Feature OFF does not read optional lesson tables; ON requires checked record
schema and session data, rejects unsupported session statuses, ignores cancelled
sessions and marks submitted sessions as derived. Its dependency list includes
every table read, to be declared by the full endpoint's storage boundary.

Actual guarded TEMP SQL covers both feature/reporting modes, successful empty,
106-date bounded reads, latest/earlier packets, shared known-status parity with
real unchanged legacy helpers, unknown/mixed/foreign origins, duplicate/invalid
rows, real missing columns and recovery. It also observes actual unchanged
legacy helper behavior: unknown status can normalize open, and the staff helper
alone does not check foreign original year. These are helper observations, not
HTTP acceptance or a claim that existing routes are fixed. No production data,
route, grant, migration, durable receipt, mobile publication or readiness change.
The eventual endpoint must authorize inside one coherent boundary, apply active
write-year/calendar and closed-year read-only policy, retain protected old work,
and never treat these independent facts as capture permission. Phase1/all8 remain
incomplete until full endpoint and atomic mobile publication/fencing are tested.

### Complete server-owned preparation snapshot and opaque provenance (v1)

GET `/attendance/preparation` accepts no client scope dimensions. It requires
signed family-bound access and current attendance role; every success re-reads
real users.authorization_version/account/role, issuing-session liveness, unique
active write year with supported current bounds, exact existing class ACL,
strict normalized rosters, full bounded calendar, workflow/lesson locks and all
original-origin facts inside one owned verified InnoDB read-only repeatable-read
boundary. No compatibility AV fallback, request-static year memo or closed
is_current fallback may certify this snapshot. Restricted class scope preserves
the existing active-assignment predicate (including existing class-active
behavior); privileged scope remains active classes. No client owner/year/class,
Host or Origin selects scope or identity. True empty authorized scope is complete;
unsupported/failed facts cannot produce a partial success. Saved off-roster marks
remain present and block new capture rather than being silently discarded.

Configured SITE_URL must provide a supported canonical HTTPS identity, not its
localhost fallback. A completed payload has one random preparation ID, original
server authorization timestamp and at-most30-day deadline also bounded by the
original active-year end. Authentication/refresh/ping/304 cannot renew it. Scope
is a coherent point-in-time snapshot, not a promise that no authority can change
after that point; its timestamp starts before the read, never after a slow read.
Known snapshot authority denial stays typed even if cleanup separately fails;
the boundary still discards an uncertain owned connection. Every denial/error is
no-store, old work is not modified, and a new success does not rebind old work.

The response carries exact base64url UTF8 dataset bytes and a domain-separated
server HMAC root binding their SHA256 to server/owner/role/AV/stable family,
preparation ID and original lease. Each day has a separately domain-separated
opaque context token binding its original write year separately from roster
fallback, class/date, roster/base/workflow digests, calendar code and bounded
opening/closing timestamps. Same signing secret cannot interchange an access,
root or day token. Future coverage is not early capture; days beyond the original
lease have no capture eligibility. Calendar/known workflow/lesson/origin/roster
checks apply together. Staff's existing supported workflow override never defeats
foreign/unknown/closed original-year restrictions. Complete empty remains valid
data without inventing a nonempty attendance save. No receipt or settlement is
authorized by preparation. New prepared tokens are not submitted through legacy
writes; durable receipt negotiation remains negative until that implementation.

Opaque HMAC is server-verifiable provenance, NOT a client public verification
key or encryption. Client trust requires authenticated HTTPS, expected configured
server identity, strict full parsing and Keystore-protected local integrity of
the exact payload/grant publication. No server secret goes to the client. The
server inspect helper checks cryptographic provenance only; it is NOT current
authorization/expiry/upload permission. Later uploads must enforce current
scope/session/workflow and exact immutable intent/receipt semantics; old expired
provenance remains useful for audited resolution, not automatic write/purge.

Guarded native integration creates ONLY a fresh randomized owned schema on the
isolated synthetic service, registers ownership only after successful CREATE,
uses actual persistent InnoDB tables/metadata and drops only that owned schema.
Synthetic root credentials are fixture-only, never school config or application
privilege acceptance. The real boundary/readers/core auth/attendance route and
HMAC run; only response delivery is adapted, not HTTP transport. A real atomic
second-connection commit changes authority, assignments, year, roster identity,
calendar and workflow during the read; the snapshot remains one old version and
the next call denies stale authority. Real missing columns, nontransactional
storage, temporary shadows and existing outer transactions fail. Metrics are tiny
synthetic SQL/byte/timing observations, not production/device performance. Source
and CI do not establish installed schema/binary equality or deployment readiness.

This completes only the server preparation path after its checked merge. Mobile
atomic protected publication, runtime/owner/session fencing and actual positive
readiness remain Phase1 obligations; Phase1 and all8 workstreams are NOT complete.
No deployment, mobile activation, durable upload, encryption, owner signing or
phone acceptance is claimed.

### Inactive mobile preparation transport consistency boundary

The owner supplied the nine-phase offline roadmap and confirmed three policies:
changed assignment/member eligibility work is preserved for administrator review;
multi-device conflicts require review without silent overwrite; a 30-day offline
capture lease renews only after a successful complete authorized-data refresh.
[Offline acceptance targets](../OFFLINE_ACCEPTANCE.md) record the engineering
scenarios and remaining release gates. These decisions do not activate a new
review inbox, grant old permissions or complete the broader campaign audit.

`AttendancePreparationEnvelope` is an unused pure Dart transport decoder. It
accepts the API data object, an independently captured server/owner/role/AV/family
scope and an explicit caller resource budget. It rejects malformed/noncanonical
base64url, invalid UTF8/JSON/object shapes, unsupported envelope/root fields,
version/settlement markers, inconsistent root/payload scope, foreign captured
scope, invalid integer identities/lease bounds and exact-byte digest mismatch.
It retains immutable original payload text/base64url, opaque grant and digest;
JSON re-encoding is never used to verify the payload hash. Exceeding a resource
budget fails the entire decode rather than truncating a dataset.

This is transport consistency, NOT server HMAC verification, full dataset
validation, freshness/expiry enforcement, current authority, capture eligibility,
publication, encryption, readiness or settlement. A well-formed forged MAC is
intentionally not authenticated by this class; it has no server signing key.
Its eventual adapter must authenticate configured HTTPS, validate all dataset
coverage/facts, protect exact bytes locally and fence publication against current
runtime/durable session and generation. No current app code imports this decoder.
No HTTP client, schema/migration, local storage, UI, worker, legacy write or
signing/dependency policy changes in this slice.

A synthetic complete-empty transport fixture was generated by the actual PHP
issuer with a public fixture-only key. PHP verifies its root provenance, exact
payload hash and reissued facts; this does not make that minimal transport fixture
a full dataset vector. Dart tests cover exact Unicode/whitespace bytes,
immutability, corruption, canonical encoding, resource limits, missing fields,
foreign scope, root/payload disagreement, lease shapes and the explicit absence
of MAC authentication. Local Flutter 3.44.9/Dart 3.12.2:56 focused tests passed;
all1228 Flutter tests passed sequentially without skipped tests. The first
parallel full-suite attempt stalled and was stopped; it is not a passing run.
PHP8.4:2 shared-fixture/inactive-boundary tests passed; targeted Dart analysis
reported no issues. Native phone, HTTP, large-dataset performance, protected
publication and all-department acceptance remain unverified. Phase1/all8 and the
nine-phase roadmap remain incomplete. No production deployment is authorized.

### Inactive full preparation dataset validation (pure Dart, no publication)

`AttendancePreparationDataset` is an unused pure Dart validator that takes the exact payload UTF-8 text preserved by the transport envelope and checks every server-producible structural fact: top-level version/complete/owner/AV/role/family/server-identity/lease/window, write-year bounds, lessons flag, member list (unique sorted IDs, bounded strings), class list (unique IDs, write-year binding, per-day coverage equal to from..to window, strictly ascending dates, member-id uniqueness, calendar codes o/m/n/c/d/h/x, base-roster flag, records/packet/origin/capture/lease/context-token coherence), packet/record shapes, and school-calendar map. It rejects any unknown, missing, duplicate, mistyped, out-of-range or incoherent field with a FormatException and never returns a partial model. It does not verify the server HMAC, enforce current authority/freshness, grant capture permission, publish to storage or decide readiness; a well-formed forged day token still passes shape checks by design.

Context token shape is checked for canonical base64url and hex MAC, not signature; capture_eligible is only checked for calendar-code and non-empty roster coherence, not re-derived business logic. This is intentional: future eligibility still belongs to the server and to capture-time checks. No current app code imports this validator. No HTTP client, schema/migration, local storage, UI, worker or signing change in this slice.

A synthetic full payload fixture (2 members, 1 class, 3 days covering present/packet/unknown-origin cases and a 30-day lease) exercises the validator. Dart tests cover the valid fixture, Amharic preservation, all required top/member/class/day/record/packet/school-calendar shapes, duplicate/ordering/missing/extra/invalid leases, window budget, calendar-code rules, record uniqueness, lease bounds, context-token shape and the explicit absence of MAC verification. Local Flutter 3.44.9/Dart 3.12.2: 42 focused tests passed; all 1270 Flutter tests passed sequentially without skips; targeted Dart analysis reported only low-severity warnings (existing unrelated cast) and no errors. Python fixture/inactive-boundary tests passed. No production deployment, mobile publication, protected storage or phone acceptance is claimed. Phase1/all8 and the nine-phase roadmap remain incomplete until atomic protected publication/fencing and truthful readiness are implemented and device-verified.

### Durable preparation snapshot publication with generation fencing (v39, inactive)

`attendance_preparation_snapshot` (id=1, schema v39) stores the last successfully published complete preparation with its exact payload JSON, SHA, owner/AV, server identity, lease and write-year, plus the publishing generation. `AttendancePreparationStore.publishPreparation` validates envelope/dataset coherence (preparation_id/lease/owner+AV) and then in one SQLite transaction checks the current `local_session_state` generation/owner/AV against the supplied `LegacyWriteSession`; stale cross-account/session/generation callbacks throw `StaleLegacyWriteSession` and leave the previous snapshot intact. A second validated publish atomically replaces the row with a new timestamp; failed validation or stale fencing preserves the old snapshot (keep-old, no lease extension per owner policy). Corrupt stored JSON is treated as absent on read, not as a crash. `clearSnapshot` is similarly fenced. The store is inactive: no existing app code calls it, no HTTP client or UI is added, and the migration is additive (v39 creates the table on fresh installs and via `onUpgrade` for existing devices). Existing pending-work and session tables are untouched. Local Flutter 3.44.9/Dart 3.12.2: 6 new store tests passed plus all 1276 Flutter tests passed; targeted analysis clean. No deployment, readiness or encryption claim. Phase1/all8 remain incomplete.

### Truthful preparation readiness gate (inactive, v39)

`AttendancePreparationReadiness.evaluate(snapshot, expectedSession, now)` is a pure evaluator for the durable snapshot: returns `isReady` with a single `AttendanceReadinessReason` — `missing` when no snapshot was ever published (never confuse with a valid empty roster), `staleSession` when the snapshot's owner/AV/generation does not match the current `LegacyWriteSession`, `clockSkew` when `now < issuedAt`, `expired` when `now ≥ deadline` (`shouldBlockWrites=true`), otherwise `ready` when the expected session is valid and `issuedAt ≤ now < deadline`. A validated complete payload that contains 0 members/classes (and an empty school-calendar) is still `ready` if the lease/session match — by owner rule, an empty complete is a legitimate server state distinct from `missing`. Generation fencing mirrors the store: a generation mismatch is stale even if owner/AV match. The evaluator does not fetch, verify signatures, extend leases, or gate writes itself; upcoming local-first attendance integration will call it to decide whether an offline session can be created and what banner/reason to show. Added `@visibleForTesting AttendancePreparationSnapshot.forTest` to construct synthetic snapshots without a DB for the 8 readiness unit tests. Local Flutter 3.44.9/Dart 3.12.2: 8 readiness tests passed plus all 1284 Flutter tests passed; targeted analysis clean. Inactive, no deployment or encryption claim.

### Inactive preparation fetcher with keep-old on failure (v39)

`AttendancePreparationFetcher.fetchAndPublish(session, get)` performs `GET /attendance/preparation` via an injected `ApiService.get`, validates the envelope against the provided `AttendancePreparationScope` and full dataset shape, then publishes atomically via `AttendancePreparationStore` with generation fencing. On `!success` (401 authority change, 503 unavailable, etc.) it throws `AttendancePreparationFetchException` with the server-provided code and preserves the previous snapshot (never overwrites on failure). `staleSession` (invalid or generation-mismatched `LegacyWriteSession`) is rejected before network publish. The fetcher does not retry, extend leases, or synthesize scope; callers surface the typed code as a banner/reauth. Inactive: no app code calls it yet. Local Flutter 3.44.9/Dart 3.12.2: 3 fetcher tests passed plus all 1287 Flutter tests passed; targeted analysis clean on the new file.

### Truthful local-first attendance adapter (inactive, v39)

`AttendancePreparationAdapter.resolve()` reads `LocalDb.getLocalSession()` and `AttendancePreparationStore.getSnapshot()` then evaluates `AttendancePreparationReadiness` with `DateTime.now().toUtc()`. It returns `missing` when no snapshot was ever published, `staleSession` on owner/AV/generation mismatch, `clockSkew`/`expired` for lease issues, otherwise `ready` (including complete-empty). `classFor(classId)` exposes roster lookup from the ready snapshot only. The adapter never synthesizes data, never extends leases, never deletes old snapshots, and has no UI call site yet — the attendance screen will use it to render Ready/Needs-refresh/Blocked/Stale banners and to source roster/calendar offline. Local Flutter 3.44.9/Dart 3.12.2: 4 adapter tests passed plus all 1291 Flutter tests passed; targeted analysis clean.

### Attendance screen truthful preparation banners (active UI, v39)

`AttendanceScreen` now resolves `AttendancePreparationAdapter` on init and shows truthful banners: `missing` → “No offline preparation yet — connect and refresh”, `staleSession` → “Your sign-in changed — refresh”, `expired` → “Your offline preparation expired”, `clockSkew` → “Your phone clock looks wrong”, plus an info banner when preparation is `ready` but has 0 classes. Banners are advisory only — they never block roster loading or synthesize data, and `isReady` does not gate `saveAttendanceLocal` (authorization is still checked per-save). The screen refreshes preparation status on network reconnect via the existing connectivity logic and on demand via `_loadPreparationStatus()`. Local Flutter 3.44.9/Dart 3.12.2: 1291 tests passed; analyzer clean.

### Attendance refresh preparation action (active, v39)

Attendance screen now offers explicit “Refresh preparation” / “Refresh” buttons. Tapping calls `AttendancePreparationFetcher.fetchAndPublish()` with the current `LocalDb.getLocalSession()`-derived `LegacyWriteSession` and `ApiService.get('/attendance/preparation')`. On success the durable snapshot is replaced and the banner updates to ready; on `AttendancePreparationFetchException` the previous snapshot is preserved and the server-provided message is shown in a snackbar (401 authority change, 503 unavailable, etc.). No auto-retry, no lease extension, no silent overwrite. Local Flutter 3.44.9/Dart 3.12.2: analyzer clean.

### Exact atomic attendance receipt (schema v40, inactive)

`attendance_sync_receipt` (client_op_id PK, owner/AV/generation, class/date, http_status, receipt_json, server_request_id, created_at) stores the server’s exact response atomically with the outbox completion. The server already commits attendance rows + workflow packet + idempotency record in one MySQL transaction; the client will mirror that by INSERTing the receipt in the same SQLite transaction that marks `pending_attendance` as completed. The table is inactive — no drain code writes to it yet — and is created on `onCreate` and via `onUpgrade` <40. Schema version bumped 39→40; four version-expecting tests updated. Local Flutter 3.44.9/Dart 3.12.2: 1 new receipt schema test passed plus all 1292 Flutter tests passed; targeted analyzer clean.

### Exact atomic receipt drain (active, v40)

`LocalDb.settleLegacyOperation()` now INSERTs into `attendance_sync_receipt` in the same SQLite transaction that marks `pending_attendance` as `synced=1` when `settlement.kind == accepted` for `LegacyOperationKind.attendance` with a non-empty `receiptJson`. `SyncService._legacySettlement()` captures `ApiResponse.data` as JSON `receiptJson` plus `serverRequestId`/`httpStatus` for accepted attendance operations; `INSERT OR IGNORE` preserves the first receipt on idempotent replay. Retryable/needsAttention never creates a receipt. Two new atomic tests verify accepted inserts receipt with owner/AV/generation/class/date binding and retryable creates no receipt. Local Flutter 3.44.9/Dart 3.12.2: 2 new atomic tests passed plus all 1294 Flutter tests passed; targeted analyzer clean.

### 30-day authorization lifecycle & device revocation (active, v40)

`LocalDb.clearAllCache()` and `clearAllUserData()` now delete `attendance_preparation_snapshot` and `attendance_sync_receipt` alongside existing caches — logout/account-switch cannot leak previous roster or receipts to the next user on the same device. `AttendancePreparationStore.clearSnapshot()` remains fenced for in-session clears; `clearAllUserData()` is unconditional for destructive logout. 30-day lease renewal is defined as a *complete authorized-data refresh* (`AttendancePreparationFetcher.fetchAndPublish` success): login, partial refresh, or 304/ETag ping never extends `deadline`. Two new lifecycle tests verify (1) login without publish preserves old deadline and only a new publish renews, and (2) logout clears preparation/receipts/pending with no cross-account leakage. Local Flutter 3.44.9/Dart 3.12.2: 2 new lifecycle tests passed plus all 1296 Flutter tests passed; targeted analyzer clean.

### Department offline inventory and two-scope attendance correction (active)

SSMS has exactly **two** offline attendance scopes — Education Department attendance (by **class**) and Mezmur Department attendance (by **section**, `member_department_periods` when `attendance_v2` is enabled) — both served by the single atomic `GET /attendance/preparation` endpoint (predicate `classRestricted` + `attendance_v2`). Earlier wording of “three scopes (teacher/mezmur/general)” is corrected. `DepartmentOfflinePolicy` codifies the current surface: `offlineEnabledToday = {educationAttendance, mezmurAttendance}`, `plannedNext = {grades}`, `onlineOnly = {education, members, hr, finance, materials}`. Grades is the next offline department; no other department claims offline capability until its supported-scope and dependency inventory lands. `LocalDb.clearAllUserData()` already wipes all department caches (`cached_grade_sheets`, `cached_edu_*`, `cached_members`, `cached_hr_*`, etc.); the policy guard ensures future code cannot accidentally claim an uninventoried department as offline. Four new policy tests enforce exactly two enabled today, grades planned-not-enabled, no overlap, and online-only isolation. Local Flutter 3.44.9/Dart 3.12.2: 4 policy tests passed plus all 1300 Flutter tests passed; targeted analyzer clean.

### Grades preparation: server dataset and client validation/storage (inactive, v41, class-scoped)

Grades offline follows the same safety contract as attendance: server-defined, class-scoped (teacher sees assigned class-subjects, edu_dept/admins see all), same 30-day lease (`deadline = min(issuedAt+30d, yearEnd)`), same `AttendancePreparationReadBoundary` atomic snapshot, same HMAC `root` + per-class `grades_class` context tokens. `GradesPreparationService.read()` reuses the attendance read-boundary pattern with grades-specific tables (`subjects`, `assessments`, `academic_terms`) and publishes subjects/assessments/roster per authorized class; `GradesPreparationGrant.issue()` signs the exact payload bytes and per-class roster/assessment digests. `api/v1/routes/grades.php` adds `GET /grades/preparation` with `Cache-Control: no-store`, no query scope, and `GRADES_PREPARATION_UNAVAILABLE` on failure (never overwriting old snapshot). Client adds `GradesPreparationDataset` (strict validation, no HMAC verify), `GradesPreparationEnvelope` (captured scope + digest), `GradesPreparationStore` (fenced `grades_preparation_snapshot` table, `generation` binding, `clearSnapshot` fencing), `GradesPreparationReadiness` (missing/staleSession/expired/clockSkew/ready) and `GradesPreparationFetcher` (no retry, keep-old on failure). Schema `localDatabaseSchemaVersion` 40→41 adds `grades_preparation_snapshot` onCreate/onUpgrade<41 and extends `clearAllCache`/`clearAllUserData` to wipe it. Existing `pending_grades` rows are preserved (quarantine policy): no migration drops or auto-restamps them. Four v41 tests updated; 5 new dataset tests + 2 store tests verify class-scoped decoding, duplicate-subject/assessment rejection, lease bounds, publish/read, and stale-session fencing. Local Flutter 3.44.9/Dart 3.12.2: 7 new grades tests passed plus all 1307 Flutter tests passed; targeted analyzer clean. Inactive — no UI activation or receipt drain yet.

### Grades closure before next phase: receipt, adapter, UI banners, lifecycle (active, v42)

To close department-by-department extension before encryption/real-phone phases: schema 41→42 adds `grades_sync_receipt` (`client_op_id` PK, `owner/AV/generation`, `assessment_id`, `http_status`, `receipt_json`, `server_request_id`, `created_at`) + index on `onCreate`/`onUpgrade<42` and extends `clearAllCache`/`clearAllUserData` to wipe it. `LocalDb.settleLegacyOperation` now `INSERT OR IGNORE` grades receipts atomically with `synced=1` (same fencing as attendance, never hiding business success); `SyncService._legacySettlement` captures `receiptJson`/`serverRequestId`/`httpStatus` for accepted `grades` (attendance already captured). `GradesPreparationAdapter` resolves `LocalDb.getLocalSession` + `GradesPreparationStore.getSnapshot` with `GradesPreparationReadiness`, exposing `ready`/`missing`/`staleSession`/`expired`/`clockSkew` and `classFor`. Teacher grades screen now shows truthful banners (`missing`→“No offline preparation yet…”, `staleSession`, `expired`, `clockSkew`) and `Refresh preparation` button calling `GradesPreparationFetcher.fetchAndPublish` with current `LegacyWriteSession` and `ApiService.get('/grades/preparation')`; success replaces snapshot and banner, failure preserves old snapshot and shows typed message. Five v42 version tests bumped 41→42; 2 adapter tests + 2 receipt-atomic tests verify ready/ missing and accepted→receipt / retryable→no receipt. Local Flutter 3.44.9/Dart 3.12.2: 4 new closure tests passed plus all 1311 Flutter tests passed; targeted analyzer clean. `DepartmentOfflinePolicy` updated to 3 offline scopes (Education attendance, Mezmur attendance, Grades) with `plannedNext` empty; UI remains advisory and never blocks `saveGradesLocal`.

### Separate encryption investigation — research only, no encrypted claim (docs/ENCRYPTION_INVESTIGATION.md)

Ordinary `sqflite` remains ordinary SQLite — never called encrypted. This slice intentionally does **not** mix ciphertext into the 39→42 integrity migration. `docs/ENCRYPTION_INVESTIGATION.md` records the separate gate: threat model (stolen locked/unlocked, forensic dump, `adb backup`, malware, revoked-copy), options A SQLCipher / B EncryptedFile / C stay-ordinary with hardening (`allowBackup=false`, `FLAG_SECURE`, transactional wipe), and five required measurements before any “encrypted at rest” claim — migration/orphan-WAL recovery, cold-start, crash-recovery atomicity, key-lifecycle (biometric invalidation, reinstall, backup-restore quarantine), and size/power on the two reference phones. Recommendation: keep v42 integrity shipped; any future crypto lands behind `FeatureGate` on a spike branch `spike/sqlcipher-migration-v43` only after those five pass and the owner approves key-loss UX. No schema, token, or deployment change in this slice.

### Controlled real-phone release gates — plan and checklist (docs/RELEASE_GATES.md)

Passing `flutter test` is not deployment, review, school approval or phone acceptance. `docs/RELEASE_GATES.md` turns acceptance 30 into a measurable checklist on two reference tiers (low-end Go 2 GB/32 GB + mid-range 4 GB/64 GB, `--release` build, same `SITE_URL`) with three roster fixtures (8 / 153 / 500+ members). For each fixture × tier × department (Education attendance, Mezmur attendance, Grades) it lists the 30 gates: preparation without scrolling, wrong-role isolation, empty-vs-missing, interrupted/failed refresh keep-old, coherent revision, survive restart/airplane, stale-callback fencing, tap→durable, rapid-switch context isolation, roster-survives-miss, closed/foreign year preservation, lease deadline/renewal, bounded revocation, B-cannot-see-A transactional wipe, logout-blocking on pending/uncertain, stable `client_op_id`/hash, uncertain-until-receipt, one-business-effect replay, mutation-rejected, atomic receipt, permission/year checked in mutation boundary, multi-device review-not-overwrite, member add/remove/transfer/promotion, legacy-DB upgrade, interrupted migration/orphan WAL/sibling, foreground/background fencing, truthful UI states, and the full synthetic+phone matrix (7/14/30 d, low storage, weak nets, 2-phone conflict). Includes a measurement template and “before you tap Release” sign-off (no encrypted-at-rest release notes, rollback plan, school sign-off on revocation/conflict wording). The plan is documentation only — gates are unmeasured until real-phone runs land; the next step is a `beta/offline-v42` APK and a `RELEASE_EVIDENCE.md` with numbers.
