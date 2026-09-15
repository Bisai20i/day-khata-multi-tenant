<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    rows: { type: Array, default: () => [] },
    totals: {
        type: Object,
        default: () => ({
            opening: '0.00',
            current: '0.00',
            days31_60: '0.00',
            days61_90: '0.00',
            days90Plus: '0.00',
            total: '0.00',
        }),
    },
    stores: { type: Array, default: () => [] },
    asOf: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
});

useLayoutChrome('Aged Receivables');

const asOf = ref(props.asOf);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const asOfLabel = computed(() => `As of BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);

function applyFilter() {
    router.get(
        window.location.pathname,
        { as_of: asOf.value, store_id: storeId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const columns = [
    { id: 'party', header: 'Customer', numeric: false, cell: ({ row }) => row.original.party },
    { id: 'opening', header: 'Opening / unallocated', numeric: true, cell: ({ row }) => formatMoney(row.original.opening) },
    { id: 'current', header: 'Current (0-30)', numeric: true, cell: ({ row }) => formatMoney(row.original.current) },
    { id: 'days31_60', header: '31-60 days', numeric: true, cell: ({ row }) => formatMoney(row.original.days31_60) },
    { id: 'days61_90', header: '61-90 days', numeric: true, cell: ({ row }) => formatMoney(row.original.days61_90) },
    { id: 'days90Plus', header: '90+ days', numeric: true, cell: ({ row }) => formatMoney(row.original.days90Plus) },
    { id: 'total', header: 'Total', numeric: true, cell: ({ row }) => formatMoney(row.original.total) },
];
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Aged Receivables</h2>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">{{ asOfLabel }}</p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">As of</label>
                    <NepaliDateInput v-model="asOf" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="Nothing outstanding" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Opening:</span> <span class="font-semibold">{{ formatMoney(totals.opening) }}</span></div>
                <div><span class="text-text-muted">Current:</span> <span class="font-semibold">{{ formatMoney(totals.current) }}</span></div>
                <div><span class="text-text-muted">31-60:</span> <span class="font-semibold">{{ formatMoney(totals.days31_60) }}</span></div>
                <div><span class="text-text-muted">61-90:</span> <span class="font-semibold">{{ formatMoney(totals.days61_90) }}</span></div>
                <div><span class="text-text-muted">90+:</span> <span class="font-semibold">{{ formatMoney(totals.days90Plus) }}</span></div>
                <div><span class="text-text-muted">Total:</span> <span class="font-semibold">{{ formatMoney(totals.total) }}</span></div>
            </div>

            <p class="mt-3 text-[11.5px] text-text-faint">
                The ageing buckets cover open credit invoices. Anything else the customer's ledger carries -
                a migrated opening due, a receipt on account, a manual journal voucher - sits in
                "Opening / unallocated", so this report always adds up to the customer's ledger balance.
            </p>
        </Card>
    </div>
</template>
