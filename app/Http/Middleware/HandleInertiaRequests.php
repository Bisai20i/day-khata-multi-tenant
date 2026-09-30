<?php

namespace App\Http\Middleware;

use App\Models\FiscalYear;
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
