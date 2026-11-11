<?php

namespace App\Exports;

use Illuminate\Support\Collection;

/**
 * Lays out the rows of the Debtors / Creditors workbooks, flat or grouped by
 * area, so both exports build them the same way.
 *
 * Flat: the party rows, then a Total row. Grouped (the report's "Group by
 * area" view): per area a heading row, its party rows and a Subtotal row,
 * then the Total row - the same structure the on-screen report shows. The
 * Area column is filled in both layouts.
 */
final class PartyBalanceRows
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{area: string, rows: list<array<string, mixed>>, totals: array<string, string>}>|null  $groups
     * @return Collection<int, array<string, mixed>>
     */
    public static function build(Collection $rows, string $total, ?array $groups): Collection
    {
        $lines = collect();

        if ($groups === null) {
            $lines = $rows->values();
        } else {
            foreach ($groups as $group) {
                $lines->push(self::line("Area: {$group['area']}"));

                foreach ($group['rows'] as $row) {
                    $lines->push($row);
                }

                $lines->push(self::line("Subtotal - {$group['area']}", balance: $group['totals']['balance'] ?? '0.00'));
            }
        }

        return $lines->push(self::line('Total', balance: $total));
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(string $label, ?string $balance = null): array
    {
        return [
            'name' => $label,
            'address' => null,
            'mobile_no' => null,
            'area' => null,
            'balance' => $balance,
        ];
    }
}
