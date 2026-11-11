<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import BranchSelect from '@/components/branch/BranchSelect.vue';
import { useBranchField } from '@/composables/useBranchField';
import { useBranchLabel } from '@/composables/useBranchLabel';
import { compareMoney, formatMoney } from '@/lib/money';
import { formatBsDate, todayInKathmandu } from '@/lib/format';

/**
 * Later settlement of a credit capital or service bill (audit CS-01), with
 * the bill's live settlements and, for a user who may cancel them, a Cancel
 * button per row (the confirmation modal stays on the page).
 *
 * Branches (plan 12, BR12): the paying branch starts on the bill's own branch
 * when the user may use it, so the normal case posts no Inter-Branch Clearing
 * lines; otherwise on the form default. The picker and the `branch_id` field
 * only exist for a multi-branch company. A branch-restricted user also sees
 * one "paid from other branches" line for settlements they cannot see
 * (`settled_in_other_branches`), so the list matches the outstanding balance.
 */
const props = defineProps({
    // The bill being settled; null closes the modal.
    capitalPurchase: { type: Object, default: null },
    accounts: { type: Array, default: () => [] },
    canCancelSettlement: { type: Boolean, default: false },
    branchOptions: { type: Array, default: () => [] },
    defaultBranchId: { type: Number, default: null },
});

const emit = defineEmits(['close', 'cancel-settlement']);

const { plural } = useBranchLabel();

const settleForm = useForm({
    date: todayInKathmandu(),
    amount: '',
    payment_mode: 'cash',
    bank_account_id: null,
    reference_number: '',
});

const { branchId, showPicker, withBranch } = useBranchField(
    () => props.branchOptions,
    () => props.defaultBranchId,
);

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

const hasOutstanding = computed(() => compareMoney(props.capitalPurchase?.outstanding_amount ?? '0.00', '0.00') > 0);

const liveSettlements = computed(() => (props.capitalPurchase?.settlements ?? []).filter((settlement) => settlement.status === 'posted'));

const settledInOtherBranches = computed(() => props.capitalPurchase?.settled_in_other_branches ?? null);

// A fresh form for every bill opened (by id, so a failed submit, which
// replaces the page props, keeps what the user typed).
watch(
    () => props.capitalPurchase?.id ?? null,
    (id) => {
        if (id === null) {
            return;
        }

        settleForm.reset();
        settleForm.clearErrors();
        settleForm.amount = props.capitalPurchase.outstanding_amount;

        const billBranchId = props.capitalPurchase.branch_id ?? null;
        branchId.value = props.branchOptions.some((option) => option.id === billBranchId) ? billBranchId : props.defaultBranchId;
    },
    { immediate: true },
);

function submitSettle() {
    settleForm.transform((data) => withBranch(data)).post(`/capital-purchases/${props.capitalPurchase.id}/settlements`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Modal
        :open="!!capitalPurchase"
        title="Settle capital purchase"
        size="compact"
        @update:open="(value) => { if (!value) emit('close'); }"
    >
        <div v-if="capitalPurchase" class="flex flex-col gap-4">
            <p class="text-sm text-text-muted">
                Outstanding on this bill: <strong class="text-text-strong">Rs. {{ formatMoney(capitalPurchase.outstanding_amount) }}</strong>
            </p>
            <div v-if="hasOutstanding" class="flex flex-col gap-3">
                <div>
                    <Label class="mb-1">Date (BS) <span class="text-danger">*</span></Label>
                    <NepaliDateInput v-model="settleForm.date" required />
                    <p v-if="settleForm.errors.date" class="mt-1 text-sm text-danger">{{ settleForm.errors.date }}</p>
                </div>
                <div>
                    <Label class="mb-1">Amount <span class="text-danger">*</span></Label>
                    <Input v-model="settleForm.amount" type="text" inputmode="decimal" required />
                    <p v-if="settleForm.errors.amount" class="mt-1 text-sm text-danger">{{ settleForm.errors.amount }}</p>
                    <p v-if="!showPicker && settleForm.errors.branch_id" class="mt-1 text-sm text-danger" role="alert">{{ settleForm.errors.branch_id }}</p>
                </div>
                <BranchSelect
                    v-model="branchId"
                    :options="branchOptions"
                    :error="settleForm.errors.branch_id"
                    help="Where the money was paid from."
                />
                <div>
                    <Label class="mb-1">Paid by <span class="text-danger">*</span></Label>
                    <Select :model-value="settleForm.payment_mode" :options="settleModeOptions" @update:model-value="(v) => (settleForm.payment_mode = v)" />
                </div>
                <div v-if="settleForm.payment_mode === 'bank'">
                    <Label class="mb-1">Bank account <span class="text-danger">*</span></Label>
                    <Combobox :model-value="settleForm.bank_account_id" :options="settleAccountOptions" placeholder="Select bank account" @update:model-value="(v) => (settleForm.bank_account_id = v)" />
                    <p v-if="settleForm.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ settleForm.errors.bank_account_id }}</p>
                </div>
                <div>
                    <Label class="mb-1">Reference</Label>
                    <Input v-model="settleForm.reference_number" type="text" maxlength="255" />
                </div>
            </div>
            <div v-if="liveSettlements.length || settledInOtherBranches">
                <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Payments made</h4>
                <ul class="mt-2 flex flex-col gap-2 text-sm">
                    <li v-for="settlement in liveSettlements" :key="settlement.id" class="flex items-center justify-between gap-2">
                        <span>{{ formatBsDate(settlement.date) }} - Rs. {{ formatMoney(settlement.amount) }} ({{ settlement.payment_mode }})</span>
                        <Button v-if="canCancelSettlement" variant="secondary" tone="neutral" type="button" @click="emit('cancel-settlement', settlement)">Cancel</Button>
                    </li>
                    <li v-if="settledInOtherBranches" class="flex items-center justify-between gap-2 text-text-muted">
                        <span>Paid from other {{ plural('lower') }} - Rs. {{ formatMoney(settledInOtherBranches) }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <template #footer>
            <Button variant="secondary" tone="neutral" type="button" @click="emit('close')">Close</Button>
            <Button
                v-if="capitalPurchase && hasOutstanding"
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
</template>
