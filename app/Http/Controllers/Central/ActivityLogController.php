<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only, filterable listing of PlatformAdminActivityLog rows - the
 * append-only audit trail for sensitive central (platform-admin) actions.
 * Spans every tenant, so this lives directly under Central rather than
 * Central\Tenants. Protected by the "platform" guard, see
 * routes/central-activity-log.php.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = $request->filled('tenant_id') ? $request->integer('tenant_id') : null;
        $action = $request->filled('action') ? $request->string('action')->toString() : null;
        $platformAdminId = $request->filled('platform_admin_id') ? $request->integer('platform_admin_id') : null;

        $logs = PlatformAdminActivityLog::query()
            ->with(['platformAdmin:id,name,email', 'tenant:id,company_name'])
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($action, fn ($query) => $query->where('action', $action))
            ->when($platformAdminId, fn ($query) => $query->where('platform_admin_id', $platformAdminId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PlatformAdminActivityLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'platform_admin' => $log->platformAdmin ? [
                    'name' => $log->platformAdmin->name,
                    'email' => $log->platformAdmin->email,
                ] : null,
                'tenant' => $log->tenant ? [
                    'id' => $log->tenant->id,
                    'company_name' => $log->tenant->company_name,
                ] : null,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ]);

        return Inertia::render('Central/ActivityLog/Index', [
            'logs' => $logs,
            'filters' => [
                'tenant_id' => $tenantId,
                'action' => $action,
                'platform_admin_id' => $platformAdminId,
            ],
            'tenantOptions' => Tenant::query()
                ->orderBy('company_name')
                ->get(['id', 'company_name'])
                ->map(fn (Tenant $tenant): array => ['value' => $tenant->id, 'label' => $tenant->company_name])
                ->values(),
            'platformAdminOptions' => PlatformAdmin::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (PlatformAdmin $admin): array => ['value' => $admin->id, 'label' => $admin->name])
                ->values(),
            'actionOptions' => PlatformAdminActivityLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->map(fn (string $action): array => ['value' => $action, 'label' => $action])
                ->values(),
        ]);
    }
}
