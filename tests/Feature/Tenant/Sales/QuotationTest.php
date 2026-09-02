<?php

use App\Enums\FiscalYearStatus;
use App\Enums\QuotationStatus;
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

function makeDraftQuotation(User $admin, Customer $customer, Item $item, array $lineOverrides = []): Quotation
{
    $quotation = Quotation::create([
        'customer_id' => $customer->id,
        'date' => '2026-06-01',
        'discount' => 0,
        'vat_rate' => 13,
        'status' => QuotationStatus::Draft,
        'created_by' => $admin->id,
    ]);

    if ($lineOverrides !== []) {
        $quotation->lines()->create([
            'item_id' => $item->id,
            'quantity' => 2,
            'rate' => 100,
            'discount' => 0,
            ...$lineOverrides,
        ]);
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

        $quotation = makeDraftQuotation($admin, $customer, $item, []);

        expect(JournalVoucher::count())->toBe(0)
            ->and($quotation->fresh()->status)->toBe(QuotationStatus::Draft);
    });

    $tenant->delete();
});

test('a draft quotation can be edited and deleted, a non-draft one cannot', function () {
    $tenant = provisionQuotationTestTenant('quote-edit.tenant-test');

    $tenant->run(function () {
        $admin = quotationTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create();

        $quotation = makeDraftQuotation($admin, $customer, $item, []);
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
        $admin = quotationTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $quotation = Quotation::create([
            'customer_id' => $customer->id,
            'date' => '2026-06-01',
            'discount' => 0,
            'vat_rate' => 13,
            'status' => QuotationStatus::Draft,
            'created_by' => $admin->id,
        ]);
        $quotation->lines()->create(['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]);

        $sale = $quotation->convertToSale($admin);

        expect((float) $sale->taxable_amount)->toBe(200.0)
            ->and((float) $sale->total)->toBe(226.0)
            ->and($sale->payment_mode)->toBe('credit')
            ->and($sale->lines()->count())->toBe(1)
            ->and($sale->lines()->first()->item_id)->toBe($item->id);

        $quotation->refresh();
        expect($quotation->status)->toBe(QuotationStatus::Converted)
            ->and($quotation->sale_id)->toBe($sale->id);
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

        $emptyQuotation = makeDraftQuotation($admin, $customer, $item, []);
        expect(fn () => $emptyQuotation->convertToSale($admin))->toThrow(InvalidArgumentException::class);

        $quotation = Quotation::create([
            'customer_id' => $customer->id,
            'date' => '2026-06-01',
            'discount' => 0,
            'vat_rate' => 13,
            'status' => QuotationStatus::Draft,
            'created_by' => $admin->id,
        ]);
        $quotation->lines()->create(['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]);

        $quotation->convertToSale($admin);

        expect(fn () => $quotation->fresh()->convertToSale($admin))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a converted quotation can no longer be edited, deleted, or cancelled', function () {
    $domain = 'quote-immutable.tenant-test';
    $tenant = provisionQuotationTestTenant($domain);

    [$customerId, $itemId, $quotationId] = $tenant->run(function () {
        quotationTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $quotation = Quotation::create([
            'customer_id' => $customer->id,
            'date' => '2026-06-01',
            'discount' => 0,
            'vat_rate' => 13,
            'status' => QuotationStatus::Draft,
            'created_by' => User::first()->id,
        ]);
        $quotation->lines()->create(['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]);
        $quotation->convertToSale(User::first());

        expect(fn () => $quotation->fresh()->cancel())->toThrow(InvalidArgumentException::class);

        return [$customer->id, $item->id, $quotation->id];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->put("http://{$domain}/quotations/{$quotationId}", [
        'customer_id' => $customerId,
        'date' => '2026-06-01',
        'lines' => [['item_id' => $itemId, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
    ])->assertSessionHasErrors('quotation');

    $this->delete("http://{$domain}/quotations/{$quotationId}")->assertSessionHasErrors('quotation');

    $tenant->run(function () use ($quotationId) {
        expect(Quotation::find($quotationId)->status)->toBe(QuotationStatus::Converted);
    });

    $tenant->delete();
});
