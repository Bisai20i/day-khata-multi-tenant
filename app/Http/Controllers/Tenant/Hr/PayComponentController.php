<?php

namespace App\Http\Controllers\Tenant\Hr;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PayComponent;
use App\Rules\AccountUnderHead;
use App\Support\Branches\BranchAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pay components (Salary, Bonus, Allowance and whatever a tenant adds). Each
 * maps to the expense account its amounts are debited to. The starter three
 * are system components: renamable and remappable, but never deactivated, and
 * their codes never change. Nothing is deleted - a component in use is
 * referenced by posted payslips.
 */
class PayComponentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Hr/PayComponents/Index', [
            'components' => PayComponent::query()
                ->with('expenseAccount:id,code,name')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            // Not Stock Received from Branches (Purchase Accounts): only the
            // system posts it, and AccountUnderHead refuses it (BranchAccounts).
            'expenseAccounts' => BranchAccounts::exclude(Account::query())
                ->where(fn ($query) => $query
                    ->whereHas('group.accountHead', fn ($q) => $q->where('name', 'Expenses'))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->where('name', 'Expenses')))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Codes are stored upper-case, so the unique check must see the same
        // form (a case-sensitive collation would otherwise let "salary" through
        // validation and fail on the index).
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('hr_pay_components', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'expense_account_id' => ['required', 'integer', 'exists:accounts,id', new AccountUnderHead('Expenses')],
        ]);

        PayComponent::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'kind' => PayComponent::KIND_EARNING,
            'expense_account_id' => $data['expense_account_id'],
            'is_system' => false,
            'is_active' => true,
            'sort_order' => (int) PayComponent::query()->max('sort_order') + 1,
        ]);

        return back()->with('status', 'Pay component added.');
    }

    public function update(Request $request, PayComponent $component): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expense_account_id' => ['required', 'integer', 'exists:accounts,id', new AccountUnderHead('Expenses')],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($component->is_system && ! $data['is_active']) {
            return back()->withErrors(['is_active' => 'A starter pay component (Salary, Bonus, Allowance) cannot be deactivated.']);
        }

        $component->update($data);

        return back()->with('status', 'Pay component updated.');
    }
}
