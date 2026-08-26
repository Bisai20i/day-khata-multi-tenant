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
    heads: { type: Array, default: () => [] },
    currentYearEarnings: { type: Number, default: 0 },
    totalAssets: { type: Number, default: 0 },
    totalLiabilitiesAndCapital: { type: Number, default: 0 },
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

const assetHeads = computed(() => props.heads.filter((head) => head.name === 'Assets'));
const otherHeads = computed(() => props.heads.filter((head) => head.name !== 'Assets'));
</script>

<template>
    <AppLayout title="Balance Sheet" :nav-items="navItems">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold text-text-strong">Balance Sheet</h2>
            <div class="w-56" v-if="fiscalYearId !== null">
                <Select :model-value="fiscalYearId" :options="fiscalYearOptions" @update:model-value="onFiscalYearChange" />
            </div>
        </div>

        <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No fiscal year has been created yet.
        </p>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <Card variant="panel" title="Assets">
                <div v-if="assetHeads.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">No asset balances.</div>
                <div v-else class="divide-y divide-border">
                    <template v-for="head in assetHeads" :key="head.name">
                        <template v-for="group in head.groups" :key="group.name">
                            <div class="px-1 py-1 text-[13px] font-semibold text-text-base">{{ group.name }}</div>
                            <div
                                v-for="account in group.accounts"
                                :key="`acc-${account.id}`"
                                class="flex items-center px-1 py-1 pl-4 text-[13px] text-text-base"
                            >
                                <div class="flex-1">{{ account.name }}</div>
                                <div class="w-28 text-right">{{ money(account.debit) }}</div>
                            </div>
                            <template v-for="subgroup in group.subgroups" :key="subgroup.name">
                                <div class="px-1 py-1 pl-4 text-[13px] font-semibold text-text-base">{{ subgroup.name }}</div>
                                <div
                                    v-for="account in subgroup.accounts"
                                    :key="`acc-${account.id}`"
                                    class="flex items-center px-1 py-1 pl-8 text-[13px] text-text-base"
                                >
                                    <div class="flex-1">{{ account.name }}</div>
                                    <div class="w-28 text-right">{{ money(account.debit) }}</div>
                                </div>
                            </template>
                        </template>
                    </template>
                    <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">Total Assets</div>
                        <div class="w-28 text-right">{{ money(totalAssets) }}</div>
                    </div>
                </div>
            </Card>

            <Card variant="panel" title="Liabilities & Capital">
                <div v-if="otherHeads.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">No liability/capital balances.</div>
                <div v-else class="divide-y divide-border">
                    <template v-for="head in otherHeads" :key="head.name">
                        <div class="px-1 py-1.5 text-[13px] font-bold text-text-strong">{{ head.name }}</div>
                        <template v-for="group in head.groups" :key="group.name">
                            <div class="px-1 py-1 pl-4 text-[13px] font-semibold text-text-base">{{ group.name }}</div>
                            <div
                                v-for="account in group.accounts"
                                :key="`acc-${account.id}`"
                                class="flex items-center px-1 py-1 pl-8 text-[13px] text-text-base"
                            >
                                <div class="flex-1">{{ account.name }}</div>
                                <div class="w-28 text-right">{{ money(account.credit) }}</div>
                            </div>
                            <template v-for="subgroup in group.subgroups" :key="subgroup.name">
                                <div class="px-1 py-1 pl-8 text-[13px] font-semibold text-text-base">{{ subgroup.name }}</div>
                                <div
                                    v-for="account in subgroup.accounts"
                                    :key="`acc-${account.id}`"
                                    class="flex items-center px-1 py-1 pl-12 text-[13px] text-text-base"
                                >
                                    <div class="flex-1">{{ account.name }}</div>
                                    <div class="w-28 text-right">{{ money(account.credit) }}</div>
                                </div>
                            </template>
                        </template>
                    </template>
                    <div v-if="currentYearEarnings !== 0" class="flex items-center px-1 py-1 pl-4 text-[13px] text-text-base">
                        <div class="flex-1">Current Year Earnings (unaudited)</div>
                        <div class="w-28 text-right">{{ money(currentYearEarnings) }}</div>
                    </div>
                    <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">Total Liabilities &amp; Capital</div>
                        <div class="w-28 text-right">{{ money(totalLiabilitiesAndCapital) }}</div>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
