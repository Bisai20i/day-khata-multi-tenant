<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { navGroups } from '@/lib/nav-items.js';
import { formatMoney, compareMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    income: { type: Array, default: () => [] },
    expenses: { type: Array, default: () => [] },
    stock: { type: Object, default: () => ({ opening: '0.00', closing: '0.00', posted: false }) },
    totalIncome: { type: String, default: '0.00' },
    totalExpenses: { type: String, default: '0.00' },
    grossProfit: { type: String, default: '0.00' },
    netProfit: { type: String, default: '0.00' },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

const fiscalYear = ref(props.fiscalYearId);
const from = ref(props.from);
const to = ref(props.to);

watch(fiscalYear, (value) => {
    const chosen = props.fiscalYears.find((year) => year.id === value);
    from.value = chosen?.startDate ?? null;
    to.value = chosen?.endDate ?? null;
    apply();
});

function apply() {
    router.get(
        window.location.pathname,
        { fiscal_year_id: fiscalYear.value ?? undefined, from: from.value ?? undefined, to: to.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

// Sign tests run through the money helpers, never through Number().
const isProfit = computed(() => compareMoney(props.netProfit, '0.00') >= 0);
const isGrossProfit = computed(() => compareMoney(props.grossProfit, '0.00') >= 0);
const absNetProfit = computed(() => (isProfit.value ? props.netProfit : props.netProfit.replace('-', '')));
const absGrossProfit = computed(() => (isGrossProfit.value ? props.grossProfit : props.grossProfit.replace('-', '')));
</script>

<template>
    <AppLayout title="Income Statement" :nav-items="navItems">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold text-text-strong">Income Statement</h2>
        </div>

        <Card v-if="fiscalYearId !== null" variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Fiscal Year</label>
                    <Select v-model="fiscalYear" :options="fiscalYearOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <Button variant="primary" tone="purple" @click="apply">Apply</Button>
            </div>
            <p v-if="from && to" class="mt-2 text-[12px] text-text-muted">
                {{ formatBsDate(from) }} to {{ formatBsDate(to) }} BS
                <span class="text-text-muted">({{ from }} to {{ to }})</span>
            </p>
        </Card>

        <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No fiscal year has been created yet.
        </p>

        <template v-else>
            <Card variant="panel" title="Trading Account" class="mb-4">
                <div class="divide-y divide-border">
                    <div class="flex items-center px-1 py-1.5 text-[13px] text-text-base">
                        <div class="flex-1">Opening Stock</div>
                        <div class="w-32 text-right">{{ formatMoney(stock.opening) }}</div>
                    </div>
                    <div class="flex items-center px-1 py-1.5 text-[13px] text-text-base">
                        <div class="flex-1">
                            Closing Stock
                            <span v-if="!stock.posted" class="text-text-muted">· valued as at {{ stock.asOf }}</span>
                        </div>
                        <div class="w-32 text-right">{{ formatMoney(stock.closing) }}</div>
                    </div>
                    <div class="flex items-center px-1 pt-2 text-[13px] font-bold" :class="isGrossProfit ? 'text-success' : 'text-danger'">
                        <div class="flex-1">{{ isGrossProfit ? 'Gross Profit' : 'Gross Loss' }}</div>
                        <div class="w-32 text-right">{{ formatMoney(absGrossProfit) }}</div>
                    </div>
                </div>
                <p v-if="!stock.posted" class="mt-2 text-[12px] text-text-muted">
                    This fiscal year has not been closed yet, so the stock figures above are valued live from stock
                    movements rather than read from posted entries. Closing the year posts exactly these amounts.
                </p>
            </Card>

            <Card variant="panel" title="Income" class="mb-4">
                <div v-if="income.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">No income recorded.</div>
                <div v-else class="divide-y divide-border">
                    <div
                        v-for="account in income"
                        :key="`income-${account.id ?? account.code}`"
                        class="flex items-center px-1 py-1.5 text-[13px] text-text-base"
                    >
                        <div class="flex-1">
                            {{ account.name }} <span class="text-text-muted">· {{ account.code ?? '—' }}</span>
                            <span v-if="account.computed" class="text-text-muted">· computed</span>
                        </div>
                        <div class="w-32 text-right">{{ formatMoney(account.amount) }}</div>
                    </div>
                    <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">Total Income</div>
                        <div class="w-32 text-right">{{ formatMoney(totalIncome) }}</div>
                    </div>
                </div>
            </Card>

            <Card variant="panel" title="Expenses" class="mb-4">
                <div v-if="expenses.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">No expenses recorded.</div>
                <div v-else class="divide-y divide-border">
                    <div
                        v-for="account in expenses"
                        :key="`expense-${account.id ?? account.code}`"
                        class="flex items-center px-1 py-1.5 text-[13px] text-text-base"
                    >
                        <div class="flex-1">
                            {{ account.name }} <span class="text-text-muted">· {{ account.code ?? '—' }}</span>
                            <span v-if="account.computed" class="text-text-muted">· computed</span>
                        </div>
                        <div class="w-32 text-right">{{ formatMoney(account.amount) }}</div>
                    </div>
                    <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">Total Expenses</div>
                        <div class="w-32 text-right">{{ formatMoney(totalExpenses) }}</div>
                    </div>
                </div>
            </Card>

            <Card variant="panel">
                <div class="flex items-center px-1 py-1 text-[14px] font-bold" :class="isProfit ? 'text-success' : 'text-danger'">
                    <div class="flex-1">{{ isProfit ? 'Net Profit' : 'Net Loss' }}</div>
                    <div class="w-32 text-right">{{ formatMoney(absNetProfit) }}</div>
                </div>
            </Card>
        </template>
    </AppLayout>
</template>
