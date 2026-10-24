# ADR-0017: Members are never registered from the Android app

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-17 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Registration needs document uploads, duplicate checks and HR review, which were hard to guarantee on an offline mobile client.

## Decision

Member registration is web-only and owned by HR. The mobile registration path was removed. Mobile edits of guardian data sync back to the server.

## Consequences

- **Positive:** one controlled entry point for the register.
- **Negative:** field registration requires a browser.

## Evidence

Commit `f5098cb`; [ARCHITECTURE.md §4](../ARCHITECTURE.md#4-mobile-application-mobilewbws_flutter_app).
