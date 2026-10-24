# ADR-0020: Refuse marks above the maximum; a blank mark is "Did not take" and is excluded from the total

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-22 | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

Marks above an assessment's maximum could be saved, and students with blank marks seemed to disappear from reports.

## Decision

- The server refuses any mark above the maximum with HTTP 422 and saves nothing; web and mobile show the limit live.
- A blank mark is shown as **"Did not take"** on report cards and class reports and is **excluded** from the total (owner decision D1).
- Long lists are paginated everywhere.

## Consequences

- **Positive:** valid data; visible gaps.
- **Negative:** totals for students who missed assessments are not penalised automatically; policy questions (retakes) remain manual.

## Evidence

Commits `524ab7f`, `d854591`, `205d398`.
