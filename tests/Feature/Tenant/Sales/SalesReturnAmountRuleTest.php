<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\ItemUnit;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoucherSequence;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * CONTRACTS C6, the amount rule, and the two audit findings it closes:
 *
 * - P0-3: a partial return used to re-derive its value as
 *   `line_total / quantity x returnQty`, which rounds twice - a 3-unit line
 *   worth 100.00 returned one unit at a time credited 99.99 and the missing
 *   paisa stayed on the customer forever.
 * - P0-12: the stock movement used the as-entered quantity, so returning
 *   1 Box of an item sold in Boxes of 12 put 1 piece back instead of 12.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionReturnAmountTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function returnAmountAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function returnAmountOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create([
        'name' => 'FY1',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => FiscalYearStatus::Open,
    ]);
}

/** A date inside the open fiscal year that every test in this file can post on. */
function returnAmountDate(): string
{
    return now()->toDateString();
}

test('three returns of one third of a line credit exactly the line value and reverse its VAT exactly', function () {
    $tenant = provisionReturnAmountTenant('return-amount-thirds.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // 3 units at 33.3333... is not representable, so this is exactly the
        // shape that lost a paisa: 3 x 33.33 = 99.99 against a 100.00 line.
        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '3', 'rate' => '33.3333', 'discount' => '0']],
            $admin,
        );

        expect((string) $sale->taxable_amount)->toBe('100.00')
            ->and((string) $sale->vat_amount)->toBe('13.00');

        $saleLine = $sale->lines()->firstOrFail();
        $credited = Money::zero();
        $vatCredited = Money::zero();

        foreach (['1', '1', '1'] as $quantity) {
            $return = SalesReturn::post(
                ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
                [['sale_line_id' => $saleLine->id, 'quantity' => $quantity]],
                $admin,
            );

            $credited = $credited->plus(Money::of($return->taxable_amount));
            $vatCredited = $vatCredited->plus(Money::of($return->vat_amount));

            $voucher = $return->journalVoucher;
            expect(Money::sum($voucher->lines->pluck('debit'))->toString())
                ->toBe(Money::sum($voucher->lines->pluck('credit'))->toString());
        }

        // The last return takes "the component minus what is already
        // credited", so the three credits are 33.33 + 33.33 + 33.34.
        expect($credited->toString())->toBe('100.00')
            ->and($vatCredited->toString())->toBe('13.00');
    });

    $tenant->delete();
});

test('every way of splitting a line into returns credits exactly the line value and its VAT', function () {
    $tenant = provisionReturnAmountTenant('return-amount-property.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // Every composition of 6 units: 1+1+1+1+1+1, 1+2+3, 4+2, 6, ... Each
        // one has to land on exactly the same total as the invoice line.
        $compositions = [
            [6],
            [1, 5],
            [5, 1],
            [2, 4],
            [3, 3],
            [1, 1, 4],
            [1, 2, 3],
            [2, 2, 2],
            [1, 1, 1, 3],
            [1, 1, 1, 1, 1, 1],
        ];

        foreach ($compositions as $composition) {
            $sale = Sale::post(
                ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
                [['item_id' => $item->id, 'quantity' => '6', 'rate' => '16.6667', 'discount' => '0']],
                $admin,
            );
            $saleLine = $sale->lines()->firstOrFail();

            $net = Money::zero();
            $vat = Money::zero();

            foreach ($composition as $quantity) {
                $return = SalesReturn::post(
                    ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
                    [['sale_line_id' => $saleLine->id, 'quantity' => (string) $quantity]],
                    $admin,
                );

                $net = $net->plus(Money::of($return->taxable_amount));
                $vat = $vat->plus(Money::of($return->vat_amount));
            }

            expect($net->toString())->toBe((string) $sale->taxable_amount)
                ->and($vat->toString())->toBe((string) $sale->vat_amount);
        }
    });

    $tenant->delete();
});

test('a return spreads the header discount and TDS exactly, and completing the invoice reverses both in full', function () {
    $tenant = provisionReturnAmountTenant('return-amount-full-reversal.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $vatableItem = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $exemptItem = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $tdsAccount = Account::factory()->create();

        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => returnAmountDate(),
                'payment_mode' => 'credit',
                'discount' => '33.33',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => '11.11',
            ],
            [
                ['item_id' => $vatableItem->id, 'quantity' => '3', 'rate' => '33.3333', 'discount' => '0'],
                ['item_id' => $exemptItem->id, 'quantity' => '7', 'rate' => '9.99', 'discount' => '0'],
            ],
            $admin,
        );

        $lines = $sale->lines()->orderBy('id')->get();
        $taxable = Money::zero();
        $nontaxable = Money::zero();
        $vat = Money::zero();
        $tds = Money::zero();

        // Returned in awkward slices: the two lines finish on different
        // returns, so the last-remaining rule fires at different moments.
        $slices = [
            [[$lines[0]->id, '1'], [$lines[1]->id, '2']],
            [[$lines[0]->id, '1'], [$lines[1]->id, '4']],
            [[$lines[0]->id, '1'], [$lines[1]->id, '1']],
        ];

        foreach ($slices as $slice) {
            $return = SalesReturn::post(
                ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
                array_map(fn (array $line) => ['sale_line_id' => $line[0], 'quantity' => $line[1]], $slice),
                $admin,
            );

            $taxable = $taxable->plus(Money::of($return->taxable_amount));
            $nontaxable = $nontaxable->plus(Money::of($return->nontaxable_amount));
            $vat = $vat->plus(Money::of($return->vat_amount));
            $tds = $tds->plus(Money::of($return->tds_amount));
        }

        expect($taxable->toString())->toBe((string) $sale->taxable_amount)
            ->and($nontaxable->toString())->toBe((string) $sale->nontaxable_amount)
            ->and($vat->toString())->toBe((string) $sale->vat_amount)
            ->and($tds->toString())->toBe((string) $sale->tds_amount);
    });

    $tenant->delete();
});

