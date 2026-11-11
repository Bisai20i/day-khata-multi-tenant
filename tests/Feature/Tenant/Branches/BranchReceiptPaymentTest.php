<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Branch;
use App\Models\CapitalPurchase;
use App\Models\CapitalPurchaseSettlement;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchBalancer;
use App\Support\Branches\BranchContext;
use App\Support\Money\Money;
use App\Support\SettlementNarration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Receipts, payments and settlements across branches (plan 12, BR07)
|--------------------------------------------------------------------------
|
| A receipt or payment is filed where the money moved. It may settle
| documents of other branches: the party line follows each document's
| branch, the cash or bank line the receipt's or payment's branch, and the
| unallocated remainder stays in the receipt's or payment's branch.
| Inter-Branch Clearing lines (hub = the receipt's or payment's branch) keep
| every branch balanced (I1). Cancelling mirrors every line in its branch.
| Outstanding balances and cancel blockers see every branch. With only Main,
| the vouchers are exactly what they always were.
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    auth()->forgetGuards();
    tenancy()->end();
});

function provisionBranchReceiptTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function branchReceiptOpenYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function branchReceiptAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

/**
 * An admin limited to $branches, read back so all_branches is a loaded value.
 *
 * @param  list<Branch>  $branches
 */
function branchReceiptRestrictedUser(array $branches, ?Branch $default = null): User
{
    $user = branchReceiptAdmin();
    $user->forceFill(['all_branches' => false, 'default_branch_id' => $default?->id])->save();
    $user->branches()->attach(array_map(fn (Branch $branch): int => $branch->id, $branches));

    return User::query()->findOrFail($user->id);
}

function branchReceiptMainStore(): Store
{
    return Store::query()->where('name', 'Main Store')->firstOrFail();
}

/**
 * Another branch with one active store of its own.
 *
 * @return array{0: Branch, 1: Store}
 */
function branchReceiptBranch(string $name): array
{
    $branch = Branch::factory()->create(['name' => $name]);
    $store = Store::factory()->create(['name' => "{$name} Store", 'branch_id' => $branch->id]);

    return [$branch, $store];
}

function branchReceiptClearingAccount(): Account
{
    return Account::where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->firstOrFail();
}

function branchReceiptCashId(): int
{
    return (int) Account::query()->where('code', 'AS1')->value('id');
}

/**
 * Non-vatable, stockable goods with negative stock allowed, so a bill's total
 * is exactly quantity x rate and the tests stay about branches.
 */
function branchReceiptItem(): Item
{
    CompanySetting::current()->update(['allow_negative_stock' => true]);

    return Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
}

function branchReceiptCreditSale(Customer $customer, Item $item, Store $store, string $rate, User $actor): Sale
{
    return Sale::post(
        ['customer_id' => $customer->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'store_id' => $store->id],
        [['item_id' => $item->id, 'quantity' => '1', 'rate' => $rate]],
        $actor,
    );
}

function branchReceiptCreditPurchase(Supplier $supplier, Item $item, Store $store, string $rate, User $actor): Purchase
{
    return Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'store_id' => $store->id],
        [['item_id' => $item->id, 'quantity' => '1', 'rate' => $rate]],
        $actor,
    );
}

/**
 * Each line of a voucher in id order as [branch, account, debit, credit,
 * counterparty branch].
 *
 * @return list<array{0: int, 1: int, 2: string, 3: string, 4: int|null}>
 */
function branchReceiptLineRows(JournalVoucher $voucher): array
{
    return BranchContext::unrestricted(fn () => JournalVoucherLine::query()
        ->where('journal_voucher_id', $voucher->id)
        ->orderBy('id')
        ->get())
        ->map(fn (JournalVoucherLine $line): array => [
            (int) $line->branch_id,
            (int) $line->account_id,
            $line->debit,
            $line->credit,
            $line->counterparty_branch_id === null ? null : (int) $line->counterparty_branch_id,
        ])
        ->all();
}

/**
 * Debit minus credit per branch over $voucher's lines (I1: every value is
 * 0.00), ascending branch id.
 *
 * @return array<int, string>
 */
function branchReceiptNetByBranch(JournalVoucher $voucher): array
{
    $nets = [];

    foreach (branchReceiptLineRows($voucher) as [$branchId, , $debit, $credit]) {
        $nets[$branchId] = ($nets[$branchId] ?? Money::zero())->plus($debit)->minus($credit);
    }

    ksort($nets);

    return array_map(fn (Money $net): string => $net->toString(), $nets);
}

/**
 * Debit minus credit of the clearing account per branch, over every voucher.
 *
 * @return array<int, string>
 */
function branchReceiptClearingBalances(Account $clearing): array
{
    $nets = [];

    foreach (BranchContext::unrestricted(fn () => JournalVoucherLine::query()->where('account_id', $clearing->id)->orderBy('id')->get()) as $line) {
        $nets[(int) $line->branch_id] = ($nets[(int) $line->branch_id] ?? Money::zero())->plus($line->debit)->minus($line->credit);
    }

    ksort($nets);

    return array_map(fn (Money $net): string => $net->toString(), $nets);
}

