# Documentation index

**Status labels**

| Label | Meaning |
|---|---|
| **Canonical** | Maintained. Describes the current system. If it disagrees with the code, the code wins; fix the document |
| **Reference** | Accurate for its narrow topic |
| **Superseded** | Replaced by the linked canonical document; kept for context |
| **Historical** | A point-in-time record (rollout, incident). Useful for *why*, not for *what is true now* |

## 1. Getting started

| Document | Status |
|---|---|
| [README.md](../README.md): project overview, stack, setup summary | Canonical |
| [ONBOARDING.md](ONBOARDING.md): first-week path for a new engineer | Canonical |
| [CONTRIBUTING.md](../CONTRIBUTING.md): workflow, coding standards, database rules, tests, definition of done | Canonical |
| [CHANGELOG.md](../CHANGELOG.md): change history, mobile build history, deployment record | Canonical |
| [BRANCHING.md](../BRANCHING.md): `main` vs `dev` rules | Canonical |
| [PRODUCTION_WORKFLOW.md](PRODUCTION_WORKFLOW.md): repository roles and release policy | Canonical |
| [ci_cd/GIT_CHEATSHEET.md](ci_cd/GIT_CHEATSHEET.md) | Reference |

## 2. Product and architecture

| Document | Status |
|---|---|
| [product/PRD.md](product/PRD.md): product requirements, with requirement IDs | Canonical |
| [product/GLOSSARY.md](product/GLOSSARY.md): domain, calendar and technical terms | Canonical |
| [architecture/TECHNICAL_DESIGN.md](architecture/TECHNICAL_DESIGN.md): technical design (arc42, C4 diagrams, runtime and deployment views) | Canonical |
| [adr/README.md](adr/README.md): architecture decision records ADR-0001 to ADR-0020, plus a template | Canonical |
| [rfc/README.md](rfc/README.md): proposals for significant changes; [RFC 0001](rfc/0001-repository-structure.md) repository structure | Reference |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Canonical |
| [FAILURE_INTELLIGENCE.md](FAILURE_INTELLIGENCE.md), [mobile client contract](../Mobile/wbws_flutter_app/docs/FAILURE_INTELLIGENCE_CLIENT.md) | Reference |
| [WEB_ADMIN_SYNC_MONITORING.md](WEB_ADMIN_SYNC_MONITORING.md) | Reference |
| [SECTION_AGE_GROUP_SOURCE_OF_TRUTH.md](SECTION_AGE_GROUP_SOURCE_OF_TRUTH.md) | Reference |
| [Mobile app README](../Mobile/wbws_flutter_app/README.md) | Reference |

## 3. API and authorization

| Document | Status |
|---|---|
| [API.md](API.md) | Canonical |
| [api/openapi.yaml](api/openapi.yaml): OpenAPI 3.1 reference for `/api/v1` | Canonical |
| [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md) | Canonical |

## 4. Database

| Document | Status |
|---|---|
| [DATABASE.md](DATABASE.md): engine, migration rules, migration inventory | Canonical |
| [DATA_DICTIONARY.md](DATA_DICTIONARY.md): every table by domain, where it is defined, core ERD, declared foreign keys | Canonical |
| [incidents/INCIDENT_2026-10-03_TRACKING_SCHEMA.md](incidents/INCIDENT_2026-10-03_TRACKING_SCHEMA.md) | Historical (incident record) |

## 5. Security

| Document | Status |
|---|---|
| [SECURITY.md](SECURITY.md): controls, secrets (key names only), reporting | Canonical |

Open security findings are tracked privately, not in this repository (see [SECURITY.md §5](SECURITY.md#5-open-findings)).

## 6. Development, testing and CI

| Document | Status |
|---|---|
| [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md): setup, test suites, CI | Canonical |
| [TEST_STRATEGY.md](TEST_STRATEGY.md): test levels, coverage by risk, quality gates | Canonical |
| [tests/audit/README.md](../tests/audit/README.md) | Reference |
| [ci_cd/ARCHITECTURE.md](ci_cd/ARCHITECTURE.md) | Superseded |
| [ci_cd/SEARCH_LOAD_AND_ERRORS.md](ci_cd/SEARCH_LOAD_AND_ERRORS.md) | Historical |

## 7. Operations and releases

| Document | Status |
|---|---|
| [OPERATIONS.md](OPERATIONS.md): deployment, rollback, backups, mobile release, monitoring, [known limitations and open questions](OPERATIONS.md#7-known-limitations-and-open-questions) | Canonical |
| [Mobile app RELEASE.md](../Mobile/wbws_flutter_app/docs/RELEASE.md): Android build and signing guide | Canonical for mobile builds |
| [Mobile app RELEASE_NOTES.md](../Mobile/wbws_flutter_app/RELEASE_NOTES.md) | Canonical (release log) |
| [audits/AUDIO_STORAGE_SETUP.md](audits/AUDIO_STORAGE_SETUP.md): media storage setup | Reference |
| [audits/AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md](audits/AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md) | Historical (build 24 rollout) |
| [audits/DEPLOYMENT_RUNBOOK.md](audits/DEPLOYMENT_RUNBOOK.md) | Superseded by [OPERATIONS.md](OPERATIONS.md) |
| Mobile fleet telemetry: [release gate](audits/MOBILE_FLEET_TELEMETRY_STAGE0_RELEASE_GATE.md), [staging evidence](audits/MOBILE_FLEET_TELEMETRY_STAGE1_STAGING_EVIDENCE.md), [integrity hardening](audits/MOBILE_FLEET_TELEMETRY_INTEGRITY_HARDENING.md), [retention](audits/MOBILE_FLEET_TELEMETRY_RETENTION.md) | Reference |
| [Mobile/HOW_TO_SHIP_AN_UPDATE.md](../Mobile/HOW_TO_SHIP_AN_UPDATE.md) | Superseded by [OPERATIONS.md §5](OPERATIONS.md#5-mobile-release) |

## 8. Sources of truth

When documents disagree, use this table.

| Question | Authoritative source | Not authoritative |
|---|---|---|
| What is the code? | `main` of the development repository. The production repository's `main` is kept identical | Feature branches; `dev` (beta settings) |
| What is deployed? | The [production deployment record](../CHANGELOG.md#production-deployment-record) (commit, files, verification result) | Files on the server; server-side git metadata |
| What is the database schema? | The production database (not yet verified; see [DATABASE.md §4](DATABASE.md#4-applied-state-in-production-unverified)) | `sql/legacy/database_schema.sql` (stale); `sql/` describes intent only |
| Which app version is in the field? | The app-release manager (`/api/v1/app/config`) | `pubspec.yaml` on `main` |
| Who may do what? | `admin/access_control.php`, `api/v1/core/acl.php` and the checks inside each endpoint | Older documents |
| Why is it built this way? | [Architecture decision records](adr/README.md) | — |
| Security and privacy findings | Tracked privately ([SECURITY.md §5](SECURITY.md#5-open-findings)) | This repository |
