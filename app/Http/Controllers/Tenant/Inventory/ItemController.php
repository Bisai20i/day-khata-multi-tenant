<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Concerns\ImportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\ItemUnit;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection as SupportCollection;
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
     * Memoised because both the page render and the validation rule ask for
     * the same list within one request.
     *
     * @var SupportCollection<int, array{id: int, code: ?string, name: string, label: string}>|null
     */
    private ?SupportCollection $postingAccounts = null;

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
        'posting_account',
    ];

    public function index(): Response
    {
        $items = Item::query()
            ->with(['category:id,name', 'subcategory:id,name', 'brand:id,name', 'account:id,code,name', 'units' => fn ($q) => $q->orderBy('name')])
            ->latest()
            ->get();

        $stock = Item::currentStockByItem($items->modelKeys());

        return Inertia::render('Tenant/Inventory/Items/Index', [
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name']),
            'subcategories' => ItemSubcategory::query()->orderBy('name')->get(['id', 'item_category_id', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'items' => $items,
            // Exact decimal strings, never numbers: the page formats them
            // with money.js and never does arithmetic on them. Also what the
            // "can this item be deactivated" hint reads.
            'stockByItem' => $items->mapWithKeys(fn (Item $item) => [
                $item->id => (string) ($stock[$item->id] ?? '0.0000'),
            ]),
            'postingAccounts' => $this->postingAccountOptions()->values(),
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

    /**
     * Deleting an item that any document has ever referenced hits a
     * restrictOnDelete foreign key. That used to surface as a raw SQL
     * exception page (audit P3, "friendly FK error on delete"); here it
     * comes back as a field error explaining why, and the image is only
     * removed once the row has actually gone.
     */
    public function destroy(Item $item): RedirectResponse
    {
        $imagePath = $item->image_path;

        try {
            $item->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'item' => "\"{$item->name}\" is used by a bill, a stock movement or another record, so it cannot be deleted. Mark it inactive instead.",
            ]);
        }

        $this->deleteImage($imagePath);

        return redirect()->route('tenant.items.index')->with('status', 'Item deleted.');
    }

    /**
     * Nested under an item's own edit screen (Items/Index.vue's "Units"
     * panel) rather than a separate top-level resource - mirrors how
     * ItemVarietyController manages ItemVariety, except varieties get their
     * own flat top-level page while units are simple enough to live inline
     * on the Items list. See ItemUnit's docblock: an item with zero rows
     * here is unaffected, so this is purely additive.
     */
    public function storeUnit(Request $request, Item $item): RedirectResponse
    {
        $item->units()->create($this->validatedUnit($request, $item));

        return redirect()->route('tenant.items.index')->with('status', 'Unit added.');
    }

    public function updateUnit(Request $request, Item $item, ItemUnit $itemUnit): RedirectResponse
    {
        abort_unless($itemUnit->item_id === $item->id, 404);

        $itemUnit->update($this->validatedUnit($request, $item, $itemUnit));

        return redirect()->route('tenant.items.index')->with('status', 'Unit updated.');
    }

    public function destroyUnit(Item $item, ItemUnit $itemUnit): RedirectResponse
    {
        abort_unless($itemUnit->item_id === $item->id, 404);

        $itemUnit->delete();

        return redirect()->route('tenant.items.index')->with('status', 'Unit deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedUnit(Request $request, Item $item, ?ItemUnit $itemUnit = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('item_units', 'name')->where('item_id', $item->id)->ignore($itemUnit?->id),
            ],
            // At least 1, at most 4 decimals. The item's own `unit` is by
            // definition the SMALLEST unit it is counted in, so an alternate
            // unit always contains a whole number (or a fraction >= 1) of
            // them: a "Box" is 12 pcs, never 1/12 of one. Audit P1 found
            // factors below 1 accepted at 4 decimals, so "1/12" was stored
            // as 0.0833 and 12 pieces drifted to 0.9996 base units - a
            // permanent, compounding loss of stock. More than 4 decimals is
            // rejected rather than silently rounded (the same rule as every
            // other quantity column, CONTRACTS C2).
            'conversion_factor' => ['required', 'numeric', 'min:1', 'decimal:0,4'],
            'purchase_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'sale_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'mrp' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            // Per-unit barcode (item 6): a "Box of 12" scans differently
            // than a single piece.
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('item_units', 'barcode')->ignore($itemUnit?->id)],
            'is_active' => ['boolean'],
        ], [
            'conversion_factor.min' => 'The conversion must be at least 1: the item\'s own unit is its smallest unit, so an alternate unit holds one or more of them.',
        ]);
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
            fputcsv($handle, ['Bottled Water 1L', 'Beverages', '', 'pcs', '2201.10.00', '', '10', '15.00', '20.00', 'yes', 'yes', 'EXE8']);
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

        // Matched by code first ("EXE8"), then by name, and only against the
        // expense/fixed-asset accounts an item is allowed to post to - a
        // spreadsheet operator can't be expected to know internal ids.
        $postingAccounts = $this->postingAccountOptions();

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

            $postingAccountName = trim($row['posting_account'] ?? '');
            $postingAccountId = null;

            if ($postingAccountName !== '') {
                $match = $postingAccounts->first(
                    fn (array $account) => strtolower((string) $account['code']) === strtolower($postingAccountName)
                        || strtolower($account['name']) === strtolower($postingAccountName),
                );

                if (! $match) {
                    $skipped[] = ['row' => $rowNumber, 'name' => $name, 'reason' => "Unknown posting account \"{$postingAccountName}\" - it must be an expense or fixed asset account."];

                    continue;
                }

                $postingAccountId = $match['id'];
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
                'min_stock' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'purchase_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
                'sale_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
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
                'account_id' => $postingAccountId,
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
            'brand_id' => ['nullable', 'exists:brands,id'],
            // The ledger account this item's purchases are debited to
            // (Purchase::post() falls back to EXE8 "Purchases Account" when
            // it is null). Audit P1: there was no way to set it in the UI at
            // all, so a service item and a capital item both landed in
            // EXE8. Restricted to Expense and Fixed Asset accounts, because
            // those are the only two things buying an item can be.
            'account_id' => ['nullable', $this->postingAccountRule()],
            // Case-insensitive (audit section 4 polish, "item name
            // uniqueness"): "Coke 500ml" and "coke 500ml" are the same
            // product to a clerk typing a bill, and a plain unique rule on
            // MySQL's default collation would already reject one of them but
            // silently allow it on SQLite - this closure makes the rule
            // portable and explicit.
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule($item)],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:50'],
            'hs_code' => ['nullable', 'string', 'max:30'],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('items', 'barcode')->ignore($item?->id)],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'expiry_date' => ['nullable', 'date'],
            'purchase_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'sale_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            // Base-unit MRP (item 6), alongside the per-alternate-unit
            // item_units.mrp that already existed.
            'mrp' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'image' => ['nullable', 'image', 'max:2048'],
            'is_vatable' => ['boolean'],
            'is_stockable' => ['boolean'],
            'is_active' => ['boolean', $this->stillStockedRule($item)],
        ]);
    }

    /**
     * Refuses to deactivate an item that still has stock on hand (audit P3).
     * An inactive item disappears from every picker, so the quantity would
     * sit in the valuation and the Balance Sheet with no way to sell, adjust
     * or transfer it out. The item has to be emptied first.
     */
    private function stillStockedRule(?Item $item): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($item): void {
            // Nothing to guard when creating, when the item is staying (or
            // becoming) active, or when it was already inactive.
            if ($item === null || filter_var($value, FILTER_VALIDATE_BOOLEAN) || ! $item->is_active) {
                return;
            }

            $onHand = $item->currentStock();

            if (! $onHand->isZero()) {
                $fail("\"{$item->name}\" still has {$onHand->formatQuantity()} {$item->unit} in stock. Clear the stock first, or leave the item active.");
            }
        };
    }

    /**
     * Bulk "mark vatable" (item 6): flips every named item to vatable in one
     * request, for a tenant that just discovered a whole category should
     * have been charging VAT all along instead of editing each one by hand.
     */
    public function markVatable(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'distinct', 'exists:items,id'],
        ]);

        $updated = Item::whereIn('id', $data['item_ids'])->update(['is_vatable' => true]);

        return redirect()->route('tenant.items.index')->with('status', "Marked {$updated} item(s) vatable.");
    }

    /**
     * Per-unit barcode scan (item 6): a scanner fires this on Sales/Create,
     * Pos.vue and Purchases/Create (cross-file wiring, those pages are owned
     * elsewhere - see this task's final report) with whatever code it read.
     * `item_units.barcode` is checked first, since it is the more specific
     * match (a "Box of 12" and its own single piece can scan two different
     * codes for the same item); `items.barcode` - the base unit's own code -
     * is the fallback. `item_unit_id` in the response is null for a
     * base-unit match, exactly like every document line that has not picked
     * an alternate unit.
     */
    public function lookupBarcode(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));

        if ($code === '') {
            return response()->json(['message' => 'A barcode is required.'], 422);
        }

        $unit = ItemUnit::query()->where('barcode', $code)->with('item.units')->first();

        if ($unit) {
            return response()->json([
                'item' => $unit->item,
                'item_unit_id' => $unit->id,
            ]);
        }

        $item = Item::query()->where('barcode', $code)->with('units')->first();

        if ($item) {
            return response()->json([
                'item' => $item,
                'item_unit_id' => null,
            ]);
        }

        return response()->json(['message' => 'No item or unit matches this barcode.'], 404);
    }

    /**
     * A plain `Rule::unique` is case-sensitive on SQLite's default BINARY
     * collation (though not on MySQL's default utf8mb4_unicode_ci), so this
     * closure checks LOWER(name) directly instead, portable across both.
     */
    private function uniqueNameRule(?Item $item): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($item): void {
            $exists = Item::query()
                ->whereRaw('LOWER(name) = ?', [strtolower((string) $value)])
                ->when($item !== null, fn ($query) => $query->whereKeyNot($item->id))
                ->exists();

            if ($exists) {
                $fail('An item with this name already exists.');
            }
        };
    }

    /**
     * `account_id` must be one of the accounts postingAccountOptions() offers,
     * not any account at all: a plain `exists:accounts,id` would happily let
     * an item post its purchases into Sundry Debtors.
     */
    private function postingAccountRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! $this->postingAccountOptions()->contains(fn (array $account) => $account['id'] === (int) $value)) {
                $fail('The posting account must be an expense or fixed asset account.');
            }
        };
    }

    /**
     * Accounts an item may post to: anything under the Expenses head (a
     * normal stock or service purchase) plus the Fixed Assets group (a
     * capital item). Resolved in PHP rather than SQL because an account hangs
     * off either a group or a subgroup, never both (see Account::booted()),
     * so the head is two different joins away and the table is small enough
     * that one eager-loaded read is cheaper than the union.
     *
     * @return SupportCollection<int, array{id: int, code: ?string, name: string, label: string}>
     */
    private function postingAccountOptions(): SupportCollection
    {
        return $this->postingAccounts ??= Account::query()
            ->with(['group.accountHead', 'subgroup.accountGroup.accountHead'])
            ->orderBy('name')
            ->get()
            ->filter(function (Account $account): bool {
                $group = $account->group ?? $account->subgroup?->accountGroup;

                return $group?->name === 'Fixed Assets'
                    || ($group?->accountHead->name ?? null) === 'Expenses';
            })
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'label' => $account->code ? "{$account->code} - {$account->name}" : $account->name,
            ])
            ->values();
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
