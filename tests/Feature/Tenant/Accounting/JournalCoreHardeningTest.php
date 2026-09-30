<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Flags G-06 (a cancel after the open year's last day is dated that day,
 * decision D1), G-19 (system vouchers cannot be reversed) and G-20 (line
 * amount and narration caps, one line per account on a manual journal).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionJournalHardeningTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function journalHardeningAdmin(): User
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function journalHardeningLines(string $amount, ?string $narration = null): array
{
    return [
        ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => $amount, 'credit' => 0, 'narration' => $narration],
        ['account_id' => Account::where('code', 'INI30')->value('id'), 'debit' => 0, 'credit' => $amount],
    ];
}

test('a sale cancelled after the open year has ended is reversed on the year\'s last day, and the message says so', function () {
    $domain = 'reversal-clamped.tenant-test';
    $tenant = provisionJournalHardeningTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $admin = journalHardeningAdmin();
        $saleId = Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'invoice_type' => 'full', 'date' => '2026-12-20', 'payment_mode' => 'cash'],
            [['item_id' => Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        )->id;
    });

    // FY1 ended on the 31st but nobody has closed it yet.
    $this->travelTo(now()->setDate(2027, 1, 5));

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);
    $this->post("http://{$domain}/sales/{$saleId}/cancel", ['reason' => 'Entered twice'])->assertSessionHasNoErrors();

    $this->get("http://{$domain}/sales")
        ->assertInertia(fn ($page) => $page->where('flash.status', fn (string $status) => str_contains($status, 'dated 2026-12-31')));

    $tenant->run(function () use ($saleId) {
        $sale = Sale::findOrFail($saleId);
        $reversal = JournalVoucher::where('reversal_of_id', $sale->journal_voucher_id)->firstOrFail();

        expect($sale->status)->toBe('cancelled')
            ->and($reversal->date->toDateString())->toBe('2026-12-31');
    });

    $this->travelBack();
    $tenant->delete();
});

test('a reversal dated inside the year is still dated today', function () {
    $tenant = provisionJournalHardeningTenant('reversal-today.tenant-test');

    $tenant->run(function () {
        $admin = journalHardeningAdmin();
        $this->travelTo(now()->setDate(2026, 6, 15));
        $voucher = JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Test'], journalHardeningLines('100'), $admin);

        expect(JournalVoucher::reverse($voucher, $admin, 'Undo')->date->toDateString())->toBe('2026-06-15');
        $this->travelBack();
    });

    $tenant->delete();
});

test('a reversal, closing entry or roll-forward adjustment cannot be reversed without the internal flag', function () {
    $tenant = provisionJournalHardeningTenant('reversal-system-types.tenant-test');

    $tenant->run(function () {
        $admin = journalHardeningAdmin();
        $this->travelTo(now()->setDate(2026, 6, 15));

        $original = JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Test'], journalHardeningLines('100'), $admin);
        $reversal = JournalVoucher::reverse($original, $admin, 'Undo');

        expect(fn () => JournalVoucher::reverse($reversal, $admin, 'Undo the undo'))
            ->toThrow(InvalidArgumentException::class, 'cannot be reversed');

        foreach ([VoucherType::ClosingEntry, VoucherType::RollForwardAdjustment] as $type) {
            $system = JournalVoucher::post(['voucher_type' => $type->value, 'date' => '2026-06-02', 'narration' => 'System'], journalHardeningLines('50'), $admin);

            expect(fn () => JournalVoucher::reverse($system, $admin, 'No'))->toThrow(InvalidArgumentException::class, 'cannot be reversed');
        }

        // Internal year-end code can still do it, and has to ask.
        expect(JournalVoucher::reverse($reversal, $admin, 'Internal', allowSystemVoucher: true)->reversal_of_id)->toBe($reversal->id);

        $this->travelBack();
    });

    $tenant->delete();
});

test('a line amount beyond the ledger column or a narration over 255 characters is refused', function () {
    $tenant = provisionJournalHardeningTenant('journal-line-caps.tenant-test');

    $tenant->run(function () {
        $admin = journalHardeningAdmin();

        expect(fn () => JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Huge'], journalHardeningLines('1000000000000000000.00'), $admin))
            ->toThrow(InvalidArgumentException::class, 'larger than the ledger can hold')
            ->and(fn () => JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Long'], journalHardeningLines('10', str_repeat('x', 256)), $admin))
            ->toThrow(InvalidArgumentException::class, '255 characters');

        expect(JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Edge'], journalHardeningLines(JournalVoucher::MAX_LINE_AMOUNT, str_repeat('x', 255)), $admin)->exists)->toBeTrue();
    });

    $tenant->delete();
});

test('a manual journal cannot name the same account on two lines', function () {
    $domain = 'journal-distinct-accounts.tenant-test';
    $tenant = provisionJournalHardeningTenant($domain);

    $cashId = null;
    $tenant->run(function () use (&$cashId) {
        journalHardeningAdmin();
        $cashId = Account::where('code', 'AS1')->value('id');
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-01',
        'narration' => 'Same account twice',
        'lines' => [
            ['account_id' => $cashId, 'debit' => 100, 'credit' => 0],
            ['account_id' => $cashId, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('lines.1.account_id');

    $tenant->delete();
});
