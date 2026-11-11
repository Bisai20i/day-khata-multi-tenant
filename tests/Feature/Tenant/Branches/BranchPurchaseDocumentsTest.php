<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Branch;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branches\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Purchase-side documents carry a branch (plan 12, BR06)
|--------------------------------------------------------------------------
|
| A purchase, purchase return, capital purchase or capital sale is filed in
| one branch: the requested branch, else its store's branch, else the actor's
| posting branch. Its voucher posts into that same branch on every line, a
| store of another branch is refused (I5), a linked return (and its refund)
| inherits its purchase's branch, and cancelling reverses inside the
| document's branch. With only Main, nothing changes at all.
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    auth()->forgetGuards();
    tenancy()->end();
});

function provisionBranchPurchaseTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function branchPurchaseOpenYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function branchPurchaseAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

/**
 * An admin limited to $branches, read back so all_branches is a loaded value.
 *
 * @param  list<Branch>  $branches
 */
function branchPurchaseRestrictedUser(array $branches, ?Branch $default = null): User
{
    $user = branchPurchaseAdmin();
    $user->forceFill(['all_branches' => false, 'default_branch_id' => $default?->id])->save();
    $user->branches()->attach(array_map(fn (Branch $branch): int => $branch->id, $branches));

    return User::query()->findOrFail($user->id);
}

/**
 * The store TenantDatabaseSeeder files under Main.
 */
function branchPurchaseMainStore(): Store
{
    return Store::query()->where('name', 'Main Store')->firstOrFail();
}

/**
 * A second branch with one active store of its own.
 *
 * @return array{0: Branch, 1: Store}
 */
function branchPurchaseOtherBranch(): array
{
    $branch = Branch::factory()->create(['name' => 'Pokhara']);
    $store = Store::factory()->create(['name' => 'Pokhara Store', 'branch_id' => $branch->id]);

    return [$branch, $store];
}

/**
 * Stockable, non-vatable trading goods with negative stock allowed, so the
 * tests stay about branches, not VAT or stock policy.
 */
function branchPurchaseItem(): Item
{
    CompanySetting::current()->update(['allow_negative_stock' => true]);

    return Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
}

function branchPurchaseCashAccountId(): int
{
    return (int) Account::query()->where('code', 'AS1')->value('id');
}

/**
 * Branch id of every line of $voucher, in line order.
 *
 * @return list<int>
 */
function branchPurchaseLineBranches(JournalVoucher $voucher): array
{
    return JournalVoucherLine::query()->where('journal_voucher_id', $voucher->id)->orderBy('id')->pluck('branch_id')
        ->map(fn (mixed $id): int => (int) $id)->all();
}

/**
 * Asserts $voucher and every one of its lines sit in $branchId, with no
 * inter-branch clearing line.
 */
function expectBranchPurchaseVoucherIn(JournalVoucher $voucher, int $branchId): void
{
    $lineBranches = branchPurchaseLineBranches($voucher);

    expect($voucher->branch_id)->toBe($branchId)
        ->and($lineBranches)->not->toBeEmpty()
        ->and(array_values(array_unique($lineBranches)))->toBe([$branchId])
        ->and(JournalVoucherLine::query()->where('journal_voucher_id', $voucher->id)->whereNotNull('counterparty_branch_id')->count())->toBe(0);
}

test('a purchase from a non-Main store is filed in that store\'s branch, with its voucher, every line and its stock there', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-store-branch.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        $admin = branchPurchaseAdmin();
        [$pokhara, $pokharaStore] = branchPurchaseOtherBranch();
        $item = branchPurchaseItem();

        // No branch named: the store decides. Cash, so the settlement lines
        // are on the voucher too.
        $purchase = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => $pokharaStore->id],
            [['item_id' => $item->id, 'quantity' => '2', 'rate' => '100.00']],
            $admin,
        );

        // Branch and store named together and agreeing.
        $named = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-02', 'payment_mode' => 'credit', 'branch_id' => $pokhara->id, 'store_id' => $pokharaStore->id],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '50.00']],
            $admin,
        );

        foreach ([$purchase, $named] as $posted) {
            expect($posted->fresh()->branch_id)->toBe($pokhara->id)
                ->and($posted->store_id)->toBe($pokharaStore->id);

            expectBranchPurchaseVoucherIn($posted->journalVoucher()->firstOrFail(), $pokhara->id);
        }

        expect(ItemStockMovement::query()->where('item_id', $item->id)->pluck('store_id')->unique()->values()->all())
            ->toBe([$pokharaStore->id]);
    });

    $tenant->delete();
});

