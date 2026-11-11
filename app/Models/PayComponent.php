<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One kind of payment on a payslip - Salary, Bonus, Allowance, and whatever a
 * tenant adds (Overtime, Commission). Table-driven on purpose: a new component
 * is a row, not a schema change. Each component maps to the expense account its
 * amounts are debited to when a payroll run posts.
 *
 * `kind` is only 'earning' today; it is the hook for deductions later.
 * The three starter components are is_system: they cannot be deactivated or
 * remapped away from their code, but can be renamed.
 */
#[Fillable(['code', 'name', 'kind', 'expense_account_id', 'is_system', 'is_active', 'sort_order'])]
class PayComponent extends Model
{
    use HasFactory;

    public const KIND_EARNING = 'earning';

    protected $table = 'hr_pay_components';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /**
     * @return HasMany<StaffPayItem, $this>
     */
    public function staffPayItems(): HasMany
    {
        return $this->hasMany(StaffPayItem::class, 'pay_component_id');
    }

    /**
     * @return HasMany<PayrollLineItem, $this>
     */
    public function payrollLineItems(): HasMany
    {
        return $this->hasMany(PayrollLineItem::class, 'pay_component_id');
    }
}