test('returning one box of an item sold in boxes of twelve puts twelve base units back', function () {
    $tenant = provisionReturnAmountTenant('return-amount-conversion.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => '2', 'rate' => '1200', 'discount' => '0']],
            $admin,
        );

        // 2 Box of 12 left stock: 24 base units.
        expect(Quantity::of($item->fresh()->currentStock())->toString())->toBe('-24.0000');

        $saleLine = $sale->lines()->firstOrFail();

        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => 'One box damaged'],
            [['sale_line_id' => $saleLine->id, 'quantity' => '1']],
            $admin,
        );

        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('movement_type', StockMovementType::SaleReturn)
            ->firstOrFail();

        expect(Quantity::of($movement->quantity)->toString())->toBe('12.0000')
            ->and(Quantity::of($item->fresh()->currentStock())->toString())->toBe('-12.0000');
    });

    $tenant->delete();
});

test('a line the sale never moved stock for stays out of stock even after the item becomes stockable', function () {
    $tenant = provisionReturnAmountTenant('return-amount-stockability.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '10', 'discount' => '0']],
            $admin,
        );

        // Somebody switches the item to stockable after the bill was raised.
        $item->update(['is_stockable' => true]);

        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '2']],
            $admin,
        );

        expect(ItemStockMovement::where('item_id', $item->id)->count())->toBe(0);
    });

    $tenant->delete();
});

test('returning 0.1 and then 0.2 of a 0.3 line is accepted exactly', function () {
    $tenant = provisionReturnAmountTenant('return-amount-thirds-decimal.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '0.3', 'rate' => '1000', 'discount' => '0']],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => '0.1']],
            $admin,
        );

        // 0.3 - 0.1 as floats is 0.19999999999999998, which a tolerance-based
        // cap turned into "only 0.1999 returnable". Exact decimals accept the
        // remaining 0.2 (audit P0-4).
        $second = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => '0.2']],
            $admin,
        );

        expect((string) $second->total)->toBe('200.00');

        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => '0.0001']],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('naming the same sale line twice in one payload cannot return more than the line has', function () {
    $domain = 'return-amount-duplicate-line.tenant-test';
    $tenant = provisionReturnAmountTenant($domain);

    $saleId = null;
    $saleLineId = null;

    $tenant->run(function () use (&$saleId, &$saleLineId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        returnAmountOpenFiscalYear();
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '10', 'discount' => '0']],
            $admin,
        );

        $saleId = $sale->id;
        $saleLineId = $sale->lines()->firstOrFail()->id;

        // The model aggregates per sale line before checking the cap, so even
        // a caller that bypasses the request validation cannot claim 10 of a
        // 5-unit line (audit P0-14).
        expect(fn () => SalesReturn::post(
            ['sale_id' => $saleId, 'date' => returnAmountDate(), 'reason' => null],
            [
                ['sale_line_id' => $saleLineId, 'quantity' => '5'],
                ['sale_line_id' => $saleLineId, 'quantity' => '5'],
            ],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    test()->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    test()->post("http://{$domain}/sales-returns", [
        'sale_id' => $saleId,
        'date' => returnAmountDate(),
        'lines' => [
            ['sale_line_id' => $saleLineId, 'quantity' => 3],
            ['sale_line_id' => $saleLineId, 'quantity' => 3],
        ],
    ])->assertSessionHasErrors('lines.0.sale_line_id');

    $tenant->run(function () {
        expect(SalesReturn::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('a pending request reserves quantity, and rejecting it leaves the invoice untouched', function () {
    $tenant = provisionReturnAmountTenant('return-amount-pending-reserve.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '100', 'discount' => '0']],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();
        $outstandingBefore = Money::of($sale->outstandingAmount())->toString();

        $pending = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => 'Maybe faulty'],
            [['sale_line_id' => $saleLine->id, 'quantity' => '4']],
            $admin,
        );

        // Pending reserves the quantity...
        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => '2']],
            $admin,
        ))->toThrow(InvalidArgumentException::class);

        // ...but carries no money effect at all (audit P0-13).
        expect(Money::of($sale->fresh()->outstandingAmount())->toString())->toBe($outstandingBefore);

        $pending->reject('Customer kept the goods');

        expect(Money::of($sale->fresh()->outstandingAmount())->toString())->toBe($outstandingBefore);

        // Rejected frees the quantity again.
        $posted = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => '5']],
            $admin,
        );

        expect((string) $posted->taxable_amount)->toBe('500.00')
            ->and((string) $posted->vat_amount)->toBe('65.00');

        // A rejected request can never be approved afterwards.
        expect(fn () => $pending->fresh()->approve($admin))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a credit note posts a Reversal voucher and consumes no invoice or credit note number', function () {
    $tenant = provisionReturnAmountTenant('return-amount-cancel-series.tenant-test');

    $tenant->run(function () {
        $fiscalYear = returnAmountOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100', 'discount' => '0']],
            $admin,
        );

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => 'Damaged'],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '4']],
            $admin,
        );

        expect($return->credit_note_number)->toBe('SR-1')
            ->and($return->fiscal_year_id)->toBe($fiscalYear->id);

        $nextSaleNumber = VoucherSequence::nextNumberFor($fiscalYear, VoucherType::Sale);
        $nextReturnNumber = VoucherSequence::nextNumberFor($fiscalYear, VoucherType::SaleReturn);

        $return->cancel($admin, 'Entered in error');

        $cancelled = $return->fresh();

        expect($cancelled->status)->toBe('cancelled')
            ->and($cancelled->cancel_reason)->toBe('Entered in error')
            ->and($cancelled->cancelled_by)->toBe($admin->id)
            ->and($cancelled->cancelled_at)->not->toBeNull()
            ->and($cancelled->reversal_journal_voucher_id)->not->toBeNull()
            // The credit note keeps its own number: cancelling does not
            // renumber or release it.
            ->and($cancelled->credit_note_number)->toBe('SR-1');

        $reversal = JournalVoucher::findOrFail($cancelled->reversal_journal_voucher_id);

        expect($reversal->voucher_type)->toBe(VoucherType::Reversal)
            ->and($reversal->reversal_of_id)->toBe($return->journal_voucher_id)
            ->and(Money::sum($reversal->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($reversal->lines->pluck('credit'))->toString());

        // Neither customer-facing series moved (audit P0-15).
        expect(VoucherSequence::nextNumberFor($fiscalYear, VoucherType::Sale))->toBe($nextSaleNumber)
            ->and(VoucherSequence::nextNumberFor($fiscalYear, VoucherType::SaleReturn))->toBe($nextReturnNumber);

        // The stock that came back leaves again.
        expect(Quantity::of($item->fresh()->currentStock())->toString())->toBe('-10.0000');
    });

    $tenant->delete();
});

