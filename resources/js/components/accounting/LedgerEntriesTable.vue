<script setup>
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { compareMoney, formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

/**
 * The shared ledger statement table: Opening row, one row per posted line
 * (with its Particulars, the other side of the voucher), Total row and Closing row. Used by the per-account ledger and the Reports
 * ledger so both read identically (and match the PDF / Excel exports).
 *
 * All amounts arrive as exact decimal strings and are only formatted here;
 * balances are shown as an absolute amount plus a Dr / Cr side.
 */
const props = defineProps({
    entries: { type: Array, default: () => [] },
    openingBalance: { type: String, default: '0.00' },
    openingSide: { type: String, default: '' },
    closingBalance: { type: String, default: '0.00' },
    closingSide: { type: String, default: '' },
    periodDebit: { type: String, default: '0.00' },
    periodCredit: { type: String, default: '0.00' },
    from: { type: [String, null], default: null },
    emptyMessage: { type: String, default: 'No ledger entries found for the selected account and date range.' },
});

const PAGE_SIZE = 100;

// The Particulars column is only shown when the server sent the key (it is
// left out for viewers who may not see the other side's account names).
// An empty ledger keeps the full header.
const hasParticulars = computed(() => props.entries.length === 0 || props.entries.some((entry) => 'particulars' in entry));
const columnCount = computed(() => (hasParticulars.value ? 10 : 9));

// Same tokens as components/ui/DataTable.vue so the ledger reads like every
// other list: spaced "card" rows, 1.5px borders, 10px uppercase headers.
const headClass = 'px-[9px] py-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase whitespace-nowrap';
const cellClass = 'border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle transition-colors duration-150 ease-out first:border-l-[1.5px] last:border-r-[1.5px] group-hover:border-[#C4B5FD] group-hover:shadow-[0_2px_6px_rgba(102,0,255,.07)]';
const numClass = `${cellClass} text-right whitespace-nowrap [font-variant-numeric:tabular-nums]`;
const summaryCellClass = 'border-y-[1.5px] border-border bg-primary-tint/30 px-[9px] py-2 align-middle text-xs font-bold text-text-strong first:border-l-[1.5px] last:border-r-[1.5px]';
const pagerButtonClass = 'inline-flex h-7 w-7 items-center justify-center border-[1.5px] border-border bg-white text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-border disabled:hover:text-text-muted';

// Only the types a manual ledger reader is likely to meet need a friendly
// label; anything else falls back to its own value, so a new VoucherType never
// renders as a blank cell.
const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
    reversal: 'Reversal',
    sale: 'Sale',
    sale_abbreviated: 'Sale (Abbreviated)',
    sale_pan: 'Sale (PAN)',
    sale_return: 'Sales Return',
    purchase: 'Purchase',
    purchase_return: 'Purchase Return',
    capital_purchase: 'Capital Purchase',
    capital_sale: 'Capital Sale',
    fixed_asset_purchase: 'Fixed Asset Purchase',
    depreciation: 'Depreciation',
    asset_disposal: 'Asset Disposal',
    receipt: 'Receipt',
    payment: 'Payment',
    cash_receipt: 'Cash Receipt',
    cash_payment: 'Cash Payment',
    bank_receipt: 'Bank Receipt',
    bank_payment: 'Bank Payment',
    contra: 'Contra',
    material_issue: 'Material Issue',
    production: 'Production',
    production_entry: 'Production Entry',
    inventory_cost: 'Inventory Cost',
    payroll: 'Payroll',
    salary_payment: 'Salary Payment',
    staff_advance: 'Staff Advance',
    branch_stock_transfer: 'Branch Stock Transfer',
};

function voucherLabel(entry) {
    const type = voucherTypeLabels[entry.voucherType] ?? entry.voucherType;
    return `${type} #${entry.voucherNumber}`;
}

function bsDate(entry) {
    return entry.dateBs || formatBsDate(entry.date) || '-';
}

/** A zero (or missing) debit / credit prints as an empty cell. */
function amountOrBlank(value) {
    if (value === null || value === undefined || value === '' || compareMoney(value, '0.00') === 0) {
        return '';
    }

    return formatMoney(value);
}

/** Absolute amount: formatted with the sign stripped, so "-0.00" can never appear. */
function absoluteMoney(value) {
    const text = formatMoney(value ?? '0.00');

    return text.startsWith('-') ? text.slice(1) : text;
}

/** "1,250.00 Dr" / "1,250.00 Cr"; zero prints "0.00" with no side. */
function balanceLabel(value, side) {
    const amount = absoluteMoney(value);
    const isZero = compareMoney(value ?? '0.00', '0.00') === 0;

    return isZero || !side ? amount : `${amount} ${side}`;
}

const page = ref(1);
const totalPages = computed(() => Math.max(1, Math.ceil(props.entries.length / PAGE_SIZE)));
const pageStart = computed(() => (page.value - 1) * PAGE_SIZE);
const pageEntries = computed(() => props.entries.slice(pageStart.value, pageStart.value + PAGE_SIZE));

// A new result set (filters changed) always starts on its first page.
watch(
    () => props.entries,
    () => {
        page.value = 1;
    },
);

