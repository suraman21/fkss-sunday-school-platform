# 🚀 Go-Live Runbook — WBSS / FKSS

> **Historical go-live runbook (marked 2026-10-22).** This 2026-10-06 runbook is retained unchanged. Individual steps may still be useful, but it is **not** the current procedure. Verify each step against [OPERATIONS.md](../OPERATIONS.md) and [DATABASE.md](../DATABASE.md) before using it.


**Read this ONE document to deploy.** It puts every "what you must do" step from the audit reports in the exact order to do them. Each step says what to do and how to confirm it worked. Do them top to bottom. Don't skip.

> **Build 24 authorization/outbox release:** also complete the mandatory staged
> migration, build-23 compatibility, background-drain containment, device-drill,
> and forward-only SQLite v34 gates in
> [`AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md`](AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md).
> Its stop conditions supersede generic rollback advice for this release.

---

## STAGE 0 — Before you touch the live server (15 min)

```
[ ] 0.1  Make a full backup of the CURRENT live database (cPanel → phpMyAdmin →
         Export), even if you think there's no real data yet. Keep it somewhere safe.
         Use the DEFAULT "Quick" export (Format: SQL). Do NOT tick
         "Disable foreign key checks" — see "Restoring the 0.1 export" below
         for why, and for the command that actually restores this file.
[ ] 0.2  Make a copy of the current live files (cPanel → File Manager → compress
         public_html to a zip, download it). This is your undo button.
```

---

## STAGE 1 — Secrets file (the site won't run without this) (15 min)

The system now **refuses to start** without a secrets file — that's on purpose (it used to run with a guessable security key). Create it once.

```
[ ] 1.1  In cPanel → MySQL Databases, note your database NAME, USER, and PASSWORD.
         If you don't know the password, create a new one there and save it.

[ ] 1.2  In cPanel → File Manager, go UP one level ABOVE public_html
         (usually /home/YOURUSER/). Create a new file named:   .fkss_env.php

[ ] 1.3  Open the project file  env.example.php  (in public_html), copy ALL its
         contents into your new .fkss_env.php, and fill in:
            - DB_NAME, DB_USER, DB_PASS  → the real values from step 1.1
            - JWT_SECRET, BACKUP_KEY, HEALTH_KEY  → three DIFFERENT long random
              strings. Generate them in cPanel → Terminal with:
                 php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
              (run it three times, paste a different result into each).
            - WRITE DOWN the BACKUP_KEY and HEALTH_KEY — you'll need them later.

[ ] 1.4  Set its permission to 0600:  in File Manager right-click .fkss_env.php →
         Change Permissions → 600 (only the owner boxes ticked).

[ ] 1.5  CONFIRM: open your site. It should load normally. If you see
         "Setup required: secrets file missing", the file is in the wrong place
         or misnamed — recheck step 1.2.
```

---

## STAGE 2 — Deploy the new code (10 min)

```
[ ] 2.1  Upload/pull the new project files to the live server (all the files in
         this branch). If using Git on the server:  git pull

[ ] 2.2  CONFIRM the site still loads and you can reach the login page at
         SITE/admin/index.php
```

---

## STAGE 3 — File permissions (10 min)

In cPanel → Terminal, from inside `public_html`:

```bash
# Baseline: folders 755, files 644
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

# Public generated-QR directory is deployment-owned; requests never create it.
install -d -m 755 admin/id_cards/assets/qr

# Public asset/upload folders stay 755 (never 777). Private member files and
# encrypted backups are created with 0700/0600 outside public_html by the app.
chmod 755 admin/uploads admin/uploads/members admin/uploads/members/photos \
          admin/uploads/cache admin/id_cards/assets/qr uploads uploads/gallery \
          uploads/teachers
```
```
[ ] 3.1  Run the commands above.
[ ] 3.2  If admin/id_cards/assets/qr/ was ever set to 777, it's now 755 — good.
```

---

## STAGE 4 — Database hardening (15 min)

