# ADR-0007: Section and age group are one concept with one definition

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-09-12 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Sections and age groups were modelled separately, and different screens used different hard-coded lists, including a wrong label.

## Decision

`App\Services\MemberCategory` is the only source: A ህጻናት (7–13, `7_13`), B ማዕከላዊያን (14–17, `14_17`), C ወጣቶች (18+, `18_plus`). Since 2026-10-21, members under 7 are placed in A. Every screen, filter, report and normalisation derives from it.

## Consequences

- **Positive:** age ranges change in one place.
- **Negative:** legacy values (`18+`, alias spellings) must still be tolerated on read.

## Evidence

`admin/backend/services/MemberCategory.php`; `sql/041_section_age_source_of_truth.sql`; commit `9395f15`.
