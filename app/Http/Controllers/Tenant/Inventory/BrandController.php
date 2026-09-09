<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Item Brand/manufacturer master data - the multi-tenant equivalent of
 * legacy day_khata's misleadingly-named CompanyController (its own UI
 * labelled this "Add Item Brand"; it has nothing to do with the tenant's
 * own business profile, which is CompanySetting/SettingsController). Tags
 * Item.brand_id and drives BrandWiseReportController's stock-by-brand
 * report, the same way ItemCategory drives CategoryWiseReportController.
 * Mirrors ItemCategoryController's exact CRUD shape, plus an optional logo
 * upload (ItemController::storeImage()/deleteImage()'s pattern) since
 * legacy `companies` carried a `thumbnail`.
 */
class BrandController extends Controller
{
    private const LOGO_DISK = 'public';

    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/Brands/Index', [
            'brands' => Brand::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $this->storeLogo($request->file('logo'));
        }

        Brand::create($data);

        return redirect()->route('tenant.brands.index')->with('status', 'Brand added.');
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $data = $this->validated($request, $brand);

        if ($request->hasFile('logo')) {
            $this->deleteLogo($brand->logo_path);
            $data['logo_path'] = $this->storeLogo($request->file('logo'));
        }

        $brand->update($data);

        return redirect()->route('tenant.brands.index')->with('status', 'Brand updated.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $this->deleteLogo($brand->logo_path);
        $brand->delete();

        return redirect()->route('tenant.brands.index')->with('status', 'Brand deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('brands')->ignore($brand)],
            'logo' => ['nullable', 'image', 'max:2048'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * Namespaced under the tenant's id - same reasoning as
     * ItemController::IMAGE_DISK's docblock: the 'public' disk is never made
     * tenant-aware, so every tenant shares the same physical storage root.
     */
    private function storeLogo(UploadedFile $logo): string
    {
        return $logo->store('brands/'.tenant('id'), self::LOGO_DISK);
    }

    private function deleteLogo(?string $path): void
    {
        if ($path) {
            Storage::disk(self::LOGO_DISK)->delete($path);
        }
    }
}
