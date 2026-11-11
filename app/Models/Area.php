<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A geographic / sales area that customers and suppliers can be assigned to,
 * so receivables and payables can be filtered and grouped by area.
 */
#[Fillable(['name'])]
class Area extends Model
{
    use HasFactory;

    /**
     * Longest area name the `areas.name` column holds.
     */
    public const NAME_MAX_LENGTH = 150;

    /**
     * Trim and collapse inner whitespace, so "  Pokhara   Lakeside " is
     * stored and matched as "Pokhara Lakeside".
     */
    public static function normalizeName(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Every area ordered by name, for dropdowns and filters. Deliberately not
     * memoised: this app serves many tenants from one long-lived process
     * (queue workers, Octane-style runtimes, the test runner), and a static
     * or container cache here would leak one tenant's areas into another's.
     *
     * @return Collection<int, Area>
     */
    public static function options(): Collection
    {
        return static::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Lower-cased name => id, for matching area names typed in an import file.
     *
     * @return array<string, int>
     */
    public static function idsByLowerName(): array
    {
        $map = [];

        foreach (static::options() as $area) {
            $map[mb_strtolower($area->name)] = (int) $area->id;
        }

        return $map;
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Supplier, $this>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }
}
