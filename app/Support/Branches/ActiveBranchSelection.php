<?php

namespace App\Support\Branches;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * The session half of the top-bar branch switcher (plan 12, section 5.10).
 *
 * The session holds a UI choice only: a branch id, or "all" for the read-only
 * "All branches" view. It is never trusted. Every read checks it against what
 * the signed-in user may use right now (an assignment can be removed or a
 * branch deactivated after the choice was made), and anything stale is
 * dropped. Security stays with BranchContext's allowed set; this only
 * narrows.
 *
 * Cost: the active branches (a tiny table) are read once per request and kept
 * on the request, and everything else (allowed list, selectable check, Main,
 * "is this tenant multi-branch") is derived from that one read. A tenant
 * without the `branches` module, with only Main, or whose branches tables are
 * missing resolves to "not multi-branch" and never throws.
 */
final class ActiveBranchSelection
{
    public const SESSION_KEY = 'active_branch';

    public const ALL = 'all';

    /**
     * The only words a company may call a branch (company_settings.branch_label).
     * The single source of truth: BR10's settings validation reuses this list
     * rather than keeping its own copy.
     *
     * @var list<string>
     */
    public const LABELS = ['branch', 'site', 'outlet', 'project'];

    private const ROWS_ATTRIBUTE = 'branch_switcher_rows';

    private const CURRENT_ATTRIBUTE = 'branch_switcher_current';

    /**
     * Whether the tenant is entitled to the `branches` module. Existing
     * tenants never had the key, so it is off for them until the central
     * panel switches it on.
     */
    public static function moduleEnabled(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $tenant = tenant();

        return $tenant instanceof Tenant && $tenant->hasModule('branches');
    }

    /**
     * Whether the signed-in user may pick "All branches": only users without
     * a branch restriction.
     */
    public static function canViewAll(): bool
    {
        return BranchContext::allowedBranchIds() === null;
    }

    /**
     * Whether the tenant runs more than one active branch and has the module
     * on. False means every branch control stays hidden.
     */
    public static function isMultiBranch(Request $request): bool
    {
        return self::moduleEnabled() && count(self::activeRows($request)) > 1;
    }

    /**
     * Whether $choice (a branch id, a numeric string, or "all") is something
     * the context user may select. A branch id must be an active branch they
     * may use.
     */
    public static function isSelectable(Request $request, mixed $choice): bool
    {
        $normalized = self::normalize($choice);

        if ($normalized === null) {
            return false;
        }

        if ($normalized === self::ALL) {
            return self::canViewAll();
        }

        return in_array($normalized, self::allowedIds($request), true);
    }

    /**
     * The branch id, "all", or null for anything that is neither.
     */
    public static function normalize(mixed $choice): int|string|null
    {
        if (is_string($choice) && strtolower(trim($choice)) === self::ALL) {
            return self::ALL;
        }

        if (is_int($choice) && $choice > 0) {
            return $choice;
        }

        if (is_string($choice) && ctype_digit($choice) && (int) $choice > 0) {
            return (int) $choice;
        }

        return null;
    }

    /**
     * The stored choice when it is still valid for the context user, else
     * null (nothing chosen, or stale). A stale value is removed from the
     * session so it is not re-checked on every request.
     */
    public static function stored(Request $request): int|string|null
    {
        if (! $request->hasSession()) {
            return null;
        }

        $stored = $request->session()->get(self::SESSION_KEY);

        if ($stored === null || ! self::isMultiBranch($request)) {
            return null;
        }

        if (self::isSelectable($request, $stored)) {
            return self::normalize($stored);
        }

        $request->session()->forget(self::SESSION_KEY);

        return null;
    }

    /**
     * Puts the session choice into BranchContext for this request. Always
     * writes, even when nothing is chosen: the context is a scoped singleton
     * that a long-lived process (tests, a worker) can carry from one request
     * to the next, and a previous request's choice must not leak into this
     * one. Never throws: a tenant whose branch tables are missing simply gets
     * no selection.
     */
    public static function apply(Request $request): void
    {
        try {
            $user = self::moduleEnabled() ? $request->user('web') : null;
            $choice = $user instanceof User ? self::stored($request) : null;

            BranchContext::setActiveBranchId(is_int($choice) ? $choice : null);
        } catch (QueryException|ModelNotFoundException) {
            BranchContext::setActiveBranchId(null);
        }
    }

    /**
     * What the switcher shows as selected: a branch id, or "all".
     *
     * Order: the stored choice; else, for a user who may view every branch,
     * their default branch when set, otherwise "All branches"; else (a
     * restricted user) the branch new records would be filed under. Null when
     * the tenant is not multi-branch, or a restricted user has no usable
     * branch.
     */
    public static function current(Request $request): int|string|null
    {
        if ($request->attributes->has(self::CURRENT_ATTRIBUTE)) {
            return $request->attributes->get(self::CURRENT_ATTRIBUTE);
        }

        $current = self::resolveCurrent($request);
        $request->attributes->set(self::CURRENT_ATTRIBUTE, $current);

        return $current;
    }