test('a receipt and a payment filed in a non-Main branch keep that branch on the row and on every voucher line', function () {
    $tenant = provisionBranchReceiptTenant('br-receipt-roundtrip.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        [$pokhara] = branchReceiptBranch('Pokhara');

        $receipt = Receipt::post(['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-02', 'amount' => '150', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id], $admin);
        $payment = Payment::post(['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-02', 'amount' => '90', 'payment_mode' => 'cash', 'branch_id' => (string) $pokhara->id], $admin);

        // Read raw: were branch_id missing from #[Fillable], mass assignment
        // would drop it and BelongsToBranch would quietly stamp Main.
        expect((int) DB::table('receipts')->where('id', $receipt->id)->value('branch_id'))->toBe($pokhara->id)
            ->and((int) DB::table('payments')->where('id', $payment->id)->value('branch_id'))->toBe($pokhara->id)
            ->and($receipt->fresh()->branch_id)->toBe($pokhara->id)
            ->and($payment->fresh()->branch_id)->toBe($pokhara->id);

        foreach ([$receipt, $payment] as $document) {
            $voucher = $document->journalVoucher()->firstOrFail();

            expect((int) $voucher->branch_id)->toBe($pokhara->id)
                ->and(array_values(array_unique(array_column(branchReceiptLineRows($voucher), 0))))->toBe([$pokhara->id])
                ->and(array_filter(array_column(branchReceiptLineRows($voucher), 4)))->toBe([]);
        }
    });

    $tenant->delete();
});

test('branch A receiving for branch B\'s invoice puts the customer line in B, cash in A, and clears between them; cancel mirrors it', function () {
    $tenant = provisionBranchReceiptTenant('br-receipt-cross.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        $mainId = Branch::main()->id;
        [$pokhara] = branchReceiptBranch('Pokhara');
        $clearing = branchReceiptClearingAccount();
        $customer = Customer::factory()->create();
        $sale = branchReceiptCreditSale($customer, branchReceiptItem(), branchReceiptMainStore(), '500.00', $admin);

        expect($sale->branch_id)->toBe($mainId)
            ->and($sale->outstandingAmount()->toString())->toBe('500.00');

        $receipt = Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-02', 'amount' => '300', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id,
            'allocations' => [['sale_id' => $sale->id, 'amount' => '300']],
        ], $admin);

        $voucher = $receipt->journalVoucher()->firstOrFail();

        expect($receipt->branch_id)->toBe($pokhara->id)
            ->and((int) $voucher->branch_id)->toBe($pokhara->id)
            ->and(branchReceiptLineRows($voucher))->toBe([
                [$pokhara->id, branchReceiptCashId(), '300.00', '0.00', null],
                [$mainId, (int) $customer->account_id, '0.00', '300.00', null],
                [$mainId, $clearing->id, '300.00', '0.00', $pokhara->id],
                [$pokhara->id, $clearing->id, '0.00', '300.00', $mainId],
            ])
            ->and(branchReceiptNetByBranch($voucher))->toBe([$mainId => '0.00', $pokhara->id => '0.00'])
            ->and($sale->fresh()->outstandingAmount()->toString())->toBe('200.00')
            // Pokhara holds the cash, so it owes Main: Main Dr, Pokhara Cr.
            ->and(branchReceiptClearingBalances($clearing))->toBe([$mainId => '300.00', $pokhara->id => '-300.00']);

        // lineNarration names the receipt's own lines only; the system
        // clearing lines keep their own narration.
        $settlementNarration = SettlementNarration::line((string) $voucher->voucher_number, 'cash');

        expect(BranchContext::unrestricted(fn () => $voucher->lines()->orderBy('id')->pluck('narration')->all()))->toBe([
            $settlementNarration,
            $settlementNarration,
            BranchBalancer::CLEARING_NARRATION,
            BranchBalancer::CLEARING_NARRATION,
        ]);

        $receipt->cancel($admin, 'Wrong branch');

        $reversal = JournalVoucher::findOrFail($receipt->fresh()->reversal_journal_voucher_id);

        expect((int) $reversal->branch_id)->toBe($pokhara->id)
            ->and(branchReceiptLineRows($reversal))->toBe([
                [$pokhara->id, branchReceiptCashId(), '0.00', '300.00', null],
                [$mainId, (int) $customer->account_id, '300.00', '0.00', null],
                [$mainId, $clearing->id, '0.00', '300.00', $pokhara->id],
                [$pokhara->id, $clearing->id, '300.00', '0.00', $mainId],
            ])
            ->and($sale->fresh()->outstandingAmount()->toString())->toBe('500.00')
            ->and(branchReceiptClearingBalances($clearing))->toBe([$mainId => '0.00', $pokhara->id => '0.00']);
    });

    $tenant->delete();
});

