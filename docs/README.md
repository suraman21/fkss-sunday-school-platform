# Documentation index

**Status labels**

| Label | Meaning |
|---|---|
| **Canonical** | Maintained. Describes the current system. If it disagrees with the code, the code wins; fix the document |
| **Reference** | Accurate for its narrow topic |
| **Superseded** | Replaced by the linked canonical document; kept for context |
| **Historical** | A point-in-time record (rollout, incident). Useful for *why*, not for *what is true now* |

## User guides and documentation maintenance

| Document | Status and scope |
|---|---|
| [User manual](user_manual/README.md) | **Partial:** mobile account/device settings, updates/recovery and source-reviewed core Finance/Materials web workflows; not yet all departments or controls |
| [Developer guide](DEVELOPER_GUIDE.md) | Human/AI-assisted investigation, worked fee trace, known source discrepancies and handoff |
| [Agent contributor instructions](../AGENTS.md) | Compact repository-wide safety, evidence, testing and delivery contract |
| [Change-impact map](CHANGE_IMPACT.md) | Entry points, shared boundaries, regression questions and stop conditions |
| [Documentation quality gates](QUALITY_GATES.md) | Measurable acceptance, control-review records and honest scoring boundaries |
| [Manual coverage and verification](user_manual/COVERAGE.md) | Explicit reviewed scope and outstanding source/control/runtime work |
| [Documentation standard](DOCUMENTATION_STANDARD.md) | Authoring, terminology, evidence, review and lifecycle rules |
| [Document lifecycle register](DOCUMENT_LIFECYCLE.md) | Reviewed retirements, preserved knowledge and retained candidates |
| [Documentation catalog](DOCUMENTATION_CATALOG.md) | Topic ownership/disposition, exact review boundaries and unfinished work |
| [2026-10-28 source/CI baseline](reports/DOCUMENTATION_BASELINE_2026-10-28.md) | Dated evidence; not a deployment or checks of a later branch |

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
| [adr/README.md](adr/README.md): dated decisions ADR-0001 to ADR-0021 and a template | Maintained index; preserve original decisions and explicit supersession boundaries |
| [rfc/README.md](rfc/README.md): proposals for significant changes; [RFC 0001](rfc/0001-repository-structure.md) repository structure | Reference |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Canonical |
| [FAILURE_INTELLIGENCE.md](FAILURE_INTELLIGENCE.md), [mobile client contract](../Mobile/wbws_flutter_app/docs/FAILURE_INTELLIGENCE_CLIENT.md) | Reference |
| [WEB_ADMIN_SYNC_MONITORING.md](WEB_ADMIN_SYNC_MONITORING.md) | Reference |
| [Mobile app README](../Mobile/wbws_flutter_app/README.md) | Reference |

## 3. API and authorization

| Document | Status |
|---|---|
| [API.md](API.md) | Canonical |
| [Selected ledger action contracts](api/LEDGER_ACTIONS.md) | Earlier three-action worked reference; not full API conformance |
| [Finance action reference](api/FINANCE_CONTRACT.md) | Source-reviewed inputs/defaults, responses, failures and side effects for each Finance handler action |
| [Materials action reference](api/MATERIALS_CONTRACT.md) | Source-reviewed inputs/defaults, responses, failures and side effects for each Materials handler action |
| [Ledger permission/gate matrix](api/LEDGER_PERMISSIONS.md) | Real/shim paths, gate ordering, roles/features, methods/CSRF, confirmation and record checks; source evidence, not hosted acceptance |
| [api/openapi.yaml](api/openapi.yaml): OpenAPI 3.1 reference for `/api/v1` | Source-dated summarized reference; route/service code governs field behavior |
| [ROLES_AND_PERMISSIONS.md](ROLES_AND_PERMISSIONS.md) | Canonical |

## 4. Database

| Document | Status |
|---|---|
| [DATABASE.md](DATABASE.md): engine, migration rules, migration inventory | Canonical |
| [DATA_DICTIONARY.md](DATA_DICTIONARY.md): dated source table/foreign-key inventory and core diagrams | Historical generated reference; not current column-level or target-schema certification |
| [Ledger column reference](database/LEDGER_COLUMNS.md) | Eight manually reviewed CREATE definitions, later source constraints/indexes and runtime differences; not an applied-schema export |
| [incidents/INCIDENT_2026-10-03_TRACKING_SCHEMA.md](incidents/INCIDENT_2026-10-03_TRACKING_SCHEMA.md) | Historical (incident record) |

