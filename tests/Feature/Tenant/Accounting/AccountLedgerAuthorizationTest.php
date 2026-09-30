<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\Agent;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
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
 * A tenant with an open fiscal year and one account of each kind the ledger
 * gate distinguishes: a customer's, a supplier's, an agent's and a plain
 * (cash) account.
 *
 * @return array{0: Tenant, 1: string, 2: array{customer: int, supplier: int, agent: int, cash: int}}
 */
function provisionLedgerAuthorizationTenant(): array
{
    $domain = 'ledger-auth-'.Str::lower(Str::random(8)).'.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Ledger Auth Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $accounts = $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        return [
            'customer' => Customer::factory()->create()->account_id,
            'supplier' => Supplier::factory()->create()->account_id,
            'agent' => Agent::factory()->create()->account_id,
            'cash' => Account::where('code', 'AS1')->value('id'),
        ];
    });

    return [$tenant, $domain, $accounts];
}

/**
 * @param  array<int, string>|null  $keys  null creates the owner
 */
function ledgerAuthorizationUser(Tenant $tenant, ?array $keys): User
{
    return $tenant->run(fn () => $keys === null ? User::factory()->create() : userWithPermissions($keys));
}

/**
 * Status code of one ledger route ('' = view, 'print', 'export') for an
 * account. Ends tenancy first so the domain middleware resolves a fresh
 * tenant: initialize() is a no-op for an already initialized tenant, which
 * would otherwise keep serving stale enabled_modules.
 */
function ledgerRouteStatus(string $domain, int $accountId, string $variant = ''): int
{
    tenancy()->end();

    $suffix = $variant === '' ? '' : "/{$variant}";

    return test()->get("http://{$domain}/accounts/{$accountId}/ledger{$suffix}")->getStatusCode();
}

test('account_ledger.view opens any account ledger, party or not', function () {
    [$tenant, $domain, $accounts] = provisionLedgerAuthorizationTenant();

    test()->actingAs(ledgerAuthorizationUser($tenant, ['account_ledger.view']), 'web');

    foreach ($accounts as $accountId) {
        expect(ledgerRouteStatus($domain, $accountId))->toBe(200);
    }

    $tenant->delete();
});

test('party_ledger.view opens only customer and supplier accounts, never agent or plain accounts', function () {
    [$tenant, $domain, $accounts] = provisionLedgerAuthorizationTenant();

    test()->actingAs(ledgerAuthorizationUser($tenant, ['party_ledger.view']), 'web');

    expect(ledgerRouteStatus($domain, $accounts['customer']))->toBe(200)
        ->and(ledgerRouteStatus($domain, $accounts['supplier']))->toBe(200)
        ->and(ledgerRouteStatus($domain, $accounts['agent']))->toBe(403)
        ->and(ledgerRouteStatus($domain, $accounts['cash']))->toBe(403);

    $tenant->delete();
});

test('without either ledger key every account ledger is forbidden', function () {
    [$tenant, $domain, $accounts] = provisionLedgerAuthorizationTenant();

    test()->actingAs(ledgerAuthorizationUser($tenant, ['customers.view', 'suppliers.view', 'accounts.view']), 'web');

    foreach ($accounts as $accountId) {
        expect(ledgerRouteStatus($domain, $accountId))->toBe(403)
            ->and(ledgerRouteStatus($domain, $accountId, 'print'))->toBe(403)
            ->and(ledgerRouteStatus($domain, $accountId, 'export'))->toBe(403);
    }

    $tenant->delete();
});

test('print and export need their own action key, the view key is not enough', function () {
    [$tenant, $domain, $accounts] = provisionLedgerAuthorizationTenant();

    test()->actingAs(ledgerAuthorizationUser($tenant, ['account_ledger.view', 'party_ledger.view']), 'web');

    expect(ledgerRouteStatus($domain, $accounts['customer'], 'print'))->toBe(403)
        ->and(ledgerRouteStatus($domain, $accounts['customer'], 'export'))->toBe(403)
        ->and(ledgerRouteStatus($domain, $accounts['cash'], 'print'))->toBe(403)
        ->and(ledgerRouteStatus($domain, $accounts['cash'], 'export'))->toBe(403);

    test()->actingAs(ledgerAuthorizationUser($tenant, ['party_ledger.print', 'party_ledger.export']), 'web');

    expect(ledgerRouteStatus($domain, $accounts['customer'], 'print'))->not->toBe(403)
        ->and(ledgerRouteStatus($domain, $accounts['supplier'], 'export'))->not->toBe(403)
        ->and(ledgerRouteStatus($domain, $accounts['cash'], 'print'))->toBe(403)
        ->and(ledgerRouteStatus($domain, $accounts['cash'], 'export'))->toBe(403);

    test()->actingAs(ledgerAuthorizationUser($tenant, ['account_ledger.print', 'account_ledger.export']), 'web');

    expect(ledgerRouteStatus($domain, $accounts['cash'], 'print'))->not->toBe(403)
        ->and(ledgerRouteStatus($domain, $accounts['cash'], 'export'))->not->toBe(403);

    $tenant->delete();
});

test('the ledger page tells the UI whether this account may be printed or exported', function () {
    [$tenant, $domain, $accounts] = provisionLedgerAuthorizationTenant();

    test()->actingAs(ledgerAuthorizationUser($tenant, ['party_ledger.view', 'party_ledger.print', 'account_ledger.view']), 'web');

    test()->get("http://{$domain}/accounts/{$accounts['customer']}/ledger")
        ->assertInertia(fn ($page) => $page->where('canPrint', true)->where('canExport', false));

    test()->get("http://{$domain}/accounts/{$accounts['cash']}/ledger")
        ->assertInertia(fn ($page) => $page->where('canPrint', false)->where('canExport', false));

    $tenant->delete();
});

test('with the accounting module off, party ledgers stay reachable and the full account ledger does not, even for the owner', function () {
    [$tenant, $domain, $accounts] = provisionLedgerAuthorizationTenant();

    $owner = ledgerAuthorizationUser($tenant, null);
    $bookkeeper = ledgerAuthorizationUser($tenant, ['account_ledger.view', 'party_ledger.view']);

    $tenant->update(['enabled_modules' => array_values(array_diff(config('permissions.default_modules'), ['accounting']))]);

    foreach ([$owner, $bookkeeper] as $user) {
        test()->actingAs($user, 'web');

        expect(ledgerRouteStatus($domain, $accounts['customer']))->toBe(200)
            ->and(ledgerRouteStatus($domain, $accounts['supplier']))->toBe(200)
            ->and(ledgerRouteStatus($domain, $accounts['agent']))->toBe(403)
            ->and(ledgerRouteStatus($domain, $accounts['cash']))->toBe(403);
    }

    $tenant->delete();
});

test('the owner opens every account ledger when accounting is entitled', function () {
    [$tenant, $domain, $accounts] = provisionLedgerAuthorizationTenant();

    test()->actingAs(ledgerAuthorizationUser($tenant, null), 'web');

    foreach ($accounts as $accountId) {
        expect(ledgerRouteStatus($domain, $accountId))->toBe(200)
            ->and(ledgerRouteStatus($domain, $accountId, 'export'))->not->toBe(403);
    }

    $tenant->delete();
});
