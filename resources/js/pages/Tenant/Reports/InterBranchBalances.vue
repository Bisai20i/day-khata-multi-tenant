<script setup>
import { computed, h, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import InterBranchPairsTable from '@/components/branch/InterBranchPairsTable.vue';
import { formatMoney, isZeroMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
import { useBranchLabel } from '@/composables/useBranchLabel';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { usePermissions } from '@/composables/usePermissions';

/**
 * Inter-branch balances (plan 12, BR19, InterBranchBalancesReportController):
 * who owes whom between branches as at a date and whether both sides of each
 * pair agree, the transfers between branches in transit or received, and the
 * stock transfer accounts against the transfers themselves. Every figure is
 * an exact decimal string from the server; the page only formats.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    // BranchFilter payload; null when the company is not multi-branch.
    branchReport: { type: [Object, null], default: null },
    // The inter-branch accounts are missing from the chart (migrations not run).
    missingAccounts: { type: Boolean, default: false },
    pairs: { type: Array, default: () => [] },
    unattributed: { type: Array, default: () => [] },
    // [{ branchId, branchName, amount, direction: 'receivable'|'payable'|'settled' }]
    positions: { type: Array, default: () => [] },
    // { total, inTransit, unexplained }; only for a user who sees every branch, unnarrowed.
    companyCheck: { type: [Object, null], default: null },
    // [{ id, date, receivedOn, from, to, value, state: 'in_transit'|'received', currentStatus }]
    transfers: { type: Array, default: () => [] },
    // { transferred: {ledger, records, difference}, received: {...}, net, expectedNet, unexplained,
    //   perpetual: { inTransit, dispatched, received } | null }; transferred/received are the periodic share (BR25).
    stockAccounts: { type: [Object, null], default: null },
});

const { label, plural } = useBranchLabel();
const { can } = usePermissions();

useLayoutChrome(`Inter-${label('lower')} Balances`);

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

const fiscalYear = ref(props.fiscalYearId);
const from = ref(props.from);
const to = ref(props.to);
const branchFilter = useBranchReportFilter(() => props.branchReport);

watch(
    () => [props.fiscalYearId, props.from, props.to],
    () => {
        fiscalYear.value = props.fiscalYearId;
        from.value = props.from;
        to.value = props.to;
    },
);

function apply(overrides = {}) {
    router.get(
        window.location.pathname,
        {
            fiscal_year_id: fiscalYear.value ?? undefined,
            from: from.value ?? undefined,
            to: to.value ?? undefined,
            ...branchFilter.toQuery(),
            ...overrides,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function changeFiscalYear(value) {
    fiscalYear.value = value;
    // The server picks the new year's window.
    apply({ from: undefined, to: undefined });
}

const isNonZero = (value) => value !== null && value !== undefined && !isZeroMoney(value);

const positionText = (row) =>
    row.direction === 'settled'
        ? 'Settled'
        : row.direction === 'receivable'
          ? `Owed to ${row.branchName} ${formatMoney(row.amount)}`
          : `${row.branchName} owes ${formatMoney(row.amount)}`;

const transferColumns = [
    {
        id: 'id',
        header: 'Transfer',
        cell: ({ row }) =>
            can('stock_transfers.print')
                ? h('a', { href: `/stock-transfers/${row.original.id}/print`, target: '_blank', rel: 'noopener', class: 'text-primary hover:underline' }, `#${row.original.id}`)
                : `#${row.original.id}`,
    },
    {
        id: 'date',
        header: 'Dispatched (BS)',
        cell: ({ row }) => h('span', { class: 'whitespace-nowrap' }, formatBsDate(row.original.date)),
    },
    { id: 'from', header: 'From', cell: ({ row }) => row.original.from },
    { id: 'to', header: 'To', cell: ({ row }) => row.original.to },
    { id: 'value', header: 'Value', numeric: true, cell: ({ row }) => formatMoney(row.original.value) },
    {
        id: 'state',
        header: 'Status',
        cell: ({ row }) => {
            const transfer = row.original;

            if (transfer.state === 'received') {
                return h('span', { class: 'whitespace-nowrap text-success' }, `Received ${formatBsDate(transfer.receivedOn)}`);
            }

            const later = {
                received: transfer.receivedOn ? ` (received ${formatBsDate(transfer.receivedOn)})` : ' (received later)',
                cancelled: ' (cancelled later)',
            }[transfer.currentStatus] ?? '';

            return h('span', { class: 'whitespace-nowrap font-semibold text-warning-text' }, `In transit${later}`);
        },
    },
];

const thClass = 'border-b-[1.5px] border-border px-2 py-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase';
</script>

<template>
    <div>
        <PageHeader
            :title="`Inter-${label('lower')} Balances`"
            :description="`What each ${label('lower')} owes the others through Inter-Branch Clearing, and the stock moving between them.`"
        />

        <p v-if="!branchReport" class="px-1 py-6 text-center text-[13px] text-text-muted">
            This report is available once the company has more than one active {{ label('lower') }}.
        </p>

        <p v-else-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No fiscal year has been created yet.
        </p>

        <template v-else>
            <Card variant="panel" class="mb-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-56">
                        <Label class="mb-1">Fiscal year</Label>
                        <Select :model-value="fiscalYear" :options="fiscalYearOptions" @update:model-value="changeFiscalYear" />
                    </div>
                    <div>
                        <Label class="mb-1">From date (BS)</Label>
                        <NepaliDateInput v-model="from" />
                    </div>
                    <div>
                        <Label class="mb-1">As at (BS)</Label>
                        <NepaliDateInput v-model="to" />
                    </div>
                    <BranchReportFilter v-model:choice="branchFilter.choice.value" :report="branchReport" id-prefix="inter-branch" @change="apply()" />
                    <Button variant="primary" tone="purple" @click="apply()">Generate report</Button>
                </div>
                <p v-if="from && to" class="mt-2 text-[12px] text-text-muted">
                    Balances as at {{ formatBsDate(to) }} BS ({{ to }}); transfers received and stock accounts for
                    {{ formatBsDate(from) }} to {{ formatBsDate(to) }} BS.
                </p>
                <BranchReportNotes :report="branchReport" />
            </Card>

            <p v-if="missingAccounts" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-[13px] text-danger">
                The inter-{{ label('lower') }} accounts are missing from the chart of accounts, so there is nothing to report. Contact support.
            </p>

            <template v-else>
                <div
                    v-if="companyCheck"
                    class="mb-4 border-[1.5px] px-3 py-2 text-[13px]"
                    :class="isNonZero(companyCheck.unexplained) ? 'border-danger bg-danger-bg text-danger' : 'border-border bg-bg-subtle text-text-base'"
                >
                    <template v-if="isNonZero(companyCheck.unexplained)">
                        <span class="font-semibold">Reconciliation difference.</span> Across all {{ plural('lower') }}, Inter-Branch
                        Clearing should net to zero apart from goods in transit ({{ formatMoney(companyCheck.inTransit) }}), but it nets
                        to {{ formatMoney(companyCheck.total) }}: {{ formatMoney(companyCheck.unexplained) }} is unexplained. The
                        year cannot be closed until this is corrected.
                    </template>
                    <template v-else-if="isNonZero(companyCheck.inTransit)">
                        Across all {{ plural('lower') }}, Inter-Branch Clearing nets to {{ formatMoney(companyCheck.total) }}, which is
                        exactly the goods in transit. It returns to zero once they are received.
                    </template>
                    <template v-else>Across all {{ plural('lower') }}, Inter-Branch Clearing nets to zero.</template>
                </div>

                <Card variant="panel" :title="`Who owes whom`" class="mb-4 overflow-x-auto">
                    <InterBranchPairsTable :pairs="pairs" :unattributed="unattributed" />

                    <table v-if="positions.length > 0" class="mt-4 w-full max-w-[640px] text-[13px] text-text-base">
                        <thead>
                            <tr>
                                <th :class="[thClass, 'text-left']">{{ label() }}</th>
                                <th :class="[thClass, 'text-left']">Net clearing balance, all counterparties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in positions" :key="`position-${row.branchId}`" class="border-b border-border/50">
                                <td class="px-2 py-1.5 font-semibold text-text-strong">{{ row.branchName }}</td>
                                <td class="px-2 py-1.5 tabular-nums">{{ positionText(row) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-[12px] text-text-muted">
                        Each side is that {{ label('lower') }}'s own Inter-Branch Clearing balance toward the other, opening balance
                        included. The two sides should mirror each other; a stock transfer in transit is the one expected gap, because
                        it is in the sender's books from dispatch and in the receiver's only once received.
                    </p>
                </Card>

                <Card variant="panel" :title="`Transfers between ${plural('lower')}`" class="mb-4">
                    <div v-if="can('stock_transfers.view')" class="mb-2 flex flex-wrap gap-3 text-[12.5px]">
                        <a href="/stock-transfers?status=in_transit" class="text-primary hover:underline">Open in-transit transfers</a>
                        <a href="/stock-transfers?status=received" class="text-primary hover:underline">Open received transfers</a>
                    </div>
                    <DataTable :columns="transferColumns" :data="transfers" :page-size="15" empty-message="No transfers in transit or received in this period." />
                    <p class="mt-2 text-[12px] text-text-muted">
                        In transit as at {{ formatBsDate(to) }}, then received between {{ formatBsDate(from) }} and {{ formatBsDate(to) }}.
                        Values are what each transfer was valued at when dispatched.
                    </p>
                </Card>

                <Card v-if="stockAccounts" variant="panel" title="Stock transfer accounts" class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-[13px] text-text-base">
                        <thead>
                            <tr>
                                <th :class="[thClass, 'text-left']">Account</th>
                                <th :class="[thClass, 'text-right']">Per ledger</th>
                                <th :class="[thClass, 'text-right']">Per transfers</th>
                                <th :class="[thClass, 'text-right']">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="[key, title] in [['transferred', 'Stock Transferred to Branches (INI23)'], ['received', 'Stock Received from Branches (EXE11)']]"
                                :key="key"
                                class="border-b border-border/50"
                            >
                                <td class="px-2 py-2">{{ title }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ formatMoney(stockAccounts[key].ledger) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ formatMoney(stockAccounts[key].records) }}</td>
                                <td
                                    class="px-2 py-2 text-right tabular-nums"
                                    :class="isNonZero(stockAccounts[key].difference) ? 'font-semibold text-danger' : ''"
                                >
                                    {{ formatMoney(stockAccounts[key].difference) }}
                                </td>
                            </tr>
                            <tr v-if="stockAccounts.perpetual" class="border-b border-border/50">
                                <td class="px-2 py-2">Perpetual (AS12 / AS14) in transit as at {{ formatBsDate(to) }}</td>
                                <td class="px-2 py-2 text-right text-text-muted">-</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ formatMoney(stockAccounts.perpetual.inTransit) }}</td>
                                <td class="px-2 py-2 text-right text-text-muted">-</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-[13px] text-text-base">
                        Transferred less received: <span class="font-semibold tabular-nums">{{ formatMoney(stockAccounts.net) }}</span>
                        <template v-if="stockAccounts.expectedNet !== null">
                            ; change in goods in transit over the period: <span class="tabular-nums">{{ formatMoney(stockAccounts.expectedNet) }}</span>.
                            <span v-if="isNonZero(stockAccounts.unexplained)" class="font-semibold text-danger">
                                {{ formatMoney(stockAccounts.unexplained) }} is not explained by goods in transit.
                            </span>
                        </template>
                    </p>
                    <p class="mt-1 text-[12px] text-text-muted">
                        Per transfers: dispatched less cancelled in the period for the sending side, received in the period for the
                        receiving side. A difference in red means an entry on one of these accounts has no matching transfer.
                        <template v-if="stockAccounts.expectedNet === null">
                            For part of the company the two accounts need not match each other: goods sent to or received from other
                            {{ plural('lower') }} appear on one side only.
                        </template>
                    </p>
                    <p v-if="stockAccounts.perpetual" class="mt-1 text-[12px] text-text-muted">
                        Raw material and finished goods travel at their cost in Raw Material / Finished Goods (AS12 / AS14), not
                        through the two accounts above, so those figures are the trading share only. In the period:
                        {{ formatMoney(stockAccounts.perpetual.dispatched) }} sent less cancelled,
                        {{ formatMoney(stockAccounts.perpetual.received) }} received. While in transit their value sits only in
                        Inter-Branch Clearing; transfer values in the list above include them.
                    </p>
                </Card>
            </template>
        </template>
    </div>
</template>