```
[ ] 4.1  In phpMyAdmin, select your database → SQL tab.
[ ] 4.2  Open the project file  sql/003_production_hardening.sql. Copy SECTION A
         (indexes) and run it. This is the important speed fix. If any line says
         "Duplicate key name", that's harmless — keep going.
[ ] 4.3  Apply sql/004_year_lifecycle.sql through
         sql/016_member_duplicate_lookup.sql IN NUMERIC ORDER. These migrations
         are re-runnable and own all schema needed by the matching application
         code; normal web/API requests intentionally never repair schema. Run
         014 during this maintenance window because building its FULLTEXT index
         can briefly lock a large members table. 015 makes attendance status
         explicitly required and 016 adds the duplicate-lookup index; both are
         idempotent. 008, 009 and 010 are required BEFORE the new code is
         deployed: without 010 the mobile/REST login and token refresh return
         503 by design (safe, but the app cannot authenticate). The migrations
         are safe to run while the old code is still live (new tables/indexes
         only), so do this stage before pulling the new files.
[ ] 4.4  (Can wait to week 1, but better now) Copy SECTION B of
         sql/003_production_hardening.sql (foreign keys) and run it. It cleans up
         bad rows first, then adds the keys.
[ ] 4.5  Confirm the directory indexes exist:
         SHOW INDEX FROM members WHERE Key_name IN
         ('idx_members_status_id','idx_members_tier_id',
          'idx_members_archive_type_id','ft_members_directory');
         SHOW INDEX FROM class_enrollments
         WHERE Key_name = 'idx_ce_member_year_status_id';
[ ] 4.6  (P66 hymn art — release phase7-art01) Run
         sql/040_mezmur_hymn_art.sql (guarded and idempotent — safe on a
         live database, old or new code keeps working either way because
         every reader is probe-guarded). It adds the five art_* columns to
         mezmur_hymns plus idx_mz40_art_status. The Mezmur console's
         "Sync DB schema" button applies the same columns. Verify with
         admin/api_mezmur.php?action=ping — it must report
         code_version phase7-art01 with no missing_columns.
[ ] 4.7  (Audit 2026-10-02 — migration ordering + uniqueness) Three items.

         (a) MIGRATION 030 WAS DUPLICATED. Two different migrations both
             shipped as number 030, so "apply in numeric order" was
             ambiguous and one could be skipped without anyone noticing.
             The roster index file is now sql/052_roster_scale_indexes.sql;
             sql/030_mezmur_taxonomy.sql keeps 030 because 031/032/033
             build on it and application code names it by path. If you
             already applied the old sql/030_roster_scale_indexes.sql, you
             have the index — re-running 052 just reports "Duplicate key
             name", which is harmless. If you are deploying fresh, apply
             052 in its new position. There is no semantic change.

         (b) MEZMUR MIGRATIONS 031 -> 032 -> 033 ARE ORDER-DEPENDENT.
             033 drops title_am/reference and then clears the word index
             table that 032 CREATES, under the uniqueness rules 031 adds.
             Run out of order, 033 would drop the columns and only then
             fail on the missing table — a half-applied migration. 033 now
             detects that case and prints a BLOCKER line naming 032
             instead of aborting with a bare "table doesn't exist". Plain
             numeric order satisfies the dependency; do not reorder these.

         (c) VERIFY THE CONDITIONAL UNIQUE CONSTRAINTS. Several migrations
             add a UNIQUE key only when existing data is already clean, and
             skip it otherwise so a deployment never silently destroys
             duplicate rows a human must adjudicate. That is intended. The
             risk is that a skip used to look like a success, so a database
             could go live missing a guarantee the application assumes —
             e.g. without uq_academic_years_year_name the "year name
             already exists" check in the save endpoint can never fire.
             Run:
                 sql/preflight/uniqueness_preflight.sql
             It is read-only. It prints PASS/BLOCK/SKIP per constraint,
             lists the exact duplicate rows behind any BLOCK, and raises
             SQLSTATE 45000 if anything is blocked, so the result cannot be
             misread as "probably fine". A BLOCK is a DEPLOYMENT BLOCKER:
             decide which duplicate records survive (a business decision,
             not the migration's), merge them, re-run the owning migration
             (018 or 031), then re-run this script until it reports PASS.
             It also reports how many rows sit unreviewed in the
             migration_013_*_conflicts quarantine tables — not a blocker,
             but real user data that should not be forgotten.
```

---

## STAGE 5 — Health check + backups (20 min)

