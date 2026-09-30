<?php

declare(strict_types=1);

namespace App\Support\Permissions;

/**
 * Starter Manager and Cashier roles for a NEW tenant.
 *
 * These lists are frozen literals on purpose, in the same spirit as
 * RoleBackfill: they are the templates a tenant is provisioned with, not a
 * live policy. Editing them later changes what tenants created afterwards
 * start with and never touches the roles of tenants that already exist (an
 * owner edits their own roles in the role editor). Deriving them from the
 * catalog at runtime would silently widen every future tenant's Manager each
 * time a permission is added, which is exactly the surprise a frozen list
 * avoids. A key added to the catalog later joins a template only by an
 * explicit edit here.
 *
 * Every key that acts on a resource comes with that resource's `.view` key:
 * create forms live on the index pages (todo/permissions/ROUTE-MAP.md, shared
 * lookup endpoint 7), so `X.create` without `X.view` would be a role that can
 * post something but cannot open the page to do it. Keys that hang off
 * another resource's view (`cash_bank_vouchers.create` needs
 * `journal_vouchers.view`, the sales return request keys and unlinked returns
 * need `sales_returns.view`, capital purchase settlements need
 * `capital_purchases.view`) are grouped under that view key below.
 *
 * Owner-only keys never appear in a template (they cannot be granted), and
 * forModules() drops them defensively together with keys of modules the
 * tenant is not entitled to, so a template can never grant a dormant key.
 */
final class RoleTemplates
{
    /**
     * Counter staff: ring up and print sales (bill or POS), take receipts,
     * add a customer on the spot, look items up. Nothing that cancels, nothing
     * that reads reports, ledgers or purchases, no exports.
     *
     * Deliberate omissions and why:
     * - party_ledger.view: a customer's ledger exposes their whole balance
     *   history and supplier dealings; receipts.view already shows what the
     *   cashier collected. Grant it per role when a shop wants it.
     * - accounts.view: the receipt and sale forms do not need the chart.
     * - customers.edit / items.*: creating a customer is needed for a walk-in
     *   quick-add; changing existing masters or prices is not a counter job.
     * - every *.cancel, *.export and report key.
     *
     * @var list<string>
     */
    public const CASHIER = [
        'customers.view',
        'customers.create',
        'items.view',
        'sales.view',
        'sales.create',
        'sales.print',
        'receipts.view',
        'receipts.create',
        'receipts.print',
        'pos.view',
    ];

    /**
     * Day-to-day operations lead: everything operational (view, create, edit,
     * cancel, print, export, import) across the modules plus every report.
     *
     * Deliberate exclusions and why:
     * - users.manage, activity_log.view, print_log.view: administration and
     *   audit belong to the owner, and a manager must not be able to read the
     *   trail of their own actions.
     * - notices.manage: dashboard notices speak for the business.
     * - roles.manage, ownership.transfer, backups.manage,
     *   fiscal_year.close_archive: owner-only, never grantable.
     * - fiscal_year.create, fiscal_year.edit: fiscal year setup and posting
     *   corrections into a reopened year are accounting-control decisions.
     * - accounts.create, accounts.edit, accounts.delete: the chart of accounts
     *   structure; accounts.view stays for lookups.
     * - opening_balances.import, opening_balances.cancel, opening_stock.import:
     *   one-off setup that rewrites starting positions.
     * - every *.delete (customers, suppliers, items, agents, quotations):
     *   deleting a master or draft is destructive; cancelling a document, which
     *   keeps the audit trail, is granted instead.
     * - unlinked_sales_returns.create, unlinked_purchase_returns.create:
     *   returns with no source bill bypass the quantity and price checks a
     *   bill gives, so they stay an explicit grant.
     * - fixed_assets.manage: disposal and depreciation posting move the books.
     *
     * @var list<string>
     */
    public const MANAGER = [
        'accounts.view',
        'customers.view',
        'customers.create',
        'customers.edit',
        'customers.import',
        'suppliers.view',
        'suppliers.create',
        'suppliers.edit',
        'suppliers.import',
        'party_ledger.view',
        'party_ledger.print',
        'party_ledger.export',
        'items.view',
        'items.create',
        'items.edit',
        'items.import',
        'items.print',
        'brands.view',
        'brands.manage',
        'item_categories.view',
        'item_categories.manage',
        'fiscal_year.view',
        'fiscal_year_archive.view',
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
        'quotations.view',
        'quotations.create',
        'quotations.edit',
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
    ];

    /**
     * Keep only the template keys a tenant can actually use: real catalog
     * keys, not owner-only, belonging to an entitled module. Order follows
     * the template.
     *
     * @param  list<string>  $keys  one of the template constants
     * @param  list<string>  $entitledModules  a tenant's resolved modules
     * @return list<string>
     */
    public static function forModules(array $keys, array $entitledModules): array
    {
        return array_values(array_filter(
            $keys,
            fn (string $key): bool => PermissionCatalog::has($key)
                && ! PermissionCatalog::isOwnerOnly($key)
                && in_array(PermissionCatalog::moduleOf($key), $entitledModules, true),
        ));
    }
}
