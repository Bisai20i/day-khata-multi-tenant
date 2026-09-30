<?php

namespace App\Support\Permissions;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only code that moves users.is_owner (plans/roles-permissions-entitlements.md
 * section 5, "Owner protection"). Every tenant must have exactly one owner at
 * all times: the owner is the one account nobody can lock out, so zero owners
 * means a company nobody can administer and two means two people who can each
 * silently undo the other.
 *
 * Both entry points run in one transaction, re-read the affected rows under
 * lockForUpdate (the instances handed in may be stale: a second tab, a
 * concurrent central reassignment), re-check every precondition against the
 * locked rows, and finish by counting the owners with a locking read. A
 * locking read matters on MySQL/InnoDB: a plain SELECT under REPEATABLE READ
 * would count from the transaction's snapshot and could miss an owner another
 * transaction committed meanwhile, whereas FOR UPDATE reads the latest
 * committed rows. Any count other than one throws, which rolls the whole
 * change back. (Tests run on SQLite, where lockForUpdate() is a no-op, so the
 * locking is verified by review, not by the suite.)
 *
 * Roles are never touched: the new owner keeps whatever role they had (it
 * stops mattering while they own the company) and the old owner keeps theirs
 * and from then on is governed by it like everyone else, owner-only rights
 * gone immediately.
 */
class OwnershipTransfer
{
    /**
     * Move ownership from the current owner to another active user.
     *
     * @throws OwnershipTransferException when a precondition fails; nothing is changed then.
     */
    public static function run(User $from, User $to): void
    {
        if ((string) $from->getKey() === (string) $to->getKey()) {
            throw new OwnershipTransferException('Choose a different user: ownership cannot be transferred to the same person.');
        }

        [$lockedFrom, $lockedTo] = DB::transaction(function () use ($from, $to): array {
            // One query, ordered by id, so two concurrent transfers always
            // take the row locks in the same order and cannot deadlock.
            $locked = User::query()
                ->whereKey([$from->getKey(), $to->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (User $user): string => (string) $user->getKey());

            $lockedFrom = $locked->get((string) $from->getKey());
            $lockedTo = $locked->get((string) $to->getKey());

            if ($lockedFrom === null || $lockedTo === null) {
                throw new OwnershipTransferException('That user no longer exists. Reload the page and try again.');
            }

            if (! $lockedFrom->isOwner()) {
                throw new OwnershipTransferException('Only the current owner can transfer ownership.');
            }

            if ($lockedTo->isOwner()) {
                throw new OwnershipTransferException("{$lockedTo->name} is already the owner.");
            }

            if (! $lockedTo->isActive()) {
                throw new OwnershipTransferException("{$lockedTo->name} is inactive. Reactivate them before making them the owner.");
            }

            $lockedFrom->forceFill(['is_owner' => false])->save();
            $lockedTo->forceFill(['is_owner' => true])->save();

            self::assertExactlyOneOwner();

            self::log($lockedTo, 'ownership.transferred', "Ownership transferred from user #{$lockedFrom->getKey()} \"{$lockedFrom->name}\" to user #{$lockedTo->getKey()} \"{$lockedTo->name}\"", [
                'from' => self::describe($lockedFrom),
                'to' => self::describe($lockedTo),
            ]);

            return [$lockedFrom, $lockedTo];
        });

        self::syncInstance($from, $lockedFrom);
        self::syncInstance($to, $lockedTo);
    }

    /**
     * Make an active user the owner of a tenant that has none. This is the
     * recovery path (a platform admin fixing a tenant whose owner flag was
     * lost), not a way around run(): it refuses outright while any owner
     * exists, so it can never create a second one.
     *
     * @throws OwnershipTransferException when an owner already exists or the user cannot be owner.
     */
    public static function promote(User $to): void
    {
        $lockedTo = DB::transaction(function () use ($to): User {
            $existingOwners = self::lockedOwnerIds();

            if ($existingOwners !== []) {
                throw new OwnershipTransferException('This company already has an owner. Transfer ownership from them instead.');
            }

            /** @var User|null $lockedTo */
            $lockedTo = User::query()->lockForUpdate()->find($to->getKey());

            if ($lockedTo === null) {
                throw new OwnershipTransferException('That user no longer exists. Reload the page and try again.');
            }

            if (! $lockedTo->isActive()) {
                throw new OwnershipTransferException("{$lockedTo->name} is inactive. Reactivate them before making them the owner.");
            }

            $lockedTo->forceFill(['is_owner' => true])->save();

            self::assertExactlyOneOwner();

            self::log($lockedTo, 'ownership.promoted', "User #{$lockedTo->getKey()} \"{$lockedTo->name}\" made owner of a company that had none", [
                'to' => self::describe($lockedTo),
            ]);

            return $lockedTo;
        });

        self::syncInstance($to, $lockedTo);
    }

    /**
     * Throws (rolling the surrounding transaction back) unless exactly one
     * owner exists after the change.
     */
    private static function assertExactlyOneOwner(): void
    {
        $owners = count(self::lockedOwnerIds());

        if ($owners !== 1) {
            throw new OwnershipTransferException("Ownership was not changed: the company would have {$owners} owners instead of exactly one.");
        }
    }

    /**
     * Ids of every owner, read with a locking read (see the class docblock).
     *
     * @return list<int|string>
     */
    private static function lockedOwnerIds(): array
    {
        return User::query()
            ->where('is_owner', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();
    }

    /**
     * Copy the committed owner flag onto the caller's instance and drop its
     * memoized permission set, so the very next check on it (for example the
     * acting user's redirect decision, or the Inertia `auth.can` share) sees
     * the new rights. The flag is synced as original too, so a later save()
     * of that instance does not write it again.
     */
    private static function syncInstance(User $instance, User $committed): void
    {
        $instance->forceFill(['is_owner' => $committed->isOwner()]);
        $instance->syncOriginalAttribute('is_owner');
        $instance->flushEffectivePermissions();
    }

    /**
     * @return array{id: int|string, name: string, email: string}
     */
    private static function describe(User $user): array
    {
        return ['id' => $user->getKey(), 'name' => (string) $user->name, 'email' => (string) $user->email];
    }

    /**
     * Written to the tenant activity_logs table the same way RoleController
     * and ActivityLogObserver do (explicit 'web' guard; null when no tenant
     * user is signed in, e.g. a central or console caller). The generic
     * observer on User also records the is_owner flip on each row; this row
     * is the one that says what happened in business terms.
     *
     * @param  array<string, mixed>  $changes
     */
    private static function log(User $subject, string $action, string $description, array $changes): void
    {
        ActivityLog::create([
            'user_id' => Auth::guard('web')->id(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'description' => Str::limit($description, 250),
            'changes' => $changes,
        ]);
    }
}
