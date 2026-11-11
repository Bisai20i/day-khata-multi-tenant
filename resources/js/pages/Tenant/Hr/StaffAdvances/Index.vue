<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Ban, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PaginatorNav from '@/components/ui/PaginatorNav.vue';
import Label from '@/components/ui/Label.vue';
import StaffAdvanceForm from '@/components/hr/StaffAdvanceForm.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchListColumn } from '@/composables/useBranchListColumn';

defineOptions({ layout: AppLayout });

const { hasOpenFiscalYear } = useOpenFiscalYear();

defineProps({
    // A LengthAwarePaginator page.
    advances: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    staff: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    // Branch picker (BranchFormOptions::props()).
    branchOptions: { type: Array, default: () => [] },
    defaultBranchId: { type: Number, default: null },
});

const page = usePage();
const { branchColumns } = useBranchListColumn();
const { toast } = useToast();
useLayoutChrome('Staff advances');

// UI mirrors of routes/tenant-hr.php; each route still enforces its key.
const { can } = usePermissions();
const canCreateAdvance = computed(() => can('staff_advances.create'));
const canCancelAdvance = computed(() => can('staff_advances.cancel'));

// Posting/cancelling redirects back to this same route + component, which
// Inertia re-renders in place - watch the flash prop.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

const statusVariants = {
    posted: 'success',
    cancelled: 'danger',
};

const statusLabels = {
    posted: 'Posted',
    cancelled: 'Cancelled',
};

const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

function openCancel(advance) {
    cancelling.value = advance;
    cancelForm.reset();
    cancelForm.clearErrors();
}

function onCancelOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    cancelForm.post(`/hr/staff-advances/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

/** Cancelled advances stay listed but read as inactive. */
function cellText(row, content) {
    return row.status === 'cancelled' ? h('span', { class: 'text-text-faint line-through' }, content) : content;
}

const columns = [
    {
        id: 'date',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => cellText(row.original, `${formatBsDate(row.original.date)} (${String(row.original.date).slice(0, 10)})`),
    },
    {
        id: 'advance_number',
        header: 'Advance #',
        numeric: false,
        cell: ({ row }) => cellText(row.original, row.original.advance_number ?? '-'),
    },
    // The paying branch, only for a multi-branch company viewing all branches.
    ...branchColumns(),
    {
        id: 'staff',
        header: 'Staff',
        numeric: false,
        cell: ({ row }) => cellText(row.original, row.original.staff_name ?? '-'),
    },
    {
        id: 'amount',
        header: 'Amount (Rs.)',
        numeric: true,
        cell: ({ row }) => cellText(row.original, formatMoney(row.original.amount)),
    },
    {
        id: 'payment_mode',
        header: 'Paid by',
        numeric: false,
        cell: ({ row }) => cellText(row.original, row.original.payment_mode === 'bank' ? 'Bank' : 'Cash'),
    },
    {
        id: 'narration',
        header: 'Notes',
        numeric: false,
        cell: ({ row }) => cellText(row.original, row.original.narration || '-'),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col items-start gap-1' }, [
                h(Badge, { pill: true, variant: statusVariants[row.original.status] ?? 'neutral' }, () => statusLabels[row.original.status] ?? row.original.status),
                row.original.status === 'cancelled' && row.original.cancel_reason
                    ? h('span', { class: 'text-xs text-text-muted' }, `Reason: ${row.original.cancel_reason}`)
                    : null,
            ]),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'posted' && canCancelAdvance.value
                ? h(Tooltip, { label: 'Cancel this advance and reverse its entries' }, () =>
                      h(
                          'button',
                          {
                              type: 'button',
                              class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                              'aria-label': `Cancel advance to ${row.original.staff_name ?? 'staff'}`,
                              onClick: () => openCancel(row.original),
                          },
                          [h(Ban, { class: 'h-[13px] w-[13px]' })],
                      ),
                  )
                : '-',
    },
];
</script>

<template>
    <div>
        <template v-if="showCreateForm">
            <StaffAdvanceForm
                :staff="staff"
                :bank-accounts="bankAccounts"
                :today="today"
                :branch-options="branchOptions"
                :default-branch-id="defaultBranchId"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <PageHeader title="Staff advances" description="Money given to staff ahead of salary. Cancel an advance entered in error.">
                <Button v-if="hasOpenFiscalYear && canCreateAdvance" variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" aria-hidden="true" />
                    Give advance
                </Button>
            </PageHeader>

            <Card variant="panel" class="bg-white">
                <div v-if="advances.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <p class="text-sm font-semibold text-text-strong">No advances yet</p>
                    <p v-if="hasOpenFiscalYear && canCreateAdvance" class="text-xs text-text-muted">Give the first advance to a staff member and it will be listed here.</p>
                    <Button v-if="hasOpenFiscalYear && canCreateAdvance" variant="primary" tone="purple" @click="showCreateForm = true">
                        <Plus class="size-4" aria-hidden="true" />
                        Give advance
                    </Button>
                </div>
                <template v-else>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-text-muted" aria-live="polite">Showing {{ advances.from }}–{{ advances.to }} of {{ advances.total }}</p>
                    </div>
                    <DataTable :columns="columns" :data="advances.data" :page-size="Math.max(advances.data.length, 1)" empty-message="No advances yet" />
                    <PaginatorNav :paginator="advances" label="Staff advances pagination" />
                </template>
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel advance" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    Cancelling this advance posts a reversing entry and removes it from the staff member's outstanding advance. This cannot be undone.
                </p>
                <div>
                    <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
                    <Input v-model="cancelForm.reason" type="text" maxlength="255" placeholder="e.g. Entered wrong amount" required />
                    <p v-if="cancelForm.errors.reason" class="mt-1 text-sm text-danger">{{ cancelForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="neutral" type="button" @click="cancelling = null">Keep advance</Button>
                <Button variant="primary" tone="danger" type="button" :loading="cancelForm.processing" @click="submitCancel">
                    Cancel this advance
                </Button>
            </template>
        </Modal>
    </div>
</template>
