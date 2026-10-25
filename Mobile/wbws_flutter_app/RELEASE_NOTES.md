# FKSS App — Release Notes

User-facing notes per release. Keep the newest on top. The build
script runs the full test suite (including the version-sync pin) so
the version below must always match `pubspec.yaml` +
`AppConfig.appVersion`.

## 1.7.3 (build 40) — Marks: live limit, "Did not take", View only, result card

Works with the server change in the same release
(the server already refuses invalid marks with a clear message).

- **Live limit on marks:** on a test out of 10, a key that would make the
  mark more than 10 is refused on the spot and a small red hint says
  "Max is 10". Letters, minus, a second dot and a third decimal are refused
  too; a comma key becomes a dot. The "graded" counter counts valid marks only.
- **Save / Submit check:** a mark that is not valid (for example an old 15 on
  a /10 test) stops Save and Submit with a message naming the student.
- **Submit summary:** before submitting, a short sheet shows "47 scored ·
  3 Did not take" with the names, and **Submit** / **Go back**. The 5-second
  Undo stays.
- **"Did not take":** on a submitted list, a blank box shows an amber
  "Did not take" badge (a draft says "Not entered yet").
- **View only (🔒):** a lock in the top bar makes the whole list read-only —
  no box, no keyboard, nothing can be erased. Remembered per test on this
  phone until you tap it again. A search box finds a student by name or code.
- **Result card:** in View only (or on a submitted list) tap a student to open
  a private full-screen card — the mark big and clear, plus that student's
  other results in the same class and subject this semester, and "Marks so
  far" ("Did not take" is shown, not counted). Works offline for the mark
  itself; other results need internet.
- **Education review on the phone:** mark lists now show each student's
  score and "Did not take", with scored / did-not-take counts and the average
  (before, only attendance letters were shown).
- New endpoint `GET /api/v1/grades/student-results`. No schema change; no new
  packages.

**Lists: Load more (same build).**

- **Members:** the list still loads as you scroll, and now ends with
  "Showing 60 of 440 members" and a **Load more (380 left)** button
  ("✓ All 440 shown" at the end).
- **Review inbox (Education / Mezmur):** shows "Showing 50 of 120 packets" and
  **Load more**; extra pages are saved on the phone for offline use too.
- **Fix — Mezmur review inbox was empty on the phone:** the server sends the
  Mezmur list in a different field; the app now reads it.
- **Attendance warnings:** more than 100 warnings can now be reached with
  **Load more**.
- **Education → Enroll a student:** search shows how many match and
  **Load more** after the first 20.
- Server: `GET /api/v1/enrollment/search` takes optional `offset` and
  `with_total=1`; the review lists send totals with `with_total=1`. Older
  apps get the same answer as before. No schema change; no new packages.

## 1.7.2 (build 39) — Teacher "My Timetable" (Weekly · Daily · Monthly)

Mobile side of the Education timetable builder (web, `sql/077`). Read-only.

- **Timetable tab** in the teacher bottom navigation (Home · Attendance ·
  Grades · Timetable · Profile) in addition to the Home card. Views are
  ordered Daily · Weekly · Monthly and open on **Daily**; the top of the
  screen is one continuous header (title, Today button, greeting and date).
- **My Timetable** card on the teacher home opens the new timetable screen:
  Weekly view (Mon–Sun strip with lesson dots, week navigation), Daily view
  (day navigation, "Back to Today"), Monthly view (Ethiopian month grid,
  including ጳጉሜን), a Today button, and a period list with time, subject,
  class, room and a "Now" highlight for the lesson in progress.
- **Makeup / extra classes** added by Education appear on their date with a
  "Makeup" badge.
- **Ethiopian calendar only** — every date on the screen is EC.
- **Works offline**: the last loaded timetable is kept on the phone (per
  user) and shown with an offline banner; it is wiped on sign-out.
- Teachers see only their own schedule; the phone never edits the timetable
  (Education builds it on the web).
- New endpoint `GET /api/v1/timetable/week` (and `/today`). No schema
  change; no new feature flag; no new packages.

## 1.7.1 (build 38) — Attendance sheet reopen + review bells

Mobile side of the attendance sheet review changes. Requires the server
change that adds `sql/073`.

