<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountSubgroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/Accounts/Index', [
            'accountGroups' => AccountGroup::query()->orderBy('name')->get(['id', 'name']),
            'accountSubgroups' => AccountSubgroup::query()->orderBy('name')->get(['id', 'account_group_id', 'name']),
            'accounts' => Account::query()->with(['group:id,name', 'subgroup:id,name'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Account::create($this->validated($request));

        return redirect()->route('tenant.accounts.index')->with('status', 'Account added.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $account->update($this->validated($request, $account));

        return redirect()->route('tenant.accounts.index')->with('status', 'Account updated.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        $account->delete();

        return redirect()->route('tenant.accounts.index')->with('status', 'Account deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Account $account = null): array
    {
        return $request->validate([
            'account_group_id' => ['nullable', 'required_without:account_subgroup_id', 'exists:account_groups,id'],
            'account_subgroup_id' => ['nullable', 'required_without:account_group_id', 'exists:account_subgroups,id'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('accounts')->ignore($account)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
