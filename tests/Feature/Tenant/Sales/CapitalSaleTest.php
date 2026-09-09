<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CapitalSale;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCapitalSaleTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function capitalSaleTestActor(): User
{
    return User::factory()->create();
}

function capitalSaleOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function loginCapitalSaleTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('a cash capital sale posts a balanced voucher with no customer required', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-cash.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => 5000]],
            $actor,
        );

        expect($sale->customer_id)->toBeNull()
            ->and((float) $sale->total)->toBe(5000.0)
            ->and($sale->status)->toBe('posted');

        $voucher = $sale->journalVoucher()->with('lines')->first();
        expect($voucher->voucher_type)->toBe(VoucherType::CapitalSale);

        $totalDebit = round((float) $voucher->lines->sum(fn ($l) => (float) $l->debit), 2);
        $totalCredit = round((float) $voucher->lines->sum(fn ($l) => (float) $l->credit), 2);
        expect($totalDebit)->toBe($totalCredit)->toBe(5000.0);

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        $cashLine = $voucher->lines->firstWhere('account_id', $cashAccount->id);
        expect((float) $cashLine->debit)->toBe(5000.0);

        $pickedLine = $voucher->lines->firstWhere('account_id', $account->id);
        expect((float) $pickedLine->credit)->toBe(5000.0);
    });

    $tenant->delete();
});

test('a bank capital sale debits the bank account and credits the picked account', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-bank.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();
        $bankAccount = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'bank', 'bank_account_id' => $bankAccount->id],
            [['account_id' => $account->id, 'amount' => 1200]],
            $actor,
        );

        $voucher = $sale->journalVoucher;
        $bankLine = $voucher->lines()->where('account_id', $bankAccount->id)->first();
        expect((float) $bankLine->debit)->toBe(1200.0);
    });

    $tenant->delete();
});

test('a bank capital sale without a bank account is rejected', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-bank-missing.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        expect(fn () => CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'bank'],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a credit capital sale requires a customer and debits the customer account for the full total', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-credit.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        expect(fn () => CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        $customer = Customer::factory()->create();

        $sale = CapitalSale::post(
            ['customer_id' => $customer->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        );

        $net = $customer->account->journalVoucherLines()->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')->value('net');
        expect((float) $net)->toBe((float) $sale->total);
    });

    $tenant->delete();
});

test('a partial capital sale requires a customer, splits settlement across cash and bank, and nets the customer balance to zero', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-partial.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();
        $bankAccount = Account::factory()->create();
        $customer = Customer::factory()->create();

        expect(fn () => CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'partial', 'bank_account_id' => $bankAccount->id, 'cash_amount' => 400, 'bank_amount' => 600],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'customer');

        $sale = CapitalSale::post(
            [
                'customer_id' => $customer->id,
                'date' => '2026-06-01',
                'payment_mode' => 'partial',
                'bank_account_id' => $bankAccount->id,
                'cash_amount' => 400,
                'bank_amount' => 600,
            ],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        );

        $net = $customer->account->journalVoucherLines()->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')->value('net');
        expect((float) $net)->toBe(0.0);

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        $voucher = $sale->journalVoucher;
        expect((float) $voucher->lines()->where('account_id', $cashAccount->id)->value('debit'))->toBe(400.0)
            ->and((float) $voucher->lines()->where('account_id', $bankAccount->id)->value('debit'))->toBe(600.0);
    });

    $tenant->delete();
});

