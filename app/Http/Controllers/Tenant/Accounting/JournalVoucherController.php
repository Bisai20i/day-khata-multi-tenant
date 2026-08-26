<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class JournalVoucherController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/JournalVouchers/Index', [
            'journalVouchers' => JournalVoucher::query()
                ->with(['fiscalYear:id,name', 'creator:id,name', 'lines.account:id,code,name'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(['id', 'name', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fiscal_year_id' => ['nullable', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'narration' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            JournalVoucher::post(
                Arr::only($data, ['fiscal_year_id', 'reason', 'date', 'narration']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.journal-vouchers.index')->with('status', 'Journal voucher posted.');
    }
}
