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
    FileBarChart,
    Undo2,
} from '@lucide/vue';

/**
 * Single source of truth for the tenant sidebar nav, grouped to match the
 * approved enterprise UI direction. Previously each page hardcoded its own
 * flat copy of this list; keeping one source means adding/renaming a
 * resource only happens in one place.
 */
export function navGroups(isAdmin) {
    const groups = [
        {
            label: 'OVERVIEW',
            items: [{ label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard }],
        },
        {
            label: 'ACCOUNTING',
            items: [
                { label: 'Account Groups', href: '/account-groups', icon: Layers },
                { label: 'Account Subgroups', href: '/account-subgroups', icon: ListTree },
                { label: 'Accounts', href: '/accounts', icon: BookOpen },
                { label: 'Fiscal Years', href: '/fiscal-years', icon: CalendarRange },
                { label: 'Journal Vouchers', href: '/journal-vouchers', icon: NotebookPen },
            ],
        },
        {
            label: 'TRANSACTIONS',
            items: [
                { label: 'Sales', href: '/sales', icon: ShoppingCart },
                { label: 'Purchases', href: '/purchases', icon: PackageSearch },
                { label: 'Sales Returns', href: '/sales-returns', icon: Undo2 },
                { label: 'Purchase Returns', href: '/purchase-returns', icon: Undo2 },
            ],
        },
        {
            label: 'PARTIES',
            items: [
                { label: 'Customers', href: '/customers', icon: Users },
                { label: 'Suppliers', href: '/suppliers', icon: Truck },
            ],
        },
        {
            label: 'INVENTORY',
            items: [
                { label: 'Item Categories', href: '/item-categories', icon: Tags },
                { label: 'Item Subcategories', href: '/item-subcategories', icon: Tag },
                { label: 'Items', href: '/items', icon: Package },
                { label: 'Stock Adjustments', href: '/stock-adjustments', icon: ClipboardList },
            ],
        },
        {
            label: 'REPORTS',
            items: [
                { label: 'Trial Balance', href: '/reports/trial-balance', icon: FileBarChart },
                { label: 'Income Statement', href: '/reports/income-statement', icon: FileBarChart },
                { label: 'Balance Sheet', href: '/reports/balance-sheet', icon: FileBarChart },
                { label: 'Sales Register', href: '/reports/sales-register', icon: FileBarChart },
                { label: 'Purchase Register', href: '/reports/purchase-register', icon: FileBarChart },
                { label: 'Sales VAT Book', href: '/reports/sales-vat-book', icon: FileBarChart },
                { label: 'Purchase VAT Book', href: '/reports/purchase-vat-book', icon: FileBarChart },
                { label: 'Stock Summary', href: '/reports/stock-summary', icon: FileBarChart },
                { label: 'Day Book', href: '/reports/day-book', icon: FileBarChart },
                { label: 'Cash Book', href: '/reports/cash-book', icon: FileBarChart },
                { label: 'Bank Book', href: '/reports/bank-book', icon: FileBarChart },
                { label: 'Aged Receivables', href: '/reports/aged-receivables', icon: FileBarChart },
                { label: 'Aged Payables', href: '/reports/aged-payables', icon: FileBarChart },
                { label: 'TDS Report', href: '/reports/tds', icon: FileBarChart },
                { label: 'Stock Valuation', href: '/reports/stock-valuation', icon: FileBarChart },
                { label: 'Item-wise Sales', href: '/reports/item-wise-sales', icon: FileBarChart },
                { label: 'Item-wise Purchase', href: '/reports/item-wise-purchase', icon: FileBarChart },
                { label: 'Sales by Category', href: '/reports/sales-by-category', icon: FileBarChart },
                { label: 'Purchase by Category', href: '/reports/purchase-by-category', icon: FileBarChart },
                { label: 'Stock by Category', href: '/reports/stock-by-category', icon: FileBarChart },
            ],
        },
    ];

    if (isAdmin) {
        groups.push({
            label: 'ADMIN',
            items: [{ label: 'Manage users', href: '/admin/users', icon: UserCog }],
        });
    }

    return groups;
}
