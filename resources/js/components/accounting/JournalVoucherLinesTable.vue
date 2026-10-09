<script setup>
import { X } from '@lucide/vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import { isZeroMoney } from '@/lib/money';
import { voucherAmountOf } from '@/lib/journalVoucherCreate';

// The lines already committed to the voucher - same layout as
// SaleCreateLinesTable. `lines` and `errors` are the parent form's own,
// edited in place.
const props = defineProps({
    lines: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    accountOptions: { type: Array, default: () => [] },
});

defineEmits(['remove']);

// Debit and credit are mutually exclusive per line: setting one clears the
// other rather than blocking input.
function setDebit(index, value) {
    props.lines[index].debit = value;
    const amount = voucherAmountOf(value);
    if (amount !== null && !isZeroMoney(amount)) props.lines[index].credit = '';
}

function setCredit(index, value) {
    props.lines[index].credit = value;
    const amount = voucherAmountOf(value);
    if (amount !== null && !isZeroMoney(amount)) props.lines[index].debit = '';
}

const LINE_GRID = 'grid-cols-[1fr_130px_130px_1fr_28px]';
</script>

<template>
    <div>
        <div class="my-4 border-t border-border" />

        <div class="mb-3 flex items-center gap-1.5">
            <span class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">On this voucher</span>
            <span class="bg-primary-tint px-2 py-0.5 text-[11px] font-bold text-primary">{{ lines.length }}</span>
            <InfoTip text="Each line takes either a debit or a credit, not both. Debit increases assets and expenses; credit increases liabilities, income and equity." />
        </div>

        <p v-if="lines.length === 0" class="py-6 text-center text-sm text-text-faint">
            No lines yet. Pick an account above, enter a debit or credit, then press "Add line" (or Enter). A voucher needs at least two lines.
        </p>

        <div v-else>
            <div class="mb-2 grid gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" :class="LINE_GRID">
                <span>Account</span>
                <span class="text-right">Debit (Dr)</span>
                <span class="text-right">Credit (Cr)</span>
                <span>Line narration</span>
                <span class="sr-only">Remove</span>
            </div>

            <div v-for="(line, index) in lines" :key="index" class="mb-2 grid items-start gap-2" :class="LINE_GRID">
                <div>
                    <Combobox
                        :model-value="line.account_id"
                        :options="accountOptions"
                        placeholder="Select account"
                        @update:model-value="(v) => (line.account_id = v)"
                    />
                    <p v-if="errors[`lines.${index}.account_id`]" class="mt-1 text-xs text-danger">{{ errors[`lines.${index}.account_id`] }}</p>
                </div>
                <div>
                    <Input
                        class="text-right"
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        placeholder="0.00"
                        :model-value="line.debit"
                        @update:model-value="(v) => setDebit(index, v)"
                    />
                    <p v-if="errors[`lines.${index}.debit`]" class="mt-1 text-xs text-danger">{{ errors[`lines.${index}.debit`] }}</p>
                </div>
                <div>
                    <Input
                        class="text-right"
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        placeholder="0.00"
                        :model-value="line.credit"
                        @update:model-value="(v) => setCredit(index, v)"
                    />
                    <p v-if="errors[`lines.${index}.credit`]" class="mt-1 text-xs text-danger">{{ errors[`lines.${index}.credit`] }}</p>
                </div>
                <Input v-model="line.narration" type="text" placeholder="Optional" />
                <button
                    type="button"
                    class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                    :aria-label="`Remove line ${index + 1} from voucher`"
                    :title="`Remove line ${index + 1}`"
                    @click="$emit('remove', index)"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    </div>
</template>
