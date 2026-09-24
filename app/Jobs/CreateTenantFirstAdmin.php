<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TenantStatus;
use App\Mail\TenantWelcomeMail;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Throwable;

/**
 * Last step of the TenantCreated job pipeline (app/Providers/TenancyServiceProvider.php),
 * run after CreateDatabase/MigrateDatabase/SeedDatabase. Creates the tenant's first admin
 * user from the payload TenantController::store() stashed on the tenant's virtual `data`
 * column (see App\Models\Tenant / getCustomColumns()), then flips the tenant to Active.
 *
 * pending_admin is only ever set by TenantController::store(). Tenants created any other way
 * (Tenant::create() directly, e.g. throughout the test suite and in tinker) still run this
 * same pipeline once TenantCreated fires, so admin creation is skipped rather than assumed
 * when there's no payload to act on - the tenant still gets flipped to Active either way.
 */
class CreateTenantFirstAdmin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var TenantWithDatabase */
    protected $tenant;

    public function __construct(TenantWithDatabase $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle(): void
    {
        // Re-fetch rather than trust the (de)serialized instance passed down
        // the job pipeline - this also guarantees `data` (and therefore
        // pending_admin) is freshly decoded from the DB rather than relying
        // on whatever decode state the instance happened to carry through
        // serialization.
        $tenant = $this->tenant->fresh();

        $pendingAdmin = $tenant->pending_admin;

        if ($pendingAdmin !== null) {
            $tenant->run(function () use ($pendingAdmin): void {
                $role = Role::where('slug', 'admin')->firstOrFail();

                User::create([
                    'name' => $pendingAdmin['name'],
                    'email' => $pendingAdmin['email'],
                    // Already hashed by TenantController::store() before being stashed;
                    // the `hashed` cast on User::password only re-hashes when a value
                    // isn't already a recognized hash (Hash::isHashed()), so this is safe.
                    'password' => $pendingAdmin['password'],
                    'role_id' => $role->id,
                ]);
            });

            $this->sendWelcomeMail($tenant, $pendingAdmin);
        }

        $tenant->status = TenantStatus::Active;
        $tenant->pending_admin = null;
        $tenant->save();
    }

    /**
     * Best-effort: a mail failure never blocks provisioning itself, since
     * the admin user has already been created successfully by this point.
     *
     * @param  array{name: string, email: string, password: string, domain: string}  $pendingAdmin
     */
    private function sendWelcomeMail(TenantWithDatabase $tenant, array $pendingAdmin): void
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';
        $port = parse_url($appUrl, PHP_URL_PORT);
        $portSuffix = $port !== null ? ":{$port}" : '';

        try {
            PlatformMailer::apply();

            Mail::to($pendingAdmin['email'])->send(new TenantWelcomeMail(
                companyName: $tenant->company_name,
                adminName: $pendingAdmin['name'],
                loginUrl: "{$scheme}://{$pendingAdmin['domain']}{$portSuffix}/login",
            ));
        } catch (Throwable) {
            // Intentionally swallowed - see comment above.
        }
    }
}