    /**
     * Whether the user is looking at every branch (no narrowing). For list and
     * report filters (BR12+). False for a tenant that is not multi-branch:
     * use narrowedBranchId() there, which is simply null.
     */
    public static function viewingAll(?Request $request = null): bool
    {
        return self::current($request ?? request()) === self::ALL;
    }

    /**
     * The one branch a list or report should narrow to, or null for no
     * narrowing (all branches, or a tenant that is not multi-branch).
     */
    public static function narrowedBranchId(?Request $request = null): ?int
    {
        $current = self::current($request ?? request());

        return is_int($current) ? $current : null;
    }

    /**
     * The shared Inertia `branch` prop: null for guests and central pages,
     * the minimal "not multi-branch" shape for a single-branch tenant, the
     * full shape otherwise.
     *
     * @return array{current: int|string|null, isAll: bool, allowed: list<array{id: int, name: string, code: string, is_main: bool, is_active: bool}>, canViewAll: bool, isMulti: bool}|null
     */
    public static function shared(Request $request): ?array
    {
        if (! tenancy()->initialized || ! $request->user('web') instanceof User) {
            return null;
        }

        try {
            if (! self::isMultiBranch($request)) {
                return self::minimalShape();
            }

            $current = self::current($request);

            return [
                'current' => $current,
                'isAll' => $current === self::ALL,
                'allowed' => self::allowedBranches($request),
                'canViewAll' => self::canViewAll(),
                'isMulti' => true,
            ];
        } catch (QueryException|ModelNotFoundException) {
            return self::minimalShape();
        }
    }

    /**
     * What the company calls a branch, as a capitalised singular word
     * ("Branch" by default). Only read for a multi-branch tenant; everyone
     * else gets the default without a query. Read straight from the settings
     * row so a page view never creates it, and tolerant of the column not
     * existing yet.
     */
    public static function sharedLabel(Request $request): string
    {
        $default = ucfirst(self::LABELS[0]);

        if (! tenancy()->initialized || ! $request->user('web') instanceof User) {
            return $default;
        }

        try {
            return self::isMultiBranch($request) ? self::label() : $default;
        } catch (QueryException) {
            return $default;
        }
    }

    public static function label(): string
    {
        $stored = CompanySetting::query()->first()?->getAttribute('branch_label');
        $key = is_string($stored) ? strtolower(trim($stored)) : '';

        return ucfirst(in_array($key, self::LABELS, true) ? $key : self::LABELS[0]);
    }

    /**
     * The active branches the context user may use, in display order.
     *
     * @return list<array{id: int, name: string, code: string, is_main: bool, is_active: bool}>
     */
    public static function allowedBranches(Request $request): array
    {
        $allowed = BranchContext::allowedBranchIds();
        $rows = self::activeRows($request);

        if ($allowed === null) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => in_array($row['id'], $allowed, true),
        ));
    }

    /**
     * @return array{current: null, isAll: false, allowed: list<never>, canViewAll: false, isMulti: false}
     */
    private static function minimalShape(): array
    {
        return ['current' => null, 'isAll' => false, 'allowed' => [], 'canViewAll' => false, 'isMulti' => false];
    }

    private static function resolveCurrent(Request $request): int|string|null
    {
        if (! self::isMultiBranch($request)) {
            return null;
        }

        $stored = self::stored($request);

        if ($stored !== null) {
            return $stored;
        }

        $user = $request->user('web');
        $default = $user instanceof User ? $user->getAttribute('default_branch_id') : null;

        if (self::canViewAll()) {
            return $default !== null && self::isSelectable($request, (int) $default) ? (int) $default : self::ALL;
        }

        try {
            return BranchContext::postingBranchId();
        } catch (BranchRuleViolation|ModelNotFoundException) {
            return null;
        }
    }

    /**
     * Ids of the active branches the context user may use.
     *
     * @return list<int>
     */
    private static function allowedIds(Request $request): array
    {
        return array_map(fn (array $row): int => $row['id'], self::allowedBranches($request));
    }

    /**
     * Every active branch in display order, read once per request. A tenant
     * whose branches table is missing yields an empty list.
     *
     * @return list<array{id: int, name: string, code: string, is_main: bool, is_active: bool}>
     */
    private static function activeRows(Request $request): array
    {
        if ($request->attributes->has(self::ROWS_ATTRIBUTE)) {
            return $request->attributes->get(self::ROWS_ATTRIBUTE);
        }

        try {
            $rows = Branch::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'code', 'is_main', 'is_active'])
                ->map(fn (Branch $branch): array => [
                    'id' => (int) $branch->id,
                    'name' => (string) $branch->name,
                    'code' => (string) $branch->code,
                    'is_main' => (bool) $branch->is_main,
                    'is_active' => (bool) $branch->is_active,
                ])
                ->all();
        } catch (QueryException) {
            $rows = [];
        }

        $request->attributes->set(self::ROWS_ATTRIBUTE, $rows);

        return $rows;
    }
}
