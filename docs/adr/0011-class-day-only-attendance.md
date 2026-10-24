# ADR-0011: Attendance only on class days, with a 45-day backdating window

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-20 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Attendance could be recorded for any date, including days a class does not meet, future dates and long-past dates.

## Decision

- Attendance is accepted only on a class's working days (from the timetable) or a makeup day: never in the future, and at most **45 days** back.
- Classes without configured working days are **blocked**, with no fallback.
- Education may declare "Day Off" days, optionally cancelling makeup classes, but only before the date passes and only if no attendance exists for it.
- The rule activates once `sql/078_school_calendar.sql` is applied.

## Consequences

- **Positive:** cleaner statistics and warnings.
- **Negative:** timetables must be configured before attendance works; legitimate late entries older than 45 days need an administrative path.

## Evidence

`admin/backend/services/SchoolCalendarService.php`; commit `a5e8fee`.
