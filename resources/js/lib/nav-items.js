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
 */
export function navGroups(isAdmin) {
    const groups = [
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
                        { label: 'POS', href: '/pos', icon: ScanBarcode },
                        { label: 'Quotations', href: '/quotations', icon: FileSignature },
                        { label: 'Sales', href: '/sales', icon: ShoppingCart },
                        { label: 'Capital Sales', href: '/capital-sales', icon: Landmark },
                        { label: 'Sales Returns', href: '/sales-returns', icon: Undo2 },
                    ],
                },
                {
                    label: 'Buying',
                    items: [
                        { label: 'Purchases', href: '/purchases', icon: PackageSearch },
                        { label: 'Capital Purchases', href: '/capital-purchases', icon: Landmark },
                        { label: 'Purchase Returns', href: '/purchase-returns', icon: Undo2 },
                    ],
                },
                {
                    label: 'Receipts & Payments',
                    items: [
                        { label: 'Receipts', href: '/receipts', icon: Banknote },
                        { label: 'Payments', href: '/payments', icon: Wallet },
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
                        { label: 'Account Groups', href: '/account-groups', icon: Layers },
                        { label: 'Account Subgroups', href: '/account-subgroups', icon: ListTree },
                        { label: 'Accounts', href: '/accounts', icon: BookOpen },
                    ],
                },
                {
                    label: 'Fiscal & Vouchers',
                    items: [
                        { label: 'Fiscal Years', href: '/fiscal-years', icon: CalendarRange },
                        { label: 'Journal Vouchers', href: '/journal-vouchers', icon: NotebookPen },
                    ],
                },
            ],
        },
        {
            label: 'PARTIES',
            items: [
                { label: 'Customers', href: '/customers', icon: Users },
                { label: 'Suppliers', href: '/suppliers', icon: Truck },
                { label: 'Sales Agents', href: '/agents', icon: Handshake },
            ],
        },
        {
            label: 'INVENTORY',
            categories: [
                {
                    label: 'Catalog',
                    items: [
                        { label: 'Item Categories', href: '/item-categories', icon: Tags },
                        { label: 'Item Varieties', href: '/item-varieties', icon: Shapes },
                        { label: 'Item Subcategories', href: '/item-subcategories', icon: Tag },
                        { label: 'Items', href: '/items', icon: Package },
                        { label: 'Brands', href: '/brands', icon: Award },
                    ],
                },
                {
                    label: 'Stock Operations',
                    items: [
                        { label: 'Stores', href: '/stores', icon: Store },
                        { label: 'Stock Adjustments', href: '/stock-adjustments', icon: ClipboardList },
                        { label: 'Stock Transfers', href: '/stock-transfers', icon: ArrowLeftRight },
                        { label: 'Production & Refining', href: '/stock-conversions', icon: Factory },
                    ],
                },
            ],
        },
        {
            label: 'ASSETS',
            items: [{ label: 'Fixed Assets', href: '/fixed-assets', icon: Building2 }],
        },
        {
            label: 'REPORTS',
            categories: [
                {
                    label: 'Account Reports',
                    items: [
                        { label: 'Trial Balance', href: '/reports/trial-balance', icon: Calculator },
                        { label: 'Income Statement', href: '/reports/income-statement', icon: Calculator },
                        { label: 'Balance Sheet', href: '/reports/balance-sheet', icon: Calculator },
                        { label: 'TDS Report', href: '/reports/tds', icon: Calculator },
                        { label: 'Day Book', href: '/reports/day-book', icon: Calculator },
                        { label: 'Cash Book', href: '/reports/cash-book', icon: Calculator },
                        { label: 'Bank Book', href: '/reports/bank-book', icon: Calculator },
                        { label: 'Aged Receivables', href: '/reports/aged-receivables', icon: Calculator },
                        { label: 'Aged Payables', href: '/reports/aged-payables', icon: Calculator },
                        { label: 'Debtors', href: '/reports/debtors', icon: Calculator },
                        { label: 'Creditors', href: '/reports/creditors', icon: Calculator },
                    ],
                },
                {
                    label: 'VAT Reports',
                    items: [
                        { label: 'Sales VAT Book', href: '/reports/sales-vat-book', icon: Receipt },
                        { label: 'Purchase VAT Book', href: '/reports/purchase-vat-book', icon: Receipt },
                        { label: 'Sales Return Register', href: '/reports/sales-return-register', icon: Receipt },
                        { label: 'Purchase Return Register', href: '/reports/purchase-return-register', icon: Receipt },
                        { label: 'VAT Summary', href: '/reports/vat-summary', icon: Receipt },
                    ],
                },
                {
                    label: 'Sales Reports',
                    items: [
                        { label: 'Sales Register', href: '/reports/sales-register', icon: TrendingUp },
                        { label: 'Item-wise Sales', href: '/reports/item-wise-sales', icon: TrendingUp },
                        { label: 'Sales by Category', href: '/reports/sales-by-category', icon: TrendingUp },
                        { label: 'Sales With Note', href: '/reports/sales-with-note', icon: TrendingUp },
                    ],
                },
                {
                    label: 'Purchase Reports',
                    items: [
                        { label: 'Purchase Register', href: '/reports/purchase-register', icon: ShoppingBag },
                        { label: 'Item-wise Purchase', href: '/reports/item-wise-purchase', icon: ShoppingBag },
                        { label: 'Purchase by Category', href: '/reports/purchase-by-category', icon: ShoppingBag },
                    ],
                },
                {
                    label: 'Stock Reports',
                    items: [
                        { label: 'Stock Summary', href: '/reports/stock-summary', icon: Boxes },
                        { label: 'Stock Valuation', href: '/reports/stock-valuation', icon: Boxes },
                        { label: 'Stock by Category', href: '/reports/stock-by-category', icon: Boxes },
                        { label: 'Stock Movement Register', href: '/reports/stock-movement-register', icon: Boxes },
                        { label: 'Damage & Lost Stock', href: '/reports/damage-lost-stock', icon: Boxes },
                        { label: 'Stock by Brand', href: '/reports/stock-by-brand', icon: Boxes },
                    ],
                },
            ],
        },
    ];

    if (isAdmin) {
        groups.push({
            label: 'ADMIN',
            categories: [
                {
                    label: 'Users & Access',
                    items: [{ label: 'Manage users', href: '/admin/users', icon: UserCog }],
                },
                {
                    label: 'System',
                    items: [
                        { label: 'Notices', href: '/notices', icon: Megaphone },
                        { label: 'Activity Log', href: '/activity-log', icon: History },
                        { label: 'Print Log', href: '/reports/print-log', icon: Printer },
                        { label: 'Backups', href: '/backups', icon: Database },
                    ],
                },
            ],
        });
    }

    return groups;
}
