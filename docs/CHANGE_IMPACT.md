# Change-impact and source-navigation guide

**Baseline:** development main `13f00ac`, 2026-10-28. **Status:** source navigation and review procedure, not exhaustive action-level authorization or executed acceptance. Verify actual symbols and consumers at your candidate revision. Start with [the developer guide](DEVELOPER_GUIDE.md).

## 1. Map the change before the patch

| Impact | Required record | Example: change a ledger validation rule |
|---|---|---|
| Direct | Files/functions that must change for the request | `LedgerValidation` method and the intended Finance/Materials handler |
| Indirect | Callers/consumers of changed behavior | Other actions using that shared validator; form validation and displayed messages |
| Potential | Related surface not yet verified | Older reachable dashboard, exports, installed clients and hosted data assumptions |
| Unrelated | Explicitly preserve | Authentication, role/feature policy, schema, dependencies and release configuration unless separately authorized |

Search is discovery, not proof. After finding callers, read the relevant branches, data flow, response handling and tests. Record why each caller is affected or unaffected. A method name, button label, table name or passing lint is not sufficient behavioral evidence.

## 2. Starting points by boundary

| Change area | Start reading | Required neighboring review |
|---|---|---|
| Browser bootstrap/session | [config.php](../config.php), [access_control.php](../admin/access_control.php) | Real route and shim names, public exceptions, CLI exceptions, role map, session/CSRF and feature enforcement. Do not confuse login-only fallback with an explicit role restriction |
| Feature capabilities | [FeatureGate.php](../admin/backend/services/FeatureGate.php) | Whole-module and mixed-action gates; actual boolean configuration; frontend/mobile visibility is not server authorization |
| Finance | [frontend page](../frontend/pages/finance_dept.php), [finance.js](../frontend/js/finance.js), [core.js](../frontend/js/core.js), [API](../admin/api_finance.php) | Compatibility shim, role/feature gates, shared validator, fee/ledger atomicity, reports, destructive confirmation and historical inline dashboard reachability |
| Materials | [dashboard](../admin/dashboards/material_department.php), [API](../admin/api_material.php) | Inventory filter state and dropdown source, locked stock movement, request status versus stock, deletion-history restrictions and shared profile/communications components |
| Self-service profile | [users route](../api/v1/routes/users.php), [ProfileService.php](../admin/backend/services/ProfileService.php) | Trusted authentication identity, own-profile versus administrator entry points, version conflicts, image ownership/storage, credential changes, browser adapter and mobile callers |
| API v1 | [api/v1](../api/v1/), [API.md](API.md) | Route matching, authentication, method, input parsing, error envelope, scope and every installed-client consumer. OpenAPI is summarized, not full conformance proof |
| Mobile | [app source](../Mobile/wbws_flutter_app/lib/), [tests](../Mobile/wbws_flutter_app/test/) | Actual role/navigation path, injected service, serialization, local persistence and ownership, network failure/queue recovery, server acceptance and version compatibility |
| Schema | [sql](../sql/), [DATABASE.md](DATABASE.md) | DDL and runtime query consumers, legacy fixture setup order, defaults/nullability, indexes/constraints, target-schema evidence, backup/rollback. Do not apply the directory blindly in numerical order |
| Web assets/themes | [frontend](../frontend/), [themes](../themes/) | Layout/bootstrap, shared styles and responsive/accessible states; external-library failure. Do not rename technical identifiers while improving visible wording |
| Documentation | [catalog](DOCUMENTATION_CATALOG.md), [standard](DOCUMENTATION_STANDARD.md), [manual coverage](user_manual/COVERAGE.md) | Claim-level source evidence, index/links, private-data exposure, dated versus maintained facts and executable documentation tests |

The table is a starting map, not a file/function inventory of every domain. For education, attendance, Mezmur, communications, member administration and other tasks, trace the actual route and scoped handler from [architecture](ARCHITECTURE.md) rather than assuming the ledger pattern applies.

## 3. Review questions that prevent unsafe shortcuts

### Identity and authorization

- Which browser-session or authenticated API-token identity is trusted? The mobile API uses a custom two-part HMAC token, not JWT ([auth.php](../api/v1/core/auth.php), `createToken` / `verifyTokenState`). Can a request parameter nominate another actor or target?
- Which role, feature, class, academic year and record ownership checks run on the server?
- Does the same request through a compatibility path receive the same restriction?
- Does a CLI test bypass a guard that only runs for HTTP? Is a browser test needed?
- Is an endpoint absent from an explicit role map and therefore only login-restricted? Investigate; do not silently add a permission-policy change.

### Contracts and persistence

- What are the actual method/content type, required/optional inputs, allowed enums, limits and response/error shapes?
- Which inserts/updates/deletes happen, in which transaction? Are related audit or filesystem writes atomic, best effort or separate?
- What happens if the server commits but the client loses the response? Is deduplication actually implemented for this action?
- Do filters, pagination, limits, calendar conversion or export use only loaded rows? Does a UI status change actually change stock, money or approval?

### Compatibility and failures

- Are there multiple reachable interfaces? Is an API action missing from the current UI?
- Does a network/schema/validation/permission failure have an honest recovery path? Are pending flags reset in both success and failure paths?
- Does the change affect old clients or locally queued work? A new server contract must not silently discard old offline payloads.
- Are production parity, migration execution, installed version and operational authority unknown? Keep them unknown; do not infer them from checkout parity.

## 4. Stop conditions

Stop the risky action—not all safe investigation—when required access is missing, a check fails, the branch conflicts, data might be real, a destructive fixture cannot be isolated, the requested change needs unauthorized policy/schema/dependency work or production promotion lacks explicit authority. Preserve a secret-free record of the exact blocker and remaining safe work. Never bypass protection or modify an assertion merely to make a check green.

## 5. Completion evidence

Review the entire diff and record exact candidate/merge SHA, changed-file rationale, tests/results/skips, source-preservation boundaries, outstanding manual/action review and owner/runtime gates. Use [quality gates](QUALITY_GATES.md) for documentation and [development/testing](DEVELOPMENT_AND_TESTING.md) for CI/release. A complete plan is not a completed implementation; a generated inventory is not a reviewed manual.
