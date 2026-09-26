<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import Modal from '@/components/ui/Modal.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney } from '@/lib/money';
import { formatBsDate, todayInKathmandu } from '@/lib/format';
import Create from './Create.vue';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';

defineOptions({ layout: AppLayout });

const { hasOpenFiscalYear } = useOpenFiscalYear();

const props = defineProps({
    capitalPurchases: { type: Array, default: () => [] },
    canCancel: { type: Boolean, default: false },
    // Exact SQL sum over every capital purchase (item 8, "totals row") -
    // never a page's worth of client-side addition.
    totals: {
        type: Object,
        default: () => ({ total: '0.00' }),
    },
    suppliers: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    defaultVatRate: { type: String, default: '13.00' },
    depreciationCategories: { type: Array, default: () => [] },
    depreciationMethods: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Capital Purchases');

// Store/cancel both redirect back to this same route + component, which
// Inertia re-renders in place without an onMounted re-run - watch flash
// status instead (same pattern as Purchases/Index.vue).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

const typeLabels = {
    capital: 'Capital',
    service: 'Service',
};

const paymentModeLabels = {
    cash: 'Cash',
    bank: 'Bank',
    partial: 'Partial',
    credit: 'Credit',
};

const cancelling = ref(null);
const reasonForm = useForm({ reason: '' });

function openCancel(capitalPurchase) {
    cancelling.value = capitalPurchase;
    reasonForm.reset();
    reasonForm.clearErrors();
}

function onCancelModalOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    reasonForm.post(`/capital-purchases/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

// Later settlements (audit CS-01): pay off the outstanding part of a credit or
// partial bill, and (admin) cancel a settlement again.
const settling = ref(null);
const settleForm = useForm({
    date: todayInKathmandu(),
    amount: '',
    payment_mode: 'cash',
    bank_account_id: null,
    reference_number: '',
});
const settleModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
];
const settleAccountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

function openSettle(capitalPurchase) {
    settling.value = capitalPurchase;
    settleForm.reset();
    settleForm.clearErrors();
    settleForm.amount = capitalPurchase.outstanding_amount;
}

function submitSettle() {
    settleForm.post(`/capital-purchases/${settling.value.id}/settlements`, {
        preserveScroll: true,
        onSuccess: () => {
            settling.value = null;
        },
    });
}

const cancellingSettlement = ref(null);
const settlementReasonForm = useForm({ reason: '' });

function openCancelSettlement(settlement) {
    cancellingSettlement.value = settlement;
    settlementReasonForm.reset();
    settlementReasonForm.clearErrors();
}

function submitCancelSettlement() {
    settlementReasonForm.post(`/capital-purchases/settlements/${cancellingSettlement.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancellingSettlement.value = null;
            settling.value = null;
        },
    });
}

function liveSettlements(capitalPurchase) {
    return (capitalPurchase.settlements ?? []).filter((settlement) => settlement.status === 'posted');
}

function lineSummary(capitalPurchase) {
    return capitalPurchase.lines.map((line) => line.account?.name).filter(Boolean).join(', ');
}

const columns = [
    {
        id: 'date',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date),
    },
    {
        id: 'bill_number',
        header: 'Supplier bill no.',
        numeric: false,
        cell: ({ row }) => row.original.bill_number ?? '-',
    },
    {
        id: 'type',
        header: 'Type',
        numeric: false,
        cell: ({ row }) => typeLabels[row.original.type] ?? row.original.type,
    },
    {
        id: 'supplier',
        header: 'Supplier',
        numeric: false,
        cell: ({ row }) => row.original.supplier?.name ?? '-',
    },
    {
        id: 'accounts',
        header: 'Accounts',
        numeric: false,
        cell: ({ row }) => lineSummary(row.original) || '-',
    },
    {
        id: 'payment_mode',
        header: 'Paid by',
        numeric: false,
        cell: ({ row }) => paymentModeLabels[row.original.payment_mode] ?? row.original.payment_mode,
    },
    {
        id: 'total',
        header: 'Total (Rs.)',
        numeric: true,
        // The stored total, written once by the server's calculator.
        cell: ({ row }) => formatMoney(row.original.total),
    },
    {
        id: 'outstanding',
        header: 'Outstanding (Rs.)',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.outstanding_amount ?? '0.00'),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(
                Badge,
                { pill: true, variant: row.original.status === 'cancelled' ? 'danger' : 'success' },
                () => (row.original.status === 'cancelled' ? 'Cancelled' : 'Posted'),
            ),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'posted'
                ? h('div', { class: 'flex gap-2' }, [
                      row.original.supplier_id && (row.original.payment_mode === 'credit' || row.original.payment_mode === 'partial')
                          ? h(Tooltip, { label: 'Record a payment, or review payments made, against this bill' }, () =>
                                h(Button, {
                                    variant: 'secondary',
                                    tone: 'purple',
                                    type: 'button',
                                    'aria-label': `Settle capital purchase of ${formatMoney(row.original.total)}`,
                                    onClick: () => openSettle(row.original),
                                }, () => 'Settle'),
                            )
                          : null,
                      h(Tooltip, { label: 'Cancel this purchase and reverse its entries' }, () =>
                          h(Button, {
                              variant: 'secondary',
                              tone: 'purple',
                              type: 'button',
                              'aria-label': `Cancel capital purchase of ${formatMoney(row.original.total)}`,
                              onClick: () => openCancel(row.original),
                          }, () => 'Cancel'),
                      ),
                  ])
                : null,
    },
];
</script>