## 5. Security

| Document | Status |
|---|---|
| [SECURITY.md](SECURITY.md): controls, secrets (key names only), private reporting and evidence limits | Maintained reference, not a security certification |
| [Security architecture/review model](architecture/SECURITY_MODEL.md) | Source-bounded data flows, trust boundaries, misuse/review cases and residual verification; not a penetration test |
| [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md): authority, private evidence, containment and recovery | Maintained procedure; staffing/contacts/drills remain owner dependencies |

Open security findings are tracked privately, not in this repository (see [SECURITY.md §5](SECURITY.md#5-open-findings)).

## 6. Development, testing and CI

| Document | Status |
|---|---|
| [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md): setup, test suites, CI | Canonical |
| [TEST_STRATEGY.md](TEST_STRATEGY.md): test levels, coverage by risk, quality gates | Canonical |
| [tests/audit/README.md](../tests/audit/README.md) | Reference |
| [Document lifecycle register](DOCUMENT_LIFECYCLE.md) | Retired CI proposal/template and preserved knowledge |
| [ci_cd/SEARCH_LOAD_AND_ERRORS.md](ci_cd/SEARCH_LOAD_AND_ERRORS.md): Mezmur web request handling | Source reference; executable/comment debounce mismatch and uncompleted browser checks are explicit |

## 7. Operations and releases

| Document | Status |
|---|---|
| [OPERATIONS.md](OPERATIONS.md): deployment, rollback, backups, mobile release, monitoring, [known limitations and open questions](OPERATIONS.md#7-known-limitations-and-open-questions) | Canonical |
| [Mobile app RELEASE.md](../Mobile/wbws_flutter_app/docs/RELEASE.md): Android build and signing guide | Canonical for mobile builds |
| [Mobile app RELEASE_NOTES.md](../Mobile/wbws_flutter_app/RELEASE_NOTES.md) | Canonical (release log) |
| [audits/AUDIO_STORAGE_SETUP.md](audits/AUDIO_STORAGE_SETUP.md): media storage setup | Reference |
| [audits/AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md](audits/AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md) | Historical (build 24 rollout) |
| [audits/DEPLOYMENT_RUNBOOK.md](audits/DEPLOYMENT_RUNBOOK.md) | Historical go-live instructions with migration/restore safeguards still required by source/tests. Use [OPERATIONS.md](OPERATIONS.md) for current authorization/environment policy; do not delete or execute blindly |
| Mobile fleet telemetry: [release gate](audits/MOBILE_FLEET_TELEMETRY_STAGE0_RELEASE_GATE.md), [staging evidence](audits/MOBILE_FLEET_TELEMETRY_STAGE1_STAGING_EVIDENCE.md), [integrity hardening](audits/MOBILE_FLEET_TELEMETRY_INTEGRITY_HARDENING.md), [retention](audits/MOBILE_FLEET_TELEMETRY_RETENTION.md) | Reference |

## 8. Sources of truth

When documents disagree, use this table.

| Question | Authoritative source | Not authoritative |
|---|---|---|
| What is the code? | The exact reviewed development-main SHA. Production promotion is separate; parity is not assumed | Unreviewed feature branches; `dev` (beta settings) |
| What is deployed? | A dated [deployment record](../CHANGELOG.md#production-deployment-record) corroborated by authorized file/schema/configuration verification | A merge alone, server-side Git metadata or an old deployment record used as a live guarantee |
| What is the database schema? | The actual target database, through separately authorized structural verification; see [DATABASE.md §4](DATABASE.md#4-applied-state-in-production-unverified) | `sql/legacy/database_schema.sql` (stale); migrations alone do not prove applied state |
| Which app version is in the field? | Installed-device evidence for the devices concerned; release-manager metadata identifies the offered release, not every installed build | `pubspec.yaml` on `main`, a successful build or a download alone |
| Who may do what? | `admin/access_control.php`, `api/v1/core/acl.php` and the checks inside each endpoint | Older documents |
| Why is it built this way? | [Architecture decision records](adr/README.md) | — |
| Security and privacy findings | Tracked privately ([SECURITY.md §5](SECURITY.md#5-open-findings)) | This repository |
