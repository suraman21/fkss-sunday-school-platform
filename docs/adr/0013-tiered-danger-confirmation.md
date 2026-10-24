# ADR-0013: Tiered confirmation for destructive actions

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-21 | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

Destructive actions used inconsistent browser `confirm()` prompts. Making every action hard to confirm causes habituation.

## Decision

Friction scales with the impact of the action. High-risk actions use one shared three-step dialog with a typed phrase, and the server checks the phrase again (`409 confirm_phrase_required`). Medium-risk actions use the same dialog, checked in the client. Low-risk actions (easily undone or personal) use a small, specific dialog or an undo toast. Native `confirm()` is not used.

## Consequences

- **Positive:** consistent, accessible dialogs; server-side protection for the worst cases.
- **Negative:** every new destructive action must be classified and wired up.

## Evidence

`admin/backend/danger_confirm.php`; commits `20fc748`, `1070b57`, `c8f02fd`, `3ed188d`.
