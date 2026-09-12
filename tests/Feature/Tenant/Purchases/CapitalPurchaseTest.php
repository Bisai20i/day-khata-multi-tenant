<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\BillingException;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCapitalPurchaseTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function capitalPurchaseTestActor(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function capitalPurchaseOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function loginCapitalPurchaseTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('a cash capital purchase posts a balanced voucher with no supplier required', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-cash.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '5000']],
            $actor,
        );

        expect($purchase->supplier_id)->toBeNull()
            ->and($purchase->total)->toBe('5000.00')
            ->and($purchase->status)->toBe('posted');

        $voucher = $purchase->journalVoucher()->with('lines')->first();
        expect($voucher->voucher_type)->toBe(VoucherType::CapitalPurchase);

        $totalDebit = $voucher->lines->reduce(fn (Money $carry, $line) => $carry->plus($line->debit), Money::zero());
        $totalCredit = $voucher->lines->reduce(fn (Money $carry, $line) => $carry->plus($line->credit), Money::zero());
        expect($totalDebit->toString())->toBe('5000.00')->and($totalCredit->toString())->toBe('5000.00');

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        expect($voucher->lines->firstWhere('account_id', $cashAccount->id)->credit)->toBe('5000.00')
            ->and($voucher->lines->firstWhere('account_id', $account->id)->debit)->toBe('5000.00');
    });

    $tenant->delete();
});

test('a bank capital service purchase debits the picked account and credits the bank account', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-bank.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();
        $bankAccount = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'service', 'date' => '2026-06-01', 'payment_mode' => 'bank', 'bank_account_id' => $bankAccount->id],
            [['account_id' => $account->id, 'amount' => '1200']],
            $actor,
        );

        expect($purchase->type)->toBe('service');

        $voucher = $purchase->journalVoucher;
        expect($voucher->lines()->where('account_id', $bankAccount->id)->first()->credit)->toBe('1200.00')
            ->and($voucher->lines()->where('account_id', $account->id)->first()->debit)->toBe('1200.00');
    });

    $tenant->delete();
});

test('a bank capital purchase without a bank account is rejected', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-bank-missing.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        expect(fn () => CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'bank'],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a credit capital purchase requires a supplier and credits the supplier account for the full total', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-credit.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        expect(fn () => CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        $supplier = Supplier::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        );

        $net = $supplier->account->journalVoucherLines()->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as net')->value('net');
        expect(Money::round($net)->toString())->toBe($purchase->total);
    });

    $tenant->delete();
});

test('a partial capital purchase requires a supplier, splits settlement across cash and bank, and nets the supplier balance to zero', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-partial.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();
        $bankAccount = Account::factory()->create();
        $supplier = Supplier::factory()->create();

        expect(fn () => CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'partial', 'bank_account_id' => $bankAccount->id, 'cash_amount' => '400', 'bank_amount' => '600'],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'supplier');

        $purchase = CapitalPurchase::post(
            [
                'type' => 'capital',
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'partial',
                'bank_account_id' => $bankAccount->id,
                'cash_amount' => '400',
                'bank_amount' => '600',
            ],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        );

        $net = $supplier->account->journalVoucherLines()->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as net')->value('net');
        expect(Money::round($net)->isZero())->toBeTrue();

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        $voucher = $purchase->journalVoucher;
        expect($voucher->lines()->where('account_id', $cashAccount->id)->first()->credit)->toBe('400.00')
            ->and($voucher->lines()->where('account_id', $bankAccount->id)->first()->credit)->toBe('600.00');
    });

    $tenant->delete();
});

test('a partial capital purchase split that is one paisa out is rejected exactly', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-partial-paisa.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();
        $bankAccount = Account::factory()->create();
        $supplier = Supplier::factory()->create();

        // The old guard accepted anything within 0.01 of the amount due
        // (audit P0-4), leaving the difference on the supplier's ledger.
        $post = fn (string $cash, string $bank) => CapitalPurchase::post(
            [
                'type' => 'capital',
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'partial',
                'bank_account_id' => $bankAccount->id,
                'cash_amount' => $cash,
                'bank_amount' => $bank,
            ],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        );

        expect(fn () => $post('400.00', '599.99'))->toThrow(BillingException::class);
        expect(fn () => $post('400.00', '600.01'))->toThrow(BillingException::class);
        expect(fn () => $post('100', '50'))->toThrow(BillingException::class);

        expect($post('400.00', '600.00')->total)->toBe('1000.00');
    });

    $tenant->delete();
});