test('a purchase naming a store of another branch is refused and writes nothing', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-store-mismatch.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        $admin = branchPurchaseAdmin();
        [$pokhara] = branchPurchaseOtherBranch();
        $item = branchPurchaseItem();

        expect(fn () => Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id, 'store_id' => branchPurchaseMainStore()->id],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect(fn () => CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id, 'store_id' => branchPurchaseMainStore()->id],
            [['account_id' => Account::factory()->create()->id, 'amount' => '100']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect(fn () => CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id, 'store_id' => branchPurchaseMainStore()->id],
            [['account_id' => Account::factory()->create()->id, 'amount' => '100']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect(Purchase::query()->count())->toBe(0)
            ->and(CapitalPurchase::query()->count())->toBe(0)
            ->and(CapitalSale::query()->count())->toBe(0)
            ->and(JournalVoucher::query()->count())->toBe(0)
            ->and(ItemStockMovement::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('the purchase-side forms show a branch or store mismatch as a form error, not a server error', function () {
    $domain = 'br-purchase-forms-http.tenant-test';
    $tenant = provisionBranchPurchaseTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        branchPurchaseOpenYear();
        [$pokhara, $pokharaStore] = branchPurchaseOtherBranch();
        $item = branchPurchaseItem();

        $purchase = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'store_id' => $pokharaStore->id],
            [['item_id' => $item->id, 'quantity' => '3', 'rate' => '100.00']],
            $owner,
        );

        $ids = [
            'pokhara' => $pokhara->id,
            'main' => Branch::main()->id,
            'mainStore' => branchPurchaseMainStore()->id,
            'supplier' => Supplier::factory()->create()->id,
            'item' => $item->id,
            'purchase' => $purchase->id,
            'purchaseLine' => $purchase->lines()->firstOrFail()->id,
            'account' => Account::factory()->create()->id,
        ];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $ids['supplier'],
        'date' => '2026-06-02',
        'payment_mode' => 'cash',
        'branch_id' => $ids['pokhara'],
        'store_id' => $ids['mainStore'],
        'expected_total' => '100.00',
        'lines' => [['item_id' => $ids['item'], 'quantity' => 1, 'rate' => 100]],
    ])->assertSessionHasErrors('lines');

    $this->post("http://{$domain}/purchase-returns", [
        'purchase_id' => $ids['purchase'],
        'date' => '2026-06-02',
        'branch_id' => $ids['main'],
        'lines' => [['purchase_line_id' => $ids['purchaseLine'], 'quantity' => 1]],
    ])->assertSessionHasErrors('lines');

    $this->post("http://{$domain}/capital-sales", [
        'date' => '2026-06-02',
        'payment_mode' => 'cash',
        'branch_id' => $ids['pokhara'],
        'store_id' => $ids['mainStore'],
        'lines' => [['account_id' => $ids['account'], 'amount' => 100]],
    ])->assertSessionHasErrors('lines');

    $tenant->run(function () {
        expect(Purchase::query()->count())->toBe(1)
            ->and(PurchaseReturn::query()->count())->toBe(0)
            ->and(CapitalSale::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('a linked purchase return inherits its purchase\'s branch for the debit note, the refund and the stock, and refuses another branch', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-linked-return.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        $admin = branchPurchaseAdmin();
        [$pokhara, $pokharaStore] = branchPurchaseOtherBranch();
        $item = branchPurchaseItem();

        $purchase = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'store_id' => $pokharaStore->id],
            [['item_id' => $item->id, 'quantity' => '3', 'rate' => '100.00']],
            $admin,
        );
        $purchaseLine = $purchase->lines()->firstOrFail();

        // Another branch's store, or naming another branch, is refused.
        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-02', 'store_id' => branchPurchaseMainStore()->id],
            [['purchase_line_id' => $purchaseLine->id, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-02', 'branch_id' => Branch::main()->id],
            [['purchase_line_id' => $purchaseLine->id, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'branch of the original document');

        expect(PurchaseReturn::query()->count())->toBe(0)
            ->and(JournalVoucher::query()->count())->toBe(1);

        // No store, no branch: the purchase's branch and store, refund included.
        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-02', 'refund_account_id' => branchPurchaseCashAccountId()],
            [['purchase_line_id' => $purchaseLine->id, 'quantity' => '1']],
            $admin,
        );

        $return = $return->fresh();
        $refund = JournalVoucher::query()->findOrFail($return->refund_journal_voucher_id);

        expect($return->branch_id)->toBe($pokhara->id)
            ->and($return->store_id)->toBe($pokharaStore->id)
            ->and($return->total)->toBe('100.00');

        expectBranchPurchaseVoucherIn($return->journalVoucher()->firstOrFail(), $pokhara->id);
        expectBranchPurchaseVoucherIn($refund, $pokhara->id);

        // The goods leave the store they came into.
        expect(ItemStockMovement::query()->where('item_id', $item->id)->pluck('store_id')->unique()->values()->all())
            ->toBe([$pokharaStore->id]);
    });

    $tenant->delete();
});

test('an unlinked purchase return is placed like a purchase: by its store\'s branch', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-unlinked-return.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        $admin = branchPurchaseAdmin();
        [$pokhara, $pokharaStore] = branchPurchaseOtherBranch();
        $item = branchPurchaseItem();

        $return = PurchaseReturn::postUnlinked(
            ['date' => '2026-06-05', 'payment_mode' => 'cash', 'store_id' => $pokharaStore->id, 'reason' => 'Opening stock back to supplier'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '40.00']],
            $admin,
        );

        expect($return->fresh()->branch_id)->toBe($pokhara->id)
            ->and($return->store_id)->toBe($pokharaStore->id);

        expectBranchPurchaseVoucherIn($return->journalVoucher()->firstOrFail(), $pokhara->id);

        expect(ItemStockMovement::query()->where('item_id', $item->id)->pluck('store_id')->all())->toBe([$pokharaStore->id]);

        // A store of another branch is refused.
        expect(fn () => PurchaseReturn::postUnlinked(
            ['date' => '2026-06-05', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id, 'store_id' => branchPurchaseMainStore()->id, 'reason' => 'Wrong store'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '40.00']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect(PurchaseReturn::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('capital purchases and capital sales are filed in their store\'s branch, and a capital asset follows its bill', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-capital.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        $admin = branchPurchaseAdmin();
        [$pokhara, $pokharaStore] = branchPurchaseOtherBranch();

        $fixedAssetsGroup = AccountGroup::factory()->create(['name' => 'Fixed Assets']);
        $assetAccount = Account::factory()->underGroup()->create(['account_group_id' => $fixedAssetsGroup->id]);

        $capitalPurchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'credit', 'supplier_id' => Supplier::factory()->create()->id, 'store_id' => $pokharaStore->id],
            [[
                'account_id' => $assetAccount->id,
                'amount' => '85000',
                'create_asset' => true,
                'asset_name' => 'Delivery Van',
                'depreciation_category' => 'Pool B',
                'depreciation_method' => 'wdv',
                'depreciation_rate' => '20',
                'salvage_value' => '5000',
            ]],
            $admin,
        );

        $capitalSale = CapitalSale::post(
            ['date' => '2026-06-02', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id],
            [['account_id' => Account::factory()->create()->id, 'amount' => '1200']],
            $admin,
        );

        expect($capitalPurchase->fresh()->branch_id)->toBe($pokhara->id)
            ->and($capitalPurchase->store_id)->toBe($pokharaStore->id)
            ->and($capitalSale->fresh()->branch_id)->toBe($pokhara->id)
            // Branch named alone: its only active store.
            ->and($capitalSale->store_id)->toBe($pokharaStore->id);

        expectBranchPurchaseVoucherIn($capitalPurchase->journalVoucher()->firstOrFail(), $pokhara->id);
        expectBranchPurchaseVoucherIn($capitalSale->journalVoucher()->firstOrFail(), $pokhara->id);

        $assetId = $capitalPurchase->lines()->firstOrFail()->fixed_asset_id;

        expect($assetId)->not->toBeNull()
            ->and((int) FixedAsset::query()->whereKey($assetId)->value('branch_id'))->toBe($pokhara->id)
            ->and(FixedAsset::query()->whereKey($assetId)->value('asset_code'))->toStartWith('FA-0');
    });

    $tenant->delete();
});