test('a pending request is never titled or numbered as a credit note', function () {
    $tenant = provisionReturnAmountTenant('return-amount-request-title.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '10', 'discount' => '0']],
            $admin,
        );

        $pending = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '1']],
            $admin,
        );

        expect($pending->credit_note_number)->toBeNull()
            ->and($pending->documentNumber())->toBe("Return request #{$pending->id}");

        $posted = $pending->approve($admin);

        expect($posted->credit_note_number)->toBe('SR-1')
            ->and($posted->documentNumber())->toBe('SR-1');
    });

    $tenant->delete();
});

test('a return dated before its invoice is rejected', function () {
    $tenant = provisionReturnAmountTenant('return-amount-date-guard.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '10', 'discount' => '0']],
            $admin,
        );

        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => now()->subDay()->toDateString(), 'reason' => null],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a refund can only be paid out of a cash or bank account', function () {
    $tenant = provisionReturnAmountTenant('return-amount-refund-account.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '10', 'discount' => '0']],
            $admin,
        );
        $saleLineId = $sale->lines()->firstOrFail()->id;

        // The customer's own receivable account is not somewhere money can be
        // refunded from.
        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null, 'refund_account_id' => $customer->account_id],
            [['sale_line_id' => $saleLineId, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class);

        $cash = Account::where('code', 'AS1')->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null, 'refund_account_id' => $cash->id],
            [['sale_line_id' => $saleLineId, 'quantity' => '1']],
            $admin,
        );

        expect($return->refund_journal_voucher_id)->not->toBeNull();
    });

    $tenant->delete();
});

test('a return defaults to the store its invoice went out of', function () {
    $tenant = provisionReturnAmountTenant('return-amount-store-default.tenant-test');

    $tenant->run(function () {
        returnAmountOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = returnAmountAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $branch = Store::factory()->create(['is_active' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => returnAmountDate(), 'payment_mode' => 'credit', 'store_id' => $branch->id],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '10', 'discount' => '0']],
            $admin,
        );

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => returnAmountDate(), 'reason' => null],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '2']],
            $admin,
        );

        // Not "the first active store": the goods go back where they left from.
        expect($return->store_id)->toBe($branch->id);

        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('movement_type', StockMovementType::SaleReturn)
            ->firstOrFail();

        expect($movement->store_id)->toBe($branch->id);
    });

    $tenant->delete();
});
