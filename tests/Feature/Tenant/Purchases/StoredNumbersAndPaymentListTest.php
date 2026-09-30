<?php

use App\Enums\FiscalYearStatus;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Flags G-18 (purchases and payments keep the number they were posted with)
 * and G-17 (the payments page paginates and prices open bills for one
 * supplier in a fixed number of queries).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionStoredNumbersTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function storedNumbersAdmin(): User
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function storedNumbersCreditPurchase(User $admin, Supplier $supplier, string $rate = '100'): Purchase
{
    return Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        [['item_id' => Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id, 'quantity' => 1, 'rate' => $rate, 'discount' => 0]],
        $admin,
    );
}

test('a purchase and a payment store their number at posting, and a later prefix change does not renumber a printed bill', function () {
    $domain = 'stored-numbers.tenant-test';
    $tenant = provisionStoredNumbersTenant($domain);

    $purchaseId = null;
    $expected = null;
    $tenant->run(function () use (&$purchaseId, &$expected) {
        $admin = storedNumbersAdmin();
        CompanySetting::current()->update(['purchase_prefix' => 'PB']);
        $supplier = Supplier::factory()->create();

        $purchase = storedNumbersCreditPurchase($admin, $supplier);
        $expected = "PB-{$purchase->journalVoucher->voucher_number}";
        $purchaseId = $purchase->id;

        $payment = Payment::post(['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'amount' => '40', 'payment_mode' => 'cash'], $admin);

        expect($purchase->purchase_number)->toBe($expected)
            ->and($payment->payment_number)->toBe("PMT-{$payment->journalVoucher->voucher_number}")
            ->and($payment->fiscal_year_id)->toBe($payment->journalVoucher->fiscal_year_id);

        CompanySetting::current()->update(['purchase_prefix' => 'NEW']);
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $fakePdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    $fakePdf->shouldReceive('stream')->once()->andReturn(response('pdf', 200, ['Content-Type' => 'application/pdf']));
    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(fn (string $view, array $data) => $view === 'pdf.purchase' && $data['documentNumber'] === $expected)
        ->andReturn($fakePdf);

    $this->get("http://{$domain}/purchases/{$purchaseId}/print")->assertOk();

    $tenant->delete();
});

test('the migration backfills numbers for rows posted before it, and leaves filled rows alone', function () {
    $tenant = provisionStoredNumbersTenant('stored-numbers-backfill.tenant-test');

    $tenant->run(function () {
        $admin = storedNumbersAdmin();
        $supplier = Supplier::factory()->create();
        $purchase = storedNumbersCreditPurchase($admin, $supplier);
        $payment = Payment::post(['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'amount' => '40', 'payment_mode' => 'cash'], $admin);
        $untouched = storedNumbersCreditPurchase($admin, $supplier);

        // As if posted before the columns existed.
        DB::table('purchases')->where('id', $purchase->id)->update(['purchase_number' => null]);
        DB::table('payments')->where('id', $payment->id)->update(['payment_number' => null, 'fiscal_year_id' => null]);
        DB::table('purchases')->where('id', $untouched->id)->update(['purchase_number' => 'KEEP-ME']);

        $migration = require database_path('migrations/tenant/2026_09_29_190000_add_stored_numbers_to_purchases_and_payments.php');
        (fn () => $this->backfill())->call($migration);

        $prefix = CompanySetting::current()->purchase_prefix;

        expect(Purchase::find($purchase->id)->purchase_number)->toBe("{$prefix}-{$purchase->journalVoucher->voucher_number}")
            ->and(Purchase::find($untouched->id)->purchase_number)->toBe('KEEP-ME')
            ->and(Payment::find($payment->id)->payment_number)->toBe("PMT-{$payment->journalVoucher->voucher_number}")
            ->and(Payment::find($payment->id)->fiscal_year_id)->toBe($payment->journalVoucher->fiscal_year_id);
    });

    $tenant->delete();
});

test('the payments page paginates, and prices open bills only for the chosen supplier in a fixed number of queries', function () {
    $domain = 'payments-list-performance.tenant-test';
    $tenant = provisionStoredNumbersTenant($domain);

    $oneBillSupplierId = null;
    $manyBillSupplierId = null;
    $tenant->run(function () use (&$oneBillSupplierId, &$manyBillSupplierId) {
        $admin = storedNumbersAdmin();
        $oneBill = Supplier::factory()->create();
        $manyBills = Supplier::factory()->create();
        $oneBillSupplierId = $oneBill->id;
        $manyBillSupplierId = $manyBills->id;

        storedNumbersCreditPurchase($admin, $oneBill);

        foreach (range(1, 6) as $index) {
            $purchase = storedNumbersCreditPurchase($admin, $manyBills, '200');
            Payment::post([
                'supplier_id' => $manyBills->id, 'date' => '2026-06-02', 'amount' => '50', 'payment_mode' => 'cash',
                'allocations' => [['purchase_id' => $purchase->id, 'amount' => '50']],
            ], $admin);
        }

        foreach (range(1, 25) as $index) {
            Payment::post(['supplier_id' => $oneBill->id, 'date' => '2026-06-03', 'amount' => '1', 'payment_mode' => 'cash'], $admin);
        }
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    // 31 payments: the first page holds 25, and no open bills are sent yet.
    $firstLoad = $this->get("http://{$domain}/payments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('payments.data', 25)
            ->where('payments.total', 31)
            ->missing('outstandingPurchases'));
    $version = $firstLoad->viewData('page')['version'];

    $queriesFor = function (int $supplierId) use ($domain, $version): array {
        DB::connection('tenant')->flushQueryLog();
        DB::connection('tenant')->enableQueryLog();

        $response = $this->get("http://{$domain}/payments?supplier_id={$supplierId}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Tenant/Purchases/Payments/Index',
            'X-Inertia-Partial-Data' => 'outstandingPurchases',
        ]);

        $count = count(DB::connection('tenant')->getQueryLog());
        DB::connection('tenant')->disableQueryLog();

        return [$response->json('props.outstandingPurchases'), $count];
    };

    [$oneBillRows, $oneBillQueries] = $queriesFor($oneBillSupplierId);
    [$manyBillRows, $manyBillQueries] = $queriesFor($manyBillSupplierId);

    expect($oneBillRows)->toHaveCount(1)
        ->and($manyBillRows)->toHaveCount(6)
        ->and(collect($manyBillRows)->pluck('outstanding')->unique()->all())->toBe(['150.00'])
        // Six bills cost no more queries than one.
        ->and($manyBillQueries)->toBe($oneBillQueries);

    $tenant->delete();
});
