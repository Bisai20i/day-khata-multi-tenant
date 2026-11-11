<?php

use App\Enums\FiscalYearStatus;
use App\Enums\QuotationStatus;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Sales-side documents carry a branch (plan 12, BR05)
|--------------------------------------------------------------------------
|
| A sale, sales return or quotation is filed in one branch: the requested
| branch, else its store's branch, else the actor's posting branch. Its
| voucher posts into that same branch on every line, a store of another
| branch is refused (I5), a linked return inherits its sale's branch, and
| cancelling or approving never moves a document. With only Main, nothing
| changes at all.
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    auth()->forgetGuards();
    tenancy()->end();
});

function provisionBranchSalesTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function branchSalesOpenYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function branchSalesAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

/**
 * An admin limited to $branches, read back so all_branches is a loaded value.
 *
 * @param  list<Branch>  $branches
 */
function branchSalesRestrictedUser(array $branches, ?Branch $default = null): User
{
    $user = branchSalesAdmin();
    $user->forceFill(['all_branches' => false, 'default_branch_id' => $default?->id])->save();
    $user->branches()->attach(array_map(fn (Branch $branch): int => $branch->id, $branches));

    return User::query()->findOrFail($user->id);
}

/**
 * The store TenantDatabaseSeeder files under Main.
 */
function branchSalesMainStore(): Store
{
    return Store::query()->where('name', 'Main Store')->firstOrFail();
}

/**
 * A second branch with one active store of its own.
 *
 * @return array{0: Branch, 1: Store}
 */
function branchSalesOtherBranch(): array
{
    $branch = Branch::factory()->create(['name' => 'Pokhara']);
    $store = Store::factory()->create(['name' => 'Pokhara Store', 'branch_id' => $branch->id]);

    return [$branch, $store];
}

/**
 * Stockable trading goods, sold without prior stock (negative stock allowed)
 * so the tests stay about branches, not stock policy.
 */
function branchSalesItem(): Item
{
    CompanySetting::current()->update(['allow_negative_stock' => true]);

    return Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
}

/**
 * Branch id of every line of $voucher, in line order.
 *
 * @return list<int>
 */
function branchSalesLineBranches(JournalVoucher $voucher): array
{
    return JournalVoucherLine::query()->where('journal_voucher_id', $voucher->id)->orderBy('id')->pluck('branch_id')
        ->map(fn (mixed $id): int => (int) $id)->all();
}

function branchSalesCounterpartyLines(JournalVoucher $voucher): int
{
    return JournalVoucherLine::query()->where('journal_voucher_id', $voucher->id)->whereNotNull('counterparty_branch_id')->count();
}

test('a sale from a non-Main store is filed in that store\'s branch, with its voucher and every line there', function () {
    $tenant = provisionBranchSalesTenant('br-sales-store-branch.tenant-test');

    $tenant->run(function () {
        branchSalesOpenYear();
        $admin = branchSalesAdmin();
        [$pokhara, $pokharaStore] = branchSalesOtherBranch();
        $item = branchSalesItem();

        // No branch named: the store decides.
        $sale = Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => $pokharaStore->id],
            [['item_id' => $item->id, 'quantity' => '2', 'rate' => '100.00']],
            $admin,
        );

        // Branch and store named together and agreeing.
        $named = Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-02', 'payment_mode' => 'credit', 'branch_id' => $pokhara->id, 'store_id' => $pokharaStore->id],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '50.00']],
            $admin,
        );

        foreach ([$sale, $named] as $posted) {
            $voucher = $posted->journalVoucher()->firstOrFail();
            $lineBranches = branchSalesLineBranches($voucher);

            expect($posted->fresh()->branch_id)->toBe($pokhara->id)
                ->and($posted->store_id)->toBe($pokharaStore->id)
                ->and($voucher->branch_id)->toBe($pokhara->id)
                ->and($lineBranches)->not->toBeEmpty()
                ->and(array_unique($lineBranches))->toBe([$pokhara->id])
                ->and(branchSalesCounterpartyLines($voucher))->toBe(0);
        }

        // Stock leaves the sale's own store; movements carry no branch.
        expect(ItemStockMovement::query()->where('item_id', $item->id)->pluck('store_id')->unique()->values()->all())
            ->toBe([$pokharaStore->id]);
    });

    $tenant->delete();
});

