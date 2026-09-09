<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * The 'public' disk is never made tenant-aware (see config/tenancy.php's
     * bootstrappers docblock - FilesystemTenancyBootstrapper is deliberately
     * disabled), so every tenant shares the same physical storage/app/public
     * root. Logos are namespaced under tenant-logos/{tenant_id}/ manually to
     * avoid collisions between tenants, mirroring ItemController's own
     * storeImage() convention for the same reason.
     */
    private const LOGO_DISK = 'public';

    public function edit(): Response
    {
        return Inertia::render('Tenant/Admin/Settings/Edit', [
            'settings' => CompanySetting::current(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'pan_vat_number' => ['nullable', 'string', 'max:255'],
            'invoice_footer_note' => ['nullable', 'string', 'max:2000'],
            'print_paper_size' => ['nullable', 'in:a4,a5,58mm,80mm'],
            'default_vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'allow_negative_stock' => ['boolean'],
            'default_store_id' => ['nullable', 'exists:stores,id'],
            'sale_full_prefix' => ['required', 'string', 'max:20'],
            'sale_full_enabled' => ['boolean'],
            'sale_abbreviated_prefix' => ['required', 'string', 'max:20'],
            'sale_abbreviated_enabled' => ['boolean'],
            'sale_pan_prefix' => ['required', 'string', 'max:20'],
            'sale_pan_enabled' => ['boolean'],
            'purchase_prefix' => ['required', 'string', 'max:20'],
        ]);

        CompanySetting::current()->update($data);

        return redirect()->route('tenant.settings.edit')->with('status', 'Settings updated.');
    }

    /**
     * Kept separate from update() so uploading a logo doesn't require
     * re-submitting (and re-validating) the whole settings form.
     */
    public function uploadLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $settings = CompanySetting::current();

        if ($settings->logo_path) {
            Storage::disk(self::LOGO_DISK)->delete($settings->logo_path);
        }

        $path = $request->file('logo')->store('tenant-logos/'.tenant('id'), self::LOGO_DISK);

        $settings->update(['logo_path' => $path]);

        return redirect()->route('tenant.settings.edit')->with('status', 'Logo updated.');
    }
}
