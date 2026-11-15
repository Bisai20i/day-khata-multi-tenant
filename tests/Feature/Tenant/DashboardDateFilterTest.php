<?php

use App\Enums\FiscalYearStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
    Carbon::setTestNow();
});

test('dashboard handles date presets and custom date range query parameters', function () {
    $domain = 'dashboard-date-filter.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);

        FiscalYear::create([
            'name' => 'FY1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        $customer = Customer::factory()->create(['name' => 'Hari Thapa']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // Sale in May 2026
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-05-10', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );

        // Sale in June 2026
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-15', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 500, 'discount' => 0]],
            $admin,
        );
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    // 1. Default request carries dateRange with this_month preset and 7-day trend
    $response = $this->get("http://{$domain}/dashboard");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Dashboard')
        ->has('dateRange')
        ->where('dateRange.preset', 'this_month')
        ->has('kpis.sales.period')
        ->has('salesTrend', 7)
    );

    // 2. Preset today
    $today = now()->toDateString();
    $responseToday = $this->get("http://{$domain}/dashboard?preset=today");
    $responseToday->assertOk();
    $responseToday->assertInertia(fn ($page) => $page
        ->component('Tenant/Dashboard')
        ->where('dateRange.preset', 'today')
        ->where('dateRange.from', $today)
        ->where('dateRange.to', $today)
        ->has('salesTrend', 7)
    );

    // 3. Custom date range filtering: only the May sale, trend capped at 7 days ending at May 31
    $responseCustom = $this->get("http://{$domain}/dashboard?from=2026-05-01&to=2026-05-31");
    $responseCustom->assertOk();
    $responseCustom->assertInertia(fn ($page) => $page
        ->component('Tenant/Dashboard')
        ->where('dateRange.preset', 'custom')
        ->where('dateRange.from', '2026-05-01')
        ->where('dateRange.to', '2026-05-31')
        ->where('kpis.sales.period.count', 1)
        ->where('kpis.sales.period.total', '1000.00')
        ->has('salesTrend', 7)
        ->where('salesTrend.6.date', '2026-05-31')
    );

    // 4. Custom date range covering both sales, trend stays 7 days ending at June 30
    $responseBoth = $this->get("http://{$domain}/dashboard?from=2026-05-01&to=2026-06-30");
    $responseBoth->assertOk();
    $responseBoth->assertInertia(fn ($page) => $page
        ->component('Tenant/Dashboard')
        ->where('dateRange.preset', 'custom')
        ->where('dateRange.from', '2026-05-01')
        ->where('dateRange.to', '2026-06-30')
        ->where('kpis.sales.period.count', 2)
        ->where('kpis.sales.period.total', '2000.00')
        ->has('salesTrend', 7)
        ->where('salesTrend.6.date', '2026-06-30')
    );

    $tenant->delete();
});