```
[ ] 5.1  Open the health check using HTTP Basic authentication. Do NOT put the
         key in the URL (URLs are logged). With curl:
            curl -u health:YOUR_HEALTH_KEY https://SITE/admin/tools/health_check.php
         Confirm: Database = available (green), and the counts look sane.

[ ] 5.2  Log in as Super Admin → Backup & Data → Create Encrypted Backup.
         Confirm an encrypted `.sql.gz.ssb` file appears. New backups are kept
         outside public_html and the newest seven are retained.

[ ] 5.3  Re-run the health check (5.1) — "Last backup" should report an
         encrypted backup created minutes ago.

[ ] 5.4  Set the DAILY automatic backup in cPanel → Cron Jobs:
            0 2 * * * /usr/local/bin/php /home/YOURUSER/public_html/admin/tools/backup.php >/dev/null 2>&1
         The local CLI process is trusted; never put BACKUP_KEY in a cron command
         or URL. If that PHP path errors, try /usr/bin/php.

[ ] 5.5  Perform a restore drill to a NEW file outside public_html, then import
         it into an empty staging database (never overwrite production first):
            php admin/tools/backup.php --decrypt=BACKUP_NAME --output=/home/YOURUSER/restore-test.sql
         Preserve BACKUP_KEY securely: without it encrypted backups cannot be restored.
```

### Profile Image Orphan Cleanup

The profile-image service writes normalized JPEGs to private atomic storage and
stores only logical `private://profiles/...` references in the database. A
failed process or post-commit deletion can leave an unreferenced final image or
a staging file behind. `admin/backend/profile_image_cleanup.php` is the
CLI-only maintenance command that reclaims those artifacts after the fixed
24-hour grace period.

#### Private storage and execution boundary

The cleanup uses the same configured private root as profile-image operations,
in this order:

1. `PROFILE_PRIVATE_STORAGE_PATH`, when configured;
2. `MEMBER_PRIVATE_STORAGE_PATH/profiles`, when configured; or
3. the fallback `ssms_private/profiles` directory beside the project/web root.

For this production installation the application root is
`/home/arkeonet/felegekidusan.arkeonethiopia.com`, so the unoverridden fallback
resolves to `/home/arkeonet/ssms_private/profiles`. If either trusted server
constant overrides that location, verify the configured absolute path instead.
The root must remain outside the public web root and writable only by the
application/maintenance account. The command refuses web execution and must
never be invoked with `curl`, a query-string key, or an HTTP scheduler. It
accepts no client path, filename, user ID, or storage-root argument.

Create or verify the private directory as the application/maintenance account,
never from a web request:

```bash
umask 077
install -d -m 0700 /home/arkeonet/ssms_private
install -d -m 0700 /home/arkeonet/ssms_private/profiles
stat -c '%a %U %G %n' /home/arkeonet/ssms_private /home/arkeonet/ssms_private/profiles
find /home/arkeonet/ssms_private/profiles -maxdepth 1 -type f -printf '%m %f\n'
```

Both directories must be mode `700` and owned by the deployment account.
Normalized JPEGs, staging files, and `.profile-cleanup.lock` must be mode `600`;
the service applies that file mode when it creates them. Correct ownership as a
hosting operation if needed, but never use `chmod 777` or make the root
web-readable.

Before scanning storage, the command verifies the migrated nullable
`users.profile_image_path` column and enumerates every non-empty value from that
authoritative column. A missing schema, database error, or failed reference
query exits with code `1` before cleanup; never bypass that fail-closed gate.

The repository provides the CLI reaper but cannot install or prove a cPanel or
hosting cron. Until the following cron is installed and observed on the actual
host, cleanup is deployment-dependent and is **not runtime-verified**.

#### Required daily cron

First obtain the actual CLI PHP binary on that hosting account:

```bash
command -v php
php -v
```

`/usr/local/bin/php` below is only the common cPanel value. It **must** be
replaced with the exact path printed by `command -v php` when they differ.
Install this daily entry in cPanel → Cron Jobs:

```cron
17 3 * * * umask 077 && /usr/local/bin/php "/home/arkeonet/felegekidusan.arkeonethiopia.com/admin/backend/profile_image_cleanup.php" >> "/home/arkeonet/profile_image_cleanup.log" 2>&1
```

