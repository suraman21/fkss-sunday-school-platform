# Documentation lifecycle and retirement register

**Status:** maintained lifecycle reference. Review date: 2026-10-28. Initial baseline: `7209278`; completion-phase review starts at pushed `7e28157`. [Catalog](DOCUMENTATION_CATALOG.md) distinguishes maintained references, historical evidence and uncompleted retirement/source coverage.

Maintained procedures describe the reviewed implementation and current owner policy. Dated reports preserve the outcome at the time they were written. Obsolete proposals/templates can leave the active tree after useful knowledge and references are accounted for; Git history remains intact.

## Retired material

| Retired path | Reason | Knowledge retained / replacement | Reference and safety review |
|---|---|---|---|
| `docs/ci_cd/ARCHITECTURE.md` | Superseded September proposal mixes conversational instructions with obsolete no-CI/test-count/version claims and unimplemented release proposals | [Development/testing](DEVELOPMENT_AND_TESTING.md), [test strategy](TEST_STRATEGY.md), [release policy](PRODUCTION_WORKFLOW.md), [mobile release operations](OPERATIONS.md#5-mobile-release), [Git reference](ci_cd/GIT_CHEATSHEET.md). Distinction between merge/release and stable signing custody retained. Proposed tag-triggered signing/config generation, percentage rollout and tag-only hosting pulls are not claimed as implemented or adopted | Complete 401-line candidate reviewed. Inbound index and release-policy references reviewed/updated. No executable include or runtime dependency found. Original narrative and old measurements remain in Git history |
| `docs/ci_cd/ci.yml.txt` | Nonexecuting workflow proposal pins obsolete Flutter 3.27/PHP 8.1 and describes gates/build/signing behavior different from actual workflows | Exact executing workflows and toolchain documented in [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md). Secrets/artifact guards remain in actual CI; exposed credentials must still be rotated under [SECURITY.md](SECURITY.md) | Complete 183-line template reviewed. Only discovered active references were backend-workflow comments; replaced with current documentation pointers. Workflow executable YAML is unchanged |

The retired proposal's decision not to introduce a second over-the-air patch system does not authorize adding one now. Signing automation, staged rollout or hosting automation would each need a new scoped design and approval. The historical read-only SQLite-row crash motivates regression coverage, not an assertion that a passing build prevents all runtime errors.

## Retained or pending review

| Path | Disposition and reason |
|---|---|
| `docs/ci_cd/SEARCH_LOAD_AND_ERRORS.md` | **Retained and rewritten** as a source reference. Useful request/retry/cache knowledge survives; unverified company/performance claims are removed. Executable debounce is 160 ms despite a nearby 250 ms comment; no application behavior is changed |
| `docs/audits/DEPLOYMENT_RUNBOOK.md` | **Retained with targeted safety/editorial corrections on 2026-10-28.** Required compatibility path, 031 → 032 → 033 order, uniqueness preflight/DEPLOYMENT BLOCKER, validated restore/FK inventory and not-a-fresh-install warning remain. Historical stages are explicitly noncurrent; blanket recursive chmod, command-line password examples, automatic restore and unsupported go-live assurance are removed/replaced. The detailed profile cleanup/storage/cron contract is preserved; no host operation was performed |
| `Mobile/code_dump.txt` | **Retained; deletion not authorized by the evidence yet.** Structural review found 30 Dart-source sections, each with an existing current source counterpart, no preamble and workstation-path headers. Historical commit `2e2e7c5` introduced the snapshot; only 6/30 sections match that commit's counterpart after outer-whitespace normalization. The remaining differences were not fully reviewed for unique knowledge. README/lifecycle references are explicit and no executable reference was discovered. Preserve the snapshot/history rather than claim a complete review or delete unreviewed content; it is not current instructions or implementation truth |
| Dated audit/incident/upload/CI reports | **Retained as evidence.** An intermediate failed run or incomplete status is not grounds for erasing history. Add a dated follow-up link when necessary; do not silently rewrite prior results |
| Legitimate optional AI feature/security/API material | **Retained.** The request is professional documentation cleanup, not removal of implemented functionality or its privacy/security obligations |

## Rules for future retirement

Follow [DOCUMENTATION_STANDARD.md](DOCUMENTATION_STANDARD.md#5-retiring-a-document). Check ordinary links, inline path references, comments, migrations, tests and dynamic dependencies. Move necessary knowledge before removing a file. Run documentation/include/boundary tests after staging new files so Git-based discovery includes them. Record incomplete reviews as pending rather than treating an inventory scan as proof of obsolescence.
