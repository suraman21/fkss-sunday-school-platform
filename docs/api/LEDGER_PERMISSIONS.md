# Ledger permissions, request gates and validation

**Source baseline:** `591e23d70854079fd27b01038c2117fda0dd2981`, 2026-10-28. **VERIFIED — source review:** the browser Finance/Materials handlers, shims and gates cited below. This is not an all-system permission matrix, an executed HTTP denial test or proof of deployed configuration. No permissions are changed by this reference.

## 1. Routes and role boundary

| Handler / compatibility path | Effective role allowlist in `ROLE_MAP` | Capability |
|---|---|---|
| `/admin/api_finance.php`, `/backend/api/finance.php` | `super_admin`, `school_admin`, `finance_dept` | `finance` → `FEATURE_FINANCE` |
| `/admin/api_material.php`, `/backend/api/material.php` | `super_admin`, `school_admin`, `material_dept` | `material` → `FEATURE_MATERIAL` |

Sources: [access_control.php](../../admin/access_control.php), `$ROLE_MAP` entries for `api_finance.php`, `finance.php`, `api_material.php`, `material.php`; [FeatureGate.php](../../admin/backend/services/FeatureGate.php), `forAdminRequest`, `isEnabled`; [Finance shim](../../backend/api/finance.php), [Materials shim](../../backend/api/material.php). The shims include the real handlers; they are not HTTP redirects. The guard uses the requested script basename under `/admin/` or `/backend/`, not the included handler's filename alone. Both real and shim basenames are mapped. The capability check requires the known constant to equal boolean `true`; an undefined constant or string value is not enabled by `isEnabled`.

The ledger actions have no separate role allowlists inside their switch branches: the common module allowlist applies to every action below. That statement describes these two files, not other endpoints. A school admin impersonating a restricted role uses the assumed role here: the original-role exception in the guard is only for the impersonation switch/restore endpoints.

## 2. Execution order and denial shape

1. Each handler includes [admin/config.php](../../admin/config.php), which includes root [config.php](../../config.php). Before the centralized access guard, root configuration can invalidate a privileged session after account revalidation fails; its JSON branch returns 401 or 503 with `status`, `message`, `action: reload`. Do not assume the later handler's simple Unauthorized envelope always wins.
2. Root configuration includes `admin/access_control.php` after its auth helpers. The guard returns early for CLI or a defined `WBWS_API_REQUEST`, and limits its normal path to the requested admin/backend area. These are source execution exceptions, not routine authorization for a browser client to bypass checks.
3. On the normal guarded HTTP path, `isLoggedIn()` requires `admin_logged_in === true`; role allowlist enforcement follows, then capability enforcement. `_ac_deny` returns JSON for an AJAX/API-classified request: `status` is `session_expired` for 401, otherwise `error`; `action` is `reload` for 401, otherwise `denied`. Non-AJAX handling sets a login-page Location header. `_isAjaxRequest` recognizes real `api_` names and `/backend/api/` paths, among other signals. Do not extrapolate their error envelope to every browser page.
4. The handler separately rejects an empty `admin_logged_in` with 401 and `status: error`, `message: Unauthorized`. It selects a string `action` from `$_REQUEST`; non-string action becomes empty. Request-source precedence comes from PHP configuration, not a custom precedence rule in these handlers.
5. `requirePostActions` rejects a listed write action on a non-POST request with 405, `Allow: POST` and `status: error`, `message: This action requires POST.`
6. `requireCsrfForPost` checks **every POST**, including POSTs selecting a read action. It accepts the form/header channels and JSON-token fallback implemented in that helper; `validateCsrf` requires nonempty string token/session values and compares them with `hash_equals`. Refusal is 403 with `status: error`, `message: Security token expired. Please refresh the page.` The JSON-token fallback does not parse ledger action fields into `$_POST`.
7. A non-mysqli connection returns 503. The handler then probes only its principal table (`finance_transactions` or `material_items`) and returns a module-specific 503 on a caught probe exception. This does not verify every related table/column exists. These probes occur before the switch's try/catch.
8. The selected action runs. Unknown action returns 400. `LedgerInputException` returns its public message and supplied HTTP status (default 422); explicit conflicts use 409. The switch's general `Throwable` catch returns 500 with a generic module-specific message and calls `reportInternalError`. Open tracked database transactions are rolled back in these catches. Do not claim the switch catch covers every earlier bootstrap failure.

Sources: [config.php](../../config.php), privileged session-revalidation block, `_isAjaxRequest`, `isLoggedIn`, `requirePostActions`, `requireCsrfForPost`, `validateCsrf`, `jsonResponse`; [access guard](../../admin/access_control.php), closure and `_ac_deny`; [Finance preamble/catches](../../admin/api_finance.php); [Materials preamble/catches](../../admin/api_material.php).

## 3. Finance action matrix

All rows use the Finance roles/capability in §1. **Read fields** come from `$_GET`; read actions are not restricted to GET by `requirePostActions`. Calling one by POST still invokes CSRF. All **POST required** rows consume business fields from `$_POST`.

