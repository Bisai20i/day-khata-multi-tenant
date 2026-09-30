<?php

declare(strict_types=1);

namespace App\Support\Permissions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time move of an existing tenant from the legacy admin/staff role split
 * to the JSON role-permission model (plans/roles-permissions-entitlements.md
 * sections 5 and 6). Called by the tenant data migration
 * 2026_09_25_020100_backfill_role_permissions_and_owner.
 *
 * The key lists below are FROZEN: they are copied literally from
 * config/permissions.php and todo/permissions/ROUTE-MAP.md ("Staff parity
 * keys") as of the rollout, and this class never reads the live catalog.
 * A later catalog change (a new key, a rename, a key flipped to owner-only)
 * must not change what an already-migrated tenant received, and a tenant
 * migrated late must receive exactly what an early one did. A later release
 * that adds keys grants them to existing roles in its own data migration.
 * Never edit these lists after release; RoleBackfillTest pins that every key
 * still exists in the catalog, so a rename fails loudly instead of silently
 * granting a dead key.
 *
 * run() writes through the query builder, not Eloquent, so no model events,
 * observers or activity logging fire inside tenants:migrate, and so later
 * changes to the Role/User models cannot alter what a migration does.
 */
final class RoleBackfill
{
    /**
     * Every non-owner-only key in config/permissions.php at rollout (143 of
     * 147), in config order. The four owner-only keys (fiscal_year.close_archive,
     * roles.manage, ownership.transfer, backups.manage) are never stored in a
     * role: they pass only for the tenant owner.
     *
     * @var list<string>
     */
    public const GRANTABLE_AT_ROLLOUT = [
        'accounts.view',
        'accounts.create',
        'accounts.edit',
        'accounts.delete',
        'opening_balances.import',
        'opening_balances.cancel',
        'customers.view',
        'customers.create',
        'customers.edit',
        'customers.delete',
        'customers.import',
        'suppliers.view',
        'suppliers.create',
        'suppliers.edit',
        'suppliers.delete',
        'suppliers.import',
        'party_ledger.view',
        'party_ledger.print',
        'party_ledger.export',
        'items.view',
        'items.create',
        'items.edit',
        'items.delete',
        'items.import',
        'items.print',
        'brands.view',
        'brands.manage',
        'item_categories.view',
        'item_categories.manage',
        'fiscal_year.view',
        'fiscal_year.create',
        'fiscal_year.edit',
        'fiscal_year_archive.view',
        'users.manage',
        'sales.view',
        'sales.create',
        'sales.print',
        'sales.export',
        'sales.cancel',
        'capital_sales.view',
        'capital_sales.create',
        'capital_sales.print',
        'capital_sales.cancel',
        'sales_returns.view',
        'sales_returns.create',
        'sales_return_requests.create',
        'sales_return_requests.manage',
        'unlinked_sales_returns.create',
        'sales_returns.print',
        'sales_returns.cancel',
        'receipts.view',
        'receipts.create',
        'receipts.print',
        'receipts.cancel',
        'pos.view',
        'agents.view',
        'agents.create',
        'agents.edit',
        'agents.delete',
        'quotations.view',
        'quotations.create',
        'quotations.edit',
        'quotations.delete',
        'quotations.print',
        'quotations.cancel',
        'purchases.view',
        'purchases.create',
        'purchases.print',
        'purchases.export',
        'purchases.cancel',
        'capital_purchases.view',
        'capital_purchases.create',
        'capital_purchases.print',
        'capital_purchases.export',
        'capital_purchases.cancel',
        'capital_purchase_settlements.create',
        'capital_purchase_settlements.cancel',
        'purchase_returns.view',
        'purchase_returns.create',
        'unlinked_purchase_returns.create',
        'purchase_returns.print',
        'purchase_returns.export',
        'purchase_returns.cancel',
        'payments.view',
        'payments.create',
        'payments.cancel',
        'stock_adjustments.view',
        'stock_adjustments.create',
        'stock_adjustments.print',
        'stock_adjustments.cancel',
        'opening_stock.import',
        'stock_transfers.view',
        'stock_transfers.create',
        'stock_transfers.print',
        'stock_transfers.cancel',
        'stock_conversions.view',
        'stock_conversions.create',
        'stock_conversions.print',
        'stock_conversions.cancel',
        'stores.view',
        'stores.manage',
        'item_varieties.view',
        'item_varieties.manage',
        'journal_vouchers.view',
        'journal_vouchers.create',
        'cash_bank_vouchers.create',
        'journal_vouchers.print',
        'journal_vouchers.export',
        'journal_vouchers.cancel',
        'account_ledger.view',
        'account_ledger.print',
        'account_ledger.export',
        'fixed_assets.view',
        'fixed_assets.create',
        'fixed_assets.manage',
        'financial_statements.view',
        'financial_statements.print',
        'financial_statements.export',
        'day_book.view',
        'day_book.print',
        'day_book.export',
        'cash_bank_book.view',
        'cash_bank_book.print',
        'cash_bank_book.export',
        'cancelled_documents.view',
        'cancelled_documents.export',
        'sales_reports.view',
        'sales_reports.export',
        'purchase_reports.view',
        'purchase_reports.export',
        'receivables_reports.view',
        'receivables_reports.export',
        'payables_reports.view',
        'payables_reports.export',
        'tax_reports.view',
        'tax_reports.export',
        'stock_reports.view',
        'stock_reports.print',
        'stock_reports.export',
        'stock_valuation.view',
        'activity_log.view',
        'print_log.view',
        'notices.manage',
    ];

    /**
     * Exactly the 100 keys whose routes were reachable by any authenticated
     * user (not behind role:admin) before rollout, in config order, so the
     * legacy Staff role loses nothing and gains nothing.
     *
     * @var list<string>
     */
    public const STAFF_PARITY = [
        'accounts.view',
        'customers.view',
        'customers.create',
        'customers.edit',
        'customers.delete',
        'customers.import',
        'suppliers.view',
        'suppliers.create',
        'suppliers.edit',
        'suppliers.delete',
        'suppliers.import',
        'party_ledger.view',
        'party_ledger.print',
        'party_ledger.export',
        'items.view',
        'items.create',
        'items.edit',
        'items.delete',
        'items.import',
        'items.print',
        'brands.view',
        'brands.manage',
        'item_categories.view',
        'item_categories.manage',
        'fiscal_year.view',
        'fiscal_year.create',
        'sales.view',
        'sales.create',
        'sales.print',
        'sales.export',
        'capital_sales.view',
        'capital_sales.create',
        'capital_sales.print',
        'sales_returns.view',
        'sales_returns.create',
        'sales_return_requests.create',
        'sales_returns.print',
        'receipts.view',
        'receipts.create',
        'receipts.print',
        'pos.view',
        'agents.view',
        'agents.create',
        'agents.edit',
        'agents.delete',
        'quotations.view',
        'quotations.create',
        'quotations.edit',
        'quotations.delete',
        'quotations.print',
        'quotations.cancel',
        'purchases.view',
        'purchases.create',
        'purchases.print',
        'purchases.export',
        'capital_purchases.view',
        'capital_purchases.create',
        'capital_purchases.print',
        'capital_purchases.export',
        'capital_purchase_settlements.create',
        'purchase_returns.view',
        'purchase_returns.create',
        'purchase_returns.print',
        'purchase_returns.export',
        'payments.view',
        'payments.create',
        'stock_adjustments.view',
        'stock_adjustments.create',
        'stock_adjustments.print',
        'opening_stock.import',
        'stock_transfers.view',
        'stock_transfers.create',
        'stock_transfers.print',
        'stock_conversions.view',
        'stock_conversions.create',
        'stock_conversions.print',
        'stores.view',
        'stores.manage',
        'item_varieties.view',
        'item_varieties.manage',
        'journal_vouchers.view',
        'journal_vouchers.print',
        'journal_vouchers.export',
        'account_ledger.view',
        'account_ledger.print',
        'account_ledger.export',
        'sales_reports.view',
        'sales_reports.export',
        'purchase_reports.view',
        'purchase_reports.export',
        'receivables_reports.view',
        'receivables_reports.export',
        'payables_reports.view',
        'payables_reports.export',
        'tax_reports.view',
        'tax_reports.export',
        'stock_reports.view',
        'stock_reports.print',
        'stock_reports.export',
        'stock_valuation.view',
    ];

    /**
     * Idempotent: roles are only filled while their permissions are NULL (an
     * already backfilled or user-edited role is never overwritten), and the
     * owner is only picked while no user is flagged as owner. Never throws for
     * a tenant with missing roles or no eligible admin; that tenant simply gets
     * no owner, which the rollout dry run surfaces.
     */
    public static function run(): void
    {
        if (! Schema::hasColumn('roles', 'permissions') || ! Schema::hasColumn('users', 'is_owner')) {
            return;
        }

        DB::transaction(function (): void {
            self::backfillRoles();
            self::assignOwner();
        });
    }

    /**
     * admin gets every grantable key and is marked as a system role, staff
     * gets the parity list, and any other role gets an empty list (it had no
     * route-level meaning before rollout; only the admin slug did).
     */
    private static function backfillRoles(): void
    {
        DB::table('roles')
            ->where('slug', 'admin')
            ->whereNull('permissions')
            ->update([
                'permissions' => json_encode(self::GRANTABLE_AT_ROLLOUT),
                'is_system' => true,
            ]);

        DB::table('roles')
            ->where('slug', 'staff')
            ->whereNull('permissions')
            ->update(['permissions' => json_encode(self::STAFF_PARITY)]);

        DB::table('roles')
            ->whereNull('permissions')
            ->update(['permissions' => json_encode([])]);
    }

    /**
     * The owner is the active admin-role user whose email matches the
     * tenant's contact email (case-insensitive), else the lowest-id active
     * admin-role user, else nobody. Deactivated users are never picked: an
     * owner who cannot log in could not manage roles or transfer ownership.
     */
    private static function assignOwner(): void
    {
        if (DB::table('users')->where('is_owner', true)->exists()) {
            return;
        }

        $candidate = self::ownerCandidate();

        if ($candidate['user_id'] === null) {
            return;
        }

        DB::table('users')->where('id', $candidate['user_id'])->update(['is_owner' => true]);
    }

    /**
     * Read-only owner selection shared by run() and the permissions:owner-dry-run
     * command, so the dry run can never diverge from the real backfill. Only
     * reads roles, users (role_id, email, is_active) and the current tenant's
     * contact email; works before the is_owner column exists. Does not check
     * for an existing owner (run() does that first).
     *
     * reason is "contact email match", "lowest-id active admin", "no admin role"
     * or "no active admin"; user_id is null for the last two. active_admin_ids
     * lists every active admin-role user id in ascending order.
     *
     * @return array{user_id: ?int, reason: string, active_admin_ids: list<int>}
     */
    public static function ownerCandidate(): array
    {
        $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($adminRoleId === null) {
            return ['user_id' => null, 'reason' => 'no admin role', 'active_admin_ids' => []];
        }

        $candidates = DB::table('users')
            ->where('role_id', $adminRoleId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'email']);

        $activeAdminIds = $candidates->map(fn (object $user): int => (int) $user->id)->values()->all();

        if ($candidates->isEmpty()) {
            return ['user_id' => null, 'reason' => 'no active admin', 'active_admin_ids' => []];
        }

        $contactEmail = self::normalizedContactEmail();

        $owner = $contactEmail === null
            ? null
            : $candidates->first(fn (object $user): bool => mb_strtolower(trim((string) $user->email)) === $contactEmail);

        if ($owner !== null) {
            return ['user_id' => (int) $owner->id, 'reason' => 'contact email match', 'active_admin_ids' => $activeAdminIds];
        }

        return ['user_id' => (int) $candidates->first()->id, 'reason' => 'lowest-id active admin', 'active_admin_ids' => $activeAdminIds];
    }

    /**
     * The current tenant's contact email, lowercased and trimmed, or null
     * when tenancy is not initialized or the tenant has no contact email.
     */
    private static function normalizedContactEmail(): ?string
    {
        if (! tenancy()->initialized) {
            return null;
        }

        $email = mb_strtolower(trim((string) tenant('contact_email')));

        return $email === '' ? null : $email;
    }
}
