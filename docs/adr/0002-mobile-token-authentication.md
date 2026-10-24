# ADR-0002: Mobile API authentication with signed access tokens and rotating refresh tokens

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-08-24 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

The Android app works offline for long periods and must not keep a long-lived credential that cannot be revoked. Disabling a staff member must take effect quickly.

## Decision

- Issue a **15-minute access token**: a custom two-part token, `base64(JSON).hex(HMAC-SHA256)`, which is not a JWT.
- Issue a **90-day refresh token** that is stored server-side and **rotated on every use**. Reuse of an old refresh token revokes its whole family.
- From 2026-09-23, every request re-checks role, active status and `authorization_version` against the database.
- Legacy 30-day tokens for old builds were accepted until 2026-09-24.

## Consequences

- **Positive:** fast revocation; replay detection; no extra library.
- **Negative:** a custom format, so generic JWT tooling does not apply and the format must be documented ([API.md](../API.md#2-authentication)); one database read per request.
- **Rationale not recorded:** why a standard JWT library was not used.

## Evidence

`api/v1/core/auth.php`; `RefreshTokenService.php`; `sql/010_refresh_token_rotation.sql`; `sql/048_user_authorization_scope.sql`; commits `6388586`, `eb39756`.
