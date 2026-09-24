<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    Users,
    Truck,
    Package,
    BookOpen,
    Megaphone,
    AlertTriangle,
    X,
    Receipt,
    ScanBarcode,
    ArrowRight,
    Wallet,
    Boxes,
    Landmark,
    HandCoins,
    ReceiptText,
    Percent,
    ShoppingCart,
    ArrowDownLeft,
    ArrowUpRight,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { formatMoney, formatQuantity, compareMoney, parseMoney } from '@/lib/money.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    notices: {
        type: Array,
        default: () => [],
    },
    kpis: {
        type: Object,
        default: () => ({
            customers: { total: 0, thisWeek: 0 },
            suppliers: { total: 0, thisWeek: 0 },
            items: { total: 0, thisWeek: 0 },
            accounts: { total: 0 },
            sales: { today: { count: 0, total: '0.00' }, thisWeek: { count: 0, total: '0.00' } },
            purchases: { today: { count: 0, total: '0.00' }, thisWeek: { count: 0, total: '0.00' } },
            cashInHand: '0.00',
            stockValue: '0.00',
            debtors: '0.00',
            creditors: '0.00',
            tax: { thisWeek: { taxable: '0.00', nontaxable: '0.00', vat: '0.00' } },
        }),
    },
    lowStockItems: {
        type: Array,
        default: () => [],
    },
    fiscalYear: {
        type: [Object, null],
        default: null,
    },
    recentCustomers: {
        type: Array,
        default: () => [],
    },
    recentSales: {
        type: Array,
        default: () => [],
    },
    accountHeadBreakdown: {
        type: Array,
        default: () => [],
    },
    expiringItemsCount: {
        type: Number,
        default: 0,
    },
    salesTrend: {
        type: Array,
        default: () => [],
    },
    purchaseTrend: {
        type: Array,
        default: () => [],
    },
    topItemsThisMonth: {
        type: Array,
        default: () => [],
    },
    topCustomersThisMonth: {
        type: Array,
        default: () => [],
    },
});

// Dismissal is in-page only, not persisted anywhere - an active notice
// reappears on the next visit/reload by design (this MVP has no
// per-user "read" tracking).
const dismissedNoticeIds = ref(new Set());

const visibleNotices = computed(() => props.notices.filter((notice) => !dismissedNoticeIds.value.has(notice.id)));

function dismissNotice(id) {
    dismissedNoticeIds.value = new Set(dismissedNoticeIds.value).add(id);
}

useLayoutChrome('Dashboard');

const kpiCards = computed(() => [
    { key: 'customers', label: 'Customers', hint: 'People you sell to', href: '/customers', icon: Users, total: props.kpis.customers.total, thisWeek: props.kpis.customers.thisWeek },
    { key: 'suppliers', label: 'Suppliers', hint: 'People you buy from', href: '/suppliers', icon: Truck, total: props.kpis.suppliers.total, thisWeek: props.kpis.suppliers.thisWeek },
    { key: 'items', label: 'Items', hint: 'Products in your catalogue', href: '/items', icon: Package, total: props.kpis.items.total, thisWeek: props.kpis.items.thisWeek },
    { key: 'accounts', label: 'Ledger Accounts', hint: 'Accounts used for bookkeeping', href: '/accounts', icon: BookOpen, total: props.kpis.accounts.total, thisWeek: null },
]);

// Point-in-time balance sheet snapshot - cash, stock, and the two ledger
// balances a shopkeeper checks daily (who owes us, who do we owe).
const financialCards = computed(() => [
    { key: 'cashInHand', label: 'Cash in Hand', hint: 'Cash you hold right now', href: '/accounts', icon: Wallet, amount: props.kpis.cashInHand },
    { key: 'stockValue', label: 'Stock Value', hint: 'Worth of goods in stock', href: '/reports/stock-valuation', icon: Boxes, amount: props.kpis.stockValue },
    { key: 'debtors', label: 'Customers Owe You', hint: 'Sundry Debtors - money to collect', href: '/receipts', icon: HandCoins, amount: props.kpis.debtors },
    { key: 'creditors', label: 'You Owe Suppliers', hint: 'Sundry Creditors - money to pay', href: '/payments', icon: Landmark, amount: props.kpis.creditors },
]);

