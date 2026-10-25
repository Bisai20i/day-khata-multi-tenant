<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

test('service purchases are not reported as capital expenditure in purchase vat book', function () {
    $domain = 'service-vat-book.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Service Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = User::factory()->create(['email' => 'admin@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        $account = Account::factory()->create();

        // 1. Capital purchase
        CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '10000', 'vatable' => true]],
            $actor,
        );

        // 2. Service purchase
        CapitalPurchase::post(
            ['type' => 'service', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '5000', 'vatable' => true]],
            $actor,
        );
    });

    $this->post("http://{$domain}/login", [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/reports/purchase-vat-book?from=2026-06-01&to=2026-06-30");
    $response->assertOk();

    $response->assertInertia(function (AssertableInertia $page) {
        $rows = $page->toArray()['props']['rows'];
        expect($rows)->toHaveCount(2);

        $capitalRow = collect($rows)->firstWhere('issued_on', '2026-06-01');
        $serviceRow = collect($rows)->firstWhere('issued_on', '2026-06-02');

        expect($capitalRow['capital'])->toBeTrue();
        expect($capitalRow['capital_amount'])->toBe('10000.00');

        expect($serviceRow['capital'])->toBeFalse();
        expect($serviceRow['capital_amount'])->toBe('0.00');
    });

    $tenant->delete();
});
