<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class FiscalYearController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/FiscalYears/Index', [
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        // The very first fiscal year a tenant ever creates opens
        // automatically (there's nothing to close first); every one after
        // that starts Closed and is opened deliberately via close() below.
        $data['status'] = FiscalYear::query()->exists() ? FiscalYearStatus::Closed : FiscalYearStatus::Open;

        FiscalYear::create($data);

        return redirect()->route('tenant.fiscal-years.index')->with('status', 'Fiscal year added.');
    }

    public function close(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        $data = $request->validate([
            'next_fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
        ]);

        $next = FiscalYear::findOrFail($data['next_fiscal_year_id']);

        if ($next->journalVouchers()->exists()) {
            return back()->withErrors(['next_fiscal_year_id' => 'That fiscal year already has vouchers posted and cannot be used as the next year.']);
        }

        try {
            $fiscalYear->close($next, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['next_fiscal_year_id' => $e->getMessage()]);
        }

        return redirect()->route('tenant.fiscal-years.index')->with('status', 'Fiscal year closed.');
    }
}
