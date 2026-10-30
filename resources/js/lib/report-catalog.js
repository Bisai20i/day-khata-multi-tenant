import {
    BookOpen,
    Boxes,
    Calculator,
    Factory,
    Receipt,
    ShoppingBag,
    TrendingUp,
} from '@lucide/vue';

/**
 * Single source of truth for every report, grouped the way the Reports hub
 * page shows them. The hub page and the Ctrl+K command palette both read this
 * list, so a report is registered in one place. (The sidebar deliberately
 * links only the hub plus a few favourites - see nav-items.js.)
 *
 * Each item carries the same `permission` key as the route that serves it, so
 * `filterNavByPermission` hides reports the user cannot open. That only hides
 * links: the server `can:` middleware is the authority.
 *
 * @returns {Array<{ label: string, description: string, icon: object, items: Array<{ label: string, description: string, href: string, permission: string }> }>}
 */
export function reportCategories() {
    return [
        {
            label: 'Accounts',
            description: 'Ledgers, statements and day-to-day books.',
            icon: Calculator,
            items: [
                { label: 'Ledger', description: 'Every transaction of one account, with running balance.', href: '/reports/ledger', permission: 'account_ledger.view' },
                { label: 'Day Book', description: 'All vouchers posted on a day or date range.', href: '/reports/day-book', permission: 'day_book.view' },
                { label: 'Cash Book', description: 'Cash receipts, payments and balance.', href: '/reports/cash-book', permission: 'cash_bank_book.view' },
                { label: 'Bank Book', description: 'Bank receipts, payments and balance.', href: '/reports/bank-book', permission: 'cash_bank_book.view' },
                { label: 'Trial Balance', description: 'Debit and credit totals of every account.', href: '/reports/trial-balance', permission: 'financial_statements.view' },
                { label: 'Income Statement', description: 'Profit and loss for the period.', href: '/reports/income-statement', permission: 'financial_statements.view' },
                { label: 'Balance Sheet', description: 'Assets, liabilities and equity at a date.', href: '/reports/balance-sheet', permission: 'financial_statements.view' },
            ],
        },
        {
            label: 'Receivables & Payables',
            description: 'Who owes you and whom you owe.',
            icon: BookOpen,
            items: [
                { label: 'Debtors', description: 'Customers with outstanding balances.', href: '/reports/debtors', permission: 'receivables_reports.view' },
                { label: 'Aged Receivables', description: 'Customer dues bucketed by age.', href: '/reports/aged-receivables', permission: 'receivables_reports.view' },
                { label: 'Creditors', description: 'Suppliers you have outstanding balances with.', href: '/reports/creditors', permission: 'payables_reports.view' },
                { label: 'Aged Payables', description: 'Supplier dues bucketed by age.', href: '/reports/aged-payables', permission: 'payables_reports.view' },
            ],
        },
        {
            label: 'Sales',
            description: 'What you sold, to whom and in which category.',
            icon: TrendingUp,
            items: [
                { label: 'Sales Register', description: 'Every sale in the period.', href: '/reports/sales-register', permission: 'sales_reports.view' },
                { label: 'Item-wise Sales', description: 'Quantity and value sold per item.', href: '/reports/item-wise-sales', permission: 'sales_reports.view' },
                { label: 'Sales by Category', description: 'Sales grouped by item category.', href: '/reports/sales-by-category', permission: 'sales_reports.view' },
                { label: 'Sales With Note', description: 'Sales that carry a note.', href: '/reports/sales-with-note', permission: 'sales_reports.view' },
            ],
        },
        {
            label: 'Purchases',
            description: 'What you bought, from whom and in which category.',
            icon: ShoppingBag,
            items: [
                { label: 'Purchase Register', description: 'Every purchase in the period.', href: '/reports/purchase-register', permission: 'purchase_reports.view' },
                { label: 'Item-wise Purchase', description: 'Quantity and value bought per item.', href: '/reports/item-wise-purchase', permission: 'purchase_reports.view' },
                { label: 'Purchase by Category', description: 'Purchases grouped by item category.', href: '/reports/purchase-by-category', permission: 'purchase_reports.view' },
            ],
        },
        {
            label: 'VAT & Tax',
            description: 'Books and summaries for tax filing.',
            icon: Receipt,
            items: [
                { label: 'VAT Summary', description: 'Output and input VAT for the period.', href: '/reports/vat-summary', permission: 'tax_reports.view' },
                { label: 'Sales VAT Book', description: 'Sales invoices in the statutory VAT format.', href: '/reports/sales-vat-book', permission: 'tax_reports.view' },
                { label: 'Purchase VAT Book', description: 'Purchase bills in the statutory VAT format.', href: '/reports/purchase-vat-book', permission: 'tax_reports.view' },
                { label: 'Sales Return Register', description: 'Credit notes issued to customers.', href: '/reports/sales-return-register', permission: 'sales_reports.view' },
                { label: 'Purchase Return Register', description: 'Debit notes issued to suppliers.', href: '/reports/purchase-return-register', permission: 'purchase_reports.view' },
                { label: 'TDS Report', description: 'Tax deducted at source.', href: '/reports/tds', permission: 'tax_reports.view' },
            ],
        },
        {
            label: 'Stock',
            description: 'What you hold, what it is worth and how it moves.',
            icon: Boxes,
            items: [
                { label: 'Stock Summary', description: 'Quantity on hand per item and store.', href: '/reports/stock-summary', permission: 'stock_reports.view' },
                { label: 'Stock Valuation', description: 'Closing stock value at cost.', href: '/reports/stock-valuation', permission: 'stock_valuation.view' },
                { label: 'Stock Ageing', description: 'How long stock has been sitting.', href: '/reports/stock-ageing', permission: 'stock_ageing.view' },
                { label: 'Stock Movement Register', description: 'Every stock in and out, with the document behind it.', href: '/reports/stock-movement-register', permission: 'stock_reports.view' },
                { label: 'Stock by Category', description: 'Stock grouped by item category.', href: '/reports/stock-by-category', permission: 'stock_reports.view' },
                { label: 'Stock by Brand', description: 'Stock grouped by brand.', href: '/reports/stock-by-brand', permission: 'stock_reports.view' },
                { label: 'Damage & Lost Stock', description: 'Stock written off as damaged or lost.', href: '/reports/damage-lost-stock', permission: 'stock_reports.view' },
            ],
        },
        {
            label: 'Manufacturing',
            description: 'Production stock, cost of goods made and sold, reconciliation.',
            icon: Factory,
            items: [
                { label: 'Manufacturing Stock Ledger', description: 'Raw material, WIP and finished-goods movements.', href: '/reports/manufacturing/stock-ledger', permission: 'manufacturing_reports.view' },
                { label: 'Inventory Schedule', description: 'Opening, additions and closing by stock class.', href: '/reports/manufacturing/inventory-schedule', permission: 'manufacturing_reports.view' },
                { label: 'COGM & COGS Schedule', description: 'Cost of goods manufactured and sold.', href: '/reports/manufacturing/cogs-schedule', permission: 'manufacturing_reports.view' },
                { label: 'Inventory Reconciliation', description: 'Stock records against the ledger.', href: '/reports/manufacturing/reconciliation', permission: 'manufacturing_reports.view' },
            ],
        },
    ];
}

/**
 * Every distinct permission key a report is gated by - the sidebar's "All
 * reports" link is shown when the user holds any one of them.
 *
 * @returns {string[]}
 */
export function reportPermissions() {
    return [...new Set(reportCategories().flatMap((category) => category.items.map((item) => item.permission)))];
}
