<script setup>
import Combobox from '@/components/ui/Combobox.vue';
import Input from '@/components/ui/Input.vue';
import { formatMoney } from '@/lib/money';

defineProps({
    form: { type: Object, required: true },
    refundAccountOptions: { type: Array, default: () => [] },
    refundDue: { type: String, required: true },
    refundSplitError: { type: String, default: null },
});
</script>

<template>
    <!-- The refund, shared by both shapes: either a single account
         pays the whole credit back (leave both amounts blank), or the
         cash and bank legs are entered and must add up to the refund
         due exactly (CONTRACTS C3, assertExactSplit). -->
    <div class="grid grid-cols-3 gap-4 border-t-[1.5px] border-border pt-3">
        <div>
            <label class="mb-1 block text-sm font-semibold text-text-base">Refund via (optional)</label>
            <Combobox
                :model-value="form.refund_account_id"
                :options="refundAccountOptions"
                placeholder="No refund - credit note only"
                @update:model-value="(value) => (form.refund_account_id = value)"
            />
            <p class="mt-1 text-xs text-text-muted">Cash and bank accounts only. Leave empty to only reduce what the customer owes.</p>
            <p v-if="form.errors.refund_account_id" class="mt-1 text-sm text-danger">{{ form.errors.refund_account_id }}</p>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-text-base">Refund in cash</label>
            <Input v-model="form.refund_cash_amount" type="text" inputmode="decimal" placeholder="Leave blank for no split" />
            <p v-if="form.errors.refund_cash_amount" class="mt-1 text-sm text-danger">{{ form.errors.refund_cash_amount }}</p>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-text-base">Refund from bank</label>
            <Input v-model="form.refund_bank_amount" type="text" inputmode="decimal" placeholder="Leave blank for no split" />
            <p class="mt-1 text-xs text-text-muted">Refund due: {{ formatMoney(refundDue) }}</p>
            <p v-if="form.errors.refund_bank_amount" class="mt-1 text-sm text-danger">{{ form.errors.refund_bank_amount }}</p>
        </div>
        <p v-if="refundSplitError" class="col-span-3 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ refundSplitError }}
        </p>
    </div>
</template>
