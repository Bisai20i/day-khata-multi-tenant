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
import BranchBadge from '@/components/branch/BranchBadge.vue';
import { useBranchField } from '@/composables/useBranchField';
import { addMoney, compareMoney, formatMoney, parseMoney, subtractMoney, sumMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

const props = defineProps({
    outstandingLines: { type: Array, default: () => [] },
    // Staff id -> outstanding advance (2dp string); staff with none are absent.
    // An empty PHP collection arrives as a JSON array, hence both types.
    advanceBalances: { type: [Object, Array], default: () => ({}) },
    // Only accounts money can actually leave through, filtered server-side.
    bankAccounts: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    // Branch picker (BranchFormOptions::props()).
    branchOptions: { type: Array, default: () => [] },
    defaultBranchId: { type: Number, default: null },
});

const emit = defineEmits(['cancel', 'posted']);

const bankAccountOptions = computed(() => {
    const banks = props.bankAccounts.filter((account) => {
        const name = (account.name || '').toLowerCase();
        const code = (account.code || '').toLowerCase();
        return name.includes('bank') || code.startsWith('bank');
    });

    const list = banks.length > 0
        ? banks
        : props.bankAccounts.filter((a) => a.code !== 'AS1' && !['AS11', 'AS12', 'AS13', 'AS14', 'ASA23', 'AS31'].includes(a.code));

    return list.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    }));
});

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
    return allocationAmounts[line.id] !== undefined;
}

/**
 * Ticking fills the full outstanding (still editable); unticking drops the row.
 * Unticking a staff member's last payslip also forgets their advance choice,
 * so re-ticking starts again from "Keep advance pending".
 */
function toggleLine(line, checked) {
    if (checked) {
        allocationAmounts[line.id] = line.outstanding;
        return;
    }

    delete allocationAmounts[line.id];

    const staffStillSelected = props.outstandingLines.some((other) => other.staff_id === line.staff_id && isSelected(other));

    if (!staffStillSelected) {
        delete advanceChoices[line.staff_id];
    }
}

/** Exact decimal min of two 2dp strings. */
function minMoney(a, b) {
    return compareMoney(a, b) <= 0 ? a : b;
}

