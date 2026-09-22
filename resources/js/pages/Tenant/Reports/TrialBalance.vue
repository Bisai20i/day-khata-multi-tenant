<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { formatMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    accounts: { type: Array, default: () => [] },
    accountId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    heads: { type: Array, default: () => [] },
    totalOpeningDebit: { type: String, default: '0.00' },
    totalOpeningCredit: { type: String, default: '0.00' },
    totalPeriodDebit: { type: String, default: '0.00' },
    totalPeriodCredit: { type: String, default: '0.00' },
    totalDebit: { type: String, default: '0.00' },
    totalCredit: { type: String, default: '0.00' },
    inBalance: { type: Boolean, default: true },
});

useLayoutChrome('Trial Balance');

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

const fiscalYear = ref(props.fiscalYearId);
const from = ref(props.from);
const to = ref(props.to);
const accountId = ref(props.accountId);

// Legacy's `?accno=` single-ledger filter (audit T15-5): "All accounts" is
// the default, matching the unfiltered trial balance.
const accountOptions = computed(() => [
    { value: null, label: 'All accounts' },
    ...props.accounts.map((account) => ({ value: account.id, label: `${account.name} · ${account.code ?? '-'}` })),
]);

// The window only ever means "inside the chosen year", so switching years
// resets it rather than carrying dates that now fall outside.
watch(fiscalYear, (value) => {
    const chosen = props.fiscalYears.find((year) => year.id === value);
    from.value = chosen?.startDate ?? null;
    to.value = chosen?.endDate ?? null;
    apply();
});

function apply() {
    router.get(
        window.location.pathname,
        {
            fiscal_year_id: fiscalYear.value ?? undefined,
            from: from.value ?? undefined,
            to: to.value ?? undefined,
            account_id: accountId.value ?? undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function printUrl() {
    const params = new URLSearchParams({
        fiscal_year_id: fiscalYear.value ?? '',
        from: from.value ?? '',
        to: to.value ?? '',
        account_id: accountId.value ?? '',
    });
    return `/reports/trial-balance/print?${params.toString()}`;
}

function exportUrl() {
    const params = new URLSearchParams({
        fiscal_year_id: fiscalYear.value ?? '',
        from: from.value ?? '',
        to: to.value ?? '',
        account_id: accountId.value ?? '',
    });
    return `/reports/trial-balance/export?${params.toString()}`;
}
</script>

<template>
    <div>
        <PageHeader
            title="Trial Balance"
            description="Total debits and credits of every account. The two totals must match."
        >
            <template v-if="fiscalYearId !== null">
                <a :href="printUrl()" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print</Button></a>
                <a :href="exportUrl()"><Button variant="secondary" tone="purple">Export</Button></a>
            </template>
        </PageHeader>

        <Card v-if="fiscalYearId !== null" variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Fiscal year</label>
                    <Select v-model="fiscalYear" :options="fiscalYearOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From date (BS)</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To date (BS)</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-64">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Account</label>
                    <Select v-model="accountId" :options="accountOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="apply">Generate report</Button>
                <Button v-if="accountId !== null" variant="secondary" tone="purple" @click="accountId = null; apply()">Reset</Button>
            </div>
            <p v-if="from && to" class="mt-2 text-[12px] text-text-muted">
                Showing report for {{ formatBsDate(from) }} to {{ formatBsDate(to) }} BS
                <span class="text-text-muted">({{ from }} to {{ to }})</span>
            </p>
        </Card>

        <Card variant="panel">
            <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No fiscal year has been created yet.
            </p>

            <template v-else>
                <p
                    v-if="!inBalance"
                    class="mb-3 rounded border border-danger px-3 py-2 text-[12.5px] font-semibold text-danger"
                >
                    This trial balance does not balance. Total debit {{ formatMoney(totalDebit) }} against total credit
                    {{ formatMoney(totalCredit) }}.
                </p>

                <p v-if="heads.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                    No transactions in this period. Try widening the date range.
                </p>

                <div v-else class="overflow-x-auto">
                    <div class="min-w-[720px] divide-y divide-border">
                        <div class="flex items-center px-1 pb-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                            <div class="flex-1">Account</div>
                            <div class="w-28 text-right">Opening Debit (Dr)</div>
                            <div class="w-28 text-right">Opening Credit (Cr)</div>
                            <div class="w-28 text-right">Period Debit (Dr)</div>
                            <div class="w-28 text-right">Period Credit (Cr)</div>
                            <div class="w-28 text-right">Closing Debit (Dr)</div>
                            <div class="w-28 text-right">Closing Credit (Cr)</div>
                        </div>

                        <template v-for="head in heads" :key="head.name">
                            <div class="px-1 py-1.5 text-[13px] font-bold text-text-strong">{{ head.name }}</div>

                            <template v-for="group in head.groups" :key="group.name">
                                <div class="px-1 py-1 pl-4 text-[13px] font-semibold text-text-base">{{ group.name }}</div>

                                <div
                                    v-for="account in group.accounts"
                                    :key="`acc-${account.id}`"
                                    class="flex items-center px-1 py-1 pl-8 text-[13px] text-text-base"
                                >
                                    <div class="flex-1">{{ account.name }} <span class="text-text-muted">· {{ account.code ?? '-' }}</span></div>
                                    <div class="w-28 text-right">{{ formatMoney(account.openingDebit) }}</div>
                                    <div class="w-28 text-right">{{ formatMoney(account.openingCredit) }}</div>
                                    <div class="w-28 text-right">{{ formatMoney(account.periodDebit) }}</div>
                                    <div class="w-28 text-right">{{ formatMoney(account.periodCredit) }}</div>
                                    <div class="w-28 text-right">{{ formatMoney(account.closingDebit) }}</div>
                                    <div class="w-28 text-right">{{ formatMoney(account.closingCredit) }}</div>
                                </div>

                                <template v-for="subgroup in group.subgroups" :key="subgroup.name">
                                    <div class="px-1 py-1 pl-8 text-[13px] font-semibold text-text-base">{{ subgroup.name }}</div>

                                    <div
                                        v-for="account in subgroup.accounts"
                                        :key="`acc-${account.id}`"
                                        class="flex items-center px-1 py-1 pl-12 text-[13px] text-text-base"
                                    >
                                        <div class="flex-1">{{ account.name }} <span class="text-text-muted">· {{ account.code ?? '-' }}</span></div>
                                        <div class="w-28 text-right">{{ formatMoney(account.openingDebit) }}</div>
                                        <div class="w-28 text-right">{{ formatMoney(account.openingCredit) }}</div>
                                        <div class="w-28 text-right">{{ formatMoney(account.periodDebit) }}</div>
                                        <div class="w-28 text-right">{{ formatMoney(account.periodCredit) }}</div>
                                        <div class="w-28 text-right">{{ formatMoney(account.closingDebit) }}</div>
                                        <div class="w-28 text-right">{{ formatMoney(account.closingCredit) }}</div>
                                    </div>
                                </template>
                            </template>
                        </template>

                        <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                            <div class="flex-1">Total</div>
                            <div class="w-28 text-right">{{ formatMoney(totalOpeningDebit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalOpeningCredit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalPeriodDebit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalPeriodCredit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalDebit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalCredit) }}</div>
                        </div>
                    </div>
                </div>
            </template>
        </Card>
    </div>
</template>
