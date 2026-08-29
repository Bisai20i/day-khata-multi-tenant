<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
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
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sale_id' => ['required', 'exists:sales,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_account_id' => ['nullable', 'exists:accounts,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', 'exists:sale_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        try {
            SalesReturn::post(
                [
                    'sale_id' => $data['sale_id'],
                    'date' => $data['date'],
                    'reason' => $data['reason'] ?? null,
                    'refund_account_id' => $data['refund_account_id'] ?? null,
                ],
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Sales return posted.');
    }

    public function cancel(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $salesReturn->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Sales return cancelled.');
    }
}
