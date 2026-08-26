<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SalesReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SalesReturnController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Sales/Returns/Index', [
            'returns' => SalesReturn::query()
                ->with(['sale.customer:id,name', 'lines.saleLine.item:id,name,unit'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'sales' => Sale::query()
                ->where('status', 'posted')
                ->with(['customer:id,name', 'lines.item:id,name,unit'])
                ->orderByDesc('date')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sale_id' => ['required', 'exists:sales,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', 'exists:sale_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        try {
            SalesReturn::post(
                ['sale_id' => $data['sale_id'], 'date' => $data['date'], 'reason' => $data['reason'] ?? null],
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Sales return posted.');
    }
}
