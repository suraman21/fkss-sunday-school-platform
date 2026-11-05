# Selected ledger action contracts

**Baseline:** development main `13f00ac`, 2026-10-28. **Status:** source-reviewed field/side-effect reference for three selected browser actions—not complete API conformance, every-action authorization or live-schema verification. This is separate from the `/api/v1` OpenAPI reference. Review current source before implementing a client.

## 1. Shared browser contract

The Finance shim [backend/api/finance.php](../../backend/api/finance.php) includes [admin/api_finance.php](../../admin/api_finance.php). Materials' reviewed dashboard posts to [admin/api_material.php](../../admin/api_material.php). Both use a staff session, not the mobile API's custom two-part HMAC bearer token. [The token implementation](../../api/v1/core/auth.php), `createToken` and `verifyTokenState`, is not JWT despite its historical comments and `JWT_SECRET` setting name. Their real handler/shim names are covered by [access_control.php](../../admin/access_control.php) and module [feature resolution](../../admin/backend/services/FeatureGate.php). Finance allows the admin/Finance roles; Materials allows the admin/Materials roles. Check actual action/record scope separately for any new path.

The actions below require **POST**. Browser transport supplies form fields/FormData and CSRF; do not assume raw JSON will populate `$_POST`. [core.js](../../frontend/js/core.js) constructs FormData for the separated Finance client. [config.php](../../config.php), `requirePostActions` and `requireCsrfForPost`, implements distinct method and CSRF checks. These fields are an input contract, not permission to issue requests against a real school.

Source success responses contain `status: success` and the action-specific values below. Invalid action is HTTP 400; write-via-wrong-method is 405; selected missing records are 404; insufficient stock is 409. [LedgerInputException.php](../../admin/backend/services/LedgerInputException.php) supplies validation status, and handler catches return its public message. Internal exceptions are logged with a generic public 500 response; module/database unavailability can return 503. Authentication/role/feature/CSRF can terminate before action execution, so do not require every denial to have the same controller envelope or status.

## 2. Finance `save_fee`

| Form key | Required/default | Validation and meaning |
|---|---|---|
| `action` | Required `save_fee` | Dispatch selector |
| `member_id` | Required | Integer ≥1, existing member |
| `fee_type` | Defaults `monthly` if absent | Required nonblank text after trimming, ≤100 characters |
| `amount` | Required | Positive decimal, at most 2 places, ≤99,999,999.99 |
| `ec_month` | Optional/null | Positive integer when present; ≤13; blank/zero maps to null |
| `ec_year` | Optional/null | Positive integer when present; ≤9999; blank/zero maps to null |
| `status` | Defaults `paid` | Exactly `paid`, `unpaid` or `partial` |
| `paid_date` | Optional | Valid `YYYY-MM-DD`; absent/blank defaults to server day only when paid, otherwise null |
| `csrf_token` | Required through accepted CSRF channel | Session-bound CSRF proof; not a user/role identity |

Source: `case 'save_fee'`, `financeMember` and [LedgerValidation](../../admin/backend/services/LedgerValidation.php). Integer maximum is 2,147,483,647 before narrower bounds. The shared money validator rejects negative/zero, exponent notation, more than two places and nonscalar types.

**Side effects:** inserts `finance_member_fees`. For paid, also inserts confirmed cash income in `finance_transactions` with the fee amount/member/date/period; category is the first matching income `Monthly Contribution` category or null. The fee and income writes are in one database transaction; the handler rolls back on caught failure while that transaction is open. Unpaid/partial do not create matching income in this branch. `recorded_by` comes from session admin ID, not a submitted actor field.

**Success:** fee ID in `id`, message `Fee recorded`. This action inserts rather than updates an existing fee. No request-id/deduplication or update-by-ID contract is established here. A response lost after commit requires reconciliation before retry. Audit helper existence does not imply this branch calls it.

