<?php

namespace App\Support\Branches;

use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\StockTransfer;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Why a tenant may not have the `branches` module switched off (plan 12,
 * BR24, section 7 "Rollback").
 *
 * Switching the module off makes every screen resolve to Main again. That is
 * only lossless while the company really is Main-only; once it has more than
 * Main, branch-limited users, goods in transit between branches or a year
 * numbered per branch, turning the module off would hide data, strand stock
 * and leave per-branch series unreachable. The central panel therefore
 * refuses the switch-off while any blocker below exists. Switching the module
 * ON is never blocked.
 *
 * Read only, and every read runs inside BranchContext::unrestricted(): the
 * answer is a fact about the company, not about what one user may see. That
 * also lets the BR23 `branches:audit` command reuse blockers() as is, from
 * inside tenancy. Each check is skipped when its table or column does not
 * exist yet (a tenant database not migrated to the branch schema cannot use
 * more than Main).
 */
final class BranchModuleGuard
{
    public const EXTRA_BRANCHES = 'extra_branches';

    public const RESTRICTED_USERS = 'restricted_users';

    public const TRANSFERS_IN_TRANSIT = 'transfers_in_transit';

    public const PER_BRANCH_NUMBERING = 'per_branch_numbering';

    public const UNREADABLE = 'unreadable';

    /**
     * The blockers for the tenant whose database is current (call inside
     * tenancy), keyed by the constants above, each value one sentence that
     * names branches by the company's own word (branch, site, outlet or
     * project). Empty means the module may be switched off.
     *
     * @return array<string, string>
     */
    public static function blockers(): array
    {
        return BranchContext::unrestricted(function (): array {
            $label = self::label();
            $plural = Str::plural($label);
            $blockers = [];

            if (Schema::hasTable('branches')) {
                $branchCount = Branch::query()->count();

                if ($branchCount > 1) {
                    $extra = $branchCount - 1;
                    $blockers[self::EXTRA_BRANCHES] = "It has {$extra} ".($extra === 1 ? $label : $plural).' besides Main.';
                }
            }

            $restrictedUsers = self::restrictedUserCount();

            if ($restrictedUsers > 0) {
                $blockers[self::RESTRICTED_USERS] = $restrictedUsers === 1
                    ? "1 user is limited to particular {$plural}."
                    : "{$restrictedUsers} users are limited to particular {$plural}.";
            }

            if (Schema::hasTable('stock_transfers') && Schema::hasColumn('stock_transfers', 'status')) {
                $inTransit = StockTransfer::query()->where('status', StockTransfer::STATUS_IN_TRANSIT)->count();

                if ($inTransit > 0) {
                    $blockers[self::TRANSFERS_IN_TRANSIT] = $inTransit === 1
                        ? "1 stock transfer between {$plural} is still in transit."
                        : "{$inTransit} stock transfers between {$plural} are still in transit.";
                }
            }

            if (Schema::hasTable('fiscal_years') && Schema::hasColumn('fiscal_years', 'numbering_scope')) {
                $years = FiscalYear::query()
                    ->where('numbering_scope', FiscalYear::NUMBERING_PER_BRANCH)
                    ->orderBy('start_date')
                    ->pluck('name')
                    ->map(fn (mixed $name): string => (string) $name)
                    ->all();

                if ($years !== []) {
                    $blockers[self::PER_BRANCH_NUMBERING] = count($years) === 1
                        ? "Fiscal year {$years[0]} numbers documents separately per {$label}."
                        : 'Fiscal years '.implode(', ', $years)." number documents separately per {$label}.";
                }
            }

            return $blockers;
        });
    }

    /**
     * blockers() for $tenant, run inside its database from central code. A
     * tenant whose database does not exist (provisioning failed or pending)
     * holds no branch data, so nothing blocks. A database that exists but
     * cannot be read fails closed: the switch-off is refused rather than
     * risked on an unchecked company.
     *
     * @return array<string, string>
     */
    public static function forTenant(Tenant $tenant): array
    {
        $original = tenant();

        try {
            if (! $tenant->databaseExists()) {
                return [];
            }

            return $tenant->run(fn (): array => self::blockers());
        } catch (Throwable $exception) {
            report($exception);

            // Tenant::run() has no finally: a throwing callback leaves the
            // tenant's database as the default connection (sessions, the
            // next central query). Put the caller's context back.
            if ($original !== null) {
                tenancy()->initialize($original);
            } elseif (tenancy()->initialized) {
                tenancy()->end();
            }

            return [self::UNREADABLE => 'Its data could not be checked, so it is not safe to switch off. Try again in a moment.'];
        }
    }

    /**
     * Users limited to particular branches: all_branches switched off, or
     * holding branch_user rows. Counted once each, inactive users included,
     * because their restriction is still stored data the module owns.
     */
    private static function restrictedUserCount(): int
    {
        $hasFlag = Schema::hasTable('users') && Schema::hasColumn('users', 'all_branches');
        $hasPivot = Schema::hasTable('branch_user');

        if (! $hasFlag && ! $hasPivot) {
            return 0;
        }

        return DB::table('users')
            ->where(function ($query) use ($hasFlag, $hasPivot): void {
                if ($hasFlag) {
                    $query->orWhere('all_branches', false);
                }

                if ($hasPivot) {
                    $query->orWhereIn('id', DB::table('branch_user')->select('user_id'));
                }
            })
            ->count();
    }

    /**
     * The company's own word for a branch, lower case ("branch" when none is
     * set or company settings cannot be read).
     */
    private static function label(): string
    {
        if (! Schema::hasTable('company_settings') || ! Schema::hasColumn('company_settings', 'branch_label')) {
            return ActiveBranchSelection::LABELS[0];
        }

        return strtolower(ActiveBranchSelection::label());
    }
}
