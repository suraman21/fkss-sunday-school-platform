# ADR-0004: Hand-applied, additive and idempotent SQL migrations

| Status | Date | Deciders |
|---|---|---|
| Accepted (known debt) | 2026-08-15 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

There is no shell or deploy tooling on the server. Database changes are applied by hand through phpMyAdmin.

## Decision

Database changes are numbered files `sql/NNN_*.sql`. They are additive and idempotent where possible (`IF NOT EXISTS`, `information_schema` guards), a maintainer runs them by hand, and code tolerates a missing migration where feasible (a feature stays inert until its table exists). CI checks numbering and flags destructive statements.

## Consequences

- **Positive:** safe to re-run; deploys can come before the migration.
- **Negative:** **no migration ledger**, so the applied state in production is unknown; `sql/` cannot be applied linearly to an empty database; runtime DDL exists in places.
- **Follow-up:** introduce a ledger and a verified baseline ([DATABASE.md §6](../DATABASE.md#6-recommended-next-steps-documented-not-implemented)).

## Evidence

`sql/`; `.github/workflows/backend-checks.yml`; [DATABASE.md](../DATABASE.md).
