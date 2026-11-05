# Finance: income, expenses, member fees and reports

**Audience:** Finance staff and authorized school administrators. **Evidence:** source reviewed against development main `13f00ac` (2026-10-28); no browser/device acceptance claimed. **Coverage:** separated Finance web interface and core handlers below. Shared member picker, pager, profile, communication, theme, notifications and all legacy/direct-path controls are not fully reconciled here. This is not exhaustive departmental sign-off.

## Availability and important limitations

Use your assigned Finance account on the website. The `finance_dept` branch in [admin/dashboard.php](../../admin/dashboard.php) redirects to [frontend/pages/finance_dept.php](../../frontend/pages/finance_dept.php). That page declares `super_admin`, `school_admin` and `finance_dept` roles and the `finance` capability; its layout and the API enforce their own boundaries. Do not infer access from a hidden or visible navigation item.

The mobile home says **Finance stays on the website**: income, fees and reports are not phone workflows. Its notification bell is not a Finance transaction control.

**Current submission limitation:** the source has a busy-state reset defect in `saveTxn`, `saveFee` and `saveCat`. A successful save can leave that form's Save button disabled for the remainder of the page session; a failure is not guaranteed to recover the button correctly either. Verify whether the record was saved, then reload before further work. Do not click repeatedly or assume a timeout means nothing committed. This chapter documents the limitation; it does not fix the application. The [developer trace](../DEVELOPER_GUIDE.md) records the exact source finding.

## 1. Read the overview correctly

**Overview** shows totals and recent activity. Confirmed transactions contribute to income/expense totals and balance; pending records are counted separately. Recent activity is a limited list, not a complete ledger or bank reconciliation. An empty/failed-loading section is not proof that the school has no financial records.

The quick **Income** and **Expense** buttons select a section and open its add dialog. Compact navigation uses shorter labels such as **Home**, **Expense**, **Fees** and **Cats**. These are navigation, not a different financial calculation.

## 2. Record income or expense

1. Open **Income** or **Expenses**, then **Add**; alternatively use the overview quick button.
2. Confirm the dialog's **Add Income** or **Add Expense** title.
3. Choose **Category** if appropriate and enter **Amount (ETB)**. Review **Description**, **Date**, **Payment Method** and **Receipt #**.
4. For income, optionally select **Member (optional)** using the member picker. Selecting a member is not the same as recording a member fee.
5. Choose **Save** once. On success the dialog closes, the transaction section/overview reload and selected text fields clear. Reconcile the resulting row before any retry or another entry; see the busy-state limitation above.

| Input | Server behavior |
|---|---|
| Amount | Required; positive, at most two decimal places; maximum 99,999,999.99; scientific notation/negative values rejected |
| Category | Optional existing category of the correct income/expense type |
| Description | Optional, at most 500 Unicode characters after trimming |
| Receipt # | Optional, at most 50 characters |
| Payment Method | Server allows cash, bank transfer, mobile money, check and other enum values; use the displayed selector |
| Date | Valid submitted `YYYY-MM-DD` date; UI may present the Ethiopian date-picker display while retaining the Gregorian transport value |
| Member | Optional existing member; reviewed add dialog shows the member picker for income |

`add_transaction` inserts a **confirmed** transaction. Do not assume an added expense is pending approval merely because the API also supports other statuses. Do not treat a receipt number as a demonstrated duplicate-prevention key. No deduplicated submission guarantee is established for this insert path.

The close icon dismisses the modal without submitting. Do not assume it clears all old values; inspect the dialog before saving another record.

## 3. Record a member fee

1. Open **Member Fees**, then **Record**.
2. Select **Member** and enter **Amount (ETB)** and **Fee Type**.
3. Review **EC Month (1–13)**, **EC Year** and **Status**. The current JavaScript initializes the year approximately from the Gregorian year; verify it against the intended Ethiopian period rather than trust the default.
4. Choose **Save** once and reconcile the fee entry and, when paid, the corresponding income entry.

Member and fee type are required; fee type is limited to 100 characters. Amount uses the same positive money rule as transactions. EC month/year are optional positive identifiers when supplied, with maximum month 13 and year 9999. Supported statuses are `paid`, `unpaid` and `partial`.

**Paid has a second effect:** the server wraps fee insertion and a matching confirmed **cash income** transaction in one database transaction. It looks up the income category named `Monthly Contribution`; it does not assume a fixed category ID. If that category is absent, the income category can be null. The UI sends the local day as the paid date for a paid fee.

For `unpaid` and `partial`, this branch inserts the fee record but does **not** insert matching ledger income. Do not assume a partial fee automatically records a partial cash receipt or infer an unpaid balance from the entered amount. Follow the school's authorized process; this manual does not define new accounting policy.

**Do not manually add a second income record for the same paid fee just because the fee list and income list are separate.** Reconcile first. Deleting the matching income transaction does not automatically delete or correct the fee record.

The list offers **Status** and **EC Year** filters and a shared pager. Filters/page size change what you see, not stored records. The paged API returns totals/page metadata when requested; the unpaged compatibility response is capped at 500. Shared pager/member-search controls still require detailed independent reconciliation.

## 4. Categories

