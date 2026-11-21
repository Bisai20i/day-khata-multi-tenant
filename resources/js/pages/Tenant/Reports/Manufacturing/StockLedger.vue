<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
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
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    ledger: { type: Object, required: true },
    class: { type: String, default: 'raw_material' },
    itemId: { type: [Number, null], default: null },
    items: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Manufacturing Stock Ledger');

const branchFilter = useBranchReportFilter(() => props.branchReport);

// A malformed filter (a bad date) redirects back with validation errors; show them
// instead of leaving the page looking unchanged.
const page = usePage();
const filterErrors = computed(() => ['from', 'to', 'class', 'item_id'].map((key) => page.props.errors?.[key]).filter(Boolean));

// Unapplied filter inputs; named *Input so none shadows a prop in the template.
const classInput = ref(props.class);
const itemInput = ref(props.itemId);
const fromInput = ref(props.from);
const toInput = ref(props.to);

// Follow what the server applied (it fills in the fiscal-year defaults).
watch(
    () => [props.class, props.itemId, props.from, props.to],
    () => {
        classInput.value = props.class;
        itemInput.value = props.itemId;
        fromInput.value = props.from;
        toInput.value = props.to;
    },
);

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
        .filter((item) => item.inventory_class === classInput.value)
        .map((item) => ({ value: item.id, label: item.name })),
]);

const isWip = computed(() => classInput.value === 'wip');

// An item belongs to one class, so a chosen item never survives a class switch.
function onClassChange(value) {
    if (value !== classInput.value) {
        itemInput.value = null;
    }
    classInput.value = value;
}

function apply() {
    router.get(
        window.location.pathname,
        {
            class: classInput.value,
            item_id: isWip.value ? undefined : (itemInput.value ?? undefined),
            from: fromInput.value ?? undefined,
            to: toInput.value ?? undefined,
            ...branchFilter.toQuery(),
        },
        { preserveState: true, preserveScroll: true },
    );
}

// Print and Export follow the applied props (what is on screen), never the unapplied inputs.
function fileUrl(suffix) {
    const query = new URLSearchParams({ class: props.class, from: props.from, to: props.to });
    if (props.class !== 'wip' && props.itemId) {
        query.set('item_id', String(props.itemId));
    }
    branchFilter.appendTo(query);
    return `/reports/manufacturing/${suffix}?${query.toString()}`;
}

const printUrl = computed(() => fileUrl('stock-ledger/print'));
const exportUrl = computed(() => fileUrl('stock-ledger/export'));

const dash = (value, formatter) => (value === null || value === undefined ? '-' : formatter(value));

// Every figure arrives as an exact decimal string computed server-side; the
// page only formats them.
const columns = [
    { id: 'dateBs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) || '-' },
    { accessorKey: 'date', header: 'Date (AD)' },
    {
        accessorKey: 'document',
        header: 'Document',
        numeric: false,
        // Linked only where the document has a show page the user may open.
        cell: ({ row }) =>
            row.original.documentUrl
                ? h(Link, { href: row.original.documentUrl, class: 'font-semibold text-primary hover:underline' }, () => row.original.document)
                : row.original.document,
    },
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
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="mfg-stock-ledger"
                hide-label
                @change="apply"
            />
            <Button v-if="can('manufacturing_reports.export')" as="a" :href="printUrl" target="_blank" rel="noopener" variant="secondary" tone="purple">Print</Button>
            <Button v-if="can('manufacturing_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">Export to Excel</Button>
        </PageHeader>

        <div
            v-if="filterErrors.length"
            class="mb-4 border-[1.5px] border-red-600 bg-red-50 px-4 py-2 text-[13px] font-semibold text-red-700"
            role="alert"
        >
            <p v-for="message in filterErrors" :key="message">{{ message }}</p>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-48">
                    <Label class="mb-1">Inventory class</Label>
                    <Select :model-value="classInput" :options="classOptions" @update:model-value="onClassChange" />
                </div>
                <div v-if="!isWip" class="w-56">
                    <Label class="mb-1">Item</Label>
                    <Select v-model="itemInput" :options="itemOptions" />
                </div>
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <Label>From date</Label>
                        <InfoTip text="With no dates the current fiscal year is shown. The opening row is the verified closing of the previous year when the range starts on the new year's first day, otherwise the balance the day before." />
                    </div>
                    <NepaliDateInput v-model="fromInput" />
                </div>
                <div>
                    <Label class="mb-1">To date</Label>
                    <NepaliDateInput v-model="toInput" />
                </div>
                <Button variant="primary" tone="purple" @click="apply">Apply filters</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
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
