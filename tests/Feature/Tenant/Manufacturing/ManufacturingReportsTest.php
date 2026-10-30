<?php

use App\Enums\FiscalYearStatus;
use App\Enums\InventoryClass;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\FiscalYearInventorySnapshot;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\MaterialIssueNote;
use App\Models\ProductionEntry;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Inventory\StockCosting;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Manufacturing\ManufacturingReports;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * MC10 reports on the M04a scenario plus one sale:
 *
 *  - buy RM-A 15 @ 50 and RM-B 5 @ 125 (RM 1,375.00), plus 3 trading items @ 40;
 *  - issue RM-A 10 and RM-B 4 to WIP (1,000.00), add labour 300 and power 200
 *    (WIP 1,500.00), complete the planned 50 units as a final run
 *    (FG 1,500.00, WIP 0.00);
 *  - sell 20 FG @ 100: COGS = 20 x 30.00 = 600.00, FG 900.00, RM 375.00.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Builds the scenario inside the current tenant and returns what the tests
 * need. Dates sit inside FY 2026.
 *
 * @return array{admin: User, rmA: Item, rmB: Item, fg: Item, trading: Item, fiscalYear: FiscalYear, entry: ProductionEntry}
 */
function manufacturingReportsScenario(): array
{
    $fiscalYear = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
    CompanySetting::current()->update(['allow_negative_stock' => true]);

    $supplier = Supplier::factory()->create();
    $customer = Customer::factory()->create();
    $rmA = Item::factory()->rawMaterial()->create(['is_vatable' => false]);
    $rmB = Item::factory()->rawMaterial()->create(['is_vatable' => false]);
    $fg = Item::factory()->finishedGood()->create(['is_vatable' => false]);
    $trading = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
    // Direct costs may only be credited to money or payable accounts.
    $creditAccount = Account::where('code', 'AS1')->firstOrFail();

    Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-02-01', 'payment_mode' => 'credit', 'vat_rate' => '0'],
        [
            ['item_id' => $rmA->id, 'quantity' => 15, 'rate' => 50, 'discount' => 0],
            ['item_id' => $rmB->id, 'quantity' => 5, 'rate' => 125, 'discount' => 0],
            ['item_id' => $trading->id, 'quantity' => 3, 'rate' => 40, 'discount' => 0],
        ],
        $admin,
    );

    $note = MaterialIssueNote::post(
        ['date' => '2026-03-01'],
        [['item_id' => $rmA->id, 'quantity' => 10], ['item_id' => $rmB->id, 'quantity' => 4]],
        $admin,
    );

    $entry = ProductionEntry::create(
        ['date' => '2026-03-01', 'finished_item_id' => $fg->id, 'planned_quantity' => '50'],
        [$note->id],
        $admin,
    );
    $entry->addDirectCost(['date' => '2026-03-05', 'cost_type' => 'labor', 'description' => 'Labour', 'amount' => '300', 'credit_account_id' => $creditAccount->id], $admin);
    $entry->addDirectCost(['date' => '2026-03-06', 'cost_type' => 'utilities', 'description' => 'Power', 'amount' => '200', 'credit_account_id' => $creditAccount->id], $admin);
    $entry->complete('2026-03-10', '50', true, $admin);

    Sale::post(
        ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-04-01', 'payment_mode' => 'credit'],
        [['item_id' => $fg->id, 'quantity' => 20, 'rate' => 100, 'discount' => 0]],
        $admin,
    );

    return compact('admin', 'rmA', 'rmB', 'fg', 'trading', 'fiscalYear', 'entry');
}

