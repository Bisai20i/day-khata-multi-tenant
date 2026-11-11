<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import BranchSelect from '@/components/branch/BranchSelect.vue';
import BranchBadge from '@/components/branch/BranchBadge.vue';
import { useBranchField } from '@/composables/useBranchField';
import { compareMoney, formatMoney, parseMoney } from '@/lib/money';

const props = defineProps({
    // [{id, staff_code, name, advance}] - `advance` is the outstanding advance now.
    staff: { type: Array, default: () => [] },
    // Only accounts money can actually leave through, filtered server-side.
    bankAccounts: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    // Branch picker (BranchFormOptions::props()).
    branchOptions: { type: Array, default: () => [] },
    defaultBranchId: { type: Number, default: null },
});

const emit = defineEmits(['cancel', 'posted']);

const staffOptions = computed(() =>
    props.staff.map((member) => ({
        value: member.id,
        label: member.staff_code ? `${member.staff_code} - ${member.name}` : member.name,
    })),
);

const bankAccountOptions = computed(() =>
    props.bankAccounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
];

const form = useForm({
    staff_id: null,
    date: props.today,
    amount: '',
    payment_mode: 'cash',
    bank_account_id: null,
    reference_number: '',
    narration: '',
});

const showBankAccount = computed(() => form.payment_mode === 'bank');

// Branches (plan 12, BR12): the paying branch, where the cash or bank line
// posts. It starts blank on purpose: blank keeps the server's default, the
// staff member's branch when the user may use it (BR09, deliberately not the
// switcher's branch). Hidden and not submitted unless the company runs
// several branches and the user may pick.
const { branchId, showPicker, withBranch } = useBranchField(
    () => props.branchOptions,
    () => null,
);
const selectedStaff = computed(() => props.staff.find((member) => member.id === form.staff_id) ?? null);

// Half-typed or over-precise input simply keeps the button disabled; the
// canonical 2dp string is what gets posted.
const amountState = computed(() => {
    if (form.amount === '' || form.amount === null) return { ok: false, message: '' };

    const parsed = parseMoney(form.amount);

    if (!parsed.ok) {
        return { ok: false, message: parsed.reason === 'too_many_decimals' ? 'Use at most 2 decimal places.' : 'Enter a valid amount.' };
    }

    if (compareMoney(parsed.value, '0.00') <= 0) {
        return { ok: false, message: 'Amount must be greater than zero.' };
    }

    return { ok: true, message: '', value: parsed.value };
});

const canSubmit = computed(
    () => !form.processing && !!form.staff_id && amountState.value.ok && (!showBankAccount.value || !!form.bank_account_id),
);

function submit() {
    form.transform((data) => withBranch({
        ...data,
        amount: amountState.value.ok ? amountState.value.value : data.amount,
        bank_account_id: data.payment_mode === 'bank' ? data.bank_account_id : null,
    })).post('/hr/staff-advances', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <div>
        <PageHeader title="Give staff advance" description="Money handed to a staff member ahead of salary. It is recovered through payroll deductions. Fields marked * are required.">
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </PageHeader>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <Card variant="panel" class="!p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <Label class="mb-1">Staff <span class="text-danger">*</span></Label>
                        <Combobox
                            :model-value="form.staff_id"
                            :options="staffOptions"
                            placeholder="Select staff"
                            @update:model-value="(v) => (form.staff_id = v)"
                        />
                        <p v-if="form.errors.staff_id" class="mt-1 text-sm text-danger">{{ form.errors.staff_id }}</p>
                        <p v-if="selectedStaff" class="mt-1 text-xs text-text-muted" aria-live="polite">
                            Outstanding advance now: Rs. {{ formatMoney(selectedStaff.advance) }}
                            <BranchBadge :branch-id="selectedStaff.branch_id ?? null" class="ml-1" />
                        </p>
                    </div>
                    <div>
                        <Label class="mb-1">Date (BS) <span class="text-danger">*</span></Label>
                        <NepaliDateInput v-model="form.date" required />
                        <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Amount (Rs.) <span class="text-danger">*</span></Label>
                        <Input v-model="form.amount" type="text" inputmode="decimal" placeholder="0.00" class="text-right" required />
                        <p v-if="form.errors.amount" class="mt-1 text-sm text-danger">{{ form.errors.amount }}</p>
                        <p v-if="!showPicker && form.errors.branch_id" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.branch_id }}</p>
                        <p v-else-if="amountState.message" class="mt-1 text-sm text-danger">{{ amountState.message }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Paid by <span class="text-danger">*</span></Label>
                        <Select :model-value="form.payment_mode" :options="paymentModeOptions" @update:model-value="(v) => (form.payment_mode = v)" />
                        <p v-if="form.errors.payment_mode" class="mt-1 text-sm text-danger">{{ form.errors.payment_mode }}</p>
                    </div>
                    <BranchSelect
                        v-model="branchId"
                        :options="branchOptions"
                        :error="form.errors.branch_id"
                        :required="false"
                        help="Paid from. Leave blank for the staff member's branch."
                    />
                    <div v-if="showBankAccount">
                        <Label class="mb-1">Bank account <span class="text-danger">*</span></Label>
                        <Combobox
                            :model-value="form.bank_account_id"
                            :options="bankAccountOptions"
                            placeholder="Select bank account"
                            @update:model-value="(v) => (form.bank_account_id = v)"
                        />
                        <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Reference number</Label>
                        <Input v-model="form.reference_number" type="text" maxlength="255" placeholder="Cheque or transfer number (optional)" />
                        <p v-if="form.errors.reference_number" class="mt-1 text-sm text-danger">{{ form.errors.reference_number }}</p>
                    </div>
                    <div class="sm:col-span-3">
                        <Label class="mb-1">Notes (narration)</Label>
                        <Input v-model="form.narration" type="text" maxlength="255" placeholder="Optional note kept with this advance" />
                        <p v-if="form.errors.narration" class="mt-1 text-sm text-danger">{{ form.errors.narration }}</p>
                    </div>
                </div>
            </Card>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="!canSubmit">Give advance</Button>
            </div>
        </form>
    </div>
</template>
