import {
    Award,
    Banknote,
    Boxes,
    Building2,
    BookOpen,
    CalendarRange,
    ClipboardPen,
    Database,
    FileSignature,
    FileText,
    Factory,
    Handshake,
    HandCoins,
    History,
    LayoutDashboard,
    Landmark,
    Layers,
    Library,
    ListTree,
    Megaphone,
    NotebookPen,
    Package,
    PackageMinus,
    PackageSearch,
    PackageX,
    Printer,
    Receipt,
    Replace,
    ScanBarcode,
    Settings,
    Shapes,
    ShieldCheck,
    ShoppingCart,
    Store,
    Tag,
    Tags,
    TrendingUp,
    Truck,
    Undo2,
    UserCog,
    Users,
    Wallet,
    Wrench,
    ArrowLeftRight,
} from '@lucide/vue';
import { reportPermissions } from '@/lib/report-catalog';

/**
 * Grouped nav for the central (platform-admin) app: flat sections
 * ({ label, items }) with no category tier. `exact` items only match their own href. Was previously copy-pasted verbatim into every
 * Central/*.vue page's own script; AppLayout now builds its nav from this
 * directly instead of taking it as a per-page prop.
 */
export const centralNavItems = [
    { label: 'PLATFORM', items: [
        { label: 'Dashboard', href: '/admin', icon: LayoutDashboard, exact: true },
        { label: 'Tenants', href: '/tenants', icon: Building2 },
    ] },
    { label: 'MONITORING', items: [{ label: 'Activity log', href: '/activity-log', icon: History }] },
    { label: 'ADMINISTRATION', items: [
        { label: 'Platform settings', href: '/settings', icon: Settings },
        { label: 'Platform admins', href: '/platform-admins', icon: ShieldCheck },
    ] },
];

/**
 * Single source of truth for the tenant sidebar nav, organised by what people
 * do (sell, buy, move money, hold stock, ...) rather than by database table.
 *
 * Most sections are flat lists of pages: the sidebar renders each section as
 * an accordion (one open at a time, the section holding the current page
 * opens itself), so the whole menu fits on screen as a handful of headings.
 * Only SETUP has a `categories` tier, shown as plain sub-headings, because it
 * collects the rarely-touched lists (masters, company, system).
 *
 * Reports are not listed one by one here: the sidebar links the Reports hub
 * (lib/report-catalog.js holds every report) plus a few favourites. The
 * command palette and the hub read the catalog directly.
 *
 * Every leaf except the dashboard carries a `permission` catalog key
 * (config/permissions.php, the key of the page's index route) or, for a link
 * that is useful if any of several keys is held, `permissions`. The layout
 * filters leaves by the user's shared `auth.can` and drops categories and
 * sections left empty, so a disabled module or a missing grant hides its
 * pages. This only hides links: the server `can:` middleware is the authority.
 */
