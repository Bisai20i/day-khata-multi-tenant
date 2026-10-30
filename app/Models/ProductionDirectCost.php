<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\DirectCostType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A direct production cost (labour, power, ...) charged to a production
 * entry's work in process: Dr WIP / Cr `credit_account_id`. Written and
 * reversed only by ProductionEntry::addDirectCost()/cancelDirectCost();
 * `cancelled_at` set means its voucher has been reversed and its WIP row
 * cancelled.
 */
#[Fillable([
    'production_entry_id',
    'date',
    'cost_type',
    'description',
    'amount',
    'credit_account_id',
    'journal_voucher_id',
    'cancelled_by',
    'cancelled_at',
    'cancel_reason',
])]
class ProductionDirectCost extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'cost_type' => DirectCostType::class,
            'amount' => Decimal::class.':2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductionEntry, $this>
     */
    public function productionEntry(): BelongsTo
    {
        return $this->belongsTo(ProductionEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The accounts a direct cost may be credited to (MC6): money accounts
     * (cash-in-hand, bank and other Current Assets) and liability (payable)
     * accounts. A direct cost is paid in cash or through the bank, or left
     * owing; crediting an income or inventory account would invent or move
     * value that was never spent, and crediting a debtor would misstate what
     * a customer owes. The model enforces this itself, so every caller is
     * held to it, not only the form, and the form's picker reads this same
     * query.
     *
     * There is no bank flag on Account (bank accounts are ordinary Current
     * Assets accounts, see FixedAssetController), so "money account" means
     * a Current Assets account outside the Stock and Sundry Debtors
     * subgroups rather than a match on the word "bank" in its name.
     *
     * @return Builder<Account>
     */
    public static function creditAccountQuery(): Builder
    {
        $named = fn (string $name) => fn ($query) => $query->where('name', $name);
        $excludedSubgroups = ['Stock', 'Sundry Debtors'];

        return Account::query()->where(function (Builder $query) use ($named, $excludedSubgroups) {
            $query
                ->where(fn (Builder $direct) => $direct
                    ->whereNull('account_subgroup_id')
                    ->whereHas('group', $named('Current Assets')))
                ->orWhereHas('subgroup', fn ($subgroup) => $subgroup
                    ->whereNotIn('name', $excludedSubgroups)
                    ->whereHas('accountGroup', $named('Current Assets')))
                ->orWhereHas('group.accountHead', $named('Liabilities'))
                ->orWhereHas('subgroup.accountGroup.accountHead', $named('Liabilities'));
        });
    }
}
