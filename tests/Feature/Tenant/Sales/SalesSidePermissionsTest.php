<?php

use App\Enums\FiscalYearStatus;
use App\Enums\QuotationStatus;
use App\Models\Account;
use App\Models\CapitalSale;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
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
 * Route groups of the sales side chunk (todo/permissions/ROUTE-MAP.md, P05):
 * sales, capital sales, sales returns, receipts, POS, agents and quotations.
 * Index pages, bodiless stores and one POST per cancel key; print, export,
 * update and destroy share their key family with a row here and are covered
 * by the route audit test.
 *
 * Stores and cancels are sent with an empty body on purpose: the `can:`
 * middleware runs before validation, so an allowed user gets a validation
 * redirect (nothing is posted or reversed) and a denied one gets 403. Bound
 * routes need a real row, because route model binding runs before `can:` and
 * a missing id would 404 for everyone.
 *
 * Row shape: [route name, method, permission key, module, fixture or null,
 * extra keys the allowed user also needs].
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: list<string>}>
 */
function salesSideRouteRows(): array
{
    $rows = [
        ['tenant.sales.index', 'get', 'sales.view', 'sales', null, []],
        ['tenant.sales.store', 'post', 'sales.create', 'sales', null, []],
        ['tenant.sales.cancel', 'post', 'sales.cancel', 'sales', 'sale', []],
        ['tenant.capital-sales.index', 'get', 'capital_sales.view', 'sales', null, []],
        ['tenant.capital-sales.store', 'post', 'capital_sales.create', 'sales', null, []],
        ['tenant.capital-sales.cancel', 'post', 'capital_sales.cancel', 'sales', 'capital_sale', []],
        ['tenant.sales-returns.index', 'get', 'sales_returns.view', 'sales', null, []],
        ['tenant.sales-returns.store', 'post', 'sales_returns.create', 'sales', null, []],
        ['tenant.sales-returns.store-unlinked', 'post', 'unlinked_sales_returns.create', 'sales', null, []],
        ['tenant.sales-returns.request', 'post', 'sales_return_requests.create', 'sales', null, []],
        ['tenant.sales-returns.reject', 'post', 'sales_return_requests.manage', 'sales', 'pending_return', []],
        ['tenant.sales-returns.cancel', 'post', 'sales_returns.cancel', 'sales', 'sales_return', []],
        ['tenant.receipts.index', 'get', 'receipts.view', 'sales', null, []],
        ['tenant.receipts.store', 'post', 'receipts.create', 'sales', null, []],
        ['tenant.receipts.cancel', 'post', 'receipts.cancel', 'sales', 'receipt', []],
        ['tenant.pos.index', 'get', 'pos.view', 'pos', null, []],
        ['tenant.agents.index', 'get', 'agents.view', 'agents', null, []],
        ['tenant.agents.store', 'post', 'agents.create', 'agents', null, []],
        ['tenant.quotations.index', 'get', 'quotations.view', 'quotations', null, []],
        ['tenant.quotations.store', 'post', 'quotations.create', 'quotations', null, []],
        ['tenant.quotations.cancel', 'post', 'quotations.cancel', 'quotations', 'quotation', []],
        // Converting posts a sale, so the controller also demands sales.create.
        ['tenant.quotations.convert-to-sale', 'post', 'quotations.edit', 'quotations', 'quotation', ['sales.create']],
    ];

    $named = [];
    foreach ($rows as $row) {
        $named[$row[0]] = $row;
    }

    return $named;
}

/**
 * @return array{0: Tenant, 1: string}
 */
function provisionSalesSidePermissionTenant(): array
{
    $domain = 'sales-perm-'.Str::lower(Str::random(8)).'.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Sales Perm Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return [$tenant, $domain];
}

/**
 * @param  array<int, string>|null  $keys  null creates the owner
 */
function salesSidePermissionUser(Tenant $tenant, ?array $keys): User
{
    return $tenant->run(fn () => $keys === null ? User::factory()->create() : userWithPermissions($keys));
}

/**
 * A draft quotation with one non-stock, non-VAT line, its totals stored the
 * way QuotationController::store() stores them.
 */