test('Main paying a Pokhara bill puts the supplier line in Pokhara, the on-account remainder and cash in Main, and clears; cancel restores the bill', function () {
    $tenant = provisionBranchReceiptTenant('br-payment-cross.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        $mainId = Branch::main()->id;
        [$pokhara, $pokharaStore] = branchReceiptBranch('Pokhara');
        $clearing = branchReceiptClearingAccount();
        $supplier = Supplier::factory()->create();
        $purchase = branchReceiptCreditPurchase($supplier, branchReceiptItem(), $pokharaStore, '1000.00', $admin);

        expect($purchase->branch_id)->toBe($pokhara->id);

        // 500 paid, 400 of it against the bill, 100 on account.
        $payment = Payment::post([
            'supplier_id' => $supplier->id, 'date' => '2026-06-02', 'amount' => '500', 'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchase->id, 'amount' => '400']],
        ], $admin);

        $voucher = $payment->journalVoucher()->firstOrFail();

        expect($payment->branch_id)->toBe($mainId)
            ->and(branchReceiptLineRows($voucher))->toBe([
                [$mainId, (int) $supplier->account_id, '100.00', '0.00', null],
                [$pokhara->id, (int) $supplier->account_id, '400.00', '0.00', null],
                [$mainId, branchReceiptCashId(), '0.00', '500.00', null],
                [$pokhara->id, $clearing->id, '0.00', '400.00', $mainId],
                [$mainId, $clearing->id, '400.00', '0.00', $pokhara->id],
            ])
            ->and(branchReceiptNetByBranch($voucher))->toBe([$mainId => '0.00', $pokhara->id => '0.00'])
            ->and($purchase->fresh()->outstandingAmount()->toString())->toBe('600.00')
            ->and(branchReceiptClearingBalances($clearing))->toBe([$mainId => '400.00', $pokhara->id => '-400.00']);

        $payment->cancel($admin, 'Paid twice');

        $reversal = JournalVoucher::findOrFail($payment->fresh()->reversal_journal_voucher_id);

        expect(branchReceiptNetByBranch($reversal))->toBe([$mainId => '0.00', $pokhara->id => '0.00'])
            ->and(array_map(fn (array $row): array => [$row[0], $row[4]], branchReceiptLineRows($reversal)))
            ->toBe(array_map(fn (array $row): array => [$row[0], $row[4]], branchReceiptLineRows($voucher)))
            ->and($purchase->fresh()->outstandingAmount()->toString())->toBe('1000.00')
            ->and(branchReceiptClearingBalances($clearing))->toBe([$mainId => '0.00', $pokhara->id => '0.00']);
    });

    $tenant->delete();
});

test('one receipt settling invoices of three branches clears hub and spoke around the receipt\'s branch', function () {
    $tenant = provisionBranchReceiptTenant('br-receipt-three.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        $mainId = Branch::main()->id;
        [$pokhara, $pokharaStore] = branchReceiptBranch('Pokhara');
        [$butwal, $butwalStore] = branchReceiptBranch('Butwal');
        $clearing = branchReceiptClearingAccount();
        $customer = Customer::factory()->create();
        $item = branchReceiptItem();

        $mainSale = branchReceiptCreditSale($customer, $item, branchReceiptMainStore(), '100.00', $admin);
        $pokharaSale = branchReceiptCreditSale($customer, $item, $pokharaStore, '200.00', $admin);
        $butwalSale = branchReceiptCreditSale($customer, $item, $butwalStore, '300.00', $admin);

        // Rows deliberately out of id order; 100 stays on account in Main.
        $receipt = Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-02', 'amount' => '700', 'payment_mode' => 'cash',
            'allocations' => [
                ['sale_id' => $butwalSale->id, 'amount' => '300'],
                ['sale_id' => $mainSale->id, 'amount' => '100'],
                ['sale_id' => $pokharaSale->id, 'amount' => '200'],
            ],
        ], $admin);

        $voucher = $receipt->journalVoucher()->firstOrFail();
        $customerAccountId = (int) $customer->account_id;

        expect(branchReceiptLineRows($voucher))->toBe([
            [$mainId, branchReceiptCashId(), '700.00', '0.00', null],
            [$mainId, $customerAccountId, '0.00', '200.00', null],
            [$pokhara->id, $customerAccountId, '0.00', '200.00', null],
            [$butwal->id, $customerAccountId, '0.00', '300.00', null],
            [$pokhara->id, $clearing->id, '200.00', '0.00', $mainId],
            [$mainId, $clearing->id, '0.00', '200.00', $pokhara->id],
            [$butwal->id, $clearing->id, '300.00', '0.00', $mainId],
            [$mainId, $clearing->id, '0.00', '300.00', $butwal->id],
        ])
            ->and(branchReceiptNetByBranch($voucher))->toBe([$mainId => '0.00', $pokhara->id => '0.00', $butwal->id => '0.00'])
            ->and($mainSale->fresh()->outstandingAmount()->toString())->toBe('0.00')
            ->and($pokharaSale->fresh()->outstandingAmount()->toString())->toBe('0.00')
            ->and($butwalSale->fresh()->outstandingAmount()->toString())->toBe('0.00')
            ->and(branchReceiptClearingBalances($clearing))->toBe([$mainId => '-500.00', $pokhara->id => '200.00', $butwal->id => '300.00']);
    });

    $tenant->delete();
});

test('a cross-branch receipt with no clearing account is a form error and writes nothing', function () {
    $domain = 'br-receipt-no-clearing.tenant-test';
    $tenant = provisionBranchReceiptTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        branchReceiptOpenYear();
        [$pokhara, $pokharaStore] = branchReceiptBranch('Pokhara');
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = branchReceiptItem();

        $ids = [
            'pokhara' => $pokhara->id,
            'customer' => $customer->id,
            'supplier' => $supplier->id,
            'sale' => branchReceiptCreditSale($customer, $item, branchReceiptMainStore(), '500.00', $owner)->id,
            'purchase' => branchReceiptCreditPurchase($supplier, $item, $pokharaStore, '500.00', $owner)->id,
            'vouchers' => JournalVoucher::query()->count(),
        ];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/receipts", [
        'customer_id' => $ids['customer'], 'date' => '2026-06-02', 'amount' => '100', 'payment_mode' => 'cash', 'branch_id' => $ids['pokhara'],
        'allocations' => [['sale_id' => $ids['sale'], 'amount' => '100']],
    ])->assertSessionHasErrors('amount');

    $this->post("http://{$domain}/payments", [
        'supplier_id' => $ids['supplier'], 'date' => '2026-06-02', 'amount' => '100', 'payment_mode' => 'cash',
        'allocations' => [['purchase_id' => $ids['purchase'], 'amount' => '100']],
    ])->assertSessionHasErrors('amount');

    $tenant->run(function () use ($ids) {
        expect(Receipt::query()->count())->toBe(0)
            ->and(Payment::query()->count())->toBe(0)
            ->and(JournalVoucher::query()->count())->toBe($ids['vouchers'])
            ->and(Sale::query()->findOrFail($ids['sale'])->outstandingAmount()->toString())->toBe('500.00');
    });

    $tenant->delete();
});

