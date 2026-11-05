# 1.7.4+41: education attendance class-day gate

## Source scope

This release consumes the existing authenticated `GET /attendance/calendar`
endpoint; it does not change server policy, route implementations, schemas,
Mezmur attendance, dependencies, signing settings or update-enforcement policy.
The server remains authoritative on save/submit and outbox replay.

`AttendanceCalendar` projects only the server's `o/m/n/c/d/h/x` codes. It does
not infer weekdays. Allowed makeup days remain editable; non-class days,
unconfigured timetables, Day Offs and holidays do not. Unknown/malformed or
uncovered calendar data requires an explicit online sheet date verdict.
Future dates and an enforced moving backdate boundary use Addis Ababa dates.
An explicit online sheet verdict supersedes the cached day code, but not those
moving date bounds. Submitted/approved packet locks continue to apply.

`AttendanceScreen._canEdit` gates individual marks, bulk marking, QR callbacks,
notes, local edit persistence, autosave, Save and Submit. Loading/incomplete
rosters and superseded sessions cannot edit. Selection/load epochs discard
late responses. Calendar refresh occurs when loading a sheet, changing class
or date, resuming, or reconnecting. Successful unavailable calendar responses
remove the old disk snapshot; network failures retain it. ETag/304 revalidates
the snapshot without downloading it again.

### Follow-up readiness audit

Pending valid edits are flushed before class/date changes and refresh, and queued
on disposal before debounce cancellation. A storage refusal blocks a class/date
switch instead of discarding pending marks. Edit revisions preserve marks/notes
made during a slow response. Older draft responses cannot reopen a newly submitted
packet; Undo is bound to its exact operation and originating load epoch. Note-only
changes schedule autosave. QR feedback reports storage refusal instead of success.

A desktop native-SQLite screen journey exercises switch/leave/reopen/refusal using
the production LocalDb, in addition to controlled screen regressions. These are
not Android device/camera/signing tests. The existing CI zero-skips backend gate
is not weakened; its pre-existing live-fixture/dependency gaps remain separately
reported rather than counted as verification.

The SharedPreferences calendar contains no students or attendance marks. Its
key includes API origin, user and authorization version; it is purged with
timetable caches at sign-out and authorization-scope changes. Existing queued
attendance drafts are not silently deleted when a date becomes an off-day.

## Offline limitation

A disconnected phone cannot learn a schedule change made after its last
calendar refresh. The screen labels a saved, unvalidated calendar, and refreshes
on reconnect/resume. Server validation still rejects disallowed writes. Device
time is used for moving date bounds; no trusted-device-clock mechanism is added.
A missing/uncovered calendar is read-only, not a weekday-based fallback.

## Development acceptance and release sequence

1. Publish the feature checkpoint to the **development repository** for review.
   Only its `main` is pulled by the owner's development-server cron; a feature
   branch push alone does not deploy. Production requires later explicit owner
   acceptance. These are owner-specified release controls, not a claim that the
   current server setup or deployed files have been inspected.
2. Confirm the development server exposes `/attendance/calendar` and has the
   class-calendar schema, assignments and configured academic-year data.
3. Use `scripts/build-release.ps1` per [RELEASE.md](RELEASE.md) on the approved
   signing/build workstation. Verify the universal APK manifest is 1.7.4+41,
   signing identity, checksums, ABI coverage and release-manifest output.
4. Install on a test phone. Test a normal day and a makeup day; each of Day Off,
   holiday, non-class day and missing timetable; QR, notes, bulk marks, Save,
   Submit, autosave, approved/submitted locks, switching class/date during slow
   responses, offline restart, reconnect after an admin changes the schedule,
   sign-out/account switch and Addis midnight/backdate boundaries.
5. Confirm disabled dates produce no new local edit/outbox rows. Confirm normal
   drafts and submissions still survive restart and synchronize. Preserve any
   pre-existing queued operations; inspect rejected items instead of deleting.
6. Only after owner acceptance, separately approve production promotion and APK
   publication. Upload the matching universal APK before advertising its build.
   Do not raise minimum versions or enable force-update as part of this patch.

## Rollback

Before rollout record the previous source SHA, signed APK and release metadata.
Before deployment, withdrawing the feature checkpoint needs no data action.
After rollout, coordinate source/APK metadata rollback with the owner; do not
uninstall the app or clear storage (pending operations must survive). No database
rollback or migration is introduced. Restoring an older mobile implementation
also restores its weaker offline date guard, so retain server validation and
restrict rollout while investigating. Never automatically lower APK version
codes or assume Android permits in-place downgrade.
