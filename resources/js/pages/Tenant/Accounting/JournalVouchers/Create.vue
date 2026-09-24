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
import { formatMoney, isZeroMoney, moneyEquals, parseMoney, subtractMoney, sumMoney } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

const props = defineProps({
    accounts: {
        type: Array,
        default: () => [],
    },
    // The one closed fiscal year currently reopened for correction, or
    // null - the create form only ever offers this single alternate to the
    // currently open year (never any other closed year), per the locked
    // design decision in plans/invoicing-settings-sale-purchase-ux.md
    // ("Locked decisions" #3 / Phase D's recommended option (a)).
    correctionFiscalYear: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['cancel', 'posted']);

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

// Only admins see this picker at all (see isAdmin above) - for everyone
// else the voucher always posts into whichever fiscal year is currently
// open, which the backend defaults to when fiscal_year_id is omitted. The
// single option offered is the reopened-for-correction year itself -
// leaving the select blank keeps posting into the current open year.
const fiscalYearOptions = computed(() =>
    props.correctionFiscalYear
        ? [{ value: props.correctionFiscalYear.id, label: `${props.correctionFiscalYear.name} (reopened for correction)` }]
        : [],
);

function emptyLine() {
    return { account_id: null, debit: '', credit: '', narration: '' };
}

// todayInKathmandu(), never new Date().toISOString(): Nepal is UTC+05:45, so
// the UTC day is yesterday's date for anyone posting before 05:45 local.
const form = useForm({
    fiscal_year_id: null,
    reason: '',
    date: todayInKathmandu(),
    narration: '',
    lines: [emptyLine(), emptyLine()],
});

const isClosedYearSelected = computed(
    () => !!props.correctionFiscalYear && form.fiscal_year_id === props.correctionFiscalYear.id,
);

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

// A blank or not-yet-valid box contributes nothing; anything else is the exact
// 2dp string money.js parsed, never Number(). A third decimal is not silently
// rounded here either - it simply doesn't count towards the totals, so the
// voucher stays visibly unbalanced until it is fixed, which is the same answer
// JournalVoucher::validateLines() gives on the server.
function amountOf(value) {
    const parsed = parseMoney(value === '' || value === null || value === undefined ? 0 : value);

    return parsed.ok ? parsed.value : null;
}

// Debit and credit are mutually exclusive per line: setting one clears the other
// rather than blocking input, so the user can fix a mis-click without extra clicks.
function setDebit(index, value) {
    form.lines[index].debit = value;
    const amount = amountOf(value);
    if (amount !== null && !isZeroMoney(amount)) form.lines[index].credit = '';
}

function setCredit(index, value) {
    form.lines[index].credit = value;
    const amount = amountOf(value);
    if (amount !== null && !isZeroMoney(amount)) form.lines[index].debit = '';
}

const totalDebit = computed(() => sumMoney(form.lines.map((line) => amountOf(line.debit) ?? '0.00')));
const totalCredit = computed(() => sumMoney(form.lines.map((line) => amountOf(line.credit) ?? '0.00')));

// Exact equality, no tolerance: a 0.005 window is precisely how an unbalanced
// voucher used to reach the database (audit P0-2).
const difference = computed(() => subtractMoney(totalDebit.value, totalCredit.value));
const isBalanced = computed(() => !isZeroMoney(totalDebit.value) && moneyEquals(totalDebit.value, totalCredit.value));
const hasUnreadableAmount = computed(() =>
    form.lines.some((line) => amountOf(line.debit) === null || amountOf(line.credit) === null),
);
const canSubmit = computed(
    () => isBalanced.value && !hasUnreadableAmount.value && (!isClosedYearSelected.value || form.reason.trim().length > 0),
);

function submit() {
    form.transform((data) => ({
        ...data,
        fiscal_year_id: data.fiscal_year_id || undefined,
        reason: isClosedYearSelected.value ? data.reason : undefined,
        lines: data.lines.map((line) => ({
            account_id: line.account_id,
            debit: amountOf(line.debit) ?? '0.00',
            credit: amountOf(line.credit) ?? '0.00',
            narration: line.narration || undefined,
        })),
    })).post('/journal-vouchers', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-text-strong">New journal voucher</h3>
                <p class="text-sm text-text-muted">Record a manual accounting entry. Total debits must equal total credits before you can post.</p>
            </div>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div v-if="isAdmin && correctionFiscalYear">
                <label for="jv-fiscal-year" class="mb-1 block text-sm font-semibold text-text-base">Fiscal year</label>
                <Select
                    id="jv-fiscal-year"
                    v-model="form.fiscal_year_id"
                    :options="fiscalYearOptions"
                    placeholder="Currently open fiscal year"
                />
                <p class="mt-1 text-xs text-text-muted">Leave blank to post into the currently open fiscal year.</p>
                <p v-if="form.errors.fiscal_year_id" class="mt-1 text-sm text-danger">{{ form.errors.fiscal_year_id }}</p>
            </div>

            <div v-if="isClosedYearSelected" class="flex flex-col gap-3 border-[1.5px] border-warning-text bg-warning-bg px-3 py-3">
                <p class="text-sm text-warning-text">
                    {{ correctionFiscalYear.name }} is reopened for correction. Posting here will roll forward
                    through every fiscal year after it, up to the currently open one.
                </p>
                <div>
                    <label for="jv-reason" class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <textarea
                        id="jv-reason"
                        v-model="form.reason"
                        rows="2"
                        placeholder="Explain why this correction is needed"
                        required
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none transition-colors duration-150 focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                    ></textarea>
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">{{ form.errors.reason }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="jv-date" class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput id="jv-date" v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label for="jv-narration" class="mb-1 block text-sm font-semibold text-text-base">Narration <span class="text-danger">*</span></label>
                    <Input id="jv-narration" v-model="form.narration" type="text" placeholder="Describe this transaction" required />
                    <p v-if="form.errors.narration" class="mt-1 text-sm text-danger">{{ form.errors.narration }}</p>
                </div>
            </div>

            <div>
                <div class="mb-2 grid grid-cols-[1fr_140px_140px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Account <span class="text-danger">*</span></span>
                    <span>Debit (Dr)</span>
                    <span>Credit (Cr)</span>
                    <span>Line narration</span>
                    <span></span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_140px_140px_1fr_28px] items-start gap-2">
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
                    <Input
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        placeholder="0.00"
                        :model-value="line.debit"
                        @update:model-value="(v) => setDebit(index, v)"
                    />
                    <Input
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        placeholder="0.00"
                        :model-value="line.credit"
                        @update:model-value="(v) => setCredit(index, v)"
                    />
                    <Input v-model="line.narration" type="text" placeholder="Optional" />
                    <button
                        v-if="form.lines.length > 2"
                        type="button"
                        class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                        aria-label="Remove line"
                        @click="removeLine(index)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <Button variant="secondary" tone="purple" type="button" class="mt-1" @click="addLine">
                    <Plus class="h-3.5 w-3.5" /> Add line
                </Button>

                <p class="mt-2 text-xs text-text-muted">Each line takes either a debit or a credit, not both. Debit increases assets and expenses; credit increases liabilities, income and equity.</p>

                <div class="mt-4 grid grid-cols-[1fr_140px_140px_1fr_28px] items-center gap-2 border-t-[1.5px] border-border pt-3">
                    <span class="text-sm font-bold text-text-strong">Debit total / Credit total</span>
                    <span class="text-sm font-bold text-text-strong">{{ formatMoney(totalDebit) }}</span>
                    <span class="text-sm font-bold text-text-strong">{{ formatMoney(totalCredit) }}</span>
                    <span class="text-xs font-semibold" :class="isBalanced && !hasUnreadableAmount ? 'text-success' : 'text-danger'">
                        <template v-if="hasUnreadableAmount">Every amount must be a number with at most 2 decimals</template>
                        <template v-else>{{ isBalanced ? 'Balanced' : 'Unbalanced: debit and credit totals must match' }}</template>
                    </span>
                    <span></span>
                </div>
                <div class="mt-1 flex items-center justify-end gap-2 text-sm">
                    <span class="font-semibold text-text-base">Difference</span>
                    <span class="font-bold" :class="isBalanced ? 'text-success' : 'text-danger'">{{ formatMoney(difference) }}</span>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !canSubmit">
                    {{ form.processing ? 'Posting...' : 'Post journal voucher' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
