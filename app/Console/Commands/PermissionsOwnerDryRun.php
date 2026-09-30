<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Permissions\RoleBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Rollout dry run for the roles and permissions backfill (deployment
 * checklist step 4). Strictly read-only: for every tenant it reports who
 * RoleBackfill::run() would make owner and why, using the very same selection
 * (RoleBackfill::ownerCandidate()), so the two can never diverge. Works on
 * tenants that have not run the permissions migrations yet (guards on
 * Schema::hasColumn, query builder only) and reports a tenant whose database
 * is missing instead of aborting. Exit code 1 when any tenant would end up
 * without an owner or has an unreachable database.
 */
class PermissionsOwnerDryRun extends Command
{
    /**
     * @var string
     */
    protected $signature = 'permissions:owner-dry-run {--json : Print the report as JSON}';

    /**
     * @var string
     */
    protected $description = 'Read-only: show which user each tenant would get as owner from the roles and permissions backfill';

    public function handle(): int
    {
        $reports = [];

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use (&$reports): void {
            $reports[] = $this->inspectTenant($tenant);
        });

        $failed = collect($reports)->contains(fn (array $report): bool => $report['flag'] !== null);

        if ($this->option('json')) {
            $this->line((string) json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($reports as $report) {
                $this->printReport($report);
            }

            $this->line(count($reports).' tenant(s) checked, '.collect($reports)->whereNotNull('flag')->count().' flagged.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{tenant_id: string, company: ?string, contact_email: ?string, status: string, owner: ?string, reason: ?string, other_admins: list<string>, roles: int, users: int, flag: ?string}
     */
    private function inspectTenant(Tenant $tenant): array
    {
        $report = [
            'tenant_id' => (string) $tenant->getTenantKey(),
            'company' => $tenant->company_name,
            'contact_email' => $tenant->contact_email,
            'status' => 'ok',
            'owner' => null,
            'reason' => null,
            'other_admins' => [],
            'roles' => 0,
            'users' => 0,
            'flag' => null,
        ];

        try {
            $details = $tenant->run(fn (): array => $this->readTenant());
        } catch (Throwable $e) {
            $report['status'] = 'database unavailable';
            $report['flag'] = 'DATABASE MISSING OR UNREADABLE: '.$e->getMessage();

            return $report;
        }

        return [...$report, ...$details];
    }

    /**
     * Runs inside the tenant context. Reads only.
     *
     * @return array{status: string, owner: ?string, reason: ?string, other_admins: list<string>, roles: int, users: int, flag: ?string}
     */
    private function readTenant(): array
    {
        $roles = DB::table('roles')->count();
        $users = DB::table('users')->count();

        $emails = DB::table('users')->pluck('email', 'id');
        $label = fn (int $id): string => "#{$id} ".($emails[$id] ?? '?');

        if (Schema::hasColumn('users', 'is_owner')) {
            $existing = DB::table('users')->where('is_owner', true)->orderBy('id')->value('id');

            if ($existing !== null) {
                return [
                    'status' => 'already has owner',
                    'owner' => $label((int) $existing),
                    'reason' => 'already has owner',
                    'other_admins' => [],
                    'roles' => $roles,
                    'users' => $users,
                    'flag' => null,
                ];
            }
        }

        $candidate = RoleBackfill::ownerCandidate();

        if ($candidate['user_id'] === null) {
            return [
                'status' => 'no owner candidate',
                'owner' => null,
                'reason' => $candidate['reason'],
                'other_admins' => [],
                'roles' => $roles,
                'users' => $users,
                'flag' => 'NO OWNER CANDIDATE ('.$candidate['reason'].')',
            ];
        }

        $others = array_values(array_filter(
            $candidate['active_admin_ids'],
            fn (int $id): bool => $id !== $candidate['user_id'],
        ));

        return [
            'status' => 'would assign owner',
            'owner' => $label($candidate['user_id']),
            'reason' => $candidate['reason'],
            'other_admins' => array_map($label, $others),
            'roles' => $roles,
            'users' => $users,
            'flag' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printReport(array $report): void
    {
        $this->line("Tenant {$report['tenant_id']} | {$report['company']} | contact: ".($report['contact_email'] ?: '(none)'));

        if ($report['status'] === 'already has owner') {
            $this->line("  already has owner: {$report['owner']}");
        } elseif ($report['owner'] !== null) {
            $this->line("  would pick owner: {$report['owner']} ({$report['reason']})");
        }

        if ($report['other_admins'] !== []) {
            $this->line('  other active admins keeping the Admin role: '.implode(', ', $report['other_admins']));
        }

        if ($report['flag'] !== null) {
            $this->error("  FLAG: {$report['flag']}");
        }

        $this->line("  roles: {$report['roles']}, users: {$report['users']}");
    }
}
