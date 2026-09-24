<script setup>
import { computed } from 'vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import { compareMoney, formatMoney, formatQuantity, subtractMoney } from '@/lib/money';

/** Receipt confirmation (frontend-only - no backend receipt endpoint). */
const props = defineProps({
    open: { type: Boolean, default: false },
    receipt: { type: Object, default: null },
});

const emit = defineEmits(['close']);

/** Change owed on the completed sale: cash tendered less the amount settled. */
const receiptChange = computed(() => {
    if (!props.receipt) return '0.00';

    const over = subtractMoney(props.receipt.tendered_cash ?? '0.00', props.receipt.cash_settled ?? '0.00');

    return compareMoney(over, '0.00') > 0 ? over : '0.00';
});
</script>

<template>
    <Modal :open="open" title="Sale complete" size="compact" @update:open="(v) => (v ? null : emit('close'))">
        <!-- Every figure below is the stored sale the server returned
             (C8: posted documents always render stored server values), not
             a client-side snapshot of what the cart looked like. The only
             exception is the change, which is cash tendered at the counter
             and never part of the bill. -->
        <div v-if="receipt" class="flex flex-col gap-3 text-sm">
            <div class="flex justify-between text-text-muted">
                <span>{{ receipt.customer_name }}</span>
                <span>{{ receipt.invoice_number }}</span>
            </div>
            <div class="flex justify-between text-text-muted">
                <span>{{ receipt.date_bs }} (BS)</span>
                <span>{{ receipt.date }}</span>
            </div>
            <div class="border-t border-border pt-2">
                <div v-for="(line, i) in receipt.lines" :key="i" class="flex justify-between py-0.5">
                    <span>{{ line.name }} × {{ formatQuantity(line.quantity) }} {{ line.unit }}</span>
                    <span class="font-semibold">{{ formatMoney(line.line_total) }}</span>
                </div>
            </div>
            <div class="border-t border-border pt-2">
                <div class="flex justify-between text-base font-bold text-text-strong">
                    <span>Total</span>
                    <span>{{ formatMoney(receipt.total) }}</span>
                </div>
                <div class="mt-1 flex justify-between text-text-muted">
                    <span>Cash settled</span>
                    <span>{{ formatMoney(receipt.cash_settled) }}</span>
                </div>
                <div class="flex justify-between text-text-muted">
                    <span>Bank settled</span>
                    <span>{{ formatMoney(receipt.bank_settled) }}</span>
                </div>
                <div class="flex justify-between font-semibold text-warning-text">
                    <span>Outstanding</span>
                    <span>{{ formatMoney(receipt.outstanding) }}</span>
                </div>
                <div class="flex justify-between font-semibold text-success">
                    <span>Change</span>
                    <span>{{ formatMoney(receiptChange) }}</span>
                </div>
            </div>
        </div>
        <template #footer>
            <Button variant="primary" tone="purple" type="button" @click="emit('close')">New sale</Button>
        </template>
    </Modal>
</template>
