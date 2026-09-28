<script setup>
import { computed } from 'vue';
import { ChevronDown, ChevronUp } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import { addMoney, formatMoney, isZeroMoney } from '@/lib/money';

// Same sticky bar as SaleCreateTotalsBar: the bill's figures in the order the
// printed bill shows them, the "more options" toggle and the save buttons,
// pinned to the bottom so a long bill never scrolls them out of view.
const props = defineProps({
    totals: { type: Object, default: null },
    partialSplitError: { type: String, default: null },
    showMoreOptions: { type: Boolean, default: false },
    canSubmit: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
});

defineEmits(['update:showMoreOptions', 'cancel', 'print']);

// TDS never changes the bill total, only what is left to pay the supplier -
// so those two columns only appear once something is actually withheld.
const hasTds = computed(() => !!props.totals && !isZeroMoney(props.totals.tds_amount));
</script>

<template>
    <div class="sticky bottom-0 z-10 flex flex-col gap-3 border-[1.5px] border-border bg-white px-4 py-3 shadow-[0_-4px_16px_rgba(0,0,0,.08)]">
        <div v-if="totals" class="overflow-x-auto border-[1.5px] border-border">
            <table class="w-full table-auto border-collapse text-sm">
                <thead>
                    <tr class="divide-x divide-border border-b-[1.5px] border-border bg-bg-subtle">
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Subtotal</th>
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Discount</th>
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Amount on which VAT is charged">Taxable amount</th>
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Amount with no VAT">Non-taxable amount</th>
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Value Added Tax">VAT</th>
                        <th
                            class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] uppercase"
                            :class="hasTds ? 'text-text-muted' : 'bg-primary-tint text-primary'"
                        >
                            Bill total
                        </th>
                        <template v-if="hasTds">
                            <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Tax deducted at source">TDS withheld</th>
                            <th class="bg-primary-tint px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-primary uppercase">Payable to supplier</th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <tr class="divide-x divide-border">
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(addMoney(totals.vatable_subtotal, totals.non_vatable_subtotal)) }}</td>
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.header_discount) }}</td>
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.taxable_amount) }}</td>
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.nontaxable_amount) }}</td>
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.vat_amount) }}</td>
                        <td
                            class="px-3 py-1.5"
                            :class="hasTds ? 'font-bold text-text-strong' : 'bg-primary-tint text-base font-extrabold text-primary'"
                        >
                            {{ formatMoney(totals.total) }}
                        </td>
                        <template v-if="hasTds">
                            <td class="px-3 py-1.5 font-bold text-text-strong">-{{ formatMoney(totals.tds_amount) }}</td>
                            <td class="bg-primary-tint px-3 py-1.5 text-base font-extrabold text-primary">{{ formatMoney(totals.settlement_due) }}</td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="partialSplitError" class="text-xs font-semibold text-danger">{{ partialSplitError }}</p>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <button
                type="button"
                class="flex items-center gap-1 text-xs font-semibold text-text-muted hover:text-primary"
                :aria-expanded="showMoreOptions"
                @click="$emit('update:showMoreOptions', !showMoreOptions)"
            >
                <ChevronUp v-if="showMoreOptions" class="h-3.5 w-3.5" />
                <ChevronDown v-else class="h-3.5 w-3.5" />
                Charges &amp; more options (store, PAN, bill discount, VAT rate, TDS)
            </button>

            <div class="flex items-center gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="$emit('cancel')">Cancel</Button>
                <Button variant="secondary" tone="purple" type="button" :disabled="!canSubmit || processing" @click="$emit('print')">
                    Save &amp; Print
                </Button>
                <Button variant="primary" tone="purple" type="submit" :loading="processing" :disabled="!canSubmit">
                    {{ processing ? 'Posting...' : 'Save & post purchase' }}
                </Button>
                <p v-if="!canSubmit && !processing" class="sr-only" role="status">
                    Choose a supplier and date and add at least one item to save.
                </p>
            </div>
        </div>
    </div>
</template>
