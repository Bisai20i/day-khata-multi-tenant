<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

/**
 * The billing behaviour T04 rebuilt on DocumentCalculator: exact totals,
 * PAN invoices without VAT, the stored invoice number and series, the
 * cancellation contract (C5) and Sale::outstandingAmount()'s formula.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleBillingTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function saleBillingAdmin(string $email = 'owner@example.com'): User
{
    return User::factory()->create([
        'email' => $email,
        'role_id' => Role::where('slug', 'admin')->value('id'),
    ]);
}

function saleBillingStaff(string $email = 'staff@example.com'): User
{
    return User::factory()->create([
        'email' => $email,
        'role_id' => Role::where('slug', 'staff')->value('id'),
    ]);
}

function saleBillingOpenYear(): FiscalYear
{
    return FiscalYear::create([
        'name' => 'FY1',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => FiscalYearStatus::Open,
    ]);
}

function loginSaleBillingUser(string $domain, string $email = 'owner@example.com'): void
{
    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

/**
 * The exact total of one side of a voucher. Never `(float) $lines->sum(...)`:
 * float summing is precisely what let an unbalanced voucher through in the
 * first place (audit P0-2), so a test must not use it to prove one balances.
 *
 * @param  Collection<int, JournalVoucherLine>  $lines
 */
function saleBillingSideTotal($lines, string $column): string
{
    return Money::sum($lines->map(fn ($line) => Money::of($line->{$column})))->toString();
}

test('a golden-vector bill stores exactly the vector amounts', function () {
    $tenant = provisionSaleBillingTenant('sale-vector-total.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // Golden vector "1.5 x 33.33 vatable at 13 percent VAT": the exact case
        // the audit used to prove the old float math stored 56.49 where the
        // printed bill said 56.50 (P0-1, P0-8).
        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1.5', 'rate' => '33.33']],
            $admin,
        );

        expect($sale->taxable_amount)->toBe('50.00')
            ->and($sale->nontaxable_amount)->toBe('0.00')
            ->and($sale->vat_amount)->toBe('6.50')
            ->and($sale->total)->toBe('56.50')
            ->and($sale->lines()->first()->line_total)->toBe('50.00');
    });

    $tenant->delete();
});

test('a flat header discount is split proportionally across the taxable and exempt subtotals', function () {
    $tenant = provisionSaleBillingTenant('sale-header-split.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $vatable = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $exempt = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // Golden vector "VAT 1000 plus exempt 500 with a flat header discount
        // of 100": 66.67 comes off the taxable side and 33.33 off the exempt
        // side, summing back to exactly 100 (C3 step 4).
        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'discount' => '100',
                'discount_type' => 'flat',
            ],
            [
                ['item_id' => $vatable->id, 'quantity' => '1', 'rate' => '1000.00'],
                ['item_id' => $exempt->id, 'quantity' => '1', 'rate' => '500.00'],
            ],
            $admin,
        );

        expect($sale->taxable_amount)->toBe('933.33')
            ->and($sale->nontaxable_amount)->toBe('466.67')
            ->and($sale->vat_amount)->toBe('121.33')
            ->and($sale->total)->toBe('1521.33')
            ->and($sale->discount_amount)->toBe('100.00');
    });

    $tenant->delete();
});

test('an exempt-only bill with a flat header discount posts a balanced voucher', function () {
    $tenant = provisionSaleBillingTenant('sale-exempt-discount.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $exempt = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // This used to fail outright with "Total debit must equal total credit"
        // because the header discount was only ever taken off the vatable
        // subtotal, which was zero here (audit P1 "Ledger and balances").
        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'discount' => '50',
                'discount_type' => 'flat',
            ],
            [['item_id' => $exempt->id, 'quantity' => '1', 'rate' => '200.00']],
            $admin,
        );

        $lines = $sale->journalVoucher->lines;

        expect($sale->taxable_amount)->toBe('0.00')
            ->and($sale->nontaxable_amount)->toBe('150.00')
            ->and($sale->vat_amount)->toBe('0.00')
            ->and($sale->total)->toBe('150.00')
            ->and(saleBillingSideTotal($lines, 'debit'))->toBe(saleBillingSideTotal($lines, 'credit'));
    });

    $tenant->delete();
});