function salesSideQuotation(User $owner, Customer $customer, Item $item): Quotation
{
    $lines = [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100', 'discount' => '0']];
    $header = ['discount' => '0', 'vat_rate' => '13'];

    $quotation = Quotation::create([
        'customer_id' => $customer->id,
        'date' => '2026-06-01',
        'discount' => $header['discount'],
        'vat_rate' => $header['vat_rate'],
        ...Quotation::storedTotals(Quotation::calculateTotals($lines, $header)),
        'status' => QuotationStatus::Draft,
        'created_by' => $owner->id,
    ]);

    foreach ($lines as $line) {
        $quotation->lines()->create($line);
    }

    return $quotation;
}

/**
 * Creates the row a bound route needs and returns its id. Everything is
 * posted by the owner, so the users under test never author a document.
 */
function salesSideFixture(Tenant $tenant, User $owner, string $fixture): int
{
    return $tenant->run(function () use ($owner, $fixture): int {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $postSale = fn (): Sale => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $owner,
        );

        $returnLines = fn (Sale $sale): array => [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => 1]];

        return match ($fixture) {
            'sale' => $postSale()->id,
            'capital_sale' => CapitalSale::post(
                ['date' => '2026-06-01', 'payment_mode' => 'cash'],
                [['account_id' => Account::factory()->create()->id, 'amount' => '1000', 'vatable' => true]],
                $owner,
            )->id,
            'sales_return' => (function () use ($postSale, $returnLines, $owner): int {
                $sale = $postSale();

                return SalesReturn::post(['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null], $returnLines($sale), $owner)->id;
            })(),
            'pending_return' => (function () use ($postSale, $returnLines, $owner): int {
                $sale = $postSale();

                return SalesReturn::request(['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null], $returnLines($sale), $owner)->id;
            })(),
            'receipt' => Receipt::post(
                ['customer_id' => $customer->id, 'date' => '2026-06-10', 'amount' => '250.00', 'payment_mode' => 'cash'],
                $owner,
            )->id,
            'quotation' => salesSideQuotation($owner, $customer, $item)->id,
        };
    });
}

/**
 * Every default module except the given one and the modules that require it:
 * pos, agents and quotations require sales, so leaving any of them on would
 * switch sales straight back on through dependency resolution.
 *
 * @return list<string>
 */
function salesSideModulesWithout(string $module): array
{
    return array_values(array_filter(
        config('permissions.default_modules'),
        fn (string $candidate): bool => ! in_array($module, PermissionCatalog::resolveModules([$candidate]), true),
    ));
}

/**
 * Ends tenancy first so the domain middleware resolves a fresh tenant
 * instance: tenancy()->initialize() is a no-op for an already initialized
 * tenant, which would otherwise keep serving stale enabled_modules.
 *
 * @param  array<int, int>  $parameters
 */
function callSalesSideRoute(string $method, string $domain, string $routeName, array $parameters): int
{
    tenancy()->end();

    $url = "http://{$domain}".route($routeName, $parameters, false);

    return test()->{$method}($url)->getStatusCode();
}

it('gates each sales side route by its permission key and module', function (string $routeName, string $method, string $key, string $module, ?string $fixture, array $extraKeys) {
    [$tenant, $domain] = provisionSalesSidePermissionTenant();

    $owner = salesSidePermissionUser($tenant, null);
    $granted = salesSidePermissionUser($tenant, [$key, ...$extraKeys]);
    $denied = salesSidePermissionUser($tenant, array_values(array_diff(PermissionCatalog::grantable(), [$key])));
    $parameters = $fixture === null ? [] : [salesSideFixture($tenant, $owner, $fixture)];

    // Checks that cannot change data run first: an allowed cancel or convert
    // below may act on the fixture.

    // 403 without the key, even holding every other grantable key.
    test()->actingAs($denied, 'web');
    expect(callSalesSideRoute($method, $domain, $routeName, $parameters))->toBe(403);

    // A disabled module blocks even the owner and a role that holds the key.
    $tenant->update(['enabled_modules' => salesSideModulesWithout($module)]);
    test()->actingAs($owner, 'web');
    expect(callSalesSideRoute($method, $domain, $routeName, $parameters))->toBe(403);
    test()->actingAs($granted, 'web');
    expect(callSalesSideRoute($method, $domain, $routeName, $parameters))->toBe(403);

    // Entitled again (role grants are untouched by the toggle).
    $tenant->update(['enabled_modules' => config('permissions.default_modules')]);

    // Allowed with the key.
    test()->actingAs($granted, 'web');
    expect(callSalesSideRoute($method, $domain, $routeName, $parameters))->not->toBe(403);

    // The owner is allowed when entitled.
    test()->actingAs($owner, 'web');
    expect(callSalesSideRoute($method, $domain, $routeName, $parameters))->not->toBe(403);

    $tenant->delete();
})->with(fn () => salesSideRouteRows());

test('converting a quotation to a sale also requires sales.create', function () {
    [$tenant, $domain] = provisionSalesSidePermissionTenant();

    $owner = salesSidePermissionUser($tenant, null);
    $quotationOnly = salesSidePermissionUser($tenant, ['quotations.view', 'quotations.edit']);
    $seller = salesSidePermissionUser($tenant, ['quotations.view', 'quotations.edit', 'sales.create']);
    $quotationId = salesSideFixture($tenant, $owner, 'quotation');

    // Passes the route's quotations.edit gate, refused by the controller.
    test()->actingAs($quotationOnly, 'web');
    expect(callSalesSideRoute('post', $domain, 'tenant.quotations.convert-to-sale', [$quotationId]))->toBe(403);

    $tenant->run(function () use ($quotationId) {
        $quotation = Quotation::findOrFail($quotationId);

        expect($quotation->status)->toBe(QuotationStatus::Draft)
            ->and($quotation->sale_id)->toBeNull()
            ->and(Sale::count())->toBe(0);
    });

    test()->actingAs($seller, 'web');
    test()->post("http://{$domain}".route('tenant.quotations.convert-to-sale', [$quotationId], false))
        ->assertRedirect();

    $tenant->run(function () use ($quotationId) {
        expect(Quotation::findOrFail($quotationId)->status)->toBe(QuotationStatus::Converted);
    });

    $tenant->delete();
});
