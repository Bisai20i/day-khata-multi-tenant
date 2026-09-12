<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CapitalSale;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\BillingException;
use App\Support\Money\Money;
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
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
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
            [['account_id' => $account->id, 'amount' => '5000']],
            $actor,
        );

        expect($sale->customer_id)->toBeNull()
            ->and($sale->total)->toBe('5000.00')
            ->and($sale->status)->toBe('posted');

        $voucher = $sale->journalVoucher()->with('lines')->first();
        expect($voucher->voucher_type)->toBe(VoucherType::CapitalSale);

        $totalDebit = $voucher->lines->reduce(fn ($carry, $line) => $carry->plus($line->debit), Money::zero());
        $totalCredit = $voucher->lines->reduce(fn ($carry, $line) => $carry->plus($line->credit), Money::zero());
        expect($totalDebit->toString())->toBe('5000.00')->and($totalCredit->toString())->toBe('5000.00');

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        $cashLine = $voucher->lines->firstWhere('account_id', $cashAccount->id);
        expect($cashLine->debit)->toBe('5000.00');

        $pickedLine = $voucher->lines->firstWhere('account_id', $account->id);
        expect($pickedLine->credit)->toBe('5000.00');
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
            [['account_id' => $account->id, 'amount' => '1200']],
            $actor,
        );

        $voucher = $sale->journalVoucher;
        $bankLine = $voucher->lines()->where('account_id', $bankAccount->id)->first();
        expect($bankLine->debit)->toBe('1200.00');
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
            [['account_id' => $account->id, 'amount' => '1000']],
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
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        $customer = Customer::factory()->create();

        $sale = CapitalSale::post(
            ['customer_id' => $customer->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        );

        $net = $customer->account->journalVoucherLines()->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')->value('net');
        expect((string) Money::round($net))->toBe($sale->total);
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
            ['date' => '2026-06-01', 'payment_mode' => 'partial', 'bank_account_id' => $bankAccount->id, 'cash_amount' => '400', 'bank_amount' => '600'],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'customer');

        $sale = CapitalSale::post(
            [
                'customer_id' => $customer->id,
                'date' => '2026-06-01',
                'payment_mode' => 'partial',
                'bank_account_id' => $bankAccount->id,
                'cash_amount' => '400',
                'bank_amount' => '600',
            ],
            [['account_id' => $account->id, 'amount' => '1000']],
            $actor,
        );

        $net = $customer->account->journalVoucherLines()->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')->value('net');
        expect(Money::round($net)->isZero())->toBeTrue();

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        $voucher = $sale->journalVoucher;
        expect($voucher->lines()->where('account_id', $cashAccount->id)->first()->debit)->toBe('400.00')
            ->and($voucher->lines()->where('account_id', $bankAccount->id)->first()->debit)->toBe('600.00');
    });

    $tenant->delete();
});

test('a partial capital sale split that is one paisa out is rejected exactly', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-partial-paisa.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();
        $bankAccount = Account::factory()->create();
        $customer = Customer::factory()->create();

        // The old guard accepted anything within 0.01 of the amount due
        // (audit P0-4), leaving the difference on the customer's ledger.
        $post = fn (string $cash, string $bank) => CapitalSale::post(
            [
                'customer_id' => $customer->id,
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

test('VAT is computed from the vatable lines and the rate, not typed', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-vat.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $vatableAccount = Account::factory()->create();
        $exemptAccount = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash', 'vat_rate' => '13'],
            [
                ['account_id' => $vatableAccount->id, 'amount' => '1000', 'vatable' => true],
                ['account_id' => $exemptAccount->id, 'amount' => '500', 'vatable' => false],
            ],
            $actor,
        );

        // 13% of the taxable 1000 only, never of the exempt 500.
        expect($sale->taxable_amount)->toBe('1000.00')
            ->and($sale->nontaxable_amount)->toBe('500.00')
            ->and($sale->vat_rate)->toBe('13.00')
            ->and($sale->vat_amount)->toBe('130.00')
            ->and($sale->total)->toBe('1630.00');

        $lia20 = Account::where('code', 'LIA20')->firstOrFail();
        $vatLine = $sale->journalVoucher->lines()->where('account_id', $lia20->id)->first();
        expect($vatLine)->not->toBeNull()->and($vatLine->credit)->toBe('130.00');

        expect($sale->lines()->where('account_id', $vatableAccount->id)->first()->vatable)->toBeTrue()
            ->and($sale->lines()->where('account_id', $exemptAccount->id)->first()->vatable)->toBeFalse();
    });

    $tenant->delete();
});

test('no VAT line is posted when no line is vatable', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-no-vat.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash', 'vat_rate' => '13'],
            [['account_id' => $account->id, 'amount' => '1000', 'vatable' => false]],
            $actor,
        );

        expect($sale->vat_amount)->toBe('0.00')->and($sale->total)->toBe('1000.00');

        $lia20 = Account::where('code', 'LIA20')->firstOrFail();
        expect($sale->journalVoucher->lines()->where('account_id', $lia20->id)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('a capital sale stores an invoice number, its fiscal year and the buyer snapshot', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-invoice-number.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Ram Traders', 'tpin' => '301234567', 'address' => 'Lalitpur']);

        $sale = CapitalSale::post(
            ['customer_id' => $customer->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => '1000', 'vatable' => true]],
            $actor,
        );

        $voucher = $sale->journalVoucher;

        expect($sale->invoice_number)->toBe(CapitalSale::invoicePrefix()."-{$voucher->voucher_number}")
            ->and($sale->fiscal_year_id)->toBe($voucher->fiscal_year_id)
            ->and($sale->documentNumber())->toBe($sale->invoice_number)
            ->and($sale->buyer_name)->toBe('Ram Traders')
            ->and($sale->buyer_pan)->toBe('301234567')
            ->and($sale->buyer_address)->toBe('Lalitpur');

        // The snapshot is frozen: correcting the customer record later must not
        // change an invoice that has already been issued.
        $customer->update(['name' => 'Ram Traders Pvt Ltd', 'tpin' => '309999999']);

        expect($sale->fresh()->buyer_name)->toBe('Ram Traders')
            ->and($sale->fresh()->buyer_pan)->toBe('301234567');
    });

    $tenant->delete();
});

