<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    Megaphone,
    AlertTriangle,
    X,
    ArrowRight,
    Wallet,
    Boxes,
    Landmark,
    HandCoins,
    Receipt,
    ReceiptText,
    Percent,
    ScanBarcode,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Label from '@/components/ui/Label.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { formatMoney, formatQuantity } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
import { holdsAll } from '@/lib/quickActions';
import { usePermissions } from '@/composables/usePermissions';
import { ALL_BRANCHES } from '@/composables/useBranch';
import { SELECTED_BRANCHES, useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchLabel } from '@/composables/useBranchLabel';
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
    dateRange: {
        type: Object,
        default: () => ({ from: '', to: '', preset: 'this_month' }),
    },
});

// Dismissal is in-page only, not persisted anywhere - an active notice
// reappears on the next visit/reload by design.
const dismissedNoticeIds = ref(new Set());

const visibleNotices = computed(() => props.notices.filter((notice) => !dismissedNoticeIds.value.has(notice.id)));

function dismissNotice(id) {
    dismissedNoticeIds.value = new Set(dismissedNoticeIds.value).add(id);
}

useLayoutChrome('Dashboard');

const branchFilter = useBranchReportFilter(() => props.branchReport);
const { label: branchWord, plural: branchPlural } = useBranchLabel();
const branches = computed(() => props.branchReport?.options ?? []);
const isFixedScope = computed(() => props.branchReport?.restricted === true && branches.value.length <= 1);
const fixedScopeText = computed(() => {
    const only = branches.value[0];
    return only ? (only.code ? `${only.name} (${only.code})` : only.name) : (props.branchReport?.label ?? '');
});

const branchOptions = computed(() => {
    if (!props.branchReport) return [];
    const list = [];

    if (props.branchReport.restricted !== true) {
        list.push({ value: ALL_BRANCHES, label: `All ${branchPlural('lower')}` });
    } else if (branches.value.length > 1) {
        list.push({ value: ALL_BRANCHES, label: `Your ${branchPlural('lower')}` });
    }

    if (branchFilter.choice.value === SELECTED_BRANCHES) {
        list.push({ value: SELECTED_BRANCHES, label: props.branchReport.label });
    }

    for (const branch of branches.value) {
        const name = branch.code ? `${branch.name} (${branch.code})` : branch.name;
        list.push({ value: branch.id, label: branch.is_active === false ? `${name} (inactive)` : name });
    }

    return list;
});

const selectedBranch = computed({
    get: () => branchFilter.choice.value,
    set: (value) => {
        if (value === branchFilter.choice.value) return;
        branchFilter.choice.value = value;
        applyFilters(activePreset.value);
    },
});

// Date range filters
const activePreset = ref(props.dateRange?.preset || 'this_month');
const customFrom = ref(props.dateRange?.from || '');
const customTo = ref(props.dateRange?.to || '');

watch(
    () => props.dateRange,
    (val) => {
        if (val) {
            activePreset.value = val.preset || 'this_month';
            customFrom.value = val.from || '';
            customTo.value = val.to || '';
        }
    },
    { deep: true },
);

const presets = [
    { key: 'today', label: 'Today' },
    { key: 'this_week', label: 'This Week' },
    { key: 'this_month', label: 'This Month' },
    { key: 'this_fiscal_year', label: 'This FY' },
    { key: 'custom', label: 'Custom' },
];

function applyFilters(newPreset = activePreset.value) {
    const params = {};

    for (const [key, value] of new URLSearchParams(window.location.search)) {
        if (!['branch', 'branches', 'preset', 'from', 'to'].includes(key) && !key.startsWith('branches[')) {
            params[key] = value;
        }
    }

    const query = {
        ...params,
        ...branchFilter.toQuery(),
    };

    if (newPreset === 'custom') {
        if (customFrom.value) query.from = customFrom.value;
        if (customTo.value) query.to = customTo.value;
        query.preset = 'custom';
    } else {
        query.preset = newPreset;
    }

    router.get(window.location.pathname, query, { preserveScroll: true, preserveState: true });
}

