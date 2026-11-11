<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person on the payroll (HR module). Deliberately NOT a User: most staff
 * never log in, and a User has no pay data. `user_id` is an optional link for
 * the ones who do. Named StaffMember rather than Employee because "employees"
 * already means login users in routes/tenant-employees.php.
 *
 * The staff member has no ledger account of their own. What they are owed or
 * owe lives in the HR tables (payroll lines, payments, advances) against the
 * Salary Payable / Staff Advances Receivable control accounts - see
 * App\Support\Hr\StaffBalances and HrAccounts for why.
 *
 * `branch_id` is the staff member's home branch (NOT NULL, foreign key to
 * branches; plan 12, section 5.4): their payslip's expense, Salary Payable
 * and advance-recovery lines post in it, and a Staff Advances Receivable
 * debit lands in it. StaffController chooses it on create (default: the
 * actor's posting branch) and refuses to move a staff member while they
 * still owe an advance, so the receivable is always recovered in the branch
 * that carries it. It must stay in #[Fillable]: an unlisted column would be
 * dropped silently and the staff member would land in the column default.
 * No access scope (BelongsToBranch) yet: staff are read by system code
 * (balances, payroll locks) that must see every branch.
 */
#[Fillable([
    'staff_code', 'name', 'mobile_no', 'address', 'designation', 'department', 'join_date', 'end_date',
    'pan_no', 'bank_name', 'bank_account_no', 'user_id', 'is_active', 'branch_id',
])]
class StaffMember extends Model
{
    use HasFactory;

    protected $table = 'hr_staff';

    protected static function booted(): void
    {
        static::creating(function (self $staff): void {
            if (blank($staff->staff_code)) {
                $staff->staff_code = static::nextStaffCode();
            }
        });
    }

    /**
     * STF-0001, STF-0002, ... from the highest id so far, skipping any code
     * already taken (a hand-typed code such as STF-0005 must not make the next
     * generated one collide). The unique index is the real guard if two are
     * created in the same instant.
     */
    public static function nextStaffCode(): string
    {
        $number = (int) static::query()->max('id') + 1;

        do {
            $code = 'STF-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            $number++;
        } while (static::query()->where('staff_code', $code)->exists());

        return $code;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'join_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'branch_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<StaffMember>  $query
     * @return Builder<StaffMember>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<StaffPayItem, $this>
     */
    public function payItems(): HasMany
    {
        return $this->hasMany(StaffPayItem::class, 'staff_id');
    }

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'staff_id');
    }

    /**
     * @return HasMany<StaffAdvance, $this>
     */
    public function advances(): HasMany
    {
        return $this->hasMany(StaffAdvance::class, 'staff_id');
    }
}
