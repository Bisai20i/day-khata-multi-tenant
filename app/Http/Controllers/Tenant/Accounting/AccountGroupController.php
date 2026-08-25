<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountGroupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/AccountGroups/Index', [
            'heads' => AccountHead::query()->orderBy('name')->get(['id', 'name']),
            'groups' => AccountGroup::query()->with('accountHead:id,name')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        AccountGroup::create($this->validated($request));

        return redirect()->route('tenant.account-groups.index')->with('status', 'Account group added.');
    }

    public function update(Request $request, AccountGroup $accountGroup): RedirectResponse
    {
        $accountGroup->update($this->validated($request, $accountGroup));

        return redirect()->route('tenant.account-groups.index')->with('status', 'Account group updated.');
    }

    public function destroy(AccountGroup $accountGroup): RedirectResponse
    {
        $accountGroup->delete();

        return redirect()->route('tenant.account-groups.index')->with('status', 'Account group deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AccountGroup $accountGroup = null): array
    {
        return $request->validate([
            'account_head_id' => ['required', 'exists:account_heads,id'],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('account_groups')->where('account_head_id', $request->account_head_id)->ignore($accountGroup),
            ],
        ]);
    }
}
