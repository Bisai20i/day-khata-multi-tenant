<script setup>
import Button from '@/components/ui/Button.vue';
import { formatMoney } from '@/lib/money';

// Same sticky bar as SaleCreateTotalsBar, carrying the voucher's balance
// instead of a bill's totals - pinned to the bottom so a long voucher never
// scrolls the Dr/Cr check or the post buttons out of view. Shared by the
// journal and cash/bank voucher forms: `rows` are the figure cells, the last
// one highlighted as the headline.
defineProps({
    // [{ label, value, tone? }] - tone 'success' | 'danger' colours the value.
    rows: { type: Array, default: () => [] },
    status: { type: String, default: null },
    statusTone: { type: String, default: 'danger' },
    canSubmit: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    submitLabel: { type: String, default: 'Save & post voucher' },
    blockedHint: { type: String, default: '' },
});

defineEmits(['cancel', 'print']);
</script>

<template>
    <div class="sticky bottom-0 z-10 flex flex-col gap-3 border-[1.5px] border-border bg-white px-4 py-3 shadow-[0_-4px_16px_rgba(0,0,0,.08)]">
        <div class="overflow-x-auto border-[1.5px] border-border">
            <table class="w-full table-auto border-collapse text-sm">
                <thead>
                    <tr class="divide-x divide-border border-b-[1.5px] border-border bg-bg-subtle">
                        <th
                            v-for="(row, index) in rows"
                            :key="row.label"
                            class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] uppercase"
                            :class="index === rows.length - 1 ? 'bg-primary-tint text-primary' : 'text-text-muted'"
                        >
                            {{ row.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="divide-x divide-border">
                        <td
                            v-for="(row, index) in rows"
                            :key="row.label"
                            class="px-3 py-1.5"
                            :class="[
                                index === rows.length - 1 ? 'bg-primary-tint text-base font-extrabold' : 'font-bold',
                                row.tone === 'success' ? 'text-success' : row.tone === 'danger' ? 'text-danger' : index === rows.length - 1 ? 'text-primary' : 'text-text-strong',
                            ]"
                        >
                            {{ formatMoney(row.value) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs font-semibold" :class="statusTone === 'success' ? 'text-success' : 'text-danger'" role="status" aria-live="polite">
                {{ status }}
            </p>

            <div class="flex items-center gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="$emit('cancel')">Cancel</Button>
                <Button variant="secondary" tone="purple" type="button" :disabled="!canSubmit || processing" @click="$emit('print')">
                    Save &amp; Print
                </Button>
                <Button variant="primary" tone="purple" type="submit" :loading="processing" :disabled="!canSubmit">
                    {{ processing ? 'Posting...' : submitLabel }}
                </Button>
                <p v-if="!canSubmit && !processing && blockedHint" class="sr-only" role="status">{{ blockedHint }}</p>
            </div>
        </div>
    </div>
</template>
