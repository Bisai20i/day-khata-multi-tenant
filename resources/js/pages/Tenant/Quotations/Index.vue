<script setup>
import { computed, h, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ArrowRightCircle, Pencil, Trash2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';
import Create from './Create.vue';

const props = defineProps({
    quotations: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

// Posting/editing/converting redirects back to this same route + component,
// which Inertia re-renders in place without an onMounted re-run - watch the
// flash prop instead (same pattern as every other module in this app).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);
const editingQuotation = ref(null);

function lineTotal(line) {
    return Number(line.quantity) * Number(line.rate) - Number(line.discount);
}

function quotationTotal(quotation) {
    const lineSum = quotation.lines.reduce((sum, line) => sum + lineTotal(line), 0);
    const taxable = lineSum - Number(quotation.discount);
    const vat = taxable * (Number(quotation.vat_rate) / 100);
    return taxable + vat;
}

const statusVariants = {
    draft: 'neutral',
    converted: 'success',
    cancelled: 'danger',
};

const statusLabels = {
    draft: 'Draft',
    converted: 'Converted',
    cancelled: 'Cancelled',
};

function edit(quotation) {
    editingQuotation.value = quotation;
}

function closeForms() {
    showCreateForm.value = false;
    editingQuotation.value = null;
}

function destroy(quotation) {
    if (!confirm(`Delete quotation #${quotation.id}? This cannot be undone.`)) return;
    router.delete(`/quotations/${quotation.id}`, { preserveScroll: true });
}

function cancelQuotation(quotation) {
    if (!confirm(`Cancel quotation #${quotation.id}?`)) return;
    router.post(`/quotations/${quotation.id}/cancel`, {}, { preserveScroll: true });
}

function convertToSale(quotation) {
    if (!confirm(`Convert quotation #${quotation.id} to a real sale? This posts to the ledger and cannot be undone.`)) return;
    router.post(`/quotations/${quotation.id}/convert-to-sale`, {}, { preserveScroll: true });
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'reference_number',
        header: 'Reference #',
        numeric: false,
        cell: ({ row }) => row.original.reference_number ?? '—',
    },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        cell: ({ row }) => row.original.customer?.name ?? '—',
    },
    {
        id: 'total',
        header: 'Total',
        numeric: true,
        cell: ({ row }) => quotationTotal(row.original).toFixed(2),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { variant: statusVariants[row.original.status] ?? 'neutral' }, () => statusLabels[row.original.status] ?? row.original.status),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) => {
            const quotation = row.original;
            if (quotation.status !== 'draft') return null;

            return h('div', { class: 'flex items-center gap-1' }, [
                h(Tooltip, { label: 'Convert to sale' }, () =>
                    h(
                        'button',
                        {
                            type: 'button',
                            class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-colors duration-150',
                            'aria-label': 'Convert to sale',
                            onClick: () => convertToSale(quotation),
                        },
                        [h(ArrowRightCircle, { class: 'h-[13px] w-[13px]' })],
                    ),
                ),
                h(Tooltip, { label: 'Edit' }, () =>
                    h(
                        'button',
                        {
                            type: 'button',
                            class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                            'aria-label': 'Edit quotation',
                            onClick: () => edit(quotation),
                        },
                        [h(Pencil, { class: 'h-[13px] w-[13px]' })],
                    ),
                ),
                h(Tooltip, { label: 'Delete' }, () =>
                    h(
                        'button',
                        {
                            type: 'button',
                            class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                            'aria-label': 'Delete quotation',
                            onClick: () => destroy(quotation),
                        },
                        [h(Trash2, { class: 'h-[13px] w-[13px]' })],
                    ),
                ),
            ]);
        },
    },
];
</script>

<template>
    <AppLayout title="Quotations" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create :customers="customers" :items="items" @cancel="closeForms" @saved="closeForms" />
        </template>

        <template v-else-if="editingQuotation">
            <Create :customers="customers" :items="items" :quotation="editingQuotation" @cancel="closeForms" @saved="closeForms" />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Quotations</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">New quotation</Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="quotations" :page-size="10" empty-message="No quotations yet" />
            </Card>
        </template>
    </AppLayout>
</template>