function previousPage() {
    page.value = Math.max(1, page.value - 1);
}

function nextPage() {
    page.value = Math.min(totalPages.value, page.value + 1);
}
</script>

<template>
    <div>
        <div class="w-full overflow-x-auto">
            <table class="w-full border-separate [border-spacing:0_4px] text-[12.5px] text-text-base">
                <thead>
                    <tr>
                        <th scope="col" :class="[headClass, 'text-left']">Date (BS)</th>
                        <th scope="col" :class="[headClass, 'text-left']">Date (AD)</th>
                        <th scope="col" :class="[headClass, 'text-left']">Voucher</th>
                        <th scope="col" :class="[headClass, 'text-left']">Reference</th>
                        <th v-if="hasParticulars" scope="col" :class="[headClass, 'text-left']">Particulars</th>
                        <th scope="col" :class="[headClass, 'text-left']">Narration</th>
                        <th scope="col" :class="[headClass, 'text-left']">Main narration</th>
                        <th scope="col" :class="[headClass, 'text-right']">Debit</th>
                        <th scope="col" :class="[headClass, 'text-right']">Credit</th>
                        <th scope="col" :class="[headClass, 'text-right']">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="page === 1">
                        <td :colspan="columnCount - 1" :class="summaryCellClass">
                            Opening balance
                            <span v-if="from" class="font-normal text-text-muted">(before {{ formatBsDate(from) }} / {{ from }})</span>
                        </td>
                        <td :class="[summaryCellClass, 'text-right whitespace-nowrap [font-variant-numeric:tabular-nums]']">
                            {{ balanceLabel(openingBalance, openingSide) }}
                        </td>
                    </tr>
                    <tr v-if="entries.length === 0">
                        <td :colspan="columnCount" class="border-[1.5px] border-border bg-white px-6 py-10 text-center text-[13px] font-semibold text-text-base">
                            {{ emptyMessage }}
                        </td>
                    </tr>
                    <tr
                        v-for="(entry, offset) in pageEntries"
                        :key="pageStart + offset"
                        class="group"
                    >
                        <td :class="[cellClass, 'whitespace-nowrap font-medium text-text-strong']">{{ bsDate(entry) }}</td>
                        <td :class="[cellClass, 'whitespace-nowrap text-text-muted']">{{ entry.date }}</td>
                        <td :class="[cellClass, 'whitespace-nowrap']">{{ voucherLabel(entry) }}</td>
                        <td :class="[cellClass, 'whitespace-nowrap font-medium']">
                            <a
                                v-if="entry.document?.url"
                                :href="entry.document.url"
                                target="_blank"
                                rel="noopener"
                                class="text-primary underline"
                                :title="entry.reference ? entry.document.label : undefined"
                            >{{ entry.reference || entry.document.label }}</a>
                            <template v-else>{{ entry.reference || entry.document?.label || '-' }}</template>
                        </td>
                        <td v-if="hasParticulars" :class="cellClass">{{ entry.particulars || '-' }}</td>
                        <td :class="cellClass">{{ entry.narration || '-' }}</td>
                        <td :class="cellClass">{{ entry.mainNarration || '-' }}</td>
                        <td :class="numClass">{{ amountOrBlank(entry.debit) }}</td>
                        <td :class="numClass">{{ amountOrBlank(entry.credit) }}</td>
                        <td :class="[numClass, 'font-bold text-text-strong']">{{ balanceLabel(entry.balance, entry.side) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td :colspan="columnCount - 3" :class="summaryCellClass">Total</td>
                        <td :class="[summaryCellClass, 'text-right whitespace-nowrap [font-variant-numeric:tabular-nums]']">{{ formatMoney(periodDebit) }}</td>
                        <td :class="[summaryCellClass, 'text-right whitespace-nowrap [font-variant-numeric:tabular-nums]']">{{ formatMoney(periodCredit) }}</td>
                        <td :class="summaryCellClass"></td>
                    </tr>
                    <tr>
                        <td :colspan="columnCount - 1" :class="summaryCellClass">Closing balance</td>
                        <td :class="[summaryCellClass, 'text-right whitespace-nowrap [font-variant-numeric:tabular-nums]']">
                            {{ balanceLabel(closingBalance, closingSide) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div v-if="totalPages > 1" class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-text-muted">
                Showing {{ pageStart + 1 }}-{{ pageStart + pageEntries.length }} of {{ entries.length }} entries
                &middot; Page {{ page }} of {{ totalPages }}
            </p>
            <div class="flex items-center gap-1">
                <Tooltip label="Previous page">
                    <button type="button" :class="pagerButtonClass" :disabled="page <= 1" aria-label="Previous page" @click="previousPage">
                        <ChevronLeft class="h-3.5 w-3.5" />
                    </button>
                </Tooltip>
                <Tooltip label="Next page">
                    <button type="button" :class="pagerButtonClass" :disabled="page >= totalPages" aria-label="Next page" @click="nextPage">
                        <ChevronRight class="h-3.5 w-3.5" />
                    </button>
                </Tooltip>
            </div>
        </div>
    </div>
</template>
