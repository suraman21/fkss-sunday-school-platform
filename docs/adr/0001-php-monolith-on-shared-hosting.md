# ADR-0001: PHP monolith on shared hosting

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-08-15 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

The school needed a low-cost system that a very small team can run. The available hosting is a shared account: PHP and MariaDB, file access only, with no shell, Composer, cron, Python, Redis or custom extensions for the application.

## Decision

Build one PHP code base with no framework: PHP-rendered dashboards, JSON endpoints and shared service classes, deployed by copying files. Third-party PHP libraries are committed in `vendor/`, because Composer cannot run on the server.

## Consequences

- **Positive:** cheap to host; no build step on the server; each file can be deployed on its own.
- **Negative:** no background jobs, so work happens inside requests and cron-dependent scripts may never run; manual dependency updates; framework-level protections (routing, ORM, templating) are hand-written; deployments are not atomic.
- **Follow-up:** revisit if the hosting changes (a VPS would allow workers, cron, CI/CD deploys and a framework).

## Evidence

[CONTRIBUTING.md](../../CONTRIBUTING.md) (hosting constraints); committed `vendor/` and `composer.json`; [OPERATIONS.md](../OPERATIONS.md).
