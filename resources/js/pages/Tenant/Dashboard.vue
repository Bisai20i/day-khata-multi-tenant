<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    Users,
    Truck,
    Package,
    BookOpen,
    Megaphone,
    AlertTriangle,
    X,
    ArrowRight,
    Wallet,
    Boxes,
    Landmark,
    HandCoins,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import { formatMoney, formatQuantity } from '@/lib/money.js';
import { holdsAll } from '@/lib/quickActions';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import DashboardSalesColumn from '@/components/dashboard/DashboardSalesColumn.vue';
import DashboardTrends from '@/components/dashboard/DashboardTrends.vue';
import DashboardTopLists from '@/components/dashboard/DashboardTopLists.vue';
import DashboardBranchComparison from '@/components/dashboard/DashboardBranchComparison.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    notices: {
        type: Array,
        default: () => [],
    },
    // Every widget prop below except notices/fiscalYear is sent ONLY when the
    // user holds its key (see DashboardController::index), so a missing prop
    // means "not allowed", never "zero". kpis carries only the allowed keys.
    kpis: {
        type: Object,
        default: () => ({}),
    },
    lowStockItems: {
        type: [Array, null],
        default: null,
    },
    fiscalYear: {
        type: [Object, null],
        default: null,
    },
    recentCustomers: {
        type: [Array, null],
        default: null,
    },
    recentSales: {
        type: [Array, null],
        default: null,
    },
    accountHeadBreakdown: {
        type: [Array, null],
        default: null,
    },
    expiringItemsCount: {
        type: Number,
        default: 0,
    },
    salesTrend: {
        type: [Array, null],
        default: null,
    },
    purchaseTrend: {
        type: [Array, null],
        default: null,
    },
    topItemsThisMonth: {
        type: [Array, null],
        default: null,
    },
    topCustomersThisMonth: {
        type: [Array, null],
        default: null,
    },
    // Branch filter (plan 12, BR16): null for a company that is not
    // multi-branch, which then sees no branch control at all.
    branchReport: {
        type: [Object, null],
        default: null,
    },
    // Per-branch sales/purchases this month; null unless 2+ branches are covered.
    branchComparison: {
        type: [Object, null],
        default: null,
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

const branchFilter = useBranchReportFilter(() => props.branchReport);

/**
 * Reloads the dashboard for the picked branch, keeping any other query
 * params the URL carries (the server re-checks every id).
 */
function reloadForBranch() {
    const params = {};

    for (const [key, value] of new URLSearchParams(window.location.search)) {
        if (key !== 'branch' && key !== 'branches' && !key.startsWith('branches[')) {
            params[key] = value;
        }
    }

    router.get(window.location.pathname, { ...params, ...branchFilter.toQuery() }, { preserveScroll: true, preserveState: true });
}

/**
 * A report link that opens on the branches the dashboard shows: the branch
 * picked here, if any. Unchanged otherwise (the report then follows the
 * top-bar switcher, as the dashboard does) and always unchanged for a
 * company without branches.
 */
function withBranchQuery(href) {
    const query = branchFilter.appendTo(new URLSearchParams()).toString();

    return query === '' ? href : `${href}?${query}`;
}

const kpiCards = computed(() =>
    [
        { key: 'customers', label: 'Customers', hint: 'People you sell to', href: '/customers', icon: Users },
        { key: 'suppliers', label: 'Suppliers', hint: 'People you buy from', href: '/suppliers', icon: Truck },
        { key: 'items', label: 'Items', hint: 'Products in your catalogue', href: '/items', icon: Package },
        { key: 'accounts', label: 'Ledger Accounts', hint: 'Accounts used for bookkeeping', href: '/accounts', icon: BookOpen },
    ]
        .filter((card) => props.kpis[card.key])
        .map((card) => ({ ...card, total: props.kpis[card.key].total, thisWeek: props.kpis[card.key].thisWeek ?? null })),
);

// Point-in-time balance sheet snapshot - cash, stock, and the two ledger
// balances a shopkeeper checks daily (who owes us, who do we owe).
// Each card links to the report guarded by the same key that let its figure
// through, so the click never lands on a 403.
const financialCards = computed(() =>
    [
        { key: 'cashInHand', label: 'Cash in Hand', hint: 'Cash you hold right now', href: '/reports/cash-book', icon: Wallet },
        { key: 'stockValue', label: 'Stock Value', hint: 'Worth of goods in stock', href: '/reports/stock-valuation', icon: Boxes },
        { key: 'debtors', label: 'Customers Owe You', hint: 'Sundry Debtors - money to collect', href: '/reports/debtors', icon: HandCoins },
        { key: 'creditors', label: 'You Owe Suppliers', hint: 'Sundry Creditors - money to pay', href: '/reports/creditors', icon: Landmark },
    ]
        .filter((card) => props.kpis[card.key] !== undefined && props.kpis[card.key] !== null)
        .map((card) => ({ ...card, amount: props.kpis[card.key] })),
);

const taxSummary = computed(() => props.kpis.tax?.thisWeek ?? null);

const hasSalesColumn = computed(() => Boolean(props.kpis.sales || props.kpis.purchases || taxSummary.value || canStartPosSale.value));

const { can } = usePermissions();

// UI gating only: the POS route is still guarded server-side by `can:`.
const canStartPosSale = computed(() => holdsAll(['pos.view', 'sales.create'], can));

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

        <div v-if="branchReport" class="mb-5">
            <BranchReportFilter
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="dashboard-branch"
                @change="reloadForBranch"
            />
            <BranchReportNotes :report="branchReport" />
        </div>

        <div v-if="kpiCards.length" class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
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

        <template v-if="financialCards.length">
            <h3 class="mb-2 text-sm font-bold text-text-strong">Money position</h3>
            <p v-if="fiscalYear" class="mb-2 text-xs text-text-muted">
                Ledger balances below are for fiscal year {{ fiscalYear.name }} only.
            </p>
        </template>

        <div v-if="financialCards.length" class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Link v-for="card in financialCards" :key="card.key" :href="withBranchQuery(card.href)" class="block">
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

        <DashboardBranchComparison v-if="branchComparison" :comparison="branchComparison" />

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

        <Card v-if="lowStockItems?.length > 0" variant="panel" title="Low Stock" class="mb-5">
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

        <div
            v-if="recentSales || hasSalesColumn"
            class="mb-5 grid grid-cols-1 gap-5"
            :class="{ 'lg:grid-cols-[2fr_1fr]': recentSales && hasSalesColumn }"
        >
            <Card v-if="recentSales" variant="panel" title="Recent Sales">
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

            <DashboardSalesColumn
                v-if="hasSalesColumn"
                :kpis="kpis"
                :tax-summary="taxSummary"
                :can-start-pos-sale="canStartPosSale"
            />
        </div>

        <div
            v-if="recentCustomers || accountHeadBreakdown"
            class="mb-5 grid grid-cols-1 gap-5"
            :class="{ 'lg:grid-cols-[2fr_1fr]': recentCustomers && accountHeadBreakdown }"
        >
            <Card v-if="recentCustomers" variant="panel" title="Recent Customers">
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

            <Card v-if="accountHeadBreakdown" variant="panel" title="Account Groups">
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

        <DashboardTrends v-if="salesTrend || purchaseTrend" :sales-trend="salesTrend" :purchase-trend="purchaseTrend" />

        <DashboardTopLists
            v-if="topItemsThisMonth || topCustomersThisMonth"
            :top-items-this-month="topItemsThisMonth"
            :top-customers-this-month="topCustomersThisMonth"
        />
    </div>
</template>
