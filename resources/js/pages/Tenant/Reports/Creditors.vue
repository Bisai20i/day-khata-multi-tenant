<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { FileSpreadsheet } from '@lucide/vue';
import { formatMoney } from '@/lib/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: String, default: '0.00' },
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
});

useLayoutChrome('Creditors');

const fiscalYearId = ref(props.fiscalYearId);

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({ value: fiscalYear.id, label: fiscalYear.name })),
);

function applyFilter() {
    router.get(
        window.location.pathname,
        { fiscal_year_id: fiscalYearId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const exportUrl = computed(() =>
    fiscalYearId.value ? `/reports/creditors/export?fiscal_year_id=${fiscalYearId.value}` : '/reports/creditors/export',
);

const columns = [
    { id: 'name', header: 'Supplier', numeric: false, cell: ({ row }) => row.original.name },
    { id: 'address', header: 'Address', numeric: false, cell: ({ row }) => row.original.address ?? '—' },
    { id: 'mobile_no', header: 'Mobile', numeric: false, cell: ({ row }) => row.original.mobile_no ?? '—' },
    { id: 'balance', header: 'Balance', numeric: true, cell: ({ row }) => formatMoney(row.original.balance) },
];
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Creditors</h2>
            <Button as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Every supplier whose ledger account carries a balance in the selected fiscal year - the party
            breakdown of Sundry Creditors on the Balance Sheet, including migrated opening payables no bill
            explains.
        </p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Fiscal year</label>
                    <Select v-model="fiscalYearId" :options="fiscalYearOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No supplier carries a balance" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total:</span> <span class="font-semibold">{{ formatMoney(total) }}</span></div>
            </div>
        </Card>
    </div>
</template>