The log deliberately lives at `/home/arkeonet/profile_image_cleanup.log`,
outside the application and public web root. Do not redirect it into the
repository, `admin/uploads`, or any other web-served directory. Configure
host-level log rotation/retention for this file if required.

#### Manual pre-cron verification

Run from the actual deployed project root:

```bash
cd "/home/arkeonet/felegekidusan.arkeonethiopia.com"
command -v php
php -v
php -m | grep -Fx mysqli
php admin/backend/profile_image_cleanup.php
echo $?
```

Replace the three `php` invocations with the absolute path returned by
`command -v php` if the cron will use that absolute binary. A successful,
lock-owning run must:

- print one valid aggregate JSON object;
- exit with code `0`;
- report `"lock_acquired":true`; and
- expose no private path, image filename, user ID, credentials, token, or image
  contents.

Expected output shape (counts will vary):

```json
{"lock_acquired":true,"deleted_orphans":0,"deleted_temporaries":0,"skipped_referenced":0,"skipped_fresh":0}
```

`lock_acquired:false` with exit code `0` means another cleanup process owns the
lock. It is safe, but it does not prove that this invocation scanned storage;
wait for the other process to finish and retry once.

#### Verify the first scheduled execution

After the first 03:17 run, execute:

```bash
stat "/home/arkeonet/profile_image_cleanup.log"
tail -n 1 "/home/arkeonet/profile_image_cleanup.log"
grep -c '^Profile-image cleanup failed\.$' "/home/arkeonet/profile_image_cleanup.log"
```

Confirm all of the following:

1. the log modification time is later than the cron installation time and
   matches the expected scheduled window;
2. the final line is valid aggregate JSON with `lock_acquired:true`;
3. only the five documented fields are present;
4. the log remains outside
   `/home/arkeonet/felegekidusan.arkeonethiopia.com`; and
5. the failure count did not increase for that run.

The final log line plus the log modification time is the authoritative status
for the latest scheduled run. When that line is valid JSON, its modification
time is the latest successful execution time and the object provides the run
result, deleted-final count, deleted-temporary count, skipped-referenced count,
and skipped-fresh count. A generic failure line instead marks the latest run as
failed, and the `grep` result provides a retained failure count. The command
does not maintain a separate historical last-success registry; use the
outside-web-root log and the hosting cron history when investigating an older
run. No public status endpoint or web-readable status file is required or
permitted.

#### Failure response

A failure prints the generic line `Profile-image cleanup failed.` to stderr and
exits with code `1`. If that occurs:

1. disable only this cron entry to stop repeated failures;
2. run the manual sequence above as the same hosting user;
3. verify database connectivity and that the profile-image migration is
   present;
4. verify the configured private root exists and is readable/writable by that
   user;
5. verify the selected binary is CLI PHP and has the required mysqli support;
6. check the server's private PHP error log for the detailed operator error;
7. correct permissions/configuration without using `chmod 777`; and
8. rerun manually until exit `0` and valid aggregate JSON are observed, then
   re-enable the cron.

Do not bypass a reference-enumeration failure, lower the 24-hour grace period,
remove the cleanup lock, follow symlinks, or point the script at a
client-supplied directory.

#### Security and rollback

The scheduled user should be the normal application/maintenance account, not a
public web request and not an unnecessarily privileged system user. Keep the
private root and cleanup log inaccessible over HTTP. The cron command must not
contain database credentials, tokens, backup keys, image names, or user IDs.

To roll back the scheduled operation, remove or disable only the cron entry.
Do **not** delete the private storage root or run a broad filesystem deletion.
Existing database references and image files remain valid when the cron is
disabled. Retain or securely remove the outside-web-root operational log under
the hosting retention policy. Reinstall the same cron after the operational
issue is resolved.

---

## STAGE 6 — Security spot-checks (10 min)

```
[ ] 6.1  While LOGGED OUT, open  SITE/admin/print_member.php?id=1
         → must bounce you to login (NOT show a student). 
[ ] 6.2  While LOGGED OUT, open  SITE/admin/info_manage_member.php?id=1
         → must bounce to login.
[ ] 6.3  Open  SITE/admin/uploads/backups/  → must be "Forbidden".
[ ] 6.4  Open  SITE/error.log  → must be "Forbidden" (or Not Found).
[ ] 6.5  Force any error while browsing → you see a friendly message, never PHP
         code or file paths.
```

