# Finance browser API: action reference

**Source baseline:** `591e23d70854079fd27b01038c2117fda0dd2981`, 2026-10-28. **VERIFIED — source review:** all ten switch actions in [admin/api_finance.php](../../admin/api_finance.php), their shared [validator](../../admin/backend/services/LedgerValidation.php), [exception](../../admin/backend/services/LedgerInputException.php) and cited gates. Not a live response/schema certification or a reference for `/api/v1`.

## Request and response conventions

Real path: `/admin/api_finance.php`; compatibility include: [backend/api/finance.php](../../backend/api/finance.php). Read [ledger permission/gate ordering](LEDGER_PERMISSIONS.md) before using any action. Finance/admin role and feature checks precede normal action execution. Business write fields use form POST; read filters use query parameters. Dispatch reads a string `action` from `$_REQUEST`. Write actions require POST; the handler does not impose a GET-only rule on read actions. Every POST requires CSRF, including a read selected by POST.

Each action below adds `status: success` to the described success object. Rows obtained through `fetch_assoc()` are passed into JSON without a per-field normalization layer; do not promise every SQL numeric column is a JSON number. `SELECT *` fields depend on the effective schema, not solely this reference. Explicit PHP casts are noted where present. No invented example IDs, credentials or captured school records are used here.

Shared validation and failure ordering are in [LEDGER_PERMISSIONS.md](LEDGER_PERMISSIONS.md). Default validator failure is 422; branch-specific 404/409 and the general switch 500 catch are described below. These do not replace earlier bootstrap/session/role/feature/method/CSRF failures.

## `dashboard`

**Source:** `case 'dashboard'`. No action-specific input fields.

Returns `data` with:

| Key | Construction in source |
|---|---|
| `total_income`, `total_expense` | Confirmed income/expense sums, cast to float |
| `balance` | Total income minus total expense |
| `month_income`, `month_expense` | Confirmed sums within server `date('Y-m-01')`…`date('Y-m-t')`, cast to float |
| `pending_count`, `pending_amount` | Pending count cast to int, amount cast to float |
| `recent` | Up to 20 `finance_transactions` rows plus `category_name`, `member_name`; descending transaction date then ID; not restricted to confirmed |
| `by_category` | `name`, `type`, aggregate `total`; confirmed transactions joined to categories with INNER JOIN; grouped by category name/type, ordered by total descending |

Uncategorized transactions can contribute to totals while being absent from this INNER JOIN category breakdown. This branch performs reads; no insert, update or audit helper call.

## `categories`

**Source:** `case 'categories'`. No action-specific input fields.

Returns `categories`: `SELECT * FROM finance_categories ORDER BY type, name`. No active-only filter, limit or paging metadata in this branch. It does not create a category or expose a budget operation.

## `transactions`

**Source:** `case 'transactions'`. Query fields:

| Field | Default / actual processing |
|---|---|
| `type` | Empty; if truthy, equality filter; not passed through `Input::choice` |
| `category_id` | Empty; if truthy, equality filter; not passed through `optionalId` |
| `from`, `to` | Empty →null via `Input::date`; supplied dates validated; both present and from>to →422 |
| `status` | Empty; if truthy, equality filter; no enum validator here |
| `search` | Empty; if truthy, `%value%` LIKE matching description, receipt number or member student name |
| `limit` | Missing→100, integer cast then clamped to 1…500 |

Returns `transactions`: transaction columns plus `category_name`, `member_name`, left-joined to categories/members; descending transaction date then ID. No page/total metadata or offset parameter is implemented here. Filter values are bound as strings in this branch; write-validator rules must not be copied onto these read fields. No mutation/audit call.

## `add_transaction`

**Source:** `case 'add_transaction'`, `financeCategory`, `financeMember`. POST required.

| Field | Default / validation |
|---|---|
| `type` | Required exact `income` or `expense` |
| `category_id` | Optional ID/null; when present, existing category with matching type |
| `member_id` | Optional ID/null; when present, existing member |
| `amount` | Required positive `money` |
| `description` | Empty, trimmed UTF-8 text ≤500 characters |
| `receipt_number` | Empty, text ≤50 |
| `payment_method` | Missing→`cash`; exact `cash`, `bank_transfer`, `mobile_money`, `check`, `other` |
| `transaction_date` | Missing/empty→server `date('Y-m-d')`; valid date otherwise |
| `ec_month`, `ec_year` | Optional IDs/null; maximum month 13, year 9999 |

Inserts a confirmed `finance_transactions` row with `recorded_by` from session admin ID. Transaction date is bound as a string. Calls `financeAudit('Finance Transaction Added', ...)` after the insert; helper failure does not undo the ledger write. There is no explicit surrounding database transaction in this branch.

Success: `id` (explicit int cast of insert ID), `message: Transaction recorded`. Invalid/mismatched relationships throw public validation errors. Receipt number is not checked as a deduplication key here. There is no client-supplied actor, approval state or update-by-ID input for this branch.

## `update_transaction`

**Source:** `case 'update_transaction'`, `financeCategory`. POST required.

| Field | Default / validation |
|---|---|
| `id` | Required integer ≥1 |
| `category_id` | Optional ID/null; if present, category must match the transaction's stored type |
| `amount` | Required positive `money` |
| `description`, `receipt_number` | Missing→empty text; maximum 500 / 50 |
| `payment_method` | Missing→`cash`; same exact choices as add |
| `transaction_date` | Missing/empty→server day; valid date otherwise |
| `status` | Missing→`confirmed`; exact `confirmed`, `pending`, `cancelled` |

