<script setup>
import { computed } from 'vue';
import Card from '@/components/ui/Card.vue';
import { compareMoney, formatMoney, sumMoney } from '@/lib/money.js';

/**
 * Read-only payslip table of a posted or cancelled payroll run: one row per
 * staff member, one column per pay component used anywhere in the run, then
 * gross, advance recovered and net. A posted run also shows what has been
 * paid against each payslip and what is still outstanding (Salary Payable).
 *
 * Every figure is a server decimal string; the column and footer totals are
 * summed with lib/money.js, never as JS numbers.
 */
const props = defineProps({
    lines: { type: Array, default: () => [] },
    // Paid/outstanding only mean something once the run is posted.
    showPayments: { type: Boolean, default: false },
});

/** Components in first-seen order across the run, so columns are stable. */
const componentColumns = computed(() => {
    const columns = new Map();
    props.lines.forEach((line) => {
        (line.items ?? []).forEach((item) => {
            if (!columns.has(item.pay_component_id)) {
                columns.set(item.pay_component_id, { id: item.pay_component_id, name: item.component_name });
            }
        });
    });

    return [...columns.values()];
});

/** Per line: component id -> amount (a component appears once per line). */
const amountsByLine = computed(
    () => new Map(props.lines.map((line) => [line.id, new Map((line.items ?? []).map((item) => [item.pay_component_id, item.amount]))])),
);

function amountFor(line, componentId) {
    return amountsByLine.value.get(line.id)?.get(componentId) ?? null;
}

const componentTotals = computed(
    () =>
        new Map(
            componentColumns.value.map((column) => [
                column.id,
                sumMoney(props.lines.map((line) => amountFor(line, column.id) ?? '0.00')),
            ]),
        ),
);

const totals = computed(() => ({
    gross: sumMoney(props.lines.map((line) => line.gross)),
    recovery: sumMoney(props.lines.map((line) => line.advance_recovery)),
    net: sumMoney(props.lines.map((line) => line.net)),
    paid: sumMoney(props.lines.map((line) => line.paid ?? '0.00')),
    outstanding: sumMoney(props.lines.map((line) => line.outstanding ?? '0.00')),
}));

function isPositive(value) {
    return compareMoney(value ?? '0.00', '0.00') > 0;
}

/** Items carrying a note, listed under the row so nothing entered is hidden. */
function itemNotes(line) {
    return (line.items ?? []).filter((item) => item.note).map((item) => `${item.component_name}: ${item.note}`);
}
</script>

<template>
    <Card variant="panel" title="Payslips" class="!p-4 overflow-x-auto">
        <p v-if="lines.length === 0" class="py-6 text-center text-sm text-text-faint">This run has no payslips.</p>
        <table v-else class="w-full text-sm" :class="showPayments ? 'min-w-[860px]' : 'min-w-[640px]'">
            <thead>
                <tr class="text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <th class="py-2 pr-3">Staff</th>
                    <th v-for="column in componentColumns" :key="column.id" class="px-2 py-2 text-right">{{ column.name }}</th>
                    <th class="px-2 py-2 text-right">Gross</th>
                    <th class="px-2 py-2 text-right">Advance recovered</th>
                    <th class="px-2 py-2 text-right">Net</th>
                    <th v-if="showPayments" class="px-2 py-2 text-right">Paid</th>
                    <th v-if="showPayments" class="py-2 pl-2 text-right">Outstanding</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="line in lines" :key="line.id" class="border-t border-border align-top">
                    <td class="py-2 pr-3">
                        <div class="font-semibold text-text-strong">{{ line.staff_name }}</div>
                        <div class="text-xs text-text-muted">{{ line.staff_code }}</div>
                        <div v-if="line.note" class="mt-0.5 text-xs text-text-muted">{{ line.note }}</div>
                        <div v-for="note in itemNotes(line)" :key="note" class="mt-0.5 text-xs text-text-faint">{{ note }}</div>
                    </td>
                    <td v-for="column in componentColumns" :key="column.id" class="px-2 py-2 text-right">
                        <template v-if="amountFor(line, column.id) !== null">{{ formatMoney(amountFor(line, column.id)) }}</template>
                        <span v-else class="text-text-faint">-</span>
                    </td>
                    <td class="px-2 py-2 text-right font-semibold">{{ formatMoney(line.gross) }}</td>
                    <td class="px-2 py-2 text-right">{{ formatMoney(line.advance_recovery) }}</td>
                    <td class="px-2 py-2 text-right font-semibold text-text-strong">{{ formatMoney(line.net) }}</td>
                    <td v-if="showPayments" class="px-2 py-2 text-right">{{ formatMoney(line.paid ?? '0.00') }}</td>
                    <td v-if="showPayments" class="py-2 pl-2 text-right" :class="isPositive(line.outstanding) ? 'font-semibold text-warning-text' : 'text-text-faint'">
                        {{ formatMoney(line.outstanding ?? '0.00') }}
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-border font-bold text-text-strong">
                    <td class="py-2 pr-3">Total ({{ lines.length }})</td>
                    <td v-for="column in componentColumns" :key="column.id" class="px-2 py-2 text-right">{{ formatMoney(componentTotals.get(column.id)) }}</td>
                    <td class="px-2 py-2 text-right">{{ formatMoney(totals.gross) }}</td>
                    <td class="px-2 py-2 text-right">{{ formatMoney(totals.recovery) }}</td>
                    <td class="px-2 py-2 text-right">{{ formatMoney(totals.net) }}</td>
                    <td v-if="showPayments" class="px-2 py-2 text-right">{{ formatMoney(totals.paid) }}</td>
                    <td v-if="showPayments" class="py-2 pl-2 text-right">{{ formatMoney(totals.outstanding) }}</td>
                </tr>
            </tfoot>
        </table>
    </Card>
</template>
