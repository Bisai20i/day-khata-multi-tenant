<script setup>
import { formatMoney } from '@/lib/money';

defineProps({
    totals: { type: Object, default: null },
});

function money(value) {
    return value == null ? '-' : formatMoney(value);
}
</script>

<template>
    <div class="grid grid-cols-2 gap-2 text-sm">
        <template v-if="totals && totals.header_discount !== '0.00'">
            <span class="text-text-muted">Discount on whole bill</span>
            <span class="text-right font-semibold text-text-strong">-{{ money(totals.header_discount) }}</span>
        </template>
        <span class="text-text-muted">Taxable amount</span>
        <span class="text-right font-semibold text-text-strong">{{ money(totals?.taxable_amount) }}</span>
        <span class="text-text-muted">Non-taxable amount</span>
        <span class="text-right font-semibold text-text-strong">{{ money(totals?.nontaxable_amount) }}</span>
        <span class="text-text-muted">VAT</span>
        <span class="text-right font-semibold text-text-strong">{{ money(totals?.vat_amount) }}</span>
        <span class="font-bold text-text-strong">Bill total</span>
        <span class="text-right font-bold text-text-strong">{{ money(totals?.total) }}</span>
        <template v-if="totals && totals.tds_amount !== '0.00'">
            <span class="text-text-muted">TDS Withheld</span>
            <span class="text-right font-semibold text-text-strong">-{{ money(totals.tds_amount) }}</span>
            <span class="text-text-muted">Amount payable to supplier (after TDS)</span>
            <span class="text-right font-semibold text-text-strong">{{ money(totals.settlement_due) }}</span>
        </template>
    </div>
</template>