test('a sale naming a store of another branch is refused and writes nothing', function () {
    $tenant = provisionBranchSalesTenant('br-sales-store-mismatch.tenant-test');

    $tenant->run(function () {
        branchSalesOpenYear();
        $admin = branchSalesAdmin();
        [$pokhara] = branchSalesOtherBranch();
        $item = branchSalesItem();

        expect(fn () => Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id, 'store_id' => branchSalesMainStore()->id],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect(Sale::query()->count())->toBe(0)
            ->and(JournalVoucher::query()->count())->toBe(0)
            ->and(ItemStockMovement::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('the sales form shows a store of another branch as a form error, not a server error', function () {
    $domain = 'br-sales-store-mismatch-http.tenant-test';
    $tenant = provisionBranchSalesTenant($domain);

    $payload = [];
    $tenant->run(function () use (&$payload) {
        User::factory()->create(['email' => 'owner@example.com']);
        branchSalesOpenYear();
        [$pokhara] = branchSalesOtherBranch();

        $payload = [
            'customer_id' => Customer::factory()->create()->id,
            'date' => '2026-06-01',
            'payment_mode' => 'cash',
            'branch_id' => $pokhara->id,
            'store_id' => branchSalesMainStore()->id,
            'lines' => [['item_id' => branchSalesItem()->id, 'quantity' => 1, 'rate' => 100]],
        ];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/sales", $payload)->assertSessionHasErrors('lines');

    $tenant->run(function () {
        expect(Sale::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('a restricted user selling with no branch or store lands in their default branch and its default store', function () {
    $tenant = provisionBranchSalesTenant('br-sales-restricted-default.tenant-test');

    $tenant->run(function () {
        branchSalesOpenYear();
        [$pokhara, $firstStore] = branchSalesOtherBranch();
        $defaultStore = Store::factory()->create(['name' => 'Pokhara Counter', 'branch_id' => $pokhara->id]);
        $pokhara->update(['default_store_id' => $defaultStore->id]);
        $cashier = branchSalesRestrictedUser([$pokhara], default: $pokhara);
        $item = branchSalesItem();

        $sale = Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $cashier,
        );

        $voucher = $sale->journalVoucher()->firstOrFail();

        // The company-wide default (Main Store) is not a Pokhara store, so
        // the branch's own default store is used, not its lowest-id one.
        expect($sale->fresh()->branch_id)->toBe($pokhara->id)
            ->and($sale->store_id)->toBe($defaultStore->id)
            ->and($sale->store_id)->not->toBe($firstStore->id)
            ->and($voucher->branch_id)->toBe($pokhara->id)
            ->and(array_unique(branchSalesLineBranches($voucher)))->toBe([$pokhara->id]);

        // A store of a branch the cashier cannot use is refused (I6).
        expect(fn () => Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => branchSalesMainStore()->id],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $cashier,
        ))->toThrow(InvalidArgumentException::class, 'you do not have access');

        expect(Sale::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('a linked return inherits its sale\'s branch for the credit note and the refund, and refuses another branch', function () {
    $tenant = provisionBranchSalesTenant('br-sales-linked-return.tenant-test');

    $tenant->run(function () {
        branchSalesOpenYear();
        $admin = branchSalesAdmin();
        $approver = branchSalesAdmin();
        [$pokhara, $pokharaStore] = branchSalesOtherBranch();
        $item = branchSalesItem();

        $sale = Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => $pokharaStore->id],
            [['item_id' => $item->id, 'quantity' => '3', 'rate' => '100.00']],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        // Another branch's store, or naming another branch, is refused.
        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'store_id' => branchSalesMainStore()->id],
            [['sale_line_id' => $saleLine->id, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'branch_id' => Branch::main()->id],
            [['sale_line_id' => $saleLine->id, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'branch of the original document');

        expect(SalesReturn::query()->count())->toBe(0);

        // No store, no branch: the sale's branch and store, refund included.
        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'refund_cash_amount' => '100.00'],
            [['sale_line_id' => $saleLine->id, 'quantity' => '1']],
            $admin,
        );

        $creditNote = $return->journalVoucher()->firstOrFail();
        $refund = JournalVoucher::query()->findOrFail($return->fresh()->refund_journal_voucher_id);

        expect($return->fresh()->branch_id)->toBe($pokhara->id)
            ->and($return->store_id)->toBe($pokharaStore->id)
            ->and($creditNote->branch_id)->toBe($pokhara->id)
            ->and(array_unique(branchSalesLineBranches($creditNote)))->toBe([$pokhara->id])
            ->and($refund->branch_id)->toBe($pokhara->id)
            ->and(array_unique(branchSalesLineBranches($refund)))->toBe([$pokhara->id]);

        // A request keeps its branch through approval by someone else.
        $request = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-03'],
            [['sale_line_id' => $saleLine->id, 'quantity' => '1']],
            $admin,
        );

        expect($request->fresh()->branch_id)->toBe($pokhara->id);

        $approved = $request->approve($approver);
        $approvedVoucher = $approved->journalVoucher()->firstOrFail();

        expect($approved->branch_id)->toBe($pokhara->id)
            ->and($approvedVoucher->branch_id)->toBe($pokhara->id)
            ->and(array_unique(branchSalesLineBranches($approvedVoucher)))->toBe([$pokhara->id]);
    });

    $tenant->delete();
});

test('an unlinked return is placed like a sale: by its store\'s branch', function () {
    $tenant = provisionBranchSalesTenant('br-sales-unlinked-return.tenant-test');

    $tenant->run(function () {
        branchSalesOpenYear();
        $admin = branchSalesAdmin();
        [$pokhara, $pokharaStore] = branchSalesOtherBranch();

        $return = SalesReturn::postUnlinked(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-05', 'store_id' => $pokharaStore->id],
            [['item_id' => branchSalesItem()->id, 'quantity' => '1', 'rate' => '40.00']],
            $admin,
        );

        $voucher = $return->journalVoucher()->firstOrFail();

        expect($return->fresh()->branch_id)->toBe($pokhara->id)
            ->and($return->store_id)->toBe($pokharaStore->id)
            ->and($voucher->branch_id)->toBe($pokhara->id)
            ->and(array_unique(branchSalesLineBranches($voucher)))->toBe([$pokhara->id]);
    });

    $tenant->delete();
});

test('cancelling a sale reverses it inside the sale\'s branch and leaves the sale there', function () {
    $tenant = provisionBranchSalesTenant('br-sales-cancel.tenant-test');

    $tenant->run(function () {
        branchSalesOpenYear();
        $admin = branchSalesAdmin();
        [$pokhara, $pokharaStore] = branchSalesOtherBranch();

        $sale = Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => $pokharaStore->id],
            [['item_id' => branchSalesItem()->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        $sale->cancel($admin, 'Wrong customer');

        $sale = $sale->fresh();
        $reversal = JournalVoucher::query()->findOrFail($sale->reversal_journal_voucher_id);

        expect($sale->status)->toBe('cancelled')
            ->and($sale->branch_id)->toBe($pokhara->id)
            ->and($reversal->branch_id)->toBe($pokhara->id)
            ->and(array_unique(branchSalesLineBranches($reversal)))->toBe([$pokhara->id]);
    });

    $tenant->delete();
});

test('a quotation stores its branch, keeps it through an edit, and converts into a sale of that branch', function () {
    $this->travelTo('2026-06-15 10:00:00');

    $domain = 'br-sales-quotation.tenant-test';
    $tenant = provisionBranchSalesTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        User::factory()->create(['email' => 'owner@example.com']);
        branchSalesOpenYear();
        [$pokhara, $pokharaStore] = branchSalesOtherBranch();

        $ids = [
            'branch' => $pokhara->id,
            'store' => $pokharaStore->id,
            'customer' => Customer::factory()->create()->id,
            'item' => Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id,
        ];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $payload = fn (?int $branchId, string $rate): array => [
        'customer_id' => $ids['customer'],
        'date' => '2026-06-15',
        'discount' => '0',
        'vat_rate' => '13',
        'branch_id' => $branchId,
        'lines' => [['item_id' => $ids['item'], 'quantity' => '2', 'rate' => $rate, 'discount' => '0']],
    ];

    $this->post("http://{$domain}/quotations", $payload($ids['branch'], '100.00'))
        ->assertRedirect("http://{$domain}/quotations");

    $quotationId = $tenant->run(fn () => Quotation::query()->sole()->id);

    // Naming Main on the edit does not move the quotation.
    $mainId = $tenant->run(fn () => Branch::main()->id);
    $this->put("http://{$domain}/quotations/{$quotationId}", $payload($mainId, '120.00'))
        ->assertRedirect("http://{$domain}/quotations");

    $tenant->run(function () use ($ids) {
        $quotation = Quotation::query()->sole();

        expect($quotation->branch_id)->toBe($ids['branch'])
            ->and($quotation->total)->toBe('240.00')
            ->and($quotation->status)->toBe(QuotationStatus::Draft);

        $sale = $quotation->convertToSale(branchSalesAdmin());

        expect($sale->fresh()->branch_id)->toBe($ids['branch'])
            ->and($sale->store_id)->toBe($ids['store'])
            ->and($sale->journalVoucher()->firstOrFail()->branch_id)->toBe($ids['branch']);
    });

    $tenant->delete();
});

test('with only Main, sales documents and their vouchers are filed exactly as before', function () {
    $tenant = provisionBranchSalesTenant('br-sales-parity.tenant-test');

    $tenant->run(function () {
        branchSalesOpenYear();
        $admin = branchSalesAdmin();
        $mainId = Branch::main()->id;
        $mainStore = branchSalesMainStore();
        $item = branchSalesItem();

        $sale = Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => '2', 'rate' => '100.00']],
            $admin,
        );

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02'],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '1']],
            $admin,
        );

        $saleVoucher = $sale->journalVoucher()->firstOrFail();
        $creditNote = $return->journalVoucher()->firstOrFail();

        // The store is the legacy default (lowest-id active store), the
        // numbers are the first of their series, and every voucher and line
        // sits in Main with no clearing line anywhere.
        expect($sale->fresh()->branch_id)->toBe($mainId)
            ->and($sale->store_id)->toBe($mainStore->id)
            ->and($sale->store_id)->toBe(Store::query()->where('is_active', true)->orderBy('id')->value('id'))
            ->and($sale->invoice_number)->toEndWith('-1')
            ->and($saleVoucher->voucher_number)->toBe(1)
            ->and($saleVoucher->branch_id)->toBe($mainId)
            ->and(array_unique(branchSalesLineBranches($saleVoucher)))->toBe([$mainId])
            ->and($return->fresh()->branch_id)->toBe($mainId)
            ->and($return->store_id)->toBe($mainStore->id)
            ->and($creditNote->branch_id)->toBe($mainId)
            ->and(array_unique(branchSalesLineBranches($creditNote)))->toBe([$mainId])
            ->and(JournalVoucherLine::query()->whereNotNull('counterparty_branch_id')->count())->toBe(0)
            ->and(JournalVoucherLine::query()->where('branch_id', '!=', $mainId)->count())->toBe(0);
    });

    $tenant->delete();
});
