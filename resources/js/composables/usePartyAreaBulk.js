import { computed, h, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';

/**
 * Row selection plus the "assign selected to an area" action for the
 * Customers and Suppliers lists.
 *
 * Selection lives here, not in the table: the DataTable pages client-side, so
 * "select all" must mean every row the current filter shows, across all pages,
 * not just the ten on screen. `selectionColumn()` is the checkbox column to
 * put first in the table's column list.
 *
 * @param {object} options
 * @param {string} options.url POST endpoint, e.g. '/customers/assign-area'
 * @param {string} options.noun singular name for messages, e.g. 'customer'
 * @param {import('vue').Ref<Array<{id: number, name: string}>>} options.rows the rows the table currently shows (after any filter)
 * @param {import('vue').Ref<Array<{id: number, name: string}>>} options.areas every area, to name the target in the confirmation
 * @param {(row: object) => boolean} [options.isSelectable] rows that can be ticked (the walk-in customer cannot)
 */
export function usePartyAreaBulk({ url, noun, rows, areas, isSelectable = () => true }) {
    const { confirm } = useConfirm();
    const { toast } = useToast();

    const selectedIds = ref([]);
    const targetAreaId = ref(null);
    const applying = ref(false);

    const selectedSet = computed(() => new Set(selectedIds.value));
    const selectableRows = computed(() => rows.value.filter(isSelectable));
    const allSelected = computed(
        () => selectableRows.value.length > 0 && selectableRows.value.every((row) => selectedSet.value.has(row.id)),
    );
    const someSelected = computed(() => selectedIds.value.length > 0 && !allSelected.value);

    // A ticked row that is deleted, or no longer in the list after a reload, must not stay counted.
    watch(rows, (current) => {
        const present = new Set(current.map((row) => row.id));
        const kept = selectedIds.value.filter((id) => present.has(id));

        if (kept.length !== selectedIds.value.length) selectedIds.value = kept;
    });

    function toggle(id) {
        selectedIds.value = selectedSet.value.has(id)
            ? selectedIds.value.filter((selected) => selected !== id)
            : [...selectedIds.value, id];
    }

    function toggleAll() {
        selectedIds.value = allSelected.value ? [] : selectableRows.value.map((row) => row.id);
    }

    function clear() {
        selectedIds.value = [];
    }

    function plural(count) {
        return count === 1 ? noun : `${noun}s`;
    }

    /**
     * @param {number|null} [areaId] the area to assign; null clears it. Defaults to the bar's choice.
     */
    async function apply(areaId = targetAreaId.value) {
        const count = selectedIds.value.length;
        if (count === 0 || applying.value) return;

        const target = areas.value.find((area) => area.id === areaId);
        const message = target
            ? `Assign ${count} ${plural(count)} to "${target.name}"?`
            : `Clear the area of ${count} ${plural(count)}?`;

        if (!(await confirm({ message, confirmLabel: target ? 'Assign area' : 'Clear area' }))) return;

        router.post(
            url,
            { ids: selectedIds.value, area_id: areaId },
            {
                preserveScroll: true,
                onStart: () => {
                    applying.value = true;
                },
                onFinish: () => {
                    applying.value = false;
                },
                onSuccess: () => {
                    clear();
                    targetAreaId.value = null;
                },
                onError: (errors) => toast({
                    message: errors.ids ?? errors.area_id ?? Object.values(errors)[0] ?? `Could not update the ${plural(2)}.`,
                    variant: 'danger',
                }),
            },
        );
    }

    /**
     * The leading checkbox column for the page's DataTable.
     */
    function selectionColumn() {
        return {
            id: 'select',
            numeric: false,
            enableSorting: false,
            header: () => h('input', {
                type: 'checkbox',
                class: 'size-4 border-[1.5px] border-border',
                'aria-label': `Select all ${plural(2)}`,
                checked: allSelected.value,
                indeterminate: someSelected.value,
                onChange: toggleAll,
            }),
            cell: ({ row }) => isSelectable(row.original)
                ? h('input', {
                    type: 'checkbox',
                    class: 'size-4 border-[1.5px] border-border',
                    'aria-label': `Select ${row.original.name}`,
                    checked: selectedSet.value.has(row.original.id),
                    onChange: () => toggle(row.original.id),
                })
                : null,
        };
    }

    return { selectedIds, targetAreaId, applying, allSelected, toggleAll, clear, apply, selectionColumn };
}
