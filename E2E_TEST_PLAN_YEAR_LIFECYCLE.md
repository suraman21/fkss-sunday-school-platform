# E2E Test Plan — Year Lifecycle (R1–R5) + Offline Preservation
Protocol: Universal AI Software Change / Patch Protocol §7–§10
Date: 2026-11-06 | Env: fkss-sunday-school-platform main 6b396c5 + patch
Tester: Agent (code-side) + School Admin (user-side manual)
Scope: Set Active guard, validation, rollover teacher copy, copy assessments, hint fix, offline WIP preserved

## 1) User-Side E2E (Manual — School Admin on non-prod, two browsers + phone)

> Run on a non-prod clone with backup. Use two browser sessions (School Admin + Edu Dept) and one teacher phone.

### T1 — Set Active on empty Upcoming year (R1)
1. As School Admin: `Academic Year & Semesters` → note Active = 2018 EC (127 students).
2. `Add Year` → 2019 EC, start 2026-09-01 end 2027-08-31, weights 50/50, add 2 semesters S1/S2. Do NOT run rollover.
3. Click ✓ `Set Active` on 2019 EC → enter `SWITCH` in danger dialog → Submit.
4. **Expect:** Second dialog appears: title `Activate EMPTY year "2019 EC"?`, confirms `ACTIVATE EMPTY`, explains mobile will be empty and suggests Rollover. Press Cancel → year stays 2018 Active. Good (blocked).
5. Repeat → second dialog → type `ACTIVATE EMPTY` → Submit → toast `Active year changed — the new year is empty…` → `loadYears()` refreshes, 2019 now Active, 2018 Closed. Verify: `api_education.php?action=get_academic_years` shows 1 Active.

### T2 — Validation warnings (R3)
1. Create Upcoming year 2020 EC with **no dates** and **no semesters**.
2. Try `Set Active` → `SWITCH` → expect second dialog `Activate year "2020 EC" with warnings?` listing: “no start/end dates…”, “no semesters…”. Shows `ACTIVATE ANYWAY`.
3. Enter `ACTIVATE ANYWAY` → succeeds but toast lists warnings. Check DB: `academic_years.start_date IS NULL` still.
4. Fix dates to include today, add semesters, set weights 60/40 (not 100) → try again → warning `weights total 100%` appears. Correct to 50/50 → no warning, single `SWITCH` succeeds.

### T3 — Rollover carries students + teachers (R4)
1. Ensure 2018 EC Active with 2 classes (level 1,2), 5 students enrolled, 2 teacher_assignments scoped to 2018.
2. Ensure 2019 EC Upcoming empty, with same class catalog (classes are global).
3. `Year Rollover` → Close 2018 → Open 2019, mode `carry_forward`, preview shows `5 active student(s)`. Run → type `ROLLOVER` → success toast `5 student(s) carried … and 2 teacher assignment(s) copied`.
4. Verify DB:
   ```sql
   SELECT COUNT(*) FROM class_enrollments WHERE academic_year_id=(SELECT id FROM academic_years WHERE year_name='2019 EC') AND status='active'; -- 5
   SELECT COUNT(*) FROM teacher_assignments WHERE academic_year_id=(SELECT id FROM academic_years WHERE year_name='2019 EC') AND is_active=1; -- 2
   SELECT status FROM class_enrollments WHERE academic_year_id=(SELECT id FROM academic_years WHERE year_name='2018 EC') LIMIT 1; -- completed
   ```
5. As teacher on phone: login → `AttendancePreparationService` → `/attendance/preparation` returns 200 with 5 students and 2 classes under new year; Edu Dept web shows `Current (2019 EC)` with 5 enrolled (not 0).

### T4 — Rollover hint fix (R2)
1. Set Active year end_date to yesterday (e.g., `CURDATE() - INTERVAL 1 DAY`) via Edit Year.
2. Reload `school_admin` dashboard → amber `rolloverHint` bar appears: `The active year (… EC) ended … — run the Year Rollover…` with `Run Year Rollover` button (previously never showed due duplicate `display:none`).
3. Dismiss → disappears; reload → reappears until rollover run.

### T5 — Copy Assessments (R5)
1. In 2018 EC: Edu Dept creates 3 assessments: Quiz1 (S1), Midterm (S1), Final (S2) for class A / subject Math, with term_ids mapped to 2018 terms.
2. After rollover to 2019 EC, verify 2019 has 0 assessments:
   ```sql
   SELECT COUNT(*) FROM assessments WHERE academic_year_id=(SELECT id FROM academic_years WHERE year_name='2019 EC'); -- 0
   ```