test('a restricted user cannot cancel a sale paid from a branch they cannot see, nor allocate to an invoice of such a branch', function () {
    $tenant = provisionBranchReceiptTenant('br-receipt-restricted.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        [$pokhara, $pokharaStore] = branchReceiptBranch('Pokhara');
        branchReceiptClearingAccount();
        $clerk = branchReceiptRestrictedUser([$pokhara], $pokhara);
        $customer = Customer::factory()->create();
        $item = branchReceiptItem();

        $pokharaSale = branchReceiptCreditSale($customer, $item, $pokharaStore, '400.00', $admin);
        $mainSale = branchReceiptCreditSale($customer, $item, branchReceiptMainStore(), '250.00', $admin);

        // Main takes money for Pokhara's invoice; the clerk cannot see that receipt.
        Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-02', 'amount' => '150', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $pokharaSale->id, 'amount' => '150']],
        ], $admin);

        expect(BranchContext::restrictedTo($clerk, fn () => Receipt::query()->count()))->toBe(0)
            // ...but the invoice's outstanding balance still counts it.
            ->and(BranchContext::restrictedTo($clerk, fn () => Sale::query()->findOrFail($pokharaSale->id)->outstandingAmount()->toString()))->toBe('250.00');

        expect(fn () => BranchContext::restrictedTo($clerk, fn () => Sale::query()->findOrFail($pokharaSale->id)->cancel($clerk, 'Wrong customer')))
            ->toThrow(InvalidArgumentException::class, 'payment received against it');

        expect($pokharaSale->fresh()->status)->not->toBe('cancelled');

        $vouchers = JournalVoucher::query()->count();

        // A Pokhara receipt allocated to Main's invoice: refused, nothing written.
        expect(fn () => BranchContext::restrictedTo($clerk, fn () => Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-03', 'amount' => '50', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $mainSale->id, 'amount' => '50']],
        ], $clerk)))->toThrow(InvalidArgumentException::class, 'does not exist or is not available to you');

        // Nor can the clerk file a receipt in Main.
        expect(fn () => BranchContext::restrictedTo($clerk, fn () => Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-03', 'amount' => '50', 'payment_mode' => 'cash', 'branch_id' => Branch::main()->id,
        ], $clerk)))->toThrow(InvalidArgumentException::class);

        expect(Receipt::query()->count())->toBe(1)
            ->and(JournalVoucher::query()->count())->toBe($vouchers)
            ->and($mainSale->fresh()->outstandingAmount()->toString())->toBe('250.00');

        // Within their own branch the clerk settles normally, in one branch.
        $own = BranchContext::restrictedTo($clerk, fn () => Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-03', 'amount' => '250', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $pokharaSale->id, 'amount' => '250']],
        ], $clerk));

        expect($own->branch_id)->toBe($pokhara->id)
            ->and(array_values(array_unique(array_column(branchReceiptLineRows($own->journalVoucher()->firstOrFail()), 0))))->toBe([$pokhara->id])
            ->and($pokharaSale->fresh()->outstandingAmount()->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('a capital purchase settled from another branch debits the supplier in the bill\'s branch and clears; cancel restores the bill', function () {
    $tenant = provisionBranchReceiptTenant('br-capital-settlement.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        $mainId = Branch::main()->id;
        [$pokhara, $pokharaStore] = branchReceiptBranch('Pokhara');
        $clearing = branchReceiptClearingAccount();
        $supplier = Supplier::factory()->create();

        $fixedAssetsGroup = AccountGroup::factory()->create(['name' => 'Fixed Assets']);
        $assetAccount = Account::factory()->underGroup()->create(['account_group_id' => $fixedAssetsGroup->id]);

        $bill = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'credit', 'supplier_id' => $supplier->id, 'store_id' => $pokharaStore->id],
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

        $before = $bill->fresh()->outstandingAmount();

        $settlement = CapitalPurchaseSettlement::settle($bill, ['date' => '2026-06-05', 'amount' => '1000', 'payment_mode' => 'cash'], $admin);
        $voucher = $settlement->journalVoucher()->firstOrFail();

        expect((int) DB::table('capital_purchase_settlements')->where('id', $settlement->id)->value('branch_id'))->toBe($mainId)
            ->and(branchReceiptLineRows($voucher))->toBe([
                [$pokhara->id, (int) $supplier->account_id, '1000.00', '0.00', null],
                [$mainId, branchReceiptCashId(), '0.00', '1000.00', null],
                [$pokhara->id, $clearing->id, '0.00', '1000.00', $mainId],
                [$mainId, $clearing->id, '1000.00', '0.00', $pokhara->id],
            ])
            ->and(branchReceiptNetByBranch($voucher))->toBe([$mainId => '0.00', $pokhara->id => '0.00'])
            ->and($bill->fresh()->outstandingAmount()->toString())->toBe($before->minus('1000')->toString());

        // Settled in the bill's own branch: two lines, no clearing.
        $own = CapitalPurchaseSettlement::settle($bill, ['date' => '2026-06-05', 'amount' => '500', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id], $admin);

        expect(branchReceiptLineRows($own->journalVoucher()->firstOrFail()))->toBe([
            [$pokhara->id, (int) $supplier->account_id, '500.00', '0.00', null],
            [$pokhara->id, branchReceiptCashId(), '0.00', '500.00', null],
        ]);

        $settlement->cancel($admin, 'Wrong branch');
        $own->cancel($admin, 'Wrong amount');

        $reversal = JournalVoucher::findOrFail($settlement->fresh()->reversal_journal_voucher_id);

        expect(branchReceiptNetByBranch($reversal))->toBe([$mainId => '0.00', $pokhara->id => '0.00'])
            ->and($bill->fresh()->outstandingAmount()->toString())->toBe($before->toString())
            ->and(branchReceiptClearingBalances($clearing))->toBe([$mainId => '0.00', $pokhara->id => '0.00']);
    });

    $tenant->delete();
});

