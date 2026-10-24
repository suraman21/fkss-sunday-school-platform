# Production incident — Academic Tracking detail views fail

**Target:** `https://felegekidusan.arkeonethiopia.com/admin/dashboard.php?section=academic-tracking`
**Symptom:** Teacher, Subject and Class *detail* panels all render "Details unavailable / Could not load this X right now." Root *lists* work.
**Evidence base:** `uploads/production_database.md` (phpMyAdmin dump of `arkeonet_felegekidusan`, MariaDB 11.4.13 / PHP 8.4.25, generated 2026-10-02) + the repository at `origin/main = d0373d9`.
**Status:** diagnosed, reproduced locally against a database loaded from the production dump, and fixed in code. Migration `056` still has to be applied to production by the operator.

**Labelling:** every claim is marked **FACT**, **INFERENCE — REQUIRES VERIFICATION**, or **UNKNOWN — NOT VERIFIED FROM SOURCE**, per the Master Codebase Understanding Protocol. No code was changed.

---

## 1. Summary

Two independent defects, **both mine**, both introduced by Academic Tracking Phases 3–5. Each causes an uncaught `mysqli_sql_exception`, which `admin/api_education.php` converts into the generic message the screenshots show.

| # | Defect | Breaks |
|---|---|---|
| **A** | Queries filter `assessments.is_active`, **a column that does not exist** in production or in the canonical migration. | Teacher detail, Class detail, (Subject/Class assessment panels) |
| **B** | Three queries read `class_subjects.duration_type` / `.term_id` (added by `sql/056`, **not applied to production**) with **no working fallback**. | Subject detail, Class detail |

A third, pre-existing defect (**C**) is latent but important: the Education-duration feature's "works before the migration" safety net is **dead code on PHP 8.1+**.

**The three screenshots are not one bug.** Teacher fails from A; Subject fails from B; Class fails from both.

---

## 2. The error surface (FACT)

`admin/api_education.php` wraps each tracking action in `try { … } catch (Throwable $e)`. Lines **2017–2025** (teacher), **2138** (subject), **2224** (class):

```php
} catch (Throwable $e) {
    error_log('tracking teacher: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 'code' => 'server_error',
        'message' => 'Could not load this teacher right now.',
    ]);
}
```

**FACT:** the observed text is the catch-all, so a `Throwable` was raised — this is not a legitimate empty-data state.
**FACT:** the real SQL error *is* written to `error_log`. **The server error log will contain the exact `Unknown column …` messages** and is the fastest independent confirmation of everything below.

---

## 3. Defect A — `assessments.is_active` does not exist

**FACT — production schema.** `assessments` =
`id, class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, weight_percentage, max_score, description, due_date, assessment_order, is_published, created_by, created_at, updated_at`
There is **no `is_active`**. The flag is called **`is_published`**.

**FACT — the canonical migration agrees.** `admin/migrations/003_add_assessments.php` defines `is_published TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether grades are published to students'` and never defines `is_active`. Production matches the migration exactly; **production is not "behind" here — the code is wrong.**

**FACT — no migration anywhere adds it.** `grep` across all 58 files in `sql/` returns zero hits for `assessments` + `is_active`.

**FACT — where the invention came from.** The e2e fixture `tests/e2e/academic_intelligence.php` creates `assessments` with `` `is_active` TINYINT(1) NOT NULL DEFAULT 1 ``, added in commit `2a99daa` (Feature 1). Every subsequent test ran against that fictional column.

**FACT — the authoritative precedent uses no such filter.** The established assessment code filters by `class_id` / `subject_id` / `academic_year_id` only:
- `admin/api_subjects.php:730` — `WHERE a.academic_year_id = ?`
- `admin/api_subjects.php:905` — `WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?`
- `admin/backend/services/ReportCardService.php:1216` — `FROM assessments WHERE class_id = ?`

There is **no "active assessment" concept in this system.**

**FACT — affected sites**, all in `admin/backend/services/AcademicTrackingService.php`, all introduced by Phases 3/4/5 (`9822508`, `bcf4aa2`, `9f2864c`):

