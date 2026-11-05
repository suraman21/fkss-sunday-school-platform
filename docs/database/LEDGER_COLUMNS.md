# Ledger columns and constraints: source-defined reference

**Source baseline:** `591e23d70854079fd27b01038c2117fda0dd2981`, 2026-10-28. **VERIFIED — source declarations and handler comparison**, not a dump of an applied database or a complete system dictionary. No school data or live database was used.

## 1. Provenance and interpretation

The eight CREATE statements below are in [legacy admin migration 004](../../sql/legacy/php-migrations/admin/004_add_finance_material_tables.php), `$tables`; [legacy backend migration 004](../../sql/legacy/php-migrations/backend/004_add_finance_material_tables.php) has the same DDL but a different config include path. Their current source refuses non-CLI execution. This reference does **not** direct anyone to run those archived scripts or assume their includes work in the archived location.

Columns below reproduce each source declaration after the column name, without its separating comma. `PRIMARY KEY`, `NOT NULL`, explicit `DEFAULT NULL`, enum alternatives and timestamp clauses are preserved. Where `NOT NULL`/an explicit NULL clause is absent, do not substitute invented metadata: actual reported nullability/defaults, especially timestamp behavior, require the effective server version/SQL mode/schema. These are literal definitions, not `SHOW CREATE TABLE` observations.

All eight CREATE statements specify `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`. They use `IF NOT EXISTS`: executing a CREATE against an existing table is not proof that its columns/defaults match this source. Later source ALTERs are listed separately in §10. Applied migration state, physical indexes, generated implicit defaults and target-schema parity remain **UNKNOWN**.

## 2. `finance_categories`

**Definition source:** migration 004, `CREATE TABLE IF NOT EXISTS finance_categories`.

| Column | Declaration after column name |
|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `name` | `VARCHAR(100) NOT NULL` |
| `type` | `ENUM('income','expense') NOT NULL` |
| `description` | `VARCHAR(255) DEFAULT NULL` |
| `is_active` | `TINYINT(1) DEFAULT 1` |
| `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |

No additional index/FK is declared in this CREATE. Runtime: [Finance](../../admin/api_finance.php), `categories`, `save_category`, `financeCategory`, and paid-fee category lookup. The list/relationship check does not filter `is_active`; `save_category` does not accept it as input.

## 3. `finance_transactions`

**Definition source:** migration 004, `CREATE TABLE IF NOT EXISTS finance_transactions`.

| Column | Declaration after column name |
|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `type` | `ENUM('income','expense') NOT NULL` |
| `category_id` | `INT UNSIGNED DEFAULT NULL` |
| `member_id` | `INT UNSIGNED DEFAULT NULL` |
| `amount` | `DECIMAL(12,2) NOT NULL DEFAULT 0` |
| `description` | `VARCHAR(500) DEFAULT NULL` |
| `receipt_number` | `VARCHAR(50) DEFAULT NULL` |
| `payment_method` | `ENUM('cash','bank_transfer','mobile_money','check','other') DEFAULT 'cash'` |
| `transaction_date` | `DATE NOT NULL` |
| `ec_month` | `TINYINT UNSIGNED DEFAULT NULL` |
| `ec_year` | `SMALLINT UNSIGNED DEFAULT NULL` |
| `recorded_by` | `INT UNSIGNED DEFAULT NULL` |
| `status` | `ENUM('confirmed','pending','cancelled') DEFAULT 'confirmed'` |
| `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

CREATE indexes: `idx_type(type)`, `idx_date(transaction_date)`, `idx_member(member_id)`, `idx_category(category_id)`. No FK is in the CREATE; see the later member FK and conditional indexes in §10.

Runtime: [Finance handler](../../admin/api_finance.php), dashboard/transactions/report, add/update/delete and paid `save_fee`. Runtime money validation caps at 99,999,999.99, whereas storage is declared DECIMAL(12,2); do not infer the API limit from storage precision. The handler checks category/member relationships in relevant branches; a category-ID name alone is not proof of a database FK. No receipt-number unique key appears in this CREATE or the listed ledger ALTERs.

## 4. `finance_budgets`

**Definition source:** migration 004, `CREATE TABLE IF NOT EXISTS finance_budgets`.