test('a manual journal can move a balance between branches and gets clearing lines; a missing clearing account is a form error', function () {
    $domain = 'br-manual-journal.tenant-test';
    $tenant = provisionBranchReceiptTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        User::factory()->create(['email' => 'owner@example.com']);
        branchReceiptOpenYear();
        [$pokhara] = branchReceiptBranch('Pokhara');

        $ids = ['pokhara' => $pokhara->id, 'main' => Branch::main()->id, 'cash' => branchReceiptCashId()];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $cutOver = [
        'date' => '2026-06-10',
        'narration' => 'Move opening cash to Pokhara',
        'branch_id' => $ids['main'],
        'lines' => [
            ['account_id' => $ids['cash'], 'debit' => '250', 'credit' => null, 'branch_id' => $ids['pokhara']],
            ['account_id' => $ids['cash'], 'debit' => null, 'credit' => '250'],
        ],
    ];

    // No clearing account yet: refused on the form, nothing posted.
    $this->post("http://{$domain}/journal-vouchers", $cutOver)->assertSessionHasErrors('lines');

    $tenant->run(fn () => expect(JournalVoucher::query()->count())->toBe(0));

    $clearingId = $tenant->run(fn () => branchReceiptClearingAccount()->id);

    $this->post("http://{$domain}/journal-vouchers", $cutOver)->assertSessionHasNoErrors();

    // The same account twice in the same branch is still refused.
    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-10',
        'narration' => 'Duplicate',
        'lines' => [
            ['account_id' => $ids['cash'], 'debit' => '10', 'credit' => null],
            ['account_id' => $ids['cash'], 'debit' => null, 'credit' => '10'],
        ],
    ])->assertSessionHasErrors('lines.1.account_id');

    $tenant->run(function () use ($ids, $clearingId) {
        $voucher = JournalVoucher::query()->sole();

        expect((int) $voucher->branch_id)->toBe($ids['main'])
            ->and(branchReceiptLineRows($voucher))->toBe([
                [$ids['pokhara'], $ids['cash'], '250.00', '0.00', null],
                [$ids['main'], $ids['cash'], '0.00', '250.00', null],
                [$ids['pokhara'], $clearingId, '0.00', '250.00', $ids['main']],
                [$ids['main'], $clearingId, '250.00', '0.00', $ids['pokhara']],
            ])
            ->and(branchReceiptNetByBranch($voucher))->toBe([$ids['main'] => '0.00', $ids['pokhara'] => '0.00']);
    });

    $tenant->delete();
});

test('a Main-only company posts receipts, payments and journals exactly as before branches existed', function () {
    $domain = 'br-receipt-parity.tenant-test';
    $tenant = provisionBranchReceiptTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        branchReceiptOpenYear();
        $mainId = Branch::main()->id;
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = branchReceiptItem();
        $sale = branchReceiptCreditSale($customer, $item, branchReceiptMainStore(), '500.00', $owner);
        $purchase = branchReceiptCreditPurchase($supplier, $item, branchReceiptMainStore(), '800.00', $owner);

        // No clearing account exists: any attempt to clear would throw.
        $receipt = Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-02', 'amount' => '600', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => '500']],
        ], $owner);
        $second = Receipt::post(['customer_id' => $customer->id, 'date' => '2026-06-03', 'amount' => '75.50', 'payment_mode' => 'cash'], $owner);
        $payment = Payment::post([
            'supplier_id' => $supplier->id, 'date' => '2026-06-02', 'amount' => '800', 'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchase->id, 'amount' => '800']],
        ], $owner);

        $receiptVoucher = $receipt->journalVoucher()->firstOrFail();
        $paymentVoucher = $payment->journalVoucher()->firstOrFail();

        expect($receipt->branch_id)->toBe($mainId)
            ->and($payment->branch_id)->toBe($mainId)
            ->and((int) $receiptVoucher->voucher_number)->toBe(1)
            ->and((int) $second->journalVoucher()->firstOrFail()->voucher_number)->toBe(2)
            ->and((int) $paymentVoucher->voucher_number)->toBe(1)
            ->and($payment->payment_number)->toBe('PMT-1')
            ->and(branchReceiptLineRows($receiptVoucher))->toBe([
                [$mainId, branchReceiptCashId(), '600.00', '0.00', null],
                [$mainId, (int) $customer->account_id, '0.00', '600.00', null],
            ])
            ->and($receiptVoucher->lines()->orderBy('id')->pluck('narration')->unique()->values()->all())
            ->toBe([SettlementNarration::line($receiptVoucher->voucher_number, 'cash')])
            ->and(branchReceiptLineRows($paymentVoucher))->toBe([
                [$mainId, (int) $supplier->account_id, '800.00', '0.00', null],
                [$mainId, branchReceiptCashId(), '0.00', '800.00', null],
            ])
            ->and($sale->fresh()->outstandingAmount()->toString())->toBe('0.00')
            ->and($purchase->fresh()->outstandingAmount()->toString())->toBe('0.00');

        $ids = ['main' => $mainId, 'cash' => branchReceiptCashId(), 'other' => Account::factory()->create()->id];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-10',
        'narration' => 'Plain journal',
        'lines' => [
            ['account_id' => $ids['other'], 'debit' => '40', 'credit' => null, 'narration' => 'Dr side'],
            ['account_id' => $ids['cash'], 'debit' => null, 'credit' => '40', 'narration' => 'Cr side'],
        ],
    ])->assertSessionHasNoErrors();

    $tenant->run(function () use ($ids) {
        $journal = JournalVoucher::query()->where('narration', 'Plain journal')->sole();

        expect((int) $journal->branch_id)->toBe($ids['main'])
            ->and((int) $journal->voucher_number)->toBe(1)
            ->and(branchReceiptLineRows($journal))->toBe([
                [$ids['main'], $ids['other'], '40.00', '0.00', null],
                [$ids['main'], $ids['cash'], '0.00', '40.00', null],
            ])
            ->and($journal->lines()->orderBy('id')->pluck('narration')->all())->toBe(['Dr side', 'Cr side']);
    });

    $tenant->delete();
});

