---
paths:
  - 'app/Support/Money/**,app/Casts/Decimal.php,resources/js/lib/money.js'
---

# Lib

## No float arithmetic on money, quantity, or rate
Money/quantity/rate values are exact-decimal (`App\Support\Money\Money`/`Quantity`, `App\Casts\Decimal`, `resources/js/lib/money.js`). Never use `(float)`, `round()`, `floatval()`, `number_format()` on a raw float, `.toFixed()`, `parseFloat()`, or `Number()` math on these values - use `Money::round()`/`Quantity::round()` server-side and the `money.js` helpers client-side. Exact comparisons only, no tolerances. A stray float artefact (e.g. from an old migration backfill) will make `Money::of()` throw at runtime. `toFloat()`/`Number()` is only legitimate at a true numeric boundary (Excel export cell, DOM attribute id) - never for a money/qty/rate computation.
