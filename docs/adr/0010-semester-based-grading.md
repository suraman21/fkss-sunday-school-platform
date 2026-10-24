# ADR-0010: Semester-based grading: 100% per semester, annual score as a plain average

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-08 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Annual results previously used configurable semester weights, which teachers and parents found hard to follow.

## Decision

- Each semester closes from a **100% weight budget**, and teachers cannot create assessments beyond it.
- A full-year subject's annual score is the **plain average** of the two semester totals; a semester-only subject's final score is that semester's score.
- Per-year semester weights stay in the schema for history but are not applied.
- Semesters can be closed and reopened for corrections.

## Consequences

- **Positive:** simple, explainable report cards.
- **Negative:** historical results computed under weights may differ; the unused weight fields remain.

## Evidence

`admin/backend/services/SubjectDurationPolicy.php`; releases 1.6.5–1.6.7 (`384658f`, `02b6c6d`, `638a2f2`); `sql/056_subject_duration_and_semester_weights.sql`, `sql/067_semester_reopen_flag.sql`.
