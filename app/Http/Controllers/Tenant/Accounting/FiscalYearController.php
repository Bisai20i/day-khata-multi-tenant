<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Admin\BackupController;
use App\Http\Requests\Tenant\Accounting\UpdateNumberingScopeRequest;
use App\Models\Backup;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\User;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchContext;
use App\Support\Branches\NumberingScope;
use App\Support\NepaliCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FiscalYearController extends Controller
{
    public function index(Request $request): Response
    {
        $fiscalYears = FiscalYear::query()->with('archive:id,fiscal_year_id')->orderByDesc('start_date')->get();

        return Inertia::render('Tenant/Accounting/FiscalYears/Index', [
            'fiscalYears' => $fiscalYears,
            'fiscalYearOptions' => $this->fiscalYearOptions(),
            // Per-branch numbering (plan 12, BR20): only a multi-branch
            // company gets the prop, so everyone else sees no change.
            ...(ActiveBranchSelection::isMultiBranch($request) ? ['numbering' => $this->numberingProps($request, $fiscalYears)] : []),
        ]);
    }

    /**
     * Owner only (UpdateNumberingScopeRequest); NumberingScope::change()
     * enforces every rule under the fiscal year lock and its refusals come
     * back as a form error.
     */
    public function updateNumberingScope(UpdateNumberingScopeRequest $request, FiscalYear $fiscalYear): RedirectResponse
    {
        try {
            $changed = NumberingScope::change($fiscalYear, $request->validated('numbering_scope'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['numbering_scope' => $e->getMessage()]);
        }

        return redirect()->route('tenant.fiscal-years.index')->with('status', $changed
            ? "Numbering of \"{$fiscalYear->name}\" updated."
            : "\"{$fiscalYear->name}\" already uses that numbering.");
    }

    /**
     * What the numbering control needs: whether the viewer may change it
     * (owner), the active branches still without a series code, and per year
     * whether its scope can still change (never closed, and no voucher yet;
     * read outside the branch access scope, a READ only). The years with
     * vouchers come from one grouped query.
     *
     * @param  Collection<int, FiscalYear>  $fiscalYears
     * @return array{can_change: bool, branches_missing_series_code: list<string>, changeable_year_ids: list<int>}
     */
    private function numberingProps(Request $request, Collection $fiscalYears): array
    {
        $user = $request->user();
        $canChange = $user instanceof User && $user->isOwner();

        if (! $canChange) {
            return ['can_change' => false, 'branches_missing_series_code' => [], 'changeable_year_ids' => []];
        }

        $yearsWithVouchers = BranchContext::unrestricted(fn () => JournalVoucher::query()
            ->select('fiscal_year_id')
            ->distinct()
            ->pluck('fiscal_year_id')
            ->map(fn ($id): int => (int) $id)
            ->all());

        return [
            'can_change' => true,
            'branches_missing_series_code' => Branch::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('series_code')->orWhere('series_code', ''))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('name')
                ->all(),
            'changeable_year_ids' => $fiscalYears
                ->filter(fn (FiscalYear $year): bool => NumberingScope::yearMayChange($year) && ! in_array((int) $year->id, $yearsWithVouchers, true))
                ->map(fn (FiscalYear $year): int => (int) $year->id)
                ->values()
                ->all(),
        ];
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

        if ($refusal = $this->refuseBranchRestrictedActor($request, 'close', 'next_fiscal_year_id')) {
            return $refusal;
        }

        $next = FiscalYear::findOrFail($data['next_fiscal_year_id']);

        // Outside the branch access scope: a restricted actor must not miss
        // another branch's vouchers in the next year.
        if (BranchContext::unrestricted(fn () => $next->journalVouchers()->exists())) {
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

        if ($refusal = $this->refuseBranchRestrictedActor($request, 'reopen', 'reason')) {
            return $refusal;
        }

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
        if ($refusal = $this->refuseBranchRestrictedActor($request, 'relock', 'fiscal_year')) {
            return $refusal;
        }

        try {
            $fiscalYear->relock($request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['fiscal_year' => $e->getMessage()]);
        }

        return redirect()->route('tenant.fiscal-years.index')->with('status', "\"{$fiscalYear->name}\" relocked.");
    }

    /**
     * Closing, reopening and relocking act on the whole company's books (every
     * branch's vouchers, the company-wide P&L sweep and openings), so in a
     * multi-branch company a branch-restricted actor may not do them (plan
     * 12, section 5.7, BR22 S2). Restricted means BranchContext gives the
     * actor an allowed set rather than null; unrestricted() is deliberately
     * not consulted, since it lifts access for system code, not for a person.
     * Null (go ahead) for unrestricted actors and for a company that is not
     * multi-branch, so their behaviour is unchanged.
     */
    private function refuseBranchRestrictedActor(Request $request, string $action, string $field): ?RedirectResponse
    {
        $user = $request->user();

        if (! ActiveBranchSelection::isMultiBranch($request) || ! $user instanceof User || BranchContext::allowedBranchIds($user) === null) {
            return null;
        }

        $word = strtolower(ActiveBranchSelection::label());

        return back()->withErrors([
            $field => "Only a user with access to every {$word} can {$action} a fiscal year: it covers the whole company's books.",
        ]);
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
