# Documentation quality and acceptance gates

**Owner intent:** improve professionalism and usefulness toward 8.5–9.5/10 for future human and AI-assisted developers, while completing the departmental/every-control manual. **Baseline:** `13f00ac`, 2026-10-28. This rubric is an internal engineering review method, not an independent certification or a promise of a particular final score.

## 1. Evaluate evidence, not volume

A large document can still be unsafe. A short correct guide can be excellent within its stated scope. Evaluate each dimension separately; do not average a severe coverage/security gap away with good formatting.

| Dimension | Acceptance evidence | Not sufficient |
|---|---|---|
| Reader navigation | Human and agent entry points; role/task links; explanation separated from procedure/reference | Many disconnected documents |
| Technical traceability | Current entry → handler → identity/scope → validation → persistence → response/recovery, with source/revision | Filenames, copied comments or a generated action list |
| Reproducibility | Exact prerequisites, isolated data boundaries, appropriate command and real outcome; unexecuted steps explicit | Old CI totals, skipped tests or a working checkout alone |
| User completeness | Every requested domain and control reconciled, including dynamic/shared/destructive controls and conditions | Chapters covering only common workflows; screen/API inventories |
| Contract depth | Fields, limits, enums, errors, permissions, response and side effects reconciled against implementation | Summarized OpenAPI or a table-name dictionary |
| Security/operations | Trust/data-flow boundaries, failure/recovery, private evidence handling, explicit authority and runtime gaps | A green build or generic "secure" claim |
| Editorial/maintenance | Consistent terminology, exact visible labels, lifecycle/index updates and documentation regressions | Batch cosmetic replacement or deletion without knowledge review |
| Independent acceptance | Maintainer review, relevant synthetic browser/device acceptance and authorized runtime evidence | Self-review, a subjective score or documentation lint alone |

## 2. Scoring anchors

If a numerical assessment is requested, disclose reviewer, date, revision, audience, evidence and exclusions. Use these anchors consistently:

- **1–3:** unreliable or largely missing; harmful assumptions or unsupported completion claims.
- **4–6:** useful partial material, but substantial reader/coverage/reproducibility gaps.
- **7–8:** coherent, source-supported and maintainable within explicit limits; significant manual/contract/independent-acceptance gaps can remain.
- **8.5–9.5:** strong navigation and source traces, materially complete requested coverage, reproducible relevant checks, reviewed operational boundaries and meaningful independent acceptance. Explain any residual gaps; do not label unreviewed work complete.
- **10:** do not assert perfection or universal runtime/security assurance.

A developer-guide assessment is not the whole documentation-package assessment. Keep end-user completeness, API/schema depth and deployment/security evidence separate. Until the requested exhaustive manual and contract reconciliation are completed, do not report the whole request as a 9/10 completion simply because the developer entry points improved.

## 3. Manual control acceptance record

For each control family, record:

1. Client/page/screen and role/feature/class/year/record-state availability.
2. Exact label or icon purpose; user goal and prerequisite fields.
3. Event handler and transport/API action; authentication/authorization/validation source.
4. Persistence, transaction, filesystem or local-device effects, including no-op/status-only actions.
5. Response and visible state refresh; empty, denied, invalid, offline and uncertain-outcome recovery.
6. Destructive effects, confirmation, retained data and undo limits.
7. Source revision, reviewed references and actual synthetic browser/device result or **not executed**.
8. Shared/dynamic controls and duplicate/legacy surfaces accounted for, not silently excluded.

Mark rows **DISCOVERED**, **SOURCE REVIEWED** or **RUNTIME VERIFIED**. Source review does not imply runtime verification; a runtime result only applies to the tested case/environment. An entire department is not complete while shared controls, legacy paths or important failure states remain unreconciled.

## 4. Merge and completion gates

- Source investigation and written impact/implementation plan precede edits.
- Review each changed claim against source; inspect full diff, secret exposure and scope preservation.
- Run link/anchor, source-fact and serving-boundary regressions plus relevant executable fixtures. Do not weaken assertions to admit inaccurate wording.
- All applicable PR checks must succeed for the exact candidate SHA before a normal authorized development merge. Record actual results, not expected outcomes.
- Observe post-merge checks separately. Development-server update, owner testing and production promotion remain separate gates.
- Track unfinished controls, API fields, schema columns, knowledge-retirement review and external dependencies explicitly. A partial delivery is useful but is not full completion.

Use the final status definitions in [AGENTS.md](../AGENTS.md). The complete original request remains `INCOMPLETE — ADDITIONAL WORK REQUIRED` while required coverage is unfinished, even if a bounded set of new guides is verified. Missing integration authority/access is also recorded explicitly; never say "merged" without remote evidence.

## 5. Maintenance triggers

Revisit affected documents when routes, roles, feature gates, form fields, validation, transactions, storage, offline queues, migrations, toolchains or release processes change. Update references and manual coverage in the same PR. Keep dated evidence historical; do not make an old passing SHA appear current by editing only its date. Preserve [lifecycle](DOCUMENT_LIFECYCLE.md) and [catalog](DOCUMENTATION_CATALOG.md) distinctions.
