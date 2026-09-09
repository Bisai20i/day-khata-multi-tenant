<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Concerns\ImportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemController extends Controller
{
    use ImportsCsv;

    /**
     * The 'public' disk is never made tenant-aware (see config/tenancy.php's
     * bootstrappers docblock - FilesystemTenancyBootstrapper is deliberately
     * disabled), so every tenant shares the same physical storage/app/public
     * root. Item images are namespaced under items/{tenant_id}/ manually to
     * avoid collisions between tenants, mirroring BackupController's own
     * storagePath() convention for the same reason.
     */
    private const IMAGE_DISK = 'public';

    /**
     * Columns of the bulk-import template, in the order they're written to
     * the downloadable CSV. Also doubles as the set of fields read back out
     * of an uploaded file's header row (see ImportsCsv::parseCsvRows).
     * category/subcategory are matched by name (case-insensitive), not id -
     * a spreadsheet-friendly CSV can't reasonably expect the operator to
     * know internal ids. Image upload isn't supported via CSV (matches
     * Customer/Supplier import, neither of which supports bulk file
     * attachments either) - add one afterwards through the regular edit form.
     *
     * @var list<string>
     */
    private const IMPORT_COLUMNS = [
        'name', 'category', 'subcategory', 'unit', 'hs_code', 'barcode',
        'min_stock', 'purchase_rate', 'sale_rate', 'is_vatable', 'is_stockable',
    ];

    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/Items/Index', [
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name']),
            'subcategories' => ItemSubcategory::query()->orderBy('name')->get(['id', 'item_category_id', 'name']),
            'items' => Item::query()->with(['category:id,name', 'subcategory:id,name'])->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request->file('image'));
        }

        Item::create($data);

        return redirect()->route('tenant.items.index')->with('status', 'Item added.');
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $data = $this->validated($request, $item);

        if ($request->hasFile('image')) {
            $this->deleteImage($item->image_path);
            $data['image_path'] = $this->storeImage($request->file('image'));
        }

        $item->update($data);

        return redirect()->route('tenant.items.index')->with('status', 'Item updated.');
    }

    public function destroy(Item $item): RedirectResponse
    {
        $this->deleteImage($item->image_path);
        $item->delete();

        return redirect()->route('tenant.items.index')->with('status', 'Item deleted.');
    }

    /**
     * Downloads a blank CSV template with the exact header this app's import
     * expects, plus one example row.
     */
    public function importTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::IMPORT_COLUMNS);
            fputcsv($handle, ['Bottled Water 1L', 'Beverages', '', 'pcs', '2201.10.00', '', '10', '15.00', '20.00', 'yes', 'yes']);
            fclose($handle);
        }, 'item-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Row-by-row validated bulk import, matching Customer/SupplierController's
     * own import() exactly: rows that fail validation are skipped and
     * reported rather than aborting the whole import. category/subcategory
     * are resolved by name (case-insensitive) since a spreadsheet operator
     * can't reasonably be expected to know internal ids - an unknown name is
     * itself a skip reason, same as any other invalid row. The rows that do
     * pass are created one at a time (not a raw bulk insert), and the whole
     * batch is wrapped in a transaction so a failure partway through can't
     * leave a half-imported file.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $rows = $this->parseCsvRows($request->file('file'));

        if ($rows === null || ! array_key_exists('name', $rows[0])) {
            return back()->withErrors(['file' => 'That file could not be read. Make sure it matches the downloaded template and has a "name" column.']);
        }

        $categories = ItemCategory::all(['id', 'name'])->keyBy(fn (ItemCategory $category) => strtolower($category->name));
        $subcategories = ItemSubcategory::all(['id', 'item_category_id', 'name']);

        $seenBarcodes = [];
        $skipped = [];
        $validRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for the 0-based index, +1 for the header row.
            $name = $row['name'] ?? '';
            $categoryName = trim($row['category'] ?? '');
            $subcategoryName = trim($row['subcategory'] ?? '');
            $barcode = $row['barcode'] ?? '';

            $category = $categoryName !== '' ? $categories->get(strtolower($categoryName)) : null;

            if ($categoryName === '') {
                $skipped[] = ['row' => $rowNumber, 'name' => $name, 'reason' => 'A category is required.'];

                continue;
            }

            if (! $category) {
                $skipped[] = ['row' => $rowNumber, 'name' => $name, 'reason' => "Unknown item category \"{$categoryName}\"."];

                continue;
            }

            $subcategoryId = null;

            if ($subcategoryName !== '') {
                $subcategory = $subcategories->first(
                    fn (ItemSubcategory $candidate) => $candidate->item_category_id === $category->id
                        && strtolower($candidate->name) === strtolower($subcategoryName),
                );

                if (! $subcategory) {
                    $skipped[] = ['row' => $rowNumber, 'name' => $name, 'reason' => "Unknown subcategory \"{$subcategoryName}\" under category \"{$categoryName}\"."];

                    continue;
                }

                $subcategoryId = $subcategory->id;
            }

            $data = [
                'name' => $name,
                'unit' => $row['unit'] ?? '',
                'hs_code' => $row['hs_code'] ?? '',
                'barcode' => $barcode,
                'min_stock' => $row['min_stock'] ?? '',
                'purchase_rate' => $row['purchase_rate'] ?? '',
                'sale_rate' => $row['sale_rate'] ?? '',
            ];

            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'unit' => ['required', 'string', 'max:50'],
                'hs_code' => ['nullable', 'string', 'max:30'],
                'barcode' => ['nullable', 'string', 'max:100', Rule::unique('items', 'barcode')],
                'min_stock' => ['nullable', 'numeric', 'min:0'],
                'purchase_rate' => ['nullable', 'numeric', 'min:0'],
                'sale_rate' => ['nullable', 'numeric', 'min:0'],
            ]);

            if ($validator->fails()) {
                $skipped[] = ['row' => $rowNumber, 'name' => $name, 'reason' => $validator->errors()->first()];

                continue;
            }

            $barcodeKey = strtolower(trim($barcode));

            if ($barcodeKey !== '' && isset($seenBarcodes[$barcodeKey])) {
                $skipped[] = ['row' => $rowNumber, 'name' => $name, 'reason' => 'Duplicate barcode already used earlier in this file.'];

                continue;
            }

            if ($barcodeKey !== '') {
                $seenBarcodes[$barcodeKey] = true;
            }

            $validRows[] = [
                'item_category_id' => $category->id,
                'item_subcategory_id' => $subcategoryId,
                'name' => $data['name'],
                'unit' => $data['unit'],
                'hs_code' => $data['hs_code'] === '' ? null : $data['hs_code'],
                'barcode' => $barcode === '' ? null : $barcode,
                'min_stock' => $data['min_stock'] === '' ? null : $data['min_stock'],
                'purchase_rate' => $data['purchase_rate'] === '' ? null : $data['purchase_rate'],
                'sale_rate' => $data['sale_rate'] === '' ? null : $data['sale_rate'],
                'is_vatable' => $this->parseBoolean($row['is_vatable'] ?? '', false),
                'is_stockable' => $this->parseBoolean($row['is_stockable'] ?? '', true),
            ];
        }

        $imported = 0;

        if ($validRows !== []) {
            DB::transaction(function () use ($validRows, &$imported): void {
                foreach ($validRows as $data) {
                    Item::create($data);
                    $imported++;
                }
            });
        }

        $total = $imported + count($skipped);

        return redirect()->route('tenant.items.index')
            ->with('status', "Imported {$imported} of {$total} item(s).")
            ->with('importResult', ['imported' => $imported, 'skipped' => $skipped]);
    }

    /**
     * Blank -> $default, otherwise a lenient truthy check (matches how a
     * spreadsheet user is likely to type it: "yes"/"y"/"true"/"1").
     */
    private function parseBoolean(string $value, bool $default): bool
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'y'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Item $item = null): array
    {
        return $request->validate([
            'item_category_id' => ['required', 'exists:item_categories,id'],
            'item_subcategory_id' => [
                'nullable', 'exists:item_subcategories,id',
                function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                    if ($value && ItemSubcategory::find($value)?->item_category_id !== (int) $request->item_category_id) {
                        $fail('The selected subcategory does not belong to the selected category.');
                    }
                },
            ],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:50'],
            'hs_code' => ['nullable', 'string', 'max:30'],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('items', 'barcode')->ignore($item?->id)],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'expiry_date' => ['nullable', 'date'],
            'purchase_rate' => ['nullable', 'numeric', 'min:0'],
            'sale_rate' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'max:2048'],
            'is_vatable' => ['boolean'],
            'is_stockable' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    private function storeImage(UploadedFile $image): string
    {
        return $image->store('items/'.tenant('id'), self::IMAGE_DISK);
    }

    private function deleteImage(?string $path): void
    {
        if ($path) {
            Storage::disk(self::IMAGE_DISK)->delete($path);
        }
    }
}