**Client difference:** `saveFee()` supplies the local day for paid fees and reloads fees, overview and income. Its current busy-reset limitation is documented in the [developer guide](../DEVELOPER_GUIDE.md) and [manual](../user_manual/FINANCE.md). Do not silently implement an accounting workflow from the status labels alone.

## 3. Materials `add_transaction`

| Form key | Required/default | Validation and meaning |
|---|---|---|
| `action` | Required `add_transaction` | Dispatch selector |
| `item_id` | Required | Integer ≥1, existing inventory item |
| `type` | Required | Exactly `incoming`, `outgoing`, `adjustment` or `disposal` |
| `quantity` | Defaults 0, but type-specific validity | Integer ≥0 for adjustment; ≥1 otherwise; maximum 2,147,483,647 |
| `reason` | Optional empty | Trimmed text ≤255 characters |
| `handled_by` | Optional empty | Trimmed text ≤100 characters; not the authenticated actor |
| `transaction_date` | Optional | Valid `YYYY-MM-DD`; absent/blank defaults to server day |
| `csrf_token` | Required through accepted CSRF channel | Session-bound CSRF proof |

**Side effects:** starts a database transaction, locks the item with `FOR UPDATE`, validates availability, inserts `material_transactions`, updates item quantity/status and commits. `recorded_by` is the session admin ID. Outgoing/disposal greater than balance returns conflict. Adjustment means the **new counted balance**, not an increment or decrement. Resulting stock must stay between zero and 2,147,483,647. Existing maintenance status is preserved; otherwise status derives from new quantity and minimum.

**Success:** movement ID in `id`, new balance in `quantity`, message `Transaction recorded`. A missing item returns 404; input/internal failures roll back when the transaction is open. This is source transaction evidence, not a newly executed concurrent HTTP test or universal retry guarantee.

**Client difference:** the reviewed dashboard's `addTxn` sends incoming/outgoing only. Adjustment/disposal are backend options, not demonstrated visible buttons or authorized staff procedures. `handled_by` is descriptive input; never use it as an authorization identity.

## 4. Materials `update_request`

| Form key | Required/default | Validation and meaning |
|---|---|---|
| `action` | Required `update_request` | Dispatch selector |
| `id` | Required | Integer ≥1, existing material request |
| `status` | Required | Exactly `approved`, `denied` or `fulfilled` |
| `csrf_token` | Required through accepted CSRF channel | Session-bound CSRF proof |

**Side effects:** updates only `material_requests.status` and `approved_by` from session admin ID. It does not decrement stock or insert a movement. **Success:** `status: success`; missing request returns 404.

**Client difference:** `_reqRow` renders approve/deny buttons only for pending requests. `updateReq` containing a fulfilled label does not mean a fulfilled control is rendered. The handler checks existence and allowed target status, but this branch does not enforce a source-status transition graph. Do not invent a pending-only server restriction, automatic fulfillment movement or visible reversal UI. Policy changes require their own authorized scope.

## 5. Verification and maintenance

Source references above establish selected request/response and persistence behavior. Existing [audit HTTP fixtures](../../tests/audit/http_regressions.py) contain related synthetic cases but are not implicitly part of the default security suite; review their current prerequisites and compatibility before execution. The [pure validator fixture](../../tests/audit/test_static_safety.py) exercises validation without a database. Their existence is not proof that every contract row or browser path was executed for this documentation candidate.

For the broader ledger references, see [Finance](FINANCE_CONTRACT.md), [Materials](MATERIALS_CONTRACT.md), [ledger permissions](LEDGER_PERMISSIONS.md) and [source DDL columns](../database/LEDGER_COLUMNS.md). Their existence does not expand this page's three-action scope or prove runtime acceptance. Open work includes claim-by-claim audit of those references, other domain fields, complete response/error contracts, installed-client compatibility and target-schema parity. Preserve [coverage](../user_manual/COVERAGE.md) and [quality gates](../QUALITY_GATES.md) distinctions. Never generate a full-conformance claim from this selected reference.