test('a manual journal that does not balance in total is still refused with auto-balancing on', function () {
    $domain = 'br-manual-journal-unbalanced.tenant-test';
    $tenant = provisionBranchReceiptTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        User::factory()->create(['email' => 'owner@example.com']);
        branchReceiptOpenYear();
        [$pokhara] = branchReceiptBranch('Pokhara');
        branchReceiptClearingAccount();

        $ids = ['pokhara' => $pokhara->id, 'cash' => branchReceiptCashId(), 'other' => Account::factory()->create()->id];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-10',
        'narration' => 'Unbalanced',
        'lines' => [
            ['account_id' => $ids['other'], 'debit' => '100', 'credit' => null, 'branch_id' => $ids['pokhara']],
            ['account_id' => $ids['cash'], 'debit' => null, 'credit' => '90'],
        ],
    ])->assertSessionHasErrors(['lines' => 'Total debit must equal total credit.']);

    $tenant->run(fn () => expect(JournalVoucher::query()->count())->toBe(0));

    $tenant->delete();
});

test('the manual journal duplicate check compares the effective branch and integer account ids', function () {
    $domain = 'br-manual-journal-duplicates.tenant-test';
    $tenant = provisionBranchReceiptTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        User::factory()->create(['email' => 'owner@example.com']);
        branchReceiptOpenYear();

        $ids = ['main' => Branch::main()->id, 'cash' => branchReceiptCashId()];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $duplicate = 'Each account may appear only once per branch on a journal voucher.';

    // An explicitly Main-tagged line and an untagged line (which posts in
    // Main, the owner's posting branch) on the same account.
    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-10',
        'narration' => 'Duplicate in Main',
        'lines' => [
            ['account_id' => $ids['cash'], 'debit' => '10', 'credit' => null, 'branch_id' => $ids['main']],
            ['account_id' => $ids['cash'], 'debit' => null, 'credit' => '10'],
        ],
    ])->assertSessionHasErrors(['lines.0.account_id' => $duplicate, 'lines.1.account_id' => $duplicate]);

    // "07" and "7" name the same account.
    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-10',
        'narration' => 'Duplicate with leading zero',
        'lines' => [
            ['account_id' => (string) $ids['cash'], 'debit' => '10', 'credit' => null],
            ['account_id' => '0'.$ids['cash'], 'debit' => null, 'credit' => '10'],
        ],
    ])->assertSessionHasErrors(['lines.1.account_id' => $duplicate]);

    $tenant->run(fn () => expect(JournalVoucher::query()->count())->toBe(0));

    $tenant->delete();
});

