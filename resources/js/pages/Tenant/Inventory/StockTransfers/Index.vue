<script setup>
import { computed, h, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, PackageCheck, Plus, Printer } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, formatQuantity } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
import Create from './Create.vue';
import ReceiveModal from './ReceiveModal.vue';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';
import { useBranchListColumn } from '@/composables/useBranchListColumn';
import BranchBadge from '@/components/branch/BranchBadge.vue';
import { useBranchLabel } from '@/composables/useBranchLabel';

defineOptions({ layout: AppLayout });

const { hasOpenFiscalYear } = useOpenFiscalYear();
const { can } = usePermissions();

const props = defineProps({
    stockTransfers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    // Every active store of every active branch (BR18), null when the
    // company is not multi-branch.
    destinationStores: { type: Array, default: null },
    // BR19, multi-branch only: the list's `status` filter ('in_transit' | 'received' | null) and the
    // transfers in transit right now by direction ({ within|outgoing|incoming: { count, value } }, or null).
    statusFilter: { type: [String, null], default: null },
    inTransit: { type: [Object, null], default: null },
});

const { plural: branchPlural } = useBranchLabel();

// Directions are relative to the branches this list shows (the switcher's
// branch, else the user's own branches); the whole company is all 'within'.
const TRANSIT_LINES = {
    within: () => `between ${branchPlural('lower')}`,
    outgoing: () => `dispatched to other ${branchPlural('lower')}, awaiting receipt`,
    incoming: () => `incoming from other ${branchPlural('lower')}`,
};

const transitLines = computed(() =>
    ['within', 'outgoing', 'incoming']
        .filter((bucket) => props.inTransit?.[bucket])
        .map((bucket) => ({ bucket, text: TRANSIT_LINES[bucket](), ...props.inTransit[bucket] })),
);

function showStatus(status) {
    router.get('/stock-transfers', status ? { status } : {}, { preserveScroll: true });
}

const page = usePage();
const { toast } = useToast();

// Branches (plan 12, BR12): a transfer has a branch at each end, so instead
// of one branch column each store names its branch, for a multi-branch
// company viewing all branches (the server then loads the store branches).
// A transfer between branches (BR18) always names both branches.
const { showBranchColumn } = useBranchListColumn();

function isCrossBranch(transfer) {
    return transfer.from_branch_id !== transfer.to_branch_id;
}

function storeCell(store, transfer) {
    if (!store) {
        return '-';
    }

    if (!store.branch || !(showBranchColumn.value || isCrossBranch(transfer))) {
        return store.name;
    }

    return h('div', { class: 'flex items-center gap-1.5' }, [h('span', store.name), h(BranchBadge, { branch: store.branch })]);
}

// The branches this user may post in (shared `branch.allowed`): receiving
// needs the destination branch, cancelling a transfer in transit the
// source branch. The server checks both again.
function canUseBranch(branchId) {
    return (page.props.branch?.allowed ?? []).some((branch) => branch.id === branchId);
}

// Posted and Cancelled look exactly as before; the two-step statuses (BR18)
// follow the same text style.
const STATUSES = {
    posted: { label: 'Posted', class: 'text-success font-semibold' },
    in_transit: { label: 'In transit', class: 'text-warning-text font-semibold' },
    received: { label: 'Received', class: 'text-success font-semibold' },
    cancelled: { label: 'Cancelled', class: 'text-danger font-semibold' },
};

function statusCell(transfer) {
    const status = STATUSES[transfer.status] ?? { label: transfer.status ?? '-', class: 'text-text-muted' };

    if (transfer.status !== 'received' || !transfer.received_on) {
        return h('span', { class: status.class }, status.label);
    }

    return h('div', { class: 'whitespace-nowrap' }, [
        h('span', { class: status.class }, status.label),
        h('span', { class: 'ml-1 text-text-faint' }, formatBsDate(transfer.received_on)),
    ]);
}

function canCancel(transfer) {
    if (!can('stock_transfers.cancel')) return false;
    if (transfer.status === 'posted') return true;

    return transfer.status === 'in_transit' && canUseBranch(transfer.from_branch_id);
}

function canReceive(transfer) {
    return transfer.status === 'in_transit' && can('stock_transfers.receive') && canUseBranch(transfer.to_branch_id);
}

const receiving = ref(null);
useLayoutChrome('Stock Transfers');

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

function linesSummary(transfer) {
    if (!transfer.lines?.length) return '-';
    return transfer.lines
        .map((line) => `${line.item?.name ?? '-'} (${formatQuantity(line.quantity)})`)
        .join(', ');
}

const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

function openCancel(transfer) {
    cancelling.value = transfer;
    cancelForm.reset();
    cancelForm.clearErrors();
}

function onCancelOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    cancelForm.post(`/stock-transfers/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

const columns = [
    {
        id: 'date',
        header: 'Date (BS)',
        numeric: false,
        // Bikram Sambat first: it is the date a Nepali user works in, and
        // the one the IRD reads. The AD date stays beside it as the
        // cross-reference (contract C8 / C9).
        cell: ({ row }) =>
            h('div', { class: 'whitespace-nowrap' }, [
                formatBsDate(row.original.date),
                h('span', { class: 'ml-1 text-text-faint' }, `(${String(row.original.date ?? '').slice(0, 10)})`),
            ]),
    },
    {
        id: 'from_store',
        header: 'From store',
        numeric: false,
        cell: ({ row }) => storeCell(row.original.from_store, row.original),
    },
    {
        id: 'to_store',
        header: 'To store',
        numeric: false,
        cell: ({ row }) => storeCell(row.original.to_store, row.original),
    },
    {
        id: 'note',
        header: 'Note',
        numeric: false,
        cell: ({ row }) => row.original.note ?? '-',
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
        cell: ({ row }) => formatMoney(row.original.total_value),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) => statusCell(row.original),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-1' }, [
                can('stock_transfers.print')
                    ? h(Tooltip, { label: 'Print transfer' }, () =>
                        h(
                            'a',
                            {
                                href: `/stock-transfers/${row.original.id}/print`,
                                target: '_blank',
                                rel: 'noopener',
                                class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                                'aria-label': 'Print transfer',
                            },
                            [h(Printer, { class: 'h-[13px] w-[13px]' })],
                        ),
                    )
                    : null,
                canReceive(row.original)
                    ? h(Tooltip, { label: 'Receive transfer' }, () =>
                          h(
                              'button',
                              {
                                  type: 'button',
                                  class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                                  'aria-label': 'Receive transfer',
                                  onClick: () => (receiving.value = row.original),
                              },
                              [h(PackageCheck, { class: 'h-[13px] w-[13px]' })],
                          ),
                      )
                    : null,
                !canCancel(row.original)
                    ? null
                    : h(Tooltip, { label: 'Cancel transfer' }, () =>
                          h(
                              'button',
                              {
                                  type: 'button',
                                  class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                                  'aria-label': 'Cancel transfer',
                                  onClick: () => openCancel(row.original),
                              },
                              [h(Ban, { class: 'h-[13px] w-[13px]' })],
                          ),
                      ),
            ]),
    },
];
</script>

<template>
    <div>
        <template v-if="showCreateForm">
            <Create
                :items="items"
                :stores="stores"
                :destination-stores="destinationStores"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <PageHeader title="Stock Transfers" description="Stock transfers: move stock from one store to another.">
                <Button v-if="hasOpenFiscalYear && can('stock_transfers.create')" variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" />
                    New transfer
                </Button>
            </PageHeader>

            <div v-if="transitLines.length > 0 || statusFilter" class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[12.5px] text-text-base">
                <span v-for="line in transitLines" :key="line.bucket">
                    <span class="font-semibold text-warning-text">In transit</span>
                    {{ line.text }}: {{ line.count }} {{ line.count === 1 ? 'transfer' : 'transfers' }}, {{ formatMoney(line.value) }}
                </span>
                <span class="ml-auto flex items-center gap-2">
                    <Button v-if="statusFilter !== 'in_transit' && transitLines.length > 0" variant="secondary" tone="purple" @click="showStatus('in_transit')">
                        Show in transit only
                    </Button>
                    <span v-if="statusFilter" class="text-text-muted">Showing {{ statusFilter === 'in_transit' ? 'in-transit' : 'received' }} transfers only.</span>
                    <Button v-if="statusFilter" variant="secondary" tone="neutral" @click="showStatus(null)">Show all</Button>
                </span>
            </div>

            <Card variant="panel" class="bg-white">
                <div v-if="stockTransfers.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <p class="text-sm font-semibold text-text-strong">No stock transfers yet</p>
                    <p v-if="hasOpenFiscalYear && can('stock_transfers.create')" class="text-xs text-text-muted">Move stock between stores and the transfer will be listed here.</p>
                    <Button v-if="hasOpenFiscalYear && can('stock_transfers.create')" variant="primary" tone="purple" @click="showCreateForm = true">
                        <Plus class="size-4" />
                        New transfer
                    </Button>
                </div>
                <DataTable v-else :columns="columns" :data="stockTransfers" :page-size="10" empty-message="No stock transfers" />
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel stock transfer" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p v-if="cancelling?.status === 'in_transit'" class="text-sm text-text-muted">
                    This returns the goods to the source store and reverses the transfer's entry in the books. This cannot be undone.
                </p>
                <p v-else class="text-sm text-text-muted">
                    This reverts the quantity impact of this transfer at both stores. This cannot be undone.
                </p>
                <div>
                    <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
                    <Input v-model="cancelForm.reason" type="text" placeholder="Reason for cancellation" required />
                    <p v-if="cancelForm.errors.reason" class="mt-1 text-sm text-danger">{{ cancelForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="neutral" type="button" @click="cancelling = null">Back</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="cancelForm.processing" @click="submitCancel">
                    Confirm cancellation
                </Button>
            </template>
        </Modal>

        <ReceiveModal :transfer="receiving" @close="receiving = null" />
    </div>
</template>
