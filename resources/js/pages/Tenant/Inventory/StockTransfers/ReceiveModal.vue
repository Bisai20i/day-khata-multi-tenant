<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import Label from '@/components/ui/Label.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { formatMoney } from '@/lib/money.js';
import { formatBsDate, todayInKathmandu } from '@/lib/format.js';
import { storeLabel } from '@/lib/storeLabel.js';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * Receive a transfer between branches into its destination store (plan 12,
 * BR18). The goods enter the store on the chosen date, which cannot be
 * before the transfer date, and the receiving branch's voucher is posted for
 * the value fixed at dispatch. The full receive page is BR19.
 */
const props = defineProps({
    transfer: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const { label } = useBranchLabel();

const form = useForm({ received_on: todayInKathmandu() });

watch(
    () => props.transfer?.id,
    () => {
        form.reset();
        form.clearErrors();
        form.received_on = todayInKathmandu();
    },
);

const transferDate = computed(() => String(props.transfer?.date ?? '').slice(0, 10));

// perpetual_value is a decimal string (or absent); compare as text, no float math.
const hasPerpetualValue = computed(() => {
    const value = String(props.transfer?.perpetual_value ?? '').trim();
    return value !== '' && /[1-9]/.test(value) && !value.startsWith('-');
});

function onOpenChange(value) {
    if (!value) emit('close');
}

function submit() {
    form.post(`/stock-transfers/${props.transfer.id}/receive`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Modal :open="!!transfer" title="Receive stock transfer" size="compact" @update:open="onOpenChange">
        <form v-if="transfer" class="flex flex-col gap-4" @submit.prevent="submit">
            <p class="text-sm text-text-muted">
                Stock transfer #{{ transfer.id }} from {{ storeLabel(transfer.from_store) || '-' }}, sent on
                {{ formatBsDate(transfer.date) }} ({{ transferDate }}), worth {{ formatMoney(transfer.total_value) }}<template v-if="hasPerpetualValue"> (including raw material and finished goods)</template>.
                Receiving adds the goods to {{ storeLabel(transfer.to_store) || '-' }} and records them in this
                {{ label('lower') }}'s books. This cannot be undone; record a stock adjustment for any shortage.
            </p>
            <div>
                <Label class="mb-1">Received on <span class="text-danger">*</span></Label>
                <NepaliDateInput v-model="form.received_on" required />
                <p v-if="form.errors.received_on" class="mt-1 text-sm text-danger">{{ form.errors.received_on }}</p>
            </div>
        </form>

        <template #footer>
            <Button variant="secondary" tone="neutral" type="button" @click="emit('close')">Back</Button>
            <Button variant="primary" tone="purple" type="button" :disabled="form.processing || !form.received_on" @click="submit">
                Confirm receipt
            </Button>
        </template>
    </Modal>
</template>
