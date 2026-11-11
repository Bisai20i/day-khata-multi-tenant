<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import { usePermissions } from '@/composables/usePermissions';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Button from '@/components/ui/Button.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney, moneyEquals } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ payable: '0.00', advance: '0.00' }) },
    reconciliation: { type: [Object, null], default: null },
});

useLayoutChrome('Staff Balances');

const asOf = ref(props.asOf);
const isLoading = ref(false);

// The server falls back to today for a blank or invalid date; follow what it applied.
watch(
    () => props.asOf,
    (applied) => {
        asOf.value = applied;
    },
);

function applyFilter() {
    router.get(
        '/hr/reports/staff-balances',
        asOf.value ? { as_of: asOf.value } : {},
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const exportUrl = computed(() => `/hr/reports/staff-balances/export?${new URLSearchParams({ as_of: props.asOf }).toString()}`);

// Both control accounts, in the order the ledger lists them.
const reconciliationRows = computed(() => {
    if (!props.reconciliation) {
        return [];
    }

    return [
        { key: 'payable', label: 'Salary Payable', ...props.reconciliation.payable },
        { key: 'advance', label: 'Staff Advances Receivable', ...props.reconciliation.advance },
    ];
});

function agrees(entry) {
    return moneyEquals(entry.difference, '0.00');
}

const headerClass = 'px-[9px] py-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase whitespace-nowrap';
const cellClass = 'border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle first:border-l-[1.5px] last:border-r-[1.5px]';
const numericCellClass = `${cellClass} text-right whitespace-nowrap [font-variant-numeric:tabular-nums]`;
const totalCellClass = 'border-y-[1.5px] border-border bg-primary-tint/40 px-[9px] py-2 font-bold text-text-strong first:border-l-[1.5px] last:border-r-[1.5px]';
</script>

<template>
    <div>
        <PageHeader
            title="Staff Balances"
            description="What the business owes each staff member in unpaid salary, and what each one owes back in advances, as at a date."
        >
            <Button v-if="can('hr_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export Excel
            </Button>
        </PageHeader>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Balances as at BS {{ formatBsDate(props.asOf) }} (AD {{ props.asOf }})
        </p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <Label class="mb-1">As at date (BS)</Label>
                    <NepaliDateInput v-model="asOf" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
            </div>
        </Card>

        <Card variant="panel" class="mb-4">
            <div v-if="rows.length === 0" class="px-6 py-16 text-center text-[13px] font-semibold text-text-base">
                No staff member has a salary payable or advance balance on this date.
            </div>

            <div v-else class="w-full overflow-x-auto">
                <table class="w-full border-separate [border-spacing:0_4px] text-[12.5px] text-text-base">
                    <thead>
                        <tr>
                            <th scope="col" :class="[headerClass, 'text-left']">Code</th>
                            <th scope="col" :class="[headerClass, 'text-left']">Staff</th>
                            <th scope="col" :class="[headerClass, 'text-right']">Salary payable</th>
                            <th scope="col" :class="[headerClass, 'text-right']">Advance receivable</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.staff_id">
                            <td :class="[cellClass, 'whitespace-nowrap']">{{ row.staff_code }}</td>
                            <td :class="cellClass">
                                <Link
                                    v-if="can('hr_reports.view')"
                                    :href="`/hr/staff/${row.staff_id}/statement`"
                                    class="font-semibold text-primary hover:underline"
                                >
                                    {{ row.staff_name }}
                                </Link>
                                <template v-else>{{ row.staff_name }}</template>
                            </td>
                            <td :class="numericCellClass">{{ formatMoney(row.payable) }}</td>
                            <td :class="numericCellClass">{{ formatMoney(row.advance) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td :class="totalCellClass" colspan="2">Total</td>
                            <td :class="[totalCellClass, 'text-right whitespace-nowrap']">{{ formatMoney(totals.payable) }}</td>
                            <td :class="[totalCellClass, 'text-right whitespace-nowrap']">{{ formatMoney(totals.advance) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>

        <Card variant="panel">
            <h2 class="mb-3 text-[13px] font-bold text-text-strong">Ledger reconciliation</h2>

            <p v-if="reconciliation === null" class="rounded border border-border px-3 py-2 text-[12.5px] text-text-muted">
                This date is outside any fiscal year, so there is no ledger balance to compare the staff balances with.
            </p>

            <template v-else>
                <p class="mb-3 text-[12px] text-text-muted">
                    Staff balances compared with the ledger control accounts for fiscal year {{ reconciliation.fiscal_year }}.
                </p>

                <div class="w-full overflow-x-auto">
                    <table class="w-full border-separate [border-spacing:0_4px] text-[12.5px] text-text-base">
                        <thead>
                            <tr>
                                <th scope="col" :class="[headerClass, 'text-left']">Account</th>
                                <th scope="col" :class="[headerClass, 'text-right']">Ledger balance</th>
                                <th scope="col" :class="[headerClass, 'text-right']">Staff-ledger total</th>
                                <th scope="col" :class="[headerClass, 'text-right']">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entry in reconciliationRows" :key="entry.key">
                                <td :class="cellClass">{{ entry.label }}</td>
                                <td :class="numericCellClass">{{ formatMoney(entry.ledger) }}</td>
                                <td :class="numericCellClass">{{ formatMoney(entry.staff) }}</td>
                                <td :class="[numericCellClass, 'font-semibold']">{{ formatMoney(entry.difference) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 space-y-2">
                    <p
                        v-for="entry in reconciliationRows"
                        :key="`state-${entry.key}`"
                        class="rounded border px-3 py-2 text-[12.5px] font-semibold"
                        :class="agrees(entry)
                            ? 'border-success-text-soft bg-success-bg-soft text-success'
                            : 'border-warning-text bg-warning-bg text-warning-text'"
                    >
                        <template v-if="agrees(entry)">{{ entry.label }}: Agrees with the ledger</template>
                        <template v-else>
                            {{ entry.label }}: Differs from the ledger by {{ formatMoney(entry.difference) }} - check for manual entries
                        </template>
                    </p>
                </div>
            </template>
        </Card>
    </div>
</template>