- **Reopen / reject an approved sheet** (Education and Mezmur department
  accounts only): the review detail of an *approved* attendance sheet now
  shows "Reopen for correction" and "Reject sheet". Both ask for a reason
  (3–500 characters); the server recalculates attendance warnings and
  notifies the teacher / taker. Admins never see these buttons (server
  refuses them anyway).
- **Review history** on every attendance review detail — who approved,
  returned, reopened or rejected the sheet, when (Ethiopian calendar) and
  why.
- **Bell deep-link for teachers and takers**: a "sheet returned / rejected"
  notification opens that exact sheet (class + date, or section + date)
  so the correction can be made at once.
- Return / reject dialogs name the action (Return / Reopen / Reject)
  instead of always saying "Return".
- Admin accounts no longer see Return / Reject on attendance sheets
  (Education attendance packets and every Mezmur packet) — those decisions
  belong to the department; Approve stays.
- No schema change; no new feature flag.

## 1.7.0 (build 37) — Attendance warnings

Mobile side of attendance warnings. Everything is
behind the server feature flag `FEATURE_ATTENDANCE_V2` (`attendance_v2`
in `/app/config`); with the flag off the app looks and behaves exactly
like 1.6.10.

- **Warnings screen** (Education, Mezmur, HR, Information homes): open and
  closed attendance warnings per department, with Open / Closed filter,
  kind filter (Consecutive / Low month), search by name or code, and
  "Load more" paging. Counts per department come from the server.
- **Warning detail**: stage, streak length, absence dates (Ethiopian
  calendar), current month / year attendance rate, history events, and the
  member's status in the other department when the viewer may see it.
  Opens the member profile with one tap.
- **Close with outcome** (department staff, HR, Information only — admins
  are read-only, same as the web): Contacted / Excused / Escalated /
  Deactivated / No Action, with an optional note (max 500 characters).
  Closing never changes `members.status`.
- **Bell deep-link**: tapping an `attendance_warning` notification opens
  the warning itself; older alerts without a warning id still open the
  member profile as before.
- **Roster marker** (read-only): Education class sheets and Mezmur section
  sheets show a small warning pill next to a member who has an open
  warning. Teachers and attendance takers see the marker but cannot open
  or close warnings.
- **Member profile**: new "Attendance risk" block (per department: current
  streak, month and year rates, open warning) for roles that may read it;
  hidden silently otherwise or when offline.
- Home tiles: "Warnings" tile on Education (also honours the server tile
  list), Mezmur (staff only), HR and Information homes.

Server: `/api/v1/attendance/warnings`, `/counts`, `/{id}`, `POST
/{id}/close`, `GET /members/{id}/attendance-risk`; Education roster and
Mezmur sheet rows now carry an additive `warning` key. All dates shown to
users are Ethiopian calendar.

## 1.6.10 (build 36) — Report card: foldable A5 booklet, totals only

The report card is redesigned as a classic **foldable report card**: an A4
portrait sheet printed double-sided (duplex, flip on SHORT edge) and folded
along the middle into an A5 card, per the approved design:

- **Front cover** — school logo in a gold seal, Amharic + English school
  names, "Student Report Card", year badge, student name/class/ID strip.
- **Inside** — student profile, four big metrics (overall, grade, rank,
  attendance), attendance with reflections; then **one totals-only table**:
  Subject | 1st sem. | 2nd sem. | Annual | Grade. The per-assessment
  ledger is gone — individual scores are not shown on the card.
- **Semester subjects** (one semester only) appear in the annual column
  with their semester result — a subject is a subject.
- **Back cover** — grade scale, how-to-read notes, office-use and
  parent/guardian signature lines, school stamp box.
- Semester views get the same booklet with a single result column.
- Print: two exact A4 portrait pages per card; duplex short-edge; fold.

Web admin only — the app itself is unchanged; the version bump keeps the
single-version convention.

## 1.6.9 (build 35) — Report card: A4 landscape redesign

The report card is now a proper one-page A4 **landscape** document with a
readable type scale, following the approved reference design:

- **Two-column layout** — left: student profile, key metrics (overall,
  grade, rank, attendance), annual subject summary, attendance &
  reflections, grade scale and signature lines; right: the full
  **assessment ledger**.
