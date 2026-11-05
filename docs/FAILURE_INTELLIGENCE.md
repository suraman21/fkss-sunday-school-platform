# Failure Intelligence — Architecture & Contract

## What this is

One issue registry for every failure the system can observe, a seeded
remediation catalog, and durable recorded reports — surfaced in the
Super Admin dashboard under **Failure Intelligence**. It groups observed
symptoms, timestamps, source context and seeded remediation suggestions.
A fingerprint or catalog match is **not a verified root-cause diagnosis**;
an authorized operator must investigate and validate a fix. This is
rule-based operational aggregation, not a claim of generative-AI diagnosis.

**Evidence boundary:** source contract, not live service activation. See
[current operations](OPERATIONS.md), [sync truth boundaries](WEB_ADMIN_SYNC_MONITORING.md)
and [incident response](INCIDENT_RESPONSE.md). No production schedules,
alerts, records or private provider configuration were accessed in the
2026-10-28 documentation review.

Three channels, three fingerprints (all deterministic and low-cardinality):

| Channel | Raw table (source of truth) | Fingerprint |
|---|---|---|
| Sync attempt failures | `api_sync_attempts` (`failed`/`rejected`) | `md5(category \| code \| domain \| operation)` |
| Server errors | `arkeon_error_log` (monitor) | `md5(error_type \| file_path \| line_number)` |
| App crashes | `app_telemetry_events` (`crash`/`crash_recorded`) | the SHA-256 crash key itself |

## Components (migration 064)

- **`failure_issues`** — one row per fingerprint: source, category, title,
  first/last seen, lifetime occurrences, status workflow
  (`open → acknowledged → resolved`, reopening on recurrence), admin
  remediation override, `last_auto_report_at`.
- **`failure_remediation_catalog`** — seed-owned `(source, category)` rows
  (the client's 22 `SyncErrorCategory` failure shapes + the server error
  families) with cause and fix steps. Refreshed by re-running migration
  064; admin customisations belong on the issue (override columns win).
- **`failure_reports`** — recorded markdown reports (what/why/when/where/
  how-to-fix + correlated request ids + resolution), surviving raw-data
  retention.

## Ingest (write path)

Every hook is **advisory and never-throw**: a failure of failure
intelligence must never alter the host write.

- Sync: `ApiSyncAttemptMonitorService` — direct failed/rejected inserts,
  and both completion paths (`complete()`, `completeWithinTransaction()`),
  guarded by the UPDATE's affected-rows so a row is never counted twice.
- Server: `monitor/error_monitor.php saveError()` — after the arkeon row
  is persisted.
- Crash: `api/v1/routes/telemetry.php` — alongside the crash-count and
  signature upserts (migration 063).

## Aggregation model (read path)

`failure_issues` stores only what an upsert maintains correctly. Window
counts (24h/7d/30d), affected users/installs, per-build and per-device
distributions, and 14-day timelines are computed at read time from the
raw tables by `FailureIssueService`. The nightly job
(`admin/backend/failure_issue_reconcile.php`, CLI-only, locked,
fail-closed, aggregate-only output) recomputes lifetime aggregates as
self-healing and generates threshold reports: **≥ 5 affected installs/users
or ≥ 50 occurrences in 24h → one recorded report per issue per 24h**
(user-approved defaults).

## Admin surface

- Endpoint: `admin/api_failure_intelligence.php` — **super_admin only**
  (fleet-wide scope), session auth, CSRF (`validateCsrf`) on every POST.
  GET: `get_failure_overview`, `get_failure_issues`, `get_failure_issue`,
  `get_failure_reports`, `get_failure_report`. POST:
  `update_failure_issue` (status transitions, resolution note, remediation
  override), `generate_failure_report` (on demand).
  The endpoint writes **only** `failure_issues`/`failure_reports`.
- Dashboard: `sections/failure_intelligence_section.php` +
  `admin/js/failure_intelligence.js` — pure client-side shell (zero
  server-side queries until the section is opened), golden-signal KPIs
  (sync failure rate, p95 duration, attempts/min, crash-free installs,
  open issues), impact-ranked issue list, issue detail with timeline /
  distributions / correlated request ids / remediation editing, and the
  reports archive with rendered reports.

## Privacy

