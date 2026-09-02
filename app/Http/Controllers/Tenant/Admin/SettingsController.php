<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Tenant/Admin/Settings/Edit', [
            'settings' => CompanySetting::current(),
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
        ]);

        CompanySetting::current()->update($data);

        return redirect()->route('tenant.settings.edit')->with('status', 'Settings updated.');
    }
}