function setPreset(presetKey) {
    activePreset.value = presetKey;
    if (presetKey !== 'custom') {
        applyFilters(presetKey);
    }
}

const periodLabel = computed(() => {
    switch (activePreset.value) {
        case 'today':
            return 'Today';
        case 'this_week':
            return 'This Week';
        case 'this_fiscal_year':
            return props.fiscalYear?.name ? `FY ${props.fiscalYear.name}` : 'This FY';
        case 'custom':
            return 'Custom Range';
        case 'this_month':
        default:
            return 'This Month';
    }
});

const dateRangeSummary = computed(() => {
    if (!props.dateRange?.from || !props.dateRange?.to) return '';
    const bsFrom = formatBsDate(props.dateRange.from);
    const bsTo = formatBsDate(props.dateRange.to);
    return `BS ${bsFrom} to ${bsTo} (AD ${props.dateRange.from} to ${props.dateRange.to})`;
});

/**
 * A report link that opens on the branches the dashboard shows: the branch
 * picked here, if any. Unchanged otherwise.
 */
function withBranchQuery(href) {
    const query = branchFilter.appendTo(new URLSearchParams()).toString();
    return query === '' ? href : `${href}?${query}`;
}

// Point-in-time balance sheet snapshot - cash, stock, debtors, creditors
const financialCards = computed(() =>
    [
        { key: 'cashInHand', label: 'Cash in Hand', hint: 'Liquid cash right now', href: '/reports/cash-book', icon: Wallet },
        { key: 'stockValue', label: 'Stock Value', hint: 'Inventory value on hand', href: '/reports/stock-valuation', icon: Boxes },
        { key: 'debtors', label: 'Customers Owe You', hint: 'Sundry Debtors to collect', href: '/reports/debtors', icon: HandCoins },
        { key: 'creditors', label: 'You Owe Suppliers', hint: 'Sundry Creditors to pay', href: '/reports/creditors', icon: Landmark },
    ]
        .filter((card) => props.kpis[card.key] !== undefined && props.kpis[card.key] !== null)
        .map((card) => ({ ...card, amount: props.kpis[card.key] })),
);

// Performance metrics for the selected period (with fallback to today/thisWeek for backward compatibility)
const periodSales = computed(() => props.kpis.sales?.period ?? props.kpis.sales?.today ?? null);
const periodPurchases = computed(() => props.kpis.purchases?.period ?? props.kpis.purchases?.today ?? null);
const periodTaxSummary = computed(() => props.kpis.tax?.period ?? props.kpis.tax?.thisWeek ?? null);

const { can } = usePermissions();

// UI gating only: the POS route is still guarded server-side by `can:`.
const canStartPosSale = computed(() => holdsAll(['pos.view', 'sales.create'], can));

const hasPeriodPerformance = computed(() => Boolean(periodSales.value || periodPurchases.value || periodTaxSummary.value || canStartPosSale.value));

const paymentModeLabels = {
    cash: 'Cash',
    bank: 'Bank',
    partial: 'Partial',
    credit: 'Credit',
};

function initial(name) {
    return name?.trim()?.charAt(0)?.toUpperCase() ?? '?';
}

function formatAmount(amount) {
    return formatMoney(amount ?? '0.00');
}
</script>