3. School Admin → `Copy Assessments` → FROM 2018 → TO 2019 → preview → type `COPY` → toast `3 assessment template(s) copied…`.
4. Verify:
   ```sql
   SELECT COUNT(*) FROM assessments WHERE academic_year_id=(SELECT id FROM academic_years WHERE year_name='2019 EC'); -- 3
   -- No grades copied:
   SELECT COUNT(*) FROM academic_records WHERE academic_year_id=(SELECT id FROM academic_years WHERE year_name='2019 EC'); -- 0
   -- Term mapping correct: each new assessment.term_id belongs to 2019's term_number
   ```
5. Run copy again → toast `0 copied, 3 already existed and were skipped` (idempotent).
6. Delete one assessment in 2019, run copy again → 1 copied (only missing one restored).

### T6 — Reopen closed year (existing, must not break)
1. As School Admin, click Reopen (↺) on closed 2018 → type `REOPEN` → 2018 becomes Active again, 2019 becomes Closed. Verify new records now stamped to 2018 (write guard returns 2018 id).

### T7 — Mobile empty vs web time-travel (regression)
1. After T3 rollover, as Edu Dept web: top bar pill shows `Current (2019 EC)`. Switch pill to `2018 EC` → amber `VIEWING PAST YEAR — READ ONLY` banner appears, all Save buttons disabled (`opacity .45`). Switch back via `Return to Current Year` → banner gone.
2. As teacher mobile (still on 2019 Active): roster shows 5 students (not 0), because teacher_assignments were copied.

### T8 — Delete protection (regression)
1. Try to delete Active 2019 → error `ACTIVE year cannot be deleted`.
2. Try to delete Closed 2018 (has enrolments) → error `Closed (past) years are permanently protected`.
3. Create empty Upcoming 2021 EC (no records) → delete → succeeds. Verify `academic_terms` cascade deleted.

### T9 — Offline WIP preserved (protocol requirement)
1. After year fixes, verify Mobile offline files still compile: `flutter test` for attendance_preparation_* suites still pass (no files deleted, no schema lost).

**Pass criteria:** All 9 manual scenarios show expected toasts/dialogs and DB counts; no data deleted; closed years remain viewable read-only.

---

## 2) Code-Side E2E (Automated — static + DB-mock)

Executed by `tests/year_lifecycle_e2e.py` and `tests/security/test_year_lifecycle_guard.py`:

- Static grep: `ay_validate_target_year` exists in academic_year.php, returns errors/warnings.
- Static grep: `confirm_activate_empty` and `confirm_activate_invalid` codes in api_education.php, with `ACTIVATE EMPTY` / `ACTIVATE ANYWAY` phrases, and `ay_validate_target_year` call.
- Static grep: `teacher_assignments` INSERT in year_rollover.php with `academic_year_id = ?` filter and `INSERT IGNORE`.
- Static grep: `copy_assessments` action registered in requirePostActions and manageActions, with CSRF + ay_is_readonly guard, term Map via term_number, idempotent dup check, activity_logs.
- Static grep: `rolloverHint` style fixed (single display:none), `copyAssessmentsModal` + `openCopyAssessmentsModal` exist in school_admin.php.
- Mock DB: Simulate empty upcoming year → handler returns 403-like guard error; simulate non-empty → handler proceeds to ay_switch_active; simulate missing dates → validation returns error.
- Security: Verify no hard-coded token, no `is_current` bypass, no `DELETE FROM` on rollover (only UPDATE completed), no promotion outside transaction.

**Pass criteria:** All greps pass, mock DB flows return expected JSON codes.

---

## 3) Failure Mode Tests

- Switch to non-existent year id 99999 → `That academic year does not exist.`
- Copy assessments with same source/target → `Source and target year must be different.`
- Copy assessments without COPY phrase → `Type COPY to confirm…`
- Rollover while time-travelling (session ay_view_year_id = past) → 403 `You are viewing a past year (read-only)…`
- Concurrent Set Active (two admins) → second gets `Already the active year` or atomic switch via transaction (no double Active; check `SELECT COUNT(*) WHERE status='active' =1`).

---

## 4) Regression Checklist

- HR registration → `ay_require_writable` still blocks when viewing past year.
- Edu Dept promote one student (`api_education?action=promote`) still atomic with `affected_rows` guard.
- Delete class still respects active_enrollments check.
- Mobile preparation still requires exactly 1 active year with today in range (unchanged).
- Existing attendance/grade flows unchanged (no new columns, no schema migration).

---

## 5) Execution Log

Code-side: `python3 tests/year_lifecycle_e2e.py` → 18/18 pass (see artifact `YEAR_LIFECYCLE_TEST_REPORT.md`).
User-side: Run manually on non-prod clone per T1–T9, record DB counts and screenshots.

## 6) Honest Completion

If any T1–T9 fails, do NOT mark complete — file bug, fix, re-run. Never claim production-grade without manual DB counts.
