<?php

namespace App\Http\Middleware;

use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\ReversalNotice;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'platformAdmin' => $request->user('platform'),
                // With its role: pages decide what to offer from
                // auth.user.role.slug, and the relation was only ever loaded by
                // the role middleware, so admins lost their admin-only buttons
                // and menu items on every route that had no role gate.
                'user' => $request->user('web')?->loadMissing('role'),
                // The UI only hides things; the server gate is the authority.
                // Sorted permission keys of the tenant user (empty for guests
                // and platform admins), read lazily from the memoized set.
                'can' => function () use ($request): array {
                    $user = $request->user('web');

                    if (! $user instanceof User) {
                        return [];
                    }

                    $keys = array_keys($user->effectivePermissions());
                    sort($keys);

                    return $keys;
                },
                'isOwner' => function () use ($request): bool {
                    $user = $request->user('web');

                    return $user instanceof User && $user->isOwner();
                },
            ],
            'tenant' => fn (): ?array => tenancy()->initialized
                ? [
                    'company_name' => tenant('company_name'),
                    // Every posting path needs an open fiscal year
                    // (JournalVoucher::post()); pages use this to hide their
                    // create buttons and AppLayout to show a setup banner.
                    'has_open_fiscal_year' => FiscalYear::hasOpen(),
                ]
                : null,
            // Top-bar branch switcher (plan 12, section 5.10). Lazy: nothing
            // runs for guests or central pages, and a tenant without the
            // branches module, or with only Main, gets the minimal shape
            // (isMulti = false) for one cached branches read, so the UI
            // renders nothing extra.
            'branch' => fn (): ?array => ActiveBranchSelection::shared($request),
            // What the company calls a branch: Branch, Site, Outlet or Project
            // (the default, without a query, unless the tenant is multi-branch).
            'branchLabel' => fn (): string => ActiveBranchSelection::sharedLabel($request),
            'flash' => [
                // Plus the note JournalVoucher::reverse() leaves when it had to
                // date a cancellation on the open year's last day (flags G-06).
                'status' => fn (): ?string => ReversalNotice::appendTo($request->session()->get('status')),
                'importResult' => fn (): ?array => $request->session()->get('importResult'),
                // CONTRACTS C11: a controller that just posted a document
                // flashes ['type', 'id', 'print_url'] here, so the page can
                // open exactly that document's print view. Pages used to guess
                // the newest id out of the list they were redirected to, which
                // printed the wrong bill for a back-dated sale or a second
                // till, and threw outright once the list became a paginator
                // (audit P0-6).
                'created' => fn (): ?array => $request->session()->get('created'),
            ],
        ];
    }
}