Open **Categories**, choose **Add**, then enter **Name**, **Type** and optional **Description**. Name is required up to 100 characters; description up to 255; type is income or expense. Choose **Save** once and verify the category after reload if the button remains busy.

The separated lists display income and expense categories without edit/delete controls. The server's `save_category` can update an ID, but that is not a visible edit workflow in the reviewed page. The server refuses changing the type of a category already used by transactions. Ask the administrator about an authorized correction rather than trying a direct API request.

## 5. Reports and exports

1. Open **Reports**.
2. Review **From** and **To**; start must not be after end. Confirm the actual submitted dates, particularly when the Ethiopian date-picker is active.
3. Choose **Generate**.
4. Check the requested range and the rendered income, expense, net and category results before choosing **Export**.

The report handler includes **confirmed** transactions whose stored dates are between the two submitted dates, inclusive. Pending/cancelled transactions do not contribute. The client shows totals and a category table; API monthly data does not imply a separately visible chart exists.

Income/expense **Export** writes an `.xlsx` file from the currently loaded client transaction array. The list loader does not request every record: the API default transaction limit is 100, maximum 500 if explicitly requested. A downloaded list is not a full financial-year audit export.

Report **Export** reads the currently rendered category table, not a fresh database snapshot. The source does not clearly reset every previously rendered report element for empty/error results, so never assume an old visible table belongs to the newly selected range. Reconcile dates/results before exporting, or reload and generate again. If the XLSX library is unavailable, the client reports a library-loading problem; no financial records are changed by export failure.

Treat exports as school information. Use approved storage/sharing; do not upload ledger/member data to public issues, AI prompts or unapproved support channels.

## 6. Delete a transaction—permanent

The trash icon has tooltip **Delete**. It opens a high-impact confirmation describing the transaction and consequences. Read it carefully, confirm the intended record and provide the displayed typed phrase only when authorized. Cancel if unsure. The server separately checks the Finance deletion phrase; a UI-only confirmation is not its whole protection.

An allowed delete permanently removes the transaction and changes totals/reports. There is no undo/recycle-bin contract. Other transactions, categories and member-fee rows are not automatically removed. `financeAudit` attempts to retain a short activity note, but logging is best effort; it is not a guaranteed recoverable copy of the deleted transaction.

**Wording discrepancy:** the confirmation suggests using Edit for an amount mistake, but the separated transaction table exposes Delete, not an Edit button. An `update_transaction` API branch exists; that does not make it a visible control in this page. Do not delete/recreate a record merely to work around the missing UI; ask the administrator for the approved correction process.

If the dialog library did not load, the UI refuses the action and requests a reload. Do not bypass confirmation or erase records directly in the database.

## Core control-to-handler reference

[finance.js](../../frontend/js/finance.js) handles the page; [core.js](../../frontend/js/core.js) sends FormData/CSRF; [backend/api/finance.php](../../backend/api/finance.php) includes the real [Finance API](../../admin/api_finance.php). Writes require the server's login/role/feature, POST and CSRF conditions; passing client validation alone is not permission.

| Control family | Handler → action | Effect / boundary |
|---|---|---|
| Overview / Income / Expenses / Fees / Categories / Reports | `Finance.nav` | Section change and relevant loaders; shared navigation acceptance still pending |
| Overview Income/Expense; section Add | `openAddTxn` | Opens corresponding modal; no write yet |
| Transaction Save | `saveTxn` → `add_transaction` | Confirmed insert; reconcile before retry; busy reset defect remains |
| Transaction Delete | `deleteTxn` → `delete_transaction` | Permanent, server checks phrase; does not correct a fee row |
| Member Fees Record; Save | `openFeeModal` / `saveFee` → `save_fee` | Fee insert; paid additionally creates cash income atomically |
| Fee Status / EC Year / pager | `loadFees` → `member_fees` | Filtered/paged read; not a payment mutation |
| Category Add; Save | `openCatModal` / `saveCat` → `save_category` | New category in current UI; update-by-ID is API behavior |
| From / To; Generate | `loadReport` → `report` | Confirmed-date-range summary; check for stale rendered content |
| List Export | `exportTxns` / `exportXlsx` | Workbook of loaded rows, not a server full-ledger export |
| Report Export | `exportReport` / `exportXlsx` | Workbook of rendered category table; reconcile range first |
| Modal close icons | `modal(..., false)` | Local close only; inspect retained values on reopen |

## Sources and remaining verification

Sources: [page/forms](../../frontend/pages/finance_dept.php), [client handlers](../../frontend/js/finance.js), [layout authorization/calendar](../../frontend/layouts/base.php), [server branches](../../admin/api_finance.php), [validator](../../admin/backend/services/LedgerValidation.php), [mobile website-only home](../../Mobile/wbws_flutter_app/lib/screens/finance/finance_home.dart), [existing synthetic audit HTTP cases](../../tests/audit/http_regressions.py).

Remaining: independently reproduce/review submission recovery, report empty/error behavior and date defaults; all shared/picker/pager/profile/communication/notification/theme controls; older inline Finance dashboard/direct-path reconciliation; hosted role/feature/schema parity and school accounting-process acceptance. See [coverage](COVERAGE.md). Neither source-reviewed core guidance nor an API action list completes the requested every-control manual.
