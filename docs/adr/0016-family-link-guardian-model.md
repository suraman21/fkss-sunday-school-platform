# ADR-0016: Family Link: a guardian entity with typo-tolerant matching and a review queue

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-17 | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

Parent details were copied onto each child's record, so siblings could not be linked reliably, and Amharic spelling variants defeated exact matching. This is a prerequisite for a future parent app.

## Decision

- Add `guardians`, `member_guardians`, `guardian_aliases` and `guardian_link_suggestions`.
- Matching is Fellegi-Sunter style: blocking keys, Jaro-Winkler similarity (`edgaras/strsim`, vendored) and three bands. Confident matches link automatically, uncertain ones go to a review queue, and the rest become new guardians.
- A name-only match can never auto-link.
- The legacy guardian columns on `members` remain as display copies.

## Consequences

- **Positive:** reliable sibling links; auditable review.
- **Negative:** thresholds need tuning against real data; guardian data is duplicated until the legacy columns are retired.

## Evidence

`GuardianMatchService.php`; `api_guardians.php`; `sql/069_guardian_linkage.sql`; commit `695830c`.
