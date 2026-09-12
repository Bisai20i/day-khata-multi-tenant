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
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function saleTestAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function saleTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function saleTestCashAccount(): Account
{
    return Account::where('code', 'AS1')->firstOrFail();
}

function saleTestSalesAccount(): Account
{
    return Account::where('code', 'INI20')->firstOrFail();
}

function saleTestVatPayableAccount(): Account
{
    return Account::where('code', 'LIA20')->firstOrFail();
}

function saleTestAccountNetBalance(int $accountId): float
{
    return (float) JournalVoucherLine::query()
        ->where('account_id', $accountId)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
        ->value('net');
}

test('a cash sale posts a balanced voucher, nets the customer account to zero, and records a stock movement', function () {
    $tenant = provisionSaleTestTenant('sale-cash.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        // This test sells more than is in stock (there's no prior purchase)
        // purely to exercise the stock-movement/currentStock() assertions
        // below - opt into the negative-stock policy rather than pre-
        // stocking the item, since going negative is the point here.
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect((float) $sale->taxable_amount)->toBe(200.0)
            ->and((float) $sale->vat_amount)->toBe(26.0)
            ->and((float) $sale->total)->toBe(226.0)
            ->and($sale->journalVoucher->voucher_type)->toBe(VoucherType::Sale);

        $lines = $sale->journalVoucher->lines;
        $totalDebit = (float) $lines->sum('debit');
        $totalCredit = (float) $lines->sum('credit');
        expect($totalDebit)->toBe($totalCredit);

        // Cash sale: customer is debited then immediately credited by the
        // settlement leg, so its net ledger balance is zero.
        expect(saleTestAccountNetBalance($customer->account_id))->toBe(0.0)
            ->and(saleTestAccountNetBalance(saleTestCashAccount()->id))->toBe(226.0)
            ->and(saleTestAccountNetBalance(saleTestSalesAccount()->id))->toBe(-200.0)
            ->and(saleTestAccountNetBalance(saleTestVatPayableAccount()->id))->toBe(-26.0);

        $movement = ItemStockMovement::where('item_id', $item->id)->firstOrFail();
        expect($movement->movement_type)->toBe(StockMovementType::Sale)
            ->and((float) $movement->quantity)->toBe(2.0)
            ->and($movement->cancelled)->toBeFalse()
            ->and($item->fresh()->currentStock()->toString())->toBe('-2.0000');
    });

    $tenant->delete();
});

test('a credit sale skips the settlement leg and leaves the total owed on the customer account', function () {
    $tenant = provisionSaleTestTenant('sale-credit.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        // Sold without any prior stock, purely to exercise credit-sale
        // ledger behavior below - the stock level isn't what this test is
        // about, so opt out of the negative-stock guard.
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect((float) $sale->total)->toBe(113.0)
            ->and(saleTestAccountNetBalance($customer->account_id))->toBe(113.0)
            ->and(saleTestAccountNetBalance(saleTestCashAccount()->id))->toBe(0.0);
    });

    $tenant->delete();
});

test('vat is only charged on vatable lines', function () {
    $tenant = provisionSaleTestTenant('sale-vat-mix.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $vatable = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $nonVatable = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [
                ['item_id' => $vatable->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0],
                ['item_id' => $nonVatable->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0],
            ],
            $admin,
        );

        expect((float) $sale->taxable_amount)->toBe(100.0)
            ->and((float) $sale->nontaxable_amount)->toBe(50.0)
            ->and((float) $sale->vat_amount)->toBe(13.0)
            ->and((float) $sale->total)->toBe(163.0);
    });

    $tenant->delete();
});

