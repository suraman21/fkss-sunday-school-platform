# Documentation standard

**Status:** maintained authoring reference. Applies to first-party documentation and English user-visible wording. Preserve technical identifiers, business rules, Amharic text, proper names, licenses and attribution.

## 1. Organize for the reader

Use the [documentation index](README.md) as the audience-first entry point. Keep documentation in Git, review it with implementation changes and test local links. Do not add a publishing framework merely to improve presentation.

| Content type | Reader's question | Required content |
|---|---|---|
| Tutorial | How do I learn this safely? | Audience, synthetic sample environment, prerequisites, guided steps, expected result and next task |
| How-to guide | How do I finish a task? | Permission/scope, entry point, prerequisites, numbered actions, completion check, failure recovery and irreversible effects |
| Reference | What does this control or contract mean? | Exact label/identifier, conditions, action, persistence, side effects, errors and source |
| Explanation | Why does this work this way? | Concepts, relationships, trade-offs and decision-record links |
| Operational runbook | How do I diagnose or change a service safely? | Entry criteria, authority/environment, prerequisites, diagnostics, authorized actions, verification, rollback, escalation and evidence record |

Link to canonical procedures instead of copying them. Keep background explanation outside step lists. Never describe a planned feature in the present tense as if it exists.

## 2. Write professional English

- Prefer clear, direct US English and sentence-case headings. Use active voice and present tense for implemented behavior.
- Begin task steps with an action: **Open**, **Select**, **Enter**, **Review** or **Save**. State the location before the action when it might be unclear.
- Use **select** for controls so instructions work for touch, keyboard and mouse users. Explain an icon's meaning; do not rely only on position or color.
- Quote UI labels exactly, even when the existing interface is inconsistent. Correct a label only after checking selectors, form values, translations and tests.
- Describe completion precisely: saved locally, queued, acknowledged by the server, submitted, approved, downloaded and installed are different outcomes.
- Replace conversational prompts, model personas, self-congratulation and unsupported guarantees with facts and instructions. Do not erase legitimate AI functionality, its security requirements or attribution.
- Avoid decorative emoji in maintained prose, unexplained phase codes, all-capital emphasis and company comparisons used as proof. Preserve exact safety phrases when tests or procedures depend on them.
- Examples use synthetic data. Never include real member details, secrets, private infrastructure, signing material or vulnerability reproduction details in public documentation.

### Terminology

| Preferred prose | Rule |
|---|---|
| Sign in / sign out | Verbs; quote existing **Login** or **Logout** labels exactly |
| User name / username | Use **username** for the account field and UI label; do not confuse it with the person's full name |
| Information Department | Explain the existing **Info Department** label; keep `info_dept` unchanged |
| Materials Department | Keep existing labels and `material_dept` identifiers unchanged unless separately reviewed |
| Member / student / teacher | Use the actual entity and permission context, not interchangeable synonyms |
| Mezmur | Preserve this domain name; explain it as the hymn ministry/catalog where useful |
| Academic year / semester / class day | Use the [glossary](product/GLOSSARY.md) and source rules; never infer a writable day from the device clock alone |
| Development main / production main / deployed revision | Name the repository and environment; **main** alone does not prove deployment |
| Optional AI assistant | Distinguish provider-backed functionality from deterministic failure intelligence and ordinary automation |

## 3. Evidence requirements

The executable implementation takes precedence over comments and older prose. Trace the UI event to its service/endpoint, authorization, validation, persistence and response before documenting an action. A file name or extracted label is discovery evidence, not verified behavior.

| Classification | Meaning |
|---|---|
| VERIFIED | Direct evidence from identified source/configuration/tests or an explicitly dated observation |
| HIGH CONFIDENCE | Multiple supporting references, with a stated remaining gap |
| INFERENCE | Interpretation requiring verification; not a promised capability |
| UNKNOWN | Insufficient evidence; name the missing evidence and next verification action |
| DEPENDENCY NOT VERIFIED | A required external service, configuration or environment was not inspected |
| POTENTIAL SECURITY CONCERN — REQUIRES VERIFICATION | A possible issue requiring private investigation, not a public assertion of a vulnerability |

