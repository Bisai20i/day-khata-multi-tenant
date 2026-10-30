# M08b Strict stock for perpetual items

Follow-up (parallel with M08a) | Decided by the user on 2026-10-25 | Agent: builder-high

## Owned files

- `app/Models/Sale.php`, `SalesReturn.php`, `Purchase.php`, `PurchaseReturn.php`, `StockAdjustment.php` and
  `StockTransfer.php`.
- New `app/Support/Inventory/PerpetualStockGuard.php`, if a shared helper is cleaner than repeating the rule.
- The sale/POS Vue pages, only if the shortfall message needs surfacing (grep for how stock errors are shown today).
- Tests: new `tests/Feature/Tenant/Manufacturing/PerpetualStrictStockTest.php`, plus any existing test whose expectation
  changes (list them).

Do NOT edit `Item.php`, `PerpetualCosting.php`, `MaterialIssueNote.php` or `ProductionEntry.php` (M08a owns the last two).

## Decision (do not re-ask)

Raw Material and Finished Good items **always** enforce stock on every stock-out path, ignoring
`company_settings.allow_negative_stock`, exactly like `MaterialIssueNote::post` already does. The moving average is
value / quantity on hand, which is meaningless at zero or negative quantity. Trading items keep honouring the setting.

## Tasks

- [x] 1. Find every place `allow_negative_stock` is read on a stock-out path: sale and POS post, sales return cancel,
  purchase cancel, purchase return post (linked and unlinked) and cancel, stock adjustment out and adjustment-in
  cancel, stock transfer post and cancel. For perpetual items, make the check unconditional, store-scoped and
  aggregated per item. The check runs after the item lock, and the error names the item and the shortfall. Trading
  behaviour must stay identical.
- [x] 2. Make sure the sale and POS forms surface the server's shortfall message on the right line (they may already
  do so for trading items with the setting off; reuse that).
- [x] 3. Tests (write, do not run), with `allow_negative_stock` ON:
  - Overselling a finished good is refused, while overselling a trading item still succeeds.
  - Cancelling a raw-material purchase whose stock was already issued is refused.
  - A perpetual adjustment out beyond stock is refused.
  - A transfer of a perpetual item beyond the source store's stock is refused.
  - Two lines of the same perpetual item are aggregated.
