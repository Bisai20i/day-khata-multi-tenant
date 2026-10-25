# Implementation Plan 01: Replace Item Deletion with Active/Inactive Management

## 1. Requirement Summary
In the Item Name list (`/items`), replace the current destructive SQL `Delete` action with an Active/Inactive status management workflow:
- Users must be able to mark an item as Inactive instead of deleting it through the list action.
- Inactive items must appear in a separate "Inactive Items" list/tab.
- Active items must remain visible in the default "Active Items" list/tab.
- When an inactive item is reactivated, it must return to the normal active item list.
- An item with remaining stock on hand must not be deactivated until its stock is zero.
- Historical records, ledger entries, previous invoices, and stock movements referencing inactive items must remain completely preserved.

## 2. Current Implementation
- **Controller**: [`app/Http/Controllers/Tenant/Inventory/ItemController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Inventory/ItemController.php)
  - `index()` (lines 68–90) fetches all items regardless of `is_active` status and sends them to the frontend.
  - `destroy(Item $item)` (lines 126–141) attempts a hard delete via `$item->delete()`. When a `QueryException` is caught due to foreign key constraints, it returns: `"{$item->name}" is used by a bill, a stock movement or another record, so it cannot be deleted. Mark it inactive instead.`
  - `stillStockedRule(?Item $item)` (lines 454–468) prevents deactivating an item in update validation if `$item->currentStock()` is non-zero.
- **Frontend Page**: [`resources/js/pages/Tenant/Inventory/Items/Index.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Inventory/Items/Index.vue)
  - Renders a single table displaying all items.
  - Row actions include `RowActions` with `canDelete: can('items.delete')` calling `destroy(row.original)` (`router.delete('/items/${item.id}')`).
  - There is no UI button to mark an item inactive from the list or to view/filter inactive items.
- **Database Schema**:
  - `items` table already possesses a boolean `is_active` column (default `true`) created in migration `2026_08_25_100008_create_items_table.php`.
  - No soft delete column (`deleted_at`) exists.

## 3. Research Findings
- **Database Foreign Keys**: Exactly 8 database tables enforce `restrictOnDelete()` against `items.id`:
  1. `item_stock_movements.item_id`
  2. `sale_lines.item_id`
  3. `purchase_lines.item_id`
  4. `stock_adjustment_lines.item_id`
  5. `quotation_lines.item_id`
  6. `item_varieties.item_id`
  7. `stock_transfer_lines.item_id`
  8. `stock_conversion_lines.item_id`
  Because of these constraints, any item that has been used in a transaction cannot be deleted via SQL `DELETE`.
