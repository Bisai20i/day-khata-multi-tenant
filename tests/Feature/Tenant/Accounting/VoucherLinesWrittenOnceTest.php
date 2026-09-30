<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\CapitalPurchaseSettlement;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucherLine;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Flags G-22: posted journal voucher lines are written once. Every document
 * used to insert its lines and then rewrite their narration with a bulk
 * query-builder update, which only worked because a bulk update skips the
 * model guard that keeps posted lines immutable. They are now inserted with
 * their final narration, and this test fails on any UPDATE of the table.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

test('posting every kind of document never updates a journal voucher line, and the lines still carry the document number', function () {
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'voucher-lines-written-once.tenant-test']);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $account = Account::factory()->create();

        $lineUpdates = [];
        DB::connection('tenant')->listen(function (QueryExecuted $query) use (&$lineUpdates) {
            if (preg_match('/^\s*update\s+"?`?journal_voucher_lines/i', $query->sql)) {
                $lineUpdates[] = $query->sql;
            }
        });

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 4, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $receipt = Receipt::post(['customer_id' => $customer->id, 'date' => '2026-06-02', 'amount' => '100', 'payment_mode' => 'cash'], $admin);
        $salesReturn = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-03', 'reason' => null],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => 1]],
            $admin,
        );
        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 50, 'discount' => 0]],
            $admin,
        );
        $payment = Payment::post(['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'amount' => '60', 'payment_mode' => 'cash'], $admin);
        $purchaseReturn = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-03'],
            [['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 1]],
            $admin,
        );
        $capital = CapitalPurchase::post(
            ['type' => 'capital', 'supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'vat_rate' => '0'],
            [['account_id' => $account->id, 'amount' => '500']],
            $admin,
        );
        CapitalPurchaseSettlement::settle($capital, ['date' => '2026-06-04', 'amount' => '100', 'payment_mode' => 'cash'], $admin);

        expect($lineUpdates)->toBe([]);

        $narrationsOf = fn (int $voucherId) => JournalVoucherLine::where('journal_voucher_id', $voucherId)->pluck('narration')->unique()->values()->all();

        expect($narrationsOf($sale->journal_voucher_id))->toBe(["{$sale->invoice_number} - Credit"])
            ->and($narrationsOf($receipt->journal_voucher_id))->toBe(["{$receipt->journalVoucher->voucher_number} - Cash Settlement"])
            ->and($narrationsOf($salesReturn->journal_voucher_id))->toBe(["{$salesReturn->credit_note_number} - Credit"])
            ->and($narrationsOf($purchase->journal_voucher_id))->toBe(["{$purchase->purchase_number} - Credit"])
            ->and($narrationsOf($payment->journal_voucher_id))->toBe(["{$payment->payment_number} - Cash Settlement"])
            ->and($narrationsOf($purchaseReturn->journal_voucher_id))->toBe(["{$purchaseReturn->debit_note_number} - Credit"])
            ->and($narrationsOf($capital->journal_voucher_id))->toBe(["CP-{$capital->journalVoucher->voucher_number} - Credit"]);
    });

    $tenant->delete();
});
