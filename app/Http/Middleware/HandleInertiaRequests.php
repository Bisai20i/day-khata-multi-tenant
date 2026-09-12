<?php

namespace App\Http\Middleware;

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
                'user' => $request->user('web'),
            ],
            'tenant' => fn (): ?array => tenancy()->initialized
                ? ['company_name' => tenant('company_name')]
                : null,
            'flash' => [
                'status' => fn (): ?string => $request->session()->get('status'),
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
