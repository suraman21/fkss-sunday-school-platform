# Materials: inventory, receipts, issues and requests

**Audience:** Materials staff and authorized school administrators. **Evidence:** source reviewed against development main `13f00ac` (2026-10-28); no browser/device acceptance is claimed for this chapter. **Coverage:** core Materials web workflow and controls below. Shared profile, communications, notifications, responsive navigation and all failure-state acceptance still need their own control reconciliation. This is not an exhaustive all-control departmental sign-off.

## Availability and safe starting point

Sign in with your assigned account and open the Materials dashboard. [Dashboard routing](../../admin/dashboard.php) includes [material_department.php](../../admin/dashboards/material_department.php) for `material_dept`. The [server role map](../../admin/access_control.php) allows Materials handlers for `super_admin`, `school_admin` and `material_dept`; the `material` capability must also be available. A missing module is not permission to use another person's account or turn on a deployment feature.

The mobile Materials home displays **Materials stay on the website**. It does not receive, issue or edit stock. Use the website for inventory work. Its notification bell is a separate shared feature, not an inventory refresh or stock-control button.

If you see **Setup Required** or a service-unavailable message, stop and ask the deployment administrator to check the versioned migrations. Do not run migration URLs, change database settings or repeatedly submit records.

## 1. Understand the overview

**Total Items**, **In Stock**, **Low Stock**, **Out of Stock**, **Maintenance** and **Pending Requests** are summary cards, not automatic purchasing or issue actions. **Low Stock Alerts** and **Recent Activity** summarize selected records; they are not the full movement history. The API limits low-stock alerts and recent movements, and the dashboard renders only part of that activity list.

The top-level cards are initially rendered by PHP. `loadDash()` refreshes alert/activity lists, not every server-rendered counter. After a mutation, a card may remain stale until a page reload. Reconcile the actual inventory/request rows before relying on a counter. A blank or failed-loading list is not proof that stock is zero.

## 2. Add or edit an inventory item

1. Open **Inventory** (or **Items** in the compact navigation).
2. Choose **Add Item**, or the row's pencil icon with tooltip **Edit**.
3. Enter **Name**, choose **Category**, and review **Description**, **Quantity**, **Min Qty**, **Unit**, **Location** and **Condition**.
4. Choose **Save**. On success the dialog closes and inventory/alert lists are requested again. Verify the saved row and quantity.

| Field | Meaning and source-validated limit |
|---|---|
| Name | Required; at most 150 Unicode characters after trimming |
| Category | Optional existing category; blank means no category |
| Description | Optional; at most 500 characters |
| Quantity | Whole number, zero or greater; maximum 2,147,483,647 |
| Min Qty | Whole-number low-stock threshold, zero or greater; same maximum |
| Unit | Required; defaults to `piece`; at most 30 characters |
| Location | Optional; at most 100 characters |
| Condition | Visible options are Good, Fair, Poor and Damaged; this is distinct from stock availability |

**Important:** editing **Quantity** directly changes the stored balance through `save_item`; it is not the same as recording an incoming/outgoing movement. Do not use it as a substitute for a receipt/issue record when history is required. Follow the school's authorized correction procedure rather than inventing one from this manual.

The server derives availability from quantity and threshold: zero → `out_of_stock`; positive quantity at or below minimum → `low_stock`; otherwise → `in_stock`. A condition such as **Damaged** does not by itself record a disposal. Purchase price/date and additional backend condition/status possibilities are not fields exposed by this item dialog.

The cross at the top of a dialog closes it without submitting. Closing is not a save, nor a guaranteed field reset: inspect all values when reopening. If a request times out, first reload/reconcile the inventory before submitting again.

## 3. Search, filter and export

Use **Search**, **Category** and **Status** in Inventory. Search is sent to the API for name/description matching. Category and supported stock status restrict the loaded list. Clear filters to return to the broader list. The visible Status selector offers In Stock, Low Stock and Out of Stock; do not assume every backend status is an available selector choice.

Choose **Export** to write an `.xlsx` inventory file from the currently loaded `items` array. It includes Name, Category, Qty, Min, Unit, Location, Condition and Status—not every database field or the whole movement/request history. Filters therefore affect what is available to export. Verify the rows before treating a download as complete. Browser library/download failure is not a server-side inventory change. Store and share exports only through approved school channels.

The same loaded inventory array supplies the receipt/issue/request item selectors. If an expected item is absent, clear Inventory filters and reload before opening those forms. Do not create a duplicate item merely because a filtered selector is incomplete.

## 4. Receive or issue items

1. Open **Receive Items** / **In** or **Issue Items** / **Out**.
2. Select the existing **Item** and a positive whole-number **Quantity**.
3. Review **Date**. Defaults are client-generated; verify the intended day rather than assume timezone/calendar conversion.
4. Enter **Received From / Reason** or **Issued To / Reason**, and **Handled By** as appropriate.
5. Choose **Record Incoming** or **Record Outgoing**.
6. On success, verify the resulting quantity in Inventory. The form clears quantity/reason/handler and reloads item/alert data; this does not guarantee every summary card is refreshed.

Incoming increases stock. Outgoing decreases stock; the server refuses an issue greater than the available quantity. Reason is limited to 255 characters, handler to 100, and the submitted date must be a valid `YYYY-MM-DD` date. The server locks the item row, inserts a movement and updates its balance within one transaction. This source behavior is not a newly executed concurrency test in this chapter.

