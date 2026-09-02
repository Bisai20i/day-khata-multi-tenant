<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
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
            Receipt::class,
            Payment::class,
            JournalVoucher::class,
            FixedAsset::class,
            Quotation::class,
            User::class,
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

        $logs = ActivityLog::query()
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
