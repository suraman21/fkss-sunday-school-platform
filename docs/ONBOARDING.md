# Engineer onboarding

**Status:** canonical, written 2026-10-23. A first-week path for an engineer joining the project. It links to the reference documents rather than repeating them.

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
| [adr/README.md](adr/README.md) | Why the system is built this way (20 decision records) |
| [API.md](API.md) and [api/openapi.yaml](api/openapi.yaml) | Mobile API conventions and the full `/api/v1` operation list |
| [DATABASE.md](DATABASE.md) and [DATA_DICTIONARY.md](DATA_DICTIONARY.md) | Migration rules, schema sources and every table by domain |

## 4. Day 3: set up and run the tests

1. Follow [DEVELOPMENT_AND_TESTING.md §1–§2](DEVELOPMENT_AND_TESTING.md#1-prerequisites) to set up a local test environment.
2. Run the security and regression suite and the Flutter unit tests. Compare your result with the current CI status in [§3](DEVELOPMENT_AND_TESTING.md#3-current-ci-status): 79 explained skips are expected today, and no failures (the two earlier failures were fixed on 2026-10-24).
3. Read [TEST_STRATEGY.md](TEST_STRATEGY.md) to understand what each suite protects.

## 5. Day 4: learn how changes ship

| Read | Why |
|---|---|
| [CONTRIBUTING.md](../CONTRIBUTING.md) | Workflow, coding standards, definition of done |
| [BRANCHING.md](../BRANCHING.md) | `main` (production) and `dev` (beta), and what may move between them |
| [OPERATIONS.md](OPERATIONS.md) | Deployment steps, rollback, backups and mobile release |
| [CHANGELOG.md](../CHANGELOG.md) | What changed recently, and the production deployment record |
| [SECURITY.md](SECURITY.md) | Security controls and how to report a vulnerability |

## 6. Day 5: first contribution

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
