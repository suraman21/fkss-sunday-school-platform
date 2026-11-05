# Documentation catalog and review boundaries

**Maintained catalog, 2026-10-28.** Review starts from pushed documentation baseline `7e28157`, itself based on development `7209278`. Developer-readiness, security-model and Finance/Materials additions were subsequently source-reviewed against merged development main `13f00ac`. This catalog records dispositions and targeted source review; it is not an assertion that every historical paragraph, endpoint, permission, UI control or runtime has been exhaustively audited.

## 1. Maintained entry points

| Audience / topic | Reference | Review and evidence boundary |
|---|---|---|
| Project orientation | [Root overview](../README.md), [documentation index](README.md), [onboarding](ONBOARDING.md) | Entry paths, source/deployment separation, current test/release policy; no whole-runtime certification |
| Contributors | [Developer guide](DEVELOPER_GUIDE.md), [agent instructions](../AGENTS.md), [change-impact map](CHANGE_IMPACT.md), [contribution guidelines](../CONTRIBUTING.md), [Git workflow](ci_cd/GIT_CHEATSHEET.md), [branching](../BRANCHING.md) | Baseline `13f00ac` source-trace exercise, contributor safety and reviewer handoff; topic branch/PR/check workflow, safe recovery and one-way beta isolation. Current protection settings and actual integration require fresh evidence |
| Documentation acceptance | [Quality gates](QUALITY_GATES.md) | Evidence-based dimensions and control-review criteria; no numerical rating or full-manual completion inferred from document volume |
| Product | [PRD](product/PRD.md), [glossary](product/GLOSSARY.md) | Stable requirement IDs; original source-derived baseline retained with corrections for retired HR attendance, website-only mobile departments, recovery and evidence limits |
| Architecture | [Overview](ARCHITECTURE.md), [technical design](architecture/TECHNICAL_DESIGN.md) | Component and request/data-flow references; corrected cron/queue, schema activation, local-lock and delivery assumptions; diagrams are simplified explanations |
| Decisions | [ADR index](adr/README.md) and [ADR-0021](adr/0021-development-acceptance-before-production.md) | Preserve dated decisions; current owner workflow partially supersedes old environment equivalence without reversing beta isolation |
| API | [API overview](API.md), [OpenAPI](api/openapi.yaml), [selected worked actions](api/LEDGER_ACTIONS.md), [Finance](api/FINANCE_CONTRACT.md), [Materials](api/MATERIALS_CONTRACT.md), [ledger permissions](api/LEDGER_PERMISSIONS.md) | Baseline `591e23d` adds individual field/default/response/effect and gate review for every switch action in the two browser ledger handlers. Not all-system permission/API conformance. The 117-operation OpenAPI reference retains summarized bodies and its original scope |
| Database | [Migration reference](DATABASE.md), [historical dictionary](DATA_DICTIONARY.md), [ledger columns](database/LEDGER_COLUMNS.md), [SQL guide](../sql/README.md), [legacy boundaries](../sql/legacy/README.md) | Baseline `591e23d` adds literal columns/constraints for eight manually reviewed ledger definitions and later source ALTERs, not an applied-schema export. Existing 76-file/102-target inventory and dictionary reference/FK counts retain their earlier evidence boundaries; other domains remain unfinished |
| Security | [Security reference](SECURITY.md), [security architecture/review model](architecture/SECURITY_MODEL.md) | Source-bounded trust/data-flow views and review scenarios supplement the existing control/privacy/custody boundaries. No independent assessment, live control certification or access to the private findings register |
| Testing | [Development/testing](DEVELOPMENT_AND_TESTING.md), [test strategy](TEST_STRATEGY.md), [test folders](../tests/README.md) | Pinned toolchain/workflow map, zero-skip rule, synthetic local fixtures and candidate-specific results; old baseline successes do not prove a later candidate |
| Operational changes | [Operations](OPERATIONS.md), [promotion policy](PRODUCTION_WORKFLOW.md), [incident response](INCIDENT_RESPONSE.md), [scripts](../scripts/README.md) | Development acceptance precedes separate production authorization; private evidence, backup/restore and rollback gates. Staffing, schedules, custody and deployed parity remain dependencies |
| Server-observed sync | [Sync-monitoring contract](WEB_ADMIN_SYNC_MONITORING.md) | Projection vs. business/idempotency truth, unsent local-state visibility limits, bounded read actions and retention; fixed incomplete action-boundary sentence |
| Failure intelligence | [Server contract](FAILURE_INTELLIGENCE.md), [client contract](../Mobile/wbws_flutter_app/docs/FAILURE_INTELLIGENCE_CLIENT.md) | Rule-based grouping/remediation suggestions, not verified diagnosis; installation correlation/privacy and actual scheduled activation are separate concerns |
| Android engineering/release | [Mobile folder](../Mobile/README.md), [app guide](../Mobile/wbws_flutter_app/README.md), [release guide](../Mobile/wbws_flutter_app/docs/RELEASE.md), [App updates](../Mobile/wbws_flutter_app/docs/PROFILE_APP_UPDATES.md) | Owner-controlled build/signing/phone/publication gates; browser upload resume differs from mobile retry-from-zero; installer launch is not installation |
| End users | [Partial manual](user_manual/README.md), [coverage](user_manual/COVERAGE.md), [Finance](user_manual/FINANCE.md), [Materials](user_manual/MATERIALS.md) | Account/device/update/recovery chapters plus core web ledger/inventory source traces and mobile website-only notices. Exhaustive department/every-control scope is active and not deferred; it is not complete. Shared/dynamic/legacy controls and runtime acceptance remain open |
| Request handling | [Mezmur search reference](ci_cd/SEARCH_LOAD_AND_ERRORS.md) | Executable 160 ms debounce vs. nearby 250 ms comment, request/retry limits and browser-acceptance gaps; no behavior change |

