<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Branch context and access scope (plan 12, section 5.7, invariant I6)
|--------------------------------------------------------------------------
|
| postingBranchId() fallback order, the allowed set, and BranchAccessScope on
| journal vouchers and lines: active only for restricted users, lifted by
| BranchContext::unrestricted().
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    auth()->forgetGuards();
    tenancy()->end();
});

function provisionBranchContextTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function branchContextUser(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

/**
 * A user limited to $branches, with an optional default branch. Read back
 * from the database so all_branches is a real loaded value.
 *
 * @param  list<Branch>  $branches
 */
function branchContextRestrictedUser(array $branches, ?Branch $default = null): User
{
    $user = branchContextUser();
    $user->forceFill(['all_branches' => false, 'default_branch_id' => $default?->id])->save();
    $user->branches()->attach(array_map(fn (Branch $branch): int => $branch->id, $branches));

    return User::query()->findOrFail($user->id);
}

/**
 * Two vouchers, one per branch, posted by an unrestricted user.
 *
 * @return array{main: JournalVoucher, other: JournalVoucher}
 */
function branchContextPostOnePerBranch(Branch $other): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $poster = branchContextUser();
    $cash = Account::where('code', 'AS1')->firstOrFail()->id;
    $sales = Account::where('code', 'INI20')->firstOrFail()->id;

    $post = fn (int $branchId) => JournalVoucher::post(
        ['date' => '2026-06-01', 'narration' => 'Sale', 'branch_id' => $branchId],
        [
            ['account_id' => $cash, 'debit' => 10, 'credit' => 0],
            ['account_id' => $sales, 'debit' => 0, 'credit' => 10],
        ],
        $poster,
    );

    return ['main' => $post(Branch::main()->id), 'other' => $post($other->id)];
}

test('with no user at all the posting branch is Main and nothing is restricted', function () {
    $tenant = provisionBranchContextTenant('br-ctx-console.tenant-test');

    $tenant->run(function () {
        expect(BranchContext::user())->toBeNull()
            ->and(BranchContext::postingBranchId())->toBe(Branch::main()->id)
            ->and(BranchContext::allowedBranchIds())->toBeNull()
            ->and(BranchContext::scopeBranchIds())->toBeNull();
    });

    $tenant->delete();
});

test('an unrestricted user posts into their default branch, else Main when it is NULL or inactive', function () {
    $tenant = provisionBranchContextTenant('br-ctx-unrestricted.tenant-test');

    $tenant->run(function () {
        $mainId = Branch::main()->id;
        $branch = Branch::factory()->create();
        $closed = Branch::factory()->inactive()->create();

        $noDefault = branchContextUser();
        $noDefault->forceFill(['default_branch_id' => null])->save();

        $withDefault = branchContextUser();
        $withDefault->forceFill(['default_branch_id' => $branch->id])->save();

        $inactiveDefault = branchContextUser();
        $inactiveDefault->forceFill(['default_branch_id' => $closed->id])->save();

        expect(BranchContext::postingBranchId($noDefault->fresh()))->toBe($mainId)
            ->and(BranchContext::postingBranchId($withDefault->fresh()))->toBe($branch->id)
            ->and(BranchContext::postingBranchId($inactiveDefault->fresh()))->toBe($mainId)
            ->and(BranchContext::allowedBranchIds($withDefault->fresh()))->toBeNull();
    });

    $tenant->delete();
});

test('a restricted user falls back from default to Main to their first allowed branch', function () {
    $tenant = provisionBranchContextTenant('br-ctx-restricted.tenant-test');

    $tenant->run(function () {
        $main = Branch::main();
        $first = Branch::factory()->create(['sort_order' => 1]);
        $second = Branch::factory()->create(['sort_order' => 2]);

        $withDefault = branchContextRestrictedUser([$first, $second], $second);
        $nullDefaultWithMain = branchContextRestrictedUser([$main, $second]);
        $nullDefaultWithoutMain = branchContextRestrictedUser([$second, $first]);
        $forbiddenDefault = branchContextRestrictedUser([$second], $main);
        $nowhere = branchContextRestrictedUser([]);

        expect(BranchContext::postingBranchId($withDefault))->toBe($second->id)
            ->and(BranchContext::postingBranchId($nullDefaultWithMain))->toBe($main->id)
            ->and(BranchContext::postingBranchId($nullDefaultWithoutMain))->toBe($first->id)
            ->and(BranchContext::postingBranchId($forbiddenDefault))->toBe($second->id)
            ->and(BranchContext::allowedBranchIds($nullDefaultWithoutMain))->toBe([$first->id, $second->id])
            ->and(fn () => BranchContext::postingBranchId($nowhere))->toThrow(BranchRuleViolation::class);
    });

    $tenant->delete();
});

test('the UI selection wins only when the context user may use it', function () {
    $tenant = provisionBranchContextTenant('br-ctx-selection.tenant-test');

    $tenant->run(function () {
        $first = Branch::factory()->create(['sort_order' => 1]);
        $second = Branch::factory()->create(['sort_order' => 2]);
        $user = branchContextRestrictedUser([$first, $second], $first);
        auth()->setUser($user);

        BranchContext::setActiveBranchId($second->id);

        expect(BranchContext::activeBranchId())->toBe($second->id)
            ->and(BranchContext::postingBranchId())->toBe($second->id);

        // A forged selection outside the allowed set is ignored.
        BranchContext::setActiveBranchId(Branch::main()->id);

        expect(BranchContext::activeBranchId())->toBeNull()
            ->and(BranchContext::postingBranchId())->toBe($first->id);
    });

    $tenant->delete();
});

