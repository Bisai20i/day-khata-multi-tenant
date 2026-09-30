<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Tenancy stays initialized after an HTTP call inside one test process, so
 * revert to the central connection for RefreshDatabase's teardown.
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * Route groups of the purchase and accounting chunk (todo/permissions/
 * ROUTE-MAP.md, P06): [route name, method, permission key, module].
 *
 * Routes without model binding are listed as-is. The two bound journal
 * voucher routes take the fixture voucher from provisionPurchaseAccountingTenant().
 * The account ledger routes are controller-authorized (see
 * AccountLedgerAuthorizationTest) and the owner-only fiscal year routes have
 * their own test below. Other bound routes (purchase, return, payment and
 * settlement cancel/print, fixed asset dispose, archive browsing) share their
 * key with a row here, or are covered by their feature tests and the route
 * audit test.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
 */
function purchaseAccountingRouteRows(): array
{
    $rows = [
        ['tenant.purchases.index', 'get', 'purchases.view', 'purchases'],
        ['tenant.purchases.store', 'post', 'purchases.create', 'purchases'],
        ['tenant.purchases.export', 'get', 'purchases.export', 'purchases'],
        ['tenant.capital-purchases.index', 'get', 'capital_purchases.view', 'purchases'],
        ['tenant.capital-purchases.store', 'post', 'capital_purchases.create', 'purchases'],
        ['tenant.capital-purchases.export', 'get', 'capital_purchases.export', 'purchases'],
        ['tenant.purchase-returns.index', 'get', 'purchase_returns.view', 'purchases'],
        ['tenant.purchase-returns.store', 'post', 'purchase_returns.create', 'purchases'],
        ['tenant.purchase-returns.store-unlinked', 'post', 'unlinked_purchase_returns.create', 'purchases'],
        ['tenant.purchase-returns.quote-unlinked', 'get', 'unlinked_purchase_returns.create', 'purchases'],
        ['tenant.purchase-returns.export', 'get', 'purchase_returns.export', 'purchases'],
        ['tenant.payments.index', 'get', 'payments.view', 'purchases'],
        ['tenant.payments.store', 'post', 'payments.create', 'purchases'],
        ['tenant.fiscal-years.index', 'get', 'fiscal_year.view', 'core'],
        ['tenant.fiscal-years.store', 'post', 'fiscal_year.create', 'core'],
        ['tenant.journal-vouchers.index', 'get', 'journal_vouchers.view', 'accounting'],
        ['tenant.journal-vouchers.export', 'get', 'journal_vouchers.export', 'accounting'],
        ['tenant.journal-vouchers.store', 'post', 'journal_vouchers.create', 'accounting'],
        ['tenant.journal-vouchers.cash-bank.store', 'post', 'cash_bank_vouchers.create', 'accounting'],
        ['tenant.journal-vouchers.print', 'get', 'journal_vouchers.print', 'accounting'],
        ['tenant.journal-vouchers.cancel', 'post', 'journal_vouchers.cancel', 'accounting'],
        ['tenant.fixed-assets.index', 'get', 'fixed_assets.view', 'accounting'],
        ['tenant.fixed-assets.store', 'post', 'fixed_assets.create', 'accounting'],
        ['tenant.fixed-assets.store-existing', 'post', 'fixed_assets.create', 'accounting'],
        ['tenant.fixed-assets.post-depreciation', 'post', 'fixed_assets.manage', 'accounting'],
    ];

    $named = [];
    foreach ($rows as $row) {
        $named[$row[0]] = $row;
    }

    return $named;
}

/**
 * A tenant with an open fiscal year, a closed one before it and one posted
 * journal voucher, so the bound routes resolve their models.
 *
 * @return array{0: Tenant, 1: string, 2: array{openYear: int, closedYear: int, voucher: int}}
 */
function provisionPurchaseAccountingTenant(): array
{
    $domain = 'purchase-acct-perm-'.Str::lower(Str::random(8)).'.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Purchase Accounting Perm Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $fixtures = $tenant->run(function () {
        $closedYear = FiscalYear::create(['name' => 'FY0', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => FiscalYearStatus::Closed]);
        $openYear = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $voucher = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Permission fixture'],
            [
                ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => 100, 'credit' => 0],
                ['account_id' => Account::where('code', 'INI20')->value('id'), 'debit' => 0, 'credit' => 100],
            ],
            User::factory()->create(),
        );

        return ['openYear' => $openYear->id, 'closedYear' => $closedYear->id, 'voucher' => $voucher->id];
    });

    return [$tenant, $domain, $fixtures];
}

/**
 * @param  array<int, string>|null  $keys  null creates the owner
 */