test('a partial payment with mismatched cash and bank amounts is rejected', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-partial-mismatch.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();
        $bankAccount = Account::factory()->create();
        $customer = Customer::factory()->create();

        expect(fn () => CapitalSale::post(
            [
                'customer_id' => $customer->id,
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

test('a VAT amount posts an additional credit line to the output VAT account', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-vat.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash', 'vat_amount' => 130],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        );

        expect((float) $sale->total)->toBe(1130.0);

        $lia20 = Account::where('code', 'LIA20')->firstOrFail();
        $vatLine = $sale->journalVoucher->lines()->where('account_id', $lia20->id)->first();
        expect($vatLine)->not->toBeNull()->and((float) $vatLine->credit)->toBe(130.0);
    });

    $tenant->delete();
});

test('no VAT line is posted when vat_amount is zero', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-no-vat.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => 1000]],
            $actor,
        );

        $lia20 = Account::where('code', 'LIA20')->firstOrFail();
        expect($sale->journalVoucher->lines()->where('account_id', $lia20->id)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('cancelling a capital sale posts a mirrored reversal voucher and cannot be cancelled twice', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-cancel.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => 5000]],
            $actor,
        );

        $originalLines = $sale->journalVoucher->lines()->get()->map(fn ($l) => [$l->account_id, (float) $l->debit, (float) $l->credit])->all();

        $sale->cancel($actor, 'Entered by mistake');

        expect($sale->fresh()->status)->toBe('cancelled');

        $reversal = JournalVoucher::where('voucher_type', VoucherType::CapitalSale)
            ->where('id', '!=', $sale->journal_voucher_id)
            ->firstOrFail();
        $reversedLines = $reversal->lines()->get()->map(fn ($l) => [$l->account_id, (float) $l->credit, (float) $l->debit])->all();

        sort($originalLines);
        sort($reversedLines);
        expect($reversedLines)->toEqual($originalLines);

        expect(fn () => $sale->cancel($actor, 'Again'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('the capital sales index page renders', function () {
    $domain = 'capital-sales-index-render.tenant-test';
    $tenant = provisionCapitalSaleTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginCapitalSaleTestUser($domain);

    $this->get("http://{$domain}/capital-sales")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Sales/CapitalSales/Index'));

    $tenant->delete();
});

test('an authenticated user can post a capital sale through the store route', function () {
    $domain = 'capital-sales-store-http.tenant-test';
    $tenant = provisionCapitalSaleTestTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        User::factory()->create(['email' => 'owner@example.com']);
        capitalSaleOpenFiscalYear();
        $accountId = Account::factory()->create()->id;
    });

    loginCapitalSaleTestUser($domain);

    $this->post("http://{$domain}/capital-sales", [
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'lines' => [
            ['account_id' => $accountId, 'amount' => 2500],
        ],
    ])->assertRedirect("http://{$domain}/capital-sales");

    $tenant->run(function () {
        expect(CapitalSale::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('posting a capital sale with an invalid payment mode is rejected by validation', function () {
    $domain = 'capital-sales-invalid-mode.tenant-test';
    $tenant = provisionCapitalSaleTestTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        User::factory()->create(['email' => 'owner@example.com']);
        capitalSaleOpenFiscalYear();
        $accountId = Account::factory()->create()->id;
    });

    loginCapitalSaleTestUser($domain);

    $this->post("http://{$domain}/capital-sales", [
        'date' => '2026-06-01',
        'payment_mode' => 'cheque',
        'lines' => [
            ['account_id' => $accountId, 'amount' => 100],
        ],
    ])->assertSessionHasErrors('payment_mode');

    $tenant->delete();
});

test('an authenticated user can cancel a posted capital sale through the cancel route', function () {
    $domain = 'capital-sales-cancel-http.tenant-test';
    $tenant = provisionCapitalSaleTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $actor = User::factory()->create(['email' => 'owner@example.com']);
        capitalSaleOpenFiscalYear();
        $account = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => 100]],
            $actor,
        );
        $saleId = $sale->id;
    });

    loginCapitalSaleTestUser($domain);

    $this->post("http://{$domain}/capital-sales/{$saleId}/cancel", [
        'reason' => 'Entered by mistake',
    ])->assertRedirect("http://{$domain}/capital-sales");

    $tenant->run(function () use ($saleId) {
        expect(CapitalSale::query()->findOrFail($saleId)->status)->toBe('cancelled');
    });

    $tenant->delete();
});

test('the capital sales index route is rejected for an unauthenticated request', function () {
    $domain = 'capital-sales-index-guest.tenant-test';
    $tenant = provisionCapitalSaleTestTenant($domain);

    $this->get("http://{$domain}/capital-sales")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('the capital sales store route is rejected for an unauthenticated request', function () {
    $domain = 'capital-sales-store-guest.tenant-test';
    $tenant = provisionCapitalSaleTestTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        capitalSaleOpenFiscalYear();
        $accountId = Account::factory()->create()->id;
    });

    $this->post("http://{$domain}/capital-sales", [
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'lines' => [['account_id' => $accountId, 'amount' => 100]],
    ])->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        expect(CapitalSale::query()->count())->toBe(0);
    });

    $tenant->delete();
});
