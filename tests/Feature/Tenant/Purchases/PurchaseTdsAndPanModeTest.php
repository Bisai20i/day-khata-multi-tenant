<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\BillingException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * T13 items 1 and 2: the TDS rate stored and computed server-side, and the
 * PAN / non-VAT purchase mode that pushes every line into the exempt column.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseTdsTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function purchaseTdsTestOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

/**
 * The seeded "TDS Payable" default (migration 2026_09_14_130040). That
 * migration runs BEFORE ChartOfAccountsSeeder during tenant provisioning, so
 * a freshly provisioned tenant does not have LIA21 yet - see the cross-file
 * request to add it to ChartOfAccountsSeeder. Until then every test that
 * needs the default creates it the same way the migration does.
 */
function purchaseTdsPayableAccount(): Account
{
    return Account::firstOrCreate(
        ['code' => 'LIA21'],
        [
            'account_group_id' => AccountGroup::where('name', 'Current Liabilities')->firstOrFail()->id,
            'account_subgroup_id' => null,
            'name' => 'TDS Payable',
        ]
    );
}

test('a TDS rate is applied to taxable plus nontaxable and stored with the bill', function () {
    $tenant = provisionPurchaseTdsTestTenant('purchase-tds-rate.tenant-test');

    $tenant->run(function () {
        purchaseTdsTestOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $vatable = Item::factory()->create(['is_vatable' => true]);
        $exempt = Item::factory()->create(['is_vatable' => false]);
        $tdsAccount = Account::factory()->create();

        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'tds_account_id' => $tdsAccount->id,
                'tds_rate' => '1.5',
            ],
            [
                ['item_id' => $vatable->id, 'quantity' => 1, 'rate' => 1000],
                ['item_id' => $exempt->id, 'quantity' => 1, 'rate' => 500],
            ],
            $actor,
        );

        // Base = 1000 taxable + 500 exempt = 1500; 1.5% of 1500 = 22.50.
        // VAT (130.00 on the taxable half) is deliberately NOT in the base.
        expect($purchase->taxable_amount)->toBe('1000.00')
            ->and($purchase->nontaxable_amount)->toBe('500.00')
            ->and($purchase->vat_amount)->toBe('130.00')
            ->and($purchase->total)->toBe('1630.00')
            ->and($purchase->tds_rate)->toBe('1.50')
            ->and($purchase->tds_amount)->toBe('22.50');

        $tdsLine = $purchase->journalVoucher->lines()->where('account_id', $tdsAccount->id)->first();
        expect($tdsLine)->not->toBeNull()->and($tdsLine->credit)->toEqual('22.50');
    });

    $tenant->delete();
});

test('a TDS rate rounds once, half up, exactly as Money::percent does', function () {
    $tenant = provisionPurchaseTdsTestTenant('purchase-tds-rounding.tenant-test');

    $tenant->run(function () {
        purchaseTdsTestOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);
        $tdsAccount = Account::factory()->create();

        // 1.5% of 1001.00 = 15.015, which must land on 15.02, not 15.01.
        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'tds_account_id' => $tdsAccount->id,
                'tds_rate' => '1.5',
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => '1001']],
            $actor,
        );

        expect($purchase->tds_amount)->toBe('15.02')
            ->and($purchase->total)->toBe('1001.00');
    });

    $tenant->delete();
});

test('TDS withheld without a picked account falls back to the seeded TDS Payable account', function () {
    $tenant = provisionPurchaseTdsTestTenant('purchase-tds-default-account.tenant-test');

    $tenant->run(function () {
        purchaseTdsTestOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);
        $tdsPayable = purchaseTdsPayableAccount();

        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'tds_rate' => '10',
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            $actor,
        );

        expect($purchase->tds_amount)->toBe('100.00')
            ->and($purchase->tds_account_id)->toBe($tdsPayable->id);

        $tdsLine = $purchase->journalVoucher->lines()->where('account_id', $tdsPayable->id)->first();
        expect($tdsLine)->not->toBeNull()->and($tdsLine->credit)->toEqual('100.00');
    });

    $tenant->delete();
});

test('a TDS amount above the taxable plus nontaxable base is rejected', function () {
    $tenant = provisionPurchaseTdsTestTenant('purchase-tds-above-base.tenant-test');

    $tenant->run(function () {
        purchaseTdsTestOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true]);
        $tdsAccount = Account::factory()->create();

        // 1130.00 is the grand total, but only 1000.00 is the TDS base: VAT is
        // never withheld against.
        expect(fn () => Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => '1130.00',
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            $actor,
        ))->toThrow(BillingException::class);

        expect(Purchase::count())->toBe(0);
    });

    $tenant->delete();
});

