# ADR-0014: `main` is production; `dev` is main plus beta settings, and never flows back

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-20 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

A beta app pointing at a test server was needed without any risk of beta settings reaching production.

## Decision

- `main` is the production code, mirrored to the production repository.
- `dev` is `main` plus exactly one commit that re-targets the app to the beta server.
- Changes flow only from `main` to `dev`; `dev` is never merged into `main`.
- CI (*Branch guard*) and a pinned test enforce this.

## Consequences

- **Positive:** production values cannot be overwritten by accident.
- **Negative:** feature branches must target `main`; `dev` must be refreshed after every `main` change.

## Evidence

[BRANCHING.md](../../BRANCHING.md); `.github/workflows/branch-guard.yml`; commits `4c52494`, `d86b56f`.
