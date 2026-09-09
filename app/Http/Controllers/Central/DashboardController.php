<?php

namespace App\Http\Controllers\Central;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdminActivityLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Replaces the inline closure that used to live in routes/central-auth.php.
 * Read-only aggregate view over Tenant/PlatformAdminActivityLog - no writes,
 * no new schema.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        // toBase() skips Eloquent hydration/casting: without it, ->status
        // would come back as a TenantStatus enum instance rather than a
        // plain string, and pluck('count', 'status') would key the
        // resulting collection by that enum object instead of its value.
        $statusCounts = Tenant::query()->toBase()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $gracePeriodDays = PlatformSetting::current()->default_grace_period_days;

        // Small dataset at platform scale - filtered in PHP via the same
        // isPastGracePeriod() the tenant list/show pages already use, rather
        // than duplicating its date math in SQL.
        $tenantsPastGracePeriod = Tenant::query()
            ->whereNotNull('suspended_at')
            ->get()
            ->filter(fn (Tenant $tenant): bool => $tenant->isPastGracePeriod($gracePeriodDays))
            ->values();

        $trialsExpiringSoon = Tenant::query()
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
            ->orderBy('trial_ends_at')
            ->get();

        $recentActivity = PlatformAdminActivityLog::query()
            ->with(['platformAdmin:id,name', 'tenant:id,company_name'])
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get();

        return Inertia::render('Central/Dashboard', [
            'stats' => [
                'total' => (int) $statusCounts->sum(),
                'active' => (int) $statusCounts->get(TenantStatus::Active->value, 0),
                'provisioning' => (int) $statusCounts->get(TenantStatus::Provisioning->value, 0),
                'suspended' => (int) $statusCounts->get(TenantStatus::Suspended->value, 0),
                'created_this_week' => Tenant::where('created_at', '>=', now()->startOfWeek())->count(),
                'created_this_month' => Tenant::where('created_at', '>=', now()->startOfMonth())->count(),
            ],
            'tenantsPastGracePeriod' => $tenantsPastGracePeriod->map(fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'suspended_at' => $tenant->suspended_at?->toDateString(),
            ])->values(),
            'trialsExpiringSoon' => $trialsExpiringSoon->map(fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
            ])->values(),
            'recentActivity' => $recentActivity->map(fn (PlatformAdminActivityLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'platform_admin' => $log->platformAdmin?->name,
                'tenant' => $log->tenant?->company_name,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ])->values(),
        ]);
    }
}
