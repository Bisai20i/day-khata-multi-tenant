<?php

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * Tenancy has no automatic "end of request" hook outside of a real PHP-FPM
 * style process boundary, so within a single test process the tenant
 * connection stays the default connection after an HTTP call. Revert to the
 * central connection after every test so RefreshDatabase's teardown rolls
 * back the connection it actually started a transaction on.
 */
afterEach(function () {
    tenancy()->end();
});

function provisionItemImportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsItemImportOwner(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('an authenticated user can bulk import items from a csv file', function () {
    $domain = 'item-import-success.tenant-test';
    $tenant = provisionItemImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        ItemCategory::create(['name' => 'Beverages', 'is_active' => true]);
        $snacks = ItemCategory::create(['name' => 'Snacks', 'is_active' => true]);
        ItemSubcategory::create(['item_category_id' => $snacks->id, 'name' => 'Chips', 'is_active' => true]);
    });

    loginAsItemImportOwner($domain);

    $csv = "name,category,subcategory,unit,hs_code,barcode,min_stock,purchase_rate,sale_rate,is_vatable,is_stockable\n"
        ."Bottled Water 1L,Beverages,,pcs,2201.10,1234567890123,10,15.00,20.00,yes,yes\n"
        ."Potato Chips 50g,Snacks,Chips,pcs,,,5,,,no,yes\n";
    $file = UploadedFile::fake()->createWithContent('items.csv', $csv);

    $response = $this->post("http://{$domain}/items/import", ['file' => $file]);

    $response->assertRedirect("http://{$domain}/items");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 2 && $result['skipped'] === [];
    });

    $tenant->run(function () {
        $water = Item::query()->where('name', 'Bottled Water 1L')->firstOrFail();
        expect($water->category->name)->toBe('Beverages')
            ->and($water->subcategory)->toBeNull()
            ->and($water->barcode)->toBe('1234567890123')
            ->and((float) $water->min_stock)->toBe(10.0)
            ->and((float) $water->purchase_rate)->toBe(15.0)
            ->and((float) $water->sale_rate)->toBe(20.0)
            ->and($water->is_vatable)->toBeTrue()
            ->and($water->is_stockable)->toBeTrue();

        $chips = Item::query()->where('name', 'Potato Chips 50g')->firstOrFail();
        expect($chips->subcategory->name)->toBe('Chips')
            ->and($chips->barcode)->toBeNull()
            ->and($chips->is_vatable)->toBeFalse()
            ->and($chips->is_stockable)->toBeTrue();
    });

    $tenant->delete();
});

test('bulk import skips invalid or unresolvable rows and reports why without importing them', function () {
    $domain = 'item-import-invalid.tenant-test';
    $tenant = provisionItemImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        ItemCategory::create(['name' => 'Beverages', 'is_active' => true]);
        Item::factory()->create(['name' => 'Existing Item', 'barcode' => '9999999999999']);
    });

    loginAsItemImportOwner($domain);

    $csv = "name,category,subcategory,unit,hs_code,barcode,min_stock,purchase_rate,sale_rate,is_vatable,is_stockable\n"
        // valid row.
        ."Good Row,Beverages,,pcs,,,,,,,\n"
        // missing required name.
        .",Beverages,,pcs,,,,,,,\n"
        // unknown category.
        ."Bad Category,NoSuchCategory,,pcs,,,,,,,\n"
        // missing required unit.
        ."No Unit,Beverages,,,,,,,,,\n"
        // barcode already used by an existing item.
        ."Duplicate Of Existing,Beverages,,pcs,,9999999999999,,,,,\n"
        // barcode repeated later in the same file.
        ."First Of Pair,Beverages,,pcs,,5555555555555,,,,,\n"
        ."Second Of Pair,Beverages,,pcs,,5555555555555,,,,,\n";
    $file = UploadedFile::fake()->createWithContent('items.csv', $csv);

    $response = $this->post("http://{$domain}/items/import", ['file' => $file]);

    $response->assertRedirect("http://{$domain}/items");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 2 && count($result['skipped']) === 5;
    });

    $tenant->run(function () {
        expect(Item::query()->where('name', 'Good Row')->exists())->toBeTrue();
        expect(Item::query()->where('name', 'First Of Pair')->exists())->toBeTrue();

        expect(Item::query()->where('name', 'No Name Address')->exists())->toBeFalse();
        expect(Item::query()->where('name', 'Bad Category')->exists())->toBeFalse();
        expect(Item::query()->where('name', 'No Unit')->exists())->toBeFalse();
        expect(Item::query()->where('name', 'Duplicate Of Existing')->exists())->toBeFalse();
        expect(Item::query()->where('name', 'Second Of Pair')->exists())->toBeFalse();

        // The pre-existing item and the two genuinely valid rows only - no
        // partial writes from the invalid ones leaked into the table.
        expect(Item::query()->count())->toBe(3);
    });

    $tenant->delete();
});

test('guests cannot reach the item import endpoints', function () {
    $domain = 'item-import-guest.tenant-test';
    $tenant = provisionItemImportTestTenant($domain);

    $tenant->run(function () {
        ItemCategory::create(['name' => 'Beverages', 'is_active' => true]);
    });

    $file = UploadedFile::fake()->createWithContent('items.csv', "name,category,subcategory,unit,hs_code,barcode,min_stock,purchase_rate,sale_rate,is_vatable,is_stockable\nWater,Beverages,,pcs,,,,,,,\n");

    $this->post("http://{$domain}/items/import", ['file' => $file])
        ->assertRedirect("http://{$domain}/login");

    $this->get("http://{$domain}/items/import/template")
        ->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        expect(Item::query()->count())->toBe(0);
    });

    $tenant->delete();
});
