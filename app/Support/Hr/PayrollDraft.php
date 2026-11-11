<?php

namespace App\Support\Hr;

use App\Models\Branch;
use App\Models\PayComponent;
use App\Models\PayrollLine;
use App\Models\PayrollLineItem;
use App\Models\PayrollRun;
use App\Models\StaffMember;
use App\Models\StaffPayItem;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Branches\DocumentBranch;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates and edits DRAFT payroll runs. Nothing here touches the ledger; that
 * is PayrollPoster's job once the draft is reviewed.
 */
class PayrollDraft
{
    /**
     * Opens a draft for a period, prefilled from each eligible staff member's
     * recurring pay items in force on the period's last day. Eligible = active,
     * employed at some point in the period, in the run's branch, with at least
     * one active pay item, and not already in another live run whose period
     * overlaps this one.
     *
     * Branch (plan 12, BR09): the run's branch is the one named in the header,
     * else the actor's posting branch (Main for a single-branch company, so a
     * header without a branch still means Main). A named branch must exist,
     * be active and be one the actor may use; DocumentBranch raises every
     * refusal as InvalidArgumentException so the form shows it. The branch is
     * always written explicitly on the run and its lines rather than left to
     * a column default.
     *
     * `all_branches` makes a company-wide run: staff of every active branch
     * the actor may use, each payslip in its staff member's own branch, with
     * the run's branch as the voucher's origin.
     *
     * @param  array{period_label: string, period_start: string, period_end: string, date: string, narration?: string|null, branch_id?: int|string|null, all_branches?: bool|null}  $header
     *
     * @throws InvalidArgumentException when nobody is eligible or the branch cannot be used
     */
    public static function generate(array $header, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($header, $actor): PayrollRun {
            $alreadyPaidThisPeriod = static::staffInLiveRun($header['period_start'], $header['period_end'], null);
            $branchId = DocumentBranch::forDocument($actor, $header['branch_id'] ?? null)->branchId;
            $allBranches = (bool) ($header['all_branches'] ?? false);

            $staff = StaffMember::query()
                ->active()
                ->when(
                    $allBranches,
                    fn ($query) => $query->whereIn('branch_id', static::usableBranchIds($actor)),
                    fn ($query) => $query->where('branch_id', $branchId),
                )
                ->where(fn ($q) => $q->whereNull('join_date')->orWhereDate('join_date', '<=', $header['period_end']))
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $header['period_start']))
                ->whereNotIn('id', $alreadyPaidThisPeriod)
                ->orderBy('name')
                ->get();

            $activeComponents = PayComponent::query()->where('is_active', true)->pluck('id');

            // One query for every staff member's pay rows, grouped per person.
            $itemsByStaff = StaffPayItem::query()
                ->whereIn('staff_id', $staff->modelKeys())
                ->whereIn('pay_component_id', $activeComponents)
                ->inForceOn($header['period_end'])
                ->orderBy('pay_component_id')
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->get()
                ->groupBy('staff_id');

            $rows = [];

            foreach ($staff as $member) {
                // One row per component: should legacy data hold two rows in
                // force for one component, the latest-starting one wins rather
                // than failing the whole run on "same pay component twice".
                $items = ($itemsByStaff[$member->id] ?? collect())
                    ->unique('pay_component_id')
                    ->values();

                if ($items->isNotEmpty()) {
                    $rows[] = [
                        'staff_id' => $member->id,
                        'advance_recovery' => '0.00',
                        'note' => null,
                        'items' => $items->map(fn (StaffPayItem $item): array => [
                            'pay_component_id' => $item->pay_component_id,
                            'amount' => $item->amount,
                            'note' => null,
                        ])->all(),
                    ];
                }
            }

            if ($rows === []) {
                throw new InvalidArgumentException('No eligible staff for this period. Staff need an active pay item, and must not already be in another live run covering any of these dates.');
            }

            $run = PayrollRun::create([
                'period_label' => $header['period_label'],
                'period_start' => $header['period_start'],
                'period_end' => $header['period_end'],
                'date' => $header['date'],
                'narration' => $header['narration'] ?? null,
                'branch_id' => $branchId,
                'status' => PayrollRun::STATUS_DRAFT,
                'created_by' => $actor->id,
            ]);

            static::writeLines($run, $rows, $actor);

