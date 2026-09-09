<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Renders a printable sheet of CODE-128 barcode labels for one or more
 * items - fills the audit gap in legacy day_khata's
 * InventoryController::itembarcode()/printBarcode() (msc/barcode.blade.php,
 * msc/printbarcode.blade.php): multi-tenant only ever supported *scanning* a
 * barcode to find an item (Item.barcode + the Combobox's searchText on
 * Sales/Purchases), with no way to *generate/print* the label stickers
 * themselves. Uses picqer/php-barcode-generator (the actively-maintained
 * equivalent of legacy's Picqer\Barcode\BarcodeGeneratorPNG - same vendor,
 * same API, just no longer abandoned) to render each barcode as a PNG,
 * embedded as a base64 <img> the same way legacy's printbarcode.blade.php
 * did, then streams the sheet through dompdf (already used for every other
 * printable document in this app - see SaleController::print()) instead of
 * legacy's raw-HTML-swap + window.print() trick.
 *
 * An item with no barcode set renders its label with name/price but no
 * barcode image - legacy's msc/barcode.blade.php did the same
 * (`@if($item->barcode!=null) ... @else &nbsp; @endif`), never fabricating
 * a placeholder code, so a blank label here matches that exactly rather
 * than inventing a value nothing else in the system would ever recognize as
 * this item's barcode.
 */
class BarcodeLabelController extends Controller
{
    /**
     * Hard cap on labels printed per item - purely a sane guardrail against
     * an accidental/malicious request generating an enormous PDF (every
     * label embeds its own PNG image), not a business rule.
     */
    private const MAX_QUANTITY_PER_ITEM = 500;

    public function print(Request $request): HttpResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'distinct', 'exists:items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY_PER_ITEM],
        ]);

        $items = Item::query()->whereIn('id', collect($validated['items'])->pluck('item_id'))->get()->keyBy('id');
        $generator = new BarcodeGeneratorPNG;

        $labels = [];

        foreach ($validated['items'] as $row) {
            $item = $items->get($row['item_id']);

            if (! $item) {
                continue;
            }

            $barcodeImage = $item->barcode !== null
                ? base64_encode($generator->getBarcode($item->barcode, $generator::TYPE_CODE_128))
                : null;

            for ($i = 0; $i < $row['quantity']; $i++) {
                $labels[] = [
                    'name' => $item->name,
                    'price' => $item->sale_rate,
                    'barcode' => $item->barcode,
                    'barcodeImage' => $barcodeImage,
                ];
            }
        }

        $pdf = Pdf::loadView('pdf.barcode-labels', ['labels' => $labels]);
        $pdf->setPaper('a4');

        return $pdf->stream('barcode-labels.pdf');
    }
}