- **Picker Isolation**: Inactive items are already filtered out of Sales (`SaleController::itemsForPicker()` queries `where('is_active', true)`), Purchases (`PurchaseController` queries `where('is_active', true)`), and POS (`PosController` queries `where('is_active', true)`).
- **Reports**: Reports join historically without `is_active` filter, ensuring past turnover, revenue, and COGS calculations remain 100% accurate.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - Controller: [`InventoryController.php:1207–1247`](file:///D:/Projects/day-khata/day_khata/app/Http/Controllers/InventoryController.php#L1207-L1247) route `GET /delete-itemsDetails/{id}`.
  - Table: `inventorysettings.status` (`0` = Active, `1` = Inactive).
  - Toggling required closing stock to be zero (`$closingStock != 0 -> error: Closing stock must be zero`).
  - View: [`resources/views/items/itemlist.blade.php:479–493`](file:///D:/Projects/day-khata/day_khata/resources/views/items/itemlist.blade.php#L479-L493) provided a header toggle button: `"View Inactive Items"` / `"View Active Items"` with query parameter `inactive_items=1`.
  - Row action menu provided a status toggle link (`<i class="fa fa-power-off"></i>`) to toggle between active and inactive.

## 5. Verified Root Cause
The new multi-tenant application has the `is_active` database column and the backend `stillStockedRule` validation rule, but the UI was built with a generic `DELETE` action on table rows. When the foreign key constraint blocked deletion, the backend told the user to "Mark it inactive instead", but no route or button was ever implemented to allow the user to mark an item inactive or filter inactive items.

## 6. Proposed Implementation
1. **Route**:
   - Add atomic status toggle route in [`routes/tenant-business.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/routes/tenant-business.php):
     ```php
     Route::patch('/items/{item}/toggle-active', [ItemController::class, 'toggleActive'])
         ->middleware('can:items.edit')
         ->name('items.toggle-active');
     ```
2. **Controller**:
   - In [`ItemController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Inventory/ItemController.php):
     - Add `toggleActive(Item $item)`:
       - If active: checks `$item->currentStock()->isZero()`. If non-zero, redirects back with validation error: `"{$item->name}" still has {$onHand} {$item->unit} in stock. Clear the stock first, or leave the item active.` If zero, updates `is_active = false` and flashes `"{$item->name}" marked as inactive.`
       - If inactive: updates `is_active = true` and flashes `"{$item->name}" reactivated successfully.`
     - In `index(Request $request)`: Support tab filtering by passing items separated or filtered by `is_active` status, with counts for `activeCount` and `inactiveCount`.
3. **Frontend UI**:
   - In [`resources/js/pages/Tenant/Inventory/Items/Index.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Inventory/Items/Index.vue):
     - Add segmented tabs: **Active Items (`activeCount`)** and **Inactive Items (`inactiveCount`)**.
     - In the **Active Items** list: Remove the `Delete` action from `RowActions`. Add a `"Mark Inactive"` action button with confirmation dialog. If stock is non-zero, alert the user immediately.
     - In the **Inactive Items** list: Provide a `"Reactivate"` action button with confirmation dialog.
     - Add informational banner in the Inactive tab: *"Inactive items are hidden from sales, purchase, and stock entry forms. Historical reports and ledger data remain preserved."*

## 7. Affected Components
- `routes/tenant-business.php`
- `app/Http/Controllers/Tenant/Inventory/ItemController.php`
- `resources/js/pages/Tenant/Inventory/Items/Index.vue`
- `resources/js/components/inventory/ItemsIndexFormModal.vue`

## 8. Dependencies and Risks
- **Dependency**: No database migration needed (`is_active` already exists).
- **Risk**: Unique constraint on `items.name`. If a user deactivates an item and tries to create a new item with the exact same name, it violates `unique('name')`.
  - *Mitigation*: The validation message should clearly state: *"An inactive item with this name already exists. You can reactivate it from the Inactive Items tab."*
- **Risk**: Stock discrepancies.
  - *Mitigation*: Atomically gate deactivation on `$item->currentStock()->isZero()` both frontend and backend.

## 9. Testing and Verification
- **Automated Tests**:
  - Create Feature test in `tests/Feature/Tenant/Inventory/ItemStatusManagementTest.php`:
    - Test active item deactivation fails when stock > 0.
    - Test active item deactivation succeeds when stock == 0.
    - Test inactive item does not appear in Sales or Purchase pickers.
    - Test inactive item reactivation succeeds.
- **Manual Verification**:
  1. Open `/items`, verify "Active Items" and "Inactive Items" tabs.
  2. Verify Delete button is replaced with "Mark Inactive".
  3. Attempt to deactivate an item with stock; verify warning.
  4. Deactivate an item with 0 stock; verify it moves to "Inactive Items" tab.
  5. Go to "Inactive Items" tab, click "Reactivate"; verify it moves back to "Active Items".

## 10. Completion Criteria
- [x] No `DELETE /items/{id}` action available on active items.
- [x] Active and Inactive items displayed in separate tabs with accurate counts.
- [x] Items with non-zero stock blocked from deactivation.
- [x] Reactivation restores item to active list and pickers.
- [x] All automated tests pass.

## 11. Open Questions
- *None*: The legacy behavior and multi-tenant schema align completely with zero ambiguity.
