<?php

namespace App\Support\Branches;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Store;
use App\Models\User;
use InvalidArgumentException;
use LogicException;

/**
 * Where a new document is filed: its branch and, for a store-bearing
 * document, its store (plan 12, sections 5.4 and 5.5, invariants I5 and I6).
 * One resolver for every posting module, so sales, purchases, stock and HR
 * documents all pick and check their branch the same way.
 *
 * Branch, in order: the branch the caller asked for, else the store's
 * branch, else BranchContext::postingBranchId() (the actor's selected or
 * default branch, else Main). A linked document (a return against a sale)
 * instead inherits its parent's branch and may not ask for another.
 *
 * Store, when none was given: the tenant's legacy default (the company
 * default store, else the lowest-id active store), but only while that store
 * belongs to the document's branch; otherwise the branch's own default store
 * when it is active, else the branch's lowest-id active store. A linked
 * document defaults to its parent's store.
 *
 * Checks, all raised as InvalidArgumentException so every controller that
 * already catches it shows a form error instead of a 500 (BranchRuleViolation
 * is a DomainException and AuthorizationException renders a bare 403):
 * the store must belong to the document's branch (I5), and the actor must be
 * allowed to use the branch, which must be active (I6). The access check is
 * skipped inside BranchContext::unrestricted(), where system code files
 * documents on purpose; JournalVoucher::post() still checks the voucher.
 *
 * Single-branch parity: with only Main, every store is a Main store and the
 * actor's posting branch is Main, so the legacy default store always fits
 * and the document gets exactly the store it got before. The cost is one
 * primary-key read of the store's branch_id; Main's id and the active flags
 * come from BranchContext's per-request cache.
 */