<template>
    <div>
        <template v-if="showCreateForm">
            <Create
                :suppliers="suppliers"
                :accounts="accounts"
                :stores="stores"
                :default-vat-rate="defaultVatRate"
                :depreciation-categories="depreciationCategories"
                :depreciation-methods="depreciationMethods"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <PageHeader title="Capital purchases" description="Long-term assets and services you bought, such as equipment or furniture. Cancel one that was posted in error.">
                <a href="/capital-purchases/export">
                    <Button variant="secondary" tone="purple" type="button">Export list</Button>
                </a>
                <Button v-if="hasOpenFiscalYear" variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" aria-hidden="true" />
                    New capital purchase
                </Button>
            </PageHeader>

            <Card variant="panel">
                <div v-if="capitalPurchases.length === 0" class="py-10 text-center">
                    <p class="text-sm font-semibold text-text-strong">No capital purchases yet</p>
                    <p class="mt-1 text-sm text-text-muted">Record the first asset or service bill you received.</p>
                    <Button v-if="hasOpenFiscalYear" class="mt-3" variant="primary" tone="purple" type="button" @click="showCreateForm = true">
                        <Plus class="size-4" aria-hidden="true" />
                        New capital purchase
                    </Button>
                </div>
                <DataTable v-else :columns="columns" :data="capitalPurchases" :page-size="10" empty-message="No capital purchases yet" />

                <!-- Server-computed SQL sum over every capital purchase
                     (item 8, "totals row"). -->
                <div class="mt-3 border-t-[1.5px] border-border pt-3 text-sm">
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Total of all capital purchases (Rs.)</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(totals.total) }}</p>
                </div>
            </Card>
        </template>

        <Modal
            :open="!!settling"
            title="Settle capital purchase"
            size="compact"
            @update:open="(value) => { if (!value) settling = null; }"
        >
            <div v-if="settling" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    Outstanding on this bill: <strong class="text-text-strong">Rs. {{ formatMoney(settling.outstanding_amount) }}</strong>
                </p>
                <div v-if="Number(settling.outstanding_amount) > 0" class="flex flex-col gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Date (BS) <span class="text-danger">*</span></label>
                        <NepaliDateInput v-model="settleForm.date" required />
                        <p v-if="settleForm.errors.date" class="mt-1 text-sm text-danger">{{ settleForm.errors.date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Amount <span class="text-danger">*</span></label>
                        <Input v-model="settleForm.amount" type="text" inputmode="decimal" required />
                        <p v-if="settleForm.errors.amount" class="mt-1 text-sm text-danger">{{ settleForm.errors.amount }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Paid by <span class="text-danger">*</span></label>
                        <Select :model-value="settleForm.payment_mode" :options="settleModeOptions" @update:model-value="(v) => (settleForm.payment_mode = v)" />
                    </div>
                    <div v-if="settleForm.payment_mode === 'bank'">
                        <label class="mb-1 block text-sm font-semibold text-text-base">Bank account <span class="text-danger">*</span></label>
                        <Combobox :model-value="settleForm.bank_account_id" :options="settleAccountOptions" placeholder="Select bank account" @update:model-value="(v) => (settleForm.bank_account_id = v)" />
                        <p v-if="settleForm.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ settleForm.errors.bank_account_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Reference</label>
                        <Input v-model="settleForm.reference_number" type="text" maxlength="255" />
                    </div>
                </div>
                <div v-if="liveSettlements(settling).length">
                    <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Payments made</h4>
                    <ul class="mt-2 flex flex-col gap-2 text-sm">
                        <li v-for="settlement in liveSettlements(settling)" :key="settlement.id" class="flex items-center justify-between gap-2">
                            <span>{{ formatBsDate(settlement.date) }} - Rs. {{ formatMoney(settlement.amount) }} ({{ settlement.payment_mode }})</span>
                            <Button v-if="canCancel" variant="secondary" tone="purple" type="button" @click="openCancelSettlement(settlement)">Cancel</Button>
                        </li>
                    </ul>
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="settling = null">Close</Button>
                <Button
                    v-if="settling && Number(settling.outstanding_amount) > 0"
                    variant="primary"
                    tone="purple"
                    type="button"
                    :loading="settleForm.processing"
                    @click="submitSettle"
                >
                    Post settlement
                </Button>
            </template>
        </Modal>

        <Modal
            :open="!!cancellingSettlement"
            title="Cancel settlement"
            size="compact"
            @update:open="(value) => { if (!value) cancellingSettlement = null; }"
        >
            <div v-if="cancellingSettlement" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    Cancelling the settlement of Rs. {{ formatMoney(cancellingSettlement.amount) }} posts a reversing entry and makes the amount outstanding again.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="settlementReasonForm.reason" type="text" maxlength="500" placeholder="Reason for cancellation" required />
                    <p v-if="settlementReasonForm.errors.reason" class="mt-1 text-sm text-danger">{{ settlementReasonForm.errors.reason }}</p>
                </div>
            </div>
            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="cancellingSettlement = null">Keep settlement</Button>
                <Button variant="primary" tone="purple" type="button" :loading="settlementReasonForm.processing" @click="submitCancelSettlement">
                    Cancel this settlement
                </Button>
            </template>
        </Modal>

        <Modal
            :open="!!cancelling"
            title="Cancel capital purchase"
            size="compact"
            @update:open="onCancelModalOpenChange"
        >
            <div v-if="cancelling" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    Cancelling the capital purchase of {{ formatMoney(cancelling.total) }} posts a reversing entry. This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="reasonForm.reason" type="text" maxlength="500" placeholder="Reason for cancellation" required />
                    <p v-if="reasonForm.errors.reason" class="mt-1 text-sm text-danger">{{ reasonForm.errors.reason }}</p>
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="cancelling = null">Keep purchase</Button>
                <Button variant="primary" tone="purple" type="button" :loading="reasonForm.processing" @click="submitCancel">
                    Cancel this purchase
                </Button>
            </template>
        </Modal>
    </div>
</template>