test('a PAN invoice charges no VAT and is numbered in its own SalePan series', function () {
    $tenant = provisionSaleBillingTenant('sale-pan-series.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // Audit P0-10: a PAN bill used to charge a hidden 13% that the PDF then
        // hid from the customer while it still posted to LIA20.
        $pan = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'pan', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '1000.00']],
            $admin,
        );

        $vatPayableId = Account::where('code', 'LIA20')->value('id');

        expect($pan->vat_amount)->toBe('0.00')
            ->and($pan->vat_rate)->toBe('0.00')
            ->and($pan->taxable_amount)->toBe('0.00')
            ->and($pan->nontaxable_amount)->toBe('1000.00')
            ->and($pan->total)->toBe('1000.00')
            ->and($pan->journalVoucher->voucher_type)->toBe(VoucherType::SalePan)
            ->and($pan->invoice_number)->toBe('SLP-1')
            ->and($pan->journalVoucher->lines()->where('account_id', $vatPayableId)->count())->toBe(0)
            ->and($pan->lines()->first()->vatable)->toBeFalse();

        // The full-invoice series is untouched by the PAN bill: both start at 1.
        $full = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '1000.00']],
            $admin,
        );

        expect($full->invoice_number)->toBe('SL-1');
    });

    $tenant->delete();
});

test('an invoice type switched off in settings is rejected', function () {
    $tenant = provisionSaleBillingTenant('sale-type-disabled.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        CompanySetting::current()->update(['sale_pan_enabled' => false]);
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        expect(fn () => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'pan', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'This invoice type is turned off in Settings.');
    });

    $tenant->delete();
});

test('an abbreviated invoice above Rs 10,000 is rejected but exactly Rs 10,000 is allowed', function () {
    $tenant = provisionSaleBillingTenant('sale-abbreviated-cap.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $exempt = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $atTheCap = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'abbreviated', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $exempt->id, 'quantity' => '1', 'rate' => '10000.00']],
            $admin,
        );

        expect($atTheCap->total)->toBe('10000.00');

        expect(fn () => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'abbreviated', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $exempt->id, 'quantity' => '1', 'rate' => '10000.01']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'Use a full tax invoice above Rs 10,000.');
    });

    $tenant->delete();
});

test('the VAT rate always comes from company settings, never from the request', function () {
    $tenant = provisionSaleBillingTenant('sale-vat-rate-source.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        CompanySetting::current()->update(['default_vat_rate' => '13.00']);
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                // Deliberately bogus: the browser has no say in the rate.
                'vat_rate' => '0',
            ],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        expect($sale->vat_rate)->toBe('13.00')->and($sale->vat_amount)->toBe('13.00');
    });

    $tenant->delete();
});

test('the stored invoice number does not change when the prefix setting changes', function () {
    $tenant = provisionSaleBillingTenant('sale-invoice-number-frozen.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        expect($sale->invoice_number)->toBe('SL-1');

        CompanySetting::current()->update(['sale_full_prefix' => 'INV']);

        // An already-issued invoice keeps its number forever (C7); only the
        // next one issued uses the new prefix.
        expect($sale->fresh()->invoice_number)->toBe('SL-1');

        $next = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        expect($next->invoice_number)->toBe('INV-2');
    });

    $tenant->delete();
});

test('cancelling a sale leaves no gap in the invoice series and refuses a second cancellation', function () {
    $tenant = provisionSaleBillingTenant('sale-cancel-no-gap.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $first = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        $first->cancel($admin, 'Recorded in error');

        $second = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        // Audit P0-15: the reversal used to be posted as a SaleReturn voucher,
        // which consumed the next number in a printed series.
        expect($first->fresh()->invoice_number)->toBe('SL-1')
            ->and($second->invoice_number)->toBe('SL-2')
            ->and(JournalVoucher::where('voucher_type', VoucherType::Reversal)->count())->toBe(1);

        expect(fn () => $first->fresh()->cancel($admin, 'Again'))
            ->toThrow(InvalidArgumentException::class, 'This sale has already been cancelled.');
    });

    $tenant->delete();
});

