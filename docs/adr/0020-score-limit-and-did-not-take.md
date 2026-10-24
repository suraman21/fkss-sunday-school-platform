# ADR-0020: Refuse marks above the maximum; a blank mark is "Did not take" and is excluded from the total

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-22 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Marks above an assessment's maximum could be saved, and students with blank marks seemed to disappear from reports.

## Decision

- The server refuses any mark above the maximum with HTTP 422 and saves nothing; web and mobile show the limit live.
- A blank mark is shown as **"Did not take"** on report cards and class reports and is **excluded** from the total.
- Long lists are paginated everywhere.

## Consequences

- **Positive:** valid data; visible gaps.
- **Negative:** totals for students who missed assessments are not penalised automatically; policy questions (retakes) remain manual.

## Evidence

Commits `524ab7f`, `d854591`, `205d398`.
