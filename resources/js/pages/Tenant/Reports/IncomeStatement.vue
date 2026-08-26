<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    income: { type: Array, default: () => [] },
    expenses: { type: Array, default: () => [] },
    totalIncome: { type: Number, default: 0 },
    totalExpenses: { type: Number, default: 0 },
    netProfit: { type: Number, default: 0 },
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

function onFiscalYearChange(value) {
    router.get(window.location.pathname, { fiscal_year_id: value }, { preserveState: true, preserveScroll: true });
}

function money(value) {
    return Number(value ?? 0).toFixed(2);
}
</script>

<template>
    <AppLayout title="Income Statement" :nav-items="navItems">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold text-text-strong">Income Statement</h2>
            <div class="w-56" v-if="fiscalYearId !== null">
                <Select :model-value="fiscalYearId" :options="fiscalYearOptions" @update:model-value="onFiscalYearChange" />
            </div>
        </div>

        <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No fiscal year has been created yet.
        </p>

        <template v-else>
            <Card variant="panel" title="Income" class="mb-4">
                <div v-if="income.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">No income recorded.</div>
                <div v-else class="divide-y divide-border">
                    <div
                        v-for="account in income"
                        :key="`income-${account.id}`"
                        class="flex items-center px-1 py-1.5 text-[13px] text-text-base"
                    >
                        <div class="flex-1">{{ account.name }} <span class="text-text-muted">· {{ account.code ?? '—' }}</span></div>
                        <div class="w-28 text-right">{{ money(account.amount) }}</div>
                    </div>
                    <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">Total Income</div>
                        <div class="w-28 text-right">{{ money(totalIncome) }}</div>
                    </div>
                </div>
            </Card>

            <Card variant="panel" title="Expenses" class="mb-4">
                <div v-if="expenses.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">No expenses recorded.</div>
                <div v-else class="divide-y divide-border">
                    <div
                        v-for="account in expenses"
                        :key="`expense-${account.id}`"
                        class="flex items-center px-1 py-1.5 text-[13px] text-text-base"
                    >
                        <div class="flex-1">{{ account.name }} <span class="text-text-muted">· {{ account.code ?? '—' }}</span></div>
                        <div class="w-28 text-right">{{ money(account.amount) }}</div>
                    </div>
                    <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">Total Expenses</div>
                        <div class="w-28 text-right">{{ money(totalExpenses) }}</div>
                    </div>
                </div>
            </Card>

            <Card variant="panel">
                <div class="flex items-center px-1 py-1 text-[14px] font-bold" :class="netProfit >= 0 ? 'text-success' : 'text-danger'">
                    <div class="flex-1">{{ netProfit >= 0 ? 'Net Profit' : 'Net Loss' }}</div>
                    <div class="w-28 text-right">{{ money(Math.abs(netProfit)) }}</div>
                </div>
            </Card>
        </template>
    </AppLayout>
</template>
