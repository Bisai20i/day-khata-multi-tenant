<?php

namespace App\Support\Branches;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Throwable;

/**
 * What a document create form needs to offer a branch picker (plan 12,
 * section 5.10, BR12): the branches the actor may file into and the one the
 * picker starts on. Shared by every create form (receipts, payments, capital
 * settlements, manual journals, then sales, purchases and stock documents),
 * paired with the Vue `BranchSelect` component and the `useBranchField`
 * composable.
 *
 * UI only. The posting path still resolves and checks the branch itself
 * (DocumentBranch, I6); nothing here is trusted on the way back in.
 *
 * Cost and parity: every answer is derived from ActiveBranchSelection's
 * active-branch rows, which are read once per request (the shared Inertia
 * `branch` prop reads them anyway). A tenant without the `branches` module
 * costs no query at all; a Main-only tenant gets an empty choice list, so
 * the picker never renders and the form submits exactly what it did before.
 */
final class BranchFormOptions
{
    /**
     * DocumentBranch's messages for a branch the actor picked (or would get
     * by default) that they cannot use. Mirrored here because DocumentBranch
     * raises plain InvalidArgumentExceptions; keep in step with it.
     *
     * @var list<string>
     */
    private const BRANCH_MESSAGES = [
        'The selected branch is inactive or you do not have access to it.',
        'The document names an invalid branch.',
        'This document must be recorded in the branch of the original document.',
        'You do not have access to post into one of the branches on this entry.',
    ];

    /**
     * The active branches $actor may file a document into, Main first, then
     * in the switcher's order (sort_order, then id). Empty when the tenant is
     * not multi-branch, which is what hides the picker.
     *
     * @return list<array{id: int, name: string, code: string, is_main: bool}>
     */
    public static function choices(User $actor, ?Request $request = null): array
    {
        $request ??= request();

        try {
            if (! ActiveBranchSelection::isMultiBranch($request)) {
                return [];
            }

            $rows = BranchContext::restrictedTo($actor, fn (): array => ActiveBranchSelection::allowedBranches($request));
        } catch (QueryException|ModelNotFoundException) {
            return [];
        }

        // usort is stable, so everything but Main keeps the switcher's order.
        usort($rows, fn (array $a, array $b): int => (int) $b['is_main'] <=> (int) $a['is_main']);

        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'code' => $row['code'],
            'is_main' => $row['is_main'],
        ], $rows);
    }

    /**
     * The branch a new document starts on for the signed-in user: the
     * switcher's branch when one is selected, else their posting branch (a
     * valid default branch, else Main, else their first allowed branch).
     * "All branches" therefore falls back to the user's default, as the
     * switcher promises. Always one of choices().
     *
     * Not multi-branch: Main's id when the module is on (taken from the rows
     * already read), else null. The picker is hidden then and no branch_id
     * is submitted, so the server files the document as it always did.
     */
    public static function defaultId(Request $request): ?int
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return null;
        }

        try {
            if (! ActiveBranchSelection::isMultiBranch($request)) {
                return self::mainIdWithoutMultiBranch($request);
            }
        } catch (QueryException|ModelNotFoundException) {
            return null;
        }

        $ids = array_column(self::choices($actor, $request), 'id');

        if ($ids === []) {
            return null;
        }

        $narrowed = ActiveBranchSelection::narrowedBranchId($request);

        if ($narrowed !== null && in_array($narrowed, $ids, true)) {
            return $narrowed;
        }

        try {
            $posting = BranchContext::postingBranchId($actor);
        } catch (BranchRuleViolation|ModelNotFoundException) {
            $posting = null;
        }

        return $posting !== null && in_array($posting, $ids, true) ? $posting : $ids[0];
    }

    /**
     * Both form props at once, as closures so an Inertia partial reload that
     * does not ask for them never computes them:
     * `Inertia::render('…', [...BranchFormOptions::props($request), …])`.
     *
     * @return array{branchOptions: Closure(): list<array{id: int, name: string, code: string, is_main: bool}>, defaultBranchId: Closure(): ?int}
     */
    public static function props(Request $request): array
    {
        return [
            'branchOptions' => fn (): array => $request->user() instanceof User ? self::choices($request->user(), $request) : [],
            'defaultBranchId' => fn (): ?int => self::defaultId($request),
        ];
    }

    /**
     * The form field a posting failure belongs on: `$branchField` when it is
     * about the document's own branch (inactive, not accessible, invalid, no
     * usable branch, a linked document asking for another branch), `$storeField`
     * when a store is outside the document's branch (only if the form has a
     * store field), else `$fallback`, the field the controller used before
     * BR12. Problems with an allocation target, a missing clearing account or
     * an amount keep `$fallback`, so existing messages stay where they were.
     */
    public static function errorField(Throwable $exception, string $fallback, string $branchField = 'branch_id', ?string $storeField = null): string
    {
        $message = $exception->getMessage();

        $noUsableBranch = $exception->getPrevious() instanceof BranchRuleViolation
            && $message === BranchRuleViolation::noUsableBranch()->getMessage();

        if ($noUsableBranch || in_array($message, self::BRANCH_MESSAGES, true)) {
            return $branchField;
        }

        if ($storeField !== null && (
            $message === DocumentBranch::STORE_OUTSIDE_BRANCH_GENERIC
            || (str_starts_with($message, 'Store "') && str_contains($message, '" does not belong to branch "'))
        )) {
            return $storeField;
        }

        return $fallback;
    }

    /**
     * Main's id for a tenant that is not multi-branch, from the active rows
     * ActiveBranchSelection already read (the module is on); null when the
     * module is off, so that path stays query-free.
     */
    private static function mainIdWithoutMultiBranch(Request $request): ?int
    {
        if (! ActiveBranchSelection::moduleEnabled()) {
            return null;
        }

        foreach (ActiveBranchSelection::allowedBranches($request) as $row) {
            if ($row['is_main']) {
                return $row['id'];
            }
        }

        return null;
    }
}
