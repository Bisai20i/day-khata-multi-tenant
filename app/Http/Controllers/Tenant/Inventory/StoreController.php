<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/Stores/Index', [
            'stores' => Store::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Store::create($this->validated($request));

        return redirect()->route('tenant.stores.index')->with('status', 'Store added.');
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $store->update($this->validated($request));

        return redirect()->route('tenant.stores.index')->with('status', 'Store updated.');
    }

    public function destroy(Store $store): RedirectResponse
    {
        $store->delete();

        return redirect()->route('tenant.stores.index')->with('status', 'Store deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