| Line | Function | Phase |
|---|---|---|
| 556 | `teacherAssessments()` | 3 |
| **819** | **`assessmentCounts()`** | 3 |
| 1314 | `offeringAssessments()` | 4 |
| 1446 | `offeringAssessmentCounts()` | 4 |
| 1905 | `classAssessments()` | 5 |
| **2083** | **`classAssessmentCount()`** | 5 |
| 2210 | `classAssessmentsBySubject()` | 5 |

No other file in the repository filters assessments by `is_active`.

### Why this breaks the *teacher* screen (FACT)

`teacherDetail()` (line 459) calls `teacherIdentity()` → `teacherAssignments()` → **`assessmentCounts()`** (line 792). `teacherAssignments()` touches only `teacher_assignments`/`classes`/`subjects` and is clean — the failure is in `assessmentCounts()`, line 819:

```sql
SELECT class_id, subject_id, COUNT(*) AS n FROM assessments
 WHERE is_active = 1 AND ((class_id = ? AND subject_id = ?) OR …)
```

`assessmentCounts()` returns early when the teacher has no *subject-bearing* assignment (`if (!$pairs) return [];`), so a homeroom-only teacher loads fine. **Verified against the real row for the teacher in the screenshot:**

- `users.id = 18`, `full_name = 'Biruk Teshome'`, `role = 'teacher'` — **FACT**
- assignments: `(class_id=1, subject_id=1, assistant)` and `(class_id=3, subject_id=NULL, homeroom)` — **FACT**

The first assignment is subject-bearing, so `$pairs` is non-empty, the query runs, and it references a non-existent column. ✔ fully explains screenshot 1.

---

## 4. Defect B — `class_subjects.duration_type` / `.term_id` read without a fallback

**FACT — production schema.** `class_subjects` = `id, class_id, subject_id, created_at`. 77 real rows. The string `duration_type` appears **0 times in the entire 9,797-line dump**.

**FACT — `sql/056_subject_duration_and_semester_weights.sql` has not been applied to production.** It is the migration that adds both columns.

**FACT — unguarded readers** in `AcademicTrackingService.php`:

| Line | Function | Phase |
|---|---|---|
| 1122 | `subjectOfferings()` | 4 |
| 1174 | `findOffering()` | 4 |
| 1712 | `classSubjects()` | 5 |

**FACT — verified against the entities in the screenshots:** subject "መሠረተ ሃይማኖት" = `subjects.id = 1`; class "1ኛ ክፍል" = `classes.id = 1`. Both detail paths reach the above functions. ✔ explains screenshots 2 and 3.

---

## 5. Defect C (pre-existing, latent) — the migration-056 fallback is dead code on PHP 8.1+

The Education-duration feature *tried* to degrade gracefully. The idiom used everywhere is:

```php
$stmt = @$conn->prepare($sql056);
if (!$stmt) { $stmt = $conn->prepare($sqlLegacy); }   // ReportCardService:835
```

