# Mobile Fleet Telemetry — Retention and Lifecycle Phase

**Scope:** diagnostic telemetry lifecycle only

**Policy:** retain `app_telemetry_events` and `app_downloads` for 90 days

**Status:** source and static-contract verified; cron installation, PHP runtime,
MariaDB execution, and staging behavior are `NOT VERIFIED` in this workspace.

## 1. Boundary

The cleanup target is deliberately limited to:

```text
app_telemetry_events.created_at
app_downloads.downloaded_at
```

The job does **not** delete or update:

- `app_installations` aggregate rows;
- `api_sync_attempts` server-observed monitor rows;
- `api_idempotency_records`;
- mobile SQLite outbox tables;
- attendance, grades, HR, communication, or other user-data tables.

The mobile outbox/user-data lifecycle remains independent from diagnostic
telemetry retention. A telemetry age horizon must never expire unsent user work.

## 2. Implementation

The CLI target is:

```text
admin/backend/mobile_telemetry_retention.php
```

It is:

- CLI-only;
- deployment/cron-owned;
- protected by a non-blocking file lock;
- fail-closed when the database or required telemetry tables are unavailable;
- bounded to 5,000 rows per delete statement;
- bounded to 10 batches per table per invocation;
- safe to run repeatedly;
- aggregate-only in its output, with no event payloads, installation IDs, or
  download metadata printed.

When the maximum batch count is reached, the output reports:

```json
"backlog_possible": true
```

The next scheduled invocation continues the cleanup. This prevents a large
historical backlog from creating one unbounded database lock.

## 3. Required deployment schedule

First identify the deployed CLI PHP binary:

```bash
command -v php
php -v
php -m | grep -Fx mysqli
```

Install a daily cron outside the application request path. The paths below are
examples and must be replaced with the deployed paths:

```cron
23 3 * * * umask 077 && /usr/local/bin/php "/home/ACCOUNT/public_html/admin/backend/mobile_telemetry_retention.php" >> "/home/ACCOUNT/mobile_telemetry_retention.log" 2>&1
```

The log must remain outside the web root and must not contain database
credentials, tokens, event payloads, installation IDs, or member data.

## 4. Manual staging verification

After applying migrations `051`, `059`, and `060` to an authorized staging
database:

```bash
cd "/path/to/deployed/project"
/usr/local/bin/php admin/backend/mobile_telemetry_retention.php
printf 'exit=%s\n' "$?"
```

Expected successful output shape:

```json
{"lock_acquired":true,"retention_days":90,"batch_size":5000,"max_batches_per_table":10,"deleted_events":0,"deleted_downloads":0,"event_batches":1,"download_batches":1,"backlog_possible":false}
```

Counts vary. The output must not expose row contents.

Verify with aggregate-only database queries:

```sql
SELECT COUNT(*) AS old_events
  FROM app_telemetry_events
 WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 90 DAY);

SELECT COUNT(*) AS old_downloads
  FROM app_downloads
 WHERE downloaded_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 90 DAY);

SELECT COUNT(*) AS monitor_rows
  FROM api_sync_attempts;
```

Run the cleanup again. It must be idempotent and must not change
`api_sync_attempts`.

A controlled disposable staging database may contain approved age-separated
rows for this verification. Do not insert fabricated telemetry or monitoring
rows into a shared staging or production environment merely to make the job
appear successful.

## 5. Failure and rollback

A failed run prints only:

```text
Mobile telemetry retention failed.
```

The detailed exception remains in the server's private PHP error log. Exit code
`1` is the operational failure signal.

If the cron fails:

1. disable only this retention cron entry;
2. preserve the database and application data;
3. inspect the private error log and schema preflight;
4. correct the deployment or database issue;
5. rerun manually until exit code `0` and valid aggregate JSON are observed;
6. re-enable the cron.

Rollback is operational: remove or disable the cron entry. No schema rollback
is required because this phase adds no database schema and does not modify
request-time behavior.

## 6. Acceptance criteria

- [ ] The script is unreachable through a browser request.
- [ ] The script deletes only the two approved telemetry tables.
- [ ] Each delete is bounded and ordered by the table's primary key.
- [ ] A concurrent run exits safely without deleting rows.
- [ ] Missing schema fails closed before cleanup.
- [ ] The job never touches `api_sync_attempts` or mobile user-data tables.
- [ ] A second run is idempotent.
- [ ] The cron log remains outside the web root.
- [ ] Staging execution and aggregate reconciliation are recorded.
- [ ] Production sign-off includes the actual installed cron evidence.

## 7. Growth awareness: `app_installations` (retained by design)

`app_installations` rows are never deleted by this job: the table is the
lifetime counter basis for fleet KPIs (total installs, device/model/OS
distributions), and the pinned contract test forbids this job from deleting
anything outside the two approved telemetry tables. Growth is bounded by real
installs. The postdrill report surfaces an informational count of
installations unseen for 395+ days so an operator can make an explicit,
documented retention decision if a fleet is ever decommissioned at scale.
That decision is deliberately NOT automated here — pruning would silently
change the basis of every lifetime KPI.