## 2. Dated evidence, proposals and compatibility references

| Material | Disposition |
|---|---|
| `CHANGELOG.md`, mobile `RELEASE_NOTES.md`, `docs/reports/`, `docs/incidents/` | Retain dated outcomes, including failed/intermediate runs. A record is not a current deployment guarantee; sanitize new records, do not rewrite old results to look successful |
| ADRs 0001–0020 | Retain original decisions/rationale. Applicability notes link the newer owner workflow where assumptions changed; a design policy is not proof every screen conforms |
| `docs/rfc/` | Preserve proposal/acceptance boundaries; proposed phases are not implemented merely because they appear in a plan |
| Deployment runbook | Preserve required path and tested migration/restore/profile-cleanup safeguards; targeted safety corrections remove unsafe generic directions. Historical stages are not a current release sequence |
| Build-24 authorization/outbox runbook | Retain compatibility, device-drill, containment and forward-only local-migration gates as a dated rollout reference; verify applicability before reuse |
| Media-storage and fleet-telemetry audit references | Retain topic-specific configuration, preflight, integrity and retention evidence. Actual credentials, target flags, schedules and runtime activation require separately authorized checks |
| Synthetic APK browser/audit harness instructions | Retain isolation/destructive-test warnings and measured scenario limits. Browser/synthetic APK success is not production quota/WAF, signing or phone acceptance |
| `Mobile/code_dump.txt` | Retained, nonexecuting historical snapshot. Complete unique-knowledge retirement review remains unfinished; structural/historical comparison alone does not justify deletion |
| Retired CI proposal/template | Removed in the first documentation commit after complete candidate review and knowledge/reference migration; see [lifecycle register](DOCUMENT_LIFECYCLE.md) |
| Legitimate AI feature/API/security references | Preserve implemented optional functionality and provider/privacy obligations. Do not delete these merely because they contain “AI” |
| Vendored libraries, license/attribution/version notices, generated platform README assets | Preserve provenance and technical meaning. Not rewritten as product prose |
| Build manifests, CMake files, requirements, robots rules and upload-directory sentinels | Technical inputs/operational boundaries, not editorial scratch notes. Do not delete or reword them based on `.txt` extension |

The tracked Markdown/text inventory includes code/build inputs and third-party material, so a raw filename count is not a count of maintained documents. Discovery/grep classifies candidates; it does not establish runtime reachability, unused status or a completed content review.

## 3. Evidence classifications

- **VERIFIED:** supported by a cited source, a named local test or an observed check at its exact revision. State which one; these are different levels of evidence.
- **HIGH CONFIDENCE / INFERENCE:** explanation supported indirectly; label it rather than convert it into a guarantee.
- **UNKNOWN / DEPENDENCY NOT VERIFIED:** actual hosting configuration, current schema, certificates, installed fleet, private custody, scheduled execution, branch protections or acceptance not observed here.
- **POTENTIAL SECURITY CONCERN — REQUIRES VERIFICATION:** report privately with evidence and authorization boundaries; absence of a public finding is not proof of safety.

## 4. Unfinished work and excluded claims

1. Remaining source-copy differences require complete review before retirement.
2. The full departmental/every-control manual is now explicitly in the active requested scope. New Finance/Materials core chapters reduce but do not close it. All-action permission reconciliation, shared/dynamic/legacy controls and browser/phone acceptance remain unfinished acceptance requirements.
3. A regenerated column-level dictionary and complete field-level API conformance need dedicated isolated schema/contract work; no live-school data is authorized for this update.
4. Actual development cron/deployment parity, owner acceptance and any production promotion are not accomplished by editing these documents.
5. CI/check and integration outcomes belong to the final candidate/merge record. Do not substitute the previously green `7209278` baseline for fresh results.

Follow [authoring/retirement standards](DOCUMENTATION_STANDARD.md). New contributors update the reference tied to their source change; operators verify target-specific prerequisites before executing a procedure.
