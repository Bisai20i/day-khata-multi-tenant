<?php

use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\CapitalPurchase;
use App\Models\CapitalPurchaseSettlement;
use App\Models\FiscalYear;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function csTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function csActor(string $slug = 'admin'): User
{
    return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
}

function csOpenYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
}

/**
 * A 10,000 credit capital purchase from a fresh supplier.
 *
 * @return array{0: CapitalPurchase, 1: Supplier, 2: User}
 */
function csCreditPurchase(string $mode = 'credit', array $extra = []): array
{
    csOpenYear();
    $actor = csActor();
    $supplier = Supplier::factory()->create();
    $account = Account::factory()->create();

    $purchase = CapitalPurchase::post(
        array_merge(['type' => 'service', 'supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => $mode], $extra),
        [['account_id' => $account->id, 'amount' => '10000']],
        $actor,
    );

    return [$purchase, $supplier, $actor];
}

function csBalance(JournalVoucher $voucher): array
{
    $lines = $voucher->lines()->get();

    return [
        Money::sum($lines->map(fn ($l) => Money::of($l->debit)))->toString(),
        Money::sum($lines->map(fn ($l) => Money::of($l->credit)))->toString(),
    ];
}

test('a credit bill shows its full amount outstanding and a settlement posts a balanced Dr supplier Cr cash voucher', function () {
    $tenant = csTenant('cs-settle.tenant-test');

    $tenant->run(function () {
        [$purchase, $supplier, $actor] = csCreditPurchase();

        expect($purchase->outstandingAmount()->toString())->toBe('10000.00');

        $settlement = CapitalPurchaseSettlement::settle($purchase, [
            'date' => '2026-06-10', 'amount' => '4000', 'payment_mode' => 'cash',
        ], $actor);

        $voucher = $settlement->journalVoucher;
        expect($voucher->voucher_type)->toBe(VoucherType::Payment)
            ->and(csBalance($voucher))->toBe(['4000.00', '4000.00'])
            ->and($voucher->lines()->where('account_id', $supplier->account_id)->first()->debit)->toBe('4000.00')
            ->and($voucher->lines()->where('account_id', Account::where('code', 'AS1')->value('id'))->first()->credit)->toBe('4000.00')
            ->and($settlement->status)->toBe('posted')
            ->and($purchase->fresh()->outstandingAmount()->toString())->toBe('6000.00');
    });

    $tenant->delete();
});

test('a partial bill only owes its unpaid part and cash or bank bills owe nothing', function () {
    $tenant = csTenant('cs-partial.tenant-test');

    $tenant->run(function () {
        [$purchase] = csCreditPurchase('partial', ['cash_amount' => '3000', 'bank_amount' => '0']);
        expect($purchase->outstandingAmount()->toString())->toBe('7000.00');
    });

    $tenant->delete();
});

test('a settlement above the outstanding balance is rejected and nothing is posted', function () {
    $tenant = csTenant('cs-overpay.tenant-test');

    $tenant->run(function () {
        [$purchase, , $actor] = csCreditPurchase();
        $vouchers = JournalVoucher::count();

        expect(fn () => CapitalPurchaseSettlement::settle($purchase, [
            'date' => '2026-06-10', 'amount' => '10000.01', 'payment_mode' => 'cash',
        ], $actor))->toThrow(InvalidArgumentException::class, 'outstanding');

        CapitalPurchaseSettlement::settle($purchase, ['date' => '2026-06-10', 'amount' => '10000', 'payment_mode' => 'cash'], $actor);

        expect(fn () => CapitalPurchaseSettlement::settle($purchase->fresh(), [
            'date' => '2026-06-11', 'amount' => '0.01', 'payment_mode' => 'cash',
        ], $actor))->toThrow(InvalidArgumentException::class)
            ->and(JournalVoucher::count())->toBe($vouchers + 1)
            ->and($purchase->fresh()->outstandingAmount()->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('a cash bill cannot be settled later', function () {
    $tenant = csTenant('cs-cash-bill.tenant-test');

    $tenant->run(function () {
        [$purchase, , $actor] = csCreditPurchase('cash');

        expect(fn () => CapitalPurchaseSettlement::settle($purchase, [
            'date' => '2026-06-10', 'amount' => '100', 'payment_mode' => 'cash',
        ], $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a settlement reverses its voucher and restores the outstanding balance', function () {
    $tenant = csTenant('cs-cancel-settlement.tenant-test');

    $tenant->run(function () {
        [$purchase, , $actor] = csCreditPurchase();
        $settlement = CapitalPurchaseSettlement::settle($purchase, ['date' => '2026-06-10', 'amount' => '4000', 'payment_mode' => 'cash'], $actor);

        expect(fn () => $settlement->cancel($actor, '  '))->toThrow(InvalidArgumentException::class);

        $settlement->cancel($actor, 'Wrong amount');

        $fresh = $settlement->fresh();
        expect($fresh->status)->toBe('cancelled')
            ->and($fresh->cancel_reason)->toBe('Wrong amount')
            ->and($fresh->cancelled_by)->toBe($actor->id)
            ->and($fresh->reversalJournalVoucher->voucher_type)->toBe(VoucherType::Reversal)
            ->and(csBalance($fresh->reversalJournalVoucher))->toBe(['4000.00', '4000.00'])
            ->and($purchase->fresh()->outstandingAmount()->toString())->toBe('10000.00');

        expect(fn () => $fresh->cancel($actor, 'again'))->toThrow(InvalidArgumentException::class, 'already');
    });

    $tenant->delete();
});

test('a bill with live settlements cannot be cancelled until they are cancelled', function () {
    $tenant = csTenant('cs-block-cancel.tenant-test');

    $tenant->run(function () {
        [$purchase, , $actor] = csCreditPurchase();
        $settlement = CapitalPurchaseSettlement::settle($purchase, ['date' => '2026-06-10', 'amount' => '4000', 'payment_mode' => 'cash'], $actor);

        expect(fn () => $purchase->cancel($actor, 'Mistake'))->toThrow(InvalidArgumentException::class, 'settlements');

        $settlement->cancel($actor, 'Undo');
        $purchase->fresh()->cancel($actor, 'Mistake');

        expect($purchase->fresh()->status)->toBe('cancelled');
    });

    $tenant->delete();
});

test('settlement routes: any user can settle, only an admin can cancel a settlement', function () {
    $domain = 'cs-routes.tenant-test';
    $tenant = csTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        [$purchase, , $actor] = csCreditPurchase();
        $settlement = CapitalPurchaseSettlement::settle($purchase, ['date' => '2026-06-10', 'amount' => '1000', 'payment_mode' => 'cash'], $actor);
        User::factory()->create(['email' => 'staff@example.com', 'role_id' => Role::where('slug', '!=', 'admin')->value('id')]);
        $ids = [$purchase->id, $settlement->id];
    });

    $this->post("http://{$domain}/login", ['email' => 'staff@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/capital-purchases/{$ids[0]}/settlements", [
        'date' => '2026-06-12', 'amount' => '500', 'payment_mode' => 'cash',
    ])->assertRedirect();

    $this->post("http://{$domain}/capital-purchases/{$ids[0]}/settlements", [
        'date' => '2026-06-12', 'amount' => '999999', 'payment_mode' => 'cash',
    ])->assertSessionHasErrors('amount');

    $this->post("http://{$domain}/capital-purchases/settlements/{$ids[1]}/cancel", ['reason' => 'x'])->assertForbidden();

    $tenant->run(function () use ($ids) {
        expect(CapitalPurchase::find($ids[0])->outstandingAmount()->toString())->toBe('8500.00');
    });

    $tenant->delete();
});

test('cancelling a capital purchase cancels its untouched fixed asset', function () {
    $tenant = csTenant('cs-asset-cancel.tenant-test');

    $tenant->run(function () {
        csOpenYear();
        $actor = csActor();
        $group = AccountGroup::factory()->create(['name' => 'Fixed Assets']);
        $assetAccount = Account::factory()->underGroup()->create(['account_group_id' => $group->id]);

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [[
                'account_id' => $assetAccount->id, 'amount' => '85000', 'create_asset' => true,
                'asset_name' => 'Van', 'depreciation_category' => 'Pool B', 'depreciation_method' => 'wdv',
                'depreciation_rate' => '20', 'salvage_value' => '0',
            ]],
            $actor,
        );

        $asset = $purchase->lines()->sole()->fixedAsset;
        $purchase->cancel($actor, 'Entered twice');

        expect($asset->fresh()->status)->toBe('cancelled');
    });

    $tenant->delete();
});

test('a capital purchase whose asset already has depreciation cannot be cancelled', function () {
    $tenant = csTenant('cs-asset-depreciated.tenant-test');

    $tenant->run(function () {
        csOpenYear();
        $actor = csActor();
        $group = AccountGroup::factory()->create(['name' => 'Fixed Assets']);
        $assetAccount = Account::factory()->underGroup()->create(['account_group_id' => $group->id]);

        $purchase = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [[
                'account_id' => $assetAccount->id, 'amount' => '85000', 'create_asset' => true,
                'asset_name' => 'Van', 'depreciation_category' => 'Pool B', 'depreciation_method' => 'wdv',
                'depreciation_rate' => '20', 'salvage_value' => '0',
            ]],
            $actor,
        );

        $asset = $purchase->lines()->sole()->fixedAsset;
        FixedAssetDepreciation::create([
            'fixed_asset_id' => $asset->id,
            'fiscal_year_id' => FiscalYear::query()->value('id'),
            'journal_voucher_id' => $purchase->journal_voucher_id,
            'posted_date' => '2026-12-31',
            'opening_wdv' => '85000.00',
            'depreciation_amount' => '17000.00',
            'closing_wdv' => '68000.00',
        ]);

        expect(fn () => $purchase->cancel($actor, 'Too late'))->toThrow(InvalidArgumentException::class, 'depreciated or disposed');
        expect($purchase->fresh()->status)->toBe('posted')
            ->and($asset->fresh()->status)->toBe('active');
    });

    $tenant->delete();
});

test('the payment account must be a cash or bank type account and line accounts must not be reserved', function () {
    $tenant = csTenant('cs-accounts.tenant-test');

    $tenant->run(function () {
        csOpenYear();
        $actor = csActor();
        $supplier = Supplier::factory()->create();
        $expense = Account::factory()->create();
        $bank = Account::factory()->create();

        $post = fn (array $data, array $lines) => CapitalPurchase::post(
            array_merge(['type' => 'service', 'date' => '2026-06-01'], $data),
            $lines,
            $actor,
        );

        // Supplier control account as the bank account.
        expect(fn () => $post(
            ['payment_mode' => 'bank', 'bank_account_id' => $supplier->account_id],
            [['account_id' => $expense->id, 'amount' => '100']],
        ))->toThrow(InvalidArgumentException::class, 'payment account');

        // Input VAT as a line account.
        $vatAccount = Account::where('code', 'ASA23')->firstOrFail();
        expect(fn () => $post(
            ['payment_mode' => 'cash'],
            [['account_id' => $vatAccount->id, 'amount' => '100']],
        ))->toThrow(InvalidArgumentException::class, 'expense or asset account');

        // Supplier ledger as a line account.
        expect(fn () => $post(
            ['payment_mode' => 'cash'],
            [['account_id' => $supplier->account_id, 'amount' => '100']],
        ))->toThrow(InvalidArgumentException::class, 'expense or asset account');

        // Line account equal to the payment account.
        expect(fn () => $post(
            ['payment_mode' => 'bank', 'bank_account_id' => $bank->id],
            [['account_id' => $bank->id, 'amount' => '100']],
        ))->toThrow(InvalidArgumentException::class, 'expense or asset account');

        // A normal purchase still posts.
        expect($post(
            ['payment_mode' => 'bank', 'bank_account_id' => $bank->id],
            [['account_id' => $expense->id, 'amount' => '100']],
        )->status)->toBe('posted');
    });

    $tenant->delete();
});
