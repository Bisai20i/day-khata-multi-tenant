<script setup>
import { compareMoney, formatMoney } from '@/lib/money.js';

/**
 * Opening / Debit / Credit / Closing of every sub-ledger of the selected
 * account. A row's name opens that sub-ledger's own ledger; the parent's own
 * postings row (when it has any) is not clickable. The total row ties to the
 * ledger below when "include sub-ledgers" is on.
 *
 * - `summary`: { rows: [{ id, code, name, own, openingBalance, openingSide,
 *   debit, credit, closingBalance, closingSide }], total: same shape | null }.
 *   Balances are signed decimal strings; the Dr / Cr side comes from the server.
 */
defineProps({
    summary: { type: Object, required: true },
});

const emit = defineEmits(['select']);

const headClass = 'px-[9px] py-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase whitespace-nowrap';
const cellClass = 'border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle first:border-l-[1.5px] last:border-r-[1.5px]';
const numClass = `${cellClass} text-right whitespace-nowrap [font-variant-numeric:tabular-nums]`;
const totalClass = 'border-y-[1.5px] border-border bg-primary-tint/30 px-[9px] py-2 align-middle text-xs font-bold text-text-strong first:border-l-[1.5px] last:border-r-[1.5px]';

/** Debit or credit cell: a zero prints blank. */
function amountOrBlank(value) {
    return compareMoney(value ?? '0.00', '0.00') === 0 ? '' : formatMoney(value);
}

/** "1,250.00 Dr" / "1,250.00 Cr"; zero prints "0.00" with no side. */
function balanceLabel(value, side) {
    const text = formatMoney(value ?? '0.00');
    const amount = text.startsWith('-') ? text.slice(1) : text;

    return compareMoney(value ?? '0.00', '0.00') === 0 || !side ? amount : `${amount} ${side}`;
}
</script>

<template>
    <div class="w-full overflow-x-auto">
        <table class="w-full border-separate [border-spacing:0_4px] text-[12.5px] text-text-base">
            <thead>
                <tr>
                    <th scope="col" :class="[headClass, 'text-left']">Sub-ledger</th>
                    <th scope="col" :class="[headClass, 'text-right']">Opening</th>
                    <th scope="col" :class="[headClass, 'text-right']">Debit</th>
                    <th scope="col" :class="[headClass, 'text-right']">Credit</th>
                    <th scope="col" :class="[headClass, 'text-right']">Closing</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in summary.rows" :key="row.id ?? 'own'">
                    <td :class="cellClass">
                        <button
                            v-if="row.id"
                            type="button"
                            class="cursor-pointer text-left font-medium text-primary hover:underline"
                            @click="emit('select', row.id)"
                        >
                            {{ row.name }}
                        </button>
                        <span v-else class="text-text-muted">{{ row.name }}</span>
                        <span v-if="row.code" class="text-text-muted"> · {{ row.code }}</span>
                    </td>
                    <td :class="numClass">{{ balanceLabel(row.openingBalance, row.openingSide) }}</td>
                    <td :class="numClass">{{ amountOrBlank(row.debit) }}</td>
                    <td :class="numClass">{{ amountOrBlank(row.credit) }}</td>
                    <td :class="[numClass, 'font-bold text-text-strong']">{{ balanceLabel(row.closingBalance, row.closingSide) }}</td>
                </tr>
            </tbody>
            <tfoot v-if="summary.total">
                <tr>
                    <td :class="totalClass">Total</td>
                    <td :class="[totalClass, 'text-right whitespace-nowrap [font-variant-numeric:tabular-nums]']">
                        {{ balanceLabel(summary.total.openingBalance, summary.total.openingSide) }}
                    </td>
                    <td :class="[totalClass, 'text-right whitespace-nowrap [font-variant-numeric:tabular-nums]']">{{ formatMoney(summary.total.debit) }}</td>
                    <td :class="[totalClass, 'text-right whitespace-nowrap [font-variant-numeric:tabular-nums]']">{{ formatMoney(summary.total.credit) }}</td>
                    <td :class="[totalClass, 'text-right whitespace-nowrap [font-variant-numeric:tabular-nums]']">
                        {{ balanceLabel(summary.total.closingBalance, summary.total.closingSide) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
