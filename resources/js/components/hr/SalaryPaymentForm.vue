<script setup>
import { computed, reactive } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import BranchSelect from '@/components/branch/BranchSelect.vue';
import BranchBadge from '@/components/branch/BranchBadge.vue';
import { useBranchField } from '@/composables/useBranchField';
import { compareMoney, formatMoney, parseMoney, sumMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

const props = defineProps({
    outstandingLines: { type: Array, default: () => [] },
    // Only accounts money can actually leave through, filtered server-side.
    bankAccounts: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    // Branch picker (BranchFormOptions::props()).
    branchOptions: { type: Array, default: () => [] },
    defaultBranchId: { type: Number, default: null },
});

const emit = defineEmits(['cancel', 'posted']);

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
    date: props.today,
    payment_mode: 'cash',
    bank_account_id: null,
    reference_number: '',
    narration: '',
});

const showBankAccount = computed(() => form.payment_mode === 'bank');

// Branches (plan 12, BR12): the paying branch, where the cash or bank line
// posts. It starts blank on purpose: blank keeps the server's default, the
// payslips' branch when the user may use it (BR09, deliberately not the
// switcher's branch). Hidden and not submitted unless the company runs
// several branches and the user may pick.
const { branchId, showPicker, withBranch } = useBranchField(
    () => props.branchOptions,
    () => null,
);

// Payslip line id -> amount string. Presence of a key means the row is ticked.
const allocationAmounts = reactive({});
const staffFilter = reactive({ text: '' });

const visibleLines = computed(() => {
    const needle = staffFilter.text.trim().toLowerCase();

    if (needle === '') {
        return props.outstandingLines;
    }

    return props.outstandingLines.filter(
        (line) => line.staff_name.toLowerCase().includes(needle) || (line.staff_code ?? '').toLowerCase().includes(needle),
    );
});

function isSelected(line) {
    return Object.prototype.hasOwnProperty.call(allocationAmounts, line.id);
}

/** Ticking fills the full outstanding (still editable); unticking drops the row. */
function toggleLine(line, checked) {
    if (checked) {
        allocationAmounts[line.id] = line.outstanding;
    } else {
        delete allocationAmounts[line.id];
    }
}

/**
 * One entry per ticked row: either a validated 2dp amount or an error message.
 * Exact decimal compare against the row's outstanding, no tolerance.
 */
const rowStates = computed(() => {
    const states = {};

    for (const line of props.outstandingLines) {
        if (!isSelected(line)) continue;

        const parsed = parseMoney(allocationAmounts[line.id]);

        if (!parsed.ok) {
            states[line.id] = { error: parsed.reason === 'too_many_decimals' ? 'Use at most 2 decimal places.' : 'Enter a valid amount.' };
        } else if (compareMoney(parsed.value, '0.00') <= 0) {
            states[line.id] = { error: 'Amount must be greater than zero.' };
        } else if (compareMoney(parsed.value, line.outstanding) > 0) {
            states[line.id] = { error: `Cannot exceed the outstanding ${formatMoney(line.outstanding)}.` };
        } else {
            states[line.id] = { amount: parsed.value };
        }
    }

    return states;
});

const validAllocations = computed(() =>
    props.outstandingLines
        .filter((line) => rowStates.value[line.id]?.amount !== undefined)
        .map((line) => ({ payroll_line_id: line.id, amount: rowStates.value[line.id].amount })),
);

const hasInvalidRow = computed(() => Object.values(rowStates.value).some((state) => state.error !== undefined));
const totalPaid = computed(() => sumMoney(validAllocations.value.map((row) => row.amount)));

/** Server field errors are keyed by position in `allocations`; map them to the payslip row. */
const serverRowErrors = computed(() => {
    const byLineId = {};

    for (const [key, message] of Object.entries(form.errors)) {
        const match = /^allocations\.(\d+)\./.exec(key);
        const allocation = match ? validAllocations.value[Number(match[1])] : null;

        if (allocation) {
            byLineId[allocation.payroll_line_id] = message;
        }
    }

    return byLineId;
});

const canSubmit = computed(
    () =>
        !form.processing &&
        validAllocations.value.length > 0 &&
        !hasInvalidRow.value &&
        (!showBankAccount.value || !!form.bank_account_id),
);

