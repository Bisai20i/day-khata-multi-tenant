<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { formatMoney, isZeroMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    heads: { type: Array, default: () => [] },
    stock: { type: Object, default: () => ({ opening: '0.00', closing: '0.00', posted: false }) },
    currentYearEarnings: { type: String, default: '0.00' },
    totalAssets: { type: String, default: '0.00' },
    totalLiabilitiesAndCapital: { type: String, default: '0.00' },
    balanceWarning: { type: [String, null], default: null },
});

useLayoutChrome('Balance Sheet');

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

const fiscalYear = ref(props.fiscalYearId);
const to = ref(props.to);

watch(fiscalYear, (value) => {
    const chosen = props.fiscalYears.find((year) => year.id === value);
    to.value = chosen?.endDate ?? null;
    apply();
});

function apply() {
    router.get(
        window.location.pathname,
        { fiscal_year_id: fiscalYear.value ?? undefined, to: to.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const assetHeads = computed(() => props.heads.filter((head) => head.name === 'Assets'));
const otherHeads = computed(() => props.heads.filter((head) => head.name !== 'Assets'));
const hasEarnings = computed(() => !isZeroMoney(props.currentYearEarnings));

function printUrl() {
    const params = new URLSearchParams({ fiscal_year_id: fiscalYear.value ?? '', to: to.value ?? '' });
    return `/reports/balance-sheet/print?${params.toString()}`;
}

function exportUrl() {
    const params = new URLSearchParams({ fiscal_year_id: fiscalYear.value ?? '', to: to.value ?? '' });
    return `/reports/balance-sheet/export?${params.toString()}`;
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold text-text-strong">Balance Sheet</h2>
            <div v-if="fiscalYearId !== null" class="flex items-center gap-2">
                <a :href="printUrl()" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print</Button></a>
                <a :href="exportUrl()"><Button variant="secondary" tone="purple">Export</Button></a>
            </div>
        </div>

        <Card v-if="fiscalYearId !== null" variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Fiscal Year</label>
                    <Select v-model="fiscalYear" :options="fiscalYearOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">As at</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <Button variant="primary" tone="purple" @click="apply">Apply</Button>
            </div>
            <p v-if="to" class="mt-2 text-[12px] text-text-muted">
                As at {{ formatBsDate(to) }} BS <span class="text-text-muted">({{ to }})</span>
            </p>
        </Card>

        <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No fiscal year has been created yet.
        </p>

        <template v-else>
            <p
                v-if="balanceWarning"
                class="mb-4 rounded border border-danger px-3 py-2 text-[12.5px] font-semibold text-danger"
            >
                {{ balanceWarning }}
            </p>

            <div class="grid gap-4 md:grid-cols-2">
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
                                    <div class="w-32 text-right">{{ formatMoney(account.debit) }}</div>
                                </div>
                                <template v-for="subgroup in group.subgroups" :key="subgroup.name">
                                    <div class="px-1 py-1 pl-4 text-[13px] font-semibold text-text-base">{{ subgroup.name }}</div>
                                    <div
                                        v-for="account in subgroup.accounts"
                                        :key="`acc-${account.id}`"
                                        class="flex items-center px-1 py-1 pl-8 text-[13px] text-text-base"
                                    >
                                        <div class="flex-1">{{ account.name }}</div>
                                        <div class="w-32 text-right">{{ formatMoney(account.debit) }}</div>
                                    </div>
                                </template>
                            </template>
                        </template>
                        <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                            <div class="flex-1">Total Assets</div>
                            <div class="w-32 text-right">{{ formatMoney(totalAssets) }}</div>
                        </div>
                    </div>
                    <p v-if="!stock.posted" class="mt-2 text-[12px] text-text-muted">
                        Stock in Hand is valued live at {{ formatMoney(stock.closing) }} as at {{ stock.asOf }}, because
                        this fiscal year has not been closed yet. Closing the year posts exactly that amount.
                    </p>
                </Card>

                <Card variant="panel" title="Liabilities &amp; Capital">
                    <div v-if="otherHeads.length === 0 && !hasEarnings" class="px-1 py-6 text-center text-[13px] text-text-muted">
                        No liability/capital balances.
                    </div>
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
                                    <div class="w-32 text-right">{{ formatMoney(account.credit) }}</div>
                                </div>
                                <template v-for="subgroup in group.subgroups" :key="subgroup.name">
                                    <div class="px-1 py-1 pl-8 text-[13px] font-semibold text-text-base">{{ subgroup.name }}</div>
                                    <div
                                        v-for="account in subgroup.accounts"
                                        :key="`acc-${account.id}`"
                                        class="flex items-center px-1 py-1 pl-12 text-[13px] text-text-base"
                                    >
                                        <div class="flex-1">{{ account.name }}</div>
                                        <div class="w-32 text-right">{{ formatMoney(account.credit) }}</div>
                                    </div>
                                </template>
                            </template>
                        </template>
                        <div v-if="hasEarnings" class="flex items-center px-1 py-1 pl-4 text-[13px] text-text-base">
                            <div class="flex-1">Current Year Earnings (unaudited)</div>
                            <div class="w-32 text-right">{{ formatMoney(currentYearEarnings) }}</div>
                        </div>
                        <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                            <div class="flex-1">Total Liabilities &amp; Capital</div>
                            <div class="w-32 text-right">{{ formatMoney(totalLiabilitiesAndCapital) }}</div>
                        </div>
                    </div>
                </Card>
            </div>
        </template>
    </div>
</template>
