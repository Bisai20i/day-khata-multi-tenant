<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import JournalVoucherStagingRow from '@/components/accounting/JournalVoucherStagingRow.vue';
import JournalVoucherLinesTable from '@/components/accounting/JournalVoucherLinesTable.vue';
import JournalVoucherTotalsBar from '@/components/accounting/JournalVoucherTotalsBar.vue';
import { useConfirm } from '@/composables/useConfirm';
import { isZeroMoney, moneyEquals, subtractMoney, sumMoney } from '@/lib/money';
import { voucherAmountOf } from '@/lib/journalVoucherCreate';
import { todayInKathmandu } from '@/lib/format';

/**
 * Laid out like Sales/Create.vue: voucher details card, one "Add line" row
 * feeding the voucher's lines, and a sticky bar carrying the Dr/Cr balance
 * and the post buttons.
 */
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
const { confirm } = useConfirm();

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

// todayInKathmandu(), never new Date().toISOString(): Nepal is UTC+05:45, so
// the UTC day is yesterday's date for anyone posting before 05:45 local.
const form = useForm({
    fiscal_year_id: null,
    reason: '',
    date: todayInKathmandu(),
    narration: '',
    // Lines only ever enter the voucher through the "Add line" staging row
    // (JournalVoucherStagingRow) - no starter blank rows here.
    lines: [],
});

const isClosedYearSelected = computed(
    () => !!props.correctionFiscalYear && form.fiscal_year_id === props.correctionFiscalYear.id,
);

const totalDebit = computed(() => sumMoney(form.lines.map((line) => voucherAmountOf(line.debit) ?? '0.00')));
const totalCredit = computed(() => sumMoney(form.lines.map((line) => voucherAmountOf(line.credit) ?? '0.00')));

// Exact equality, no tolerance: a 0.005 window is precisely how an unbalanced
// voucher used to reach the database (audit P0-2).
const difference = computed(() => subtractMoney(totalDebit.value, totalCredit.value));
const isBalanced = computed(() => !isZeroMoney(totalDebit.value) && moneyEquals(totalDebit.value, totalCredit.value));
const hasUnreadableAmount = computed(() =>
    form.lines.some((line) => voucherAmountOf(line.debit) === null || voucherAmountOf(line.credit) === null),
);
const canSubmit = computed(
    () => form.lines.length >= 2
        && form.lines.every((line) => line.account_id)
        && isBalanced.value
        && !hasUnreadableAmount.value
        && (!isClosedYearSelected.value || form.reason.trim().length > 0),
);

const totalsRows = computed(() => [
    { label: 'Debit total', value: totalDebit.value },
    { label: 'Credit total', value: totalCredit.value },
    { label: 'Difference', value: difference.value, tone: isBalanced.value ? 'success' : 'danger' },
]);

const balanceStatus = computed(() => {
    if (hasUnreadableAmount.value) return 'Every amount must be a number with at most 2 decimals.';
    if (form.lines.length < 2) return 'Add at least two lines.';

    return isBalanced.value ? 'Balanced' : 'Unbalanced: debit and credit totals must match.';
});

/** Cancel straight away when nothing was entered, otherwise ask before discarding. */
async function requestCancel() {
    const hasEntries = form.isDirty || form.lines.length > 0;
    if (hasEntries) {
        const discard = await confirm({
            title: 'Discard this voucher?',
            message: 'The lines and details you entered have not been saved and will be lost.',
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

    form.transform((data) => ({
        ...data,
        fiscal_year_id: data.fiscal_year_id || undefined,
        reason: isClosedYearSelected.value ? data.reason : undefined,
        lines: data.lines.map((line) => ({
            account_id: line.account_id,
            debit: voucherAmountOf(line.debit) ?? '0.00',
            credit: voucherAmountOf(line.credit) ?? '0.00',
            narration: line.narration || undefined,
        })),
    })).post('/journal-vouchers', {
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
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h3 class="text-base font-bold text-text-strong">New journal voucher</h3>
            <p class="text-xs text-text-muted">Record a manual accounting entry. Fields marked * are required. Total debits must equal total credits before you can post.</p>
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
                    <label for="jv-date" class="mb-1 block text-sm font-semibold text-text-base">Date (BS) <span class="text-danger" aria-hidden="true">*</span></label>
                    <NepaliDateInput id="jv-date" v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.date }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label for="jv-narration" class="mb-1 block text-sm font-semibold text-text-base">Narration <span class="text-danger" aria-hidden="true">*</span></label>
                    <Input id="jv-narration" v-model="form.narration" type="text" placeholder="Describe this transaction" required aria-describedby="jv-narration-help" />
                    <p id="jv-narration-help" class="mt-1 text-xs text-text-muted">Printed on the voucher and shown in every ledger it touches.</p>
                    <p v-if="form.errors.narration" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.narration }}</p>
                </div>
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
                <div v-if="isClosedYearSelected" class="sm:col-span-2">
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
            <p v-if="isClosedYearSelected" class="mt-3 border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-sm text-warning-text">
                {{ correctionFiscalYear.name }} is reopened for correction. Posting here will roll forward
                through every fiscal year after it, up to the currently open one.
            </p>
        </Card>

        <Card variant="panel" class="!p-4">
            <div class="mb-3 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Entries <span class="text-danger">*</span></div>

            <JournalVoucherStagingRow
                :account-options="accountOptions"
                :total-debit="totalDebit"
                :total-credit="totalCredit"
                @add="(line) => form.lines.push(line)"
            />

            <JournalVoucherLinesTable
                :lines="form.lines"
                :errors="form.errors"
                :account-options="accountOptions"
                @remove="(index) => form.lines.splice(index, 1)"
            />
        </Card>

        <JournalVoucherTotalsBar
            :rows="totalsRows"
            :status="balanceStatus"
            :status-tone="isBalanced && !hasUnreadableAmount && form.lines.length >= 2 ? 'success' : 'danger'"
            :can-submit="canSubmit"
            :processing="form.processing"
            submit-label="Save & post voucher"
            blocked-hint="Add at least two lines whose debits and credits balance to post."
            @cancel="requestCancel"
            @print="submit(true)"
        />
    </form>
    </div>
</template>