test('a UI selection made under one tenant is ignored under the next', function () {
    $first = provisionBranchContextTenant('br-ctx-stale-a.tenant-test');
    $second = provisionBranchContextTenant('br-ctx-stale-b.tenant-test');

    $selectedId = $first->run(function () {
        $branch = Branch::factory()->create();
        BranchContext::setActiveBranchId($branch->id);

        expect(BranchContext::activeBranchId())->toBe($branch->id);

        return $branch->id;
    });

    $second->run(function () use ($selectedId) {
        // Same id, active, in the second tenant: only the tenant switch
        // itself can make the stale selection go away.
        $lookalike = Branch::factory()->create();

        expect($lookalike->id)->toBe($selectedId)
            ->and(BranchContext::activeBranchId())->toBeNull()
            ->and(BranchContext::postingBranchId())->toBe(Branch::main()->id);
    });

    $first->delete();
    $second->delete();
});

test('a valid default branch never needs the Main lookup', function () {
    $tenant = provisionBranchContextTenant('br-ctx-lazy-main.tenant-test');

    $tenant->run(function () {
        $branch = Branch::factory()->create();
        $user = branchContextUser();
        $user->forceFill(['default_branch_id' => $branch->id])->save();

        // A half-provisioned tenant with no Main (a raw write, bypassing the
        // model guard on purpose).
        DB::table('branches')->where('is_main', true)->update(['is_main' => false]);
        BranchContext::flush();

        expect(BranchContext::mainBranchIdOrNull())->toBeNull()
            ->and(BranchContext::postingBranchId($user->fresh()))->toBe($branch->id);
    });

    $tenant->delete();
});

test('the owner is never restricted, even with all_branches switched off', function () {
    $tenant = provisionBranchContextTenant('br-ctx-owner.tenant-test');

    $tenant->run(function () {
        $owner = User::factory()->create();
        $owner->forceFill(['all_branches' => false])->save();

        expect($owner->fresh()->isOwner())->toBeTrue()
            ->and(BranchContext::allowedBranchIds($owner->fresh()))->toBeNull();
    });

    $tenant->delete();
});

test('a restricted user cannot read another branch\'s vouchers or lines, but unrestricted() can', function () {
    $tenant = provisionBranchContextTenant('br-ctx-scope.tenant-test');

    $tenant->run(function () {
        $other = Branch::factory()->create();
        $vouchers = branchContextPostOnePerBranch($other);
        $user = branchContextRestrictedUser([$other], $other);

        BranchContext::restrictedTo($user, function () use ($vouchers) {
            expect(JournalVoucher::query()->pluck('id')->all())->toBe([$vouchers['other']->id])
                ->and(JournalVoucher::query()->find($vouchers['main']->id))->toBeNull()
                ->and(JournalVoucherLine::query()->pluck('journal_voucher_id')->unique()->values()->all())->toBe([$vouchers['other']->id]);

            $everything = BranchContext::unrestricted(fn () => JournalVoucher::query()->orderBy('id')->pluck('id')->all());

            expect($everything)->toBe([$vouchers['main']->id, $vouchers['other']->id])
                // The scope is back on after unrestricted() returns.
                ->and(JournalVoucher::query()->count())->toBe(1);
        });

        // The same holds for the logged-in user, without restrictedTo().
        auth()->setUser($user);

        expect(JournalVoucher::query()->pluck('id')->all())->toBe([$vouchers['other']->id]);

        auth()->forgetGuards();

        // With nobody logged in nothing is hidden.
        expect(JournalVoucher::query()->count())->toBe(2);
    });

    $tenant->delete();
});

test('a restricted user sees a cross-branch voucher that touches their branch, but only their lines', function () {
    $tenant = provisionBranchContextTenant('br-ctx-cross.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        Account::where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->firstOrFail();
        $other = Branch::factory()->create();

        // Main collects cash for the other branch's sale.
        $voucher = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Collected for other branch'],
            [
                ['account_id' => Account::where('code', 'AS1')->firstOrFail()->id, 'debit' => 25, 'credit' => 0],
                ['account_id' => Account::where('code', 'INI20')->firstOrFail()->id, 'debit' => 0, 'credit' => 25, 'branch_id' => $other->id],
            ],
            branchContextUser(),
            autoBalance: true,
        );

        $user = branchContextRestrictedUser([$other], $other);

        BranchContext::restrictedTo($user, function () use ($voucher, $other) {
            $visible = JournalVoucher::query()->findOrFail($voucher->id);

            expect($visible->branch_id)->toBe(Branch::main()->id)
                ->and($visible->lines()->pluck('branch_id')->unique()->values()->all())->toBe([$other->id])
                ->and($visible->lines()->count())->toBe(2);
        });
    });

    $tenant->delete();
});

test('a restricted user cannot post into a branch outside their set, and lands in their own by default', function () {
    $tenant = provisionBranchContextTenant('br-ctx-post.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $other = Branch::factory()->create();
        $user = branchContextRestrictedUser([$other], $other);
        $lines = [
            ['account_id' => Account::where('code', 'AS1')->firstOrFail()->id, 'debit' => 5, 'credit' => 0],
            ['account_id' => Account::where('code', 'INI20')->firstOrFail()->id, 'debit' => 0, 'credit' => 5],
        ];

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Into Main', 'branch_id' => Branch::main()->id],
            $lines,
            $user,
        ))->toThrow(AuthorizationException::class);

        $voucher = JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Own branch'], $lines, $user);

        expect($voucher->branch_id)->toBe($other->id);
    });

    $tenant->delete();
});