test('a cancellation reason is required and capped at 500 characters', function () {
    $tenant = provisionSaleBillingTenant('sale-cancel-reason.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        expect(fn () => $sale->cancel($admin, '   '))->toThrow(InvalidArgumentException::class);
        expect(fn () => $sale->cancel($admin, str_repeat('x', 501)))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a sale belonging to a closed fiscal year cannot be cancelled', function () {
    $tenant = provisionSaleBillingTenant('sale-cancel-closed-year.tenant-test');

    $tenant->run(function () {
        $year = saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        // The filed year is closed and a new one opened: cancelling now would
        // rewrite a period that has already been reported (C4/C5).
        $year->update(['status' => FiscalYearStatus::Closed]);
        FiscalYear::create([
            'name' => 'FY2',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        expect(fn () => $sale->fresh()->cancel($admin, 'Too late'))->toThrow(InvalidArgumentException::class);
        expect(Sale::find($sale->id)->status)->toBe('posted');
    });

    $tenant->delete();
});

test('outstanding on a credit sale accounts for TDS, posted returns, refunds and receipts', function () {
    $tenant = provisionSaleBillingTenant('sale-outstanding.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $tdsAccount = Account::factory()->create();

        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => '100',
            ],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100.00']],
            $admin,
        );

        // Audit P1: TDS used to be ignored here, so a credit invoice with TDS
        // could never be settled - the buyer only ever pays total less TDS.
        expect($sale->total)->toBe('1000.00')
            ->and($sale->outstandingAmount()->toString())->toBe('900.00');

        $saleLine = $sale->lines()->firstOrFail();

        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02'],
            [['sale_line_id' => $saleLine->id, 'quantity' => '2']],
            $admin,
        );

        // 200 returned, carrying a 20 share of the invoice's TDS with it, so
        // the customer is credited 180.
        expect($sale->fresh()->outstandingAmount()->toString())->toBe('720.00');

        Receipt::post(
            [
                'customer_id' => $customer->id,
                'date' => '2026-06-03',
                'payment_mode' => 'cash',
                'amount' => '720.00',
                'allocations' => [['sale_id' => $sale->id, 'amount' => '720.00']],
            ],
            $admin,
        );

        expect($sale->fresh()->outstandingAmount()->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('a cash sale is fully settled at posting, so nothing is outstanding', function () {
    $tenant = provisionSaleBillingTenant('sale-outstanding-cash.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        expect($sale->outstandingAmount()->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('a refunded return leaves the invoice still owed in full', function () {
    $tenant = provisionSaleBillingTenant('sale-outstanding-refund.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100.00']],
            $admin,
        );

        // Cash In Hand: a Current Assets account, which is what a refund may
        // be paid out of.
        $refundAccount = Account::where('code', 'AS1')->firstOrFail();

        SalesReturn::post(
            [
                'sale_id' => $sale->id,
                'date' => '2026-06-02',
                'refund_account_id' => $refundAccount->id,
            ],
            [['sale_line_id' => $sale->lines()->value('id'), 'quantity' => '2']],
            $admin,
        );

        // The 200 credit note is cancelled out by the 200 actually handed back
        // over the counter, so the invoice itself is still owed in full: the
        // customer has their money for the returned goods, not a reduced bill.
        expect($sale->fresh()->outstandingAmount()->toString())->toBe('1000.00');
    });

    $tenant->delete();
});

test('a rejected return request has no effect on what is outstanding', function () {
    $tenant = provisionSaleBillingTenant('sale-outstanding-rejected.tenant-test');

    $tenant->run(function () {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100.00']],
            $admin,
        );

        $request = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-02'],
            [['sale_line_id' => $sale->lines()->value('id'), 'quantity' => '2']],
            $admin,
        );

        // Audit P0-13: a pending or rejected request used to permanently lower
        // the invoice's outstanding balance even though it booked nothing.
        expect($sale->fresh()->outstandingAmount()->toString())->toBe('1000.00');

        $request->reject('Goods were fine');

        expect($sale->fresh()->outstandingAmount()->toString())->toBe('1000.00');
    });

    $tenant->delete();
});

