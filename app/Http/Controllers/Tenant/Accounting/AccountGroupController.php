<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\ActivityLog;
use App\Models\JournalVoucherLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        $data = $this->validated($request, $accountGroup);

        $this->assertHeadIsChangeable($accountGroup, (int) $data['account_head_id']);

        $accountGroup->update($data);

        return redirect()->route('tenant.account-groups.index')->with('status', 'Account group updated.');
    }

    public function destroy(AccountGroup $accountGroup): RedirectResponse
    {
        DB::transaction(function () use ($accountGroup): void {
            $locked = AccountGroup::query()->whereKey($accountGroup->getKey())->lockForUpdate()->firstOrFail();

            if ($blocker = $locked->deletionBlocker()) {
                throw ValidationException::withMessages(['account_group' => $blocker]);
            }

            $locked->delete();

            ActivityLog::create([
                'user_id' => auth('web')->id(),
                'action' => 'deleted',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'description' => sprintf('Account group #%s "%s" deleted', $locked->getKey(), $locked->name),
                'changes' => null,
            ]);
        });

        return redirect()->route('tenant.account-groups.index')->with('status', 'Account group deleted.');
    }

    /**
     * A group's head decides whether every account under it is a
     * profit-and-loss account (swept to zero and folded into retained
     * earnings at year-end) or a balance-sheet account (carried forward).
     * Moving a group between heads after its accounts have been posted to
     * therefore silently rewrites history: last year's closing entries swept
     * the old side, this year's would sweep the new one, and the Balance Sheet
     * stops balancing. Blocked once anything is posted; a mis-filed account is
     * moved to a correctly-headed group instead.
     */
    private function assertHeadIsChangeable(AccountGroup $accountGroup, int $newHeadId): void
    {
        if ($accountGroup->account_head_id === $newHeadId) {
            return;
        }

        $hasPostings = JournalVoucherLine::query()
            ->whereHas('account', function ($query) use ($accountGroup) {
                $query->where('account_group_id', $accountGroup->id)
                    ->orWhereHas('subgroup', fn ($subgroup) => $subgroup->where('account_group_id', $accountGroup->id));
            })
            ->exists();

        if ($hasPostings) {
            throw ValidationException::withMessages([
                'account_head_id' => 'Accounts in this group already have postings, so it can no longer be moved to a different head.',
            ]);
        }
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
