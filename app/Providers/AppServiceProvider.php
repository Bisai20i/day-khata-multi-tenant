<?php

namespace App\Providers;

use App\Listeners\RecordProvisioningFailure;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\JournalVoucher;
use App\Models\Payment;
use App\Models\PlatformAdmin;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Observers\ActivityLogObserver;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tenant-scoped audit trail: attach ActivityLogObserver to every
        // financial model via Eloquent's global observer registration
        // instead of editing each model's own file - keeps this the only
        // place that knows about the activity-log feature, so it never
        // collides with other passes that own these models' files.
        Sale::observe(ActivityLogObserver::class);
        Purchase::observe(ActivityLogObserver::class);
        SalesReturn::observe(ActivityLogObserver::class);
        PurchaseReturn::observe(ActivityLogObserver::class);
        StockAdjustment::observe(ActivityLogObserver::class);
        Receipt::observe(ActivityLogObserver::class);
        Payment::observe(ActivityLogObserver::class);
        JournalVoucher::observe(ActivityLogObserver::class);
        FixedAsset::observe(ActivityLogObserver::class);
        Quotation::observe(ActivityLogObserver::class);
        User::observe(ActivityLogObserver::class);
        // Covers close()/reopen()/relock() - each is a plain field-setting
        // update() under the hood, so the generic observer's 'updated'
        // write already logs them without any bespoke logging call.
        FiscalYear::observe(ActivityLogObserver::class);

        // Owner-only central actions: platform settings changes, tenant
        // delete, platform-admin management. 'support' admins can do
        // everything else (view, impersonate, suspend/resume, view users/
        // audit log).
        Gate::define('platform-owner', fn (PlatformAdmin $admin): bool => $admin->isOwner());

        // See App\Listeners\RecordProvisioningFailure's own docblock for why
        // this hooks the framework's JobFailed event rather than the
        // TenancyServiceProvider pipeline directly.
        Event::listen(JobFailed::class, RecordProvisioningFailure::class);
    }
}
