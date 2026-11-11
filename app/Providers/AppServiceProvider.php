<?php

namespace App\Providers;

use App\Listeners\RecordProvisioningFailure;
use App\Models\Area;
use App\Models\Branch;
use App\Models\CapitalPurchase;
use App\Models\CapitalPurchaseSettlement;
use App\Models\CapitalSale;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\JournalVoucher;
use App\Models\MaterialIssueNote;
use App\Models\PayComponent;
use App\Models\Payment;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\PlatformAdmin;
use App\Models\ProductionCompletion;
use App\Models\ProductionDirectCost;
use App\Models\ProductionEntry;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StaffAdvance;
use App\Models\StaffMember;
use App\Models\StaffPayItem;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Observers\ActivityLogObserver;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchPrintDetails;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
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
        CapitalSale::observe(ActivityLogObserver::class);
        CapitalPurchase::observe(ActivityLogObserver::class);
        CapitalPurchaseSettlement::observe(ActivityLogObserver::class);
        MaterialIssueNote::observe(ActivityLogObserver::class);
        ProductionEntry::observe(ActivityLogObserver::class);
        ProductionDirectCost::observe(ActivityLogObserver::class);
        ProductionCompletion::observe(ActivityLogObserver::class);
        StaffMember::observe(ActivityLogObserver::class);
        PayrollRun::observe(ActivityLogObserver::class);
        PayrollPayment::observe(ActivityLogObserver::class);
        StaffAdvance::observe(ActivityLogObserver::class);
        PayComponent::observe(ActivityLogObserver::class);
        StaffPayItem::observe(ActivityLogObserver::class);
        User::observe(ActivityLogObserver::class);
        Area::observe(ActivityLogObserver::class);
        // Covers close()/reopen()/relock() - each is a plain field-setting
        // update() under the hood, so the generic observer's 'updated'
        // write already logs them without any bespoke logging call.
        FiscalYear::observe(ActivityLogObserver::class);

        // BranchContext caches the Main id, the allowed-branch sets and the
        // active flags for the lifetime of a request. Drop that cache when a
        // branch is created or (de)activated, or when a user's branch access
        // changes; other user saves (remember token, profile) must not clear
        // the UI's branch selection that flush() also resets.
        Branch::saved(fn () => BranchContext::flush());
        Branch::deleted(fn () => BranchContext::flush());
        User::saved(function (User $user): void {
            if ($user->wasChanged(['all_branches', 'default_branch_id', 'is_active'])) {
                BranchContext::flush();
            }
        });

        // The one place a printed document's header learns its branch's
        // address, phone, email and PAN (plan 12, BR21). The shared layout
        // (every A4/A5 document), the IRD invoice view (its included parts
        // inherit the data) and the thermal receipt read $branchPrintDetails;
        // for anything but a multi-branch company it is the company's own
        // details, so single-branch prints are unchanged.
        View::composer(
            ['pdf.layout', 'pdf.sale', 'pdf.sale-receipt'],
            fn (ViewContract $view) => $view->with('branchPrintDetails', BranchPrintDetails::forView($view->getData())),
        );

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