test('cancelling any purchase-side document reverses it inside the document\'s branch and leaves it there', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-cancel.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        $admin = branchPurchaseAdmin();
        [$pokhara, $pokharaStore] = branchPurchaseOtherBranch();
        $item = branchPurchaseItem();

        $returned = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'store_id' => $pokharaStore->id],
            [['item_id' => $item->id, 'quantity' => '2', 'rate' => '100.00']],
            $admin,
        );
        $return = PurchaseReturn::post(
            ['purchase_id' => $returned->id, 'date' => '2026-06-02', 'refund_account_id' => branchPurchaseCashAccountId()],
            [['purchase_line_id' => $returned->lines()->firstOrFail()->id, 'quantity' => '1']],
            $admin,
        );
        $refundId = $return->fresh()->refund_journal_voucher_id;

        $purchase = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => $pokharaStore->id],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );
        $capitalPurchase = CapitalPurchase::post(
            ['type' => 'service', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => $pokharaStore->id],
            [['account_id' => Account::factory()->create()->id, 'amount' => '300']],
            $admin,
        );
        $capitalSale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => $pokharaStore->id],
            [['account_id' => Account::factory()->create()->id, 'amount' => '400']],
            $admin,
        );

        $return->cancel($admin, 'Supplier took it back after all');
        $purchase->cancel($admin, 'Entered twice');
        $capitalPurchase->cancel($admin, 'Wrong account');
        $capitalSale->cancel($admin, 'Wrong buyer');

        foreach ([$return, $purchase, $capitalPurchase, $capitalSale] as $document) {
            $document = $document->fresh();

            expect($document->status)->toBe('cancelled')
                ->and($document->branch_id)->toBe($pokhara->id);

            expectBranchPurchaseVoucherIn(JournalVoucher::query()->findOrFail($document->reversal_journal_voucher_id), $pokhara->id);
        }

        // The refund's own reversal stays in the return's branch too.
        expectBranchPurchaseVoucherIn(JournalVoucher::query()->where('reversal_of_id', $refundId)->firstOrFail(), $pokhara->id);
    });

    $tenant->delete();
});

