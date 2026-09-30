<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Flags G-10 and G-16: the unlinked purchase return form gets its total from
 * the server (a line with no rate is valued at a 12-decimal average cost the
 * browser cannot reproduce), sends it back as the now-required
 * `expected_total`, and the model compares it even when it is zero.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionUnlinkedQuoteTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginUnlinkedQuoteUser(string $domain, string $email = 'owner@example.com'): void
{
    test()->post("http://{$domain}/login", [
        'email' => $email,
        'password' => 'password',
    ]);
}

/**
 * 3 pieces at 100.00 then 4 at 101.11, so the weighted average cost is
 * 704.44 / 7 = 100.6342857..., a figure no 4-decimal rate reproduces.
 *
 * @return array{itemId: int, storeId: int}
 */
function seedUnlinkedQuoteStock(): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
    User::factory()->create(['email' => 'staff@example.com', 'role_id' => Role::where('slug', 'cashier')->value('id')]);
    $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
    $supplier = Supplier::factory()->create();

    $first = Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        [['item_id' => $item->id, 'quantity' => '3', 'rate' => '100']],
        $admin,
    );
    Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'payment_mode' => 'credit'],
        [['item_id' => $item->id, 'quantity' => '4', 'rate' => '101.11']],
        $admin,
    );

    return ['itemId' => $item->id, 'storeId' => $first->store_id];
}

test('the quote prices an average-cost line on the server and the same total posts cleanly', function () {
    $domain = 'unlinked-quote.tenant-test';
    $tenant = provisionUnlinkedQuoteTenant($domain);

    $seeded = null;
    $tenant->run(function () use (&$seeded) {
        $seeded = seedUnlinkedQuoteStock();
    });

    loginUnlinkedQuoteUser($domain);

    $query = http_build_query([
        'date' => '2026-06-10',
        'vat_rate' => '13',
        'lines' => [['item_id' => $seeded['itemId'], 'quantity' => '3']],
    ]);

    // 3 x 100.6342857... = 301.90 taxable, VAT 39.25 (301.90 x 13%), 341.15.
    $quote = $this->getJson("http://{$domain}/purchase-returns/unlinked/quote?{$query}")
        ->assertOk()
        ->assertJson([
            'taxable_amount' => '301.90',
            'nontaxable_amount' => '0.00',
            'vat_amount' => '39.25',
            'total' => '341.15',
            'lines' => ['301.90'],
        ])
        ->json();

    $this->post("http://{$domain}/purchase-returns/unlinked", [
        'date' => '2026-06-10',
        'vat_rate' => '13',
        'store_id' => $seeded['storeId'],
        'reason' => 'Opening stock returned',
        'payment_mode' => 'cash',
        'expected_total' => $quote['total'],
        'lines' => [['item_id' => $seeded['itemId'], 'quantity' => '3']],
    ])->assertSessionHasNoErrors();

    $tenant->run(function () {
        expect(PurchaseReturn::query()->where('is_unlinked', true)->value('total'))->toBe('341.15');
    });

    $tenant->delete();
});

test('the unlinked return refuses a save whose expected total is missing or differs, even when it is zero', function () {
    $domain = 'unlinked-quote-mismatch.tenant-test';
    $tenant = provisionUnlinkedQuoteTenant($domain);

    $seeded = null;
    $tenant->run(function () use (&$seeded) {
        $seeded = seedUnlinkedQuoteStock();
    });

    loginUnlinkedQuoteUser($domain);

    $payload = [
        'date' => '2026-06-10',
        'store_id' => $seeded['storeId'],
        'reason' => 'Opening stock returned',
        'payment_mode' => 'cash',
        'lines' => [['item_id' => $seeded['itemId'], 'quantity' => '3']],
    ];

    $this->post("http://{$domain}/purchase-returns/unlinked", $payload)->assertSessionHasErrors('expected_total');

    // "0" used to slip past an empty() check and skip the comparison.
    $this->post("http://{$domain}/purchase-returns/unlinked", [...$payload, 'expected_total' => '0'])->assertSessionHasErrors('lines');

    $tenant->run(function () {
        expect(PurchaseReturn::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('the quote is admin only, like the unlinked post it previews', function () {
    $domain = 'unlinked-quote-staff.tenant-test';
    $tenant = provisionUnlinkedQuoteTenant($domain);

    $seeded = null;
    $tenant->run(function () use (&$seeded) {
        $seeded = seedUnlinkedQuoteStock();
    });

    loginUnlinkedQuoteUser($domain, 'staff@example.com');

    $query = http_build_query(['date' => '2026-06-10', 'lines' => [['item_id' => $seeded['itemId'], 'quantity' => '1']]]);

    $this->getJson("http://{$domain}/purchase-returns/unlinked/quote?{$query}")->assertForbidden();

    $tenant->delete();
});

test('a purchase posted without the total the user saw is a validation error', function () {
    $domain = 'purchase-expected-total-required.tenant-test';
    $tenant = provisionUnlinkedQuoteTenant($domain);

    $seeded = null;
    $supplierId = null;
    $tenant->run(function () use (&$seeded, &$supplierId) {
        $seeded = seedUnlinkedQuoteStock();
        $supplierId = Supplier::query()->value('id');
    });

    loginUnlinkedQuoteUser($domain);

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $supplierId,
        'date' => '2026-06-05',
        'payment_mode' => 'cash',
        'lines' => [['item_id' => $seeded['itemId'], 'quantity' => 1, 'rate' => 100]],
    ])->assertSessionHasErrors('expected_total');

    $tenant->delete();
});
