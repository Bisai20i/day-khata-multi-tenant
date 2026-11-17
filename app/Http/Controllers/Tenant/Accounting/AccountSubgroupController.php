<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountGroup;
use App\Models\AccountSubgroup;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountSubgroupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/AccountSubgroups/Index', [
            'groups' => AccountGroup::query()->with('accountHead:id,name')->orderBy('name')->get(['id', 'account_head_id', 'name']),
            'subgroups' => AccountSubgroup::query()->with(['accountGroup.accountHead:id,name', 'accountGroup:id,account_head_id,name'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        AccountSubgroup::create($this->validated($request));

        return redirect()->route('tenant.account-subgroups.index')->with('status', 'Account subgroup added.');
    }

    public function update(Request $request, AccountSubgroup $accountSubgroup): RedirectResponse
    {
        $accountSubgroup->update($this->validated($request, $accountSubgroup));

        return redirect()->route('tenant.account-subgroups.index')->with('status', 'Account subgroup updated.');
    }

    public function destroy(AccountSubgroup $accountSubgroup): RedirectResponse
    {
        DB::transaction(function () use ($accountSubgroup): void {
            $locked = AccountSubgroup::query()->whereKey($accountSubgroup->getKey())->lockForUpdate()->firstOrFail();

            if ($blocker = $locked->deletionBlocker()) {
                throw ValidationException::withMessages(['account_subgroup' => $blocker]);
            }

            $locked->delete();

            ActivityLog::create([
                'user_id' => auth('web')->id(),
                'action' => 'deleted',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'description' => sprintf('Account subgroup #%s "%s" deleted', $locked->getKey(), $locked->name),
                'changes' => null,
            ]);
        });

        return redirect()->route('tenant.account-subgroups.index')->with('status', 'Account subgroup deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AccountSubgroup $accountSubgroup = null): array
    {
        return $request->validate([
            'account_group_id' => ['required', 'exists:account_groups,id'],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('account_subgroups')->where('account_group_id', $request->account_group_id)->ignore($accountSubgroup),
            ],
        ]);
    }
}
