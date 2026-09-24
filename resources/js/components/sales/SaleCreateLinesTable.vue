<script setup>
import { computed, ref } from 'vue';
import { X } from '@lucide/vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { stockStatus, toggleDiscountTypeOn, unitOptionsFor } from '@/lib/saleCreate';

// The lines already committed to the bill. selectItem/selectUnit/applyMrp are
// the generic line actions from useSaleCreateItems(); `lines` and `errors`
// are the parent form's own, edited in place through v-model.
const props = defineProps({
    lines: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    itemOptions: { type: Array, default: () => [] },
    itemsById: { type: Object, default: () => ({}) },
    showLineExtras: { type: Boolean, default: false },
    effectiveVatRate: { type: String, default: '0' },
    totals: { type: Object, default: null },
    selectItem: { type: Function, required: true },
    selectUnit: { type: Function, required: true },
    applyMrp: { type: Function, required: true },
});

defineEmits(['remove']);

const lineGridColumns = computed(() =>
    props.showLineExtras
        ? '1fr 80px 90px 80px 90px 90px 120px 96px 28px'
        : '1fr 80px 90px 90px 120px 96px 28px',
);

/** A line's own total, or null while the line is still incomplete. */
function lineTotal(index) {
    return props.totals ? props.totals.lines[index].line_total : null;
}

function lineDiscountAmount(index) {
    return props.totals ? props.totals.lines[index].discount_amount : null;
}

function toggleLineDiscountType(index) {
    toggleDiscountTypeOn(props.lines[index], lineDiscountAmount(index));
}

// --- Enter key: advance, never submit --------------------------------------
// On an already-added row, Enter just walks Qty -> Rate -> Discount; there's
// no line left to auto-create at the end since new items only ever enter
// through the staging panel.
const LINE_FIELDS = ['quantity', 'rate', 'discount'];
const linesEl = ref(null);

function focusLineField(index, field) {
    const target = linesEl.value?.querySelector(`[data-line-field="${field}-${index}"] input`);
    target?.focus();
    target?.select?.();
}

function onLineEnter(index, field) {
    const position = LINE_FIELDS.indexOf(field);
    if (position < LINE_FIELDS.length - 1) {
        focusLineField(index, LINE_FIELDS[position + 1]);
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
                <template v-if="showLineExtras">
                    <span>Bonus (free)</span>
                    <span>MRP (incl. VAT)</span>
                </template>
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
                    <!-- Stock is always quoted in the item's own base unit
                         with that unit named, plus the conversion factor
                         when the line is entered in an alternate unit -
                         the cashier can see both numbers instead of a
                         base-unit figure silently labelled as Boxes. A
                         coloured badge (not just text) flags low/out of
                         stock, alongside the line's own VAT status. -->
                    <p v-if="itemsById[line.item_id]?.current_stock != null" class="mt-1 flex flex-wrap items-center gap-1">
                        <span
                            v-if="stockStatus(itemsById[line.item_id])"
                            class="inline-flex px-1.5 py-0.5 text-[10px] font-bold"
                            :class="stockStatus(itemsById[line.item_id]) === 'out' ? 'bg-danger-bg text-danger' : 'bg-[#FEF9C3] text-[#92400E]'"
                        >
                            {{ stockStatus(itemsById[line.item_id]) === 'out' ? 'Out of stock' : 'Low stock' }}
                        </span>
                        <span
                            class="inline-flex px-1.5 py-0.5 text-[10px] font-bold"
                            :class="itemsById[line.item_id]?.is_vatable ? 'bg-[#D9EDF7] text-[#245269]' : 'bg-[#EEEEEE] text-[#555555]'"
                        >
                            {{ itemsById[line.item_id]?.is_vatable ? `VAT ${effectiveVatRate}%` : 'Non taxable' }}
                        </span>
                        <span class="text-xs text-text-muted">
                            Stock: {{ formatQuantity(itemsById[line.item_id].current_stock) }} {{ itemsById[line.item_id].unit }}
                            <template v-if="line.item_unit_id">
                                (1 {{ itemsById[line.item_id].units.find((u) => u.id === line.item_unit_id)?.name }} =
                                {{ formatQuantity(itemsById[line.item_id].units.find((u) => u.id === line.item_unit_id)?.conversion_factor ?? 1) }}
                                {{ itemsById[line.item_id].unit }})
                            </template>
                        </span>
                    </p>
                    <p v-if="errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                        {{ errors[`lines.${index}.item_id`] }}
                    </p>
                </div>
                <div>
                    <Select
                        v-if="itemsById[line.item_id]?.units?.length"
                        :model-value="line.item_unit_id"
                        :options="unitOptionsFor(itemsById[line.item_id])"
                        @update:model-value="(v) => selectUnit(line, v)"
                    />
                    <span v-else class="block pt-2 text-xs text-text-muted">{{ itemsById[line.item_id]?.unit ?? '-' }}</span>
                </div>
                <!-- No min="0": a negative quantity is a valid in-bill
                     return/adjustment line (see SaleController::store()'s
                     validation comment). -->
                <div :data-line-field="`quantity-${index}`">
                    <Input
                        v-model="line.quantity"
                        class="text-right"
                        type="number"
                        step="0.0001"
                        placeholder="0"
                        required
                        @keydown.enter.prevent="onLineEnter(index, 'quantity')"
                    />
                    <p v-if="errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                        {{ errors[`lines.${index}.quantity`] }}
                    </p>
                </div>
                <!-- Free / bonus units handed over with the line: they
                     move stock but are never priced, so the preview and
                     the bill total ignore them entirely (audit section 3
                     "Sales"). -->
                <div v-if="showLineExtras">
                    <Input
                        v-model="line.bonus_quantity"
                        type="number"
                        min="0"
                        step="0.0001"
                        placeholder="0"
                        title="Free units given with this line - moves stock, never billed"
                    />
                    <p v-if="errors[`lines.${index}.bonus_quantity`]" class="mt-1 text-xs text-danger">
                        {{ errors[`lines.${index}.bonus_quantity`] }}
                    </p>
                </div>
                <!-- MRP / VAT-inclusive entry: typing the sticker price
                     fills Rate to the right with MRP / 1.13 for a vatable
                     line (applyLineMrp()). Browser-only - the server is
                     sent the rate, never the MRP. -->
                <div v-if="showLineExtras">
                    <!-- Explicit :model-value + @update:model-value rather
                         than v-model: the rate has to be recalculated from
                         the value the cashier just typed, and a plain
                         @input listener would fire before v-model had
                         written it back. -->
                    <Input
                        :model-value="line.mrp"
                        type="number"
                        min="0"
                        step="0.0001"
                        placeholder="Incl. VAT"
                        title="VAT-inclusive price: fills Rate with MRP / (1 + VAT%)"
                        @update:model-value="(v) => applyMrp(line, v)"
                    />
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
                    <p v-if="errors[`lines.${index}.rate`]" class="mt-1 text-xs text-danger">
                        {{ errors[`lines.${index}.rate`] }}
                    </p>
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
                                @click="toggleLineDiscountType(index)"
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
                    :aria-label="`Remove ${itemsById[line.item_id]?.name ?? 'item'} (line ${index + 1}) from bill`"
                    :title="`Remove line ${index + 1}`"
                    @click="$emit('remove', index)"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    </div>
</template>
