<script>
import {
    createPaginatedRowModel,
    createSortedRowModel,
    rowPaginationFeature,
    rowSortingFeature,
    tableFeatures,
} from '@tanstack/vue-table';

const dataTableFeatures = tableFeatures({
    rowSortingFeature,
    rowPaginationFeature,
    sortedRowModel: createSortedRowModel(),
    paginatedRowModel: createPaginatedRowModel(),
});
</script>

<script setup>
import { computed, watch } from 'vue';
import { FlexRender, useTable } from '@tanstack/vue-table';
import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    ChevronsUpDown,
    Inbox,
} from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuTrigger,
} from 'reka-ui';
import { cn } from '@/lib/utils';
import Tooltip from '@/components/ui/Tooltip.vue';

const props = defineProps({
    columns: { type: Array, required: true },
    data: { type: Array, default: () => [] },
    pageSize: { type: Number, default: 10 },
    emptyMessage: { type: String, default: 'No records found' },
    class: { type: [String, Array, Object], default: '' },
});

const pageSizeOptions = computed(() => {
    const base = [10, 25, 50, 100];
    return base.includes(props.pageSize) ? base : [props.pageSize, ...base].sort((a, b) => a - b);
});

const hasRows = computed(() => props.data.length > 0);

function accessorValue(row, columnDef) {
    if (!row) return undefined;
    if (typeof columnDef.accessorFn === 'function') return columnDef.accessorFn(row, 0);
    if (columnDef.accessorKey) return row[columnDef.accessorKey];
    return undefined;
}

const numericColumnIds = computed(() => {
    const map = new Map();
    for (const def of props.columns) {
        const id = def.id ?? def.accessorKey;
        const flag = def.numeric ?? def.meta?.numeric;
        if (typeof flag === 'boolean') {
            map.set(id, flag);
            continue;
        }
        const sample = props.data.find((row) => {
            const value = accessorValue(row, def);
            return value !== undefined && value !== null;
        });
        map.set(id, typeof accessorValue(sample, def) === 'number');
    }
    return map;
});

function isNumeric(columnId) {
    return numericColumnIds.value.get(columnId) === true;
}

const table = useTable({
    features: dataTableFeatures,
    columns: props.columns,
    data: computed(() => props.data),
    initialState: {
        pagination: { pageIndex: 0, pageSize: props.pageSize },
    },
});

watch(
    () => props.pageSize,
    (size) => table.setPageSize(size),
);

function sortIndicator(column) {
    if (!column.getCanSort()) return null;
    const direction = column.getIsSorted();
    if (direction === 'asc') return ChevronUp;
    if (direction === 'desc') return ChevronDown;
    return ChevronsUpDown;
}

function ariaSort(column) {
    const direction = column.getIsSorted();
    if (direction === 'asc') return 'ascending';
    if (direction === 'desc') return 'descending';
    return column.getCanSort() ? 'none' : undefined;
}

function onHeaderKeydown(event, column) {
    if (!column.getCanSort()) return;
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    column.getToggleSortingHandler()?.(event);
}
</script>

<template>
    <div :class="cn('w-full', props.class)">
        <div
            v-if="!hasRows"
            class="flex flex-col items-center justify-center gap-2 border-[1.5px] border-border bg-white px-6 py-16 text-center"
        >
            <slot name="empty">
                <Inbox class="h-8 w-8 text-text-faint" aria-hidden="true" />
                <p class="text-[13px] font-semibold text-text-base">{{ emptyMessage }}</p>
            </slot>
        </div>

        <template v-else>
            <div class="w-full overflow-x-auto">
                <table class="w-full border-separate [border-spacing:0_4px] text-[12.5px] text-text-base">
                    <thead>
                        <tr v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
                            <th
                                v-for="header in headerGroup.headers"
                                :key="header.id"
                                scope="col"
                                :aria-sort="ariaSort(header.column)"
                                :tabindex="header.column.getCanSort() ? 0 : undefined"
                                :class="cn(
                                    'border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase',
                                    header.column.getCanSort() ? 'cursor-pointer select-none outline-none focus-visible:text-primary' : '',
                                    isNumeric(header.column.id) ? 'text-right' : 'text-left',
                                )"
                                @click="header.column.getToggleSortingHandler()?.($event)"
                                @keydown="onHeaderKeydown($event, header.column)"
                            >
                                <div
                                    class="flex items-center gap-1"
                                    :class="isNumeric(header.column.id) ? 'justify-end' : 'justify-start'"
                                >
                                    <FlexRender v-if="!header.isPlaceholder" :header="header" />
                                    <component
                                        :is="sortIndicator(header.column)"
                                        v-if="sortIndicator(header.column)"
                                        class="h-3 w-3 shrink-0"
                                        :class="header.column.getIsSorted() ? 'text-primary' : 'text-text-faint'"
                                    />
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in table.getRowModel().rows" :key="row.id" class="group">
                            <td
                                v-for="cell in row.getAllCells()"
                                :key="cell.id"
                                :class="cn(
                                    'border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle transition-colors duration-150 ease-out first:border-l-[1.5px] last:border-r-[1.5px] group-hover:border-[#C4B5FD] group-hover:shadow-[0_4px_16px_rgba(102,0,255,.15)]',
                                    isNumeric(cell.column.id) ? 'text-right [font-variant-numeric:tabular-nums]' : 'text-left',
                                )"
                            >
                                <FlexRender :cell="cell" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-text-muted">
                    Page {{ table.atoms.pagination.get().pageIndex + 1 }} of {{ table.getPageCount() }}
                </p>
                <div class="flex items-center gap-3">
                    <DropdownMenuRoot>
                        <DropdownMenuTrigger
                            class="inline-flex items-center gap-1 border-[1.5px] border-border bg-white px-2 py-1 text-xs font-semibold text-text-muted outline-none transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                        >
                            {{ table.atoms.pagination.get().pageSize }} / page
                            <ChevronDown class="h-3 w-3" />
                        </DropdownMenuTrigger>
                        <DropdownMenuPortal>
                            <DropdownMenuContent
                                :side-offset="4"
                                align="end"
                                class="z-50 min-w-[7rem] border-[1.5px] border-border bg-white p-1 shadow-[0_8px_24px_rgba(0,0,0,.12)]"
                            >
                                <DropdownMenuItem
                                    v-for="size in pageSizeOptions"
                                    :key="size"
                                    class="cursor-pointer px-2 py-1.5 text-xs font-medium text-text-base outline-none select-none data-[highlighted]:bg-primary-tint data-[highlighted]:text-primary"
                                    @select="table.setPageSize(size)"
                                >
                                    {{ size }} / page
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenuPortal>
                    </DropdownMenuRoot>
                    <div class="flex items-center gap-1">
                        <Tooltip label="Previous page">
                            <button
                                type="button"
                                class="inline-flex h-7 w-7 items-center justify-center border-[1.5px] border-border bg-white text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-border disabled:hover:text-text-muted"
                                :disabled="!table.getCanPreviousPage()"
                                aria-label="Previous page"
                                @click="table.previousPage()"
                            >
                                <ChevronLeft class="h-3.5 w-3.5" />
                            </button>
                        </Tooltip>
                        <Tooltip label="Next page">
                            <button
                                type="button"
                                class="inline-flex h-7 w-7 items-center justify-center border-[1.5px] border-border bg-white text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-border disabled:hover:text-text-muted"
                                :disabled="!table.getCanNextPage()"
                                aria-label="Next page"
                                @click="table.nextPage()"
                            >
                                <ChevronRight class="h-3.5 w-3.5" />
                            </button>
                        </Tooltip>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
