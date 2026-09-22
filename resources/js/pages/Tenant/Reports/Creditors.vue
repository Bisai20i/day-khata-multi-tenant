<script setup>
import { computed, h, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { FileSpreadsheet } from '@lucide/vue';
import { formatMoney } from '@/lib/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: String, default: '0.00' },
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    creditBalanceNote: { type: String, default: '' },
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
    { id: 'address', header: 'Address', numeric: false, cell: ({ row }) => row.original.address ?? '-' },
    { id: 'mobile_no', header: 'Mobile', numeric: false, cell: ({ row }) => row.original.mobile_no ?? '-' },
    {
        id: 'balance',
        header: 'Balance',
        numeric: true,
        cell: ({ row }) => row.original.is_credit_balance
            ? h('span', { class: 'inline-flex items-center gap-1.5' }, [
                formatMoney(row.original.balance),
                h('span', { class: 'rounded-full bg-success/15 px-1.5 py-0.5 text-[10px] font-semibold text-success' }, 'Credit balance'),
            ])
            : formatMoney(row.original.balance),
    },
];
</script>

<template>
    <div>
        <PageHeader
            title="Creditors"
            description="Suppliers you owe money to, with the balance owed to each. Includes migrated opening payables no bill explains."
        >
            <Button as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Fiscal year</label>
                    <Select v-model="fiscalYearId" :options="fiscalYearOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Generate report</Button>
            </div>
            <p v-if="creditBalanceNote" class="mt-3 text-xs text-text-muted">{{ creditBalanceNote }}</p>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No supplier is owed a balance in this fiscal year. Try another fiscal year." />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="font-bold text-text-strong">Total:</span> <span class="font-bold">{{ formatMoney(total) }}</span></div>
            </div>
        </Card>
    </div>
</template>
