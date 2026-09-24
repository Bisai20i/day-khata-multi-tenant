<script setup>
import { ref } from 'vue';
import { ChevronDown, ChevronUp } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
import { addMoney, formatMoney } from '@/lib/money';

defineProps({
    totals: { type: Object, default: null },
    showPartialFields: { type: Boolean, default: false },
    partialBalanced: { type: Boolean, default: true },
    showMoreOptions: { type: Boolean, default: false },
    canSubmit: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['update:showMoreOptions', 'cancel', 'print']);

// "Save & Print" copy count (audit section 4 polish, "Save & Print N
// copies"): the backend already supports ?copies=N up to
// SaleController::MAX_PRINT_COPIES (5), this just wires a picker to it.
const PRINT_COPY_OPTIONS = [1, 2, 3, 4, 5];
const printCopies = ref(1);

/** Save & Print with a specific copy count, chosen from the dropdown. */
function submitAndPrint(copies) {
    printCopies.value = copies;
    emit('print', copies);
}
</script>

<template>
    <!-- Totals + actions: pinned to the bottom of the scroll area so a
         long bill's grand total and Save buttons never scroll out of
         view (audit UX pass). -->
    <div class="sticky bottom-0 z-10 flex flex-col gap-3 border-[1.5px] border-border bg-white px-4 py-3 shadow-[0_-4px_16px_rgba(0,0,0,.08)]">
        <!-- Subtotal, Discount, Taxable, Non-taxable, VAT, Total: the same
             order and the same six figures the printed bill shows, so the
             cashier can reconcile the screen against the paper line by
             line instead of having to trust that Taxable + VAT reaches the
             Total on a mixed bill. -->
        <div v-if="totals" class="border-[1.5px] border-border">
            <table class="w-full table-auto border-collapse text-sm">
                <thead>
                    <tr class="divide-x divide-border border-b-[1.5px] border-border bg-bg-subtle">
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Subtotal</th>
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Discount</th>
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Amount on which VAT is charged">Taxable amount</th>
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Amount with no VAT">Non-taxable amount</th>
                        <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Value Added Tax">VAT</th>
                        <th class="bg-primary-tint px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-primary uppercase">Total to pay</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="divide-x divide-border">
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(addMoney(totals.vatable_subtotal, totals.non_vatable_subtotal)) }}</td>
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.header_discount) }}</td>
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.taxable_amount) }}</td>
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.nontaxable_amount) }}</td>
                        <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.vat_amount) }}</td>
                        <td class="bg-primary-tint px-3 py-1.5 text-base font-extrabold text-primary">{{ formatMoney(totals.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="totals && showPartialFields && !partialBalanced" class="text-xs font-semibold text-danger">
            Cash + bank amounts must add up to exactly {{ formatMoney(totals.settlement_due) }}.
        </p>

        <div class="flex items-center justify-between gap-2">
            <button
                type="button"
                class="flex items-center gap-1 text-xs font-semibold text-text-muted hover:text-primary"
                @click="$emit('update:showMoreOptions', !showMoreOptions)"
            >
                <ChevronUp v-if="showMoreOptions" class="h-3.5 w-3.5" />
                <ChevronDown v-else class="h-3.5 w-3.5" />
                Charges &amp; more options (store, bill discount, TDS, agent)
            </button>

            <div class="flex items-center gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="$emit('cancel')">Cancel</Button>
                <div class="flex">
                    <Button
                        variant="secondary"
                        tone="purple"
                        type="button"
                        class="!rounded-r-none"
                        :disabled="!canSubmit"
                        @click="submitAndPrint(printCopies)"
                    >
                        Save &amp; Print ({{ printCopies }} {{ printCopies === 1 ? 'copy' : 'copies' }})
                    </Button>
                    <DropdownMenu align="end">
                        <template #trigger>
                            <Button variant="secondary" tone="purple" type="button" class="!rounded-l-none !border-l-0 !px-2" :disabled="!canSubmit">
                                <ChevronDown class="size-3.5" />
                            </Button>
                        </template>
                        <DropdownMenuItem v-for="n in PRINT_COPY_OPTIONS" :key="n" @select="submitAndPrint(n)">
                            {{ n }} {{ n === 1 ? 'copy' : 'copies' }}
                        </DropdownMenuItem>
                    </DropdownMenu>
                </div>
                <Button variant="primary" tone="purple" type="submit" :loading="processing" :disabled="!canSubmit">
                    {{ processing ? 'Posting...' : 'Save & post sale' }}
                </Button>
                <p v-if="!canSubmit && !processing" class="sr-only" role="status">
                    Choose a customer and date and add at least one item to save.
                </p>
            </div>
        </div>
    </div>
</template>