test('a restricted user gets form errors for cancelling a cross-branch receipt and for allocating to hidden documents', function () {
    $domain = 'br-receipt-restricted-http.tenant-test';
    $tenant = provisionBranchReceiptTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        $admin = branchReceiptAdmin();
        branchReceiptOpenYear();
        [$pokhara] = branchReceiptBranch('Pokhara');
        branchReceiptClearingAccount();
        $clerk = branchReceiptRestrictedUser([$pokhara], $pokhara);
        $clerk->forceFill(['email' => 'clerk@example.com'])->save();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = branchReceiptItem();

        $mainSale = branchReceiptCreditSale($customer, $item, branchReceiptMainStore(), '500.00', $admin);
        $mainPurchase = branchReceiptCreditPurchase($supplier, $item, branchReceiptMainStore(), '500.00', $admin);

        // Filed in the clerk's branch, but it settles a Main invoice, so its
        // voucher touches Main too.
        $receipt = Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-02', 'amount' => '200', 'payment_mode' => 'cash', 'branch_id' => $pokhara->id,
            'allocations' => [['sale_id' => $mainSale->id, 'amount' => '200']],
        ], $admin);

        $ids = [
            'receipt' => $receipt->id,
            'customer' => $customer->id,
            'supplier' => $supplier->id,
            'mainSale' => $mainSale->id,
            'mainPurchase' => $mainPurchase->id,
            'vouchers' => JournalVoucher::query()->count(),
        ];
    });

    $this->post("http://{$domain}/login", ['email' => 'clerk@example.com', 'password' => 'password']);

    // reverse() refuses to post into Main for the clerk
    // (AuthorizationException): a form error, not a 403 or 500 page.
    $this->post("http://{$domain}/receipts/{$ids['receipt']}/cancel", ['reason' => 'Wrong customer'])
        ->assertSessionHasErrors(['reason' => 'You do not have access to post into one of the branches on this entry.']);

    $this->post("http://{$domain}/receipts", [
        'customer_id' => $ids['customer'], 'date' => '2026-06-03', 'amount' => '50', 'payment_mode' => 'cash',
        'allocations' => [['sale_id' => $ids['mainSale'], 'amount' => '50']],
    ])->assertSessionHasErrors(['amount' => "Sale [{$ids['mainSale']}] does not exist or is not available to you."]);

    $this->post("http://{$domain}/payments", [
        'supplier_id' => $ids['supplier'], 'date' => '2026-06-03', 'amount' => '50', 'payment_mode' => 'cash',
        'allocations' => [['purchase_id' => $ids['mainPurchase'], 'amount' => '50']],
    ])->assertSessionHasErrors(['amount' => "Purchase #{$ids['mainPurchase']} does not exist or is not available to you."]);

    // The clerk is still the authenticated user in this process, so read the
    // totals unrestricted: they are company-wide counts, not the clerk's view.
    $tenant->run(function () use ($ids) {
        BranchContext::unrestricted(function () use ($ids): void {
            expect(Receipt::query()->findOrFail($ids['receipt'])->status)->toBe('posted')
                ->and(Receipt::query()->count())->toBe(1)
                ->and(Payment::query()->count())->toBe(0)
                ->and(JournalVoucher::query()->count())->toBe($ids['vouchers'])
                ->and(Sale::query()->findOrFail($ids['mainSale'])->outstandingAmount()->toString())->toBe('300.00');
        });
    });

    $tenant->delete();
});

test('a restricted user learns nothing about a hidden bill, and cannot cancel a bill paid from a hidden branch', function () {
    $tenant = provisionBranchReceiptTenant('br-payment-restricted.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        [$pokhara, $pokharaStore] = branchReceiptBranch('Pokhara');
        branchReceiptClearingAccount();
        $clerk = branchReceiptRestrictedUser([$pokhara], $pokhara);
        $supplier = Supplier::factory()->create();
        $item = branchReceiptItem();

        $hiddenOpen = branchReceiptCreditPurchase($supplier, $item, branchReceiptMainStore(), '700.00', $admin);
        $hiddenOtherSupplier = branchReceiptCreditPurchase(Supplier::factory()->create(), $item, branchReceiptMainStore(), '300.00', $admin);
        $hiddenCancelled = branchReceiptCreditPurchase($supplier, $item, branchReceiptMainStore(), '200.00', $admin);
        $hiddenCancelled->cancel($admin, 'Entered twice');
        $pokharaBill = branchReceiptCreditPurchase($supplier, $item, $pokharaStore, '400.00', $admin);

        $attempt = fn (int $purchaseId) => fn () => BranchContext::restrictedTo($clerk, fn () => Payment::post([
            'supplier_id' => $supplier->id, 'date' => '2026-06-03', 'amount' => '50', 'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchaseId, 'amount' => '50']],
        ], $clerk));

        // Open, another supplier's, cancelled or missing: one message, with
        // no number, amount, supplier or status in it.
        foreach ([$hiddenOpen->id, $hiddenOtherSupplier->id, $hiddenCancelled->id, 999999] as $purchaseId) {
            expect($attempt($purchaseId))->toThrow(InvalidArgumentException::class, "Purchase #{$purchaseId} does not exist or is not available to you.");
        }

        expect(Payment::query()->count())->toBe(0);

        // Main pays the Pokhara bill; the clerk cannot see that payment.
        Payment::post([
            'supplier_id' => $supplier->id, 'date' => '2026-06-02', 'amount' => '100', 'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $pokharaBill->id, 'amount' => '100']],
        ], $admin);

        expect(BranchContext::restrictedTo($clerk, fn () => Payment::query()->count()))->toBe(0)
            ->and(BranchContext::restrictedTo($clerk, fn () => Purchase::query()->findOrFail($pokharaBill->id)->outstandingAmount()->toString()))->toBe('300.00');

        expect(fn () => BranchContext::restrictedTo($clerk, fn () => Purchase::query()->findOrFail($pokharaBill->id)->cancel($clerk, 'Wrong supplier')))
            ->toThrow(InvalidArgumentException::class, 'payment made against it');

        expect($pokharaBill->fresh()->status)->toBe('posted');
    });

    $tenant->delete();
});

test('a restricted user cannot settle a capital purchase of a branch they cannot use', function () {
    $tenant = provisionBranchReceiptTenant('br-settlement-restricted.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        [$pokhara] = branchReceiptBranch('Pokhara');
        branchReceiptClearingAccount();
        $clerk = branchReceiptRestrictedUser([$pokhara], $pokhara);

        $fixedAssetsGroup = AccountGroup::factory()->create(['name' => 'Fixed Assets']);
        $assetAccount = Account::factory()->underGroup()->create(['account_group_id' => $fixedAssetsGroup->id]);

        $bill = CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'credit', 'supplier_id' => Supplier::factory()->create()->id, 'store_id' => branchReceiptMainStore()->id],
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

        $before = $bill->fresh()->outstandingAmount()->toString();
        $vouchers = JournalVoucher::query()->count();

        expect(fn () => BranchContext::restrictedTo($clerk, fn () => CapitalPurchaseSettlement::settle($bill, ['date' => '2026-06-05', 'amount' => '100', 'payment_mode' => 'cash'], $clerk)))
            ->toThrow(InvalidArgumentException::class, 'do not have access');

        expect(CapitalPurchaseSettlement::query()->count())->toBe(0)
            ->and(JournalVoucher::query()->count())->toBe($vouchers)
            ->and($bill->fresh()->outstandingAmount()->toString())->toBe($before);
    });

    $tenant->delete();
});