test('a restricted user lands in their default branch and its default store, and is refused branches they cannot use', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-restricted-default.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        [$pokhara, $firstStore] = branchPurchaseOtherBranch();
        $defaultStore = Store::factory()->create(['name' => 'Pokhara Counter', 'branch_id' => $pokhara->id]);
        $pokhara->update(['default_store_id' => $defaultStore->id]);
        $clerk = branchPurchaseRestrictedUser([$pokhara], default: $pokhara);
        $item = branchPurchaseItem();

        $purchase = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $clerk,
        );
        $capitalSale = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => Account::factory()->create()->id, 'amount' => '250']],
            $clerk,
        );

        // The company-wide default (Main Store) is not a Pokhara store, so
        // the branch's own default store is used, not its lowest-id one.
        foreach ([$purchase, $capitalSale] as $document) {
            expect($document->fresh()->branch_id)->toBe($pokhara->id)
                ->and($document->store_id)->toBe($defaultStore->id)
                ->and($document->store_id)->not->toBe($firstStore->id);

            expectBranchPurchaseVoucherIn($document->journalVoucher()->firstOrFail(), $pokhara->id);
        }

        // A store, or a branch, the clerk cannot use is refused (I6).
        expect(fn () => Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => branchPurchaseMainStore()->id],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $clerk,
        ))->toThrow(InvalidArgumentException::class, 'you do not have access');

        expect(fn () => PurchaseReturn::postUnlinked(
            ['date' => '2026-06-05', 'payment_mode' => 'cash', 'branch_id' => Branch::main()->id, 'reason' => 'Not my branch'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '40.00']],
            $clerk,
        ))->toThrow(InvalidArgumentException::class, 'you do not have access');

        // A linked return of a Main purchase inherits Main, which the clerk
        // cannot use.
        $mainPurchase = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '2', 'rate' => '100.00']],
            branchPurchaseAdmin(),
        );

        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $mainPurchase->id, 'date' => '2026-06-02'],
            [['purchase_line_id' => $mainPurchase->lines()->firstOrFail()->id, 'quantity' => '1']],
            $clerk,
        ))->toThrow(InvalidArgumentException::class, 'you do not have access');

        expect(Purchase::query()->count())->toBe(2)
            ->and(PurchaseReturn::query()->count())->toBe(0);

        // The clerk cancels their own branch's purchase: the reversal stays
        // in Pokhara even with the access scope on.
        BranchContext::restrictedTo($clerk, fn () => Purchase::query()->findOrFail($purchase->id)->cancel($clerk, 'Wrong supplier'));

        $cancelled = Purchase::query()->findOrFail($purchase->id);

        expect($cancelled->status)->toBe('cancelled');
        expectBranchPurchaseVoucherIn(JournalVoucher::query()->findOrFail($cancelled->reversal_journal_voucher_id), $pokhara->id);
    });

    $tenant->delete();
});