---

## STAGE 7 — Role testing (30–45 min) — the most important step

Log in as each role and check the pages that role uses ([ROLES_AND_PERMISSIONS.md](../ROLES_AND_PERMISSIONS.md)).

```
[ ] 7.1  Create one test user per role (Users page, as super admin).
[ ] 7.2  Log in as EACH role and run its checklist. Pay special attention to:
           - finance_dept: log in → finance dashboard loads → can add a
             transaction → can see the student roster for fees.
           - teacher: can take attendance and it SAVES (re-open shows the marks);
             but CANNOT delete a class (should get a "permission" message).
[ ] 7.3  Confirm each role is BLOCKED from other departments' pages (the "CANNOT"
         lines in the checklist).
[ ] 7.4  Confirm logout, then Back button, cannot see the dashboard.
```

**If anything in Stage 7 fails, STOP and note exactly which role + which URL.** That's the highest-risk area (the access-control map) and is worth getting right before real users arrive.

---

## STAGE 7B — Attendance rework verification (2026-10, one-time)

No migrations are needed for the rework (all merge paths use existing
unique keys; HR tables stay as a read-only archive). Verify behavior
after the code deploy. Background: [ADR-0009](../adr/0009-attendance-ownership.md).

```
[ ] 7B.1 Default-absent sanity (teacher, mobile app + web taker):
         open a fresh day → EVERY student shows ABSENT, Save/Submit are
         enabled immediately; tapping Save with nothing marked succeeds
         (an all-absent day — the chosen conservative default).

[ ] 7B.2 Draft merge: with a teacher mid-marking, watch the web Sync
         Monitor — paused partial autosaves must NOT produce new 422
         INCOMPLETE_SHEET rejected rows from updated clients. A residual
         422 with code ROSTER_MISMATCH means real roster drift (reload).

[ ] 7B.3 QR: scan a member card → marked Present instantly; scan again
         → friendly "already present" (Amharic), no duplicate row.

[ ] 7B.4 HR retirement: an OLD HR taker app (if any) shows the honest
         "HR attendance was retired" attention message after sync (410,
         code HR_ATTENDANCE_RETIRED). HR dashboard → "Attendance
         History" is read-only (view/export only); "Attendance Reports"
         shows Education + Mezmur cards and member search. Creating an
         HR taker account is refused server-side.

[ ] 7B.5 Optional cleanup: deactivate remaining hr_attendance_taker
         accounts (Users page) once no old devices remain; expect the
         410 attention burst to decay to zero.
```

---

## STAGE 8 — First real academic year (only when you're ready to enroll)

```
[ ] 8.1  Education → Academic Years → create the current year, set it current.
[ ] 8.2  Create your classes and enroll students normally.
[ ] 8.3  For NEXT year (in ~12 months): use  SITE/admin/tools/year_rollover.php
         (super admin). Preview first, back up first, then run "carry forward".
         Do NOT start a new year by hand — the tool keeps enrollments intact.
```

---

## ✅ You are live when…
- Stages 1–7 are all ticked.
- The health check is green.
- A backup file exists and the daily cron is set.
- The Profile Image Orphan Cleanup cron is installed and its first scheduled
  aggregate JSON result is verified from the outside-web-root log.
- Every role passed its test checklist.

## Still open (safe to launch, handle after) — from the audits
- **Editable member sync batches**: XLSX round trips remain intentionally bounded to 2,000 rows; use the complete streaming CSV export for large read-only extracts.
- **Legacy cron path**: `admin/backend/cron_backup.php` is now a CLI-only compatibility adapter to the same encrypted streaming service. New schedules should use `admin/tools/backup.php`.
- **Code cleanup (Phase B)**: only after the above is stable and tested on a staging copy.

## Restoring the 0.1 export (read before you need it)

**Do not restore the 0.1 export by importing it in phpMyAdmin.** A phpMyAdmin
export appends its foreign keys as trailing `ALTER TABLE ... ADD CONSTRAINT`
statements and contains no `FOREIGN_KEY_CHECKS` statements at all. A plain
import therefore stops at the first constraint that the live data violates and
leaves the database **half-restored**. Measured against the 2026-10-02
production export: `ERROR 1452` at line 9746, exit 1, **31 of 42 foreign keys**,
87 tables — a database that looks present but is missing a quarter of its
referential integrity.

