<?php

namespace App\Exports;

use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Lays out the rows of the Debtors / Creditors workbooks, flat or grouped by
 * area, so both exports build them the same way, and turns each line into its
 * sheet cells (absolute balance plus a Dr/Cr side).
 *
 * Lines carry their `balance` signed in the party's normal side; the exports
 * convert to absolute + side at map() time via cells().
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
     * Heading rows of both workbooks: the title, the fiscal year, the branch
     * filter's label and the area filter (each only when known), a blank
     * spacer, then the column headings. The columns themselves never move.
     *
     * @return list<list<string>>
     */
    public static function headingRows(string $title, string $partyLabel, ?string $fiscalYearName, ?string $branchLabel, ?string $areaLabel): array
    {
        $rows = [[$title]];

        if ($fiscalYearName !== null) {
            $rows[] = ["Fiscal year: {$fiscalYearName}"];
        }

        if ($branchLabel !== null) {
            $rows[] = [$branchLabel];
        }

        if ($areaLabel !== null) {
            $rows[] = ["Area: {$areaLabel}"];
        }

        $rows[] = [''];
        $rows[] = [$partyLabel, 'Address', 'Mobile', 'Area', 'Balance', 'Dr/Cr'];

        return $rows;
    }

    /**
     * Bold title and bold column-heading row for a sheet laid out by headingRows().
     *
     * @param  list<list<string>>  $headingRows
     * @return array<int, array<string, mixed>>
     */
    public static function headingStyles(array $headingRows): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            count($headingRows) => ['font' => ['bold' => true]],
        ];
    }

    /**
     * The side ("Dr" / "Cr") a signed party balance actually sits on. The
     * report signs balances in the party's normal side - debtors positive =
     * Dr, creditors positive = Cr - so a negative amount is the opposite side
     * (an overpaid party). Zero has no side.
     */
    public static function side(string $signed, bool $creditNormal): string
    {
        $money = Money::of($signed);

        if ($money->isZero()) {
            return '';
        }

        $onNormalSide = ! $money->isNegative();

        return $onNormalSide === $creditNormal ? 'Cr' : 'Dr';
    }

    /**
     * The signed balance without its sign, as a 2-decimal string.
     */
    public static function absolute(string $signed): string
    {
        return Money::of($signed)->abs()->toString();
    }

    /**
     * The sheet cells for one built line: name, address, mobile, area, the
     * absolute balance (the one numeric cell - `Money::toFloat()` is the
     * Excel boundary) and its Dr/Cr side. Heading lines of the grouped layout
     * carry no balance, so both of those cells stay empty.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string|float|null>
     */
    public static function cells(array $row, bool $creditNormal): array
    {
        $balance = $row['balance'] ?? null;

        return [
            $row['name'] ?? '',
            $row['address'] ?? '',
            $row['mobile_no'] ?? '',
            $row['area'] ?? '',
            $balance === null ? null : Money::of(self::absolute($balance))->toFloat(),
            $balance === null ? '' : self::side($balance, $creditNormal),
        ];
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
