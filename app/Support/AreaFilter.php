<?php

namespace App\Support;

use App\Models\Area;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The "Area" filter shared by the Customers / Suppliers lists, the Debtors /
 * Creditors reports and the receivable / payable ageing reports.
 *
 * Request value `area`: '' / absent = all areas, 'none' = parties with no
 * area, a positive integer = that area's id. Anything else (garbage, zero,
 * negative, an array) is treated as "all areas" rather than an error, so a
 * hand-edited URL never breaks a report.
 *
 * "Group by area" is the separate boolean `group_by_area`.
 */
final class AreaFilter
{
    public const ALL = '';

    public const UNASSIGNED = 'none';

    /**
     * Heading used for rows that have no area.
     */
    public const UNASSIGNED_LABEL = 'Unassigned';

    private function __construct(
        public readonly string $value,
        public readonly ?int $areaId,
    ) {}

    public static function fromRequest(Request $request, string $key = 'area'): self
    {
        $raw = $request->input($key);
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === self::UNASSIGNED) {
            return new self(self::UNASSIGNED, null);
        }

        if ($raw !== '' && ctype_digit($raw) && (int) $raw > 0) {
            // Normalised, so a zero-padded "007" re-selects area 7 in the dropdown.
            return new self((string) (int) $raw, (int) $raw);
        }

        return new self(self::ALL, null);
    }

    /**
     * Whether the request asks for the grouped view.
     */
    public static function groupRequested(Request $request, string $key = 'group_by_area'): bool
    {
        return $request->boolean($key);
    }

    public function isActive(): bool
    {
        return $this->value !== self::ALL;
    }

    public function isUnassigned(): bool
    {
        return $this->value === self::UNASSIGNED;
    }

    /**
     * Human label for report headers: "Pokhara", "Unassigned" or "All areas".
     */
    public function label(): string
    {
        if ($this->isUnassigned()) {
            return self::UNASSIGNED_LABEL;
        }

        if ($this->areaId !== null) {
            return Area::query()->whereKey($this->areaId)->value('name') ?? 'Unknown area';
        }

        return 'All areas';
    }

    /**
     * Narrow a customers / suppliers query to the selected area.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, string $column = 'area_id'): Builder
    {
        if ($this->isUnassigned()) {
            $query->whereNull($column);
        } elseif ($this->areaId !== null) {
            $query->where($column, $this->areaId);
        }

        return $query;
    }

    /**
     * Narrow a document query (a sale, a purchase) to those whose party - the
     * given relation - is in the selected area.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function applyThroughRelation(Builder $query, string $relation): Builder
    {
        if (! $this->isActive()) {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $party) => $this->apply($party));
    }

    /**
     * Group rows by area for the "Group by area" view. Areas are sorted by
     * name (case-insensitively); rows with no area come last as "Unassigned".
     * Row order inside an area is preserved. Subtotals are exact `Money`
     * sums of the given measure keys, so they add up to the report's grand
     * total to the paisa.
     *
     * @param  iterable<int, array<string, mixed>>  $rows  each carries an `area` key (name or null)
     * @param  list<string>  $measures  row keys holding decimal strings to subtotal
     * @return list<array{area: string, rows: list<array<string, mixed>>, totals: array<string, string>}>
     */
    public static function groupRows(iterable $rows, array $measures): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['area'] ?? ''));
            // Sorts after any real area name.
            $key = $name === '' ? "\u{10FFFF}" : mb_strtolower($name);

            $buckets[$key] ??= [
                'area' => $name === '' ? self::UNASSIGNED_LABEL : $name,
                'rows' => [],
                'sums' => array_fill_keys($measures, Money::zero()),
            ];

            $buckets[$key]['rows'][] = $row;

            foreach ($measures as $measure) {
                $buckets[$key]['sums'][$measure] = $buckets[$key]['sums'][$measure]->plus($row[$measure] ?? '0');
            }
        }

        ksort($buckets, SORT_STRING);

        return array_values(array_map(static fn (array $bucket): array => [
            'area' => $bucket['area'],
            'rows' => $bucket['rows'],
            'totals' => array_map(static fn (Money $sum): string => $sum->toString(), $bucket['sums']),
        ], $buckets));
    }
}