test('a restricted clerk cancels a capital purchase whose asset sits in their branch, but not once that asset is disposed', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-capital-asset-cancel.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        [$pokhara, $pokharaStore] = branchPurchaseOtherBranch();
        $clerk = branchPurchaseRestrictedUser([$pokhara], default: $pokhara);

        $fixedAssetsGroup = AccountGroup::factory()->create(['name' => 'Fixed Assets']);
        $assetAccount = Account::factory()->underGroup()->create(['account_group_id' => $fixedAssetsGroup->id]);

        $postWithAsset = fn (string $assetName): CapitalPurchase => CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'store_id' => $pokharaStore->id],
            [[
                'account_id' => $assetAccount->id,
                'amount' => '50000',
                'create_asset' => true,
                'asset_name' => $assetName,
                'depreciation_category' => 'Pool B',
                'depreciation_method' => 'wdv',
                'depreciation_rate' => '20',
            ]],
            $clerk,
        );

        $kept = $postWithAsset('Delivery Van');
        $disposed = $postWithAsset('Generator');

        $keptAssetId = $kept->lines()->firstOrFail()->fixed_asset_id;
        $disposedAssetId = $disposed->lines()->firstOrFail()->fixed_asset_id;

        expect((int) FixedAsset::query()->whereKey($keptAssetId)->value('branch_id'))->toBe($pokhara->id)
            ->and((int) FixedAsset::query()->whereKey($disposedAssetId)->value('branch_id'))->toBe($pokhara->id);

        FixedAsset::query()->whereKey($disposedAssetId)->update(['status' => 'disposed']);

        // With the clerk's access scope on, the guard still sees the asset.
        expect(fn () => BranchContext::restrictedTo($clerk, fn () => CapitalPurchase::query()->findOrFail($disposed->id)->cancel($clerk, 'Wrong bill')))
            ->toThrow(InvalidArgumentException::class, 'depreciated or disposed');

        expect($disposed->fresh()->status)->toBe('posted');

        BranchContext::restrictedTo($clerk, fn () => CapitalPurchase::query()->findOrFail($kept->id)->cancel($clerk, 'Wrong bill'));

        $kept = $kept->fresh();

        expect($kept->status)->toBe('cancelled')
            ->and($kept->branch_id)->toBe($pokhara->id)
            ->and(FixedAsset::query()->whereKey($keptAssetId)->value('status'))->toBe('cancelled');

        expectBranchPurchaseVoucherIn(JournalVoucher::query()->findOrFail($kept->reversal_journal_voucher_id), $pokhara->id);
    });

    $tenant->delete();
});