const taxSummary = computed(() => props.kpis.tax?.thisWeek ?? { taxable: '0.00', nontaxable: '0.00', vat: '0.00' });

/**
 * Tallest bar in the two trend strips, picked by exact money comparison.
 * Only the bar GEOMETRY below leaves the decimal world - the same narrow
 * exemption CONTRACTS C1 grants Money::toFloat() for chart data. No amount
 * a user reads is ever produced this way.
 */
const maxTrendTotal = computed(() => {
    let max = '0.00';

    for (const day of [...props.salesTrend, ...props.purchaseTrend]) {
        if (compareMoney(day.total, max) > 0) {
            max = day.total;
        }
    }

    return max;
});

function trendBarWidth(total) {
    const max = parseMoney(maxTrendTotal.value);
    const value = parseMoney(total ?? '0.00');

    if (!max.ok || !value.ok || max.value === 0n) {
        return '0%';
    }

    return `${Math.min(100, (Number(value.value) / Number(max.value)) * 100)}%`;
}

function trendDayLabel(dateString) {
    return new Date(`${dateString}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' });
}

const quickActions = [
    { label: 'New sale', href: '/sales', icon: Receipt },
    { label: 'Quick POS sale', href: '/pos', icon: ScanBarcode },
    { label: 'New purchase', href: '/purchases', icon: ShoppingCart },
    { label: 'Receive payment', href: '/receipts', icon: ArrowDownLeft },
    { label: 'Make payment', href: '/payments', icon: ArrowUpRight },
];

const dotPalette = ['#6600FF', '#0EA5E9', '#F59E0B', '#10B981', '#EC4899'];

const paymentModeLabels = {
    cash: 'Cash',
    bank: 'Bank',
    partial: 'Partial',
    credit: 'Credit',
};

function initial(name) {
    return name?.trim()?.charAt(0)?.toUpperCase() ?? '?';
}

/**
 * Every amount on this page arrives as an exact decimal string from
 * DashboardController and is rendered with Indian grouping (CONTRACTS C8) -
 * no parseFloat, no toFixed, no arithmetic in the browser.
 */
function formatAmount(amount) {
    return formatMoney(amount ?? '0.00');
}
</script>

<template>
    <div>
        <PageHeader
            title="Dashboard"
            :description="fiscalYear ? `Your business at a glance - fiscal year ${fiscalYear.name}.` : 'Your business at a glance.'"
        />

        <section aria-label="Quick actions" class="mb-5 flex flex-wrap gap-2">
            <Button v-for="action in quickActions" :key="action.href" :as="Link" :href="action.href" variant="primary" tone="purple">
                <component :is="action.icon" class="size-4" aria-hidden="true" />
                {{ action.label }}
            </Button>
        </section>

        <div v-if="visibleNotices.length" class="mb-5 flex flex-col gap-2">
            <div
                v-for="notice in visibleNotices"
                :key="notice.id"
                class="flex items-start gap-3 border-[1.5px] border-primary bg-primary-tint px-4 py-3"
            >
                <Megaphone class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-text-strong">{{ notice.title }}</p>
                    <p class="mt-0.5 text-sm whitespace-pre-line text-text-base">{{ notice.body }}</p>
                </div>
                <button
                    type="button"
                    class="shrink-0 text-text-muted transition-colors duration-150 hover:text-text-strong"
                    aria-label="Dismiss"
                    @click="dismissNotice(notice.id)"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>
        </div>

        <h3 class="mb-2 text-sm font-bold text-text-strong">Your business</h3>
        <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Link v-for="card in kpiCards" :key="card.key" :href="card.href" class="block">
            <Card variant="panel" class="h-full transition-colors hover:border-primary">
                <div class="flex items-start justify-between">
                    <div class="flex size-9 items-center justify-center bg-primary-tint">
                        <component :is="card.icon" class="size-5 text-primary" />
                    </div>
                    <span
                        v-if="card.thisWeek"
                        class="bg-success-bg px-2 py-0.5 text-[11px] font-semibold text-success"
                    >
                        +{{ card.thisWeek }} this week
                    </span>
                </div>
                <p class="mt-3 text-2xl font-bold text-text-strong">{{ card.total }}</p>
                <p class="text-sm font-semibold text-text-base">{{ card.label }}</p>
                <p class="text-xs text-text-muted">{{ card.hint }}</p>
            </Card>
            </Link>
        </div>

        <h3 class="mb-2 text-sm font-bold text-text-strong">Money position</h3>
        <p v-if="fiscalYear" class="mb-2 text-xs text-text-muted">
            Ledger balances below are for fiscal year {{ fiscalYear.name }} only.
        </p>

        <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Link v-for="card in financialCards" :key="card.key" :href="card.href" class="block">
            <Card variant="panel" class="h-full transition-colors hover:border-primary">
                <div class="flex size-9 items-center justify-center bg-primary-tint">
                    <component :is="card.icon" class="size-5 text-primary" />
                </div>
                <p class="mt-3 text-2xl font-bold text-text-strong">{{ formatAmount(card.amount) }}</p>
                <p class="text-sm font-semibold text-text-base">{{ card.label }}</p>
                <p class="text-xs text-text-muted">{{ card.hint }}</p>
            </Card>
            </Link>
        </div>

        <Card v-if="expiringItemsCount > 0" variant="panel" class="mb-5">
            <div class="flex items-center gap-3">
                <div class="flex size-9 shrink-0 items-center justify-center bg-warning-bg">
                    <AlertTriangle class="size-5 text-warning-text" aria-hidden="true" />
                </div>
                <div>
                    <p class="text-sm font-bold text-text-strong">{{ expiringItemsCount }} {{ expiringItemsCount === 1 ? 'item is' : 'items are' }} expiring soon</p>
                    <p class="text-xs text-text-muted">Expiring within the next 30 days</p>
                </div>
            </div>
        </Card>

        <Card v-if="lowStockItems.length > 0" variant="panel" title="Low Stock" class="mb-5">
            <div class="divide-y divide-border">
                <div
                    v-for="item in lowStockItems"
                    :key="`low-${item.id}`"
                    class="flex items-center gap-3 px-1 py-1.5 text-[13px] text-text-base"
                >
                    <AlertTriangle class="size-4 shrink-0 text-warning-text" aria-hidden="true" />
                    <div class="min-w-0 flex-1 truncate">{{ item.name }}</div>
                    <div class="w-32 text-right">
                        <span class="font-semibold text-text-strong">{{ formatQuantity(item.stock) }}</span>
                        <span class="text-text-muted"> / {{ formatQuantity(item.minStock) }} {{ item.unit }}</span>
                    </div>
                </div>
            </div>
            <p class="mt-2 text-xs text-text-muted">Items at or below their reorder level.</p>
        </Card>

        <div class="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-[2fr_1fr]">
            <Card variant="panel" title="Recent Sales">
                <div v-if="recentSales.length === 0" class="py-6 text-center text-sm text-text-muted">
                    No sales yet.
                    <Link href="/sales" class="font-semibold text-primary hover:underline">Record your first sale</Link>
                </div>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-soft text-left text-[11px] font-bold tracking-[.6px] text-text-muted uppercase">
                            <th class="pb-2 font-bold">Customer</th>
                            <th class="pb-2 font-bold">Date</th>
                            <th class="pb-2 font-bold">Payment</th>
                            <th class="pb-2 text-right font-bold">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="sale in recentSales" :key="sale.id" class="border-b border-border-soft last:border-0">
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <span class="flex size-6 items-center justify-center rounded-full bg-primary-tint text-[11px] font-bold text-primary">
                                        {{ initial(sale.customer) }}
                                    </span>
                                    <span class="text-text-base">{{ sale.customer ?? '-' }}</span>
                                </div>
                            </td>
                            <td class="py-2 text-text-muted">{{ sale.date }}</td>
                            <td class="py-2">
                                <span class="border border-border px-1.5 py-0.5 text-xs text-text-muted">
                                    {{ paymentModeLabels[sale.paymentMode] ?? sale.paymentMode }}
                                </span>
                            </td>
                            <td class="py-2 text-right font-semibold text-text-strong">{{ formatAmount(sale.total) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="recentSales.length" class="mt-3 text-right">
                    <Link href="/sales" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                        View all sales
                        <ArrowRight class="size-3.5" aria-hidden="true" />
                    </Link>
                </div>
            </Card>

            <div class="flex flex-col gap-5">
                <Card variant="panel">
                    <div class="flex items-start justify-between">
                        <div class="flex size-9 items-center justify-center bg-primary-tint">
                            <Receipt class="size-5 text-primary" />
                        </div>
                        <span
                            v-if="kpis.sales.thisWeek.count"
                            class="bg-success-bg px-2 py-0.5 text-[11px] font-semibold text-success"
                        >
                            {{ kpis.sales.thisWeek.count }} this week
                        </span>
                    </div>
                    <p class="mt-3 text-2xl font-bold text-text-strong">{{ formatAmount(kpis.sales.today.total) }}</p>
                    <p class="text-sm text-text-muted">Today's Sales - {{ kpis.sales.today.count }} bills</p>
                </Card>

                <Card variant="panel">
                    <div class="flex items-start justify-between">
                        <div class="flex size-9 items-center justify-center bg-primary-tint">
                            <ReceiptText class="size-5 text-primary" />
                        </div>
                        <span
                            v-if="kpis.purchases.thisWeek.count"
                            class="bg-success-bg px-2 py-0.5 text-[11px] font-semibold text-success"
                        >
                            {{ kpis.purchases.thisWeek.count }} this week
                        </span>
                    </div>
                    <p class="mt-3 text-2xl font-bold text-text-strong">{{ formatAmount(kpis.purchases.today.total) }}</p>
                    <p class="text-sm text-text-muted">Today's Purchases - {{ kpis.purchases.today.count }} bills</p>
                </Card>

                <Card variant="panel">
                    <div class="flex items-start justify-between">
                        <div class="flex size-9 items-center justify-center bg-primary-tint">
                            <Percent class="size-5 text-primary" />
                        </div>
                    </div>
                    <p class="mt-3 text-sm font-bold text-text-strong">Tax Summary (This Week)</p>
                    <dl class="mt-2 flex flex-col gap-1 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-text-muted">Taxable</dt>
                            <dd class="font-semibold text-text-strong">{{ formatAmount(taxSummary.taxable) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-text-muted">Non-taxable</dt>
                            <dd class="font-semibold text-text-strong">{{ formatAmount(taxSummary.nontaxable) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-text-muted">VAT</dt>
                            <dd class="font-semibold text-text-strong">{{ formatAmount(taxSummary.vat) }}</dd>
                        </div>
                    </dl>
                </Card>

                <Card variant="panel">
                    <div class="flex size-9 items-center justify-center bg-primary-tint">
                        <ScanBarcode class="size-5 text-primary" aria-hidden="true" />
                    </div>
                    <p class="mt-3 text-sm font-bold text-text-strong">Start a new sale</p>
                    <p class="text-xs text-text-muted">Jump straight into POS for a quick walk-in sale.</p>
                    <Button :as="Link" href="/pos" variant="primary" tone="purple" class="mt-3 w-full justify-center">
                        <ScanBarcode class="size-4" aria-hidden="true" />
                        Go to POS
                    </Button>
                </Card>
            </div>
        </div>

        <div class="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-[2fr_1fr]">
            <Card variant="panel" title="Recent Customers">
                <div v-if="recentCustomers.length === 0" class="py-6 text-center text-sm text-text-muted">
                    No customers yet.
                    <Link href="/customers" class="font-semibold text-primary hover:underline">Add a customer</Link>
                </div>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-soft text-left text-[11px] font-bold tracking-[.6px] text-text-muted uppercase">
                            <th class="pb-2 font-bold">Name</th>
                            <th class="pb-2 font-bold">Mobile</th>
                            <th class="pb-2 font-bold">Ledger code</th>
                            <th class="pb-2 font-bold">Added</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="customer in recentCustomers" :key="customer.name + customer.added" class="border-b border-border-soft last:border-0">
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <span class="flex size-6 items-center justify-center rounded-full bg-primary-tint text-[11px] font-bold text-primary">
                                        {{ initial(customer.name) }}
                                    </span>
                                    <span class="text-text-base">{{ customer.name }}</span>
                                </div>
                            </td>
                            <td class="py-2 text-text-muted">{{ customer.mobile ?? '-' }}</td>
                            <td class="py-2">
                                <span class="border border-border px-1.5 py-0.5 text-xs text-text-muted">
                                    {{ customer.code ?? '-' }}
                                </span>
                            </td>
                            <td class="py-2 text-text-muted">{{ customer.added }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>

            <Card variant="panel" title="Account Groups">
                <div v-if="accountHeadBreakdown.length === 0" class="py-6 text-center text-sm text-text-muted">
                    No account heads found
                </div>
                <ul v-else class="flex flex-col gap-3">
                    <li
                        v-for="(head, index) in accountHeadBreakdown"
                        :key="head.name"
                        class="flex items-center justify-between text-sm"
                    >
                        <span class="flex items-center gap-2 text-text-base">
                            <span
                                class="size-2.5 rounded-full"
                                :style="{ backgroundColor: dotPalette[index % dotPalette.length] }"
                            />
                            {{ head.name }}
                        </span>
                        <span class="font-semibold text-text-strong">{{ head.count }}</span>
                    </li>
                </ul>
            </Card>
        </div>

        <div class="mb-5 grid grid-cols-1 gap-5 md:grid-cols-2">
            <Card variant="panel" title="Sales Trend (Last 7 Days)">
                <div v-if="salesTrend.length === 0" class="py-6 text-center text-sm text-text-muted">
                    No sales in the last 7 days.
                    <Link href="/sales" class="font-semibold text-primary hover:underline">Record a sale</Link>
                </div>
                <ul v-else class="flex flex-col gap-2">
                    <li v-for="day in salesTrend" :key="day.date" class="flex items-center gap-3 text-sm">
                        <span class="w-14 shrink-0 text-xs text-text-muted">{{ trendDayLabel(day.date) }}</span>
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-border-soft">
                            <span class="block h-full bg-primary" :style="{ width: trendBarWidth(day.total) }" />
                        </span>
                        <span class="w-20 shrink-0 text-right font-semibold text-text-strong">{{ formatAmount(day.total) }}</span>
                    </li>
                </ul>
            </Card>

            <Card variant="panel" title="Purchase Trend (Last 7 Days)">
                <div v-if="purchaseTrend.length === 0" class="py-6 text-center text-sm text-text-muted">
                    No purchases in the last 7 days.
                    <Link href="/purchases" class="font-semibold text-primary hover:underline">Record a purchase</Link>
                </div>
                <ul v-else class="flex flex-col gap-2">
                    <li v-for="day in purchaseTrend" :key="day.date" class="flex items-center gap-3 text-sm">
                        <span class="w-14 shrink-0 text-xs text-text-muted">{{ trendDayLabel(day.date) }}</span>
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-border-soft">
                            <span class="block h-full bg-[#F59E0B]" :style="{ width: trendBarWidth(day.total) }" />
                        </span>
                        <span class="w-20 shrink-0 text-right font-semibold text-text-strong">{{ formatAmount(day.total) }}</span>
                    </li>
                </ul>
            </Card>
        </div>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <Card variant="panel" title="Top 5 Items This Month">
                <div v-if="topItemsThisMonth.length === 0" class="py-6 text-center text-sm text-text-muted">
                    No sales this month yet.
                    <Link href="/sales" class="font-semibold text-primary hover:underline">Record a sale</Link>
                </div>
                <ol v-else class="flex flex-col gap-3">
                    <li v-for="(item, index) in topItemsThisMonth" :key="item.name" class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2 text-text-base">
                            <span class="flex size-5 items-center justify-center rounded-full bg-primary-tint text-[11px] font-bold text-primary">
                                {{ index + 1 }}
                            </span>
                            {{ item.name }}
                        </span>
                        <span class="font-semibold text-text-strong">{{ formatAmount(item.total) }}</span>
                    </li>
                </ol>
            </Card>

            <Card variant="panel" title="Top 5 Customers This Month">
                <div v-if="topCustomersThisMonth.length === 0" class="py-6 text-center text-sm text-text-muted">
                    No sales this month yet.
                    <Link href="/sales" class="font-semibold text-primary hover:underline">Record a sale</Link>
                </div>
                <ol v-else class="flex flex-col gap-3">
                    <li v-for="(customer, index) in topCustomersThisMonth" :key="customer.name" class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2 text-text-base">
                            <span class="flex size-5 items-center justify-center rounded-full bg-primary-tint text-[11px] font-bold text-primary">
                                {{ index + 1 }}
                            </span>
                            {{ customer.name }}
                        </span>
                        <span class="font-semibold text-text-strong">{{ formatAmount(customer.total) }}</span>
                    </li>
                </ol>
            </Card>
        </div>
    </div>
</template>
