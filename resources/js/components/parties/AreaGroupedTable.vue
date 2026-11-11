<script setup>
import { formatMoney } from '@/lib/money';

/**
 * The "Group by area" layout of a party report: one heading per area, that
 * area's party rows, then a subtotal row.
 *
 * Takes the same column definitions the page gives its flat DataTable
 * ({ id, header, numeric, cell }), so a cell is written once and renders the
 * same in both layouts. `subtotalColumns` lists the column ids that carry an
 * amount; the server computed each group's `totals` with exact decimals, so
 * they add up to the report total to the paisa.
 *
 * @typedef {{ area: string, rows: object[], totals: Record<string, string> }} AreaGroup
 */
const props = defineProps({
    columns: { type: Array, required: true },
    groups: { type: Array, default: () => [] },
    subtotalColumns: { type: Array, default: () => [] },
    emptyMessage: { type: String, default: 'No records found' },
});

// A DataTable cell renders to a string or a vnode; this passes either through.
const CellValue = (cellProps) => cellProps.node;

function cellNode(column, row) {
    return column.cell ? column.cell({ row: { original: row } }) : row[column.accessorKey];
}

function isNumeric(column) {
    return column.numeric === true;
}
</script>

<template>
    <div class="w-full overflow-x-auto">
        <p v-if="groups.length === 0" class="py-10 text-center text-[13px] font-semibold text-text-base">{{ emptyMessage }}</p>

        <table v-else class="w-full border-collapse text-[12.5px] text-text-base">
            <thead>
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.id"
                        scope="col"
                        class="px-[9px] py-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase"
                        :class="isNumeric(column) ? 'text-right' : 'text-left'"
                    >
                        {{ column.header }}
                    </th>
                </tr>
            </thead>

            <tbody v-for="(group, groupIndex) in groups" :key="groupIndex">
                <tr>
                    <th
                        :colspan="columns.length"
                        scope="colgroup"
                        class="border-y-[1.5px] border-border bg-primary-tint px-[9px] py-1.5 text-left text-[12px] font-bold text-primary"
                    >
                        {{ group.area }}
                        <span class="ml-1 font-semibold text-text-muted">({{ group.rows.length }})</span>
                    </th>
                </tr>
                <tr v-for="(row, index) in group.rows" :key="index" class="border-b border-border">
                    <td
                        v-for="column in columns"
                        :key="column.id"
                        class="bg-white px-[9px] py-2 align-middle"
                        :class="isNumeric(column) ? 'text-right [font-variant-numeric:tabular-nums]' : 'text-left'"
                    >
                        <CellValue :node="cellNode(column, row)" />
                    </td>
                </tr>
                <tr class="border-y-[1.5px] border-border bg-bg-subtle font-bold text-text-strong">
                    <td
                        v-for="(column, index) in columns"
                        :key="column.id"
                        class="px-[9px] py-2"
                        :class="isNumeric(column) ? 'text-right [font-variant-numeric:tabular-nums]' : 'text-left'"
                    >
                        <template v-if="index === 0">Subtotal - {{ group.area }}</template>
                        <template v-else-if="subtotalColumns.includes(column.id)">{{ formatMoney(group.totals[column.id] ?? '0.00') }}</template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
