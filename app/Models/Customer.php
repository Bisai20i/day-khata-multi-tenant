<?php

namespace App\Models;

use App\Models\Concerns\HasLedgerAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['account_id', 'name', 'address', 'mobile_no', 'email', 'tpin', 'citizenship', 'is_walk_in'])]
class Customer extends Model
{
    use HasFactory, HasLedgerAccount;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_walk_in' => 'boolean',
        ];
    }

    protected function ledgerAccountSubgroupName(): string
    {
        return 'Sundry Debtors';
    }

    /**
     * The one protected, always-available customer for a sale where nobody
     * bothers to record who the buyer was (audit section 3 "Sales", ported
     * from legacy's `DefaultMainAccountSeeder.php:60-77`). Seeded for every
     * tenant - fresh ones by TenantDatabaseSeeder, existing ones by the
     * 2026_09_14_120000 data migration - so this returns null only if
     * neither has run yet, which callers treat the same as "no default".
     */
    public static function walkIn(): ?self
    {
        return static::where('is_walk_in', true)->first();
    }
}
