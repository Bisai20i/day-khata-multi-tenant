<script setup>
import { computed, ref } from 'vue';
import { X } from '@lucide/vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { unitOptionsFor } from '@/lib/saleCreate';

// The lines already committed to the bill - same layout as
// SaleCreateLinesTable. `lines` and `errors` are the parent form's own,
// edited in place; selectItem/selectUnit are Purchases/Create.vue's line
// actions.
const props = defineProps({
    lines: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    itemOptions: { type: Array, default: () => [] },
    itemsById: { type: Map, required: true },
    showLineExtras: { type: Boolean, default: false },
    vatRate: { type: String, default: '0' },
    forceNonTaxable: { type: Boolean, default: false },
    totals: { type: Object, default: null },
    selectItem: { type: Function, required: true },
    selectUnit: { type: Function, required: true },
});

defineEmits(['remove', 'toggle-discount-type']);

const lineGridColumns = computed(() =>
    props.showLineExtras ? '1fr 80px 90px 80px 100px 120px 96px 28px' : '1fr 80px 90px 100px 120px 96px 28px',
);

/** A line's own total, or null while the bill can't be previewed yet. */
function lineTotal(index) {
    return props.totals ? props.totals.lines[index].line_total : null;
}

/** VAT badge text: a PAN bill makes every line exempt, whatever the item says. */
function vatBadge(item) {
    return item?.is_vatable && !props.forceNonTaxable ? `VAT ${props.vatRate || 0}%` : 'Non taxable';
}

// --- Enter key: advance, never submit --------------------------------------
const LINE_FIELDS = ['quantity', 'rate', 'discount'];
const linesEl = ref(null);

function onLineEnter(index, field) {
    const position = LINE_FIELDS.indexOf(field);
    if (position < LINE_FIELDS.length - 1) {
        const target = linesEl.value?.querySelector(`[data-line-field="${LINE_FIELDS[position + 1]}-${index}"] input`);
        target?.focus();
        target?.select?.();
    }
}
</script>

