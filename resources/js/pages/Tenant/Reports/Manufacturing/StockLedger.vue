<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import { usePermissions } from '@/composables/usePermissions';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    ledger: { type: Object, required: true },
    class: { type: String, default: 'raw_material' },
    itemId: { type: [Number, null], default: null },
    items: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
});

useLayoutChrome('Manufacturing Stock Ledger');

const classValue = ref(props.class);
const itemValue = ref(props.itemId);
const from = ref(props.from);
const to = ref(props.to);

const classOptions = [
    { value: 'raw_material', label: 'Raw Material' },
    { value: 'wip', label: 'Work in Process' },
    { value: 'finished_good', label: 'Finished Goods' },
];

// Items are only offered for the two classes that are item-level; WIP is
// tracked per production entry, so the item filter is hidden for it.
const itemOptions = computed(() => [
    { value: null, label: 'All items' },
    ...props.items
        .filter((item) => item.inventory_class === classValue.value)
        .map((item) => ({ value: item.id, label: item.name })),
]);

const isWip = computed(() => classValue.value === 'wip');

function params() {
    return {
        class: classValue.value,
        item_id: isWip.value ? undefined : (itemValue.value ?? undefined),
        from: from.value ?? undefined,
        to: to.value ?? undefined,
    };
}

function apply() {
    router.get(window.location.pathname, params(), { preserveState: true, preserveScroll: true });
}

function fileUrl(suffix) {
    const query = new URLSearchParams(Object.entries(params()).filter(([, value]) => value !== undefined));
    return `${window.location.pathname}/${suffix}?${query.toString()}`;
}

const dash = (value, formatter) => (value === null || value === undefined ? '-' : formatter(value));

// Every figure arrives as an exact decimal string computed server-side; the
// page only formats them.
const columns = [
    { id: 'dateBs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) || '-' },
    { accessorKey: 'date', header: 'Date (AD)' },
    { accessorKey: 'document', header: 'Document', numeric: false },
    { accessorKey: 'inQuantity', header: 'In qty', cell: ({ row }) => dash(row.original.inQuantity, formatQuantity) },
    { accessorKey: 'outQuantity', header: 'Out qty', cell: ({ row }) => dash(row.original.outQuantity, formatQuantity) },
    { accessorKey: 'balanceQuantity', header: 'Balance qty', cell: ({ row }) => dash(row.original.balanceQuantity, formatQuantity) },
    { accessorKey: 'inValue', header: 'In value', cell: ({ row }) => dash(row.original.inValue, formatMoney) },
    { accessorKey: 'outValue', header: 'Out value', cell: ({ row }) => dash(row.original.outValue, formatMoney) },
    { accessorKey: 'balanceValue', header: 'Balance value', cell: ({ row }) => formatMoney(row.original.balanceValue) },
];
</script>

<template>
    <div>
        <PageHeader title="Manufacturing Stock Ledger" description="Quantity and cost movement of raw materials, work in process or finished goods, with a running balance.">
            <a v-if="can('manufacturing_reports.export')" :href="fileUrl('print')" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print</Button></a>
            <a v-if="can('manufacturing_reports.export')" :href="fileUrl('export')"><Button variant="secondary" tone="purple">Export</Button></a>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-48">
                    <Label class="mb-1">Inventory class</Label>
                    <Select v-model="classValue" :options="classOptions" />
                </div>
                <div v-if="!isWip" class="w-56">
                    <Label class="mb-1">Item</Label>
                    <Select v-model="itemValue" :options="itemOptions" />
                </div>
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <Label>From date</Label>
                        <InfoTip text="With no dates the current fiscal year is shown. The opening row is the verified closing of the previous year when the range starts on the new year's first day, otherwise the balance the day before." />
                    </div>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <Label class="mb-1">To date</Label>
                    <NepaliDateInput v-model="to" />
                </div>
                <Button variant="primary" tone="purple" @click="apply">Apply filters</Button>
            </div>
        </Card>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap justify-end gap-6 text-[13px]">
                <div>
                    <span class="text-text-muted">Opening:</span>
                    <span class="font-semibold tabular-nums">
                        <template v-if="ledger.opening.quantity !== null">{{ formatQuantity(ledger.opening.quantity) }} / </template>{{ formatMoney(ledger.opening.value) }}
                    </span>
                    <span v-if="ledger.openingFromSnapshot" class="ml-1 text-[11px] text-text-muted">(prior year snapshot)</span>
                </div>
                <div>
                    <span class="text-text-muted">Closing:</span>
                    <span class="font-semibold tabular-nums">
                        <template v-if="ledger.closing.quantity !== null">{{ formatQuantity(ledger.closing.quantity) }} / </template>{{ formatMoney(ledger.closing.value) }}
                    </span>
                </div>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="ledger.rows" :page-size="25" empty-message="No movements in this period. Try a wider date range." />
        </Card>
    </div>
</template>
