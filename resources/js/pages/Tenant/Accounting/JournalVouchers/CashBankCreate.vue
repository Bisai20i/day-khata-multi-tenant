<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { formatMoney, isZeroMoney, parseMoney, sumMoney } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

const props = defineProps({
    accounts: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['cancel', 'posted']);

const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

const voucherTypeOptions = [
    { value: 'cash_receipt', label: 'Cash Receipt' },
    { value: 'cash_payment', label: 'Cash Payment' },
    { value: 'bank_receipt', label: 'Bank Receipt' },
    { value: 'bank_payment', label: 'Bank Payment' },
    { value: 'contra', label: 'Contra (transfer between two cash/bank accounts)' },
];

function emptyLine() {
    return { account_id: null, amount: '', narration: '' };
}

// todayInKathmandu(), never new Date().toISOString(): Nepal is UTC+05:45, so
// the UTC day is yesterday's date for anyone posting before 05:45 local.
const form = useForm({
    voucher_type: 'cash_receipt',
    date: todayInKathmandu(),
    narration: '',
    bank_account_id: null,
    from_account_id: null,
    to_account_id: null,
    amount: '',
    lines: [emptyLine()],
});

const isBank = computed(() => form.voucher_type === 'bank_receipt' || form.voucher_type === 'bank_payment');
const isContra = computed(() => form.voucher_type === 'contra');
const isReceipt = computed(() => form.voucher_type === 'cash_receipt' || form.voucher_type === 'bank_receipt');

const otherAccountsLabel = computed(() => (isReceipt.value ? 'Received from' : 'Paid to'));
const cashOrBankLabel = computed(() => (isBank.value ? 'Bank account' : 'Cash (In Hand)'));

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

function amountOf(value) {
    const parsed = parseMoney(value === '' || value === null || value === undefined ? 0 : value);

    return parsed.ok ? parsed.value : null;
}

const hasUnreadableAmount = computed(() =>
    isContra.value
        ? amountOf(form.amount) === null
        : form.lines.some((line) => amountOf(line.amount) === null),
);

const total = computed(() =>
    isContra.value ? (amountOf(form.amount) ?? '0.00') : sumMoney(form.lines.map((line) => amountOf(line.amount) ?? '0.00')),
);

const canSubmit = computed(() => {
    if (hasUnreadableAmount.value) return false;
    if (isZeroMoney(total.value)) return false;
    if (isContra.value) {
        return !!form.from_account_id && !!form.to_account_id && form.from_account_id !== form.to_account_id;
    }
    if (isBank.value && !form.bank_account_id) return false;
    return form.lines.length > 0 && form.lines.every((line) => line.account_id);
});

function submit() {
    form.transform((data) => {
        const payload = {
            voucher_type: data.voucher_type,
            date: data.date,
            narration: data.narration,
        };

        if (isContra.value) {
            payload.from_account_id = data.from_account_id;
            payload.to_account_id = data.to_account_id;
            payload.amount = amountOf(data.amount) ?? '0.00';
        } else {
            if (isBank.value) payload.bank_account_id = data.bank_account_id;
            payload.lines = data.lines.map((line) => ({
                account_id: line.account_id,
                amount: amountOf(line.amount) ?? '0.00',
                narration: line.narration || undefined,
            }));
        }

        return payload;
    }).post('/journal-vouchers/cash-bank', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h3 class="text-base font-bold text-text-strong">New cash/bank voucher</h3>
                <p class="mt-1 text-[13px] text-text-muted">
                    Record simple money in or out. Use a cash voucher when paid in cash, a bank voucher when it goes through a bank account, and Contra to move money between two cash/bank accounts.
                </p>
            </div>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Back to vouchers</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Voucher type <span class="text-danger">*</span></label>
                    <Select v-model="form.voucher_type" :options="voucherTypeOptions" />
                    <p class="mt-1 text-xs text-text-muted">
                        {{
                            isContra
                                ? 'Moves money from one cash/bank account to another.'
                                : isReceipt
                                  ? 'Money coming in, to ' + (isBank ? 'a bank account.' : 'cash in hand.')
                                  : 'Money going out, from ' + (isBank ? 'a bank account.' : 'cash in hand.')
                        }}
                    </p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Narration <span class="text-danger">*</span></label>
                <Input v-model="form.narration" type="text" placeholder="Describe this transaction" required />
                <p class="mt-1 text-xs text-text-muted">A short note explaining what this voucher is for, shown in the ledger.</p>
                <p v-if="form.errors.narration" class="mt-1 text-sm text-danger">{{ form.errors.narration }}</p>
            </div>

            <template v-if="isContra">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">From Account <span class="text-danger">*</span></label>
                        <Combobox v-model="form.from_account_id" :options="accountOptions" placeholder="Money moves from" />
                        <p v-if="form.errors.from_account_id" class="mt-1 text-sm text-danger">{{ form.errors.from_account_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">To Account <span class="text-danger">*</span></label>
                        <Combobox v-model="form.to_account_id" :options="accountOptions" placeholder="Money moves to" />
                        <p v-if="form.errors.to_account_id" class="mt-1 text-sm text-danger">{{ form.errors.to_account_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Amount <span class="text-danger">*</span></label>
                        <Input v-model="form.amount" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required />
                        <p v-if="form.errors.amount" class="mt-1 text-sm text-danger">{{ form.errors.amount }}</p>
                    </div>
                </div>
            </template>

            <template v-else>
                <div v-if="isBank" class="w-1/2">
                    <label class="mb-1 block text-sm font-semibold text-text-base">{{ cashOrBankLabel }} <span class="text-danger">*</span></label>
                    <Combobox v-model="form.bank_account_id" :options="accountOptions" placeholder="Select bank account" />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>

                <div>
                    <div class="mb-2 grid grid-cols-[1fr_140px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <span>{{ otherAccountsLabel }} <span class="text-danger">*</span></span>
                        <span>Amount <span class="text-danger">*</span></span>
                        <span>Line note (optional)</span>
                        <span></span>
                    </div>

                    <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_140px_1fr_28px] items-start gap-2">
                        <div>
                            <Combobox
                                :model-value="line.account_id"
                                :options="accountOptions"
                                placeholder="Select account"
                                @update:model-value="(v) => (line.account_id = v)"
                            />
                            <p v-if="form.errors[`lines.${index}.account_id`]" class="mt-1 text-xs text-danger">
                                {{ form.errors[`lines.${index}.account_id`] }}
                            </p>
                        </div>
                        <Input v-model="line.amount" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" />
                        <Input v-model="line.narration" type="text" placeholder="Optional" />
                        <button
                            v-if="form.lines.length > 1"
                            type="button"
                            class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                            aria-label="Remove line"
                            @click="removeLine(index)"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <Button variant="secondary" tone="purple" type="button" class="mt-1" @click="addLine">
                        <Plus class="h-3.5 w-3.5" /> Add account
                    </Button>

                    <div class="mt-4 flex items-center justify-end gap-2 border-t-[1.5px] border-border pt-3 text-sm font-bold text-text-strong">
                        <span>Total</span>
                        <span>{{ formatMoney(total) }}</span>
                    </div>
                </div>
            </template>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="form.processing || !canSubmit">
                    Post voucher
                </Button>
            </div>
        </form>
    </Card>
</template>
