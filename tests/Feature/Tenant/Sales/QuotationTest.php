<?php

use App\Enums\FiscalYearStatus;
use App\Enums\QuotationStatus;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionQuotationTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function quotationTestAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function quotationTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

/**
 * Creates a quotation the way the controller does: the totals are calculated
 * once by DocumentCalculator and stored on the row. A quotation whose stored
 * total does not match its lines can no longer be converted, which is exactly
 * the guarantee the audit's P0-9 fix is there to give.
 *
 * @param  array<int, array{item_id: int, quantity: string, rate: string, discount?: string}>  $lines
 * @param  array{discount?: string, vat_rate?: string}  $header
 */
function createQuotation(User $admin, Customer $customer, array $lines, array $header = []): Quotation
{
    $header = ['discount' => '0', 'vat_rate' => '13', ...$header];
    $totals = Quotation::calculateTotals($lines, $header);

    $quotation = Quotation::create([
        'customer_id' => $customer->id,
        'date' => '2026-06-01',
        'discount' => $header['discount'],
        'vat_rate' => $header['vat_rate'],
        ...Quotation::storedTotals($totals),
        'status' => QuotationStatus::Draft,
        'created_by' => $admin->id,
    ]);

    foreach ($lines as $line) {
        $quotation->lines()->create($line);
    }

    return $quotation;
}

test('creating a quotation posts nothing to the ledger or stock', function () {
    $tenant = provisionQuotationTestTenant('quote-noop.tenant-test');

    $tenant->run(function () {
        quotationTestOpenFiscalYear();
        $admin = quotationTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $quotation = createQuotation($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => '2', 'rate' => '100', 'discount' => '0'],
        ]);

        expect(JournalVoucher::count())->toBe(0)
            ->and($quotation->fresh()->status)->toBe(QuotationStatus::Draft)
            ->and($quotation->total)->toBe('226.00');
    });

    $tenant->delete();
});

test('a draft quotation can be edited and cancelled, a non-draft one cannot', function () {
    $tenant = provisionQuotationTestTenant('quote-edit.tenant-test');

    $tenant->run(function () {
        $admin = quotationTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create();

        $quotation = createQuotation($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => '2', 'rate' => '100', 'discount' => '0'],
        ]);
        expect($quotation->status)->toBe(QuotationStatus::Draft);

        $quotation->update(['narration' => 'Revised terms']);
        expect($quotation->fresh()->narration)->toBe('Revised terms');

        $quotation->cancel();
        expect($quotation->fresh()->status)->toBe(QuotationStatus::Cancelled);

        expect(fn () => $quotation->cancel())->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('converting a draft quotation posts a real sale with matching lines and flips status', function () {
    $tenant = provisionQuotationTestTenant('quote-convert.tenant-test');

    $tenant->run(function () {
        quotationTestOpenFiscalYear();
        // Converted without any prior stock - this test is only about the
        // conversion flow, not stock policy.
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = quotationTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $quotation = createQuotation($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => '2', 'rate' => '100', 'discount' => '0'],
        ]);

        $sale = $quotation->convertToSale($admin);

        expect($sale->taxable_amount)->toBe('200.00')
            ->and($sale->total)->toBe('226.00')
            ->and($sale->payment_mode)->toBe('credit')
            ->and($sale->lines()->count())->toBe(1)
            ->and($sale->lines()->first()->item_id)->toBe($item->id);

        $quotation->refresh();
        expect($quotation->status)->toBe(QuotationStatus::Converted)
            ->and($quotation->sale_id)->toBe($sale->id);
    });

    $tenant->delete();
});

test('a mixed vatable and exempt quote with a header discount converts to a sale of exactly the same total', function () {
    $tenant = provisionQuotationTestTenant('quote-convert-mixed.tenant-test');

    $tenant->run(function () {
        quotationTestOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = quotationTestAdmin();
        $customer = Customer::factory()->create();
        $vatableItem = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $exemptItem = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        // The audit's own example (P0-9): a 1000 vatable line, a 500 exempt
        // line and a 100 header discount added up to 1400.00, 1582.00 or
        // 1517.00 depending on which screen you looked at, and to something
        // else again once it became a sale.
        $quotation = createQuotation(
            $admin,
            $customer,
            [
                ['item_id' => $vatableItem->id, 'quantity' => '1', 'rate' => '1000', 'discount' => '0'],
                ['item_id' => $exemptItem->id, 'quantity' => '1', 'rate' => '500', 'discount' => '0'],
            ],
            ['discount' => '100', 'vat_rate' => '13'],
        );

        // The header discount is split proportionally by largest remainder:
        // 66.67 off the vatable side, 33.33 off the exempt side (C3 step 4).
        expect($quotation->taxable_amount)->toBe('933.33')
            ->and($quotation->nontaxable_amount)->toBe('466.67')
            ->and($quotation->vat_amount)->toBe('121.33')
            ->and($quotation->total)->toBe('1521.33');

        $sale = $quotation->convertToSale($admin);

        expect($sale->total)->toBe($quotation->fresh()->total)
            ->and($sale->taxable_amount)->toBe('933.33')
            ->and($sale->nontaxable_amount)->toBe('466.67')
            ->and($sale->vat_amount)->toBe('121.33');
    });

    $tenant->delete();
});