<template>
    <div>
        <div class="my-4 border-t border-border" />

        <div class="mb-3 flex items-center gap-1.5">
            <span class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">On this bill</span>
            <span class="bg-primary-tint px-2 py-0.5 text-[11px] font-bold text-primary">{{ lines.length }}</span>
        </div>

        <p v-if="lines.length === 0" class="py-6 text-center text-sm text-text-faint">
            No items yet. Search or scan an item above, enter quantity and rate, then press "Add item" (or Enter) to put it on the bill.
        </p>

        <div v-else ref="linesEl">
            <div
                class="mb-2 grid gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase transition-[grid-template-columns] duration-150"
                :style="{ gridTemplateColumns: lineGridColumns }"
            >
                <span>Item</span>
                <span>Unit</span>
                <span class="text-right">Qty</span>
                <span v-if="showLineExtras" title="Bonus quantity received at no charge">Free qty</span>
                <span class="text-right">Rate</span>
                <span class="text-right">Discount</span>
                <span class="text-right">Amount</span>
                <span class="sr-only">Remove</span>
            </div>

            <div
                v-for="(line, index) in lines"
                :key="index"
                class="mb-2 grid items-start gap-2 transition-[grid-template-columns] duration-150"
                :style="{ gridTemplateColumns: lineGridColumns }"
            >
                <div>
                    <Combobox
                        :model-value="line.item_id"
                        :options="itemOptions"
                        placeholder="Select item"
                        @update:model-value="(v) => selectItem(line, v)"
                    />
                    <p v-if="itemsById.get(line.item_id)" class="mt-1 flex flex-wrap items-center gap-1">
                        <span
                            class="inline-flex px-1.5 py-0.5 text-[10px] font-bold"
                            :class="itemsById.get(line.item_id)?.is_vatable && !forceNonTaxable ? 'bg-[#D9EDF7] text-[#245269]' : 'bg-[#EEEEEE] text-[#555555]'"
                        >
                            {{ vatBadge(itemsById.get(line.item_id)) }}
                        </span>
                        <span v-if="line.item_unit_id" class="text-xs text-text-muted">
                            1 {{ itemsById.get(line.item_id).units.find((u) => u.id === line.item_unit_id)?.name }} =
                            {{ formatQuantity(itemsById.get(line.item_id).units.find((u) => u.id === line.item_unit_id)?.conversion_factor ?? 1) }}
                            {{ itemsById.get(line.item_id).unit }}
                        </span>
                    </p>
                    <Input
                        v-if="showLineExtras"
                        v-model="line.note"
                        type="text"
                        placeholder="Note for this item (optional)"
                        aria-label="Note for this item"
                        class="mt-1 text-xs"
                    />
                    <p v-if="errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">{{ errors[`lines.${index}.item_id`] }}</p>
                    <p v-if="errors[`lines.${index}.note`]" class="mt-1 text-xs text-danger">{{ errors[`lines.${index}.note`] }}</p>
                </div>
                <div>
                    <Select
                        v-if="itemsById.get(line.item_id)?.units?.length"
                        :model-value="line.item_unit_id"
                        :options="unitOptionsFor(itemsById.get(line.item_id))"
                        @update:model-value="(v) => selectUnit(line, v)"
                    />
                    <span v-else class="block pt-2 text-xs text-text-muted">{{ itemsById.get(line.item_id)?.unit ?? '-' }}</span>
                </div>
                <div :data-line-field="`quantity-${index}`">
                    <Input
                        v-model="line.quantity"
                        class="text-right"
                        type="number"
                        min="0"
                        step="0.0001"
                        placeholder="0"
                        required
                        @keydown.enter.prevent="onLineEnter(index, 'quantity')"
                    />
                    <p v-if="errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">{{ errors[`lines.${index}.quantity`] }}</p>
                </div>
                <div v-if="showLineExtras">
                    <Input
                        v-model="line.bonus_quantity"
                        type="number"
                        min="0"
                        step="0.0001"
                        placeholder="0"
                        title="Bonus units received at no charge - stocked, never billed"
                    />
                    <p v-if="errors[`lines.${index}.bonus_quantity`]" class="mt-1 text-xs text-danger">{{ errors[`lines.${index}.bonus_quantity`] }}</p>
                </div>
                <div :data-line-field="`rate-${index}`">
                    <Input
                        v-model="line.rate"
                        class="text-right"
                        type="number"
                        min="0"
                        step="0.0001"
                        placeholder="0.00"
                        required
                        @keydown.enter.prevent="onLineEnter(index, 'rate')"
                    />
                    <p v-if="errors[`lines.${index}.rate`]" class="mt-1 text-xs text-danger">{{ errors[`lines.${index}.rate`] }}</p>
                </div>
                <div :data-line-field="`discount-${index}`">
                    <Input
                        v-model="line.discount"
                        class="text-right"
                        type="number"
                        min="0"
                        step="0.01"
                        :max="line.discount_type === 'percentage' ? 100 : undefined"
                        :placeholder="line.discount_type === 'percentage' ? '%' : 'Rs'"
                        @keydown.enter.prevent="onLineEnter(index, 'discount')"
                    >
                        <template #addon>
                            <button
                                type="button"
                                class="flex h-9 w-9 shrink-0 items-center justify-center text-[10px] font-bold text-text-muted hover:text-primary"
                                title="Click to switch between % and Rs discount"
                                :aria-label="`Discount type for line ${index + 1}: switch between percent and rupees`"
                                @click="$emit('toggle-discount-type', index)"
                            >
                                {{ line.discount_type === 'percentage' ? '%' : 'Rs' }}
                            </button>
                        </template>
                    </Input>
                </div>
                <span class="block pt-2 text-right text-[13px] font-semibold text-text-strong">
                    {{ lineTotal(index) === null ? '-' : formatMoney(lineTotal(index)) }}
                </span>
                <button
                    type="button"
                    class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                    :aria-label="`Remove ${itemsById.get(line.item_id)?.name ?? 'item'} (line ${index + 1}) from bill`"
                    :title="`Remove line ${index + 1}`"
                    @click="$emit('remove', index)"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    </div>
</template>
