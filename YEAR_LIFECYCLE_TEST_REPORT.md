# Year Lifecycle — E2E Test Report (Protocol §9–§10)
**Branch:** main 6b396c5 + R3/R5 patch (pending) → `tests/year_lifecycle_e2e.py` 42/42 PASS  
**Date:** 2026-11-06 09:40 Africa/Addis_Ababa | Tester: Agent (code) + School Admin (manual)  
**Scope:** R1 guard empty Set Active, R2 hint fix, R3 validation, R4 teacher copy on rollover, R5 copy assessments

## Code-Side E2E — Automated Static + Mock DB
**Runner:** `python3 tests/year_lifecycle_e2e.py`

```
[PASS] ay_validate_target_year exists
[PASS] checks missing dates
[PASS] checks semesters
[PASS] checks weights
[PASS] ay_switch_active atomic
[PASS] R1 confirm_activate_empty
[PASS] R3 confirm_activate_invalid
[PASS] ACTIVATE EMPTY / ACTIVATE ANYWAY phrases
[PASS] Empty enrolment check (R1)
[PASS] delete_year protected (regression)
[PASS] Rollover marks completed not deleted
[PASS] copy_assessments endpoint + params + term mapping + idempotency + logging
[PASS] R4 teacher copy INSERT IGNORE, year-scoped, report field, atomic, rollback
[PASS] rolloverHint fix, copyAssessmentsModal, button, JS handlers
[PASS] No token leakage
[PASS] Mock failure modes (4/4)
— 42 passed, 0 failed
```

**Security/Regression — existing suites:**
- `test_academic_year_admin_fix.py` 21/21 PASS (semester ownership, date integrity, rollover reminder still pinned)
- `test_academic_year_charset_fix.py` 9/9 PASS (charset migration idempotent, exception hardening)
- `test_api_idempotency.py` 5/5 PASS (no double execution)

**Failure-mode mocks (manual grep):**
- `Set Active 99999` → `That academic year does not exist.`
- `Copy same source/target` → `Source and target year must be different.`
- `Copy without COPY` → `Type COPY to confirm`
- `Rollover while time-travelling` → `You are viewing a past year (read-only)…` (ay_is_readonly guard)

## User-Side E2E — Manual DB Counts (non-prod clone)
*Execute on a cloned DB with backup; record counts + screenshots.*

| # | Scenario | Steps (summary) | Expected DB / UI | Result |
|---|----------|-----------------|------------------|--------|
| T1 | Empty Set Active blocked | Create 2019 EC empty → Set Active → SWITCH | 2nd dialog `Activate EMPTY year "2019 EC"?` → Cancel keeps 2018 Active; `ACTIVATE EMPTY` succeeds with `Active year changed — the new year is empty…` | ☐ |
| T2 | Validation warnings | Create 2020 EC no dates/no semesters → Set Active → SWITCH | Dialog `Activate year "2020 EC" with warnings?` listing 2 issues → `ACTIVATE ANYWAY` succeeds, warnings toasted | ☐ |
| T3 | Rollover carry + teachers | 2018: 5 enrolments +2 teacher_assignments → Rollover 2018→2019 carry_forward | `class_enrollments` 2019=5 active, 2018=completed; `teacher_assignments` 2019=2; Mobile prep returns 5 students | ☐ |
| T4 | Hint appears | Set Active end_date = yesterday → reload dashboard | Amber `rolloverHint` visible with `Run Year Rollover` (was hidden before) | ☐ |
| T5 | Copy assessments | 2018: 3 assessments → 2019:0 → Copy Assessments 2018→2019 COPY | 2019 assessments=3, academic_records=0, dup 2nd run `0 copied, 3 skipped` | ☐ |
| T6 | Reopen | Reopen closed 2018 → REOPEN | 2018 Active again, new writes stamp to 2018 | ☐ |
| T7 | Time-travel read-only | After rollover, switch pill to past year | Amber banner + disabled Saves; Return brings back to Current | ☐ |
| T8 | Delete protection | Try delete Active/Closed/empty Upcoming | Active/Closed blocked, empty Upcoming deleted | ☐ |
| T9 | Offline preserved | `flutter test` attendance_preparation_* | All suites pass | ☐ |

**Honest completion:** Code-side 42/42 PASS. Manual T1–T9 must be ticked on non-prod with `SELECT COUNT(*)` outputs before marking production-ready. Any fail → file bug, fix, re-run.

## Diff Inspected (this patch)
```
admin/backend/academic_year.php       | ay_validate_target_year (R3) 38 lines
admin/api_education.php               | R1+R3 expanded guard + copy_assessments 120 lines
admin/dashboards/school_admin.php     | R1+R3 frontend + R5 modal/button/JS 62 lines
```
No schema migration, no DELETE of history, no permission widening beyond `school_admin|super_admin|edu_dept` for copy.

## Next
Push `main` + `main→dev` sync, revoke token, attach this report + `E2E_TEST_PLAN_YEAR_LIFECYCLE.md` to PR.
