<script setup>
import { computed } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import { formatBsDate } from '@/lib/format';

/**
 * Status cell for the purchase and capital purchase lists. A cancelled bill
 * shows who cancelled it, when and why under its badge (flags G-11), so a
 * reader of the list does not have to open the Cancelled Documents report to
 * learn why a bill no longer counts.
 */
const props = defineProps({
    document: { type: Object, required: true },
    pill: { type: Boolean, default: false },
});

const isCancelled = computed(() => props.document.status === 'cancelled');

const cancelledOn = computed(() => {
    const at = props.document.cancelled_at;
    if (!at) {
        return null;
    }
    const adDate = at.slice(0, 10);
    return `${formatBsDate(adDate) || adDate} (AD ${adDate})`;
});
</script>

<template>
    <div class="flex flex-col items-start gap-0.5">
        <Badge :pill="pill" :variant="isCancelled ? 'danger' : 'success'">{{ isCancelled ? 'Cancelled' : 'Posted' }}</Badge>
        <div v-if="isCancelled" class="max-w-[220px] text-[11px] leading-snug text-text-muted">
            <div v-if="cancelledOn || document.canceller">
                <span v-if="cancelledOn">{{ cancelledOn }}</span>
                <span v-if="document.canceller"> by {{ document.canceller.name }}</span>
            </div>
            <div v-if="document.cancel_reason" class="break-words">Reason: {{ document.cancel_reason }}</div>
        </div>
    </div>
</template>