test('each stock ledger closes at the GL balance of its inventory account', function () {
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'mfg-reports-ledgers.tenant-test']);

    $tenant->run(function () {
        $scenario = manufacturingReportsScenario();
        $reports = app(ManufacturingReports::class);

        $expected = [
            ManufacturingReports::CLASS_RAW_MATERIAL => [ManufacturingAccounts::RAW_MATERIAL, '375.00'],
            ManufacturingReports::CLASS_WIP => [ManufacturingAccounts::WIP, '0.00'],
            ManufacturingReports::CLASS_FINISHED_GOOD => [ManufacturingAccounts::FINISHED_GOODS, '900.00'],
        ];

        foreach ($expected as $class => [$code, $value]) {
            $ledger = $reports->stockLedger($class, '2026-01-01', '2026-12-31');
            $gl = $reports->glBalance($scenario['fiscalYear'], $code, null, '2026-12-31', false);

            expect($ledger['closing']['value'])->toBe($value)
                ->and($gl->toString())->toBe($value)
                ->and($ledger['opening']['value'])->toBe('0.00');
        }

        $rm = $reports->stockLedger(ManufacturingReports::CLASS_RAW_MATERIAL, '2026-01-01', '2026-12-31');
        expect($rm['closing']['quantity'])->toBe('6.0000')
            ->and($rm['rows'])->toHaveCount(4);

        $fg = $reports->stockLedger(ManufacturingReports::CLASS_FINISHED_GOOD, '2026-01-01', '2026-12-31', $scenario['fg']->id);
        expect($fg['closing']['quantity'])->toBe('30.0000')
            ->and(end($fg['rows'])['balanceValue'])->toBe('900.00');
    });

    $tenant->delete();
});

test('the inventory schedule total equals AS12 + AS13 + AS14 plus trading stock', function () {
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'mfg-reports-schedule.tenant-test']);

    $tenant->run(function () {
        $scenario = manufacturingReportsScenario();
        $reports = app(ManufacturingReports::class);

        $schedule = $reports->inventorySchedule($scenario['fiscalYear'], '2026-12-31');

        $gl = fn (string $code) => $reports->glBalance($scenario['fiscalYear'], $code, null, '2026-12-31', false);
        $expected = Money::sum([
            $gl(ManufacturingAccounts::RAW_MATERIAL),
            $gl(ManufacturingAccounts::WIP),
            $gl(ManufacturingAccounts::FINISHED_GOODS),
            StockCosting::totalPeriodicClosingValue('2026-12-31'),
        ]);

        expect($schedule['total'])->toBe($expected->toString())
            ->and($schedule['rows'])->toHaveCount(4)
            ->and(collect($schedule['rows'])->firstWhere('key', 'trading')['gl'])->toBe('120.00');

        foreach (['raw_material', 'wip', 'finished_good'] as $key) {
            expect(collect($schedule['rows'])->firstWhere('key', $key)['difference'])->toBe('0.00');
        }
    });

    $tenant->delete();
});

test('computed COGS equals the EXE10 balance with a zero reconciling line', function () {
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'mfg-reports-cogs.tenant-test']);

    $tenant->run(function () {
        $scenario = manufacturingReportsScenario();
        $reports = app(ManufacturingReports::class);

        $schedule = $reports->cogsSchedule($scenario['fiscalYear'], '2026-01-01', '2026-12-31');
        $lines = collect($schedule['lines'])->pluck('amount', 'key');

        expect($schedule['glCogs'])->toBe('600.00')
            ->and($schedule['computedCogs'])->toBe('600.00')
            ->and($schedule['difference'])->toBe('0.00')
            ->and($schedule['reconciled'])->toBeTrue()
            ->and($lines['rm_consumed'])->toBe('1000.00')
            ->and($lines['direct_costs'])->toBe('500.00')
            ->and($lines['total_manufacturing'])->toBe('1500.00')
            ->and($lines['cogm'])->toBe('1500.00')
            ->and($lines['closing_fg'])->toBe('900.00')
            ->and($lines['reconciling'])->toBe('0.00');
    });

    $tenant->delete();
});