export function navGroups() {
    return [
        {
            // No heading: the dashboard is the home link, always visible.
            label: '',
            items: [{ label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard }],
        },
        {
            label: 'SELL',
            items: [
                { label: 'POS', href: '/pos', icon: ScanBarcode, permission: 'pos.view' },
                { label: 'Quotations', href: '/quotations', icon: FileSignature, permission: 'quotations.view' },
                { label: 'Sales', href: '/sales', icon: ShoppingCart, permission: 'sales.view' },
                { label: 'Capital Sales', href: '/capital-sales', icon: Landmark, permission: 'capital_sales.view' },
                { label: 'Sales Returns', href: '/sales-returns', icon: Undo2, permission: 'sales_returns.view' },
            ],
        },
        {
            label: 'BUY',
            items: [
                { label: 'Purchases', href: '/purchases', icon: PackageSearch, permission: 'purchases.view' },
                { label: 'Capital & Service', href: '/capital-purchases', icon: Wrench, permission: 'capital_purchases.view' },
                { label: 'Purchase Returns', href: '/purchase-returns', icon: PackageX, permission: 'purchase_returns.view' },
            ],
        },
        {
            label: 'MONEY',
            items: [
                { label: 'Receipts', href: '/receipts', icon: Banknote, permission: 'receipts.view' },
                { label: 'Payments', href: '/payments', icon: Wallet, permission: 'payments.view' },
                { label: 'Journal Vouchers', href: '/journal-vouchers', icon: NotebookPen, permission: 'journal_vouchers.view' },
            ],
        },
        {
            label: 'INVENTORY',
            items: [
                { label: 'Items', href: '/items', icon: Package, permission: 'items.view' },
                { label: 'Stock Adjustments', href: '/stock-adjustments', icon: ClipboardPen, permission: 'stock_adjustments.view' },
                { label: 'Stock Transfers', href: '/stock-transfers', icon: ArrowLeftRight, permission: 'stock_transfers.view' },
                { label: 'Refining & Repackaging', href: '/stock-conversions', icon: Replace, permission: 'stock_conversions.view' },
            ],
        },
        {
            label: 'MANUFACTURING',
            items: [
                { label: 'Material Issues', href: '/material-issues', icon: PackageMinus, permission: 'material_issues.view' },
                { label: 'Production Entries', href: '/production-entries', icon: Factory, permission: 'production_entries.view' },
            ],
        },
        {
            label: 'PARTIES',
            items: [
                { label: 'Customers', href: '/customers', icon: Users, permission: 'customers.view' },
                { label: 'Suppliers', href: '/suppliers', icon: Truck, permission: 'suppliers.view' },
                { label: 'Sales Agents', href: '/agents', icon: Handshake, permission: 'agents.view' },
            ],
        },
        {
            label: 'ACCOUNTING',
            items: [
                { label: 'Accounts', href: '/accounts', icon: BookOpen, permission: 'accounts.view' },
                { label: 'Fiscal Years', href: '/fiscal-years', icon: CalendarRange, permission: 'fiscal_year.view' },
                { label: 'Fixed Assets', href: '/fixed-assets', icon: HandCoins, permission: 'fixed_assets.view' },
            ],
        },
        {
            label: 'REPORTS',
            // Most reports have no sidebar link of their own; on those pages
            // the section stays open and "All reports" is the highlighted link.
            activePrefix: '/reports',
            items: [
                // Any-of: the hub is useful to anyone who can open at least one report.
                { label: 'All reports', href: '/reports', icon: FileText, permissions: reportPermissions(), exact: true },
                { label: 'Ledger', href: '/reports/ledger', icon: Library, permission: 'account_ledger.view' },
                { label: 'Sales Register', href: '/reports/sales-register', icon: TrendingUp, permission: 'sales_reports.view' },
                { label: 'Stock Summary', href: '/reports/stock-summary', icon: Boxes, permission: 'stock_reports.view' },
                { label: 'VAT Summary', href: '/reports/vat-summary', icon: Receipt, permission: 'tax_reports.view' },
            ],
        },
        {
            label: 'SETUP',
            categories: [
                {
                    label: 'Masters',
                    items: [
                        { label: 'Stores', href: '/stores', icon: Store, permission: 'stores.view' },
                        { label: 'Item Categories', href: '/item-categories', icon: Tags, permission: 'item_categories.view' },
                        { label: 'Item Subcategories', href: '/item-subcategories', icon: Tag, permission: 'item_categories.view' },
                        { label: 'Item Varieties', href: '/item-varieties', icon: Shapes, permission: 'item_varieties.view' },
                        { label: 'Brands', href: '/brands', icon: Award, permission: 'brands.view' },
                        { label: 'Account Groups', href: '/account-groups', icon: Layers, permission: 'accounts.view' },
                        { label: 'Account Subgroups', href: '/account-subgroups', icon: ListTree, permission: 'accounts.view' },
                    ],
                },
                {
                    label: 'Company & Access',
                    items: [
                        { label: 'Company Setup', href: '/settings', icon: Settings, permission: 'settings.manage' },
                        { label: 'Manage users', href: '/admin/users', icon: UserCog, permission: 'users.manage' },
                        { label: 'Roles & permissions', href: '/admin/roles', icon: ShieldCheck, permission: 'roles.manage' },
                    ],
                },
                {
                    label: 'System',
                    items: [
                        { label: 'Notices', href: '/notices', icon: Megaphone, permission: 'notices.manage' },
                        { label: 'Activity Log', href: '/activity-log', icon: History, permission: 'activity_log.view' },
                        { label: 'Print Log', href: '/reports/print-log', icon: Printer, permission: 'print_log.view' },
                        { label: 'Backups', href: '/backups', icon: Database, permission: 'backups.manage' },
                    ],
                },
            ],
        },
    ];
}