            return $run;
        });
    }

    /**
     * Replaces a draft's header and every line with the submitted ones. Lines
     * may name staff of any branch (each payslip takes its staff member's
     * home branch); with $actor given, each of those branches must be active
     * and usable by the actor, so the form shows the problem now rather than
     * at posting.
     *
     * @param  array{period_label: string, period_start: string, period_end: string, date: string, narration?: string|null, lines: array<int, array{staff_id: int|string, advance_recovery?: mixed, note?: string|null, items: array<int, array{pay_component_id: int|string, amount: mixed, note?: string|null}>}>}  $data
     *
     * @throws InvalidArgumentException
     */
    public static function replace(PayrollRun $run, array $data, ?User $actor = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $data, $actor): PayrollRun {
            /** @var PayrollRun $locked */
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new InvalidArgumentException('Only a draft payroll run can be edited.');
            }

            $locked->update([
                'period_label' => $data['period_label'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'date' => $data['date'],
                'narration' => $data['narration'] ?? null,
            ]);

            $inOtherRuns = static::staffInLiveRun($data['period_start'], $data['period_end'], $locked->id);

            foreach ($data['lines'] as $line) {
                if (in_array((int) $line['staff_id'], $inOtherRuns, true)) {
                    $name = StaffMember::query()->whereKey($line['staff_id'])->value('name');

                    throw new InvalidArgumentException("{$name} is already in another payroll run whose period overlaps this one.");
                }
            }

            static::deleteLines($locked);
            static::writeLines($locked, $data['lines'], $actor);

            return $locked->refresh();
        });
    }

    /**
     * Throws the whole draft away (lines, items, header). Drafts have no
     * voucher, so there is nothing to reverse.
     */
    public static function discard(PayrollRun $run): void
    {
        DB::transaction(function () use ($run): void {
            /** @var PayrollRun $locked */
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new InvalidArgumentException('Only a draft payroll run can be discarded; cancel a posted one instead.');
            }

            static::deleteLines($locked);
            $locked->delete();
        });
    }

    /**
     * Staff ids that already sit in a posted or draft run whose period
     * overlaps this one by at least a day (a cancelled run frees them),
     * optionally ignoring one run. Overlap, not an exact match: "1-30 June"
     * and "15 June-14 July" would otherwise pay the same days twice.
     * PayrollPoster::post() repeats the check under the staff locks.
     *
     * @return list<int>
     */
    private static function staffInLiveRun(string $periodStart, string $periodEnd, ?int $exceptRunId): array
    {
        return PayrollLine::query()
            ->whereHas('run', fn ($run) => $run
                ->whereIn('status', [PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_POSTED])
                ->whereDate('period_start', '<=', $periodEnd)
                ->whereDate('period_end', '>=', $periodStart)
                ->when($exceptRunId, fn ($q) => $q->whereKeyNot($exceptRunId)))
            ->pluck('staff_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Active branches $actor may use: every active branch for an unrestricted
     * actor, else their allowed set (which only ever holds active branches).
     *
     * @return list<int>
     */
    private static function usableBranchIds(User $actor): array
    {
        $allowed = BranchContext::allowedBranchIds($actor);

        if ($allowed !== null) {
            return $allowed;
        }

        return Branch::query()->where('is_active', true)->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    private static function deleteLines(PayrollRun $run): void
    {
        $lineIds = $run->lines()->pluck('id');

        PayrollLineItem::query()->whereIn('payroll_line_id', $lineIds)->delete();
        $run->lines()->delete();
    }

    /**
     * Writes lines and items, validating each, and stores the run's totals.
     * Each payslip is stamped with its staff member's current home branch.
     *
     * @param  array<int, array{staff_id: int|string, advance_recovery?: mixed, note?: string|null, items: array<int, array{pay_component_id: int|string, amount: mixed, note?: string|null}>}>  $lines
     */
    private static function writeLines(PayrollRun $run, array $lines, ?User $actor = null): void
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A payroll run needs at least one staff member.');
        }

        $totalGross = Money::zero();
        $totalRecovery = Money::zero();
        $seenStaff = [];
        $checkedBranches = [];

        foreach ($lines as $line) {
            $staffId = (int) $line['staff_id'];

            if (isset($seenStaff[$staffId])) {
                throw new InvalidArgumentException('A staff member can appear only once in a payroll run.');
            }

            $seenStaff[$staffId] = true;

            $staff = StaffMember::query()->findOrFail($staffId);

            if (! $staff->is_active) {
                throw new InvalidArgumentException("{$staff->name} is inactive and cannot be paid in a new run.");
            }

            $staffBranchId = (int) $staff->branch_id;

            // Once per branch: active and usable by the actor (I6). Without
            // an actor, JournalVoucher::post() still checks at posting.
            if ($actor !== null && ! isset($checkedBranches[$staffBranchId])) {
                DocumentBranch::forDocument($actor, $staffBranchId);
                $checkedBranches[$staffBranchId] = true;
            }

            $items = $line['items'] ?? [];

            if ($items === []) {
                throw new InvalidArgumentException("{$staff->name} has no pay items in this run.");
            }

            $gross = Money::zero();
            $componentsSeen = [];

            foreach ($items as $item) {
                $amount = Money::of($item['amount']);

                if (! $amount->isPositive()) {
                    throw new InvalidArgumentException("Each pay amount for {$staff->name} must be greater than zero.");
                }

                $componentId = (int) $item['pay_component_id'];

                if (isset($componentsSeen[$componentId])) {
                    throw new InvalidArgumentException("{$staff->name} has the same pay component twice; combine them into one amount.");
                }

                $componentsSeen[$componentId] = true;
                $gross = $gross->plus($amount);
            }

            $recovery = Money::ofNullable($line['advance_recovery'] ?? null) ?? Money::zero();

            if ($recovery->isNegative()) {
                throw new InvalidArgumentException("The advance recovery for {$staff->name} cannot be negative.");
            }

            if ($recovery->isGreaterThan($gross)) {
                throw new InvalidArgumentException("The advance recovery for {$staff->name} cannot exceed their gross pay.");
            }

            if ($recovery->isPositive() && $recovery->isGreaterThan(StaffBalances::advance($staffId))) {
                throw new InvalidArgumentException("The advance recovery for {$staff->name} is more than their outstanding advance.");
            }

            $payslip = $run->lines()->create([
                'staff_id' => $staffId,
                // The payslip follows the staff member's home branch.
                'branch_id' => $staffBranchId,
                'gross' => $gross->toString(),
                'advance_recovery' => $recovery->toString(),
                'net' => $gross->minus($recovery)->toString(),
                'note' => $line['note'] ?? null,
            ]);

            foreach ($items as $item) {
                $payslip->items()->create([
                    'pay_component_id' => (int) $item['pay_component_id'],
                    'amount' => Money::of($item['amount'])->toString(),
                    'note' => $item['note'] ?? null,
                ]);
            }

            $totalGross = $totalGross->plus($gross);
            $totalRecovery = $totalRecovery->plus($recovery);
        }

        $run->update([
            'total_gross' => $totalGross->toString(),
            'total_recovery' => $totalRecovery->toString(),
            'total_net' => $totalGross->minus($totalRecovery)->toString(),
        ]);
    }
}
