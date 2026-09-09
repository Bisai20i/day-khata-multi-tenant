<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    /**
     * The 'public' disk is never made tenant-aware (see config/tenancy.php's
     * bootstrappers docblock - FilesystemTenancyBootstrapper is deliberately
     * disabled), so every tenant shares the same physical storage/app/public
     * root. Item images are namespaced under items/{tenant_id}/ manually to
     * avoid collisions between tenants, mirroring BackupController's own
     * storagePath() convention for the same reason.
     */
    private const IMAGE_DISK = 'public';

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