Use the repository's deterministic restore instead. It loads the data first,
then applies each constraint individually so that **every** constraint is
validated against real rows, and it reports any that the data rejects:

```
./scripts/restore_production_dump.sh <new_database> <export.sql> \
    -h 127.0.0.1 -u <user> -p<password>
```

Expected results — anything else is a signal, and the script exits 2:

| Export taken | Foreign keys | Meaning |
|---|---|---|
| Before `sql/055` | **39 / 42** | 3 constraints blocked by historical orphaned mezmur link rows. Expected. Apply `sql/055_mezmur_orphan_link_cleanup.sql` to reach 42/42. |
| After `sql/055` | **42 / 42** | Every declared constraint present and validated. |

**Why not just tick "Disable foreign key checks" in phpMyAdmin?** Because it
hides the problem instead of restoring correctly. Constraints added while
foreign-key checking is off are created **without being validated**, and
re-enabling checks afterwards does not retro-validate them. Measured on the
same export: the import "succeeds" and reports a complete **42/42**, while
**99 orphaned `mezmur_hymn_categories` rows remain in the database**. You get a
schema that claims integrity it does not have. The restore script deliberately
refuses that shortcut.

(The app's own encrypted backup — Stage 5.2 / `admin/tools/backup.php` — is
self-contained and already emits its own `FOREIGN_KEY_CHECKS` guards, so it
does not need this procedure. The 0.1 phpMyAdmin export does.)

## If something breaks
1. You have the file zip (0.2) and DB export (0.1) — restore them to undo.
   Restore the database with `scripts/restore_production_dump.sh`, not with a
   phpMyAdmin import — see "Restoring the 0.1 export" above.
2. Check the health check page — it usually points at the problem (DB down, disk full, env missing).
3. The real error detail is in the server error log (not shown to users, by design).

---

## Stage 0 (read first) — `sql/` is not a fresh-install sequence

*Added 2026-10-02 after audit finding M.*

**Do not build a new database by running `sql/001` through `sql/052` in
order. It will fail, and it would not produce a correct schema if it
didn't.** The directory mixes two different kinds of file that nothing
currently distinguishes:

* **replayable migrations** — guarded, idempotent, safe to re-run; and
* **one-time operational scripts** written against the already-live
  production database. `sql/003_production_hardening.sql` says so in its own
  header: *"HOW TO RUN (do this ONCE, from phpMyAdmin)"*.

Two consequences, both measured:

1. **Seven core tables are altered by migrations but never created by any of
   them** — `users`, `members`, `subjects`, `finance_transactions`,
   `finance_member_fees`, `material_items`, `material_transactions`. Their
   real DDL originates outside this directory (legacy runtime DDL, plus the
   stale `database_schema.sql`). A fresh run has nothing to alter.

2. **Four tables are altered by a lower-numbered migration than the one that
   creates them** — `003` adds foreign keys to `teacher_assignments`, which
   is first created in `006`; `004` alters `academic_years` (created in
   `012`); `012` alters `wbws_groups` and `wbws_group_leaders` (created in
   `013`); `003` also alters `attendance`, `class_enrollments` and
   `academic_records` (all created in `013`). `003` contains no
   `information_schema` guard, no `IF EXISTS`, and no prepared statement, so
   in strict numeric order it errors out.

There is also **no `schema_migrations` ledger**, so nothing in the database
records which of these files has actually been applied. Migration state is
tracked by hand.

### What to do

* **Existing production deployments** — continue applying new migrations
  individually, in number order, as you have been. Nothing here changes that.
* **Standing up a new environment** — take a `mysqldump` of the live schema
  (`mysqldump --no-data`) and restore that as the baseline, *then* apply any
  migrations newer than the dump. Do not attempt to replay `sql/` from
  scratch.
* **Before sign-off** — run `sql/preflight/uniqueness_preflight.sql` and
  resolve every `BLOCK` row.

This boundary is pinned by `tests/security/test_migration_schema_boundary.py`,
which fails if a new migration alters another unmanaged table or introduces a
new forward reference.