- **Assessment ledger** — every assessment of both semesters on one
  aligned grid (name | score/max | weight | weighted %) per subject,
  with each semester's total from 100% in its own column. Semester-only
  subjects show "Not offered this semester" in their off semester.
- **Readable type** — data values 7.6–9.5pt, metric figures 14.5pt,
  Amharic subject names bold; tabular numerals keep columns aligned
  (the reference's 5–7pt text was too small to read comfortably).
- **Same data contract** — annual = average of the two semester totals,
  "—" where a subject does not run; the summary table (Subject | 1st
  sem. | 2nd sem. | Annual | Grade) is unchanged in meaning.
- **Print** — `@page A4 landscape`, one card per page; semester views
  get the matching single-semester skin.
- The dashboards' report-card windows were widened so the landscape
  sheet fits on screen.

Web admin only — the app itself is unchanged; the version bump keeps the
single-version convention.

## 1.6.8 (build 34) — Report card layout: statement table

The annual report card's detailed section is now ONE consolidated table
(the professional transcript pattern) instead of stacked subject cards —
compact rows, clear hierarchy, fits one printed page:

- **Subject header rows** open each subject (name, English name,
  full-year / semester-only note).
- **One row per assessment** — Score | Max | Weight | % — with a thin
  bilingual semester divider above each group.
- **Shaded subtotal row per semester** ("1st Semester total (from 100)").
- **Bold annual row** per subject ("Annual — average of the two
  semesters") with the grade chip; a still-running subject says
  "continues next semester".
- Semester-only subjects keep the explicit "— Not offered this semester"
  dash in their off semester.
- The summary table (Subject | 1st Semester | 2nd Semester | Annual) and
  the semester views are unchanged.

Web admin only — the app itself is unchanged; the version bump keeps the
single-version convention.

## 1.6.7 (build 33) — Detailed semester report card (term-close model, release 3)

The annual report card now shows the semester-based model in full detail
(each semester closes from 100%; the annual figure is the plain average
of the two semesters):

- **Detailed section** — for every subject, each assessment is listed one
  by one under its semester (score, weight, percentage), with that
  semester's total from 100% right next to the semester heading.
- **Summary section** — a compact table: Subject | 1st Semester |
  2nd Semester | Annual (average) | Grade.
- **Semester-only subjects** show a clear "—" (not offered this semester)
  in the semester they don't run — never a misleading zero or blank.
- **Annual = plain average** — the annual score of a full-year subject is
  (Semester 1 + Semester 2) / 2. The per-year s1/s2 weights are no longer
  applied (kept in the year settings for history). A full-year subject
  with only Semester 1 recorded still shows as "continuing", never a
  half-year average presented as final.
- Semester report views (choosing a specific semester) keep their
  existing single-semester layout.

Web admin only — the app itself is unchanged from 1.6.2; the version bump
keeps the single-version convention.

## 1.6.6 (build 32) — Per-semester 100% weight budget (term-close model, release 2)

The 100% assessment budget is now **per semester** for every subject, on
every surface — each semester's report closes from 100% and the next
semester starts from 0, so a subject's assessments must total 100% *in
each semester*, not 100% across the year.

- **Website (Education Department)** — creating, updating or moving a
  test charges the budget of the semester it lands in (create/update
  accept the same validated semester override as before); messages now
  name the semester ("Assessments in 1ኛ ሴሚስተር already total 90%, only
  10% remaining this semester"). Applying a template to a semester
  replaces only THAT semester's un-graded scheme — the other semester's
  assessments and marks are untouched (previously it wiped the whole
  year, which also blocked Semester 2 templates after Semester 1 had
  grades).
- **Mobile API** — the same per-semester budget on every creation path,
  including the batch template apply, which previously had **no weight
  validation at all**. Template items are validated (each weight 1–100,
  total ≤ 100%) before anything is deleted.
- **Teachers never create assessments** — reaffirmed and tightened: the
  department-only 403 gate stays, and the dead "New Assessment" dialog
  that lingered in the teacher app (unreachable code) plus its API
  client method were removed. Assessment setup is the Education
  Department's job, on the web console.

## 1.6.5 (build 31) — Semester close & reopen (term-close model, release 1)

The school's reporting model is semester-based: when the Education
Department flips the current semester, the previous one is CLOSED —
teachers can view its marks for analysis, but editing belongs to the
Education Department (the PowerSchool / Skyward pattern: locked reporting
term + administrator-approved corrections).

- **Teacher app hero box** — the home banner now shows which semester is
  running ("አሁን ያለው ሴሚስተር · 1ኛ ሴሚስተር"), and the Grades screen gets a
  semester switcher: the current semester is the default working view;
  other semesters are viewable (read-only) for analysis.
- **Server-enforced close** — saving or submitting marks into a closed
  semester is refused on BOTH the website and the app (409 TERM_CLOSED)
  with a clear message. Teachers keep full read access; the Education
  Department, School Admin and Super Admin always pass the gate.
- **Reopen for corrections** — new Education action (`reopen_term` /
  `close_term_reopen`, sql/067): the department can reopen a closed
  semester so teachers can fix wrong marks, then close it again. Setting
  a new current semester re-closes every reopened window automatically.
- **Visible locks** — the website's grade entry shows a red "Semester
  closed" notice and disables the sheet; the app shows a "Closed
  semester — read only" bar; assessment lists tag closed semesters.
- **Assessment lists are semester-scoped** — the app's assessment list
  (and the API default) now shows the current semester's tests; legacy
  tests with no semester stay visible until the department assigns them.
- **Audit** — the website's grade saves now write the same activity log
  entries the app already wrote, including the semester context.

Deploy note: run `sql/067_semester_reopen_flag.sql` on the database
(one idempotent column addition) and upload the changed server files.

## 1.6.4 (build 30) — Grade-entry semester fence (web admin; no app changes)

Web admin only — the app itself is unchanged from 1.6.2; the version bump
keeps the single-version convention.

- **Semester fence on grade entry** — teachers now see which semester their
  marks are being recorded into ("Recording into: 1ኛ ሴሚስተር") on both the
  grade-entry screen and the marklist submission screen. If a test belongs
  to a different semester than the current one, an amber notice explains
  that marks will back-fill into that semester — the boundary after a
  semester switch is never silent again.
- **Assessment creation can target a semester** — the Education Department
  may assign a test to any semester of the active year when creating it
  (default: the current semester); tests with no semester are flagged in
  the teacher's list.
- **Semester re-assignment for existing tests** — moving a test to another
  semester (of the active year) now also re-stamps its recorded marks in
  the same transaction, so all marks of one test always sit in one
  semester. This is the cleanup path for legacy tests with no semester.

## 1.6.3 (build 29) — Academic Year admin fixes (web admin; no app changes)

This release is a web-admin maintenance release — the app itself is unchanged
from 1.6.2, and the version is bumped only to keep the single-version
convention. If you still have the 1.6.2 APK, no action is needed.

Web admin (felegekidusan.com/admin):

- **Academic Year modal fixed** — on some browsers the "Add Year" form was
  covered by the page (the table and sidebar drew over it and the Save button
  could not be clicked). All admin pages now force-refresh their stylesheets
  on every release, and the modal stacking no longer depends on the shared
  stylesheet loading.
- **Year dates** — start/end dates are now validated (real dates, end after
  start) and pre-filled with the national calendar suggestion
  (Meskerem 16 → Sene 30). Semester dates must fall inside the year's dates.
- **Semesters are managed by the Education Department** — the Edu Dept
  dashboard can now add, edit, delete and set the current semester. Creating
  the academic year itself stays with the School Admin.
- **Current semester** — can now only be set on a semester of the active
  year (previously it could be set on a past year's semester by mistake).
  The semester list also shows a "dates suggest now" hint when today falls
  inside a semester's saved dates.
- **Year-end reminder** — when the active year's end date has just passed,
  the School Admin dashboard suggests running the Year Rollover.
- **Year save charset repair** — the production database's academic_years
  table was still latin1 (restored from the old server), so saving a year
  with the default Amharic name failed with a generic error. Run
  `sql/066_academic_year_charset_repair.sql` once (phpMyAdmin) to convert
  the table to utf8mb4; the save path now also reports the real cause and a
  log reference if anything ever fails again.

## 1.6.2 (build 28) — Automatic hymn list rebuild after the server move

The app now rebuilds its hymn library from the current system whenever the
system's dataset changes identity — fixing hymns from the old system that
kept appearing after the move to felegekidusan.com.

- Hymns that came from the old system's database are removed
  automatically on the first sync; no reinstall or manual clearing.
- Any hymn edits still waiting to upload are kept and re-sent to the
  current system.
- Accounts, logins, downloads and settings are untouched.

## 1.6.1 (build 27) — New address: felegekidusan.com

The system now lives at its own address: felegekidusan.com. This update
points the app at the new address so it keeps working after the old
address (felegekidusan.arkeonethiopia.com) is retired.

- The app now connects to https://felegekidusan.com/api/v1 — the same
  system, the same account and the same data; only the address changes.
- The Android network security configuration now lists felegekidusan.com.
- Everything else is unchanged: attendance, mezmur, messages, offline
  sync and automatic updates work exactly as before.

## 1.6.0 (build 26) — Faster attendance & HR retirement

Taking attendance is now faster: every student starts as Absent, so you
only mark the ones who attended — tap Present/Late/Excused or scan their
QR card; Save is always available. Pausing mid-marking no longer causes
sync errors, and HR attendance has been retired: HR now views combined
attendance reports from the Education and Mezmur departments.

- Class and Mezmur sheets open with everyone marked Absent (the safe
  default) — presence is never assumed; only a tap or a QR scan marks it.
- Save and Submit are always available; the old "mark every student"
  blocker is gone.
- QR scan marks Present instantly and tells you kindly when a member is
  already registered.
- Drafts now sync exactly what you marked — no more rejected syncs when
  you pause mid-sheet.
- HR attendance removed: HR staff see combined Education + Mezmur
  reports on the school web dashboard; everything recorded before the
  change stays saved and readable.

## 1.5.1 (build 25) — App lock rate limiting, update pipeline & security hardening

- App lock passcode rate limiting now features real-time countdown throttling and dynamic multi-digit indicator dots.
- Super Admin mobile app release manager and direct self-hosted OTA updates without cPanel/FTP workflows.
- Strict security boundaries, non-diagnostic exception handling, and offline sync resilience.

## 1.5.0 (build 24) — Safer access and recoverable offline work

- Live role, status, and teacher-assignment changes now reconcile before the
  app continues under an old authorization scope.
- Authentication expiry preserves owner-bound offline attendance, grades,
  communication, and hymn operations for safe same-account recovery.
- Sync Center shows queued and needs-attention work without exposing another
  user's payload, and recovery actions target the exact operation reviewed.
- Legacy attendance/grade replacement and settlement are atomic and
  operation-identity safe across rapid edits, retries, crashes, and races.
- Operations can remotely pause outbound background drains without disabling
  durable local saves or deleting queued rows.

## 1.4.0 (build 23) — Works offline (Messages)

The Messages feature is now offline-first, like WhatsApp: the phone's
database is what you see, and the network only refreshes it.

### Opens instantly, everywhere

- **Instant thread list** — Messages opens with your conversations
  immediately; the first-ever open is the only one that waits for the
  network.
- **Instant conversations** — every thread opens from its local
  history, airplane mode included, receipts and all. History you
  scrolled through before stays available offline ("Load older"
  serves it from the phone).
- **Instant badge** — the bell shows your unread count the moment the
  app starts, not after the first network round-trip.

### Sends that survive

- **Send in airplane mode** — a message leaves the composer
  immediately with a clock icon and delivers itself the moment the
  connection returns. The phone keeps trying on its own (with
  smart, staggered retries), so you never tap "retry" for a
  connection problem again.
- **Real failures are honest** — if the school's server rejects a
  message on the merits, the bubble turns red with the reason once;
  tap to retry, long-press to discard.
- **Drafts are kept** — a half-written reply survives closing the
  app, per conversation, like WhatsApp.

### Behind the scenes

- Messages are stored locally (with the same sign-out wipe as all
  your other data on a shared phone) and synced with cheap
  conditional requests — idle polls cost almost nothing.
- Each send carries a unique tag so a flaky connection can never
  post the same message twice (server migration 046).
- Local history is capped at the newest 500 messages per
  conversation (older ones load from the server on demand).

## 1.3.0 (build 22) — Communication polish

A full accessibility and design pass over Messages and Notifications.

### Readable by everyone (accessibility)

- **High-contrast messages** — your own messages now use a light
  background with dark text (WhatsApp-style); timestamps, ✓✓ "Seen"
  and failed-send states are clearly visible on any screen, in
  sunlight, for low-vision users. Every text pair meets the WCAG AA
  contrast standard.
- **Screen-reader support** — unread badges, receipts, deleted
  placeholders and the notification bell now announce themselves
  properly to TalkBack/VoiceOver, including the unread count.
- **Bigger touch targets** for message menus and the bell badge.

### Messages that feel right

- **Message grouping** — streaks from one sender collapse into tidy
  groups: name once, time on the last bubble, tight spacing.
- **Copy & links** — long-press any message to copy it. Links,
  email addresses and phone numbers in messages are tappable (open
  in browser / mail / dialer).
- **Drafts** — a half-written reply survives leaving the
  conversation and comes back when you return.
- **New-message pill** — reading history while new messages arrive
  shows a "N new messages ↓" pill instead of jumping the screen.
- **Thread times** — the conversation list shows when each thread
  was last active (Today 14:05 / Yesterday / weekday / date).
- **Searchable recipients** — the new-conversation picker has a
  search box, removable chips and a selection counter.

### A trustworthy inbox

- **Actionable alerts** — tapping an alert about a person now opens
  that person's profile directly (in addition to marking it read).
- **Offline never loses your content** — a failed refresh keeps
  your rows and shows a slim "offline — showing recent" banner
  instead of wiping the list.
- **Per-type icons** — attendance, enrollment, tasks, roles and
  member events each get their own icon.
- **No more flashing skeletons** when returning from a conversation
  or marking everything read.
- **Battery-friendly** — the notification poll pauses whenever the
  app is in the background and refreshes immediately on return.

### For the server administrator

- No new migrations in this release (044 + 045 from the previous
  release still apply if not yet applied). The alert deep-link uses
  data the server already stores — an additive field older apps and
  the web simply ignore.

## 1.2.0 (build 21) — Communication parity

The Messages and Notifications experience now matches the web
Communication Center feature for feature.

### Conversations

- **Read receipts** — your messages show ✓✓ "Seen" once everyone in
  the conversation has read them; ✓ while still unread. Receipts
  update live while the conversation is open.
- **Edit & delete your messages** — tap the ⋯ on one of your messages
  (or long-press it) to edit it or delete it for everyone. Deleted
  messages leave a "This message was deleted" placeholder; the
  content is gone for good.
- **Load older messages** — long conversations page in from the
  server as you scroll up; your position never jumps.
- **Faster, more reliable sending** — your message appears the
  moment you tap send; if the network drops, it stays in place with
  a one-tap retry.
- **Day separators** — Today / Yesterday / dates between message
  groups.
- New conversations: pick several recipients at once, as before.

### Notifications inbox

- **All / Unread filter** on alerts, with the live unread count.
- **Load older** on both alerts and announcements — history pages in
  on demand instead of being capped at the first 40.
- **Instant mark-as-read** — tapping an alert or announcement clears
  its unread state immediately (the save happens in the background
  and self-corrects if it fails).
- **Clearer offline states** — "Could not load + Retry" screens when
  the list itself couldn't be fetched, instead of a misleading
  "No announcements".

### Performance & data

- **Idle polling now costs almost nothing** — the app asks the
  server "anything changed?" and a "no" (HTTP 304) carries zero
  payload. On a quiet day this removes the largest recurring
  background transfer the app makes.
- The open conversation also polls conditionally — no change, no
  download, no wasted writes.

### Fixes

- Fixed a build-blocking defect from 1.1.17: five department home
  screens (Admin, Attendance Taker, Teacher, Education Dept, Info
  Dept) had malformed code around the notification bell and could
  not compile. If you are on 1.1.17, update before anything else.
- Kept the notification badge from drifting when marking items read
  while offline (reverts cleanly on failure).

### Notes for this release

- Server side: requires a server with migrations `043`–`045` applied; the new
  message features degrade gracefully — the app simply doesn't show
  them — if the server is older, except the compile fix, which is
  client-only.
- Tasks remain web-only by design.

## 1.1.17 (build 20) — prior release

See repository history.
