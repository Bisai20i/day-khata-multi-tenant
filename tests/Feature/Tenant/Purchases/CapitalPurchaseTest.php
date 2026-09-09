<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
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
    return User::factory()->create();
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
            [['account_id' => $account->id, 'amount' => 5000]],
            $actor,
        );

        expect($purchase->supplier_id)->toBeNull()
            ->and((float) $purchase->total)->toBe(5000.0)
            ->and($purchase->status)->toBe('posted');

        $voucher = $purchase->journalVoucher()->with('lines')->first();
        expect($voucher->voucher_type)->toBe(VoucherType::CapitalPurchase);

        $totalDebit = round((float) $voucher->lines->sum(fn ($l) => (float) $l->debit), 2);
        $totalCredit = round((float) $voucher->lines->sum(fn ($l) => (float) $l->credit), 2);
        expect($totalDebit)->toBe($totalCredit)->toBe(5000.0);

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        $cashLine = $voucher->lines->firstWhere('account_id', $cashAccount->id);
        expect((float) $cashLine->credit)->toBe(5000.0);

        $pickedLine = $voucher->lines->firstWhere('account_id', $account->id);
        expect((float) $pickedLine->debit)->toBe(5000.0);
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
            [['account_id' => $account->id, 'amount' => 1200]],
            $actor,
        );

        expect($purchase->type)->toBe('service');

        $voucher = $purchase->journalVoucher;
        $bankLine = $voucher->lines()->where('account_id', $bankAccount->id)->first();
        expect((float) $bankLine->credit)->toBe(1200.0);
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
            [['account_id' => $account->id, 'amount' => 1000]],
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
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        $supplier = Supplier::factory()->create();

        $purchase = CapitalPurchase::post(
            ['supplier_id' => $supplier->id, 'type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        );

        $net = $supplier->account->journalVoucherLines()->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as net')->value('net');
        expect((float) $net)->toBe((float) $purchase->total);
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
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'partial', 'bank_account_id' => $bankAccount->id, 'cash_amount' => 400, 'bank_amount' => 600],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'supplier');

        $purchase = CapitalPurchase::post(
            [
                'supplier_id' => $supplier->id,
                'type' => 'capital',
                'date' => '2026-06-01',
                'payment_mode' => 'partial',
                'bank_account_id' => $bankAccount->id,
                'cash_amount' => 400,
                'bank_amount' => 600,
            ],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        );

        $net = $supplier->account->journalVoucherLines()->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as net')->value('net');
        expect((float) $net)->toBe(0.0);

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        $voucher = $purchase->journalVoucher;
        expect((float) $voucher->lines()->where('account_id', $cashAccount->id)->value('credit'))->toBe(400.0)
            ->and((float) $voucher->lines()->where('account_id', $bankAccount->id)->value('credit'))->toBe(600.0);
    });

    $tenant->delete();
});

test('a partial payment with mismatched cash and bank amounts is rejected', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-partial-mismatch.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();
        $bankAccount = Account::factory()->create();
        $supplier = Supplier::factory()->create();

        expect(fn () => CapitalPurchase::post(
            [
                'supplier_id' => $supplier->id,
                'type' => 'capital',
                'date' => '2026-06-01',
                'payment_mode' => 'partial',
                'bank_account_id' => $bankAccount->id,
                'cash_amount' => 100,
                'bank_amount' => 50,
            ],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a VAT amount posts an additional debit line to the input VAT account', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-vat.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'vat_amount' => 130],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        );

        expect((float) $purchase->total)->toBe(1130.0);

        $asa23 = Account::where('code', 'ASA23')->firstOrFail();
        $vatLine = $purchase->journalVoucher->lines()->where('account_id', $asa23->id)->first();
        expect($vatLine)->not->toBeNull()->and((float) $vatLine->debit)->toBe(130.0);
    });

    $tenant->delete();
});

test('no VAT line is posted when vat_amount is zero', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-no-vat.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        );

        $asa23 = Account::where('code', 'ASA23')->firstOrFail();
        expect($purchase->journalVoucher->lines()->where('account_id', $asa23->id)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('an invalid type is rejected', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-invalid-type.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        expect(fn () => CapitalPurchase::post(
            ['type' => 'asset', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a capital purchase posts a mirrored reversal voucher and cannot be cancelled twice', function () {
    $tenant = provisionCapitalPurchaseTestTenant('capital-purchase-cancel.tenant-test');

    $tenant->run(function () {
        capitalPurchaseOpenFiscalYear();
        $actor = capitalPurchaseTestActor();
        $account = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => 5000]],
            $actor,
        );

        $originalLines = $purchase->journalVoucher->lines()->get()->map(fn ($l) => [$l->account_id, (float) $l->debit, (float) $l->credit])->all();

        $purchase->cancel($actor, 'Entered by mistake');

        expect($purchase->fresh()->status)->toBe('cancelled');

        $reversal = JournalVoucher::where('voucher_type', VoucherType::CapitalPurchase)
            ->where('id', '!=', $purchase->journal_voucher_id)
            ->firstOrFail();
        $reversedLines = $reversal->lines()->get()->map(fn ($l) => [$l->account_id, (float) $l->credit, (float) $l->debit])->all();

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
        'lines' => [
            ['account_id' => $accountId, 'amount' => 2500],
        ],
    ])->assertRedirect("http://{$domain}/capital-purchases");

    $tenant->run(function () {
        expect(CapitalPurchase::query()->count())->toBe(1);
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
            ['account_id' => $accountId, 'amount' => 100],
        ],
    ])->assertSessionHasErrors('payment_mode');

    $tenant->delete();
});

test('an authenticated user can cancel a posted capital purchase through the cancel route', function () {
    $domain = 'capital-purchases-cancel-http.tenant-test';
    $tenant = provisionCapitalPurchaseTestTenant($domain);

    $purchaseId = null;
    $tenant->run(function () use (&$purchaseId) {
        $actor = User::factory()->create(['email' => 'owner@example.com']);
        capitalPurchaseOpenFiscalYear();
        $account = Account::factory()->create();

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => 100]],
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
        'lines' => [['account_id' => $accountId, 'amount' => 100]],
    ])->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        expect(CapitalPurchase::query()->count())->toBe(0);
    });

    $tenant->delete();
});