| Column | Declaration after column name |
|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `category_id` | `INT UNSIGNED DEFAULT NULL` |
| `ec_year` | `SMALLINT UNSIGNED NOT NULL` |
| `budgeted_amount` | `DECIMAL(12,2) NOT NULL DEFAULT 0` |
| `description` | `VARCHAR(255) DEFAULT NULL` |
| `created_by` | `INT UNSIGNED DEFAULT NULL` |
| `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |

CREATE constraint: `UNIQUE KEY uniq_cat_year(category_id, ec_year)`. No FK is declared here. No budget switch action exists in the reviewed Finance API despite its introductory comment. This DDL does not establish a budget UI, API or live table; no whole-repository dynamic-reachability conclusion is made.

## 5. `finance_member_fees`

**Definition source:** migration 004, `CREATE TABLE IF NOT EXISTS finance_member_fees`.

| Column | Declaration after column name |
|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `member_id` | `INT UNSIGNED NOT NULL` |
| `fee_type` | `VARCHAR(100) NOT NULL DEFAULT 'monthly'` |
| `amount` | `DECIMAL(12,2) NOT NULL DEFAULT 0` |
| `ec_month` | `TINYINT UNSIGNED DEFAULT NULL` |
| `ec_year` | `SMALLINT UNSIGNED DEFAULT NULL` |
| `paid_date` | `DATE DEFAULT NULL` |
| `status` | `ENUM('paid','unpaid','partial') DEFAULT 'unpaid'` |
| `recorded_by` | `INT UNSIGNED DEFAULT NULL` |
| `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |

CREATE indexes: `idx_member(member_id)`, `idx_status(status)`. Later member FK in §10. No member/period/fee-type unique constraint appears in this CREATE or the listed ALTERs. [Finance `save_fee`](../../admin/api_finance.php) explicitly supplies the handler default `paid`, unlike this DDL's `unpaid`. Paid income is a separate transaction row; this CREATE does not declare a foreign-key link from a fee to its corresponding income row.

## 6. `material_categories`

**Definition source:** migration 004, `CREATE TABLE IF NOT EXISTS material_categories`.

| Column | Declaration after column name |
|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `name` | `VARCHAR(100) NOT NULL` |
| `description` | `VARCHAR(255) DEFAULT NULL` |
| `is_active` | `TINYINT(1) DEFAULT 1` |
| `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |

No additional index/FK in this CREATE. [Materials](../../admin/api_material.php), `categories`, `save_category`, category existence check and joins. The list does not restrict active status; category save does not accept an active flag.

## 7. `material_items`

**Definition source:** migration 004, `CREATE TABLE IF NOT EXISTS material_items`.

| Column | Declaration after column name |
|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `name` | `VARCHAR(150) NOT NULL` |
| `category_id` | `INT UNSIGNED DEFAULT NULL` |
| `description` | `VARCHAR(500) DEFAULT NULL` |
| `quantity` | `INT DEFAULT 0` |
| `min_quantity` | `INT DEFAULT 0` |
| `unit` | `VARCHAR(30) DEFAULT 'piece'` |
| `location` | `VARCHAR(100) DEFAULT NULL` |
| `condition_status` | `ENUM('good','fair','poor','damaged','disposed') DEFAULT 'good'` |
| `purchase_date` | `DATE DEFAULT NULL` |
| `purchase_price` | `DECIMAL(12,2) DEFAULT NULL` |
| `status` | `ENUM('in_stock','low_stock','out_of_stock','maintenance') DEFAULT 'in_stock'` |
| `added_by` | `INT UNSIGNED DEFAULT NULL` |
| `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

CREATE indexes: `idx_category(category_id)`, `idx_status(status)`. Later category FK in §10. Runtime [Materials `save_item`](../../admin/api_material.php) supplies validated nonnegative quantities, computes stock status and can directly replace balance; it does not merely rely on DDL defaults. DDL's zero quantity default plus in_stock default is not the handler's zero→out_of_stock calculation. `add_transaction` preserves an existing maintenance status; `save_item` recomputes status without that preservation. No migration/policy change is made to reconcile these distinct paths.

## 8. `material_transactions`

**Definition source:** migration 004, `CREATE TABLE IF NOT EXISTS material_transactions`.

