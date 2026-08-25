<?php

namespace App\Models\Concerns;

use App\Models\Account;
use App\Models\AccountSubgroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gives a master-data model (Customer, Supplier) its own ledger Account,
 * auto-created on first save and kept in sync on update. Mirrors the
 * legacy app's "creating a customer/supplier also writes a mainaccount
 * row" behavior (see CustomerController::store/update), normalized onto
 * a real foreign key instead of a matched-by-string denormalized copy.
 */
trait HasLedgerAccount
{
    protected static function bootHasLedgerAccount(): void
    {
        static::creating(function (Model $model): void {
            if (! $model->account_id) {
                $model->account_id = $model->makeLedgerAccount()->id;
            }
        });

        static::updated(function (Model $model): void {
            if ($model->wasChanged(['name', 'mobile_no', 'address'])) {
                $model->account?->update([
                    'name' => $model->name,
                    'phone' => $model->mobile_no,
                    'address' => $model->address,
                ]);
            }
        });
    }

    /**
     * Name of the account subgroup new records of this model are filed
     * under (e.g. "Sundry Debtors" for customers, "Sundry Creditors" for
     * suppliers). Must already exist - seeded by ChartOfAccountsSeeder.
     */
    abstract protected function ledgerAccountSubgroupName(): string;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected function makeLedgerAccount(): Account
    {
        $subgroup = AccountSubgroup::where('name', $this->ledgerAccountSubgroupName())->firstOrFail();

        return $subgroup->accounts()->create([
            'name' => $this->name,
            'phone' => $this->mobile_no,
            'address' => $this->address,
        ]);
    }
}