test('a receipt against a fully settled invoice, or an invoice of an inactive branch, is refused', function () {
    $tenant = provisionBranchReceiptTenant('br-receipt-settled-inactive.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        [$pokhara, $pokharaStore] = branchReceiptBranch('Pokhara');
        branchReceiptClearingAccount();
        $customer = Customer::factory()->create();
        $item = branchReceiptItem();

        $settled = branchReceiptCreditSale($customer, $item, branchReceiptMainStore(), '100.00', $admin);
        Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-02', 'amount' => '100', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $settled->id, 'amount' => '100']],
        ], $admin);

        expect(fn () => Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-03', 'amount' => '10', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $settled->id, 'amount' => '10']],
        ], $admin))->toThrow(InvalidArgumentException::class, 'has nothing outstanding');

        $pokharaSale = branchReceiptCreditSale($customer, $item, $pokharaStore, '300.00', $admin);
        $pokhara->update(['is_active' => false]);
        BranchContext::flush();

        expect(fn () => Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-03', 'amount' => '50', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $pokharaSale->id, 'amount' => '50']],
        ], $admin))->toThrow(InvalidArgumentException::class, 'is not available to you');

        expect(Receipt::query()->count())->toBe(1)
            ->and($pokharaSale->fresh()->outstandingAmount()->toString())->toBe('300.00');
    });

    $tenant->delete();
});

/**
 * Runs $post with the tenant connection's query log on and returns the SQL
 * in order. SQLite drops FOR UPDATE, so this pins down the order the locking
 * queries run in (as PostingLockOrderTest does), not that the locks are held.
 *
 * @return list<string>
 */
function branchReceiptQueryLog(Closure $post): array
{
    $connection = DB::connection('tenant');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $post();

        return array_values(array_map(fn (array $entry): string => $entry['query'], $connection->getQueryLog()));
    } finally {
        $connection->disableQueryLog();
    }
}

/**
 * Position of the first query starting with $prefix, or null.
 *
 * @param  list<string>  $log
 */
function branchReceiptQueryPosition(array $log, string $prefix): ?int
{
    foreach ($log as $position => $query) {
        if (str_starts_with($query, $prefix)) {
            return $position;
        }
    }

    return null;
}

/**
 * Every SELECT that ran before $position.
 *
 * @param  list<string>  $log
 * @return list<string>
 */
function branchReceiptSelectsBefore(array $log, int $position): array
{
    return array_values(array_filter(array_slice($log, 0, $position), fn (string $query): bool => str_starts_with($query, 'select')));
}

test('receipts and payments lock their documents before the first plain read of the transaction', function () {
    $tenant = provisionBranchReceiptTenant('br-receipt-lock-order.tenant-test');

    $tenant->run(function () {
        branchReceiptOpenYear();
        $admin = branchReceiptAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = branchReceiptItem();
        $sale = branchReceiptCreditSale($customer, $item, branchReceiptMainStore(), '500.00', $admin);
        $purchase = branchReceiptCreditPurchase($supplier, $item, branchReceiptMainStore(), '500.00', $admin);

        $receiptLog = branchReceiptQueryLog(fn () => Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-02', 'amount' => '100', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => '100']],
        ], $admin));

        $saleLock = branchReceiptQueryPosition($receiptLog, 'select * from "sales" where "id" in');

        // The sale lock is the first SELECT; the customer, branch, account
        // and fiscal-year reads all come after it.
        expect($saleLock)->not->toBeNull()
            ->and(branchReceiptSelectsBefore($receiptLog, $saleLock))->toBe([])
            ->and(branchReceiptQueryPosition($receiptLog, 'select * from "customers"'))->toBeGreaterThan($saleLock)
            ->and(branchReceiptQueryPosition($receiptLog, 'select * from "accounts"'))->toBeGreaterThan($saleLock)
            ->and(branchReceiptQueryPosition($receiptLog, 'select * from "fiscal_years"'))->toBeGreaterThan($saleLock);

        $paymentLog = branchReceiptQueryLog(fn () => Payment::post([
            'supplier_id' => $supplier->id, 'date' => '2026-06-02', 'amount' => '100', 'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchase->id, 'amount' => '100']],
        ], $admin));

        $supplierLock = branchReceiptQueryPosition($paymentLog, 'select * from "suppliers" where "suppliers"."id" = ?');
        $purchaseLock = branchReceiptQueryPosition($paymentLog, 'select * from "purchases" where "id" in');

        // Supplier, then the bills straight after, before any plain read.
        expect($supplierLock)->not->toBeNull()
            ->and(branchReceiptSelectsBefore($paymentLog, $supplierLock))->toBe([])
            ->and($purchaseLock)->toBe($supplierLock + 1)
            ->and(branchReceiptQueryPosition($paymentLog, 'select * from "accounts"'))->toBeGreaterThan($purchaseLock)
            ->and(branchReceiptQueryPosition($paymentLog, 'select * from "fiscal_years"'))->toBeGreaterThan($purchaseLock);
    });

    $tenant->delete();
});