test('a duplicate supplier bill number is caught across branches, even for a restricted user who cannot see the first bill', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-bill-number.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        $admin = branchPurchaseAdmin();
        [$pokhara] = branchPurchaseOtherBranch();
        $clerk = branchPurchaseRestrictedUser([$pokhara], default: $pokhara);
        $supplier = Supplier::factory()->create();
        $item = branchPurchaseItem();

        Purchase::post(
            ['supplier_id' => $supplier->id, 'bill_number' => 'B-100', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );
        CapitalPurchase::post(
            ['type' => 'service', 'supplier_id' => $supplier->id, 'bill_number' => 'CP-100', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => Account::factory()->create()->id, 'amount' => '300']],
            $admin,
        );

        // The clerk's own view hides the Main bills.
        expect(BranchContext::restrictedTo($clerk, fn () => Purchase::query()->count()))->toBe(0)
            ->and(BranchContext::restrictedTo($clerk, fn () => CapitalPurchase::query()->count()))->toBe(0);

        // Still refused with the sentence, not a unique-index error.
        expect(fn () => BranchContext::restrictedTo($clerk, fn () => Purchase::post(
            ['supplier_id' => $supplier->id, 'bill_number' => 'B-100', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $clerk,
        )))->toThrow(InvalidArgumentException::class, 'has already been entered');

        expect(fn () => BranchContext::restrictedTo($clerk, fn () => CapitalPurchase::post(
            ['type' => 'service', 'supplier_id' => $supplier->id, 'bill_number' => 'CP-100', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['account_id' => Account::factory()->create()->id, 'amount' => '300']],
            $clerk,
        )))->toThrow(InvalidArgumentException::class, 'has already been entered');

        expect(Purchase::query()->count())->toBe(1)
            ->and(CapitalPurchase::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('with only Main, purchase-side documents and their vouchers are filed exactly as before', function () {
    $tenant = provisionBranchPurchaseTenant('br-purchase-parity.tenant-test');

    $tenant->run(function () {
        branchPurchaseOpenYear();
        $admin = branchPurchaseAdmin();
        $mainId = Branch::main()->id;
        $mainStore = branchPurchaseMainStore();
        $legacyDefaultStoreId = Store::query()->where('is_active', true)->orderBy('id')->value('id');
        $item = branchPurchaseItem();

        $purchase = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '2', 'rate' => '100.00']],
            $admin,
        );
        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-02', 'refund_account_id' => branchPurchaseCashAccountId()],
            [['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => '1']],
            $admin,
        );
        $unlinked = PurchaseReturn::postUnlinked(
            ['date' => '2026-06-03', 'payment_mode' => 'cash', 'reason' => 'Opening stock'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '40.00']],
            $admin,
        );
        $capitalPurchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-04', 'payment_mode' => 'cash'],
            [['account_id' => Account::factory()->create()->id, 'amount' => '5000']],
            $admin,
        );
        $capitalSale = CapitalSale::post(
            ['date' => '2026-06-05', 'payment_mode' => 'cash'],
            [['account_id' => Account::factory()->create()->id, 'amount' => '1200']],
            $admin,
        );

        $purchaseVoucher = $purchase->journalVoucher()->firstOrFail();

        // The store is the legacy default (the only active store), the
        // numbers are the first of their series, and every document, voucher
        // and line sits in Main with no clearing line anywhere.
        expect($purchase->store_id)->toBe($mainStore->id)
            ->and($purchase->store_id)->toBe($legacyDefaultStoreId)
            ->and($purchase->purchase_number)->toEndWith('-1')
            ->and($purchaseVoucher->voucher_number)->toBe(1)
            ->and($return->store_id)->toBe($mainStore->id)
            ->and($return->debit_note_number)->toEndWith('-1')
            ->and($unlinked->store_id)->toBe($mainStore->id)
            ->and($capitalPurchase->store_id)->toBe($mainStore->id)
            ->and($capitalSale->store_id)->toBe($mainStore->id)
            ->and($capitalSale->invoice_number)->toEndWith('-1');

        foreach ([$purchase, $return, $unlinked, $capitalPurchase, $capitalSale] as $document) {
            expect($document->fresh()->branch_id)->toBe($mainId);
            expectBranchPurchaseVoucherIn($document->journalVoucher()->firstOrFail(), $mainId);
        }

        expectBranchPurchaseVoucherIn(JournalVoucher::query()->findOrFail($return->fresh()->refund_journal_voucher_id), $mainId);

        expect(JournalVoucher::query()->where('branch_id', '!=', $mainId)->count())->toBe(0)
            ->and(JournalVoucherLine::query()->where('branch_id', '!=', $mainId)->count())->toBe(0)
            ->and(JournalVoucherLine::query()->whereNotNull('counterparty_branch_id')->count())->toBe(0);
    });

    $tenant->delete();
});
