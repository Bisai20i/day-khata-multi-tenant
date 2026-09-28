<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Exports\JournalVoucherListExport;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Covers the server-side filters, search, sort, pagination and export added
 * to JournalVoucherController::index()/export() so the Journal Vouchers list
 * matches the sales list, and the `created` flash the create forms' "Save &
 * Print" relies on.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Tenant with two journal vouchers (JV-1 "Office rent", JV-2 "Bank interest")
 * and one cash receipt (CR-1 "Scrap sale"), logged in as an admin.
 *
 * @return array{0: Tenant, 1: array{cash: int, sales: int}}
 */
function provisionJournalVoucherListTenant(string $domain): array
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $accounts = [];
    $tenant->run(function () use (&$accounts) {
        $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $accounts = [
            'cash' => Account::where('code', 'AS1')->value('id'),
            'sales' => Account::where('code', 'INI20')->value('id'),
        ];

        foreach ([['2026-06-01', 'Office rent', 500], ['2026-06-05', 'Bank interest', 75]] as [$date, $narration, $amount]) {
            JournalVoucher::post(['date' => $date, 'narration' => $narration], [
                ['account_id' => $accounts['cash'], 'debit' => $amount, 'credit' => 0],
                ['account_id' => $accounts['sales'], 'debit' => 0, 'credit' => $amount],
            ], $admin);
        }

        JournalVoucher::postCashBank([
            'voucher_type' => 'cash_receipt',
            'date' => '2026-06-03',
            'narration' => 'Scrap sale',
            'lines' => [['account_id' => $accounts['sales'], 'amount' => 40]],
        ], $admin);
    });

    test()->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    return [$tenant, $accounts];
}

test('the journal vouchers list is paginated server-side, newest first by default', function () {
    $domain = 'jv-list-default.tenant-test';
    [$tenant] = provisionJournalVoucherListTenant($domain);

    $this->get("http://{$domain}/journal-vouchers")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Accounting/JournalVouchers/Index')
            ->where('journalVouchers.total', 3)
            ->where('journalVouchers.per_page', 25)
            ->where('journalVouchers.data.0.narration', 'Bank interest')
            ->where('filters.sort', 'date')
            ->where('filters.sort_dir', 'desc'));

    $tenant->delete();
});

test('the voucher type and date filters narrow the list', function () {
    $domain = 'jv-list-type.tenant-test';
    [$tenant] = provisionJournalVoucherListTenant($domain);

    $this->get("http://{$domain}/journal-vouchers?".http_build_query(['voucher_type' => 'journal', 'from' => '2026-06-02']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('journalVouchers.total', 1)
            ->where('journalVouchers.data.0.narration', 'Bank interest')
            ->where('filters.voucher_type', 'journal'));

    $this->get("http://{$domain}/journal-vouchers?".http_build_query(['voucher_type' => 'not-a-type']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('journalVouchers.total', 3)
            ->where('filters.voucher_type', null));

    $tenant->delete();
});

test('the search matches a printed voucher number or any part of the narration', function () {
    $domain = 'jv-list-search.tenant-test';
    [$tenant] = provisionJournalVoucherListTenant($domain);

    $this->get("http://{$domain}/journal-vouchers?".http_build_query(['search' => 'rent']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('journalVouchers.total', 1)
            ->where('journalVouchers.data.0.narration', 'Office rent'));

    // "JV-2" matches voucher number 2 of any type; only the journal series
    // has reached 2 here.
    $this->get("http://{$domain}/journal-vouchers?".http_build_query(['search' => 'JV-2']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('journalVouchers.total', 1)
            ->where('journalVouchers.data.0.narration', 'Bank interest'));

    $tenant->delete();
});

test('sorting by voucher number ascending and an unknown sort column falls back to date', function () {
    $domain = 'jv-list-sort.tenant-test';
    [$tenant] = provisionJournalVoucherListTenant($domain);

    $this->get("http://{$domain}/journal-vouchers?".http_build_query(['voucher_type' => 'journal', 'sort' => 'voucher_number', 'sort_dir' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'voucher_number')
            ->where('journalVouchers.data.0.narration', 'Office rent'));

    $this->get("http://{$domain}/journal-vouchers?".http_build_query(['sort' => 'id; DROP TABLE journal_vouchers;']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.sort', 'date'));

    $tenant->delete();
});

test('the export covers the filtered set with each voucher amount, as xlsx or csv', function () {
    Excel::fake();

    $domain = 'jv-list-export.tenant-test';
    [$tenant] = provisionJournalVoucherListTenant($domain);

    $this->get("http://{$domain}/journal-vouchers/export?".http_build_query(['voucher_type' => 'journal']))->assertOk();

    Excel::assertDownloaded('journal-vouchers.xlsx', function (JournalVoucherListExport $export) {
        $rows = $export->collection();

        return $rows->count() === 2
            && $rows->first()['narration'] === 'Bank interest'
            && $rows->first()['amount'] === '75.00'
            && $rows->first()['voucher_type'] === VoucherType::Journal->value;
    });

    $this->get("http://{$domain}/journal-vouchers/export?format=csv")->assertOk();

    Excel::assertDownloaded('journal-vouchers.csv', fn (JournalVoucherListExport $export) => $export->collection()->count() === 3);

    $tenant->delete();
});

test('posting a journal or cash/bank voucher flashes the created voucher and its print url', function () {
    $domain = 'jv-created-flash.tenant-test';
    [$tenant, $accounts] = provisionJournalVoucherListTenant($domain);

    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-10',
        'narration' => 'Owner drawing',
        'lines' => [
            ['account_id' => $accounts['sales'], 'debit' => 20, 'credit' => 0],
            ['account_id' => $accounts['cash'], 'debit' => 0, 'credit' => 20],
        ],
    ])->assertRedirect();

    $journalId = null;
    $tenant->run(function () use (&$journalId) {
        $journalId = JournalVoucher::where('narration', 'Owner drawing')->value('id');
    });

    expect(session('created'))->toMatchArray([
        'type' => 'journal_voucher',
        'id' => $journalId,
    ])->and(session('created.print_url'))->toEndWith("/journal-vouchers/{$journalId}/print");

    $this->post("http://{$domain}/journal-vouchers/cash-bank", [
        'voucher_type' => 'cash_receipt',
        'date' => '2026-06-11',
        'narration' => 'Sold old boxes',
        'lines' => [['account_id' => $accounts['sales'], 'amount' => 15]],
    ])->assertRedirect();

    $cashReceiptId = null;
    $tenant->run(function () use (&$cashReceiptId) {
        $cashReceiptId = JournalVoucher::where('narration', 'Sold old boxes')->value('id');
    });

    expect(session('created.id'))->toBe($cashReceiptId)
        ->and(session('created.print_url'))->toEndWith("/journal-vouchers/{$cashReceiptId}/print");

    $tenant->delete();
});
