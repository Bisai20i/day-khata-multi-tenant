<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import LedgerEntriesTable from '@/components/accounting/LedgerEntriesTable.vue';
import { formatMoney } from '@/lib/money';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    account: {
        type: Object,
        required: true,
    },
    // Area of the customer / supplier this account belongs to, when it has one.
    partyArea: {
        type: [String, null],
        default: null,
    },
    fiscalYears: {
        type: Array,
        default: () => [],
    },
    fiscalYearId: {
        type: [Number, null],
        default: null,
    },
    from: {
        type: [String, null],
        default: null,
    },
    to: {
        type: [String, null],
        default: null,
    },
    openingBalance: {
        type: String,
        default: '0.00',
    },
    closingBalance: {
        type: String,
        default: '0.00',
    },
    // Dr / Cr side of the opening and closing balances ('' when zero).
    openingSide: {
        type: String,
        default: '',
    },
    closingSide: {
        type: String,
        default: '',
    },
    // Totals of the debit / credit columns over the selected window.
    periodDebit: {
        type: String,
        default: '0.00',
    },
    periodCredit: {
        type: String,
        default: '0.00',
    },
    entries: {
        type: Array,
        default: () => [],
    },
    // Computed per account by AccountController::ledger() (account_ledger.X,
    // or party_ledger.X on a customer/supplier account), the same rule the
    // print and export routes enforce.
    canPrint: {
        type: Boolean,
        default: false,
    },
    canExport: {
        type: Boolean,
        default: false,
    },
    // Branch filter (opening balance and lines); null when the company has no branches.
    branchReport: {
        type: [Object, null],
        default: null,
    },
});

// "1,250.00 Dr" / "1,250.00 Cr"; zero prints "0.00" with no side, never "-0.00".
function balanceText(balance, side) {
    const amount = formatMoney(balance).replace(/^-/, '');

    return side ? `${amount} ${side}` : amount;
}

useLayoutChrome(() => `Ledger - ${props.account.name}`);

// An arbitrary date window inside the chosen fiscal year (T14 task 3) - the
// backend clamps whatever is sent here to the year's own start/end and
// recomputes the opening balance for the window (never just resets it to
// zero), so a mid-year "From" still opens at the right running total.
const fromInput = ref(props.from);
const toInput = ref(props.to);
const branchFilter = useBranchReportFilter(() => props.branchReport);

watch(
    () => props.from,
    (v) => (fromInput.value = v),
);

watch(
    () => props.to,
    (v) => (toInput.value = v),
);

// The server always sends the fiscal year's own start/end, so "Clear filters"
// only matters once the applied window differs from them.
const hasCustomWindow = computed(() => {
    const year = props.fiscalYears.find((candidate) => candidate.id === props.fiscalYearId);

    if (!year) {
        return Boolean(props.from || props.to);
    }

    return (props.from !== null && props.from !== year.start_date) || (props.to !== null && props.to !== year.end_date);
});

function applyWindow() {
    router.get(
        window.location.pathname,
        { fiscal_year_id: props.fiscalYearId, from: fromInput.value ?? undefined, to: toInput.value ?? undefined, ...branchFilter.toQuery() },
        { preserveState: true, preserveScroll: true },
    );
}

function clearWindow() {
    fromInput.value = null;
    toInput.value = null;
    applyWindow();
}

// Print / Export follow the applied filters (props), not unapplied edits.
function reportUrl(suffix) {
    const params = new URLSearchParams({
        fiscal_year_id: props.fiscalYearId ?? '',
        from: props.from ?? '',
        to: props.to ?? '',
    });
    branchFilter.appendTo(params);
    return `${window.location.pathname}/${suffix}?${params.toString()}`;
}

const printUrl = () => reportUrl('print');
const exportUrl = () => reportUrl('export');

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

function onFiscalYearChange(value) {
    // A new fiscal year has its own start/end, so any window chosen for the
    // previous one is dropped rather than sent along and silently clamped.
    fromInput.value = null;
    toInput.value = null;
    router.get(
        window.location.pathname,
        { fiscal_year_id: value, ...branchFilter.toQuery() },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <div>
        <PageHeader
            :title="`Ledger: ${account.name}${account.code ? ' (' + account.code + ')' : ''}`"
            description="Every debit and credit posted to this account, with a running balance. Pick a fiscal year and optionally narrow to a date range."
            back-href="/accounts"
            back-label="Back to accounts"
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="account-ledger"
                hide-label
                @change="applyWindow"
            />
            <template v-if="fiscalYearId !== null">
                <a v-if="canPrint" :href="printUrl()" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print ledger</Button></a>
                <a v-if="canExport" :href="exportUrl()"><Button variant="secondary" tone="purple">Export ledger</Button></a>
            </template>
        </PageHeader>

        <p v-if="partyArea" class="mb-3 text-[13px] text-text-muted">
            Area: <span class="font-semibold text-text-strong">{{ partyArea }}</span>
        </p>

        <Card v-if="fiscalYearId !== null" variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <Label class="mb-1">Fiscal year</Label>
                    <Select :model-value="fiscalYearId" :options="fiscalYearOptions" @update:model-value="onFiscalYearChange" />
                </div>
                <div>
                    <Label class="mb-1">From date</Label>
                    <NepaliDateInput v-model="fromInput" />
                </div>
                <div>
                    <Label class="mb-1">To date</Label>
                    <NepaliDateInput v-model="toInput" />
                </div>
                <Button variant="primary" tone="purple" @click="applyWindow">Apply filters</Button>
                <Button v-if="hasCustomWindow" variant="secondary" tone="purple" @click="clearWindow">Clear filters</Button>
            </div>
            <p class="mt-2 text-xs text-text-muted">Dates outside the chosen fiscal year are trimmed to its start and end. The opening balance adjusts to your From date.</p>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <Card v-if="fiscalYearId !== null" variant="panel" class="mb-4">
            <div class="flex flex-wrap justify-end gap-6 text-[13px]">
                <div>
                    <span class="text-text-muted">Opening balance:</span>
                    <span class="font-semibold tabular-nums">{{ balanceText(openingBalance, openingSide) }}</span>
                </div>
                <div>
                    <span class="text-text-muted">Closing balance:</span>
                    <span class="font-semibold tabular-nums">{{ balanceText(closingBalance, closingSide) }}</span>
                </div>
            </div>
        </Card>

        <Card variant="panel">
            <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No fiscal year has been created yet. Create one under Fiscal Years to start seeing ledger activity here.
            </p>
            <LedgerEntriesTable
                v-else
                :entries="entries"
                :opening-balance="openingBalance"
                :opening-side="openingSide"
                :closing-balance="closingBalance"
                :closing-side="closingSide"
                :period-debit="periodDebit"
                :period-credit="periodCredit"
                :from="props.from"
                empty-message="No activity in this period. Try a wider date range or another fiscal year."
            />
        </Card>
    </div>
</template>
