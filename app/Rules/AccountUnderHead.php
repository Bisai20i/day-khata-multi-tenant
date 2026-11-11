<?php

namespace App\Rules;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use App\Support\Branches\BranchAccounts;
use App\Support\Hr\HrAccounts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Server-side twin of the "bank" and "TDS" pickers in the purchase screens:
 * the id must be an account filed anywhere under the named head of the chart
 * (for example Assets for a bank or refund account, Liabilities for TDS) and
 * must not be a customer or supplier ledger account, because posting a
 * settlement into a party ledger corrupts that party's outstanding balance.
 */
class AccountUnderHead implements ValidationRule
{
    public function __construct(private readonly string $head) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $underHead = Account::query()
            ->whereKey($value)
            ->where(fn ($query) => $query
                ->whereHas('group.accountHead', fn ($q) => $q->where('name', $this->head))
                ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->where('name', $this->head)))
            ->exists();

        if (! $underHead) {
            $fail("The selected :attribute must be an account under {$this->head}.");

            return;
        }

        if (Supplier::where('account_id', $value)->exists() || Customer::where('account_id', $value)->exists()) {
            $fail('The selected :attribute cannot be a customer or supplier account.');

            return;
        }

        // The HR control accounts move only through payroll, salary payment and
        // staff advance documents, or the HR sub-ledger stops matching them.
        if (Account::query()->whereKey($value)->whereIn('code', HrAccounts::CONTROL_CODES)->exists()) {
            $fail('The selected :attribute is controlled by the HR module and cannot be chosen here.');

            return;
        }

        // Inter-Branch Clearing and the two branch stock transfer accounts are
        // posted only by the system; a one-sided line in one of them breaks
        // invariant I3 and blocks the year-end close (BranchAccounts).
        if (BranchAccounts::isControlAccount($value)) {
            $fail('The selected :attribute is posted only by the system for inter-branch entries and cannot be chosen here.');
        }
    }
}