| Action | Method restriction | Additional record / confirmation checks | Source effect boundary |
|---|---|---|---|
| `dashboard` | No read-method restriction here | No record-ID input | Aggregates and selected recent rows |
| `categories` | No read-method restriction here | No record-ID input | Categories read, including no `is_active` filter |
| `transactions` | No read-method restriction here | Optional filters; validated date range | Limited transaction list |
| `member_fees` | No read-method restriction here | Optional member/year/status filters | Compatibility or paged fee list |
| `report` | No read-method restriction here | Validated date range | Confirmed transaction summaries |
| `add_transaction` | POST required | Optional member must exist; category must exist with matching type | Confirmed insert; best-effort audit call |
| `update_transaction` | POST required | Transaction exists; category matches stored transaction type | Updates listed fields; best-effort audit call |
| `delete_transaction` | POST required | Typed phrase then valid ID; missing transaction →404 | Hard delete; best-effort audit call |
| `save_category` | POST required | Existing category for update; type change refused when transactions use it | Insert/update category |
| `save_fee` | POST required | Member exists; amount/period/status/date validation | Fee plus paid-income transaction as described in [Finance contract](FINANCE_CONTRACT.md) |

Source: each corresponding `case` in [api_finance.php](../../admin/api_finance.php). No creator-only, class, academic-year assignment or per-record owner restriction is applied in these branches; submitted IDs are checked as described, not silently scoped to the actor's own records. This is a bounded source observation, not a proposed permission policy.

Finance deletion calls `dc_require_phrase('finance.delete_transaction')` before ID validation. [danger_confirm.php](../../admin/backend/danger_confirm.php) maps it to `DELETE TRANSACTION`, normalizes whitespace/case, rejects non-string input and compares with `hash_equals`. Missing/wrong phrase produces 409 and the helper's `status`, `success`, `ok`, `code`, `needs_confirmation`, `confirm_phrase`, `message`, `error` fields. It is a confirmation gate in addition to role/method/CSRF—not a credential or ownership proof.

## 4. Materials action matrix

All rows use the Materials roles/capability in §1; the same read-method and POST-CSRF distinction applies.

| Action | Method restriction | Additional record / confirmation checks | Source effect boundary |
|---|---|---|---|
| `dashboard` | No read-method restriction here | No record-ID input | Stock/request summaries and limited recent rows |
| `items` | No read-method restriction here | Optional category/status/search filters | Item list; no pagination in this branch |
| `categories` | No read-method restriction here | No record-ID input | Category list, no `is_active` filter |
| `requests` | No read-method restriction here | Recognized optional status; optional paging | Request list |
| `save_item` | POST required | Optional category exists; supplied item ID exists | Item insert/update; direct quantity replacement |
| `delete_item` | POST required | Locked item exists; no movement history or linked request | Transactional hard delete |
| `add_transaction` | POST required | Locked item exists; stock/range validation | Movement insert and stock update in one transaction |
| `save_category` | POST required | Supplied category ID exists | Category insert/update |
| `save_request` | POST required | Optional item exists; otherwise item name required | New request; no stock change |
| `update_request` | POST required | Request exists; allowed destination status | Status and deciding admin only; no stock change |

Source: corresponding `case` branches in [api_material.php](../../admin/api_material.php). There is no Materials `dc_require_phrase` call in these branches; a UI dialog does not establish Finance's server-phrase contract. `update_request` does not enforce a source-status transition graph or requester-only decision rule. `handled_by` and `requested_by` are descriptive input, not trusted authentication identity. Actor IDs for ledger writes/decisions come from `$_SESSION['admin_id']` as cast by the handlers; not every insert includes an actor field.

## 5. Shared validator semantics

Source: [LedgerValidation.php](../../admin/backend/services/LedgerValidation.php), each named method; [LedgerInputException.php](../../admin/backend/services/LedgerInputException.php), constructor.

| Method | Source rule |
|---|---|
| `text` | Rejects nonscalar non-null input, then string-casts/trims; requires valid UTF-8; optional nonblank requirement; `mb_strlen` maximum. It is not a string-only input check |
| `integer` | Accepts only string/int matching signed whole-number syntax; bounds default 0…2,147,483,647; branch may raise the minimum |
| `optionalId` | Null, empty string, integer 0 or string `0` →null; otherwise `integer` minimum 1 |
| `money` | String/int/float with decimal syntax, at most two fractional places; finite and ≤99,999,999.99; strictly positive unless `allowZero` is true |
| `choice` | String exactly in the supplied array, strict comparison |
| `date` | Null/empty → supplied default; otherwise string `YYYY-MM-DD`, year ≥1000 and valid `checkdate`; no trimming step |

## 6. Review limits

This reference establishes gates and branch checks from source, not runtime effectiveness on an actual host. CLI helper tests do not exercise the HTTP access guard. Deployment feature values, session/database state, effective schema, browser interaction and all other API modules remain separately unverified here. See [Finance](FINANCE_CONTRACT.md), [Materials](MATERIALS_CONTRACT.md) and the [source-defined ledger dictionary](../database/LEDGER_COLUMNS.md) for the matching bounded contracts.
