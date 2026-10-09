<script setup>
import { computed } from 'vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import InfoTip from '@/components/ui/InfoTip.vue';

// Payment and Notes side by side, same layout as SaleCreatePaymentNotes.
const props = defineProps({
    form: { type: Object, required: true },
    // Money can only leave through an asset account, so the server sends
    // this list already narrowed to the Assets head.
    bankAccounts: { type: Array, default: () => [] },
    showBankField: { type: Boolean, default: false },
    showPartialFields: { type: Boolean, default: false },
});

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial (cash + bank)' },
    { value: 'credit', label: 'Credit' },
];

const bankAccountOptions = computed(() =>
    props.bankAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })),
);
</script>

<template>
    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
        <Card variant="panel" class="!p-4">
            <template #title>
                <div class="flex items-center gap-1">
                    <span>Payment</span>
                    <InfoTip text="Credit = you pay the supplier later and the amount goes on their account." />
                    <span id="purchase-payment-help" class="sr-only">
                        Credit = you pay the supplier later and the amount goes on their account.
                    </span>
                </div>
            </template>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="purchase-payment-mode" class="mb-1 block text-sm font-semibold text-text-base">Payment type <span class="text-danger" aria-hidden="true">*</span></label>
                    <Select id="purchase-payment-mode" v-model="form.payment_mode" :options="paymentModeOptions" aria-describedby="purchase-payment-help" />
                </div>
                <div v-if="showBankField">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank account <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.bank_account_id"
                        :options="bankAccountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (form.bank_account_id = v)"
                    />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>
            </div>

            <div v-if="showPartialFields" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="purchase-cash-amount" class="mb-1 block text-sm font-semibold text-text-base">Paid in cash (Rs) <span class="text-danger" aria-hidden="true">*</span></label>
                    <Input id="purchase-cash-amount" v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" required aria-describedby="purchase-cash-error" />
                    <p v-if="form.errors.cash_amount" id="purchase-cash-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.cash_amount }}</p>
                </div>
                <div>
                    <label for="purchase-bank-amount" class="mb-1 block text-sm font-semibold text-text-base">Paid by bank (Rs) <span class="text-danger" aria-hidden="true">*</span></label>
                    <Input id="purchase-bank-amount" v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" required aria-describedby="purchase-bank-error" />
                    <p v-if="form.errors.bank_amount" id="purchase-bank-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.bank_amount }}</p>
                </div>
            </div>
        </Card>

        <Card variant="panel" title="Notes" class="!p-4">
            <label for="purchase-narration" class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
            <Input id="purchase-narration" v-model="form.narration" type="text" placeholder="Optional note kept with this purchase" />
            <p v-if="form.errors.narration" class="mt-1 text-sm text-danger">{{ form.errors.narration }}</p>
        </Card>
    </div>
</template>