<template>
    <div>
        <!-- Notice Banners -->
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

        <!-- Unified Filter Toolbar: Date Range + Integrated Branch Filter -->
        <Card variant="panel" class="mb-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <!-- Date Presets & Custom Date Pickers -->
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <Label class="mb-1">Period</Label>
                        <div class="inline-flex h-9 items-center rounded-xs border border-border bg-bg-subtle p-0.5 text-xs font-semibold">
                            <button
                                v-for="preset in presets"
                                :key="preset.key"
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-xs px-2.5 py-1 text-xs transition-colors',
                                    activePreset === preset.key
                                        ? 'bg-primary font-bold text-white shadow-xs'
                                        : 'text-text-muted hover:text-text-strong',
                                ]"
                                @click="setPreset(preset.key)"
                            >
                                {{ preset.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Custom Date Range picker inputs -->
                    <div v-if="activePreset === 'custom'" class="flex items-end gap-2">
                        <div class="w-36">
                            <Label class="mb-1">From Date (BS)</Label>
                            <NepaliDateInput v-model="customFrom" placeholder="From (BS)" />
                        </div>
                        <div class="w-36">
                            <Label class="mb-1">To Date (BS)</Label>
                            <NepaliDateInput v-model="customTo" placeholder="To (BS)" />
                        </div>
                        <Button size="sm" variant="secondary" tone="purple" class="h-9 px-3" @click="applyFilters('custom')">
                            Apply
                        </Button>
                    </div>
                </div>

                <!-- Branch Filter (multi-branch only - no redundant labels) -->
                <div v-if="branchReport" class="w-52">
                    <Label class="mb-1">{{ branchWord() }}</Label>
                    <p v-if="isFixedScope" class="flex h-9 items-center truncate text-[13px] font-semibold text-text-strong">
                        {{ fixedScopeText }}
                    </p>
                    <Select v-else v-model="selectedBranch" :options="branchOptions" :placeholder="branchReport.label" class="h-9 w-full" />
                </div>
            </div>

            <!-- Date Range Summary & Restriction Note (No redundant branch display label) -->
            <div class="mt-2.5 flex flex-wrap items-center justify-between gap-2 border-t border-border-soft pt-2 text-[11.5px] text-text-muted">
                <span v-if="dateRangeSummary">{{ dateRangeSummary }}</span>
                <span v-if="branchReport?.restricted" class="text-text-faint">
                    Company-wide figures are restricted for your account.
                </span>
            </div>
        </Card>

        <!-- Section 1: Financial Health & Liquidity (Point-in-Time Snapshot) -->
        <template v-if="financialCards.length">
            <h3 class="mb-2 text-xs font-bold tracking-wider uppercase text-text-muted">Financial Position</h3>
            <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
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
        </template>

        <!-- Section 2: Period Performance (Reactive to Date Range) -->
        <template v-if="hasPeriodPerformance">
            <h3 class="mb-2 text-xs font-bold tracking-wider uppercase text-text-muted">
                Performance in Period ({{ periodLabel }})
            </h3>
            <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <!-- Sales in Period -->
                <Card v-if="periodSales" variant="panel">
                    <div class="flex items-start justify-between">
                        <div class="flex size-9 items-center justify-center bg-primary-tint">
                            <Receipt class="size-5 text-primary" />
                        </div>
                        <span class="rounded-xs bg-primary-tint px-2 py-0.5 text-[11px] font-semibold text-primary">
                            {{ periodSales.count }} {{ periodSales.count === 1 ? 'sale' : 'sales' }}
                        </span>
                    </div>
                    <p class="mt-3 text-2xl font-bold text-text-strong">{{ formatAmount(periodSales.total) }}</p>
                    <p class="text-sm font-semibold text-text-base">Sales in Period</p>
                    <p class="text-xs text-text-muted">Revenue in selected period</p>
                </Card>

                <!-- Purchases in Period -->
                <Card v-if="periodPurchases" variant="panel">
                    <div class="flex items-start justify-between">
                        <div class="flex size-9 items-center justify-center bg-primary-tint">
                            <ReceiptText class="size-5 text-primary" />
                        </div>
                        <span class="rounded-xs bg-primary-tint px-2 py-0.5 text-[11px] font-semibold text-primary">
                            {{ periodPurchases.count }} {{ periodPurchases.count === 1 ? 'bill' : 'bills' }}
                        </span>
                    </div>
                    <p class="mt-3 text-2xl font-bold text-text-strong">{{ formatAmount(periodPurchases.total) }}</p>
                    <p class="text-sm font-semibold text-text-base">Purchases in Period</p>
                    <p class="text-xs text-text-muted">Procurement in selected period</p>
                </Card>

                <!-- Tax Summary in Period -->
                <Card v-if="periodTaxSummary" variant="panel">
                    <div class="flex items-start justify-between">
                        <div class="flex size-9 items-center justify-center bg-primary-tint">
                            <Percent class="size-5 text-primary" />
                        </div>
                        <span class="text-xs font-bold text-text-strong">VAT: {{ formatAmount(periodTaxSummary.vat) }}</span>
                    </div>
                    <p class="mt-3 text-sm font-bold text-text-strong">Tax Summary</p>
                    <div class="mt-1 flex justify-between text-xs text-text-muted">
                        <span>Taxable: {{ formatAmount(periodTaxSummary.taxable) }}</span>
                        <span>Exempt: {{ formatAmount(periodTaxSummary.nontaxable) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-text-muted">13% VAT & non-taxable sales</p>
                </Card>

                <!-- Quick POS Action Button Card -->
                <Card v-if="canStartPosSale" variant="panel" class="flex flex-col justify-between">
                    <div>
                        <div class="flex size-9 items-center justify-center bg-primary-tint">
                            <ScanBarcode class="size-5 text-primary" aria-hidden="true" />
                        </div>
                        <p class="mt-2 text-sm font-bold text-text-strong">Quick Walk-in Sale</p>
                        <p class="text-xs text-text-muted">Open POS counter billing terminal</p>
                    </div>
                    <Button :as="Link" href="/pos" variant="primary" tone="purple" class="mt-3 w-full justify-center">
                        <ScanBarcode class="size-4" aria-hidden="true" />
                        Go to POS
                    </Button>
                </Card>
            </div>
        </template>

        <!-- Section 3: Actionable Attention Alerts (Only shown if count > 0) -->
        <div v-if="expiringItemsCount > 0 || (lowStockItems && lowStockItems.length > 0)" class="mb-5 flex flex-col gap-3">
            <Card v-if="expiringItemsCount > 0" variant="panel">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center bg-warning-bg">
                        <AlertTriangle class="size-5 text-warning-text" aria-hidden="true" />
                    </div>
                    <div>
                        <p class="text-sm font-bold text-text-strong">
                            {{ expiringItemsCount }} {{ expiringItemsCount === 1 ? 'item is' : 'items are' }} expiring soon
                        </p>
                        <p class="text-xs text-text-muted">Expiring within the next 30 days</p>
                    </div>
                </div>
            </Card>

            <Card v-if="lowStockItems?.length > 0" variant="panel" title="Low Stock Alert">
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
        </div>

        <!-- Section 4: Operational Analytics & Activity (60% / 40% Grid) -->
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1.5fr_1fr]">
            <!-- Left Column: Trends & Recent Sales -->
            <div class="flex flex-col gap-5">
                <DashboardTrends
                    v-if="salesTrend || purchaseTrend"
                    :sales-trend="salesTrend"
                    :purchase-trend="purchaseTrend"
                />

                <Card v-if="recentSales" variant="panel" title="Recent Sales">
                    <div v-if="recentSales.length === 0" class="py-6 text-center text-sm text-text-muted">
                        No sales recorded yet.
                        <Link href="/sales" class="font-semibold text-primary hover:underline">Record a sale</Link>
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
            </div>

            <!-- Right Column: Top Performers & Branch Comparison -->
            <div class="flex flex-col gap-5">
                <DashboardTopLists
                    v-if="topItemsThisMonth || topCustomersThisMonth"
                    :top-items-this-month="topItemsThisMonth"
                    :top-customers-this-month="topCustomersThisMonth"
                    :period-label="periodLabel"
                />

                <DashboardBranchComparison v-if="branchComparison" :comparison="branchComparison" />
            </div>
        </div>
    </div>
</template>