test('posting a sale flashes the created document so the page can print it', function () {
    $domain = 'sale-created-flash.tenant-test';
    $tenant = provisionSaleBillingTenant($domain);

    $itemId = null;
    $customerId = null;

    $tenant->run(function () use (&$itemId, &$customerId) {
        saleBillingOpenYear();
        saleBillingAdmin();
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id;
    });

    loginSaleBillingUser($domain);

    $response = $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'expected_total' => '100.00',
        'lines' => [['item_id' => $itemId, 'quantity' => '1', 'rate' => '100']],
    ]);

    $response->assertRedirect("http://{$domain}/sales");

    $created = session('created');

    expect($created['type'])->toBe('sale')
        ->and($created['id'])->toBeInt()
        ->and($created['print_url'])->toContain("/sales/{$created['id']}/print")
        ->and($created['receipt']['invoice_number'])->toBe('SL-1')
        ->and($created['receipt']['total'])->toBe('100.00');

    $tenant->delete();
});

test('a bill total that differs from the previewed total is refused', function () {
    $domain = 'sale-expected-total.tenant-test';
    $tenant = provisionSaleBillingTenant($domain);

    $itemId = null;
    $customerId = null;

    $tenant->run(function () use (&$itemId, &$customerId) {
        saleBillingOpenYear();
        saleBillingAdmin();
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id;
    });

    loginSaleBillingUser($domain);

    // C8: the browser sends the total it showed; anything else is refused
    // rather than quietly booking a different amount.
    $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'expected_total' => '99.00',
        'lines' => [['item_id' => $itemId, 'quantity' => '1', 'rate' => '100']],
    ])->assertSessionHasErrors('expected_total');

    $tenant->run(function () {
        expect(Sale::count())->toBe(0);
    });

    $tenant->delete();
});

test('a quantity with more than four decimals is rejected by the store route', function () {
    $domain = 'sale-too-many-decimals.tenant-test';
    $tenant = provisionSaleBillingTenant($domain);

    $itemId = null;
    $customerId = null;

    $tenant->run(function () use (&$itemId, &$customerId) {
        saleBillingOpenYear();
        saleBillingAdmin();
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id;
    });

    loginSaleBillingUser($domain);

    // Audit P0-5: 0.00004 x 1,000,000 charged Rs 40 and stored a quantity of
    // 0.0000 with no stock movement at all.
    $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'lines' => [['item_id' => $itemId, 'quantity' => '0.00004', 'rate' => '1000000']],
    ])->assertSessionHasErrors('lines.0.quantity');

    $tenant->delete();
});

test('a blank rate is a validation error, never a silent zero', function () {
    $domain = 'sale-blank-rate.tenant-test';
    $tenant = provisionSaleBillingTenant($domain);

    $itemId = null;
    $customerId = null;

    $tenant->run(function () use (&$itemId, &$customerId) {
        saleBillingOpenYear();
        saleBillingAdmin();
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id;
    });

    loginSaleBillingUser($domain);

    $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'lines' => [['item_id' => $itemId, 'quantity' => '1', 'rate' => '']],
    ])->assertSessionHasErrors('lines.0.rate');

    $tenant->delete();
});

test('a bank account that is a customer ledger is rejected', function () {
    $domain = 'sale-bank-account-filter.tenant-test';
    $tenant = provisionSaleBillingTenant($domain);

    $itemId = null;
    $customerId = null;
    $partyAccountId = null;

    $tenant->run(function () use (&$itemId, &$customerId, &$partyAccountId) {
        saleBillingOpenYear();
        saleBillingAdmin();
        $customer = Customer::factory()->create();
        $customerId = $customer->id;
        $partyAccountId = $customer->account_id;
        $itemId = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id;
    });

    loginSaleBillingUser($domain);

    $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'bank',
        'bank_account_id' => $partyAccountId,
        'lines' => [['item_id' => $itemId, 'quantity' => '1', 'rate' => '100']],
    ])->assertSessionHasErrors('bank_account_id');

    $tenant->delete();
});

test('only an admin may cancel a sale', function () {
    $domain = 'sale-cancel-role.tenant-test';
    $tenant = provisionSaleBillingTenant($domain);

    $saleId = null;

    $tenant->run(function () use (&$saleId) {
        saleBillingOpenYear();
        $admin = saleBillingAdmin();
        saleBillingStaff();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $saleId = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        )->id;
    });

    loginSaleBillingUser($domain, 'staff@example.com');

    $this->post("http://{$domain}/sales/{$saleId}/cancel", ['reason' => 'Nope'])->assertForbidden();

    $tenant->run(function () use ($saleId) {
        expect(Sale::find($saleId)->status)->toBe('posted');
    });

    $tenant->delete();
});