**FACT:** `@` suppresses diagnostics; it does **not** suppress thrown exceptions.
**FACT:** since PHP 8.1 the default `mysqli_report` mode is `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, i.e. mysqli **throws** on error. Production runs **PHP 8.4.25**.
**FACT:** the shared connection (`config.php:231`, `new mysqli(...)`) never calls `mysqli_report()`. The only four `MYSQLI_REPORT_OFF` calls in the repo are in `api_identity_migration.php`, `tools/health_check.php`, `tools/migrate_identity_codes.php`, `tools/repair_user_role.php` — none in the education request path.

⇒ **Every `if (!$stmt)` guard in this codebase is unreachable**, including the migration-056 fallbacks in `ReportCardService::fetchSubjects` (835) and `api_subjects.php` (501, 574).

**Self-correction:** I earlier recorded these sites as "SAFE" by reading their comments. The comments describe an intent the code does not implement. Comments are not evidence.

**INFERENCE — REQUIRES VERIFICATION:** because `ReportCardService::fetchSubjects()` is therefore expected to throw on production, **report-card generation and Student Tracking are likely broken in production as well** — and have been since the Education-duration feature (`34d5157`→`11be77a`) deployed without `sql/056`, i.e. *before* Phases 2–5. Not shown in the screenshots; must be confirmed by exercising a report card and the Student Tracking detail view, or by reading `error_log`.

**Corroborating logic (FACT-based):** if mysqli were *not* throwing, `assessmentCounts()`'s `if (!$stmt) return $out;` would have returned an empty array and the teacher screen would have **loaded successfully** with zero counts. It did not load. The observed teacher failure is itself evidence that mysqli is in throwing mode.

---

## 6. Resolved open question — `teacher_assignments.teacher_id` (FACT)

Production carries the column comment *"Member ID who is teaching"*, which suggested the code's `JOIN users u ON u.id = ta.teacher_id AND u.role='teacher'` might be joining the wrong table. **It is not.** Actual values are `7, 10, 11, 12, 18 …`, each matching a `users.id` whose `role = 'teacher'`. The comment is stale and misleading; **the join is correct.** No defect. (Note `users.member_id` is the real link to `members`.)

---

## 7. Proposed remediation — NOT APPLIED

Presented for approval; nothing has been changed, and per standing instruction **no migration will be executed against production by me.**

**A. Remove the fabricated filter (7 sites, `AcademicTrackingService.php`).**
Delete `is_active = 1` / `a.is_active = 1`. **Do not substitute `is_published = 1`:** production's real assessment rows carry `is_published = 0`, so that filter would hide all 13 assessments and silently render zeros — wrong data, and a direct violation of the "no fake zeros / keep the four facts distinct" rule. Removing the condition matches the authoritative precedent exactly.

**B. Make the three 056 readers degrade for real** — `try { prepare($sql056) } catch (mysqli_sql_exception) { prepare($sqlLegacy) }`, with `duration_type`/`offering_term` surfaced as `null` ("unclassified"), never guessed.

**C. Repair the same idiom at its origin** — `ReportCardService.php:835`, `api_subjects.php:501`, `:574`. This is a change to `ReportCardService`, which I am normally barred from touching; it qualifies as "a verified defect that directly blocks the workflow", but I will not touch it without your explicit go-ahead.

**D. Correct the test fixture and add a conformance guard.** `tests/e2e/academic_intelligence.php` must define `assessments.is_published`, not `is_active`. Until it does, the suite will keep passing while production keeps failing. A test that diffs fixture DDL against the canonical migrations would have caught all of this.

**E. Deployment action for you, not me:** apply `sql/056` to production. Even after B and C, the duration feature cannot actually function without it.

**Fastest mitigation:** A alone restores Teacher detail. A + B restore all three screens. C is needed before you can trust report cards.

---

## 8. Honest assessment of how this shipped

Phases 2–5 claim schema verification "against `information_schema`". **That verification ran against a fixture I wrote myself**, which contained a column production has never had. The two `information_schema` guard tests pin the fixture, not production. CI run #44's 1,958 passing tests are therefore not evidence about production behaviour for any schema-dependent path.

The Academic Tracking Phase 5 design record (§2) stated `class_subjects = id, class_id, subject_id, duration_type, term_id`. **That is wrong for production** and must be corrected.

I am not claiming this list is exhaustive. An alias-aware sweep of every column reference in `AcademicTrackingService.php` against the production dump found exactly three non-existent references (`a.is_active`, `cs.duration_type`, `cs.term_id`) and no others; the same sweep has not yet been run across the rest of the codebase.


---

## 9. Reproduction (performed, not assumed)

The production dump was loaded into a local throwaway database
(`ssms_prodshape`) so the real schema and real rows could be exercised.
Production itself was never contacted.

Verified first that the local copy has the production shape:
`assessments.is_active` absent, `class_subjects.duration_type` absent,
13 assessment rows, 77 class_subject rows.

**Before the fix — every entry point raised:**

| Entry point | Error |
|---|---|
| `teacherDetail(18)` | `Unknown column 'is_active' in 'WHERE'` |
| `subjectDetail(1)` | `Unknown column 'cs.duration_type' in 'SELECT'` |
| `classDetail(1)` | `Unknown column 'is_active' in 'WHERE'` |
| `studentDetail(2, class 1)` | `Unknown column 'cs.duration_type' in 'SELECT'` |
| `teacherAssessments(18,1,1)` | `Unknown column 'a.is_active' in 'WHERE'` |
| `ReportCardService::getCard(2,1)` | `Unknown column 'cs.duration_type' in 'SELECT'` |
| `ReportCardService::getClassReport(1)` | `Unknown column 'cs.duration_type' in 'SELECT'` |
| `ReportCardService::getClassCards(1)` | `Unknown column 'cs.duration_type' in 'SELECT'` |

**working = 0, broken = 8.** All four tracking screens and the whole
report-card subsystem. The three screenshots were a subset.

**Defect C was proved directly**, not argued from documentation:

```
$stmt = @$conn->prepare("SELECT cs.duration_type FROM class_subjects cs WHERE cs.class_id = ?");
  -> THREW mysqli_sql_exception: Unknown column 'cs.duration_type' in 'SELECT'
  -> VERDICT: the 'if (!$stmt)' fallback is DEAD CODE.