| Column | Declaration after column name |
|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `item_id` | `INT UNSIGNED NOT NULL` |
| `type` | `ENUM('incoming','outgoing','adjustment','disposal') NOT NULL` |
| `quantity` | `INT NOT NULL DEFAULT 0` |
| `reason` | `VARCHAR(255) DEFAULT NULL` |
| `handled_by` | `VARCHAR(100) DEFAULT NULL` |
| `recorded_by` | `INT UNSIGNED DEFAULT NULL` |
| `transaction_date` | `DATE NOT NULL` |
| `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |

CREATE indexes: `idx_item(item_id)`, `idx_type(type)`. Later item FK in §10. Runtime [Materials `add_transaction`](../../admin/api_material.php) validates quantity by type, records session actor and changes stock under a transaction/row lock. `handled_by` is bounded descriptive text, not a user foreign key or trusted login identity.

## 9. `material_requests`

**Definition source:** migration 004, `CREATE TABLE IF NOT EXISTS material_requests`.

| Column | Declaration after column name |
|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `item_id` | `INT UNSIGNED DEFAULT NULL` |
| `item_name` | `VARCHAR(150) DEFAULT NULL` |
| `quantity` | `INT NOT NULL DEFAULT 1` |
| `requested_by` | `VARCHAR(100) NOT NULL` |
| `department` | `VARCHAR(100) DEFAULT NULL` |
| `reason` | `VARCHAR(500) DEFAULT NULL` |
| `status` | `ENUM('pending','approved','denied','fulfilled') DEFAULT 'pending'` |
| `approved_by` | `INT UNSIGNED DEFAULT NULL` |
| `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

No additional index/FK in this CREATE or request-specific ALTER in the cited hardening statements. Runtime [Materials `save_request` / `update_request`](../../admin/api_material.php) checks supplied item existence, inserts requests without explicit status, and updates status/deciding actor separately. Requested-by text is not a user-ID FK. Approved-by is set for each supported decision, not approval alone. No automatic stock movement is implemented by that update branch.

## 10. Later source constraints and conditional indexes

[003_production_hardening.sql](../../sql/003_production_hardening.sql), ledger sections, contains the following ALTER statements. **Declared in source does not mean applied.** Its preceding cleanup statements mutate/delete orphan records; this table is a reference, not execution instructions.

| Named FK | Source table/column | Referenced table/column | Declared delete action | Preceding cleanup |
|---|---|---|---|---|
| `fk_fin_member` | `finance_transactions.member_id` | `members.id` | SET NULL | Null missing-member links |
| `fk_fee_member` | `finance_member_fees.member_id` | `members.id` | CASCADE | Delete missing-member fee rows |
| `fk_mi_category` | `material_items.category_id` | `material_categories.id` | SET NULL | Null missing-category links |
| `fk_mt_item` | `material_transactions.item_id` | `material_items.id` | CASCADE | Delete missing-item movement rows |

The Materials deletion API refuses an item linked to movements/requests. That API behavior and a source-declared movement FK cascade are different layers; do not document the API as invoking the cascade for historical items or assume the FK exists on the target.

[Legacy optimization admin 005](../../sql/legacy/php-migrations/admin/005_optimize_database.php), Finance section, conditionally attempts `idx_fin_type_status(type, status)` and `idx_fin_recorded_by(recorded_by)` if table exists and named index is absent. [Backend copy](../../sql/legacy/php-migrations/backend/005_optimize_database.php) has the same Finance index statements but a different config include. Current scripts refuse HTTP and also retain session/role gates; their existence does not establish successful execution. Source comments describing safety do not prove idempotence/application success.

The charset-conversion lines for these ledger tables in SQL 003's section C are **comments**, not executed ALTERs. No automatic migration order, target state, restore outcome or production enforcement is inferred here.

## 11. Trace to actual API behavior

Use [Finance contract](../api/FINANCE_CONTRACT.md), [Materials contract](../api/MATERIALS_CONTRACT.md) and [permission/gate reference](../api/LEDGER_PERMISSIONS.md). API input defaults/limits, literal source DDL and effective stored metadata are separate facts. The [older table inventory](../DATA_DICTIONARY.md) remains historical; these eight manually reviewed definitions do not refresh its other domains or convert it into a live-schema audit.
