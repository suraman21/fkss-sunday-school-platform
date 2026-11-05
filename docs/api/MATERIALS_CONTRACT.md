# Materials browser API: action reference

**Source baseline:** `591e23d70854079fd27b01038c2117fda0dd2981`, 2026-10-28. **VERIFIED — source review:** all ten switch actions in [admin/api_material.php](../../admin/api_material.php), [LedgerValidation](../../admin/backend/services/LedgerValidation.php), [LedgerInputException](../../admin/backend/services/LedgerInputException.php) and cited gates. Not a live response/schema certification or `/api/v1` contract.

## Request and response conventions

Real path: `/admin/api_material.php`; compatibility include: [backend/api/material.php](../../backend/api/material.php). Read [ledger permissions](LEDGER_PERMISSIONS.md) for exact common roles, feature, session, method, CSRF and failure ordering. Dispatch selects a string `action` from `$_REQUEST`. Write business fields are form POST; query filters come from `$_GET`. Only the six listed writes are POST-restricted by `requirePostActions`; read actions are not explicitly GET-only. Every POST still requires CSRF.

Success objects below include `status: success`. `fetch_assoc` rows are encoded without per-column normalization; `SELECT *` output depends on the effective schema. Only explicit PHP casts described below establish numeric construction in this source. Validator failures default to 422; branch-specific 404/409 and generic switch 500 failures do not replace earlier bootstrap/session/role/feature/method/CSRF failures.

## `dashboard`

**Source:** `case 'dashboard'`. No action-specific input fields. Returns `data`:

| Key | Construction in source |
|---|---|
| `total`, `in_stock`, `low_stock`, `out_of_stock`, `maintenance` | Count/status aggregates, explicitly cast to int when a row is obtained |
| `by_category` | Category `name` and item count `cnt`, LEFT JOIN items, grouped by category ID and ordered by count descending |
| `low_stock_items` | Up to 10 item rows plus `category_name`; status low_stock **or** quantity≤minimum; quantity ascending |
| `recent` | Up to 15 movement rows plus `item_name`; creation timestamp descending |
| `pending_requests` | Pending request count, cast to int when a row is obtained |

The low-stock predicate is not the same as the low-stock status count. This branch reads data; it does not issue stock, approve requests or expose a complete paged movement-history endpoint.

## `items`

**Source:** `case 'items'`. Query fields:

| Field | Default / actual processing |
|---|---|
| `category_id` | Missing→0, integer cast; nonzero applies equality filter; no `optionalId` validation |
| `status` | Empty; only recognized `in_stock`, `low_stock`, `out_of_stock`, `maintenance` values apply a filter |
| `search` | Empty; truthy value supplies `%value%` LIKE matching item name or description |

Returns `items`: all selected item columns plus `category_name` through LEFT JOIN, ordered by item name. No page, limit, total or offset handling in this branch. Category/status/search filters do not change inventory. Do not copy write-field validation onto these read filters.

## `save_item`

**Source:** `case 'save_item'`. POST required.

| Field | Default / validation |
|---|---|
| `id` | Optional ID/null; null selects insert |
| `name` | Required nonblank text ≤150 |
| `category_id` | Optional ID/null; existing category required when supplied |
| `description` | Empty text ≤500 |
| `quantity`, `min_quantity` | Missing→0; whole-number `integer`, 0…2,147,483,647 |
| `unit` | Missing→`piece`; required nonblank text ≤30 |
| `location` | Empty text ≤100 |
| `condition_status` | Missing→`good`; exact `good`, `fair`, `poor`, `damaged`, `disposed` |
| `purchase_price` | Missing/empty/null through the null-coalescing check→null; otherwise `money` allowing zero |
| `purchase_date` | Missing/empty→null; valid date otherwise |

Missing selected category throws validation error. For update, missing item→404. Inserts or replaces the listed item values; new rows include `added_by` from session. No explicit surrounding transaction or movement-history insert. Success: `id` (existing ID or insert ID).

Stock status is recomputed: quantity≤0→out_of_stock, otherwise quantity≤minimum→low_stock, otherwise in_stock. An edit can therefore replace a prior maintenance status. This differs from `add_transaction`, which preserves maintenance. Updating is not sparse: omitted description/location clear to empty, quantity/minimum reset to zero, purchase fields become null, and other defaults are supplied. Quantity editing is not a receipt/issue movement. Backend enum options are not proof the UI exposes them.

## `delete_item`

**Source:** `case 'delete_item'`. POST `id` is integer ≥1.

Starts a transaction, locks the item with `FOR UPDATE`; missing→rollback and 404. Checks movement history, then request links: either existing link throws 409 and the switch catch rolls back. Otherwise hard-deletes the item, commits and returns only `status: success`. No server-side typed-phrase helper is called in this branch. No undo contract is implemented. Source DDL's movement cascade, if applied, is distinct from this API's refusal to delete an item with movement history.

