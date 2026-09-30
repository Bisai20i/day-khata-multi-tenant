<?php

namespace App\Http\Controllers\Central\Tenants;

use App\Http\Controllers\Controller;
use App\Http\Requests\Central\ReassignTenantOwnerRequest;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\OwnershipTransfer;
use App\Support\Permissions\OwnershipTransferException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Central recovery path for a tenant whose owner has left (plan section 5).
 * Platform-owner only (see routes/central-tenants.php). All the invariants
 * (exactly one owner, active target, locking, tenant-side audit row) live in
 * OwnershipTransfer; this controller only picks the right entry point and
 * writes the central audit entry.
 */
class TenantOwnerController extends Controller
{
    /**
     * Make the chosen active tenant user the owner: a transfer from the
     * current owner, or a promotion when the tenant has none. Every failure
     * is reported as a 422 on `user_id`, decided inside the tenant context but
     * thrown outside it so tenancy is always ended cleanly.
     */
    public function update(ReassignTenantOwnerRequest $request, Tenant $tenant): RedirectResponse
    {
        $targetId = (int) $request->validated('user_id');

        if (! $tenant->databaseExists()) {
            throw ValidationException::withMessages([
                'user_id' => "This tenant's database does not exist yet, so it has no users.",
            ]);
        }

        /** @var array{error: string|null, before: array<string, mixed>|null, after: array<string, mixed>|null} $outcome */
        $outcome = $tenant->run(function () use ($targetId): array {
            $target = User::query()->find($targetId);

            if ($target === null) {
                return ['error' => 'That user does not exist in this tenant.', 'before' => null, 'after' => null];
            }

            if (! $target->isActive()) {
                return ['error' => "{$target->name} is inactive. Reactivate them before making them the owner.", 'before' => null, 'after' => null];
            }

            if ($target->isOwner()) {
                return ['error' => "{$target->name} is already the owner.", 'before' => null, 'after' => null];
            }

            $owner = User::query()->where('is_owner', true)->orderBy('id')->first();
            $before = $owner === null ? null : self::describe($owner);

            try {
                if ($owner === null) {
                    OwnershipTransfer::promote($target);
                } else {
                    OwnershipTransfer::run($owner, $target);
                }
            } catch (OwnershipTransferException $e) {
                return ['error' => $e->getMessage(), 'before' => null, 'after' => null];
            }

            return ['error' => null, 'before' => $before, 'after' => self::describe($target)];
        });

        if ($outcome['error'] !== null) {
            throw ValidationException::withMessages(['user_id' => $outcome['error']]);
        }

        PlatformAdminActivityLog::record('tenant.reassign_owner', $tenant, [
            'before' => $outcome['before'],
            'after' => $outcome['after'],
        ]);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', "{$outcome['after']['name']} is now the owner.");
    }

    /**
     * @return array{id: int|string, name: string, email: string}
     */
    public static function describe(User $user): array
    {
        return ['id' => $user->getKey(), 'name' => (string) $user->name, 'email' => (string) $user->email];
    }
}