test('a COGS mismatch is returned as a non-zero reconciling line, never hidden', function () {
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'mfg-reports-mismatch.tenant-test']);

    $tenant->run(function () {
        $scenario = manufacturingReportsScenario();
        $reports = app(ManufacturingReports::class);

        // A manual journal into EXE10 moves the ledger but not the
        // subledgers, so the computed COGS no longer matches it.
        $cogsAccount = Account::where('code', ManufacturingAccounts::COGS)->firstOrFail();
        $other = Account::factory()->create();

        JournalVoucher::post(
            [
                'voucher_type' => VoucherType::Journal->value,
                'date' => '2026-05-01',
                'narration' => 'Manual COGS posting',
            ],
            [
                ['account_id' => $cogsAccount->id, 'debit' => '25.00', 'credit' => '0.00'],
                ['account_id' => $other->id, 'debit' => '0.00', 'credit' => '25.00'],
            ],
            $scenario['admin'],
        );

        $schedule = $reports->cogsSchedule($scenario['fiscalYear'], '2026-01-01', '2026-12-31');

        expect($schedule['difference'])->toBe('25.00')
            ->and($schedule['reconciled'])->toBeFalse()
            ->and(collect($schedule['lines'])->firstWhere('key', 'reconciling')['style'])->toBe('alert');
    });

    $tenant->delete();
});

test('opening figures come from the prior year snapshot when the window starts the next year', function () {
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'mfg-reports-snapshot.tenant-test']);

    $tenant->run(function () {
        $scenario = manufacturingReportsScenario();
        $reports = app(ManufacturingReports::class);

        $scenario['fiscalYear']->update(['status' => FiscalYearStatus::Closed]);
        $next = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);

        // The verified closing figures write by FiscalYear::close() (MC9).
        foreach ([[$scenario['rmA'], '5.0000', '250.00'], [$scenario['rmB'], '1.0000', '125.00']] as [$item, $quantity, $value]) {
            FiscalYearInventorySnapshot::create([
                'fiscal_year_id' => $scenario['fiscalYear']->id,
                'inventory_class' => InventoryClass::RawMaterial->value,
                'item_id' => $item->id,
                'quantity' => $quantity,
                'value' => $value,
            ]);
        }

        FiscalYearInventorySnapshot::create([
            'fiscal_year_id' => $scenario['fiscalYear']->id,
            'inventory_class' => InventoryClass::FinishedGood->value,
            'item_id' => $scenario['fg']->id,
            'quantity' => '30.0000',
            'value' => '900.00',
        ]);

        $rm = $reports->stockLedger(ManufacturingReports::CLASS_RAW_MATERIAL, '2027-01-01', '2027-12-31');
        $fg = $reports->stockLedger(ManufacturingReports::CLASS_FINISHED_GOOD, '2027-01-01', '2027-12-31');
        $wip = $reports->stockLedger(ManufacturingReports::CLASS_WIP, '2027-01-01', '2027-12-31');

        expect($rm['openingFromSnapshot'])->toBeTrue()
            ->and($rm['opening'])->toBe(['quantity' => '6.0000', 'value' => '375.00'])
            ->and($rm['closing']['value'])->toBe('375.00')
            ->and($fg['openingFromSnapshot'])->toBeTrue()
            ->and($fg['opening']['value'])->toBe('900.00')
            // The prior year wrote no WIP rows, so the opening falls back to
            // the WIP subledger as of the day before, which is zero.
            ->and($wip['openingFromSnapshot'])->toBeFalse()
            ->and($wip['opening']['value'])->toBe('0.00');

        $schedule = $reports->cogsSchedule($next, '2027-01-01', '2027-12-31');

        expect(collect($schedule['lines'])->firstWhere('key', 'opening_rm')['amount'])->toBe('375.00')
            ->and(collect($schedule['lines'])->firstWhere('key', 'opening_fg')['amount'])->toBe('900.00');

        // A window that does not start the day after a year-end ignores the
        // snapshot and computes the balance from the movements.
        $mid = $reports->stockLedger(ManufacturingReports::CLASS_RAW_MATERIAL, '2026-06-01', '2026-12-31');
        expect($mid['openingFromSnapshot'])->toBeFalse()
            ->and($mid['opening']['value'])->toBe('375.00');
    });

    $tenant->delete();
});
