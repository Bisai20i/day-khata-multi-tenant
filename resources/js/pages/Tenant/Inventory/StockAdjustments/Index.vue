<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Ban } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';
import Create from './Create.vue';

const props = defineProps({
    stockAdjustments: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

// Posting/cancelling redirects back to this same route + component, which
// Inertia re-renders in place without an onMounted re-run - watch the flash
// prop instead (same pattern as every other module in this app).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

const reasonLabels = {
    damage: 'Damage',
    lost: 'Lost',
    correction: 'Correction',
    found: 'Found',
    opening: 'Opening',
    other: 'Other',
};

function linesSummary(adjustment) {
    if (!adjustment.lines?.length) return '—';
    return adjustment.lines
        .map((line) => {
            const sign = line.direction === 'in' ? '+' : '-';
            const reason = reasonLabels[line.reason_type] ?? line.reason_type;
            return `${line.item?.name ?? '—'} (${sign}${Number(line.quantity)} ${reason})`;
        })
        .join(', ');
}

const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

function openCancel(adjustment) {
    cancelling.value = adjustment;
    cancelForm.reset();
    cancelForm.clearErrors();
}

function onCancelOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    cancelForm.post(`/stock-adjustments/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'note',
        header: 'Note',
        numeric: false,
        cell: ({ row }) => row.original.note ?? '—',
    },
    {
        id: 'lines',
        header: 'Lines',
        numeric: false,
        cell: ({ row }) => linesSummary(row.original),
    },
    {
        id: 'total_value',
        header: 'Total value',
        numeric: true,
        cell: ({ row }) => Number(row.original.total_value).toFixed(2),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(
                'span',
                {
                    class:
                        row.original.status === 'cancelled'
                            ? 'text-danger font-semibold'
                            : 'text-success font-semibold',
                },
                row.original.status === 'cancelled' ? 'Cancelled' : 'Posted',
            ),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'cancelled'
                ? null
                : h(Tooltip, { label: 'Cancel adjustment' }, () =>
                      h(
                          'button',
                          {
                              type: 'button',
                              class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                              'aria-label': 'Cancel adjustment',
                              onClick: () => openCancel(row.original),
                          },
                          [h(Ban, { class: 'h-[13px] w-[13px]' })],
                      ),
                  ),
    },
];
</script>

<template>
    <AppLayout title="Stock Adjustments" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create :items="items" @cancel="showCreateForm = false" @posted="showCreateForm = false" />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Stock Adjustments</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">New adjustment</Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="stockAdjustments" :page-size="10" empty-message="No stock adjustments yet" />
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel stock adjustment" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This reverts the quantity impact of this adjustment. This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <Input v-model="cancelForm.reason" type="text" required />
                    <p v-if="cancelForm.errors.reason" class="mt-1 text-sm text-danger">{{ cancelForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="cancelling = null">Back</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="cancelForm.processing" @click="submitCancel">
                    Confirm cancellation
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