test('a partial payment whose cash and bank amounts do not add up to the settlement due is rejected', function () {
    $tenant = provisionSaleTestTenant('sale-partial-mismatch.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $bankAccount = Account::factory()->create();

        expect(fn () => Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'partial',
                'bank_account_id' => $bankAccount->id,
                'cash_amount' => 10,
                'bank_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('the TDS leg only appears when a TDS amount is set, and reduces the settlement due', function () {
    $tenant = provisionSaleTestTenant('sale-tds.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $tdsAccount = Account::factory()->create();

        $withoutTds = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        expect($withoutTds->journalVoucher->lines()->where('account_id', $tdsAccount->id)->count())->toBe(0);

        $withTds = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-02',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect((float) $withTds->total)->toBe(100.0);
        $tdsLine = $withTds->journalVoucher->lines()->where('account_id', $tdsAccount->id)->firstOrFail();
        expect((float) $tdsLine->debit)->toBe(10.0);

        // Cash received is total (100) minus TDS withheld (10) = 90.
        $cashLines = $withTds->journalVoucher->lines()->where('account_id', saleTestCashAccount()->id)->get();
        expect((float) $cashLines->sum('debit'))->toBe(90.0);
    });

    $tenant->delete();
});

test('cancelling a sale posts a Reversal voucher, flags stock movements cancelled, and rejects double-cancellation', function () {
    $tenant = provisionSaleTestTenant('sale-cancel.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        // Sold without any prior stock, purely to exercise the
        // cancellation/reversal assertions below.
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 3, 'rate' => 50, 'discount' => 0]],
            $admin,
        );

        expect($item->fresh()->currentStock()->toString())->toBe('-3.0000');

        $sale->cancel($admin, 'Recorded in error');

        // The reversal goes into its own Reversal series (CONTRACTS C4/C5), so
        // it never consumes an invoice number and leaves no gap in the printed
        // sale series (audit P0-15).
        $reversal = JournalVoucher::where('voucher_type', VoucherType::Reversal)->firstOrFail();
        // Exact, not `(float) sum()`: float summing is what accepted an
        // unbalanced voucher in the first place (audit P0-2).
        $reversalDebit = Money::sum($reversal->lines->map(fn ($line) => Money::of($line->debit)));
        $reversalCredit = Money::sum($reversal->lines->map(fn ($line) => Money::of($line->credit)));

        expect($reversalDebit->toString())->toBe($reversalCredit->toString())
            ->and(saleTestAccountNetBalance($customer->account_id))->toBe(0.0)
            ->and($sale->fresh()->status)->toBe('cancelled')
            ->and($sale->fresh()->reversal_journal_voucher_id)->toBe($reversal->id)
            ->and($sale->fresh()->cancel_reason)->toBe('Recorded in error')
            ->and($sale->fresh()->cancelled_by)->toBe($admin->id)
            ->and($sale->fresh()->cancelled_at)->not->toBeNull()
            ->and($item->fresh()->currentStock()->toString())->toBe('0.0000');

        expect(fn () => $sale->cancel($admin, 'Again'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a percentage header discount is computed against the document subtotal and persists its raw value and type', function () {
    $tenant = provisionSaleTestTenant('sale-header-discount-percentage.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'discount' => 20,
                'discount_type' => 'percentage',
            ],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // 20% of a 200 subtotal = 40 discount, all of it on the vatable
        // group since there is no exempt line to share it with.
        expect((float) $sale->taxable_amount)->toBe(160.0)
            ->and((float) $sale->discount)->toBe(20.0)
            ->and($sale->discount_type)->toBe('percentage')
            ->and($sale->discount_amount)->toBe('40.00');
    });

    $tenant->delete();
});

test('a percentage line discount is computed against that line\'s own base and persists its raw value and type', function () {
    $tenant = provisionSaleTestTenant('sale-line-discount-percentage.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 4, 'rate' => 50, 'discount' => 10, 'discount_type' => 'percentage']],
            $admin,
        );

        // qty 4 * rate 50 = 200 base, 10% off = 180 line total.
        $line = $sale->lines()->firstOrFail();
        expect((float) $line->line_total)->toBe(180.0)
            ->and((float) $line->discount)->toBe(10.0)
            ->and($line->discount_type)->toBe('percentage');
    });

    $tenant->delete();
});

test('a percentage discount over 100 is rejected', function () {
    $tenant = provisionSaleTestTenant('sale-discount-over-100.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        expect(fn () => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 150, 'discount_type' => 'percentage']],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a chalani number persists on the sale', function () {
    $tenant = provisionSaleTestTenant('sale-chalani-number.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'chalani_number' => 'CH-2026-042', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect($sale->fresh()->chalani_number)->toBe('CH-2026-042');
    });

    $tenant->delete();
});

test('a sale that would drive stock negative is rejected by default', function () {
    $tenant = provisionSaleTestTenant('sale-negative-stock-blocked.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true, 'name' => 'Short Stock Widget']);

        // Only 5 in stock (a real prior purchase, not a raw stock movement,
        // so this exercises Item::currentStock() the same way the app does).
        Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 50]],
            $admin,
        );

        expect(CompanySetting::current()->allow_negative_stock)->toBeFalse();

        expect(fn () => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'Short Stock Widget');

        // Stock is untouched - the rejected sale posted nothing at all.
        expect($item->fresh()->currentStock()->toString())->toBe('5.0000');
    });

    $tenant->delete();
});

test('a sale that would drive stock negative is allowed once allow_negative_stock is on', function () {
    $tenant = provisionSaleTestTenant('sale-negative-stock-allowed.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        CompanySetting::current()->update(['allow_negative_stock' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect($sale->exists)->toBeTrue()
            ->and($item->fresh()->currentStock()->toString())->toBe('-10.0000');
    });

    $tenant->delete();
});

test('a sale within available stock is unaffected by the negative-stock guard', function () {
    $tenant = provisionSaleTestTenant('sale-negative-stock-within-limit.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 50]],
            $admin,
        );

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 4, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect($sale->exists)->toBeTrue()
            ->and($item->fresh()->currentStock()->toString())->toBe('6.0000');
    });

    $tenant->delete();
});

test('a non-stockable item is never subject to the negative-stock guard', function () {
    $tenant = provisionSaleTestTenant('sale-negative-stock-non-stockable.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1000, 'rate' => 1, 'discount' => 0]],
            $admin,
        );

        expect($sale->exists)->toBeTrue();
    });

    $tenant->delete();
});
