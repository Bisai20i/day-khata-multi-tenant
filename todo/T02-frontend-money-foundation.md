# T02 Frontend money foundation

Phase 1 | Parallel with T01 | Implements contract C8 and the Input.vue fix | Audit refs: P0-7, P0-8
(foundation), frontend findings in `plans/gap-audit-2026-09-11.md`.

T01 writes `tests/fixtures/billing-vectors.json` in parallel; code your JS test against the C8 fixture shape
and read the file at test time. Mirror C3 **step for step**; the two implementations must never disagree.

## Owned files

New: `resources/js/lib/money.js`, `resources/js/lib/format.js`, `tests/js/money.test.mjs`,
`tests/js/format.test.mjs`.
Modify: `resources/js/components/ui/Input.vue`.

## Tasks

- [x] 1. `money.js` per C8 using scaled `BigInt` (money = paisa, quantity = 1/10000). Parsing never goes
  through `Number()`; accept strings with optional sign and `.`, accept JS numbers only via
  `String(number)` when it has no exponent. HalfUp = ties away from zero (matches brick/math and PHP).
  Multiplication 4dp x 4dp keeps full precision before the single rounding.
- [x] 2. `calculateDocument(lines, header)` identical to C3, same error `reason` codes, same key names in the
  returned totals (`gross`, `discount_amount`, `line_total`, `base_quantity`, `vatable_subtotal`, ...
  `settlement_due`).
- [x] 3. `allocateMoney`, `percentOf`, `multiplyMoney`, add/subtract/sum/compare/equals helpers,
  `formatMoney` (Indian grouping), `formatQuantity`, `formatRate` per C1 formatting rules.
- [x] 4. `format.js`: `formatBsDate(isoDate)` built on the existing `resources/js/lib/nepali-calendar.js`
  (read it first), `todayInKathmandu()` returning `YYYY-MM-DD` using `Intl.DateTimeFormat` with
  `timeZone: 'Asia/Kathmandu'` (no UTC `toISOString()`).
- [x] 5. `Input.vue`: set `inheritAttrs: false` (via `defineOptions`) and bind `$attrs` onto the native
  `<input>` so `step`, `min`, `max`, `inputmode`, `name`, `autocomplete`, `aria-*` and listeners reach it,
  while keeping the existing `class` prop merging on the input and the icon wrapper intact. Check every
  current usage (`grep -rn "<Input" resources/js`) still renders the same. This single change unblocks
  decimal entry app-wide (audit P0-7).
- [x] 6. `tests/js/money.test.mjs` (`node:test` + `node:assert`, no packages): run every golden vector from
  `tests/fixtures/billing-vectors.json`, plus the JS-specific traps: `(1.005).toFixed(2)`-style inputs,
  `0.1 + 0.2` style sums, negative ties (`-0.125` to `-0.13`), `-0.00` never produced, Indian grouping,
  4dp x 4dp at `DECIMAL(15,4)` extremes (beyond 2^53). `tests/js/format.test.mjs` for the date helpers.
  Write them, do not run them.

## Acceptance

- No `Number()`, `parseFloat`, `toFixed` or `Math.round` on money/quantity inside `money.js`.
- `node -e` smoke check (pure, importing `money.js`) shows case 1 of the vectors gives `56.50`; paste it.
- Exported names match C8 exactly; any deviation listed in the report.
