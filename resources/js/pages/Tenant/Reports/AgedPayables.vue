<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    totals: {
        type: Object,
        default: () => ({ current: 0, days31_60: 0, days61_90: 0, days90Plus: 0, total: 0 }),
    },
    stores: { type: Array, default: () => [] },
    asOf: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const asOf = ref(props.asOf);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

function applyFilter() {
    router.get(
        window.location.pathname,
        { as_of: asOf.value, store_id: storeId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const columns = [
    { id: 'party', header: 'Supplier', numeric: false, cell: ({ row }) => row.original.party },
    { id: 'current', header: 'Current (0-30)', numeric: true, cell: ({ row }) => row.original.current.toFixed(2) },
    { id: 'days31_60', header: '31-60 days', numeric: true, cell: ({ row }) => row.original.days31_60.toFixed(2) },
    { id: 'days61_90', header: '61-90 days', numeric: true, cell: ({ row }) => row.original.days61_90.toFixed(2) },
    { id: 'days90Plus', header: '90+ days', numeric: true, cell: ({ row }) => row.original.days90Plus.toFixed(2) },
    { id: 'total', header: 'Total', numeric: true, cell: ({ row }) => row.original.total.toFixed(2) },
];
</script>

<template>
    <AppLayout title="Aged Payables" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Aged Payables</h2>
        </div>

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
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No outstanding credit purchases" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Current:</span> <span class="font-semibold">{{ totals.current.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">31-60:</span> <span class="font-semibold">{{ totals.days31_60.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">61-90:</span> <span class="font-semibold">{{ totals.days61_90.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">90+:</span> <span class="font-semibold">{{ totals.days90Plus.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">Total:</span> <span class="font-semibold">{{ totals.total.toFixed(2) }}</span></div>
            </div>

            <p class="mt-3 text-[11.5px] text-text-faint">
                Only reflects credit purchases reduced by purchase returns against them. A payment made
                to a supplier recorded via a manual journal voucher (rather than a dedicated
                make-payment feature, which doesn't exist yet) won't reduce the outstanding amount shown
                here.
            </p>
        </Card>
    </AppLayout>
</template>
