<?php

use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Picqer\Barcode\BarcodeGeneratorPNG;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Barcode label printing
|--------------------------------------------------------------------------
|
| Covers the audit gap in legacy day_khata's InventoryController::
| itembarcode()/printBarcode() - see BarcodeLabelController's docblock.
| Follows SalePrintTest's exact split: one HTTP-level test pins the route's
| status/Content-Type (dompdf's actual PDF bytes aren't a stable thing to
| assert against - they're a compressed binary stream), and separate tests
| render the underlying pdf.barcode-labels view directly and assert on its
| HTML, the same way SalePrintTest asserts on pdf.sale's rendered markup.
|
*/

afterEach(function () {
    tenancy()->end();
});

function provisionBarcodeLabelTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginBarcodeLabelTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the barcode label print route returns a streamed PDF for an authenticated user', function () {
    $domain = 'barcode-label-print-http.tenant-test';
    $tenant = provisionBarcodeLabelTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $itemId = Item::factory()->create(['barcode' => '8901234567890'])->id;
    });

    loginBarcodeLabelTestUser($domain);

    $this->get("http://{$domain}/items/barcode-labels/print?".http_build_query([
        'items' => [['item_id' => $itemId, 'quantity' => 2]],
    ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

test('the barcode label print route is rejected for an unauthenticated request', function () {
    $domain = 'barcode-label-print-guest.tenant-test';
    $tenant = provisionBarcodeLabelTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        $itemId = Item::factory()->create(['barcode' => '1112223334445'])->id;
    });

    $this->get("http://{$domain}/items/barcode-labels/print?".http_build_query([
        'items' => [['item_id' => $itemId, 'quantity' => 1]],
    ]))
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('the barcode label print route rejects a quantity above the sane per-item cap', function () {
    $domain = 'barcode-label-print-cap.tenant-test';
    $tenant = provisionBarcodeLabelTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $itemId = Item::factory()->create(['barcode' => '5556667778889'])->id;
    });

    loginBarcodeLabelTestUser($domain);

    $this->get("http://{$domain}/items/barcode-labels/print?".http_build_query([
        'items' => [['item_id' => $itemId, 'quantity' => 501]],
    ]))
        ->assertSessionHasErrors('items.0.quantity');

    $tenant->delete();
});

test('the rendered label sheet contains each requested copy of the exact item barcode value, name, and price', function () {
    $item = new Item([
        'name' => 'Bottled Water 1L',
        'barcode' => '8901234567890',
        'sale_rate' => 25.5,
    ]);

    $generator = new BarcodeGeneratorPNG;
    $barcodeImage = base64_encode($generator->getBarcode($item->barcode, $generator::TYPE_CODE_128));

    // Exactly what BarcodeLabelController::print() builds: one row of
    // $labels per requested copy, all carrying the same item's data.
    $labels = array_fill(0, 3, [
        'name' => $item->name,
        'price' => $item->sale_rate,
        'barcode' => $item->barcode,
        'barcodeImage' => $barcodeImage,
    ]);

    $html = view('pdf.barcode-labels', ['labels' => $labels])->render();

    expect(substr_count($html, '8901234567890'))->toBe(3)
        ->and(substr_count($html, 'Bottled Water 1L'))->toBe(3)
        ->and(substr_count($html, 'Rs. 25.50'))->toBe(3)
        ->and($html)->toContain('data:image/png;base64,'.$barcodeImage)
        ->and($html)->not->toContain('No barcode assigned');
});

test('an item with no barcode set renders its label with name/price but no barcode image, matching legacy behavior', function () {
    $labels = [
        ['name' => 'Unbarcoded Item', 'price' => 10.0, 'barcode' => null, 'barcodeImage' => null],
    ];

    $html = view('pdf.barcode-labels', ['labels' => $labels])->render();

    expect($html)
        ->toContain('Unbarcoded Item')
        ->toContain('No barcode assigned')
        ->not->toContain('label-barcode-img');
});