test('input VAT is computed from the vatable lines and the rate, not typed', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-vat.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $vatableAccount = Account::factory()->create();
        $exemptAccount = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'vat_rate' => '13'],
            [
                ['account_id' => $vatableAccount->id, 'amount' => '1000', 'vatable' => true],
                ['account_id' => $exemptAccount->id, 'amount' => '500', 'vatable' => false],
            ],
            $actor,
        );

        expect($purchase->taxable_amount)->toBe('1000.00')
            ->and($purchase->nontaxable_amount)->toBe('500.00')
            ->and($purchase->vat_rate)->toBe('13.00')
            ->and($purchase->vat_amount)->toBe('130.00')
            ->and($purchase->total)->toBe('1630.00');

        $asa23 = Account::where('code', 'ASA23')->firstOrFail();
        $vatLine = $purchase->journalVoucher->lines()->where('account_id', $asa23->id)->first();
        expect($vatLine)->not->toBeNull()->and($vatLine->debit)->toBe('130.00');
    });

    $tenant->delete();
});

test('no VAT line is posted when no line is vatable', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-no-vat.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'vat_rate' => '13'],
            [['account_id' => $account->id, 'amount' => '1000', 'vatable' => false]],
            $actor,
        );

        expect($purchase->vat_amount)->toBe('0.00')->and($purchase->total)->toBe('1000.00');

        $asa23 = Account::where('code', 'ASA23')->firstOrFail();
        expect($purchase->journalVoucher->lines()->where('account_id', $asa23->id)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('an invalid type is rejected', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-bad-type.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        expect(fn () => CapitalPurchase::post(
            ['type' => 'stock', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '100']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('the same supplier bill number cannot be entered twice while the first entry is live', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-duplicate-bill.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();
        $supplier = Supplier::factory()->create(['tpin' => '605551234']);
        $otherSupplier = Supplier::factory()->create();

        $post = fn (Supplier $on, string $bill) => CapitalPurchase::post(
            ['type' => 'capital', 'supplier_id' => $on->id, 'bill_number' => $bill, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        );

        $first = $post($supplier, 'INV-77');
        expect($first->bill_number)->toBe('INV-77')
            ->and($first->supplier_pan)->toBe('605551234')
            ->and($first->bill_number_guard)->toBe($supplier->id.'|INV-77');

        expect(fn () => $post($supplier, 'INV-77'))->toThrow(InvalidArgumentException::class, 'INV-77');

        // A different supplier may legitimately use the same bill number.
        expect($post($otherSupplier, 'INV-77')->bill_number)->toBe('INV-77');

        // Cancelling releases the number so the correct entry can be made.
        $first->cancel($actor, 'Wrong amount');
        expect($first->fresh()->bill_number_guard)->toBeNull();

        expect($post($supplier, 'INV-77')->bill_number)->toBe('INV-77');
    });

    $tenant->delete();
});

test('cancelling a capital purchase reverses it in the Reversal series and fills the cancellation columns', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-cancel.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'vat_rate' => '13'],
            [['account_id' => $account->id, 'amount' => '5000', 'vatable' => true]],
            $actor,
        );

        $originalLines = $purchase->journalVoucher->lines()->get()
            ->map(fn ($l) => [$l->account_id, $l->debit, $l->credit])->all();

        $purchase->cancel($actor, 'Entered by mistake');
        $purchase->refresh();

        expect($purchase->status)->toBe('cancelled')
            ->and($purchase->cancelled_by)->toBe($actor->id)
            ->and($purchase->cancel_reason)->toBe('Entered by mistake')
            ->and($purchase->cancelled_at)->not->toBeNull()
            ->and($purchase->reversal_journal_voucher_id)->not->toBeNull();

        $reversal = JournalVoucher::findOrFail($purchase->reversal_journal_voucher_id);
        expect($reversal->voucher_type)->toBe(VoucherType::Reversal)
            ->and($reversal->reversal_of_id)->toBe($purchase->journal_voucher_id);

        expect(JournalVoucher::where('voucher_type', VoucherType::CapitalPurchase)->count())->toBe(1);

        $reversedLines = $reversal->lines()->get()->map(fn ($l) => [$l->account_id, $l->credit, $l->debit])->all();
        sort($originalLines);
        sort($reversedLines);
        expect($reversedLines)->toEqual($originalLines);

        expect(fn () => $purchase->cancel($actor, 'Again'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('the capital purchases index page renders', function () {
    $domain = 'capital-purchases-index-render.tenant-test';
    $tenant = provisionCapitalPurchaseTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginCapitalPurchaseTestUser($domain);

    $this->get("http://{$domain}/capital-purchases")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Purchases/CapitalPurchases/Index'));

    $tenant->delete();
});

test('an authenticated user can post a capital purchase through the store route', function () {
    $domain = 'capital-purchases-store-http.tenant-test';
    $tenant = provisionCapitalPurchaseTestTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        User::factory()->create(['email' => 'owner@example.com']);
        capitalPurchaseOpenFiscalYear();
        $accountId = Account::factory()->create()->id;
    });

    loginCapitalPurchaseTestUser($domain);

    $this->post("http://{$domain}/capital-purchases", [
        'type' => 'capital',
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'vat_rate' => '13',
        'expected_total' => '2825.00',
        'lines' => [
            ['account_id' => $accountId, 'amount' => '2500', 'vatable' => true],
        ],
    ])->assertRedirect("http://{$domain}/capital-purchases");

    $tenant->run(function () {
        $purchase = CapitalPurchase::query()->sole();
        expect($purchase->total)->toBe('2825.00')->and($purchase->vat_amount)->toBe('325.00');
    });

    $tenant->delete();
});

test('the store route refuses a duplicate supplier bill number with a field error', function () {
    $domain = 'capital-purchases-duplicate-http.tenant-test';
    $tenant = provisionCapitalPurchaseTestTenant($domain);

    $accountId = null;
    $supplierId = null;
    $tenant->run(function () use (&$accountId, &$supplierId) {
        $actor = User::factory()->create(['email' => 'owner@example.com']);
        capitalPurchaseOpenFiscalYear();
        $accountId = Account::factory()->create()->id;
        $supplierId = Supplier::factory()->create()->id;

        CapitalPurchase::post(
            ['type' => 'capital', 'supplier_id' => $supplierId, 'bill_number' => 'B-1', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $accountId, 'amount' => '500']],
            $actor,
        );
    });

    loginCapitalPurchaseTestUser($domain);

    $this->post("http://{$domain}/capital-purchases", [
        'type' => 'capital',
        'supplier_id' => $supplierId,
        'bill_number' => 'B-1',
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'lines' => [['account_id' => $accountId, 'amount' => '500']],
    ])->assertSessionHasErrors('bill_number');

    $tenant->run(function () {
        expect(CapitalPurchase::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('the store route refuses a payload whose expected total does not match', function () {
    $domain = 'capital-purchases-store-mismatch.tenant-test';
    $tenant = provisionCapitalPurchaseTestTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        User::factory()->create(['email' => 'owner@example.com']);
        capitalPurchaseOpenFiscalYear();
        $accountId = Account::factory()->create()->id;
    });

    loginCapitalPurchaseTestUser($domain);

    $this->post("http://{$domain}/capital-purchases", [
        'type' => 'capital',
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'vat_rate' => '13',
        'expected_total' => '2500.00',
        'lines' => [['account_id' => $accountId, 'amount' => '2500', 'vatable' => true]],
    ])->assertSessionHasErrors('expected_total');

    $tenant->run(function () {
        expect(CapitalPurchase::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('posting a capital purchase with an invalid payment mode is rejected by validation', function () {
    $domain = 'capital-purchases-invalid-mode.tenant-test';
    $tenant = provisionCapitalPurchaseTestTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        User::factory()->create(['email' => 'owner@example.com']);
        capitalPurchaseOpenFiscalYear();
        $accountId = Account::factory()->create()->id;
    });

    loginCapitalPurchaseTestUser($domain);

    $this->post("http://{$domain}/capital-purchases", [
        'type' => 'capital',
        'date' => '2026-06-01',
        'payment_mode' => 'cheque',
        'lines' => [
            ['account_id' => $accountId, 'amount' => '100'],
        ],
    ])->assertSessionHasErrors('payment_mode');

    $tenant->delete();
});

test('an admin can cancel a posted capital purchase through the cancel route', function () {
    $domain = 'capital-purchases-cancel-http.tenant-test';
    $tenant = provisionCapitalPurchaseTestTenant($domain);

    $purchaseId = null;
    $tenant->run(function () use (&$purchaseId) {
        $actor = User::factory()->create([
            'email' => 'owner@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
        capitalPurchaseOpenFiscalYear();
        $account = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '100']],
            $actor,
        );
        $purchaseId = $purchase->id;
    });

    loginCapitalPurchaseTestUser($domain);

    $this->post("http://{$domain}/capital-purchases/{$purchaseId}/cancel", [
        'reason' => 'Entered by mistake',
    ])->assertRedirect("http://{$domain}/capital-purchases");

    $tenant->run(function () use ($purchaseId) {
        expect(CapitalPurchase::query()->findOrFail($purchaseId)->status)->toBe('cancelled');
    });

    $tenant->delete();
});

test('the capital purchases index route is rejected for an unauthenticated request', function () {
    $domain = 'capital-purchases-index-guest.tenant-test';
    $tenant = provisionCapitalPurchaseTestTenant($domain);

    $this->get("http://{$domain}/capital-purchases")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('the capital purchases store route is rejected for an unauthenticated request', function () {
    $domain = 'capital-purchases-store-guest.tenant-test';
    $tenant = provisionCapitalPurchaseTestTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        capitalPurchaseOpenFiscalYear();
        $accountId = Account::factory()->create()->id;
    });

    $this->post("http://{$domain}/capital-purchases", [
        'type' => 'capital',
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'lines' => [['account_id' => $accountId, 'amount' => '100']],
    ])->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        expect(CapitalPurchase::query()->count())->toBe(0);
    });

    $tenant->delete();
});
