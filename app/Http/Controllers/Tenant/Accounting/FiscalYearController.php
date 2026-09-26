<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Admin\BackupController;
use App\Models\Backup;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\NepaliCalendar;
use Carbon\CarbonImmutable;
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
            'fiscalYearOptions' => $this->fiscalYearOptions(),
        ]);
    }

    /**
     * A fiscal year is always Nepal's official one - Shrawan 1 through the
     * last day of the following Ashad - so the only thing the user picks is
     * the BS year it starts in; both dates are derived here via
     * NepaliCalendar::fiscalYear() rather than typed in, so a year can never
     * be created on arbitrary dates.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bs_year' => ['required', 'integer', 'between:2000,2089'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $bounds = NepaliCalendar::fiscalYear((int) $data['bs_year']);
        $name = trim((string) ($data['name'] ?? ''));

        // The very first fiscal year a tenant ever creates opens
        // automatically (there's nothing to close first); every one after
        // that starts Closed and is opened deliberately via close() below.
        $attributes = [
            'name' => $name !== '' ? $name : $bounds['name'],
            'start_date' => $bounds['start']->toDateString(),
            'end_date' => $bounds['end']->toDateString(),
            'status' => FiscalYear::query()->exists() ? FiscalYearStatus::Closed : FiscalYearStatus::Open,
        ];

        try {
            FiscalYear::create($attributes);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['bs_year' => $e->getMessage()])->withInput();
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
     * The BS fiscal years offered on the create form: five back and two
     * ahead of the one today falls in, each with its derived Shrawan 1 /
     * Ashad-end dates so the form can show exactly what will be created.
     *
     * @return array<int, array{value: int, label: string, start_date: string, end_date: string}>
     */
    private function fiscalYearOptions(): array
    {
        $current = NepaliCalendar::fiscalYearStartBsYear(CarbonImmutable::now('Asia/Kathmandu')->toDateString());

        return collect(range(min($current + 2, 2089), max($current - 5, 2000)))
            ->map(function (int $bsYear): array {
                $bounds = NepaliCalendar::fiscalYear($bsYear);

                return [
                    'value' => $bsYear,
                    'label' => $bounds['name'],
                    'start_date' => $bounds['start']->toDateString(),
                    'end_date' => $bounds['end']->toDateString(),
                ];
            })
            ->all();
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
