<?php

use App\Enums\FiscalYearStatus;
use App\Exports\CapitalPurchaseListExport;
use App\Exports\PurchaseListExport;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Flags G-02, G-11 (list part) and G-12: cancelled bills stay listed but
 * never move a total, the list and export say who cancelled a bill, when and
 * why, and the Cancel button is only offered to the admins the route lets
 * through.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseCancelInfoTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginPurchaseCancelInfoUser(string $domain, string $email = 'owner@example.com'): void
{
    test()->post("http://{$domain}/login", [
        'email' => $email,
        'password' => 'password',
    ]);
}

/**
 * Seeds one live and one cancelled bill of each kind, cancelled by an admin
 * named "Asha Admin" with the reason "Duplicate bill".
 *
 * @return array{purchaseId: int, capitalPurchaseId: int}
 */
function seedPurchaseCancelInfo(): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $admin = User::factory()->create([
        'email' => 'owner@example.com',
        'name' => 'Asha Admin',
        'role_id' => Role::where('slug', 'admin')->value('id'),
    ]);
    User::factory()->create(['email' => 'staff@example.com', 'role_id' => Role::where('slug', 'staff')->value('id')]);
    $supplier = Supplier::factory()->create();
    $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
    $account = Account::factory()->create();

    Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
        $admin,
    );
    $cancelledPurchase = Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'payment_mode' => 'credit'],
        [['item_id' => $item->id, 'quantity' => 1, 'rate' => 250, 'discount' => 0]],
        $admin,
    );
    $cancelledPurchase->cancel($admin, 'Duplicate bill');

    CapitalPurchase::post(
        ['type' => 'capital', 'supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        [['account_id' => $account->id, 'amount' => 1000, 'vatable' => false]],
        $admin,
    );
    $cancelledCapital = CapitalPurchase::post(
        ['type' => 'capital', 'supplier_id' => $supplier->id, 'date' => '2026-06-02', 'payment_mode' => 'credit'],
        [['account_id' => $account->id, 'amount' => 500, 'vatable' => false]],
        $admin,
    );
    $cancelledCapital->cancel($admin, 'Duplicate bill');

    return ['purchaseId' => $cancelledPurchase->id, 'capitalPurchaseId' => $cancelledCapital->id];
}

test('a cancelled capital purchase stays listed but does not move the totals row or the export total', function () {
    $domain = 'capital-cancel-totals.tenant-test';
    $tenant = provisionPurchaseCancelInfoTenant($domain);

    $ids = null;
    $tenant->run(function () use (&$ids) {
        $ids = seedPurchaseCancelInfo();
    });

    loginPurchaseCancelInfoUser($domain);

    $this->get("http://{$domain}/capital-purchases")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('capitalPurchases', 2)
            ->where('totals.total', '1000.00'));

    Excel::fake();
    $this->get("http://{$domain}/capital-purchases/export")->assertOk();

    Excel::assertDownloaded('capital-purchases.xlsx', function (CapitalPurchaseListExport $export) {
        $rows = $export->collection();
        $cancelled = $rows->firstWhere('status', 'Cancelled');

        return $rows->count() === 3
            && $rows->last()['total'] === '1000.00'
            && $cancelled['cancelled_by'] === 'Asha Admin'
            && $cancelled['cancel_reason'] === 'Duplicate bill'
            && $cancelled['cancelled_on'] !== null
            && in_array('Reason', $export->headings(), true);
    });

    $tenant->delete();
});

test('the purchase list shows who cancelled a bill, when and why, and so does its export', function () {
    $domain = 'purchase-cancel-info.tenant-test';
    $tenant = provisionPurchaseCancelInfoTenant($domain);

    $ids = null;
    $tenant->run(function () use (&$ids) {
        $ids = seedPurchaseCancelInfo();
    });

    loginPurchaseCancelInfoUser($domain);

    $this->get("http://{$domain}/purchases")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.total', '100.00')
            ->where('purchases.data', fn ($rows) => collect($rows)->contains(
                fn ($row) => $row['id'] === $ids['purchaseId']
                    && $row['cancel_reason'] === 'Duplicate bill'
                    && $row['canceller']['name'] === 'Asha Admin'
                    && $row['cancelled_at'] !== null,
            )));

    $this->get("http://{$domain}/capital-purchases")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('capitalPurchases', fn ($rows) => collect($rows)->contains(
                fn ($row) => $row['id'] === $ids['capitalPurchaseId']
                    && $row['cancel_reason'] === 'Duplicate bill'
                    && $row['canceller']['name'] === 'Asha Admin',
            )));

    Excel::fake();
    $this->get("http://{$domain}/purchases/export")->assertOk();

    Excel::assertDownloaded('purchases.xlsx', function (PurchaseListExport $export) {
        $cancelled = $export->collection()->firstWhere('status', 'Cancelled');

        return $cancelled['cancelled_by'] === 'Asha Admin'
            && $cancelled['cancel_reason'] === 'Duplicate bill'
            && $export->headings()[array_key_last($export->headings())] === 'Reason';
    });

    $tenant->delete();
});

test('only admins are offered the Cancel button, and the cancel routes still refuse everyone else', function () {
    $domain = 'purchase-can-cancel.tenant-test';
    $tenant = provisionPurchaseCancelInfoTenant($domain);

    $livePurchaseId = null;
    $liveCapitalId = null;
    $tenant->run(function () use (&$livePurchaseId, &$liveCapitalId) {
        seedPurchaseCancelInfo();
        $livePurchaseId = Purchase::where('status', 'posted')->value('id');
        $liveCapitalId = CapitalPurchase::where('status', 'posted')->value('id');
    });

    loginPurchaseCancelInfoUser($domain);

    $this->get("http://{$domain}/purchases")->assertInertia(fn ($page) => $page->where('canCancel', true));
    $this->get("http://{$domain}/capital-purchases")->assertInertia(fn ($page) => $page->where('canCancel', true));

    $this->post("http://{$domain}/logout");
    loginPurchaseCancelInfoUser($domain, 'staff@example.com');

    $this->get("http://{$domain}/purchases")->assertInertia(fn ($page) => $page->where('canCancel', false));
    $this->get("http://{$domain}/capital-purchases")->assertInertia(fn ($page) => $page->where('canCancel', false));

    $this->post("http://{$domain}/purchases/{$livePurchaseId}/cancel", ['reason' => 'Nope'])->assertForbidden();
    $this->post("http://{$domain}/capital-purchases/{$liveCapitalId}/cancel", ['reason' => 'Nope'])->assertForbidden();

    $tenant->delete();
});