Looks up the existing row; missing→404. Updates category, amount, description, receipt number, method, date and status. This is **not** a sparse PATCH: omitted optional inputs can clear/reset corresponding stored values. Does not update type, member, EC month/year or recorded actor through this branch. No source-state transition graph is imposed beyond the target enum. Calls best-effort Finance audit; no explicit surrounding transaction. Success: `message: Updated`.

An API action existing does not prove an Edit button is rendered in the separated web table. This reference does not invent one.

## `delete_transaction`

**Source:** `case 'delete_transaction'`; [danger_confirm.php](../../admin/backend/danger_confirm.php), `dc_require_phrase`. POST required.

POST `confirm_phrase` must satisfy the helper's `finance.delete_transaction` phrase before POST `id` is validated as integer ≥1. The helper's normalization, 409 refusal and response fields are described in [permissions](LEDGER_PERMISSIONS.md). The handler reads facts for the audit note, hard-deletes by ID, returns 404 if no row was affected, then calls best-effort audit. Success: `message: Deleted`.

No explicit surrounding transaction, undo or member-fee reversal is implemented in this branch. A short audit note is not a guaranteed restorable copy. No other action is automatically invoked to reconcile related accounting records.

## `save_category`

**Source:** `case 'save_category'`. POST required.

| Field | Default / validation |
|---|---|
| `id` | Optional ID/null; null selects insert |
| `name` | Required nonblank text ≤100 |
| `type` | Missing→`income`; exact `income` or `expense` |
| `description` | Empty text ≤255 |

For update, a missing category→404. If its type would change and a transaction references the category, returns 409. Otherwise updates name/type/description or inserts them. Success: `id` (existing ID or insert ID). No `is_active` input, deletion, explicit transaction or `financeAudit` call in this branch. Updating is not a sparse merge: missing description becomes empty and missing type selects income before the existing-type check.

## `member_fees`

**Source:** `case 'member_fees'`. Query fields:

| Field | Default / actual processing |
|---|---|
| `member_id`, `ec_year` | Missing→0, integer cast; nonzero adds equality filter; not write-ID validation |
| `status` | Empty; filter applied only for recognized `paid`, `unpaid`, `partial` |
| `page` | Presence enables paging; integer cast and clamped to available pages, minimum 1 |
| `per_page` | Paged only; missing→50; integer cast, accepted 25/50/100/200, otherwise 50 |

Returns `fees`: fee columns plus member `student_name`, `member_code`; descending EC year/month then fee ID. Without `page`, maximum 500 and no paging metadata. With `page`, additionally `total`, `page`, `total_pages`, `per_page`, calculated/cast as integers; `total_pages` is at least 1, including zero matches. Read-only; it does not change fee/payment state.

## `save_fee`

**Source:** `case 'save_fee'`, `financeMember`. POST required.

| Field | Default / validation |
|---|---|
| `member_id` | Required integer ≥1, existing member |
| `fee_type` | Missing→`monthly`; required nonblank text ≤100 |
| `amount` | Required positive `money` |
| `ec_month`, `ec_year` | Optional IDs/null; maximum 13 / 9999 |
| `status` | Missing→`paid`; exact `paid`, `unpaid`, `partial` |
| `paid_date` | Missing/empty→server day for paid, otherwise null; supplied valid date accepted |

Starts a database transaction and inserts `finance_member_fees`, actor from session. For paid only, inserts confirmed cash income using the same member/amount/period/date. Looks up the lowest-ID income category named `Monthly Contribution`; no match→null category. Description is `Monthly fee payment` for monthly, otherwise the fee type plus ` fee payment`. Commits after both writes; tracked open transaction rolls back in switch catches. Unpaid/partial insert only the fee record, not corresponding income.

Success: fee `id`, `message: Fee recorded`. No `financeAudit` call in this branch, no existing-fee ID/update contract and no request-id deduplication. The CREATE source default for fee status is **unpaid**, but this handler supplies **paid** when status is absent; they are different layers, not interchangeable defaults. See [column reference](../database/LEDGER_COLUMNS.md).

## `report`

**Source:** `case 'report'`. Query `from` / `to` default to server `date('Y-01-01')` / `date('Y-12-31')`, including empty values through the supplied date defaults. Valid dates required; from>to→422.

Returns `data`:

- `by_category`: `name`, `type`, aggregate `total`, `cnt`; confirmed transactions in inclusive date range, LEFT JOIN categories, grouped by name/type, ordered by type then total descending.
- `monthly`: `month` from SQL `%Y-%m`, `type`, aggregate `total`; confirmed/date-range filter, grouped by month/type and ordered by month.
- `totals`: aggregate `income`, `expense` with SQL COALESCE. Unlike dashboard fields, this branch does not explicitly float-cast them.

No transaction details, budget data, payment posting or mutation. This LEFT JOIN differs from dashboard's INNER JOIN category aggregation. No pagination applies to the aggregate rows.

## Persistence and coverage limits

[Ledger columns and constraints](../database/LEDGER_COLUMNS.md) preserve source declarations without claiming the live schema. The top handler comment mentions budgets, but its switch has no budget action; `finance_budgets` DDL is not evidence of a supported budget API. `financeAudit` is called by add/update/delete transaction, not every mutation despite its broad comment. Source execution outranks both comments.

This reference covers all ten actions in this one handler at the named baseline—not every Finance UI, deployment failure, concurrent outcome or API in the repository. Response loss after an insert is not proof of failure or safe retry. Existing mobile Finance home remains a website notice, not a client for these operations.