```

**After the fix — working = 8, broken = 0**, on both the pre-056
production shape *and* a post-056 database (the dump with `sql/056`
applied). The duration values are genuinely read when the columns exist
and reported as `NULL` (unclassified) when they do not:

```
ssms_prodshape   056-supported=no   -> 3:NULL         1:NULL            12:NULL
ssms_post056     056-supported=yes  -> 3:'FULL_YEAR'  1:'SEMESTER_ONLY' 12:'FULL_YEAR'
```

## 10. What changed

| File | Change |
|---|---|
| `AcademicTrackingService.php` | Removed `assessments.is_active` from 7 queries. The three migration-056 readers select the offering columns only when the database has them, else literal `NULL`. |
| `SubjectDurationPolicy.php` | New `supportsOfferingDuration(\mysqli): bool`, an information_schema probe cached per connection. It cannot throw on a missing column, unlike the `@prepare` idiom it replaces. |
| `ReportCardService.php` | `fetchSubjects()` chooses its query from the probe instead of the unreachable `@prepare(...) / if (!$stmt)` branch. |
| `api_subjects.php` | Same correction at the three offering-duration sites; the existing "migration 056 required" responses are now actually reachable. |
| `tests/e2e/academic_intelligence.php` | `assessments.is_published` replaces the invented `is_active`. New `SSMS_FIXTURE_PRE056=1` mode builds the production shape. |
| `tests/security/test_schema_conformance.py` | New. 18 tests driving the endpoints against a pre-056 database. |
| `.github/workflows/backend-checks.yml` | Provisions `ssms_pre056_e2e`. |
| Academic Tracking Phase 5 design record | §2 schema table corrected. |

**Deliberately not changed:** `AssessmentTypeService::ensureTable()` uses the
same `@$conn->query(...)` idiom to probe for the `assessment_types` table.
Production **has** that table, so it is not failing, and it is outside this
fix. Documented, not fixed.

## 11. Mutation testing of the new guard

| Mutation | Result |
|---|---|
| Reintroduce `is_active` in `classAssessmentCount()` | **caught** (2 tests) |
| Reintroduce `is_active` in `assessmentCounts()` (teacher path) | **caught** (3 tests) |
| Drop the capability check in `classSubjects()` | **caught** — `test_class_subjects_loads` |
| Restore the `@prepare` idiom in `ReportCardService` | **caught** — `test_student_detail_loads` + the idiom test |

4 mutations, 4 caught, 0 survivors.

Two existing tests in `test_class_tracking.py` failed after the fix because
their own `INSERT`s still named `is_active`; their guard-the-guard assertions
reported the row missing, which is the behaviour those guards exist for. The
column lists were corrected; no assertion was weakened.

## 12. Test state

Full `tests/security` run: **1962 passed, 12 failed, 2 skipped, 926 subtests**.
The 12 failures are the pre-existing environmental ones (11 `comm_e2e`,
1 `destructive_guard`) that require CI's seeded databases; they fail
identically on an unmodified checkout and are unrelated to this change.
Not claiming zero bugs.

## 13. Still outstanding for the operator

1. **Apply `sql/056` to production.** The code now survives without it, but
   the subject-duration feature cannot function until it is applied. I did
   not and will not run it.
2. **Check the server `error_log`** for `Unknown column` entries to confirm
   the timeline and see whether anything else is failing silently.
3. **Verify report cards** once deployed — they were in the blast radius.
4. Rotate both PATs.