The issue registry aggregates existing server-observed channels rather
than making all private local outbox state observable. Crash grouping
uses the bounded signature from migration 063 (exception class and up to
three first-party frame names); this does not establish that every
telemetry field or copied diagnostic is anonymous. Monitor capture has
redaction logic, but operators must still review samples/reports before
sharing. Raw bootstrap/native log tails have a different privacy boundary.

The admin API is read-mostly and gated by session, `super_admin` and the
central role map. Preserve that boundary; do not put private samples,
member information or raw diagnostic reports in public issues. Alerts
sent through Telegram leave the hosting environment when that optional
channel is enabled; configuration and recipient authorization require
operator review.

## Alerting (Phase 4)

Three symptom-based conditions, evaluated by
`admin/backend/failure_alert_check.php` (CLI-only, flock, fail-closed,
**every 15 minutes** — cron: `0,15,30,45 * * * *`):

| Condition | Fires when | Cooldown |
|---|---|---|
| `crash_new` | a crash identity **first seen < 24h ago** hits ≥ 5 distinct installations in 24h | 24h per identity |
| `velocity` | a build **first seen < 7 days ago** fails at ≥ 2× the trailing 30-day rate of the rest of the fleet (noise floors: ≥ 20 attempts, ≥ 5%) | 24h per build |
| `sync_budget` | fleet sync-failure rate ≥ 10% over **both** the last 1h and 6h (≥ 20 attempts per window — multi-window suppresses blips) | 6h |

Channels: the existing notification center (`sendNotification`, type
`failure_alert`, `target_roles = super_admin`; the notifications priority
enum is low/normal/high/urgent — alert severity `critical` maps to
`urgent`) and, when configured, the same Telegram bot the error monitor
uses (`MONITOR_TELEGRAM_*` constants, identical gating). Every payload
carries the **remediation one-liner** for its dominant failure category
(issue override → seeded catalog → fallback line): alert + runbook, per
SRE practice.

The cooldown ledger and alert history live in `failure_alerts`
(migration 065): one row per firing condition (`alert_key`:
`crash_new:<key>` / `velocity:<build>` / `sync_budget:default`), with
`last_sent_at`, `sent_count`, and the bounded payload. The dashboard's
**Recent Alerts** card lists this history (`get_failure_alerts`).

Thresholds are the user-approved defaults, defined as constants in
`FailureAlertService` (5 installs, 7-day build age, 30-day baseline, 2×
multiplier, 10% budget, 20-attempt floors, 24h/6h cooldowns) and pinned
by `tests/security/test_failure_intelligence_phase4.py`.

## Deploy order

**Conditional procedure, not permission to deploy.** Test only with isolated
synthetic data during routine engineering work. Integrate checked changes
into development, independently verify the development target and obtain
owner acceptance; production then needs separate explicit authorization.
Inspect the target's applied schema and preflight stop conditions rather
than treating filename order as proof that prerequisites exist. Preserve
private backups/rollback evidence before an approved migration.

1. Review/apply the numbered migrations beginning `062`, `063`, `064` and
   `065` to the separately authorized target, respecting prerequisites and
   verifying repeat-safety there; source intent is not live-state proof.
2. Deploy the server code.
3. Run the read-only staging preflight and keep the output as release
   evidence (it fail-closes with `SIGNAL SQLSTATE '45000'` on any BLOCK
   row — schema, indexes, seed count, channel tables):
   `mariadb ... < sql/preflight/failure_intelligence_preflight.sql`
4. Install the two CLI crons after the existing retention job:
   - nightly (03:47): `php .../admin/backend/failure_issue_reconcile.php`
   - every 15 min: `php .../admin/backend/failure_alert_check.php`
5. Follow the owner-controlled [APK release gates](../Mobile/wbws_flutter_app/docs/RELEASE.md) for any client publication. Client headers alone do not prove correct installation or reporting.

Use the target's verified absolute PHP/script paths and outside-web-root
logs. Record observed execution and exit status; installing a schedule is
not evidence it has run successfully. Do not put credentials in commands,
URLs or logs. The repository does not install these hosting crons.

Older servers + newer clients, and newer servers + older clients, remain
compatible: every hook tolerates a missing table (issue bookkeeping is
skipped, raw telemetry unaffected), and clients simply send headers the
old server ignores.
