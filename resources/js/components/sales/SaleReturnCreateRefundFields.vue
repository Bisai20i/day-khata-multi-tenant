<script setup>
import Card from '@/components/ui/Card.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Input from '@/components/ui/Input.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Label from '@/components/ui/Label.vue';
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
    <Card variant="panel" title="Refund" class="grid grid-cols-3 gap-4 !p-4">
        <div>
            <div class="mb-1 flex items-center gap-1">
                <Label>Refund via (optional)</Label>
                <InfoTip text="Cash and bank accounts only. Leave empty to only reduce what the customer owes." />
            </div>
            <Combobox
                :model-value="form.refund_account_id"
                :options="refundAccountOptions"
                placeholder="No refund - credit note only"
                @update:model-value="(value) => (form.refund_account_id = value)"
            />
            <p v-if="form.errors.refund_account_id" class="mt-1 text-sm text-danger">{{ form.errors.refund_account_id }}</p>
        </div>
        <div>
            <Label class="mb-1">Refund in cash</Label>
            <Input v-model="form.refund_cash_amount" type="text" inputmode="decimal" placeholder="Leave blank for no split" />
            <p v-if="form.errors.refund_cash_amount" class="mt-1 text-sm text-danger">{{ form.errors.refund_cash_amount }}</p>
        </div>
        <div>
            <Label class="mb-1">Refund from bank</Label>
            <Input v-model="form.refund_bank_amount" type="text" inputmode="decimal" placeholder="Leave blank for no split" />
            <p class="mt-1 text-xs text-text-muted">Refund due: {{ formatMoney(refundDue) }}</p>
            <p v-if="form.errors.refund_bank_amount" class="mt-1 text-sm text-danger">{{ form.errors.refund_bank_amount }}</p>
        </div>
        <p v-if="refundSplitError" class="col-span-3 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ refundSplitError }}
        </p>
    </Card>
</template>
