<?php

namespace App\Providers;

use App\Models\FixedAsset;
use App\Models\JournalVoucher;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Observers\ActivityLogObserver;
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
    }
}