test('an amount with more than two decimals is refused rather than silently rounded', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-decimals.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        expect(fn () => CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '100.005', 'vatable' => true]],
            $actor,
        ))->toThrow(BillingException::class);
    });

    $tenant->delete();
});

test('an expected total that disagrees with the server is refused', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-expected-total.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        try {
            CapitalSale::post(
                ['date' => '2026-06-01', 'payment_mode' => 'cash', 'vat_rate' => '13', 'expected_total' => '1000.00'],
                [['account_id' => $account->id, 'amount' => '1000', 'vatable' => true]],
                $actor,
            );
            $this->fail('A mismatched expected_total should have been refused.');
        } catch (BillingException $e) {
            expect($e->reason)->toBe(BillingException::REASON_TOTAL_MISMATCH);
        }

        expect(CapitalSale::count())->toBe(0);
    });

    $tenant->delete();
});

test('cancelling a capital sale reverses it in the Reversal series and fills the cancellation columns', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-cancel.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash', 'vat_rate' => '13'],
            [['account_id' => $account->id, 'amount' => '5000', 'vatable' => true]],
            $actor,
        );

        $originalLines = $sale->journalVoucher->lines()->get()
            ->map(fn ($l) => [$l->account_id, $l->debit, $l->credit])->all();

        $sale->cancel($actor, 'Entered by mistake');
        $sale->refresh();

        expect($sale->status)->toBe('cancelled')
            ->and($sale->cancelled_by)->toBe($actor->id)
            ->and($sale->cancel_reason)->toBe('Entered by mistake')
            ->and($sale->cancelled_at)->not->toBeNull()
            ->and($sale->reversal_journal_voucher_id)->not->toBeNull();

        // The reversal belongs to its own series, so no capital sale invoice
        // number is ever consumed by a cancellation (contract C4/C7).
        $reversal = JournalVoucher::findOrFail($sale->reversal_journal_voucher_id);
        expect($reversal->voucher_type)->toBe(VoucherType::Reversal)
            ->and($reversal->reversal_of_id)->toBe($sale->journal_voucher_id);

        expect(JournalVoucher::where('voucher_type', VoucherType::CapitalSale)->count())->toBe(1);

        $reversedLines = $reversal->lines()->get()->map(fn ($l) => [$l->account_id, $l->credit, $l->debit])->all();
        sort($originalLines);
        sort($reversedLines);
        expect($reversedLines)->toEqual($originalLines);

        expect(fn () => $sale->cancel($actor, 'Again'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling with a blank or over-long reason is refused', function () {
    $tenant = provisionCapitalSaleTestTenant('capital-sale-cancel-reason.tenant-test');

    $tenant->run(function () {
        capitalSaleOpenFiscalYear();
        $actor = capitalSaleTestActor();
        $account = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '100']],
            $actor,
        );

        expect(fn () => $sale->cancel($actor, '   '))->toThrow(InvalidArgumentException::class);
        expect(fn () => $sale->cancel($actor, str_repeat('x', 501)))->toThrow(InvalidArgumentException::class);
        expect($sale->fresh()->status)->toBe('posted');
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
        'vat_rate' => '13',
        'expected_total' => '2825.00',
        'lines' => [
            ['account_id' => $accountId, 'amount' => '2500', 'vatable' => true],
        ],
    ])->assertRedirect("http://{$domain}/capital-sales");

    $tenant->run(function () {
        $sale = CapitalSale::query()->sole();
        expect($sale->total)->toBe('2825.00')->and($sale->vat_amount)->toBe('325.00');
    });

    $tenant->delete();
});

test('the store route refuses a payload whose expected total does not match', function () {
    $domain = 'capital-sales-store-mismatch.tenant-test';
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
        'vat_rate' => '13',
        'expected_total' => '2500.00',
        'lines' => [
            ['account_id' => $accountId, 'amount' => '2500', 'vatable' => true],
        ],
    ])->assertSessionHasErrors('expected_total');

    $tenant->run(function () {
        expect(CapitalSale::query()->count())->toBe(0);
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
            ['account_id' => $accountId, 'amount' => '100'],
        ],
    ])->assertSessionHasErrors('payment_mode');

    $tenant->delete();
});

test('posting a capital sale with an amount carrying three decimals is rejected by validation', function () {
    $domain = 'capital-sales-decimal-validation.tenant-test';
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
            ['account_id' => $accountId, 'amount' => '100.005'],
        ],
    ])->assertSessionHasErrors('lines.0.amount');

    $tenant->delete();
});

test('an admin can cancel a posted capital sale through the cancel route', function () {
    $domain = 'capital-sales-cancel-http.tenant-test';
    $tenant = provisionCapitalSaleTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $actor = User::factory()->create([
            'email' => 'owner@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
        capitalSaleOpenFiscalYear();
        $account = Account::factory()->create();

        $sale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '100']],
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
        'lines' => [['account_id' => $accountId, 'amount' => '100']],
    ])->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        expect(CapitalSale::query()->count())->toBe(0);
    });

    $tenant->delete();
});
