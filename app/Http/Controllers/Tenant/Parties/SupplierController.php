<?php

namespace App\Http\Controllers\Tenant\Parties;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Parties/Suppliers/Index', [
            'suppliers' => Supplier::query()->with('account:id,code')->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create($this->validated($request));

        return redirect()->route('tenant.suppliers.index')->with('status', 'Supplier added.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return redirect()->route('tenant.suppliers.index')->with('status', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()->route('tenant.suppliers.index')->with('status', 'Supplier deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:20', Rule::unique('suppliers')->ignore($supplier)],
            'email' => ['nullable', 'email', 'max:255'],
            'tpin' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
