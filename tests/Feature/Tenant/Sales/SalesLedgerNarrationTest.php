<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\SettlementNarration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionNarrationTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function narrationTestAdmin(): User
{
    return User::factory()->create();
}

function narrationTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('a cash sale gives every voucher line the "{invoice} - Cash Settlement" narration', function () {
    $tenant = provisionNarrationTestTenant('narration-sale-cash.tenant-test');

    $tenant->run(function () {
        narrationTestOpenFiscalYear();
        $admin = narrationTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $expected = SettlementNarration::line($sale->invoice_number, 'cash');

        expect($sale->invoice_number)->not->toBeNull();

        foreach ($sale->journalVoucher->lines as $line) {
            expect($line->narration)->toBe($expected)
                ->and($line->narration)->toContain('Cash Settlement');
        }
    });

    $tenant->delete();
});

test('a bank sale narration reads "Bank Settlement" and a credit sale reads "Credit"', function () {
    $tenant = provisionNarrationTestTenant('narration-sale-bank-credit.tenant-test');

    $tenant->run(function () {
        narrationTestOpenFiscalYear();
        $admin = narrationTestAdmin();
        $customer = Customer::factory()->create();
        $bankAccount = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $bankSale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'bank', 'bank_account_id' => $bankAccount->id],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        foreach ($bankSale->journalVoucher->lines as $line) {
            expect($line->narration)->toBe(SettlementNarration::line($bankSale->invoice_number, 'bank'));
        }

        $creditSale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        foreach ($creditSale->journalVoucher->lines as $line) {
            expect($line->narration)->toBe(SettlementNarration::line($creditSale->invoice_number, null))
                ->and($line->narration)->toContain('Credit');
        }
    });

    $tenant->delete();
});

test('a linked sales return credit note narration reads "{credit note number} - Credit"', function () {
    $tenant = provisionNarrationTestTenant('narration-sales-return.tenant-test');

    $tenant->run(function () {
        narrationTestOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = narrationTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 2]],
            $admin,
        );

        $expected = SettlementNarration::line($return->documentNumber(), null);

        foreach ($return->journalVoucher->lines as $line) {
            expect($line->narration)->toBe($expected)
                ->and($line->narration)->toContain('Credit');
        }
    });

    $tenant->delete();
});

test('a receipt voucher line narration carries the voucher number and settlement mode', function () {
    $tenant = provisionNarrationTestTenant('narration-receipt.tenant-test');

    $tenant->run(function () {
        narrationTestOpenFiscalYear();
        $admin = narrationTestAdmin();
        $customer = Customer::factory()->create();

        $receipt = Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
        ], $admin);

        $voucher = $receipt->journalVoucher;
        $expected = SettlementNarration::line($voucher->voucher_number, 'cash');

        foreach ($voucher->lines as $line) {
            expect($line->narration)->toBe($expected)
                ->and($line->narration)->toContain('Cash Settlement');
        }
    });

    $tenant->delete();
});

test('a service item with its own posting account credits that account instead of INI20, and VAT still applies', function () {
    $tenant = provisionNarrationTestTenant('narration-service-revenue.tenant-test');

    $tenant->run(function () {
        narrationTestOpenFiscalYear();
        $admin = narrationTestAdmin();
        $customer = Customer::factory()->create();

        $serviceAccount = Account::factory()->create(['name' => 'Repair Service Income']);
        $serviceItem = Item::factory()->create([
            'is_vatable' => true,
            'is_stockable' => false,
            'account_id' => $serviceAccount->id,
        ]);
        $merchandiseItem = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [
                ['item_id' => $serviceItem->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0],
                ['item_id' => $merchandiseItem->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0],
            ],
            $admin,
        );

        $salesAccountId = Account::where('code', 'INI20')->firstOrFail()->id;
        $vatPayableId = Account::where('code', 'LIA20')->firstOrFail()->id;

        $lines = $sale->journalVoucher->lines->keyBy('account_id');

        expect(Money::of($lines[$serviceAccount->id]->credit)->toString())->toBe('1000.00')
            ->and(Money::of($lines[$salesAccountId]->credit)->toString())->toBe('500.00')
            ->and((string) $sale->vat_amount)->toBe('195.00')
            ->and(Money::of($lines[$vatPayableId]->credit)->toString())->toBe('195.00');

        expect(Money::sum($sale->journalVoucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($sale->journalVoucher->lines->pluck('credit'))->toString());
    });

    $tenant->delete();
});
