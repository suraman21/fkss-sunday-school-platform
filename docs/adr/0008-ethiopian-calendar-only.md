# ADR-0008: Ethiopian calendar for every user-facing date

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-19 (EC-only sweep); EC/GC conversion since 2026-08-16 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Staff, parents and church records use the Ethiopian calendar. Mixed calendars caused confusion in exports and screens.

## Decision

Store Gregorian dates internally and convert at the edges. Every displayed date and every export uses the Ethiopian calendar. Monthly reports use Ethiopian months.

## Consequences

- **Positive:** matches how users think and file records.
- **Negative:** conversion code is needed everywhere; date pickers and month boundaries are custom; tests must cover Pagume and year boundaries.

## Evidence

Commits `185aa97`, `b260f0f`, `6fa65df`; `admin/backend/ethiopian_date.php`; PR template checklist.
