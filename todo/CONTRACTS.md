# Shared contracts

Every task codes against these names and behaviours. A task that **implements** a contract must match it
exactly; a task that **consumes** one written by a sibling running in parallel codes against this text. If an
implementer must deviate, it says so in its report and the coordinator updates this file before the next phase.

Verified environment facts: PHP 8.4.14 locally and in CI (`composer.json` allows `^8.3`), `bcmath` + `intl`
loaded, `brick/math 0.18.0` installed (`Brick\Math\BigDecimal`, `Brick\Math\RoundingMode::HalfUp` enum case),
Node 24, tests on SQLite `:memory:`, production MySQL strict mode. Ledger sums include every voucher line
regardless of `journal_vouchers.status` (status is informational; a cancellation posts a mirrored voucher).

---

## C1. Money and Quantity value objects (implemented by T01)

Namespace `App\Support\Money`. Files `app/Support/Money/Money.php`, `Quantity.php`, `InvalidAmount.php`.

- `Money`: immutable, scale **2** (rupees and paisa). Used for every amount.
- `Quantity`: immutable, scale **4**. Used for quantities, rates, base quantities and unit conversion factors.
- `InvalidAmount extends \InvalidArgumentException`.
- Percentages (VAT rate, discount %, commission %, depreciation %) are plain `string|BigDecimal` with at most
  2 decimals, validated at the edge.

Shared API (`static` = the same class):

| Method | Behaviour |
|---|---|
| `static of(self\|BigDecimal\|string\|int\|float $v)` | Strict. Strings: optional sign, digits, optional `.`; no commas, no exponent. Floats (JSON numbers arrive as floats) are converted with PHP's shortest round-trip form (`var_export($v, true)`), never `(string)`. Throws `InvalidAmount` if the value has more decimals than the scale (after trailing zeros), or is not a number. |
| `static ofNullable(mixed $v): ?static` | `null` or `''` gives `null`, otherwise `of()`. |
| `static round(self\|BigDecimal\|string\|int\|float $v)` | The only sanctioned way to round an external value: HalfUp to the scale. |
| `static zero()`, `static sum(iterable $values)` | Exact. |
| `static max(...$v)`, `static min(...$v)` | |
| `plus($o)`, `minus($o)` | Exact. `$o` goes through the same class's `of()`. |
| `negated()`, `abs()` | |
| `multipliedBy(Quantity\|Money\|BigDecimal\|string\|int $factor)` | Exact product, then ONE HalfUp rounding to this class's scale. `Money x Quantity` gives Money (2dp); `Quantity x Quantity` gives Quantity (4dp). |
| `multipliedByFraction($numerator, $denominator)` | `round(this x numerator / denominator)` with a single rounding. Denominator zero throws. |
| `compareTo($o): int`, `isEqualTo`, `isGreaterThan`, `isGreaterThanOrEqualTo`, `isLessThan`, `isLessThanOrEqualTo`, `isZero`, `isPositive`, `isNegative` | Exact. |
| `toBigDecimal(): BigDecimal` | At the scale. |
| `toString()`, `__toString()`, `jsonSerialize(): string` | Always exactly scale decimals, `.` separator, no grouping. Zero prints `0.00` / `0.0000`, never `-0.00`. |
| `toFloat(): float` | ONLY for chart data or an Excel numeric cell. Never for arithmetic or comparison. |

Money only:

| Method | Behaviour |
|---|---|
| `percent(BigDecimal\|string\|int $pct)` | `round(this x pct / 100)`, single rounding. |
| `allocate(array $weights): list<Money>` | Split this amount into parts proportional to non-negative weights (Money, Quantity, BigDecimal, string or int). Largest-remainder at 0.01: floor every share, hand the leftover paisa one by one to the largest fractional remainders, ties to the lowest index. Parts always sum exactly to this amount. Negative amount: allocate the absolute value, negate every part. All-zero or negative weights throw. |
| `format(): string` | Indian grouping for PDFs and exports: `12,34,567.50`, `-1,234.00`. |

Quantity only: `formatQuantity()` trims trailing zeros (`1.5`, `2`); `formatRate()` shows 2 to 4 decimals
(`12.50`, `12.3456`).

