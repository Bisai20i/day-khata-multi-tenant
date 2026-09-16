<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCancelledDocumentsTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('the cancelled documents report lists a cancelled payment and a cancelled standalone journal voucher, but not a payment\'s own backing voucher twice', function () {
    $domain = 'cancelled-docs-report.tenant-test';
    $tenant = provisionCancelledDocumentsTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $supplier = Supplier::factory()->create();

        $payment = Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-01',
            'amount' => 500,
            'payment_mode' => 'cash',
        ], $admin);

        $payment->cancel($admin, 'Wrong supplier');

        $sales = Account::where('code', 'INI20')->firstOrFail();
        $cash = Account::where('code', 'AS1')->firstOrFail();

        $standalone = JournalVoucher::post(
            ['date' => '2026-06-05', 'narration' => 'Odd entry'],
            [
                ['account_id' => $cash->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 100],
            ],
            $admin,
        );

        $standalone->cancel($admin, 'Duplicate entry');
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/reports/cancelled-documents")
        ->assertOk()
        ->assertInertia(function ($page) {
            $page->component('Tenant/Reports/CancelledDocuments');

            $rows = $page->toArray()['props']['rows'];
            $types = collect($rows)->pluck('type');

            expect($types)->toContain('Payment')
                ->and($types)->toContain('Journal')
                // A payment's own backing voucher is also cancelled underneath
                // it, but it must not appear a second time as a standalone
                // "Journal" row (JournalVoucher::sourceRecordLabel() is what
                // filters it out).
                ->and($rows)->toHaveCount(2);
        });

    $this->get("http://{$domain}/reports/cancelled-documents/export")->assertOk();

    $tenant->delete();
});

test('a staff user cannot reach the cancelled documents report', function () {
    $domain = 'cancelled-docs-role-gate.tenant-test';
    $tenant = provisionCancelledDocumentsTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'staff@example.com', 'role_id' => Role::where('slug', 'staff')->value('id')]);
    });

    $this->post("http://{$domain}/login", ['email' => 'staff@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/reports/cancelled-documents")->assertForbidden();

    $tenant->delete();
});
