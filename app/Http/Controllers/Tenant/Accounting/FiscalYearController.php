<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Admin\BackupController;
use App\Models\Backup;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FiscalYearController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/FiscalYears/Index', [
            'fiscalYears' => FiscalYear::query()->with('archive')->orderByDesc('start_date')->get(),
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

        try {
            FiscalYear::create($data);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['start_date' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.fiscal-years.index')->with('status', 'Fiscal year added.');
    }

    /**
     * Closing a year is the single most destructive accounting action in the
     * app: it posts depreciation, the periodic-inventory trading pair, the
     * P&L sweep and the next year's opening balances, and none of it is
     * undoable from the UI. So a fresh database backup is taken FIRST, using
     * the tenant's existing on-demand backup mechanism, and the close is
     * refused outright if that backup cannot be produced. An admin who has
     * just been told "the backup failed" can fix the backup path and try
     * again; an admin who was never told would have had no way back.
     *
     * $reason is only required when the year has not reached its end date -
     * FiscalYear::close() enforces that rule, this just carries the field.
     */
    public function close(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        $data = $request->validate([
            'next_fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $next = FiscalYear::findOrFail($data['next_fiscal_year_id']);

        if ($next->journalVouchers()->exists()) {
            return back()->withErrors(['next_fiscal_year_id' => 'That fiscal year already has vouchers posted and cannot be used as the next year.']);
        }

        try {
            $backup = $this->takePreCloseBackup($request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['next_fiscal_year_id' => $e->getMessage()]);
        }

        try {
            $fiscalYear->close($next, $request->user(), $data['reason'] ?? null);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['next_fiscal_year_id' => $e->getMessage()]);
        }

        return redirect()->route('tenant.fiscal-years.index')
            ->with('status', "Fiscal year closed. A backup ({$backup->filename}) was taken first.");
    }

    /**
     * Reopens a closed fiscal year for correction: Purchase/Journal
     * Voucher/Stock Adjustment postings may then target it (with a reason)
     * until it's relocked. FiscalYear::reopen() logs to the tenant
     * activity log itself, via the generic ActivityLogObserver now
     * attached to this model (see AppServiceProvider::boot()) - its own
     * plain update() already carries reopened_by/reopened_at/reopen_reason
     * in `changes`, so no separate logging call is needed here.
     */
    public function reopen(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $fiscalYear->reopen($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.fiscal-years.index')->with('status', "\"{$fiscalYear->name}\" reopened for correction.");
    }

    /**
     * Ends a fiscal year's reopened-for-correction window. See reopen()'s
     * docblock for why no separate logging call is needed here either.
     *
     * The actor is passed through because relocking now posts a
     * supplementary closing entry for whatever the corrections left unswept
     * (audit P0-19), and every voucher needs a creator.
     */
    public function relock(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        try {
            $fiscalYear->relock($request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['fiscal_year' => $e->getMessage()]);
        }

        return redirect()->route('tenant.fiscal-years.index')->with('status', "\"{$fiscalYear->name}\" relocked.");
    }

    /**
     * Runs the tenant's existing on-demand backup and returns the resulting
     * Backup row, throwing when no usable backup came out of it.
     *
     * Deliberately calls BackupController rather than reimplementing a dump:
     * that class already handles both drivers (a SQLite file copy, a
     * mysqldump shell-out with the password passed via MYSQL_PWD), writes
     * outside public/, and records the attempt either way. It is used here,
     * never edited.
     */
    private function takePreCloseBackup(User $actor): Backup
    {
        $controller = new BackupController;

        if (! is_callable([$controller, 'performBackup'])) {
            throw new RuntimeException('A backup must be taken before a fiscal year can be closed, but the backup mechanism is not available. Take a manual backup from Admin > Backups and try again.');
        }

        try {
            $backup = $controller->performBackup($actor);
        } catch (Throwable $e) {
            report($e);

            throw new RuntimeException('The pre-close backup failed, so the fiscal year was not closed. Check Admin > Backups, then try again.');
        }

        if ($backup->status !== 'completed') {
            throw new RuntimeException('The pre-close backup failed, so the fiscal year was not closed. Check Admin > Backups, then try again.');
        }

        return $backup;
    }
}
