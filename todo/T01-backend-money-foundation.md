# T01 Backend money foundation

Phase 1 | Parallel with T02 | Implements contracts C1, C2, C3 | Audit refs: P0-1, P0-2 (foundation), P0-4,
P0-5, section 6 of `plans/gap-audit-2026-09-11.md`.

Everything in the system will be rebuilt on these classes. Make them small, exact, boring and exhaustively
tested. Read `vendor/brick/math/src/BigDecimal.php`, `BigNumber.php` and `RoundingMode.php` before writing.

## Owned files

New: `app/Support/Money/Money.php`, `app/Support/Money/Quantity.php`, `app/Support/Money/InvalidAmount.php`,
`app/Casts/Decimal.php`, `app/Support/Billing/DocumentCalculator.php`, `app/Support/Billing/DocumentTotals.php`,
`app/Support/Billing/LineTotals.php`, `app/Support/Billing/BillingException.php`,
`tests/fixtures/billing-vectors.json`, `tests/Unit/Support/MoneyTest.php`, `tests/Unit/Support/QuantityTest.php`,
`tests/Unit/Support/DecimalCastTest.php`, `tests/Unit/Support/DocumentCalculatorTest.php`.
Modify: `composer.json`, `composer.lock` (only via composer), `.github/workflows/ci.yml`.

## Tasks

- [ ] 1. `Money` and `Quantity` exactly per C1 (shared internals in a private trait or abstract base are fine).
  Float input via `var_export($v, true)`; reject `NAN`/`INF`. `toString()` never returns `-0.00`.
- [ ] 2. `Money::allocate()` per C1 (largest remainder, ties to lowest index, sum always exact, negative
  amounts, zero-weight error).
- [ ] 3. `App\Casts\Decimal` per C2, reading the scale from the cast parameter (`Decimal::class.':2'`).
  Include the attribute key in the exception message.
- [ ] 4. `DocumentCalculator`, `DocumentTotals`, `LineTotals`, `BillingException` exactly per C3, including
  `assertExactSplit()` and the `split_mismatch` reason. Pure PHP, no Laravel facades, no DB.
- [ ] 5. Golden vectors `tests/fixtures/billing-vectors.json`. Compute every `expected` value with an
  **independent** path (a throwaway `php -r` script using `bcmath` directly, not `DocumentCalculator`), then
  confirm the calculator agrees via a pure-PHP smoke check (`require 'vendor/autoload.php'` only). Include at
  least these cases (expected values in brackets, already hand-checked):
  1. 1.5 x 33.33 vatable, VAT 13 [gross 50.00, VAT 6.50, total 56.50]
  2. ten lines of 1.5 x 33.33 [total 565.00]
  3. 1 x 1001.50 vatable [VAT 130.20, total 1131.70]
  4. 2 x 10.00 and -1 x 4.50 vatable [subtotal 15.50, VAT 2.02, total 17.52]
  5. 28.5 x 14209.99 [gross 404984.72]
  6. 1632.5 x 78.13 [gross 127547.23]
  7. 1 x 4.10 with 15% line discount [discount 0.62, line total 3.48]
  8. 1 x 69350.00 with 6.27% line discount [discount 4348.25]
  9. VAT item 1000 + exempt item 500, flat header discount 100 [split 66.67 / 33.33, taxable 933.33,
     nontaxable 466.67, VAT 121.33, total 1521.33]
  10. same items, 10% header discount [150.00 split 100.00 / 50.00, VAT 117.00, total 1467.00]
  11. exempt-only 500 with flat header discount 50 [nontaxable 450.00, VAT 0.00, total 450.00]
  12. vatable 1000 with `force_non_taxable` [nontaxable 1000.00, VAT 0.00, total 1000.00]
  13. vatable 1000, TDS 15 [total 1130.00, settlement due 1115.00]
  14. 2 x 1200.00 with conversion factor 12 [base quantity 24.0000, gross 2400.00]
  15. two lines 0.125 x 1.00 [each gross 0.13, total before VAT 0.26]
  16. -2 x 100 with 10% line discount plus 1 x 500, vatable [line -180.00, subtotal 320.00, VAT 41.60,
      total 361.60]; the same with a flat 20 discount on the negative line gives the same result
  17. errors: flat line discount 20 on a 10.00 line (`line_discount_exceeds_line`); single line -1 x 10
      (`subtotal_not_positive`); quantity `0.00004` (`too_many_decimals`); 101% discount
      (`percentage_out_of_range`); flat header discount 2000 on 1500 (`header_discount_exceeds_subtotal`);
      TDS 2000 on base 1000 (`tds_exceeds_base`); vatable 1 x 10 and -1 x 20 plus exempt 100 with a header
      discount (`negative_group_total`); 100% header discount (`total_not_positive`); `expected_total`
      56.49 on case 1 (`total_mismatch`)
- [ ] 6. Unit tests (write, do not run): `MoneyTest` (parsing, strictness, float round-trip `0.1`, `1.5`,
  rejection of `0.30000000000000004`, every rounding proof from audit P0-1, allocate properties incl. sum
  invariant over many random splits, formatting `12,34,567.50`, `-0.00` never appears), `QuantityTest`,
  `DecimalCastTest` (get shape, set strictness, throws on `999.999` for scale 2), `DocumentCalculatorTest`
  (runs every golden vector; asserts `expected` or `expected_error` reason).
- [ ] 7. `composer require "brick/math:^0.18" --no-interaction` so the dependency is direct, not transitive.
  If it needs the network and fails, leave `composer.json` untouched and say so in the report.
- [ ] 8. `.github/workflows/ci.yml`: add a separate MySQL 8 job (`continue-on-error: true`) that runs the Pest
  suite with `DB_CONNECTION=mysql`, so DECIMAL rounding and locking behaviour get real signal without
  blocking CI. Also run `node --test tests/js` in the existing job. Do not change the existing job's steps
  otherwise.

## Acceptance

- No `float` arithmetic anywhere in the new classes; all math through `BigDecimal`.
- A pure-PHP smoke check prints every golden vector as matching (paste the summary line in the report).
- Public API matches C1-C3 names exactly; any deviation is listed in the report.