final readonly class DocumentBranch
{
    /**
     * Shown instead of the named store/branch mismatch when the actor cannot
     * see one of the two branches (BR22 M1). BranchFormOptions::errorField
     * maps it to the store field, so keep the two in step.
     */
    public const STORE_OUTSIDE_BRANCH_GENERIC = "The selected store does not belong to the document's branch, or you do not have access to it. Choose a store of the document's branch.";

    private function __construct(
        public int $branchId,
        public ?int $storeId,
    ) {}

    /**
     * A document with no store (quotation, receipt, payment, journal).
     *
     * @param  mixed  $branchId  The requested branch; null or '' means "not given".
     *
     * @throws InvalidArgumentException
     */
    public static function forDocument(User $actor, mixed $branchId = null): self
    {
        $branchId = self::requestedBranchId($branchId) ?? self::postingBranchId($actor);
        self::assertActorMayUse($actor, $branchId);

        return new self($branchId, null);
    }

    /**
     * A document that moves stock in or out of one store (sale, purchase,
     * unlinked return, stock adjustment, manufacturing document).
     *
     * @param  mixed  $branchId  The requested branch; null or '' means "not given".
     * @param  mixed  $storeId  The requested store; null or '' means "not given".
     * @param  CompanySetting|null  $settings  Pass it when the caller already loaded it, to save a read.
     *
     * @throws InvalidArgumentException
     */
    public static function forStoreDocument(User $actor, mixed $branchId, mixed $storeId, ?CompanySetting $settings = null): self
    {
        $requestedBranchId = self::requestedBranchId($branchId);
        $requestedStoreId = self::requestedId($storeId, 'The document names an invalid store.');

        if ($requestedStoreId !== null) {
            $storeBranchId = self::storeBranchId($requestedStoreId);

            // Access first (BR22 M1): a store of a branch the actor may not
            // use, or a branch they may not use, is refused with the generic
            // I6 message, so the mismatch message below (which names the
            // store and the branch) is only ever shown about branches the
            // actor can already see.
            self::assertActorMayUse($actor, $storeBranchId);

            if ($requestedBranchId !== null && $requestedBranchId !== $storeBranchId) {
                self::assertActorMayUse($actor, $requestedBranchId);

                throw self::storeOutsideBranch($requestedStoreId, $requestedBranchId, $storeBranchId, $actor);
            }

            return new self($storeBranchId, $requestedStoreId);
        }

        $branchId = $requestedBranchId ?? self::postingBranchId($actor);
        self::assertActorMayUse($actor, $branchId);

        return new self($branchId, self::defaultStoreId($branchId, $settings));
    }

    /**
     * A document linked to a parent (a return against its sale or purchase):
     * it always lands in the parent's branch (plan 12, section 5.4), and its
     * store defaults to the parent's store.
     *
     * @param  mixed  $branchId  The requested branch; anything but the parent's branch is refused.
     * @param  mixed  $storeId  The requested store; it must belong to the parent's branch.
     *
     * @throws InvalidArgumentException
     */
    public static function inherited(User $actor, int $parentBranchId, ?int $parentStoreId, mixed $branchId = null, mixed $storeId = null): self
    {
        $requestedBranchId = self::requestedBranchId($branchId);

        if ($requestedBranchId !== null && $requestedBranchId !== $parentBranchId) {
            throw new InvalidArgumentException('This document must be recorded in the branch of the original document.');
        }

        self::assertActorMayUse($actor, $parentBranchId);

        $requestedStoreId = self::requestedId($storeId, 'The document names an invalid store.');

        if ($requestedStoreId !== null) {
            self::assertStoreInBranchFor($requestedStoreId, $parentBranchId, $actor);

            return new self($parentBranchId, $requestedStoreId);
        }

        // The parent's store is in the parent's branch by I5, so it needs no
        // check. A parent with no store (rows from before stores existed)
        // falls back like a fresh document of that branch.
        return new self($parentBranchId, $parentStoreId ?? self::branchFallbackStoreId($parentBranchId));
    }

    /**
     * Invariant I5 for one store: it must belong to $branchId. For documents
     * with more than one store (a transfer's two ends) or line-level stores.
     *
     * @throws InvalidArgumentException
     */
    public static function assertStoreInBranch(int $storeId, int $branchId): void
    {
        self::assertStoreInBranchFor($storeId, $branchId, null);
    }

    /**
     * assertStoreInBranch() for a known actor (null: the context user), who
     * decides whether the refusal may name the store and its branch.
     *
     * @throws InvalidArgumentException
     */
    private static function assertStoreInBranchFor(int $storeId, int $branchId, ?User $actor): void
    {
        $storeBranchId = self::storeBranchId($storeId);

        if ($storeBranchId !== $branchId) {
            throw self::storeOutsideBranch($storeId, $branchId, $storeBranchId, $actor);
        }
    }

    /**
     * The resolved store, for the factories that always resolve one
     * (forStoreDocument() and inherited()).
     */
    public function requireStoreId(): int
    {
        return $this->storeId ?? throw new LogicException('This document branch was resolved without a store.');
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function postingBranchId(User $actor): int
    {
        try {
            return BranchContext::postingBranchId($actor);
        } catch (BranchRuleViolation $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Invariant I6: the actor may only file documents in an active branch
     * they can access. A no-op for system code inside
     * BranchContext::unrestricted().
     *
     * @throws InvalidArgumentException
     */
    private static function assertActorMayUse(User $actor, int $branchId): void
    {
        if (BranchContext::accessIsLifted()) {
            return;
        }

        if (! BranchContext::canAccessBranch($branchId, $actor)) {
            throw new InvalidArgumentException('The selected branch is inactive or you do not have access to it.');
        }
    }

    /**
     * The company default store while it belongs to $branchId (always, for a
     * single-branch tenant), else the branch's own fallback.
     *
     * @throws InvalidArgumentException
     */
    private static function defaultStoreId(int $branchId, ?CompanySetting $settings): int
    {
        $settings ??= CompanySetting::current();
        $legacyDefault = $settings->default_store_id
            ?? Store::query()->where('is_active', true)->orderBy('id')->value('id');

        $legacyDefaultUsable = $legacyDefault !== null && Store::query()
            ->whereKey($legacyDefault)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->exists();

        if ($legacyDefaultUsable) {
            return (int) $legacyDefault;
        }

        return self::branchFallbackStoreId($branchId);
    }

    /**
     * The branch's default store when it is an active store of the branch,
     * else the branch's lowest-id active store.
     *
     * @throws InvalidArgumentException
     */
    private static function branchFallbackStoreId(int $branchId): int
    {
        $branchStores = fn () => Store::query()->where('branch_id', $branchId)->where('is_active', true);

        $branchDefault = Branch::query()->whereKey($branchId)->value('default_store_id');
        $storeId = $branchDefault === null ? null : $branchStores()->whereKey($branchDefault)->value('id');
        $storeId ??= $branchStores()->orderBy('id')->value('id');

        if ($storeId === null) {
            throw new InvalidArgumentException('No active store is configured.');
        }

        return (int) $storeId;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function storeBranchId(int $storeId): int
    {
        // A shared lock: inside a posting transaction this waits for a store
        // move (Store::updating takes the row lock) and reads the committed
        // branch, so a document cannot be filed under a branch the store just
        // left. A no-op on SQLite and outside a transaction.
        $branchId = Store::query()->whereKey($storeId)->sharedLock()->value('branch_id');

        if ($branchId === null) {
            throw new InvalidArgumentException("Store [{$storeId}] does not exist.");
        }

        return (int) $branchId;
    }

    /**
     * The I5 refusal. It names the store and the document's branch only when
     * the actor (null: the context user) may see both branches involved; a
     * branch-restricted actor who named another branch's store gets a
     * generic message instead, so a refusal never reveals a store or branch
     * name outside their access (BR22 M1).
     */
    private static function storeOutsideBranch(int $storeId, int $branchId, int $storeBranchId, ?User $actor): InvalidArgumentException
    {
        if (self::branchHiddenFrom($actor, $storeBranchId) || self::branchHiddenFrom($actor, $branchId)) {
            return new InvalidArgumentException(self::STORE_OUTSIDE_BRANCH_GENERIC);
        }

        $storeName = Store::query()->whereKey($storeId)->value('name') ?? "#{$storeId}";
        $branchName = Branch::query()->whereKey($branchId)->value('name') ?? "#{$branchId}";

        return new InvalidArgumentException(sprintf(
            'Store "%s" does not belong to branch "%s". Choose a store of the document\'s branch.',
            $storeName,
            $branchName,
        ));
    }

    /**
     * Whether $branchId lies outside a branch-restricted actor's allowed set
     * (null actor: the context user). False for unrestricted actors and
     * inside BranchContext::unrestricted(), so their messages are unchanged.
     */
    private static function branchHiddenFrom(?User $actor, int $branchId): bool
    {
        if (BranchContext::accessIsLifted()) {
            return false;
        }

        $allowed = BranchContext::allowedBranchIds($actor);

        return $allowed !== null && ! in_array($branchId, $allowed, true);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function requestedBranchId(mixed $branchId): ?int
    {
        return self::requestedId($branchId, 'The document names an invalid branch.');
    }

    /**
     * A positive id from caller input; null or '' means "not given".
     *
     * @throws InvalidArgumentException
     */
    private static function requestedId(mixed $value, string $invalidMessage): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $id = (int) $value;

            if ($id > 0) {
                return $id;
            }
        }

        throw new InvalidArgumentException($invalidMessage);
    }
}
