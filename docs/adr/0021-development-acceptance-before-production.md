# ADR-0021: Development acceptance precedes separately authorized production promotion

| Status | Recorded | Decider |
|---|---|---|
| Accepted owner workflow clarification | 2026-10-28 | Project owner |

## Context

Earlier documentation described production and development `main` as identical and deployment as manual SFTP on hosting without cron. The owner's current instruction distinguishes development integration from production promotion: merge checked work into the development repository, allow its cron-based server update, obtain owner acceptance, then obtain separate production authorization.

This records an already supplied instruction, not a new deployment implementation. No scheduler, configuration, access policy or release automation is changed by the documentation update.

## Decision

1. Topic branches target development `main`; commit, push, inspect checks and merge only within the authorized scope and existing protections.
2. A merge, branch equality or CI success is not evidence of deployed files, applied schema, active configuration, installed APKs or successful owner acceptance. Verify each relevant boundary independently.
3. Development-server cron updating is owner-reported workflow; its execution, logs and target parity remain deployment dependencies until observed through authorized access.
4. Production promotion requires a separate explicit owner decision after development acceptance. Do not automatically mirror development changes to the production repository or contact production during routine local verification.
5. Preserve ADR-0014's one-way `main` → beta `dev` boundary. Never merge beta settings back into `main` or push `dev` to production.
6. The owner controls APK signing, build-number publication and phone acceptance. Debug compilation is not signed-release or device evidence.

## Consequences

- Production and development repositories may intentionally differ.
- Reports must identify source SHA, actual checks and deployment/acceptance limits separately.
- Tests use isolated synthetic records; real school data and production requests require separate authorization.
- Failed checks, conflicts, protection/access restrictions and material scope changes stop the risky release action; they do not justify bypassing a gate.
- CLI maintenance and application queues must be documented by their actual execution requirements, not a blanket historical no-cron assumption.

## Relationship to earlier records

Partially supersedes the environment/deployment assumptions in [ADR-0001](0001-php-monolith-on-shared-hosting.md) and automatic production-equivalence wording in [ADR-0014](0014-branching-main-dev.md). Their original dated decisions remain preserved. It does not replace the PHP architecture, migration safeguards or beta isolation rule.

## Evidence

The owner's development-first, cron-update, owner-test and separate-production-authorization instructions, recorded in the 2026-10-28 documentation review. Maintained procedures: [branching](../../BRANCHING.md), [release policy](../PRODUCTION_WORKFLOW.md), [operations](../OPERATIONS.md) and [Android release guide](../../Mobile/wbws_flutter_app/docs/RELEASE.md). This record does not certify that any specific server has pulled a revision.
