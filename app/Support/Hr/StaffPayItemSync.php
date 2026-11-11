<?php

namespace App\Support\Hr;

use App\Models\StaffMember;
use App\Models\StaffPayItem;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Brings a staff member's recurring pay items to a desired set as of a date,
 * keeping history: a changed amount closes the old row the day before and opens
 * a new one, a removed component is closed, an unchanged one is untouched.
 * A change dated the SAME day the current row starts updates/removes that row
 * in place (a correction made the day it was entered).
 *
 * History only moves forward. A change to a component dated on or before any
 * later point in that component's history - a row starting after it, or a
 * closed row still running on that date - is refused: it would either rewrite
 * entitlements a posted run may already have used, or open a row overlapping
 * a closed one, which leaves two amounts in force on the same day.
 */
class StaffPayItemSync
{
    /**
     * @param  array<int, array{pay_component_id: int|string, amount: mixed}>  $desired
     *
     * @throws InvalidArgumentException when a changed component's history reaches the change date
     */
    public static function sync(StaffMember $staff, array $desired, string $effectiveFrom): void
    {
        DB::transaction(function () use ($staff, $desired, $effectiveFrom): void {
            // Serialise concurrent saves of the same staff member (a double
            // submit would otherwise open two rows for one component) before
            // the history is read.
            StaffMember::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();

            $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();
            $fromDate = $from->toDateString();
            $closeOn = $from->subDay()->toDateString();

            $wanted = collect($desired)->mapWithKeys(fn (array $item): array => [
                (int) $item['pay_component_id'] => Money::of($item['amount']),
            ]);

            $history = $staff->payItems()->get()->groupBy(fn (StaffPayItem $item): int => (int) $item->pay_component_id);

            $componentIds = $history
                ->filter(fn (Collection $rows): bool => $rows->contains(fn (StaffPayItem $item): bool => $item->effective_to === null))
                ->keys()
                ->merge($wanted->keys())
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values();

            /** @var list<array{rows: Collection<int, StaffPayItem>, current: ?StaffPayItem, target: ?Money, sameDay: bool, componentId: int}> $changes */
            $changes = [];

            foreach ($componentIds as $componentId) {
                $rows = $history->get($componentId, collect());
                $current = static::current($rows);
                $target = $wanted->get($componentId);

                if ($current !== null && $target !== null && Money::of($current->amount)->isEqualTo($target)) {
                    continue;
                }

                $sameDay = $current !== null && $current->effective_from->toDateString() === $fromDate;

                static::assertMovesForward($rows, $sameDay ? $current : null, $fromDate);

                $changes[] = ['rows' => $rows, 'current' => $current, 'target' => $target, 'sameDay' => $sameDay, 'componentId' => $componentId];
            }

            foreach ($changes as $change) {
                if ($change['sameDay']) {
                    $change['target'] === null
                        ? $change['current']->delete()
                        : $change['current']->update(['amount' => $change['target']->toString()]);

                    continue;
                }

                $change['rows']
                    ->filter(fn (StaffPayItem $item): bool => $item->effective_to === null)
                    ->each(fn (StaffPayItem $item) => $item->update(['effective_to' => $closeOn]));

                if ($change['target'] !== null) {
                    static::open($staff, $change['componentId'], $change['target'], $from);
                }
            }
        });
    }

    /**
     * The open-ended row in force now. Legacy data could hold more than one;
     * the latest-starting wins (PayrollDraft::generate() picks the same one).
     *
     * @param  Collection<int, StaffPayItem>  $rows
     */
    private static function current(Collection $rows): ?StaffPayItem
    {
        return $rows
            ->filter(fn (StaffPayItem $item): bool => $item->effective_to === null)
            ->sortBy([
                fn (StaffPayItem $a, StaffPayItem $b): int => strcmp($b->effective_from->toDateString(), $a->effective_from->toDateString()),
                fn (StaffPayItem $a, StaffPayItem $b): int => $b->id <=> $a->id,
            ])
            ->first();
    }

    /**
     * Refuses a change whose date does not come after the component's
     * existing history: any row (other than the same-day row being corrected
     * in place) that starts on or after the date, or a closed row still in
     * force on it.
     *
     * @param  Collection<int, StaffPayItem>  $rows
     *
     * @throws InvalidArgumentException
     */
    private static function assertMovesForward(Collection $rows, ?StaffPayItem $sameDayRow, string $fromDate): void
    {
        $latest = null;

        foreach ($rows as $row) {
            if ($sameDayRow !== null && $row->is($sameDayRow)) {
                continue;
            }

            $start = $row->effective_from->toDateString();
            $end = $row->effective_to?->toDateString();

            foreach ([$start, $end] as $date) {
                if ($date !== null && $date >= $fromDate && ($latest === null || $date > $latest)) {
                    $latest = $date;
                }
            }
        }

        if ($latest !== null) {
            throw new InvalidArgumentException("Pay changes must take effect after the staff member's previous change ({$latest}).");
        }
    }

    private static function open(StaffMember $staff, int $componentId, Money $amount, CarbonImmutable $from): void
    {
        StaffPayItem::create([
            'staff_id' => $staff->id,
            'pay_component_id' => $componentId,
            'amount' => $amount->toString(),
            'effective_from' => $from->toDateString(),
        ]);
    }
}