/** Outstanding advance for a staff member, or null when they have none. */
function advanceFor(staffId) {
    const advance = props.advanceBalances?.[staffId];

    if (advance === undefined || advance === null || compareMoney(advance, '0.00') <= 0) {
        return null;
    }

    return advance;
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

/**
 * Staff id -> { mode: 'keep' | 'deduct', amount }. No entry means "Keep
 * advance pending": nothing is offset unless the user explicitly picks
 * "Deduct advance".
 */
const advanceChoices = reactive({});

/**
 * One group per staff member with an outstanding advance among the ticked
 * payslips, with their ticked line ids in display order and the total of
 * their valid allocation amounts.
 */
const advanceGroups = computed(() => {
    const groups = new Map();

    for (const line of props.outstandingLines) {
        if (!isSelected(line)) continue;

        const advance = advanceFor(line.staff_id);
        if (advance === null) continue;

        if (!groups.has(line.staff_id)) {
            groups.set(line.staff_id, { staffId: line.staff_id, staffName: line.staff_name, staffCode: line.staff_code, advance, lineIds: [] });
        }

        groups.get(line.staff_id).lineIds.push(line.id);
    }

    return [...groups.values()].map((group) => ({
        ...group,
        settled: sumMoney(group.lineIds.map((id) => rowStates.value[id]?.amount).filter((amount) => amount !== undefined)),
    }));
});

function isDeducting(staffId) {
    return advanceChoices[staffId]?.mode === 'deduct';
}

/** Choosing "Deduct advance" pre-fills min(outstanding advance, settled for this staff). */
function setAdvanceMode(group, mode) {
    if (mode === 'deduct') {
        advanceChoices[group.staffId] = { mode: 'deduct', amount: minMoney(group.advance, group.settled) };
    } else {
        delete advanceChoices[group.staffId];
    }
}

/** Staff id -> { amount } (validated 2dp deduction, '0.00' when kept pending) or { error }. */
const deductionStates = computed(() => {
    const states = {};

    for (const group of advanceGroups.value) {
        if (!isDeducting(group.staffId)) {
            states[group.staffId] = { amount: '0.00' };
            continue;
        }

        const parsed = parseMoney(advanceChoices[group.staffId].amount);

        if (!parsed.ok) {
            states[group.staffId] = { error: parsed.reason === 'too_many_decimals' ? 'Use at most 2 decimal places.' : 'Enter a valid amount.' };
        } else if (compareMoney(parsed.value, '0.00') < 0) {
            states[group.staffId] = { error: 'Deduction cannot be negative.' };
        } else if (compareMoney(parsed.value, group.settled) > 0) {
            states[group.staffId] = { error: `Cannot exceed the ${formatMoney(group.settled)} being settled for this staff member.` };
        } else if (compareMoney(parsed.value, group.advance) > 0) {
            states[group.staffId] = { error: `Cannot exceed the outstanding advance ${formatMoney(group.advance)}.` };
        } else {
            states[group.staffId] = { amount: parsed.value };
        }
    }

    return states;
});

const hasInvalidDeduction = computed(() => Object.values(deductionStates.value).some((state) => state.error !== undefined));

/**
 * Payslip line id -> advance_adjusted. Each staff member's deduction is spread
 * over their ticked payslips in display order, never more than a line's own
 * allocation amount.
 */
const lineAdvanceAdjusted = computed(() => {
    const byLineId = {};

    for (const group of advanceGroups.value) {
        const deduction = deductionStates.value[group.staffId]?.amount;
        if (deduction === undefined) continue;

        let remaining = deduction;

        for (const lineId of group.lineIds) {
            const lineAmount = rowStates.value[lineId]?.amount;
            if (lineAmount === undefined) continue;

            const take = minMoney(remaining, lineAmount);
            byLineId[lineId] = take;
            remaining = subtractMoney(remaining, take);
        }
    }

    return byLineId;
});

const validAllocations = computed(() =>
    props.outstandingLines
        .filter((line) => rowStates.value[line.id]?.amount !== undefined)
        .map((line) => ({
            payroll_line_id: line.id,
            amount: rowStates.value[line.id].amount,
            advance_adjusted: lineAdvanceAdjusted.value[line.id] ?? '0.00',
        })),
);

const hasInvalidRow = computed(() => Object.values(rowStates.value).some((state) => state.error !== undefined));
const totalSettled = computed(() => sumMoney(validAllocations.value.map((row) => row.amount)));
const totalDeducted = computed(() => sumMoney(validAllocations.value.map((row) => row.advance_adjusted)));
const cashToPay = computed(() => subtractMoney(totalSettled.value, totalDeducted.value));

/** Still owed on a ticked payslip once this payment's valid amount is applied. */
function owedAfter(line) {
    const amount = rowStates.value[line.id]?.amount;

    return amount === undefined ? null : subtractMoney(line.outstanding, amount);
}

/** A staff member's advance left after this payment's deduction (null while invalid). */
function advanceRemaining(group) {
    const deduction = deductionStates.value[group.staffId]?.amount;

    return deduction === undefined ? null : subtractMoney(group.advance, deduction);
}

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
        !hasInvalidDeduction.value &&
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

        <p v-if="form.errors.branch_id" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
            {{ form.errors.branch_id }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <Card variant="panel" class="!p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                        <div class="grid min-w-[1100px] grid-cols-[32px_1fr_1fr_100px_100px_100px_100px_130px_110px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                            <span></span>
                            <span>Staff</span>
                            <span>Payroll run</span>
                            <span class="flex items-center gap-1">TDS withheld <InfoTip text="Already withheld on the payslip; net pay is after TDS." /></span>
                            <span class="flex items-center gap-1">SSF / CIT withheld <InfoTip text="Employee SSF and CIT already withheld on the payslip; net pay is after them. Employer contributions are not part of net pay." /></span>
                            <span>Net pay</span>
                            <span>Still owed</span>
                            <span>Settle now (Rs.)</span>
                            <span>Owed after</span>
                        </div>
                        <div
                            v-for="line in visibleLines"
                            :key="line.id"
                            class="grid min-w-[1100px] grid-cols-[32px_1fr_1fr_100px_100px_100px_100px_130px_110px] items-center gap-2"
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
                            <span class="text-sm text-text-muted">{{ formatMoney(line.tds ?? '0.00') }}</span>
                            <span class="text-sm text-text-muted">{{ formatMoney(addMoney(line.ssf_employee ?? '0.00', line.cit_employee ?? '0.00')) }}</span>
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
                            <span v-if="owedAfter(line) !== null" class="text-sm text-text-base">{{ formatMoney(owedAfter(line)) }}</span>
                            <span v-else class="text-sm text-text-faint">-</span>
                            <p v-if="rowStates[line.id]?.error || serverRowErrors[line.id]" class="col-span-9 text-xs text-danger">
                                {{ rowStates[line.id]?.error ?? serverRowErrors[line.id] }}
                            </p>
                        </div>
                    </div>
                </div>

                <p v-if="form.errors.allocations" class="mt-2 text-sm text-danger">{{ form.errors.allocations }}</p>
                <p v-if="!showPicker && form.errors.branch_id" class="mt-2 text-sm text-danger" role="alert">{{ form.errors.branch_id }}</p>
            </Card>

            <Card v-if="advanceGroups.length > 0" variant="panel" class="!p-4">
                <template #title>
                    <div class="flex items-center gap-1">
                        <span>Staff advances</span>
                        <InfoTip text="These staff members have an advance outstanding. Keep it pending, or deduct part of it from this payment instead of paying that part in cash or bank." />
                    </div>
                </template>

                <div class="flex flex-col gap-3">
                    <div v-for="group in advanceGroups" :key="group.staffId" class="flex flex-col gap-2 border-b border-border pb-3 last:border-b-0 last:pb-0">
                        <p class="text-sm text-text-base">
                            <span class="font-semibold">{{ group.staffName }}</span>
                            <span v-if="group.staffCode" class="text-xs text-text-muted"> ({{ group.staffCode }})</span>
                            <span class="text-text-muted"> - outstanding advance Rs. {{ formatMoney(group.advance) }}, settling Rs. {{ formatMoney(group.settled) }} now</span>
                        </p>
                        <div class="flex flex-wrap items-center gap-4" role="radiogroup" :aria-label="`Advance choice for ${group.staffName}`">
                            <label class="flex items-center gap-2 text-sm text-text-base">
                                <input
                                    type="radio"
                                    class="size-4 border-[1.5px] border-border"
                                    :name="`advance-choice-${group.staffId}`"
                                    :checked="!isDeducting(group.staffId)"
                                    @change="setAdvanceMode(group, 'keep')"
                                />
                                Keep advance pending
                            </label>
                            <label class="flex items-center gap-2 text-sm text-text-base">
                                <input
                                    type="radio"
                                    class="size-4 border-[1.5px] border-border"
                                    :name="`advance-choice-${group.staffId}`"
                                    :checked="isDeducting(group.staffId)"
                                    @change="setAdvanceMode(group, 'deduct')"
                                />
                                Deduct advance
                            </label>
                            <div v-if="isDeducting(group.staffId)" class="w-40">
                                <Input
                                    v-model="advanceChoices[group.staffId].amount"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                    class="text-right"
                                    :aria-label="`Advance to deduct for ${group.staffName}`"
                                />
                            </div>
                        </div>
                        <p v-if="deductionStates[group.staffId]?.error" class="text-xs text-danger">{{ deductionStates[group.staffId].error }}</p>
                        <p v-else-if="advanceRemaining(group) !== null" class="text-xs text-text-muted">
                            Advance remaining after this payment: Rs. {{ formatMoney(advanceRemaining(group)) }}
                        </p>
                    </div>
                </div>
            </Card>

            <Card variant="panel" class="!p-4">
                <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3" aria-live="polite">
                    <div>
                        <dt class="text-text-muted">Settled against salary payable</dt>
                        <dd class="font-semibold text-text-strong">
                            Rs. {{ formatMoney(totalSettled) }}
                            <span class="font-normal text-text-muted">({{ validAllocations.length }} payslip{{ validAllocations.length === 1 ? '' : 's' }})</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">Advance deducted</dt>
                        <dd class="font-semibold text-text-strong">Rs. {{ formatMoney(totalDeducted) }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ form.payment_mode === 'bank' ? 'Bank' : 'Cash' }} to pay</dt>
                        <dd class="font-semibold text-text-strong">Rs. {{ formatMoney(cashToPay) }}</dd>
                    </div>
                </dl>
            </Card>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="!canSubmit">Record payment</Button>
            </div>
        </form>
    </div>
</template>
