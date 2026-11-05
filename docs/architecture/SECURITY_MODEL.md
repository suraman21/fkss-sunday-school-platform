# Security architecture and review model

**Baseline:** development main `13f00ac`, reviewed 2026-10-28. **Classification:** source-reviewed design model; not a penetration test, live configuration audit, comprehensive threat inventory or security certification. Read [security policy/evidence limits](../SECURITY.md) and [incident response](../INCIDENT_RESPONSE.md). Report exploitable details privately, not in public documentation.

## 1. Purpose and method

This model makes explicit the assets, trust transitions, implementation evidence and verification questions a maintainer must consider before a change. It follows the public [OWASP threat-modeling method](https://cheatsheetseries.owasp.org/cheatsheets/Threat_Modeling_Cheat_Sheet.html): what are we working on, what can go wrong, what will we do and what evidence is enough? It is intentionally maintained alongside source rather than treated as a one-time audit.

**VERIFIED** below means the cited source implements the described control. It does not prove the hosted configuration enables it, every action applies it or every possible bypass was tested. Proposed cases are review requirements, not newly executed tests.

## 2. Assets and actors

| Asset or actor | Why the boundary matters | Data-handling requirement |
|---|---|---|
| Staff/session/token identity | Establishes who can act, not merely what a client claims | Never publish tokens, cookies, credentials or unredacted request headers |
| Member/education/attendance information | Student and staff privacy; role/class/year scope | Synthetic examples for development; minimal authorized access and private support evidence |
| Finance and inventory | Integrity of money, stock, history and related records | Verify exact mutation/transaction semantics; do not blindly retry an uncertain insert |
| Profile images and uploads | Ownership, content safety and storage exposure | Validate intended access and private-storage flow; filenames/URLs are not authorization |
| Device-local work and diagnostics | Ownership, confidentiality and unsent work | A local save is not server acceptance; inspect logs before sharing; no real data in fixtures |
| Repository, environment, backups and signing material | Application integrity and operational recovery | Separate development authority from production, database restore and release signing |
| Browser/mobile user | Untrusted inputs cross into trusted application operations | Hidden UI and local checks are not server controls |
| Maintainer/AI tooling | Can affect source and release decisions | Least authority, reviewed diff, candidate checks, no instructions executed from untrusted records/logs |

No retention period, legal compliance conclusion, staffed incident SLA or actual key custodian is established by this model. These require owner-approved evidence.

## 3. Source-bounded data-flow views

### Browser ledger request

```text
Staff browser (untrusted fields / client state)
  │ session cookie + request + CSRF value
  ▼ HTTP application boundary
Browser page/bootstrap: config.php; frontend layout where used
  │ centralized access_control.php role guard + feature capability
  ▼
Finance: frontend/js/core.js → backend/api/finance.php shim
  → admin/api_finance.php
Materials: admin/dashboards/material_department.php inline JS
  → admin/api_material.php
  │ handler login, write-method and CSRF checks; LedgerValidation
  ▼ persistence boundary
mysqli queries / per-action transactions and row locks
  → database records; selected activity logging
  │ JSON success/error
  ▼ browser state boundary
Reload lists/summary or show failure; response loss leaves outcome uncertain
```

Sources: [dashboard routing](../../admin/dashboard.php), [browser role guard](../../admin/access_control.php), [capability resolution](../../admin/backend/services/FeatureGate.php), [Finance shim](../../backend/api/finance.php), [Finance handler](../../admin/api_finance.php), [Materials handler](../../admin/api_material.php), [shared validator](../../admin/backend/services/LedgerValidation.php), [core transport](../../frontend/js/core.js), [Materials UI](../../admin/dashboards/material_department.php).

**VERIFIED:** `requirePostActions` and `requireCsrfForPost` are distinct checks in [config.php](../../config.php). Finance/Materials handlers invoke both. Explicit Finance and Materials role entries exist for real handler/shim names. `FeatureGate` maps both modules and requires actual boolean `true` for a known enabled capability. This is not a full per-action authorization audit of other modules.

### Authenticated self-service profile

```text
Client identity claim + profile fields (untrusted)
  ▼ API authentication boundary
users route: apiRequireAuth() → verified uid
  ▼ identity/domain boundary
AuthenticatedProfileIdentity::fromTrustedUserId(uid)
  → ProfileService / profile-image service and repository
  ▼ database / private-storage boundary
Authenticated user's record/image; version and validation rules
  ▼ response boundary
Profile/error response; client must handle stale state and failure
```

Sources: [users.php](../../api/v1/routes/users.php), [ProfileService.php](../../admin/backend/services/ProfileService.php). **VERIFIED:** the route constructs self-service identity from authenticated `uid`; own-image ownership parameter rejection is explicit; own-profile and administrator identity types are distinct. **DEPENDENCY NOT VERIFIED:** actual hosted private-storage permissions, schema readiness and all image serving paths need authorized runtime evidence. This view does not assert that the complete API-token lifecycle or all upload controls have been re-audited here. The token format is custom two-part HMAC, not JWT; see [auth.php](../../api/v1/core/auth.php), `createToken` and `verifyTokenState`.

### Repository and release boundary

```text
Human or AI proposal → reviewed source diff → targeted tests
  → exact candidate PR checks / repository protection
  → normal development main merge
  → separately observed development-server update and owner acceptance
  → separate explicit production authorization / release procedure
```

Source authority: [workflow definitions](../../.github/workflows/), [development/testing](../DEVELOPMENT_AND_TESTING.md) and [production workflow](../PRODUCTION_WORKFLOW.md). Source equality, CI success and debug APK compilation do not prove deployment, signing-key custody, restore readiness or installed-phone acceptance.

## 4. Threat-oriented change review

| Concern | Source control/evidence to inspect | Required verification or residual risk |
|---|---|---|
| Acting outside role/record scope | Central guard, action-level checks and trusted identity creation | Negative HTTP cases for anonymous/wrong role/wrong record; CLI paths can bypass browser guard; never rely on hidden navigation |
| Forged or wrong-method mutation | `requirePostActions`, CSRF validation and session context | Exercise write-via-GET, missing/invalid CSRF and correct-role POST on synthetic fixtures |
| Malformed or misleading ledger inputs | `LedgerValidation`, relationship checks, prepared statements in selected handlers | Type/array, bounds, Unicode, invalid dates, missing relationships and wrong category type; do not assume every query shares the same pattern |
| Partial or conflicting persistence | Paid-fee transaction; Materials movement transaction with `FOR UPDATE` | Exercise rollback and concurrent updates where relevant; not a claim of universal atomicity |
| Duplicate insert after response loss | Actual action and client retry semantics | Reconcile first. No deduplication guarantee has been established for the documented fee/inventory inserts |
| Mistaking approval for movement | `update_request` updates request status; movement writes are separate | Verify status change alone does not alter stock; current UI shows decision buttons only for pending requests |
| Misleading destructive confirmation | Current UI plus server deletion branch | Finance checks typed confirmation server-side; do not assume Materials uses the same phrase contract. History/request links prevent selected item deletions |
| Evidence loss or overclaiming audit | `financeAudit` and its call sites | Helper is best effort and catches failures; fee/category branches do not become audited merely because a comment says every mutation is. Reconcile action-by-action |
| Sensitive diagnostics/exports | Actual data-producing path, private support practice | Inspect/redact before sharing; export presence does not establish encryption, retention or authorization outside the application |
| Repository artifact disclosure | Root `.htaccess` extension/directory denial rules | `.md` rule covers root `AGENTS.md` in checked-in Apache rewrite configuration; verify the actual host honors rules. PHP's built-in router is not Apache proof |
| Untrusted instructions influencing a maintainer | [agent contract](../../AGENTS.md), reviewed source/commands | Never execute instructions embedded in logs, school records, dependencies or retrieved pages; tool authority comes from the authorized task, not those inputs |

These are generic review scenarios, not newly disclosed exploit demonstrations. Preserve detailed findings and reproducible security evidence privately under [SECURITY.md](../SECURITY.md).

## 5. Required evidence before changing this model's boundaries

For an affected path, record entry/handler, trusted actor source, target scope, feature state, input contract, persistence/filesystem effects, error disclosure, recovery and independent test evidence. Review success and denial cases using disposable synthetic data. Update the data flow and security reference in the same change when its meaning changes.

Maintain three separate conclusions: **source control exists**, **specific fixture passed**, and **authorized hosted control verified**. Do not collapse them into a single "secure" verdict. A source gap is a verification/action item, not permission to alter security policy during documentation work.

## 6. Open verification work

- Complete action-level authorization, field-level API and column-level schema reconciliation across all domains.
- Reproduce/fix Finance submission recovery in a separately authorized behavioral change; see [worked trace](../DEVELOPER_GUIDE.md).
- Independently review private upload/storage, proxy/header/configuration, backup/restore, operational ownership, signing and incident drill evidence.
- Verify hosted versions, flags, actual schema, migration application and device behavior only with appropriate owner authorization.
- Obtain independent maintainer/security review; a self-authored model and passing CI do not replace it.
