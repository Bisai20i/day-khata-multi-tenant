<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettlementNarration;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * T13 item 10: every purchase, purchase return, payment and capital
 * purchase voucher line uses App\Support\SettlementNarration::line(), the
 * same helper T12's sales side already uses (see
 * tests/Feature/Tenant/Sales/SalesLedgerNarrationTest.php, the pattern this
 * file mirrors). All four models already had the wiring committed when this
 * pass started; this file is new test coverage for that wiring, not new
 * production code.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseNarrationTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function purchaseNarrationActor(): User
{
    return User::factory()->create();
}

function purchaseNarrationOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('a cash purchase gives every voucher line the "{bill} - Cash Settlement" narration', function () {
    $tenant = provisionPurchaseNarrationTenant('narration-purchase-cash.tenant-test');

    $tenant->run(function () {
        purchaseNarrationOpenFiscalYear();
        $actor = purchaseNarrationActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100]],
            $actor,
        );

        $prefix = CompanySetting::current()->purchase_prefix;
        $expected = SettlementNarration::line("{$prefix}-{$purchase->journalVoucher->voucher_number}", 'cash');

        foreach ($purchase->journalVoucher->lines as $line) {
            expect($line->narration)->toBe($expected)
                ->and($line->narration)->toContain('Cash Settlement');
        }
    });

    $tenant->delete();
});

test('a credit purchase narration reads "Credit"', function () {
    $tenant = provisionPurchaseNarrationTenant('narration-purchase-credit.tenant-test');

    $tenant->run(function () {
        purchaseNarrationOpenFiscalYear();
        $actor = purchaseNarrationActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100]],
            $actor,
        );

        $prefix = CompanySetting::current()->purchase_prefix;

        foreach ($purchase->journalVoucher->lines as $line) {
            expect($line->narration)->toContain('Credit')
                ->and($line->narration)->toContain("{$prefix}-{$purchase->journalVoucher->voucher_number}");
        }
    });

    $tenant->delete();
});

test('a linked purchase return credit note narration reads "{debit note} - Credit"', function () {
    $tenant = provisionPurchaseNarrationTenant('narration-purchase-return.tenant-test');

    $tenant->run(function () {
        purchaseNarrationOpenFiscalYear();
        $actor = purchaseNarrationActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $actor,
        );

        $line = $purchase->lines()->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['purchase_line_id' => $line->id, 'quantity' => 2]],
            $actor,
        );

        $expected = SettlementNarration::line($return->debit_note_number, null);

        foreach ($return->journalVoucher->lines as $voucherLine) {
            expect($voucherLine->narration)->toBe($expected)
                ->and($voucherLine->narration)->toContain('Credit');
        }
    });

    $tenant->delete();
});

test('an immediate cash refund on a purchase return gets its own "{debit note} - Cash Settlement" voucher', function () {
    $tenant = provisionPurchaseNarrationTenant('narration-purchase-return-refund.tenant-test');

    $tenant->run(function () {
        purchaseNarrationOpenFiscalYear();
        $actor = purchaseNarrationActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $cashAccount = Account::where('code', 'AS1')->firstOrFail();

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $actor,
        );

        $line = $purchase->lines()->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05', 'reason' => 'Damaged', 'refund_account_id' => $cashAccount->id],
            [['purchase_line_id' => $line->id, 'quantity' => 2]],
            $actor,
        );

        $refundVoucher = $return->refundJournalVoucher()->with('lines')->firstOrFail();
        $expected = SettlementNarration::line($return->debit_note_number, 'cash');

        foreach ($refundVoucher->lines as $voucherLine) {
            expect($voucherLine->narration)->toBe($expected);
        }
    });

    $tenant->delete();
});

test('a payment voucher line narration carries the payment number and settlement mode', function () {
    $tenant = provisionPurchaseNarrationTenant('narration-payment.tenant-test');

    $tenant->run(function () {
        purchaseNarrationOpenFiscalYear();
        $actor = purchaseNarrationActor();
        $supplier = Supplier::factory()->create();

        $payment = Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
        ], $actor);

        $voucher = $payment->journalVoucher;
        $expected = SettlementNarration::line("PMT-{$voucher->voucher_number}", 'cash');

        foreach ($voucher->lines as $line) {
            expect($line->narration)->toBe($expected)
                ->and($line->narration)->toContain('Cash Settlement');
        }
    });

    $tenant->delete();
});

test('a capital purchase voucher line narration carries the capital purchase document number and mode', function () {
    $tenant = provisionPurchaseNarrationTenant('narration-capital-purchase.tenant-test');

    $tenant->run(function () {
        purchaseNarrationOpenFiscalYear();
        $actor = purchaseNarrationActor();
        $account = Account::factory()->create();

        $capitalPurchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'bank', 'bank_account_id' => Account::factory()->create()->id],
            [['account_id' => $account->id, 'amount' => '5000']],
            $actor,
        );

        $voucher = $capitalPurchase->journalVoucher;
        $expected = SettlementNarration::line("CP-{$voucher->voucher_number}", 'bank');

        foreach ($voucher->lines as $line) {
            expect($line->narration)->toBe($expected)
                ->and($line->narration)->toContain('Bank Settlement');
        }
    });

    $tenant->delete();
});