## `add_transaction`

**Source:** `case 'add_transaction'`. POST required.

| Field | Default / validation |
|---|---|
| `item_id` | Required integer ≥1 |
| `type` | Required exact `incoming`, `outgoing`, `adjustment`, `disposal` |
| `quantity` | Missing→0; integer minimum 0 for adjustment, 1 otherwise; maximum 2,147,483,647 |
| `reason` | Empty text ≤255 |
| `handled_by` | Empty text ≤100; descriptive, not authenticated identity |
| `transaction_date` | Missing/empty→server day; valid date otherwise |

Starts transaction and locks item quantity/minimum/status. Missing item→rollback and 404. Outgoing/disposal above available balance→409. New quantity is incoming balance+quantity, outgoing/disposal balance−quantity, or **exact submitted balance** for adjustment. Rejects a resulting negative/above-maximum value. Maintenance status is preserved; otherwise new stock status follows zero/minimum thresholds.

Inserts `material_transactions`, records session admin ID, updates the item's quantity/status and commits. Tracked open transaction rolls back in switch catches. Success: movement `id`, resulting `quantity`, `message: Transaction recorded`. The source does not establish a deduplication key or automatic safe retry after response loss. The reviewed UI sends incoming/outgoing; backend adjustment/disposal support is not a visible-control claim.

## `categories`

**Source:** `case 'categories'`. No action-specific input fields.

Returns `categories`: all `material_categories` columns ordered by name, without an `is_active` predicate, pagination or write. Category existence is not a guarantee of associated stock.

## `save_category`

**Source:** `case 'save_category'`. POST required.

| Field | Default / validation |
|---|---|
| `id` | Optional ID/null; null selects insert |
| `name` | Required nonblank text ≤100 |
| `description` | Empty text ≤255 |

Supplied update ID must exist, otherwise 404. Inserts/updates name and description. Success: existing or inserted `id`. No active-state input, deletion, explicit transaction or activity-log write in this branch. Missing description resets it to empty on update. Server update support does not establish a category-edit button in the current dashboard.

## `requests`

**Source:** `case 'requests'`. Query fields:

| Field | Default / actual processing |
|---|---|
| `status` | Empty; recognized pending/approved/denied/fulfilled applies filter; otherwise not filtered |
| `page` | Presence selects paged branch; integer cast, clamped between 1 and available pages |
| `per_page` | Paged branch only; missing→50; integer cast, accepted 25/50/100/200, otherwise 50 |

Returns `requests`: request columns plus `item_name_ref` from LEFT JOIN to inventory. Paged rows order by creation timestamp descending then request ID descending; response also contains integer `total`, `page`, `total_pages`, `per_page`, with total_pages at least 1. Unpaged compatibility branch returns at most 100, orders by creation timestamp descending without the extra ID tie-breaker, and has no paging metadata. Reading requests does not approve or fulfill them.

## `save_request`

**Source:** `case 'save_request'`. POST required.

| Field | Default / validation |
|---|---|
| `item_id` | Optional ID/null; existing item when supplied |
| `item_name` | Empty text ≤150; required nonblank only if item ID is null |
| `quantity` | Missing→1; integer 1…2,147,483,647 |
| `requested_by` | Required nonblank text ≤100; not an authenticated actor field |
| `department` | Empty text ≤100 |
| `reason` | Empty text ≤500 |

Inserts these six fields in `material_requests`. No status/approved_by input, explicit transaction, stock movement, quantity availability check or activity-log call in this branch. A supplied nonexistent item throws validation error, not this branch's explicit 404. Success: inserted `id`. Initial pending status depends on the table's default; source DDL declares pending, but the INSERT does not explicitly assign it. Do not claim live defaults were verified.

The backend accepts an existing item with optional supplied item name, or a free-text item name without item ID. That is broader than the reviewed selector-based web form; no extra UI is implied.

## `update_request`

**Source:** `case 'update_request'`. POST `id`: integer ≥1. POST `status`: required exact `approved`, `denied` or `fulfilled`.

Looks up request existence, missing→404. Updates `status` and `approved_by` from the session admin ID. Returns only `status: success`. No source-status transition validation, requester ownership comparison, stock update, movement insert, explicit transaction or activity-log call in this branch. The deciding actor field is assigned for denial/fulfillment as well as approval. Do not interpret its column name as proof that the decision was approval.

## Persistence and coverage limits

See [source-defined ledger columns](../database/LEDGER_COLUMNS.md) for literal column/default/index/FK declarations and their application limits. No action in this handler calls the Finance audit helper or writes `activity_logs`; do not claim universal ledger audit logging from a comment in another file. A visible confirmation is not a server-enforced ownership/phrase rule.

This reference covers the ten actions in this one file, not all shared UI controls, concurrent executions, deployed data/schema or other API modules. The principal-table probe is not complete schema readiness. Independent HTTP/browser/host acceptance remains a separate evidence level.
