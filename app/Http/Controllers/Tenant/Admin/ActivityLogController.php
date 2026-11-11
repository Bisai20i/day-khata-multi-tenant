<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\MaterialIssueNote;
use App\Models\PayComponent;
use App\Models\Payment;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\ProductionCompletion;
use App\Models\ProductionDirectCost;
use App\Models\ProductionEntry;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StaffAdvance;
use App\Models\StaffMember;
use App\Models\StaffPayItem;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Branches\BranchMorphVisibility;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only view over ActivityLog rows written by ActivityLogObserver (see
 * AppServiceProvider::boot()). Admin-only, per routes/tenant-activity-log.php.
 */
class ActivityLogController extends Controller
{
    /**
     * The fixed list of models ActivityLogObserver is attached to, mirrored
     * here purely to populate the subject_type filter dropdown - kept in
     * sync with AppServiceProvider::boot()'s list by hand, same as every
     * other small duplicated list in this app (e.g.
     * StockMovementRegisterController's own movement-type label map).
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function subjectTypeOptions(): array
    {
        $models = [
            Sale::class,
            Purchase::class,
            SalesReturn::class,
            PurchaseReturn::class,
            StockAdjustment::class,
            StockTransfer::class,
            Receipt::class,
            Payment::class,
            JournalVoucher::class,
            FixedAsset::class,
            Quotation::class,
            CapitalSale::class,
            CapitalPurchase::class,
            MaterialIssueNote::class,
            ProductionEntry::class,
            ProductionDirectCost::class,
            ProductionCompletion::class,
            StaffMember::class,
            PayrollRun::class,
            PayrollPayment::class,
            StaffAdvance::class,
            PayComponent::class,
            StaffPayItem::class,
            User::class,
            FiscalYear::class,
            Role::class,
            Area::class,
            // Bulk area assignments (HandlesPartyAreas) - logged by hand, not by the observer.
            Customer::class,
            Supplier::class,
            // Saved purchase price changes made from a purchase bill
            // (ItemPurchasePriceUpdater) - logged by hand, not by the observer.
            Item::class,
        ];

        return collect($models)
            ->map(fn (string $class) => ['value' => $class, 'label' => class_basename($class)])
            ->values()
            ->all();
    }

    public function index(Request $request): Response
    {
        $subjectType = $request->string('subject_type')->toString() ?: null;
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        $query = ActivityLog::query();

        // activity_logs has no branch column, and a row's `changes` JSON
        // carries the document's own fields, so a branch-restricted user
        // must not see rows about another branch's documents. A no-op for
        // the owner and all-branch users (plan 12, section 5.7).
        BranchMorphVisibility::apply($query, 'subject_type', 'subject_id');

        $logs = $query
            ->with('user:id,name')
            ->when($subjectType, fn ($query) => $query->where('subject_type', $subjectType))
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Tenant/Admin/ActivityLog/Index', [
            'logs' => $logs,
            'subjectTypes' => $this->subjectTypeOptions(),
            'filters' => [
                'subject_type' => $subjectType,
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }
}
