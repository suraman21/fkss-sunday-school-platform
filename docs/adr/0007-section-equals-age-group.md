# ADR-0007: Section and age group are one concept with one definition

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-09-12 | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

Sections and age groups were modelled separately, and different screens used different hard-coded lists, including a wrong label.

## Decision

`App\Services\MemberCategory` is the only source: A ህጻናት (7–13, `7_13`), B ማዕከላዊያን (14–17, `14_17`), C ወጣቶች (18+, `18_plus`). Since 2026-10-21, members under 7 are placed in A. Every screen, filter, report and normalisation derives from it.

## Consequences

- **Positive:** age ranges change in one place.
- **Negative:** legacy values (`18+`, alias spellings) must still be tolerated on read.

## Evidence

`admin/backend/services/MemberCategory.php`; `sql/041_section_age_source_of_truth.sql`; commit `9395f15`.
