<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Raw Material Inventory, WIP and Finished Goods Inventory are control
 * accounts: only stock documents may move them, so a manual voucher is
 * refused before anything posts (todo/manufacturing CONTRACTS MC9).
 */
function controlAccountTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        FiscalYear::firstOrCreate(
            ['name' => '2026'],
            ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open],
        );
        User::factory()->create(['email' => 'owner@example.com']);
    });

    return $tenant;
}

test('a manual journal into an inventory control account is refused', function (string $code) {
    $domain = 'control-journal.tenant-test';
    $tenant = controlAccountTenant($domain);
    [$controlId, $cashId] = $tenant->run(fn () => [
        Account::where('code', $code)->value('id'),
        Account::where('code', 'AS1')->value('id'),
    ]);
    tenancy()->end();

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}".route('tenant.journal-vouchers.store', [], false), [
        'date' => '2026-05-01',
        'narration' => 'Manual stock write-up',
        'lines' => [
            ['account_id' => $controlId, 'debit' => '100.00', 'credit' => null],
            ['account_id' => $cashId, 'debit' => null, 'credit' => '100.00'],
        ],
    ])->assertSessionHasErrors('lines');

    expect($tenant->run(fn () => JournalVoucher::count()))->toBe(0);
})->with(['AS12', 'AS13', 'AS14']);

test('a cash payment into an inventory control account is refused', function () {
    $domain = 'control-cash.tenant-test';
    $tenant = controlAccountTenant($domain);
    $controlId = $tenant->run(fn () => Account::where('code', 'AS12')->value('id'));
    tenancy()->end();

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}".route('tenant.journal-vouchers.cash-bank.store', [], false), [
        'voucher_type' => 'cash_payment',
        'date' => '2026-05-01',
        'narration' => 'Raw material bought for cash',
        'lines' => [['account_id' => $controlId, 'amount' => '100.00']],
    ])->assertSessionHasErrors('lines');

    expect($tenant->run(fn () => JournalVoucher::count()))->toBe(0);
});

test('a manual journal between ordinary accounts still posts', function () {
    $domain = 'control-ok.tenant-test';
    $tenant = controlAccountTenant($domain);
    [$capitalId, $cashId] = $tenant->run(fn () => [
        Account::where('code', 'CA2')->value('id'),
        Account::where('code', 'AS1')->value('id'),
    ]);
    tenancy()->end();

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}".route('tenant.journal-vouchers.store', [], false), [
        'date' => '2026-05-01',
        'narration' => 'Capital introduced',
        'lines' => [
            ['account_id' => $cashId, 'debit' => '100.00', 'credit' => null],
            ['account_id' => $capitalId, 'debit' => null, 'credit' => '100.00'],
        ],
    ])->assertSessionHasNoErrors();

    expect($tenant->run(fn () => JournalVoucher::count()))->toBe(1);
});