test('a TDS rate above 100 percent is rejected rather than silently capped', function () {
    $tenant = provisionPurchaseTdsTestTenant('purchase-tds-rate-above-100.tenant-test');

    $tenant->run(function () {
        purchaseTdsTestOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);
        $tdsAccount = Account::factory()->create();

        expect(fn () => Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'tds_account_id' => $tdsAccount->id,
                'tds_rate' => '150',
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            $actor,
        ))->toThrow(BillingException::class);

        expect(Purchase::count())->toBe(0);
    });

    $tenant->delete();
});

test('the store request refuses a TDS rate above 100', function () {
    $domain = 'purchase-tds-rate-validation.tenant-test';
    $tenant = provisionPurchaseTdsTestTenant($domain);

    $itemId = null;
    $supplierId = null;

    $tenant->run(function () use (&$itemId, &$supplierId) {
        purchaseTdsTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        $supplierId = Supplier::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => false])->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $supplierId,
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'tds_rate' => '150',
        'lines' => [['item_id' => $itemId, 'quantity' => '1', 'rate' => '1000']],
    ])->assertSessionHasErrors('tds_rate');

    $tenant->run(function () {
        expect(Purchase::count())->toBe(0);
    });

    $tenant->delete();
});

test('a PAN purchase charges no VAT and books every line as exempt', function () {
    $tenant = provisionPurchaseTdsTestTenant('purchase-pan-mode.tenant-test');

    $tenant->run(function () {
        purchaseTdsTestOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create(['is_vat_registered' => true]);
        $item = Item::factory()->create(['is_vatable' => true]);

        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'force_non_taxable' => true,
            ],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 500]],
            $actor,
        );

        expect($purchase->force_non_taxable)->toBeTrue()
            ->and($purchase->vat_amount)->toBe('0.00')
            ->and($purchase->vat_rate)->toBe('0.00')
            ->and($purchase->taxable_amount)->toBe('0.00')
            ->and($purchase->nontaxable_amount)->toBe('1000.00')
            ->and($purchase->total)->toBe('1000.00');

        // The line itself is exempt, which is what puts it in the exempt
        // column of the Purchase VAT book.
        expect($purchase->lines()->first()->vatable)->toBeFalse();

        // Nothing is claimed as VAT receivable either.
        $vatReceivable = Account::where('code', 'ASA23')->firstOrFail();
        expect($purchase->journalVoucher->lines()->where('account_id', $vatReceivable->id)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('a purchase from a supplier who is not VAT registered defaults to PAN mode', function () {
    $tenant = provisionPurchaseTdsTestTenant('purchase-pan-default.tenant-test');

    $tenant->run(function () {
        purchaseTdsTestOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create(['is_vat_registered' => false]);
        $item = Item::factory()->create(['is_vatable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            $actor,
        );

        expect($purchase->force_non_taxable)->toBeTrue()
            ->and($purchase->vat_amount)->toBe('0.00')
            ->and($purchase->nontaxable_amount)->toBe('1000.00')
            ->and($purchase->total)->toBe('1000.00');
    });

    $tenant->delete();
});

test('a VAT registered supplier still charges VAT and can be overridden per bill', function () {
    $tenant = provisionPurchaseTdsTestTenant('purchase-pan-override.tenant-test');

    $tenant->run(function () {
        purchaseTdsTestOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create(['is_vat_registered' => false]);
        $item = Item::factory()->create(['is_vatable' => true]);

        // The supplier defaults to PAN, but this bill says otherwise: an
        // explicit false on the request always wins over the default.
        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'force_non_taxable' => false,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            $actor,
        );

        expect($purchase->force_non_taxable)->toBeFalse()
            ->and($purchase->taxable_amount)->toBe('1000.00')
            ->and($purchase->vat_amount)->toBe('130.00')
            ->and($purchase->total)->toBe('1130.00');
    });

    $tenant->delete();
});

test('a PAN purchase still withholds TDS on the full exempt base', function () {
    $tenant = provisionPurchaseTdsTestTenant('purchase-pan-with-tds.tenant-test');

    $tenant->run(function () {
        purchaseTdsTestOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create(['is_vat_registered' => false]);
        $item = Item::factory()->create(['is_vatable' => true]);
        $tdsAccount = Account::factory()->create();

        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'tds_account_id' => $tdsAccount->id,
                'tds_rate' => '1.5',
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            $actor,
        );

        // Base is the whole 1000.00 even though every rupee of it is exempt.
        expect($purchase->nontaxable_amount)->toBe('1000.00')
            ->and($purchase->tds_amount)->toBe('15.00')
            ->and($purchase->outstandingAmount()->toString())->toBe('985.00');
    });

    $tenant->delete();
});
