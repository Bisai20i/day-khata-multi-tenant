import {
    LayoutDashboard,
    Layers,
    ListTree,
    BookOpen,
    Users,
    Truck,
    Tags,
    Tag,
    Package,
    UserCog,
    CalendarRange,
    NotebookPen,
    ShoppingCart,
    PackageSearch,
    ClipboardList,
    Undo2,
    Building2,
    FileSignature,
    Banknote,
    Wallet,
    Store,
    Settings,
    History,
    Printer,
    Database,
    Megaphone,
    Shapes,
    ScanBarcode,
    Handshake,
    Landmark,
    ArrowLeftRight,
    Factory,
    Award,
    ShieldCheck,
    Calculator,
    Receipt,
    TrendingUp,
    ShoppingBag,
    Boxes,
} from '@lucide/vue';

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
 * Single source of truth for the tenant sidebar nav, grouped to match the
 * approved enterprise UI direction. Previously each page hardcoded its own
 * flat copy of this list; keeping one source means adding/renaming a
 * resource only happens in one place.
 *
 * Three tiers: a top-level section (plain label, never collapses), an
 * optional category tier (collapsible, only added once a section has enough
 * items to be worth sub-grouping), and leaf pages. A section with only a
 * couple of items skips the category tier entirely and lists its pages
 * directly under the section label - see `items` vs `categories` below.
 *
 * Every leaf except the dashboard carries a `permission` catalog key
 * (config/permissions.php, the key of the page's index route). The layout
 * filters leaves by the user's shared `auth.can` and drops categories and
 * sections left empty, so a disabled module or a missing grant hides its
 * pages. This only hides links: the server `can:` middleware is the authority.
 */
export function navGroups() {
    return [
        {
            label: 'OVERVIEW',
            items: [{ label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard }],
        },
        {
            label: 'TRANSACTIONS',
            categories: [
                {
                    label: 'Selling',
                    items: [
                        { label: 'POS', href: '/pos', icon: ScanBarcode, permission: 'pos.view' },
                        { label: 'Quotations', href: '/quotations', icon: FileSignature, permission: 'quotations.view' },
                        { label: 'Sales', href: '/sales', icon: ShoppingCart, permission: 'sales.view' },
                        { label: 'Capital Sales', href: '/capital-sales', icon: Landmark, permission: 'capital_sales.view' },
                        { label: 'Sales Returns', href: '/sales-returns', icon: Undo2, permission: 'sales_returns.view' },
                    ],
                },
                {
                    label: 'Buying',
                    items: [
                        { label: 'Purchases', href: '/purchases', icon: PackageSearch, permission: 'purchases.view' },
                        { label: 'Capital Purchases', href: '/capital-purchases', icon: Landmark, permission: 'capital_purchases.view' },
                        { label: 'Purchase Returns', href: '/purchase-returns', icon: Undo2, permission: 'purchase_returns.view' },
                    ],
                },
                {
                    label: 'Receipts & Payments',
                    items: [
                        { label: 'Receipts', href: '/receipts', icon: Banknote, permission: 'receipts.view' },
                        { label: 'Payments', href: '/payments', icon: Wallet, permission: 'payments.view' },
                    ],
                },
            ],
        },
        {
            label: 'ACCOUNTING',
            categories: [
                {
                    label: 'Chart of Accounts',
                    items: [
                        { label: 'Account Groups', href: '/account-groups', icon: Layers, permission: 'accounts.view' },
                        { label: 'Account Subgroups', href: '/account-subgroups', icon: ListTree, permission: 'accounts.view' },
                        { label: 'Accounts', href: '/accounts', icon: BookOpen, permission: 'accounts.view' },
                    ],
                },
                {
                    label: 'Fiscal & Vouchers',
                    items: [
                        { label: 'Fiscal Years', href: '/fiscal-years', icon: CalendarRange, permission: 'fiscal_year.view' },
                        { label: 'Journal Vouchers', href: '/journal-vouchers', icon: NotebookPen, permission: 'journal_vouchers.view' },
                    ],
                },
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
            label: 'INVENTORY',
            categories: [
                {
                    label: 'Catalog',
                    items: [
                        { label: 'Item Categories', href: '/item-categories', icon: Tags, permission: 'item_categories.view' },
                        { label: 'Item Varieties', href: '/item-varieties', icon: Shapes, permission: 'item_varieties.view' },
                        { label: 'Item Subcategories', href: '/item-subcategories', icon: Tag, permission: 'item_categories.view' },
                        { label: 'Items', href: '/items', icon: Package, permission: 'items.view' },
                        { label: 'Brands', href: '/brands', icon: Award, permission: 'brands.view' },
                    ],
                },
                {
                    label: 'Stock Operations',
                    items: [
                        { label: 'Stores', href: '/stores', icon: Store, permission: 'stores.view' },
                        { label: 'Stock Adjustments', href: '/stock-adjustments', icon: ClipboardList, permission: 'stock_adjustments.view' },
                        { label: 'Stock Transfers', href: '/stock-transfers', icon: ArrowLeftRight, permission: 'stock_transfers.view' },
                        { label: 'Production & Refining', href: '/stock-conversions', icon: Factory, permission: 'stock_conversions.view' },
                    ],
                },
            ],
        },
        {
            label: 'ASSETS',
            items: [{ label: 'Fixed Assets', href: '/fixed-assets', icon: Building2, permission: 'fixed_assets.view' }],
        },
        {
            label: 'REPORTS',
            categories: [
                {
                    label: 'Account Reports',
                    items: [
                        { label: 'Trial Balance', href: '/reports/trial-balance', icon: Calculator, permission: 'financial_statements.view' },
                        { label: 'Income Statement', href: '/reports/income-statement', icon: Calculator, permission: 'financial_statements.view' },
                        { label: 'Balance Sheet', href: '/reports/balance-sheet', icon: Calculator, permission: 'financial_statements.view' },
                        { label: 'TDS Report', href: '/reports/tds', icon: Calculator, permission: 'tax_reports.view' },
                        { label: 'Day Book', href: '/reports/day-book', icon: Calculator, permission: 'day_book.view' },
                        { label: 'Cash Book', href: '/reports/cash-book', icon: Calculator, permission: 'cash_bank_book.view' },
                        { label: 'Bank Book', href: '/reports/bank-book', icon: Calculator, permission: 'cash_bank_book.view' },
                        { label: 'Aged Receivables', href: '/reports/aged-receivables', icon: Calculator, permission: 'receivables_reports.view' },
                        { label: 'Aged Payables', href: '/reports/aged-payables', icon: Calculator, permission: 'payables_reports.view' },
                        { label: 'Debtors', href: '/reports/debtors', icon: Calculator, permission: 'receivables_reports.view' },
                        { label: 'Creditors', href: '/reports/creditors', icon: Calculator, permission: 'payables_reports.view' },
                    ],
                },
                {
                    label: 'VAT Reports',
                    items: [
                        { label: 'Sales VAT Book', href: '/reports/sales-vat-book', icon: Receipt, permission: 'tax_reports.view' },
                        { label: 'Purchase VAT Book', href: '/reports/purchase-vat-book', icon: Receipt, permission: 'tax_reports.view' },
                        { label: 'Sales Return Register', href: '/reports/sales-return-register', icon: Receipt, permission: 'sales_reports.view' },
                        { label: 'Purchase Return Register', href: '/reports/purchase-return-register', icon: Receipt, permission: 'purchase_reports.view' },
                        { label: 'VAT Summary', href: '/reports/vat-summary', icon: Receipt, permission: 'tax_reports.view' },
                    ],
                },
                {
                    label: 'Sales Reports',
                    items: [
                        { label: 'Sales Register', href: '/reports/sales-register', icon: TrendingUp, permission: 'sales_reports.view' },
                        { label: 'Item-wise Sales', href: '/reports/item-wise-sales', icon: TrendingUp, permission: 'sales_reports.view' },
                        { label: 'Sales by Category', href: '/reports/sales-by-category', icon: TrendingUp, permission: 'sales_reports.view' },
                        { label: 'Sales With Note', href: '/reports/sales-with-note', icon: TrendingUp, permission: 'sales_reports.view' },
                    ],
                },
                {
                    label: 'Purchase Reports',
                    items: [
                        { label: 'Purchase Register', href: '/reports/purchase-register', icon: ShoppingBag, permission: 'purchase_reports.view' },
                        { label: 'Item-wise Purchase', href: '/reports/item-wise-purchase', icon: ShoppingBag, permission: 'purchase_reports.view' },
                        { label: 'Purchase by Category', href: '/reports/purchase-by-category', icon: ShoppingBag, permission: 'purchase_reports.view' },
                    ],
                },
                {
                    label: 'Stock Reports',
                    items: [
                        { label: 'Stock Summary', href: '/reports/stock-summary', icon: Boxes, permission: 'stock_reports.view' },
                        { label: 'Stock Valuation', href: '/reports/stock-valuation', icon: Boxes, permission: 'stock_valuation.view' },
                        { label: 'Stock by Category', href: '/reports/stock-by-category', icon: Boxes, permission: 'stock_reports.view' },
                        { label: 'Stock Movement Register', href: '/reports/stock-movement-register', icon: Boxes, permission: 'stock_reports.view' },
                        { label: 'Damage & Lost Stock', href: '/reports/damage-lost-stock', icon: Boxes, permission: 'stock_reports.view' },
                        { label: 'Stock by Brand', href: '/reports/stock-by-brand', icon: Boxes, permission: 'stock_reports.view' },
                    ],
                },
            ],
        },
        {
            label: 'ADMIN',
            categories: [
                {
                    label: 'Users & Access',
                    items: [
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
