<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Support\FiscalYear\FiscalYearArchiver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FiscalYearArchiveController extends Controller
{
    public function store(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        try {
            FiscalYearArchiver::archive($fiscalYear, $request->user('web'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['fiscal_year' => $e->getMessage()]);
        }

        return redirect()->route('tenant.fiscal-years.index')->with('status', 'Fiscal year archived.');
    }
}