**Rounding mode everywhere: `RoundingMode::HalfUp`** (ties away from zero, symmetric for negative lines, the
same as MySQL's DECIMAL insert rounding).

## C2. Decimal Eloquent cast (implemented by T01)

`App\Casts\Decimal` implements `CastsAttributes`. Usage: `'total' => Decimal::class.':2'`,
`'quantity' => Decimal::class.':4'`, percent columns `Decimal::class.':2'`.

- `get`: `null`, or a **string** at exactly the scale (`"56.50"`, `"1.5000"`). This matches Laravel's
  `decimal:N` output, so existing readers keep working unchanged.
- `set`: accepts `null`, `Money`, `Quantity`, `BigDecimal`, `string`, `int`, `float` (float via the shortest
  round-trip form). Stores a string at the scale. **Throws `InvalidAmount`** when the value has more decimals
  than the scale: "Refusing to silently round {value} to {scale} decimals for attribute {key}". This is what
  guarantees MySQL never rounds a money value on insert.
- Each task switches the casts of the models it owns. Wrap reads as `Money::of($model->total)` before any
  arithmetic.

## C3. DocumentCalculator (implemented by T01, used by T04, T06, T07, mirrored by T02)

`App\Support\Billing\DocumentCalculator::calculate(array $lines, array $header): DocumentTotals`

Line input: `quantity`, `rate`, `discount` (default `0`), `discount_type` (`flat` or `percentage`, default
`flat`), `vatable` (bool), `conversion_factor` (default `1`). Header input: `vat_rate` (required, 0 to 100),
`discount` (default `0`), `discount_type` (default `flat`), `tds_amount` (default `0`), `force_non_taxable`
(default `false`, used for PAN invoices), `expected_total` (optional).

Algorithm (r2 = Money HalfUp to 2dp, r4 = Quantity HalfUp to 4dp, everything else exact):

1. Per line: `q = Quantity::of(quantity)` (may be negative; the caller decides whether negatives are allowed),
   `rate >= 0`, `factor > 0`. `base_quantity = r4(q x factor)`. `gross = r2(q x rate)`.
2. Line discount moves the line toward zero. Percentage (0 to 100): `amount = r2(|gross| x pct / 100)` with
   the sign of `gross`. Flat (`>= 0`, at most `|gross|`): `amount = flat` with the sign of `gross`.
   `line_total = gross - amount`.
3. `vatable = force_non_taxable ? false : line.vatable`. `vatable_subtotal` and `non_vatable_subtotal` are
   exact sums of `line_total`. `subtotal = vatable_subtotal + non_vatable_subtotal` must be `> 0`.
4. Header discount. Percentage (0 to 100): `header_discount = r2(subtotal x pct / 100)`. Flat: `0 <= d <=
   subtotal`. If it is `> 0`, both group subtotals must be `>= 0`. Then
   `[header_discount_vatable, header_discount_non_vatable] = header_discount.allocate([vatable_subtotal,
   non_vatable_subtotal])`.
5. `taxable_amount = vatable_subtotal - header_discount_vatable`, `nontaxable_amount = non_vatable_subtotal -
   header_discount_non_vatable`. Both must be `>= 0`.
6. `vat_rate = force_non_taxable ? 0 : vat_rate`. `vat_amount = taxable_amount.percent(vat_rate)`.
7. `total = taxable_amount + nontaxable_amount + vat_amount`; must be `> 0`.
8. `tds_amount`: `0 <= tds <= taxable_amount + nontaxable_amount`. `settlement_due = total - tds_amount`.
9. If `expected_total` is given and differs from `total`: throw with reason `total_mismatch`.

Errors: `App\Support\Billing\BillingException extends \InvalidArgumentException` with
`public readonly string $reason` from this fixed list (JS uses the same codes): `too_many_decimals`,
`invalid_number`, `negative_rate`, `invalid_conversion_factor`, `percentage_out_of_range`,
`negative_discount`, `line_discount_exceeds_line`, `subtotal_not_positive`,
`header_discount_exceeds_subtotal`, `negative_group_total`, `total_not_positive`, `tds_exceeds_base`,
`total_mismatch`. Messages are human-readable English for users.

Result: `App\Support\Billing\DocumentTotals` (readonly): `lines` (list of `LineTotals`: `quantity`, `rate`,
`conversionFactor`, `baseQuantity` Quantity; `gross`, `discountAmount`, `lineTotal` Money; `discountType`,
`discountValue` string; `vatable` bool), `vatableSubtotal`, `nonVatableSubtotal`, `headerDiscount`,
`headerDiscountVatable`, `headerDiscountNonVatable`, `taxableAmount`, `nontaxableAmount`, `vatAmount`,
`total`, `tdsAmount`, `settlementDue` (Money), `vatRate` (string). `toArray()` returns snake_case keys with
string values (the golden-vector `expected` shape).

Helper: `DocumentCalculator::assertExactSplit(Money $due, Money $cash, Money $bank): void`: both `>= 0` and
`cash + bank == due` exactly, else `BillingException` (`split_mismatch`, add it to the list).

### C3 addendum, settled at the Phase 1 gate (2026-09-12)

C3 left these open; T01 and T02 initially disagreed on the first one. Both engines now implement the rules
below and `tests/fixtures/billing-vectors.json` (43 vectors) pins every one of them. Do not re-decide these.

- A **negative** discount gives `negative_discount` for both `flat` and `percentage`, at line level and at
  header level. `percentage_out_of_range` is only for a percentage above 100, or for a VAT rate outside
  0 to 100 (a negative VAT rate is a range error, not a discount error).
- Missing `vat_rate`, or an unknown `discount_type` string, gives `invalid_number`.
- A TDS amount below zero, or above the base, gives `tds_exceeds_base`.
- In `toArray()`: `vat_rate` and a percentage `discount_value` render at 2 decimals (`"13.00"`), a flat
  `discount_value` renders at 2 decimals, and `lines[].vatable` stays a real boolean, not a string.
- Trailing zeros never count toward a scale: `"1.500"` is valid money, `"1.005"` is `too_many_decimals`.

Additive API beyond C1-C3 (present, safe to use): `App\Support\Money\DecimalValue` (abstract base, with
`DecimalValue::parse()`), `InvalidAmount::$reason`, `DocumentTotals::subtotal()`, and on the JS side a
`MoneyError` class carrying `.reason`. `parseMoney`, `parseQuantity` and `calculateDocument` still never
throw; they return `{ ok: false, reason }` as C8 requires.

## C4. Ledger core (implemented by T03, used by everyone)

- `VoucherType` gains `SalePan = 'sale_pan'` (own gapless series for PAN invoices) and
  `Reversal = 'reversal'` (every cancellation reversal; its own series, so no invoice/credit-note/receipt
  number is ever consumed by a cancellation).
- `JournalVoucher::post()` keeps its signature. New behaviour: every line amount is normalised with
  `Money::of()` (more than 2 decimals throws), zero lines are rejected, debit total must **exactly** equal
  credit total, and the voucher **date must lie inside the resolved fiscal year** (`start_date <= date <=
  end_date`), otherwise `InvalidArgumentException("The date {date} is outside fiscal year {name} ({start}
  to {end}).")`.
- `ClosedFiscalYearGuard::assertDateInOpenYear(string $date, ?User $actor = null, ?string $reason = null):
  FiscalYear` returns the year containing `$date` if it is open (or reopened for correction with admin +
  reason, following the existing guard), otherwise throws. Stock documents (T08) and any dated posting that
  does not go through `JournalVoucher::post()` call this.
- `JournalVoucher::reverse(JournalVoucher $original, User $actor, string $narration): JournalVoucher`:
  locks the original row, requires it not already reversed, requires the **original's fiscal year to be the
  currently open year** (else `InvalidArgumentException("This document belongs to closed fiscal year {name}.
  Record a return or credit note in the current year instead.")`), posts the mirrored lines as
  `VoucherType::Reversal` dated **today in `Asia/Kathmandu`**, sets the new voucher's `reversal_of_id`, marks
  the original voucher `status = 'cancelled'`, returns the reversal. Every module `cancel()` uses this instead
  of its own mirroring code.
- App timezone becomes `Asia/Kathmandu` (T03 edits `config/app.php`).
- `VoucherSequence::setStartingNumber(FiscalYear $fy, VoucherType $type, int $nextNumber): void` (admin
  setting): allowed only while no voucher of that type exists in that year; the next posted voucher gets
  `$nextNumber`.

## C5. Document lifecycle (every task that owns a cancellable document)

Cancellable tables: `sales`, `sales_returns`, `receipts`, `purchases`, `purchase_returns`, `payments`,
`capital_sales`, `capital_purchases` (stock documents already have their own columns, see T08).

- Add columns (each task, own migration): `cancelled_at` timestamp null, `cancelled_by` FK users null,
  `cancel_reason` text null, `reversal_journal_voucher_id` FK journal_vouchers null.
- `cancel(User $actor, string $reason)`: inside one `DB::transaction`, re-read the row with
  `lockForUpdate()`, re-check status and blockers, call `JournalVoucher::reverse()`, flag stock movements as
  today, fill the four columns, set `status = 'cancelled'`. Reason required, max 500 chars (validate).
- Cancel routes get `role:admin` middleware (the task owning the route file adds it).
- **Every state transition** (cancel, approve, reject, convert, allocate, return against) re-reads the rows it
  depends on with `lockForUpdate()` inside the transaction and re-checks the rule there. Lock several rows in
  ascending id order to avoid deadlocks.
- Request payloads that target the same parent twice (`lines.*.sale_line_id`, `allocations.*.sale_id`, etc.)
  get a `distinct` validation rule **and** the model aggregates per target before checking caps.

## C6. Returns (T05 sales side, T06 purchase side)

- Only `status = 'posted'` returns count for money, VAT, TDS and outstanding balances anywhere.
  `pending` returns reserve quantity (they block over-return) but carry no money effect.
- Stock movement quantity = return quantity x the original line's `unit_conversion_factor`.
- Amount rule (no lost paisa): for each component (line value after line and header discount, VAT share,
  TDS share) a return credits `component.multipliedByFraction(returnQty, lineQty)`, except the return that
  consumes the last remaining quantity of a line, which credits `component - everything already credited for
  that line`. A return that completes the whole document reverses the document's VAT exactly
  (`original VAT - VAT already reversed`).
- Default store = the original document's store. Return date must be `>=` the original document date and
  inside the open fiscal year.

## C7. Invoice numbers and snapshots (T03 numbering, T04/T05/T06 columns)

- `sales` gains `fiscal_year_id` (FK, backfilled from the voucher) and `invoice_number` (string 40), unique
  together. Set at posting as `{prefix}-{voucher_number}` (the format printed today, so existing bills
  reprint identically). Prefix by type: full `sale_full_prefix`, abbreviated `sale_abbreviated_prefix`, pan
  `sale_pan_prefix` (voucher type `SalePan`).
- `sales` gains buyer snapshot columns `buyer_name`, `buyer_pan`, `buyer_address` (nullable), filled at
  posting; PDFs and VAT books print the snapshot, falling back to the live customer for old rows.
- `sales_returns` gains `fiscal_year_id` + `credit_note_number`; `purchase_returns` gains `fiscal_year_id` +
  `debit_note_number`; same pattern. Pending or rejected return requests are never titled or numbered as
  credit notes ("Return request #{id}").
- Screens show the stored number; nothing re-derives a number from settings at display time.

## C8. Frontend money module (implemented by T02, used by T04-T08, T10-T14)

`resources/js/lib/money.js` (ES module, no dependencies, scaled `BigInt`):

- `parseMoney(input)`, `parseQuantity(input)`: accept strings or numbers, return `{ ok: true, value }` or
  `{ ok: false, reason }` (`reason` from the C3 list). Never through `Number()`.
- `calculateDocument(lines, header)`: same input keys and **step-for-step identical** algorithm to C3.
  Returns `{ ok: true, totals }` (totals as C3 `toArray()` strings) or `{ ok: false, reason, message }`.
- `addMoney`, `subtractMoney`, `sumMoney`, `compareMoney`, `moneyEquals`, `isZeroMoney`,
  `multiplyMoney(money, factor)`, `percentOf(money, pct)`, `allocateMoney(amount, weights)`: string in,
  string out.
- `formatMoney(v)` Indian grouping (`12,34,567.50`), `formatQuantity(v)`, `formatRate(v)` as in C1.
- `resources/js/lib/format.js`: `formatBsDate(isoDate)` using the existing `lib/nepali-calendar.js`,
  `todayInKathmandu()` returning `YYYY-MM-DD` for Asia/Kathmandu (replaces every `toISOString()` default).
- Golden vectors: `tests/fixtures/billing-vectors.json` (written by T01), consumed by
  `tests/Unit/Support/DocumentCalculatorTest.php` and `tests/js/money.test.mjs` (`node:test`, no deps).
  Shape: `[{ "name", "lines": [...], "header": {...}, "expected": { toArray() shape } }]` or
  `"expected_error": "<reason>"` instead of `expected`.
- Server check: every document form submits `expected_total` (the preview's `total` string). The server
  recomputes; any difference returns a 422 on `expected_total`: "The bill total changed. Please review it
  before saving."
- Posted documents always render stored server values, never a client recomputation.
- `components/ui/Input.vue` forwards `step`, `min`, `max`, `inputmode` and other attributes to the native
  `<input>` (T02).

## C9. Print compliance (implemented by T09, used by T04-T07)

- `App\Models\PrintLog::record(Model $document, User $user): int` stores a row and returns the copy number
  (`1` = original). Table `print_logs`: `printable_type`, `printable_id`, `copy_number`, `printed_by`,
  `printed_at`.
- `App\Support\AmountInWords::rupees(Money $amount): string`: Indian numbering ("Rupees One Lakh Twenty Three
  Thousand Four Hundred Fifty Six and Fifty Paisa Only").
- `App\Support\NepaliCalendar::formatBs(Carbon|string $adDate): string` returns `YYYY-MM-DD` (BS).
- Every document `print()` action passes to its view: `copyNumber` (from `PrintLog::record`), `dateBs`,
  `dateAd`, `fiscalYearName`, and for invoices and notes `amountInWords`. `pdf/layout.blade.php` renders
  "Copy of Original - {n-1}" when `copyNumber > 1`, BS date first with AD beside it, and the fiscal year.

## C10. Stock costing and stock reads (implemented by T08, used by T04-T07, T10, T11)

- `item_stock_movements` gains `value` (decimal 15,2, nullable): the exact, positive rupee value of a priced
  movement. Purchase: the line's net value after line and header discount, excluding VAT. Opening stock
  and priced adjustments-in: qty x rate. Purchase return: the credited net value. Unpriced movements: null.
- `Item::recordStockMovement(StockMovementType $type, Quantity|string $quantity, string $date, int $storeId,
  Model $reference, Quantity|string|null $unitCostRate = null, Money|string|null $value = null)`:
  `unit_cost_rate` is the net cost per **base** unit (`value / base quantity`, r4).
- `Item::currentStock(?int $storeId = null, ?string $asOf = null): Quantity` via SQL `SUM` on DECIMAL.
  `Item::currentStockByItem(array $itemIds, ?int $storeId = null): array<int, Quantity>` for batch display.
- `Item::lockForStockOut(array $itemIds): Collection`: locks the `items` rows (ascending id) with
  `lockForUpdate()`. Every posting that reduces stock calls it inside its transaction before reading stock.
- `App\Support\Inventory\StockCosting`:
  - `averageCost(Item $item, string $asOf): ?BigDecimal`: weighted average cost per base unit =
    (sum of `value` of priced in-movements up to `$asOf`, minus purchase-return values) / (the matching
    quantities). Transfers between stores are excluded. `null` when there is no basis; callers then fall back
    to `items.purchase_rate` (per base unit).
  - `closingValue(Item $item, string $asOf, ?int $storeId = null): Money`: `round(closing qty x average
    cost)` with one rounding.
  - `totalClosingValue(string $asOf, ?int $storeId = null): Money`: sum over stockable items.
  - `valuationRows(string $asOf, ?int $storeId = null, array $filters = []): Collection` of
    `item_id`, `quantity` (Quantity), `average_cost` (string, 4dp display), `value` (Money).
- Inventory stays **periodic** in the ledger: stock adjustments, transfers and conversions do not post
  journals. Closing stock enters the books at year-end (T11) and in the statements.

## C11. Flash of a newly created document (T04 implements, T06/T07 reuse)

Controllers redirect with `->with('created', ['type' => 'sale', 'id' => $id, 'print_url' => route(...)])`.
`HandleInertiaRequests` shares it as `flash.created`. Pages open `flash.created.print_url` after posting,
instead of guessing the newest id from a list.
