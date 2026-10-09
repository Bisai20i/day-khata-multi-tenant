<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import JournalVoucherTotalsBar from '@/components/accounting/JournalVoucherTotalsBar.vue';
import { useConfirm } from '@/composables/useConfirm';
import { isZeroMoney, parseMoney, sumMoney } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

const props = defineProps({
    accounts: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['cancel', 'posted']);
const { confirm } = useConfirm();
const page = usePage();

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

const totalsRows = computed(() => [{ label: 'Voucher total', value: total.value }]);

const blockedReason = computed(() => {
    if (hasUnreadableAmount.value) return 'Every amount must be a number with at most 2 decimals.';
    if (isZeroMoney(total.value)) return 'Enter an amount.';
    if (isContra.value) {
        if (!form.from_account_id || !form.to_account_id) return 'Choose both accounts.';
        if (form.from_account_id === form.to_account_id) return 'From and To must be different accounts.';

        return null;
    }
    if (isBank.value && !form.bank_account_id) return 'Choose the bank account.';
    if (!form.lines.every((line) => line.account_id)) return 'Choose an account on every line.';

    return null;
});

/** Cancel straight away when nothing was entered, otherwise ask before discarding. */
async function requestCancel() {
    if (form.isDirty) {
        const discard = await confirm({
            title: 'Discard this voucher?',
            message: 'The details you entered have not been saved and will be lost.',
            tone: 'danger',
            confirmLabel: 'Discard voucher',
            cancelLabel: 'Keep editing',
        });
        if (!discard) return;
    }
    emit('cancel');
}

function submit(print = false) {
    if (!canSubmit.value) return;

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
        onSuccess: () => {
            // C11: the server flashes exactly which voucher it just posted.
            const created = page.props.flash?.created;
            if (print && created?.print_url) {
                window.open(created.print_url, '_blank');
            }
            emit('posted');
        },
    });
}
</script>

<template>
    <div>
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-bold text-text-strong">New cash/bank voucher</h3>
            <p class="text-xs text-text-muted">
                Record simple money in or out. Use a cash voucher when paid in cash, a bank voucher when it goes through a bank account, and Contra to move money between two cash/bank accounts.
            </p>
        </div>
        <Button variant="secondary" tone="purple" type="button" @click="requestCancel">Cancel</Button>
    </div>

    <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
        {{ form.errors.lines }}
    </p>

    <form class="flex flex-col gap-4 pb-4" @submit.prevent="submit(false)">
        <Card variant="panel" title="Voucher details" class="!p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <label class="block text-sm font-semibold text-text-base">Voucher type <span class="text-danger" aria-hidden="true">*</span></label>
                        <InfoTip
                            :text="
                                isContra
                                    ? 'Moves money from one cash/bank account to another.'
                                    : isReceipt
                                      ? 'Money coming in, to ' + (isBank ? 'a bank account.' : 'cash in hand.')
                                      : 'Money going out, from ' + (isBank ? 'a bank account.' : 'cash in hand.')
                            "
                        />
                    </div>
                    <Select v-model="form.voucher_type" :options="voucherTypeOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date (BS) <span class="text-danger" aria-hidden="true">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.date }}</p>
                </div>
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <label class="block text-sm font-semibold text-text-base">Narration <span class="text-danger" aria-hidden="true">*</span></label>
                        <InfoTip text="What this voucher is for, shown in the ledger." />
                    </div>
                    <Input v-model="form.narration" type="text" placeholder="Describe this transaction" required />
                    <p v-if="form.errors.narration" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.narration }}</p>
                </div>
            </div>
        </Card>

        <Card variant="panel" class="!p-4">
            <div class="mb-3 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                {{ isContra ? 'Transfer' : 'Entries' }} <span class="text-danger">*</span>
            </div>

            <div v-if="isContra" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">From account <span class="text-danger" aria-hidden="true">*</span></label>
                    <Combobox v-model="form.from_account_id" :options="accountOptions" placeholder="Money moves from" />
                    <p v-if="form.errors.from_account_id" class="mt-1 text-sm text-danger">{{ form.errors.from_account_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">To account <span class="text-danger" aria-hidden="true">*</span></label>
                    <Combobox v-model="form.to_account_id" :options="accountOptions" placeholder="Money moves to" />
                    <p v-if="form.errors.to_account_id" class="mt-1 text-sm text-danger">{{ form.errors.to_account_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Amount <span class="text-danger" aria-hidden="true">*</span></label>
                    <Input v-model="form.amount" class="text-right" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required />
                    <p v-if="form.errors.amount" class="mt-1 text-sm text-danger">{{ form.errors.amount }}</p>
                </div>
            </div>

            <template v-else>
                <div v-if="isBank" class="mb-4 sm:w-1/2">
                    <label class="mb-1 block text-sm font-semibold text-text-base">{{ cashOrBankLabel }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <Combobox v-model="form.bank_account_id" :options="accountOptions" placeholder="Select bank account" />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>

                <div class="mb-2 grid grid-cols-[1fr_140px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>{{ otherAccountsLabel }}</span>
                    <span class="text-right">Amount</span>
                    <span>Line note (optional)</span>
                    <span class="sr-only">Remove</span>
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
                    <div>
                        <Input v-model="line.amount" class="text-right" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" />
                        <p v-if="form.errors[`lines.${index}.amount`]" class="mt-1 text-xs text-danger">{{ form.errors[`lines.${index}.amount`] }}</p>
                    </div>
                    <Input v-model="line.narration" type="text" placeholder="Optional" />
                    <button
                        v-if="form.lines.length > 1"
                        type="button"
                        class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                        :aria-label="`Remove line ${index + 1}`"
                        :title="`Remove line ${index + 1}`"
                        @click="removeLine(index)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <button type="button" class="mt-1 flex items-center gap-1 text-xs font-semibold text-primary" @click="addLine">
                    <Plus class="h-3.5 w-3.5" /> Add another account
                </button>
            </template>
        </Card>

        <JournalVoucherTotalsBar
            :rows="totalsRows"
            :status="blockedReason"
            :can-submit="canSubmit"
            :processing="form.processing"
            submit-label="Save & post voucher"
            :blocked-hint="blockedReason ?? ''"
            @cancel="requestCancel"
            @print="submit(true)"
        />
    </form>
    </div>
</template>
