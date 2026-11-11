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
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    filters: { type: Object, default: () => ({ from: null, to: null, staff_id: null }) },
    staffOptions: { type: Array, default: () => [] },
    components: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    totals: {
        type: Object,
        default: () => ({ components: {}, gross: '0.00', advance_recovery: '0.00', net: '0.00', paid: '0.00', outstanding: '0.00' }),
    },
});

useLayoutChrome('Payroll Register');

const from = ref(props.filters.from ?? null);
const to = ref(props.filters.to ?? null);
const staffId = ref(props.filters.staff_id ?? null);
const isLoading = ref(false);

// The server answers with the filters it applied (the open fiscal year when
// none were sent), so the inputs follow it after every visit - otherwise Reset
// would leave them blank and stay visible.
watch(
    () => props.filters,
    (applied) => {
        from.value = applied.from ?? null;
        to.value = applied.to ?? null;
        staffId.value = applied.staff_id ?? null;
    },
);

const staffSelectOptions = computed(() => [
    { value: null, label: 'All staff' },
    ...props.staffOptions.map((staff) => ({ value: staff.id, label: `${staff.name} (${staff.staff_code})` })),
]);

// Reset shows for a chosen staff member or when the dates were edited away from what is applied.
const hasActiveFilter = computed(
    () => staffId.value !== null || from.value !== (props.filters.from ?? null) || to.value !== (props.filters.to ?? null),
);

function queryParams(source) {
    const params = {};

    if (source.from) {
        params.from = source.from;
    }

    if (source.to) {
        params.to = source.to;
    }

    if (source.staff_id) {
        params.staff_id = source.staff_id;
    }

    return params;
}

function applyFilter() {
    router.get(
        '/hr/reports/payroll-register',
        queryParams({ from: from.value, to: to.value, staff_id: staffId.value }),
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

function resetFilters() {
    staffId.value = null;
    from.value = null;
    to.value = null;
    applyFilter();
}

// The export mirrors the report as it is currently shown (the applied filters).
const exportUrl = computed(() => {
    const query = new URLSearchParams(queryParams(props.filters)).toString();

    return `/hr/reports/payroll-register/export${query ? `?${query}` : ''}`;
});

const rangeLabel = computed(() => {
    if (!props.filters.from || !props.filters.to) {
        return null;
    }

    return `BS ${formatBsDate(props.filters.from)} to ${formatBsDate(props.filters.to)} (AD ${props.filters.from} to ${props.filters.to})`;
});

function componentAmount(row, component) {
    const amount = row.amounts?.[component.id];

    return amount === undefined || amount === null ? '-' : formatMoney(amount);
}

function componentTotal(component) {
    const amount = props.totals.components?.[component.id];

    return amount === undefined || amount === null ? '-' : formatMoney(amount);
}

const headerClass = 'px-[9px] py-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase whitespace-nowrap';
const cellClass = 'border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle first:border-l-[1.5px] last:border-r-[1.5px]';
const numericCellClass = `${cellClass} text-right whitespace-nowrap [font-variant-numeric:tabular-nums]`;
const totalCellClass = 'border-y-[1.5px] border-border bg-primary-tint/40 px-[9px] py-2 font-bold text-text-strong first:border-l-[1.5px] last:border-r-[1.5px]';
</script>

<template>
    <div>
        <PageHeader
            title="Payroll Register"
            description="Posted payroll in the period, per staff member and pay component, with what has been paid and what is still outstanding."
        >
            <Button v-if="can('hr_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export Excel
            </Button>
        </PageHeader>

        <p v-if="rangeLabel" class="mb-4 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <Label class="mb-1">From date (BS)</Label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <Label class="mb-1">To date (BS)</Label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-64">
                    <Label class="mb-1">Staff</Label>
                    <Select v-model="staffId" :options="staffSelectOptions" placeholder="All staff" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <Card variant="panel">
            <div
                v-if="rows.length === 0"
                class="px-6 py-16 text-center text-[13px] font-semibold text-text-base"
            >
                No posted payroll for this period or filter. Try widening the date range.
            </div>

            <div v-else class="w-full overflow-x-auto">
                <table class="w-full border-separate [border-spacing:0_4px] text-[12.5px] text-text-base">
                    <thead>
                        <tr>
                            <th scope="col" :class="[headerClass, 'text-left']">Run</th>
                            <th scope="col" :class="[headerClass, 'text-left']">Period</th>
                            <th scope="col" :class="[headerClass, 'text-left']">Date</th>
                            <th scope="col" :class="[headerClass, 'text-left']">Staff</th>
                            <th v-for="component in components" :key="`h-${component.id}`" scope="col" :class="[headerClass, 'text-right']">
                                {{ component.name }}
                            </th>
                            <th scope="col" :class="[headerClass, 'text-right']">Gross</th>
                            <th scope="col" :class="[headerClass, 'text-right']">Advance recovered</th>
                            <th scope="col" :class="[headerClass, 'text-right']">Net</th>
                            <th scope="col" :class="[headerClass, 'text-right']">Paid</th>
                            <th scope="col" :class="[headerClass, 'text-right']">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.line_id">
                            <td :class="[cellClass, 'whitespace-nowrap']">
                                <Link v-if="can('payroll.view')" :href="`/hr/payroll/${row.run_id}`" class="font-semibold text-primary hover:underline">
                                    {{ row.run_number }}
                                </Link>
                                <template v-else>{{ row.run_number }}</template>
                            </td>
                            <td :class="[cellClass, 'whitespace-nowrap']">{{ row.period_label }}</td>
                            <td :class="[cellClass, 'whitespace-nowrap']">
                                {{ formatBsDate(row.date) }}
                                <span class="block text-[11px] text-text-faint">{{ row.date }}</span>
                            </td>
                            <td :class="cellClass">{{ row.staff_name }}</td>
                            <td v-for="component in components" :key="`c-${row.line_id}-${component.id}`" :class="numericCellClass">
                                {{ componentAmount(row, component) }}
                            </td>
                            <td :class="numericCellClass">{{ formatMoney(row.gross) }}</td>
                            <td :class="numericCellClass">{{ formatMoney(row.advance_recovery) }}</td>
                            <td :class="[numericCellClass, 'font-semibold']">{{ formatMoney(row.net) }}</td>
                            <td :class="numericCellClass">{{ formatMoney(row.paid) }}</td>
                            <td :class="numericCellClass">{{ formatMoney(row.outstanding) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td :class="totalCellClass" colspan="4">Total</td>
                            <td v-for="component in components" :key="`t-${component.id}`" :class="[totalCellClass, 'text-right whitespace-nowrap']">
                                {{ componentTotal(component) }}
                            </td>
                            <td :class="[totalCellClass, 'text-right whitespace-nowrap']">{{ formatMoney(totals.gross) }}</td>
                            <td :class="[totalCellClass, 'text-right whitespace-nowrap']">{{ formatMoney(totals.advance_recovery) }}</td>
                            <td :class="[totalCellClass, 'text-right whitespace-nowrap']">{{ formatMoney(totals.net) }}</td>
                            <td :class="[totalCellClass, 'text-right whitespace-nowrap']">{{ formatMoney(totals.paid) }}</td>
                            <td :class="[totalCellClass, 'text-right whitespace-nowrap']">{{ formatMoney(totals.outstanding) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>
    </div>
</template>