The API also accepts adjustment and disposal movement types, but this dashboard's reviewed receipt/issue controls send incoming/outgoing only. Do not look for a nonexistent adjustment button or treat a backend capability as an approved staff workflow.

If the item was removed or the available balance changed, stop and reload. A network error after submission does not tell you whether a movement committed; check the quantity and recent activity privately before retrying. Do not issue twice to overcome an uncertain response.

## 5. Create and decide a request

1. Open **Requests** and choose **New Request**.
2. Select **Item**, enter a positive **Quantity** and **Requested By**. Review **Department** and **Reason**.
3. Choose **Submit**. On success the dialog closes and requests reload.
4. Review the request's status in the list. The compact check and cross buttons appear only for a `pending` row; they call Approve and Deny respectively and display a confirmation dialog.
5. Confirm only after checking the correct request. Canceling the dialog does not submit the status change.

Requested By is required and limited to 100 characters; Department is optional up to 100; Reason is optional up to 500. The reviewed web form selects an existing item and sends no free-text item name. A backend free-text option does not mean it is a visible form control.

**Approval does not issue stock.** `update_request` changes status and the recorded deciding administrator; it does not change inventory quantities or insert a movement. Record an authorized issue separately through **Issue Items** when appropriate.

The API accepts `fulfilled`, and the generic function contains a fulfilled label, but the reviewed row renderer only offers approve/deny for pending rows. Do not tell users they can reverse a decision or mark it fulfilled through a button that is not rendered. The confirmation's statement about changing status again is not proof of a visible recovery control.

The requests list uses the shared pager when available. Use its page/page-size controls to inspect more records; the unpaged API fallback returns at most 100 recent requests. Detailed shared pager behavior and responsive acceptance remain in the coverage queue. A first page is not a count of every request.

## 6. Categories

Open **Categories**, choose **Add**, enter **Name** (required, up to 100 characters) and optional **Description** (up to 255), then **Save**. A successful save closes the modal and reloads categories/selectors. Inspect the fields when reopening; do not assume they were reset.

The reviewed list displays category names/descriptions without edit or delete buttons. Although `save_category` accepts an existing ID on the server, that does not create an edit workflow in this interface.

## 7. Delete an item—permanent

Use the row's trash icon with tooltip **Delete** only when authorized. Read the confirmation, verify the item and cancel if unsure. If the confirmation library fails to load, the UI refuses the action and asks for a reload; do not work around it.

The server refuses deletion when the item has movement history or is linked to a request. It locks and checks the item before deletion. An allowed deletion permanently removes the inventory item and its current balance; no recycle bin or undo is established here. Do not erase related history or use SQL to defeat a refusal. Ask an administrator about the authorized correction/retirement process.

Materials' deletion confirmation is not Finance's server-validated typed-phrase contract. Do not assume that identical-looking dialogs imply identical backend checks.

## Core control-to-handler reference

All web writes below pass the handler's login/POST/CSRF checks and the module's role/feature boundary. Exact validation and error behavior is in [api_material.php](../../admin/api_material.php) and [LedgerValidation.php](../../admin/backend/services/LedgerValidation.php).

| Control family | Client handler → server action | Effect / recovery |
|---|---|---|
| Section navigation | `nav()` | Changes visible section and selected URL query; some sections reload data |
| Add / Edit; Save | `openItemModal` / `editItem` / `saveItem` → `save_item` | Inserts/updates item, including direct quantity edit; verify row after uncertain outcome |
| Search / Category / Status | `loadItems` → `items` | Read/filter; no stock mutation |
| Inventory Export | `exportItems` | Client-side workbook of loaded rows; no API mutation |
| Receipt / Issue buttons | `addTxn('incoming'/'outgoing')` → `add_transaction` | Transactional movement and stock update; insufficient stock refused |
| New Request; Submit | `openReqModal` / `saveReq` → `save_request` | Inserts request, not a stock movement |
| Pending check / cross | `updateReq` → `update_request` | Request decision only; buttons disappear outside pending state |
| Category Add; Save | `openMatCatModal` / `saveMatCat` → `save_category` | New category in reviewed UI; server also supports update by ID |
| Item Delete | `delItem` → `delete_item` | Permanent, refused for linked history/requests |
| Modal close | `closeModal` | Local close, no write; not a guaranteed field reset |
| Request pager | `_reqFetch` / `loadReqs` → `requests` | Paged read; shared control behavior not fully reconciled here |

## Sources and remaining verification

- [Materials dashboard](../../admin/dashboards/material_department.php): section/forms, inline handlers, navigation, export, request row rendering and shared component includes.
- [Materials API](../../admin/api_material.php): each action, validation, locks, transaction/rollback and public error responses.
- [Mobile Materials home](../../Mobile/wbws_flutter_app/lib/screens/material/material_home.dart): website-only notice and notification bell.
- [Audit HTTP fixtures](../../tests/audit/http_regressions.py): existing synthetic ledger/stock cases; their existence does not mean this chapter has executed them.

Remaining: independent walkthrough, failure/response-loss/concurrency acceptance, all shared profile/communications/notification/navigation controls, legacy/direct-path reconciliation and hosted role/feature/schema parity. See [coverage](COVERAGE.md). Source-reviewed core guidance must not be counted as a complete exhaustive departmental manual.
