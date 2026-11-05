# Engineer onboarding

**Status:** maintained, written 2026-10-23 and release/evidence paths clarified 2026-10-28. A first-week path for an engineer joining the project. It links to the reference documents rather than repeating them.

## 1. Before you start

- You get access to the **development repository** and a **test environment** only. Production access (hosting, database, app signing) is granted separately by the maintainers, for a stated task.
- Read the ground rules in [CONTRIBUTING.md §1](../CONTRIBUTING.md). The most important ones:
  - never use production data, and never commit personal data, credentials or environment files;
  - never push to `main`; every change goes through a pull request;
  - database changes are versioned SQL migrations that a maintainer applies manually.

## 2. Day 1: understand the product

| Read | Why |
|---|---|
| [README.md](../README.md) | What the system is and how the repository is laid out |
| [product/PRD.md](product/PRD.md) | Who uses it, and what it must do |
| [product/GLOSSARY.md](product/GLOSSARY.md) | Domain and Ethiopian-calendar terms you will meet in code and UI |
| [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md) | The department role model; most rules depend on it |

## 3. Day 2: understand the system

| Read | Why |
|---|---|
| [architecture/TECHNICAL_DESIGN.md](architecture/TECHNICAL_DESIGN.md) | Context, containers, runtime flows and deployment, with diagrams |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Code-level map, including the three API layers |
| [adr/README.md](adr/README.md) | Dated design decisions and the current development-before-production clarification |
| [API.md](API.md) and [api/openapi.yaml](api/openapi.yaml) | Mobile API conventions and a source-dated, summarized operation reference; not a full field-level conformance guarantee |
| [DATABASE.md](DATABASE.md) and [DATA_DICTIONARY.md](DATA_DICTIONARY.md) | Current numbered-migration inventory plus historical table-level/foreign-key source records; not verified target schema |

## 4. Day 3: set up and run the tests

1. Follow [DEVELOPMENT_AND_TESTING.md §1–§2](DEVELOPMENT_AND_TESTING.md#1-prerequisites) to set up a local test environment.
2. Run the security and regression suite and the Flutter unit tests using the isolated fixture environment. Compare the exact candidate's results with [§3](DEVELOPMENT_AND_TESTING.md#3-current-ci-status): the baseline checks are green and the earlier 79-skip setup gap is resolved. **Any skip fails the backend gate.** Report missing environment prerequisites instead of treating skipped tests as passes.
3. Read [TEST_STRATEGY.md](TEST_STRATEGY.md) to understand what each suite protects.

## 5. Day 4: learn how changes ship

| Read | Why |
|---|---|
| [CONTRIBUTING.md](../CONTRIBUTING.md) | Workflow, coding standards, definition of done |
| [BRANCHING.md](../BRANCHING.md) | Development `main`, one-way beta `dev` and separately authorized production promotion |
| [OPERATIONS.md](OPERATIONS.md) | Deployment steps, rollback, backups and mobile release |
| [CHANGELOG.md](../CHANGELOG.md) | What changed recently, and the production deployment record |
| [SECURITY.md](SECURITY.md) | Source controls, evidence limits and private vulnerability reporting |
| [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md) | Approved evidence/containment/recovery procedure; staffing and private escalation still need owner assignment |
| [Documentation catalog](DOCUMENTATION_CATALOG.md) | Maintained references, historical boundaries, retained artifacts and explicit unfinished coverage |

## 6. Day 5: first contribution

Before editing, complete the [developer guide's source-tracing exercise](DEVELOPER_GUIDE.md#2-worked-exercise-record-a-paid-member-fee) and produce a [change-impact record](CHANGE_IMPACT.md). AI-assisted contributors also read [AGENTS.md](../AGENTS.md); tools differ in automatic loading, and no agent receives production authority from a repository instruction file. Use the [security model](architecture/SECURITY_MODEL.md) for trust boundaries and [documentation quality gates](QUALITY_GATES.md) for acceptance and remaining coverage.

Good first tasks touch one area and have a clear test:
- a documentation correction you found while reading (the documents state what they were verified against; tell us when something no longer matches the code);
- a small, test-backed fix for an item in [OPERATIONS.md §7](OPERATIONS.md#7-known-limitations-and-open-questions), after the maintainers confirm it is wanted.

Open a pull request using the template, describe how you tested it, and wait for review.

## 7. Where things are

| Area | Location |
|---|---|
| Admin web application and admin JSON endpoints | `admin/` |
| Domain services (business rules) | `admin/backend/services/` |
| Mobile REST API | `api/v1/` |
| Android app (Flutter) | `Mobile/wbws_flutter_app/` |
| Database migrations | `sql/` |
| Tests | `tests/`, `Mobile/wbws_flutter_app/test/` |
| Documentation | `docs/` (index: [docs/README.md](README.md)) |

## 8. Getting help

Ask in the pull request or the team channel. When you report a problem, include what you did, what you expected, what happened, and the commit you tested. Never paste personal data, tokens or passwords into issues, pull requests or chat.