test('converting twice, or converting an empty quotation, is rejected', function () {
    $tenant = provisionQuotationTestTenant('quote-convert-guard.tenant-test');

    $tenant->run(function () {
        quotationTestOpenFiscalYear();
        $admin = quotationTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $emptyQuotation = Quotation::create([
            'customer_id' => $customer->id,
            'date' => '2026-06-01',
            'discount' => '0',
            'vat_rate' => '13',
            'status' => QuotationStatus::Draft,
            'created_by' => $admin->id,
        ]);
        expect(fn () => $emptyQuotation->convertToSale($admin))->toThrow(InvalidArgumentException::class);

        $quotation = createQuotation($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => '1', 'rate' => '100', 'discount' => '0'],
        ]);

        $quotation->convertToSale($admin);

        expect(fn () => $quotation->fresh()->convertToSale($admin))->toThrow(InvalidArgumentException::class);
        expect($quotation->fresh()->sale->id)->toBe($quotation->fresh()->sale_id);
    });

    $tenant->delete();
});

test('a quotation whose stored total no longer matches the bill it would create is not converted', function () {
    $tenant = provisionQuotationTestTenant('quote-convert-drift.tenant-test');

    $tenant->run(function () {
        quotationTestOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = quotationTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $quotation = createQuotation($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => '1', 'rate' => '1000', 'discount' => '0'],
        ]);

        // The item stops being vatable after the quote was issued, so the sale
        // would come to 1000.00 against a quote of 1130.00. The customer is
        // owed the quote they accepted, so nothing is posted at all.
        $item->update(['is_vatable' => false]);

        expect(fn () => $quotation->fresh()->convertToSale($admin))->toThrow(InvalidArgumentException::class);

        expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft)
            ->and($quotation->fresh()->sale_id)->toBeNull()
            ->and(JournalVoucher::count())->toBe(0);
    });

    $tenant->delete();
});

test('a converted quotation can no longer be edited, deleted, or cancelled', function () {
    $domain = 'quote-immutable.tenant-test';
    $tenant = provisionQuotationTestTenant($domain);

    [$customerId, $itemId, $quotationId] = $tenant->run(function () {
        quotationTestOpenFiscalYear();
        $admin = User::factory()->create([
            'email' => 'owner@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $quotation = createQuotation($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => '1', 'rate' => '100', 'discount' => '0'],
        ]);
        $quotation->convertToSale($admin);

        expect(fn () => $quotation->fresh()->cancel())->toThrow(InvalidArgumentException::class);

        return [$customer->id, $item->id, $quotation->id];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->put("http://{$domain}/quotations/{$quotationId}", [
        'customer_id' => $customerId,
        'date' => '2026-06-01',
        'lines' => [['item_id' => $itemId, 'quantity' => '1', 'rate' => '100', 'discount' => '0']],
    ])->assertSessionHasErrors('quotation');

    $this->delete("http://{$domain}/quotations/{$quotationId}")->assertSessionHasErrors('quotation');

    $tenant->run(function () use ($quotationId) {
        expect(Quotation::find($quotationId)->status)->toBe(QuotationStatus::Converted);
    });

    $tenant->delete();
});

test('the store route calculates and stores the quotation totals', function () {
    $domain = 'quote-store-totals.tenant-test';
    $tenant = provisionQuotationTestTenant($domain);

    $customerId = null;
    $itemId = null;
    $tenant->run(function () use (&$customerId, &$itemId) {
        quotationTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false])->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    // 1.5 x 33.33 = 49.995, which rounds HalfUp to 50.00 (PHP 8.4's round()
    // gave 49.99 here, audit P0-1), then 13% VAT on 50.00 is 6.50.
    $this->post("http://{$domain}/quotations", [
        'customer_id' => $customerId,
        'date' => '2026-06-01',
        'discount' => '0',
        'vat_rate' => '13',
        'expected_total' => '56.50',
        'lines' => [['item_id' => $itemId, 'quantity' => '1.5', 'rate' => '33.33', 'discount' => '0']],
    ])->assertRedirect("http://{$domain}/quotations");

    $tenant->run(function () {
        $quotation = Quotation::query()->sole();
        expect($quotation->taxable_amount)->toBe('50.00')
            ->and($quotation->nontaxable_amount)->toBe('0.00')
            ->and($quotation->vat_amount)->toBe('6.50')
            ->and($quotation->total)->toBe('56.50');
    });

    $tenant->delete();
});

test('the store route refuses a quotation whose expected total does not match', function () {
    $domain = 'quote-store-mismatch.tenant-test';
    $tenant = provisionQuotationTestTenant($domain);

    $customerId = null;
    $itemId = null;
    $tenant->run(function () use (&$customerId, &$itemId) {
        quotationTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false])->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/quotations", [
        'customer_id' => $customerId,
        'date' => '2026-06-01',
        'discount' => '0',
        'vat_rate' => '13',
        'expected_total' => '49.99',
        'lines' => [['item_id' => $itemId, 'quantity' => '1.5', 'rate' => '33.33', 'discount' => '0']],
    ])->assertSessionHasErrors('expected_total');

    $tenant->run(function () {
        expect(Quotation::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('the store route refuses a quantity with more decimals than the column stores', function () {
    $domain = 'quote-store-decimals.tenant-test';
    $tenant = provisionQuotationTestTenant($domain);

    $customerId = null;
    $itemId = null;
    $tenant->run(function () use (&$customerId, &$itemId) {
        quotationTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false])->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/quotations", [
        'customer_id' => $customerId,
        'date' => '2026-06-01',
        'lines' => [['item_id' => $itemId, 'quantity' => '0.00004', 'rate' => '1000000', 'discount' => '0']],
    ])->assertSessionHasErrors('lines.0.quantity');

    $tenant->run(function () {
        expect(Quotation::query()->count())->toBe(0);
    });

    $tenant->delete();
});