function submit() {
    form.transform((data) => withBranch({
        ...data,
        bank_account_id: data.payment_mode === 'bank' ? data.bank_account_id : null,
        allocations: validAllocations.value,
    })).post('/hr/salary-payments', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <div>
        <PageHeader title="Record salary payment" description="Pay out salary against staff payslips that still have an amount owing. Fields marked * are required.">
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </PageHeader>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <Card variant="panel" class="!p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <BranchSelect
                        v-model="branchId"
                        :options="branchOptions"
                        :error="form.errors.branch_id"
                        :required="false"
                        help="Paid from. Leave blank for the payslips' branch."
                    />
                    <div>
                        <Label class="mb-1">Payment date (BS) <span class="text-danger">*</span></Label>
                        <NepaliDateInput v-model="form.date" required />
                        <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Paid by <span class="text-danger">*</span></Label>
                        <Select :model-value="form.payment_mode" :options="paymentModeOptions" @update:model-value="(v) => (form.payment_mode = v)" />
                        <p v-if="form.errors.payment_mode" class="mt-1 text-sm text-danger">{{ form.errors.payment_mode }}</p>
                    </div>
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
                        <Input v-model="form.narration" type="text" maxlength="255" placeholder="Optional note kept with this payment" />
                        <p v-if="form.errors.narration" class="mt-1 text-sm text-danger">{{ form.errors.narration }}</p>
                    </div>
                </div>
            </Card>

            <Card variant="panel" class="!p-4">
                <template #title>
                    <div class="flex items-center gap-1">
                        <span>Payslips to pay</span>
                        <InfoTip text="Tick a payslip to pay its full outstanding amount, or edit the amount to pay part of it." />
                    </div>
                </template>

                <p v-if="outstandingLines.length === 0" class="text-sm text-text-muted">No payslips have an amount owing. Post a payroll run first.</p>

                <div v-else class="flex flex-col gap-2">
                    <div class="max-w-xs">
                        <Label class="mb-1">Filter by staff</Label>
                        <Input v-model="staffFilter.text" type="text" placeholder="Staff name or code" />
                    </div>

                    <p v-if="visibleLines.length === 0" class="text-sm text-text-muted">No payslips match this staff filter.</p>

                    <div v-else class="flex flex-col gap-2 overflow-x-auto">
                        <div class="grid min-w-[760px] grid-cols-[32px_1fr_1fr_110px_110px_130px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                            <span></span>
                            <span>Staff</span>
                            <span>Payroll run</span>
                            <span>Net pay</span>
                            <span>Still owed</span>
                            <span>Pay now (Rs.)</span>
                        </div>
                        <div
                            v-for="line in visibleLines"
                            :key="line.id"
                            class="grid min-w-[760px] grid-cols-[32px_1fr_1fr_110px_110px_130px] items-center gap-2"
                        >
                            <input
                                type="checkbox"
                                class="size-4 border-[1.5px] border-border"
                                :checked="isSelected(line)"
                                :aria-label="`Pay ${line.staff_name} for ${line.period_label}`"
                                @change="toggleLine(line, $event.target.checked)"
                            />
                            <span class="text-sm text-text-base">
                                {{ line.staff_name }}
                                <span v-if="line.staff_code" class="text-xs text-text-muted">({{ line.staff_code }})</span>
                                <BranchBadge :branch-id="line.branch_id ?? null" class="ml-1" />
                            </span>
                            <span class="text-sm text-text-base">
                                {{ line.run_number }} - {{ line.period_label }}
                                <span class="text-xs text-text-muted">{{ formatBsDate(line.run_date) }}</span>
                            </span>
                            <span class="text-sm text-text-base">{{ formatMoney(line.net) }}</span>
                            <span class="text-sm text-text-base">{{ formatMoney(line.outstanding) }}</span>
                            <Input
                                v-if="isSelected(line)"
                                v-model="allocationAmounts[line.id]"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                                class="text-right"
                                :aria-label="`Amount to pay ${line.staff_name} for ${line.period_label}`"
                            />
                            <span v-else class="text-sm text-text-faint">-</span>
                            <p v-if="rowStates[line.id]?.error || serverRowErrors[line.id]" class="col-span-6 text-xs text-danger">
                                {{ rowStates[line.id]?.error ?? serverRowErrors[line.id] }}
                            </p>
                        </div>
                    </div>
                </div>

                <p v-if="form.errors.allocations" class="mt-2 text-sm text-danger">{{ form.errors.allocations }}</p>
                <p v-if="!showPicker && form.errors.branch_id" class="mt-2 text-sm text-danger" role="alert">{{ form.errors.branch_id }}</p>
                <p class="mt-2 text-sm font-semibold text-text-strong" aria-live="polite">
                    Total to pay: Rs. {{ formatMoney(totalPaid) }}
                    <span class="font-normal text-text-muted">({{ validAllocations.length }} payslip{{ validAllocations.length === 1 ? '' : 's' }})</span>
                </p>
            </Card>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="!canSubmit">Record payment</Button>
            </div>
        </form>
    </div>
</template>