State the baseline revision and verification type. Source review, local execution, CI, browser acceptance, phone acceptance and hosted acceptance are separate. Do not call all roles, screens or controls complete without a coverage ledger that supports that claim.

For each reviewed manual surface, record the entry point, audience, exact controls, conditions, handlers/endpoints, side effects, error/recovery behavior and what remains untested. Dynamic controls and inherited/shared widgets need explicit review. Extraction scripts may assist discovery; they must not invent explanations.

## 4. Maintenance and ownership

- The contributor changing a feature updates its documentation and tests in the same pull request. The feature maintainer reviews behavior; an operator reviews operational instructions; the owner authorizes production actions.
- Significant design/product choices belong in [ADRs](adr/README.md). Incidents and release acceptance belong in dated records with exact revisions.
- During maintainer transitions, transfer access through approved account/secret-management channels. Public continuity guidance describes responsibilities and evidence, never credentials or conversational instructions to a particular assistant.
- Recheck operational assumptions before every release, including check conclusions, branch protection, deployment method, schema, backups and signing. Old observations remain dated.
- A failed or skipped check is not a successful gate. Do not weaken tests or permissions to make instructions work.

## 5. Retiring a document

1. Read the complete candidate and identify unique knowledge, decisions, limitations, operational safeguards and attribution.
2. Check inbound Markdown links, executable references, migrations, tests and dynamically constructed paths. No grep result alone proves that a public endpoint or runtime file is unused.
3. Migrate useful content to the correct maintained reference, keeping historical context where necessary.
4. Record the retired path, reason, replacement and reference checks. Keep Git history; do not rewrite it to remove legitimate evidence.
5. Remove only the verified obsolete artifact, update its references and run documentation/include/boundary tests.

Historical reports are not deleted merely because they contain an intermediate failure or an incomplete status. Add a dated follow-up link instead of rewriting the original outcome. Preserve the compatible deployment-runbook path where migrations and regression tests still require it.

## 6. Review checklist

- [ ] Audience, purpose, revision and evidence boundary are clear.
- [ ] Current behavior is source-traced; contradictions and unknowns are explicit.
- [ ] Procedures contain permissions, prerequisites, completion checks and recovery.
- [ ] Every destructive step states its scope, confirmation and reversibility.
- [ ] UI labels match the reviewed interface; identifiers/contracts are unchanged.
- [ ] No real school records, credentials, private topology or unsafe release assumptions.
- [ ] Relative links and heading anchors pass `tests/security/test_docs_links.py`.
- [ ] Retirement references and served/nonserved boundaries pass applicable structure tests.
- [ ] Coverage and tests are reported honestly; no generated claim of exhaustive completion.

## 7. Public practices adapted

Research reviewed on 2026-10-28. These sources inform structure and style, not claims about FKSS behavior or the companies' private processes:

- [Google developer documentation style guide](https://developers.google.com/style) and [procedures](https://developers.google.com/style/procedures): clarity, accessibility and action-oriented steps.
- [Microsoft step-by-step instructions](https://learn.microsoft.com/en-us/style-guide/procedures-instructions/writing-step-by-step-instructions): task headings, imperative steps and explicit completion.
- [Diátaxis](https://diataxis.fr/): separate learning, tasks, reference and explanation.
- [GitLab documentation style guide](https://docs.gitlab.com/development/documentation/styleguide/): reader-oriented topics, terminology and reviewed Markdown.
- [Django documentation contribution guide](https://docs.djangoproject.com/en/5.2/internals/contributing/writing-documentation/): version awareness and cross-references rather than duplicated instructions.
- [Write the Docs: docs as code](https://www.writethedocs.org/guide/docs-as-code/): source control, review and automated checks.
- [Anthropic public platform introduction](https://platform.claude.com/docs/en/intro): observed separation of onboarding, concepts, reference and support; no inference about internal authoring practices.
- [Google SRE: being on-call](https://sre.google/sre-book/being-on-call/): explicit operational ownership and evidence, without importing another organization's staffing or service promises.
