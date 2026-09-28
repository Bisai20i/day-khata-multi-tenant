<script setup>
import { computed, nextTick, ref } from 'vue';
import { Plus } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { isZeroMoney } from '@/lib/money';
import { balancingEntry, emptyVoucherLine, voucherAmountOf } from '@/lib/journalVoucherCreate';

// --- Add line: staging panel -------------------------------------------------
// Same step 2/step 3 split as SaleCreateStagingRow: one row of entry fields,
// separate from the lines already on the voucher. Picking an account pre-fills
// whatever amount would balance the voucher on the side it's short, so a
// two-line entry is: account, amount, Enter, account, Enter.
const props = defineProps({
    accountOptions: { type: Array, default: () => [] },
    totalDebit: { type: String, default: '0.00' },
    totalCredit: { type: String, default: '0.00' },
});

const emit = defineEmits(['add']);

const stagingLine = ref(emptyVoucherLine());
const stagingLineEl = ref(null);
const stagingAccountEl = ref(null);

function focusStagingField(field) {
    nextTick(() => {
        const target = field === 'account'
            ? stagingAccountEl.value?.querySelector('input')
            : stagingLineEl.value?.querySelector(`[data-staging-field="${field}"] input`);
        target?.focus();
        target?.select?.();
    });
}

function selectStagingAccount(accountId) {
    stagingLine.value.account_id = accountId;

    if (stagingLine.value.debit === '' && stagingLine.value.credit === '') {
        const suggestion = balancingEntry(props.totalDebit, props.totalCredit);
        stagingLine.value.debit = suggestion.debit;
        stagingLine.value.credit = suggestion.credit;
    }

    focusStagingField(stagingLine.value.credit !== '' ? 'credit' : 'debit');
}

// Debit and credit are mutually exclusive per line: typing one clears the
// other rather than blocking input, so a mis-click is fixed in one go.
function setStagingDebit(value) {
    stagingLine.value.debit = value;
    const amount = voucherAmountOf(value);
    if (amount !== null && !isZeroMoney(amount)) stagingLine.value.credit = '';
}

function setStagingCredit(value) {
    stagingLine.value.credit = value;
    const amount = voucherAmountOf(value);
    if (amount !== null && !isZeroMoney(amount)) stagingLine.value.debit = '';
}

/** An account and a non-zero amount on one side is enough to commit a line. */
const canAddStagingLine = computed(() => {
    const debit = voucherAmountOf(stagingLine.value.debit);
    const credit = voucherAmountOf(stagingLine.value.credit);

    return !!stagingLine.value.account_id
        && debit !== null
        && credit !== null
        && (!isZeroMoney(debit) || !isZeroMoney(credit));
});

function addStagingLine() {
    if (!canAddStagingLine.value) {
        focusStagingField(stagingLine.value.account_id ? 'debit' : 'account');
        return;
    }

    emit('add', { ...stagingLine.value });
    stagingLine.value = emptyVoucherLine();
    focusStagingField('account');
}

// --- Enter key: advance, never submit --------------------------------------
// Enter walks Debit -> Credit -> Line narration and then commits the line,
// instead of posting a half-entered voucher.
const STAGING_FIELDS = ['debit', 'credit', 'narration'];

function onStagingEnter(field) {
    const position = STAGING_FIELDS.indexOf(field);

    if (position < STAGING_FIELDS.length - 1) {
        focusStagingField(STAGING_FIELDS[position + 1]);
        return;
    }

    addStagingLine();
}
</script>

<template>
    <div ref="stagingLineEl" class="grid grid-cols-1 items-end gap-3 sm:grid-cols-[2fr_1fr_1fr_1.6fr]">
        <div ref="stagingAccountEl">
            <label class="mb-1 block text-sm font-semibold text-text-base">Account</label>
            <Combobox
                :model-value="stagingLine.account_id"
                :options="accountOptions"
                placeholder="Search account"
                @update:model-value="selectStagingAccount"
            />
        </div>
        <div data-staging-field="debit">
            <label class="mb-1 block text-sm font-semibold text-text-base">Debit (Dr)</label>
            <Input
                :model-value="stagingLine.debit"
                type="number"
                min="0"
                step="0.01"
                inputmode="decimal"
                placeholder="0.00"
                @update:model-value="setStagingDebit"
                @keydown.enter.prevent="onStagingEnter('debit')"
            />
        </div>
        <div data-staging-field="credit">
            <label class="mb-1 block text-sm font-semibold text-text-base">Credit (Cr)</label>
            <Input
                :model-value="stagingLine.credit"
                type="number"
                min="0"
                step="0.01"
                inputmode="decimal"
                placeholder="0.00"
                @update:model-value="setStagingCredit"
                @keydown.enter.prevent="onStagingEnter('credit')"
            />
        </div>
        <div class="flex items-end gap-2">
            <div data-staging-field="narration" class="flex-1">
                <label class="mb-1 block text-sm font-semibold text-text-base">Line narration</label>
                <Input
                    v-model="stagingLine.narration"
                    type="text"
                    placeholder="Optional"
                    @keydown.enter.prevent="onStagingEnter('narration')"
                />
            </div>
            <Button variant="primary" tone="purple" type="button" :disabled="!canAddStagingLine" @click="addStagingLine">
                <Plus class="h-3.5 w-3.5" /> Add line
            </Button>
        </div>
    </div>
</template>
