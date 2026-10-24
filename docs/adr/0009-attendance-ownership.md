# ADR-0009: Attendance belongs to Education and Mezmur; HR takes none

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-06 (HR attendance retired); 2026-10-18 (removed completely) | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

Three departments kept separate attendance, which duplicated effort and produced conflicting numbers.

## Decision

- Education owns class attendance and Mezmur owns department attendance.
- HR attendance writes return HTTP 410, and HR tables stay as a read-only archive.
- HR and Information read combined Education and Mezmur reports.
- At the same time, sheets became **default-absent**, and drafts accept and merge partial saves.

## Consequences

- **Positive:** one source per attendance type; no fabricated presence.
- **Negative:** HR historical data is frozen; reports must combine two sources.

## Evidence

Commits `e2184d1`, `eec830c`, `9ba54ee`, `4b0124f`, `d098afc`.
