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

const props = defineProps({
    accounts: {
        type: Array,
        default: () => [],
    },
    fiscalYears: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['cancel', 'posted']);

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} — ${account.name}` : account.name,
    })),
);

// Only admins see this picker at all (see isAdmin above) — for everyone
// else the voucher always posts into whichever fiscal year is currently
// open, which the backend defaults to when fiscal_year_id is omitted.
const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((year) => ({
        value: year.id,
        label: year.status === 'closed' ? `${year.name} (Closed)` : year.name,
    })),
);

function emptyLine() {
    return { account_id: null, debit: '', credit: '', narration: '' };
}

const form = useForm({
    fiscal_year_id: null,
    reason: '',
    date: '',
    narration: '',
    lines: [emptyLine(), emptyLine()],
});

const selectedFiscalYear = computed(() => props.fiscalYears.find((year) => year.id === form.fiscal_year_id));
const isClosedYearSelected = computed(() => selectedFiscalYear.value?.status === 'closed');

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

// Debit and credit are mutually exclusive per line: setting one clears the other
// rather than blocking input, so the user can fix a mis-click without extra clicks.
function setDebit(index, value) {
    form.lines[index].debit = value;
    if (Number(value) > 0) form.lines[index].credit = '';
}

function setCredit(index, value) {
    form.lines[index].credit = value;
    if (Number(value) > 0) form.lines[index].debit = '';
}

const totalDebit = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.debit) || 0), 0));
const totalCredit = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.credit) || 0), 0));
const isBalanced = computed(() => totalDebit.value > 0 && Math.abs(totalDebit.value - totalCredit.value) < 0.005);
const canSubmit = computed(() => isBalanced.value && (!isClosedYearSelected.value || form.reason.trim().length > 0));

function submit() {
    form.transform((data) => ({
        ...data,
        fiscal_year_id: data.fiscal_year_id || undefined,
        reason: isClosedYearSelected.value ? data.reason : undefined,
        lines: data.lines.map((line) => ({
            account_id: line.account_id,
            debit: Number(line.debit) || 0,
            credit: Number(line.credit) || 0,
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
            <h3 class="text-base font-bold text-text-strong">New journal voucher</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div v-if="isAdmin">
                <label for="jv-fiscal-year" class="mb-1 block text-sm font-semibold text-text-base">Fiscal year</label>
                <Select
                    id="jv-fiscal-year"
                    v-model="form.fiscal_year_id"
                    :options="fiscalYearOptions"
                    placeholder="Currently open fiscal year"
                />
                <p v-if="form.errors.fiscal_year_id" class="mt-1 text-sm text-danger">{{ form.errors.fiscal_year_id }}</p>
            </div>

            <div v-if="isClosedYearSelected" class="flex flex-col gap-3 border-[1.5px] border-warning-text bg-warning-bg px-3 py-3">
                <p class="text-sm text-warning-text">
                    {{ selectedFiscalYear.name }} is closed. Posting here is a correction and will roll forward
                    through every fiscal year after it, up to the currently open one.
                </p>
                <div>
                    <label for="jv-reason" class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <textarea
                        id="jv-reason"
                        v-model="form.reason"
                        rows="2"
                        required
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none transition-colors duration-150 focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                    ></textarea>
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">{{ form.errors.reason }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="jv-date" class="mb-1 block text-sm font-semibold text-text-base">Date</label>
                    <NepaliDateInput id="jv-date" v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label for="jv-narration" class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                    <Input id="jv-narration" v-model="form.narration" type="text" required />
                    <p v-if="form.errors.narration" class="mt-1 text-sm text-danger">{{ form.errors.narration }}</p>
                </div>
            </div>

            <div>
                <div class="mb-2 grid grid-cols-[1fr_140px_140px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Account</span>
                    <span>Debit</span>
                    <span>Credit</span>
                    <span>Narration</span>
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
                        placeholder="0.00"
                        :model-value="line.debit"
                        @update:model-value="(v) => setDebit(index, v)"
                    />
                    <Input
                        type="number"
                        min="0"
                        step="0.01"
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

                <div class="mt-4 grid grid-cols-[1fr_140px_140px_1fr_28px] items-center gap-2 border-t-[1.5px] border-border pt-3">
                    <span class="text-sm font-bold text-text-strong">Total</span>
                    <span class="text-sm font-bold text-text-strong">{{ totalDebit.toFixed(2) }}</span>
                    <span class="text-sm font-bold text-text-strong">{{ totalCredit.toFixed(2) }}</span>
                    <span class="text-xs font-semibold" :class="isBalanced ? 'text-success' : 'text-danger'">
                        {{ isBalanced ? 'Balanced' : 'Debit and credit totals must match' }}
                    </span>
                    <span></span>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !canSubmit">
                    Post voucher
                </Button>
            </div>
        </form>
    </Card>
</template>
