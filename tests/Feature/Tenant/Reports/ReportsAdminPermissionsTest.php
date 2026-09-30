<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Data-driven route enforcement for the reports and admin route groups
 * (ROUTE-MAP.md rows). One representative GET per permission key; the
 * per-route wiring itself is covered by the route audit test.
 *
 * @return array<string, array{0: string, 1: string, 2: string|null}>
 */
dataset('reports and admin routes', [
    'trial balance' => ['/reports/trial-balance', 'financial_statements.view', 'reports'],
    'income statement' => ['/reports/income-statement', 'financial_statements.view', 'reports'],
    'balance sheet' => ['/reports/balance-sheet', 'financial_statements.view', 'reports'],
    'day book' => ['/reports/day-book', 'day_book.view', 'reports'],
    'cash book' => ['/reports/cash-book', 'cash_bank_book.view', 'reports'],
    'bank book' => ['/reports/bank-book', 'cash_bank_book.view', 'reports'],
    'cancelled documents' => ['/reports/cancelled-documents', 'cancelled_documents.view', 'reports'],
    'sales register' => ['/reports/sales-register', 'sales_reports.view', 'reports'],
    'purchase register' => ['/reports/purchase-register', 'purchase_reports.view', 'reports'],
    'sales vat book' => ['/reports/sales-vat-book', 'tax_reports.view', 'reports'],
    'tds report' => ['/reports/tds', 'tax_reports.view', 'reports'],
    'aged receivables' => ['/reports/aged-receivables', 'receivables_reports.view', 'reports'],
    'aged payables' => ['/reports/aged-payables', 'payables_reports.view', 'reports'],
    'stock summary' => ['/reports/stock-summary', 'stock_reports.view', 'reports'],
    'stock valuation' => ['/reports/stock-valuation', 'stock_valuation.view', 'reports'],
    'print log' => ['/reports/print-log', 'print_log.view', 'admin'],
    'activity log' => ['/activity-log', 'activity_log.view', 'admin'],
    'notices' => ['/notices', 'notices.manage', 'admin'],
    'users (core, cannot be switched off)' => ['/admin/users', 'users.manage', null],
]);

function signInAsReportsAdminTestUser(string $domain, string $email): void
{
    test()->post("http://{$domain}/logout");
    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

/**
 * @param  list<string>  $keys
 */
function reportsAdminTestRoleUser(string $email, array $keys): void
{
    $user = userWithPermissions($keys);
    $user->forceFill(['email' => $email])->save();
}

it('allows the key holder and the owner, and denies everyone else with 403', function (string $uri, string $key, ?string $module) {
    $domain = 'reports-admin-'.Str::slug($uri).'.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () use ($key) {
        User::factory()->create(['email' => 'owner@example.com']);
        reportsAdminTestRoleUser('with-key@example.com', [$key]);
        reportsAdminTestRoleUser('without-key@example.com', ['customers.view']);
    });

    signInAsReportsAdminTestUser($domain, 'with-key@example.com');
    expect($this->get("http://{$domain}{$uri}")->status())->not->toBe(403);

    signInAsReportsAdminTestUser($domain, 'without-key@example.com');
    $this->get("http://{$domain}{$uri}")->assertForbidden();

    signInAsReportsAdminTestUser($domain, 'owner@example.com');
    expect($this->get("http://{$domain}{$uri}")->status())->not->toBe(403);

    $tenant->delete();
})->with('reports and admin routes');

it('denies even the owner and a key holder when the module is switched off', function (string $uri, string $key, ?string $module) {
    if ($module === null) {
        // Core routes cannot be switched off, so there is nothing to deny.
        expect(PermissionCatalog::modules()['core']['always_on'] ?? null)->toBeTrue();

        return;
    }

    $domain = 'reports-admin-off-'.Str::slug($uri).'.tenant-test';
    $tenant = Tenant::create([
        'company_name' => 'Acme Co',
        'enabled_modules' => array_values(array_diff(array_keys(PermissionCatalog::modules()), [$module])),
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () use ($key) {
        User::factory()->create(['email' => 'owner@example.com']);
        reportsAdminTestRoleUser('with-key@example.com', [$key]);
    });

    signInAsReportsAdminTestUser($domain, 'owner@example.com');
    $this->get("http://{$domain}{$uri}")->assertForbidden();

    signInAsReportsAdminTestUser($domain, 'with-key@example.com');
    $this->get("http://{$domain}{$uri}")->assertForbidden();

    $tenant->delete();
})->with('reports and admin routes');

it('gates print and export separately from view', function () {
    $domain = 'reports-admin-split.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        reportsAdminTestRoleUser('viewer@example.com', ['financial_statements.view', 'day_book.view', 'tax_reports.view', 'cancelled_documents.view', 'receivables_reports.view']);
    });

    signInAsReportsAdminTestUser($domain, 'viewer@example.com');

    foreach ([
        '/reports/trial-balance/print', '/reports/trial-balance/export',
        '/reports/day-book/print', '/reports/day-book/export',
        '/reports/sales-vat-book/export', '/reports/cancelled-documents/export',
        '/reports/debtors/export',
    ] as $uri) {
        $this->get("http://{$domain}{$uri}")->assertForbidden();
    }

    $tenant->delete();
});

it('keeps backups owner-only: the owner passes, a non-owner never does, whatever the role JSON says', function () {
    $domain = 'reports-admin-backups.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        reportsAdminTestRoleUser('granted@example.com', [...PermissionCatalog::grantable(), 'backups.manage']);
    });

    signInAsReportsAdminTestUser($domain, 'granted@example.com');
    $this->get("http://{$domain}/backups")->assertForbidden();
    $this->post("http://{$domain}/backups")->assertForbidden();

    signInAsReportsAdminTestUser($domain, 'owner@example.com');
    $this->get("http://{$domain}/backups")->assertOk();

    $tenant->delete();
});

it('denies the owner backups when the admin module is off', function () {
    $domain = 'reports-admin-backups-off.tenant-test';
    $tenant = Tenant::create([
        'company_name' => 'Acme Co',
        'enabled_modules' => ['sales', 'reports'],
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(fn () => User::factory()->create(['email' => 'owner@example.com']));

    signInAsReportsAdminTestUser($domain, 'owner@example.com');
    $this->get("http://{$domain}/backups")->assertForbidden();

    $tenant->delete();
});
