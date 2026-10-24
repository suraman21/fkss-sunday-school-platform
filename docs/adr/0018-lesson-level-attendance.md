# ADR-0018: Lesson-level Education attendance alongside the class-day sheet

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-19 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Education needed attendance per lesson (subject, teacher, lesson number) without breaking the existing class-day statistics.

## Decision

- Teachers save lesson sheets, stored in `attendance_sessions` and `attendance_session_records`.
- The class-day row is derived with the precedence present › late › excused › absent, and the day sheet becomes read-only once lessons exist.
- Lessons can be created without a timetable entry.
- Web only in this phase.

## Consequences

- **Positive:** richer data with unchanged monthly rates.
- **Negative:** two levels of attendance to keep consistent; no mobile support yet.

## Evidence

`LessonAttendanceService.php`; `sql/076_attendance_sessions.sql`; commit `3ad51cd`.
