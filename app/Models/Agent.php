<?php

namespace App\Models;

use App\Models\Concerns\HasLedgerAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A sales agent who may be assigned to a Sale for commission tracking (see
 * Sale::post()). Master-data model like Customer/Supplier - gets its own
 * ledger account via HasLedgerAccount, filed under the "Sales Agents"
 * subgroup (ChartOfAccountsSeeder), credited whenever a sale posts a
 * commission owed to this agent.
 *
 * commission_rate is a UI convenience only (a default % this agent
 * normally earns, used to pre-fill Sales/Create.vue's commission field) -
 * it is never enforced or read by Sale::post() itself.
 */
#[Fillable(['account_id', 'name', 'mobile_no', 'address', 'commission_rate', 'is_active'])]
class Agent extends Model
{
    use HasFactory, HasLedgerAccount;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected function ledgerAccountSubgroupName(): string
    {
        return 'Sales Agents';
    }
}