function purchaseAccountingUser(Tenant $tenant, ?array $keys): User
{
    return $tenant->run(fn () => $keys === null ? User::factory()->create() : userWithPermissions($keys));
}

/**
 * Ends tenancy first so the domain middleware resolves a fresh tenant
 * instance: tenancy()->initialize() is a no-op for an already initialized
 * tenant, which would otherwise keep serving stale enabled_modules. Bound
 * route parameters are filled from the fixtures (no request body is sent,
 * so an allowed POST stops at validation and changes nothing).
 *
 * @param  array{openYear: int, closedYear: int, voucher: int}  $fixtures
 */
function callPurchaseAccountingRoute(string $method, string $domain, string $routeName, array $fixtures): int
{
    tenancy()->end();

    $parameters = match ($routeName) {
        'tenant.journal-vouchers.print', 'tenant.journal-vouchers.cancel' => ['journalVoucher' => $fixtures['voucher']],
        'tenant.fiscal-years.close', 'tenant.fiscal-years.archive' => ['fiscalYear' => $fixtures['openYear']],
        'tenant.fiscal-years.reopen', 'tenant.fiscal-years.lock' => ['fiscalYear' => $fixtures['closedYear']],
        default => [],
    };

    $url = "http://{$domain}".route($routeName, $parameters, false);

    return test()->{$method}($url)->getStatusCode();
}

it('gates each purchase and accounting route by its permission key and module', function (string $routeName, string $method, string $key, string $module) {
    [$tenant, $domain, $fixtures] = provisionPurchaseAccountingTenant();

    $owner = purchaseAccountingUser($tenant, null);
    $granted = purchaseAccountingUser($tenant, [$key]);
    $denied = purchaseAccountingUser($tenant, array_values(array_diff(PermissionCatalog::grantable(), [$key])));

    // Owner allowed when entitled (default modules include purchases and accounting).
    test()->actingAs($owner, 'web');
    expect(callPurchaseAccountingRoute($method, $domain, $routeName, $fixtures))->not->toBe(403);

    // Allowed with the key.
    test()->actingAs($granted, 'web');
    expect(callPurchaseAccountingRoute($method, $domain, $routeName, $fixtures))->not->toBe(403);

    // 403 without the key, even holding every other grantable key.
    test()->actingAs($denied, 'web');
    expect(callPurchaseAccountingRoute($method, $domain, $routeName, $fixtures))->toBe(403);

    if ($module === 'core') {
        // Fiscal years are core: still reachable for the owner with every optional module off.
        $tenant->update(['enabled_modules' => []]);
        test()->actingAs($owner, 'web');
        expect(callPurchaseAccountingRoute($method, $domain, $routeName, $fixtures))->not->toBe(403);
    } else {
        // A disabled module blocks even the owner and a role that holds the key.
        $tenant->update(['enabled_modules' => array_values(array_diff(config('permissions.default_modules'), [$module]))]);
        test()->actingAs($owner, 'web');
        expect(callPurchaseAccountingRoute($method, $domain, $routeName, $fixtures))->toBe(403);
        test()->actingAs($granted, 'web');
        expect(callPurchaseAccountingRoute($method, $domain, $routeName, $fixtures))->toBe(403);
    }

    $tenant->delete();
})->with(fn () => purchaseAccountingRouteRows());

it('admits only the owner to close, reopen, relock and archive a fiscal year', function (string $routeName) {
    [$tenant, $domain, $fixtures] = provisionPurchaseAccountingTenant();

    $owner = purchaseAccountingUser($tenant, null);
    // Every grantable key plus the owner-only one written into the role
    // JSON: fiscal_year.close_archive is still refused to a non-owner.
    $everything = purchaseAccountingUser($tenant, [...PermissionCatalog::grantable(), 'fiscal_year.close_archive']);

    test()->actingAs($owner, 'web');
    expect(callPurchaseAccountingRoute('post', $domain, $routeName, $fixtures))->not->toBe(403);

    test()->actingAs($everything, 'web');
    expect(callPurchaseAccountingRoute('post', $domain, $routeName, $fixtures))->toBe(403);

    // The key is core: the owner keeps it with every optional module off.
    $tenant->update(['enabled_modules' => []]);
    test()->actingAs($owner, 'web');
    expect(callPurchaseAccountingRoute('post', $domain, $routeName, $fixtures))->not->toBe(403);

    $tenant->delete();
})->with([
    'close' => ['tenant.fiscal-years.close'],
    'reopen' => ['tenant.fiscal-years.reopen'],
    'relock' => ['tenant.fiscal-years.lock'],
    'archive' => ['tenant.fiscal-years.archive'],
]);
