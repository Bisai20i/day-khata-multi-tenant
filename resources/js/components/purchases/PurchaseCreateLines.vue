<script setup>
import { Plus, X } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { formatMoney } from '@/lib/money';

const props = defineProps({
    form: { type: Object, required: true },
    itemOptions: { type: Array, default: () => [] },
    itemsById: { type: Map, required: true },
    totals: { type: Object, default: null },
    barcodeCode: { type: String, default: '' },
    barcodeError: { type: String, default: null },
    barcodeScanning: { type: Boolean, default: false },
});

defineEmits([
    'update:barcodeCode',
    'scan',
    'new-item',
    'add-line',
    'remove-line',
    'select-item',
    'select-unit',
    'toggle-discount-type',
]);

// Mirrors Sales/Create.vue's unitOptionsFor() exactly, adapted for this
// file's itemsById being a Map rather than a plain object.
function unitOptionsFor(item) {
    if (!item) return [];

    return [{ value: '', label: item.unit }, ...(item.units ?? []).map((u) => ({ value: u.id, label: u.name }))];
}

function lineTotalText(index) {
    return props.totals ? formatMoney(props.totals.lines[index].line_total) : '-';
}
</script>

<template>
    <div>
        <div class="mb-3 flex items-end gap-2">
            <div class="flex-1">
                <label class="mb-1 block text-sm font-semibold text-text-base">Scan barcode</label>
                <Input
                    :model-value="barcodeCode"
                    type="text"
                    placeholder="Scan or paste a barcode, then press Enter"
                    @update:model-value="(v) => $emit('update:barcodeCode', v)"
                    @keydown.enter.prevent="$emit('scan')"
                />
            </div>
            <Button variant="secondary" tone="purple" type="button" :disabled="barcodeScanning" @click="$emit('scan')">
                Add
            </Button>
            <Button variant="secondary" tone="purple" type="button" @click="$emit('new-item')">
                <Plus class="h-3.5 w-3.5" /> New item
            </Button>
        </div>
        <p v-if="barcodeError" class="mb-2 text-sm text-danger">{{ barcodeError }}</p>

        <div class="mb-2 grid grid-cols-[1fr_90px_100px_80px_100px_90px_40px_90px_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
            <span>Item</span>
            <span>Unit</span>
            <span>Quantity</span>
            <span title="Bonus quantity received at no charge">Free qty</span>
            <span>Rate (Rs.)</span>
            <span>Discount</span>
            <span title="Discount type">Type</span>
            <span class="text-right">Line total</span>
            <span class="sr-only">Remove</span>
        </div>

        <div v-for="(line, index) in form.lines" :key="index" class="mb-2 border-b border-border pb-2 last:border-b-0">
            <div class="grid grid-cols-[1fr_90px_100px_80px_100px_90px_40px_90px_28px] items-start gap-2">
                <div>
                    <Combobox
                        :model-value="line.item_id"
                        :options="itemOptions"
                        placeholder="Select item"
                        @update:model-value="(v) => $emit('select-item', line, v)"
                    />
                    <p v-if="form.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                        {{ form.errors[`lines.${index}.item_id`] }}
                    </p>
                </div>
                <div>
                    <Select
                        v-if="itemsById.get(line.item_id)?.units?.length"
                        :model-value="line.item_unit_id"
                        :options="unitOptionsFor(itemsById.get(line.item_id))"
                        @update:model-value="(v) => $emit('select-unit', line, v)"
                    />
                    <span v-else class="block pt-2 text-xs text-text-muted">{{ itemsById.get(line.item_id)?.unit ?? '-' }}</span>
                </div>
                <Input v-model="line.quantity" type="number" min="0" step="0.0001" placeholder="0" required />
                <Input v-model="line.bonus_quantity" type="number" min="0" step="0.0001" placeholder="0" />
                <Input v-model="line.rate" type="number" min="0" step="0.0001" placeholder="0.0000" required />
                <Input
                    v-model="line.discount"
                    type="number"
                    min="0"
                    :max="line.discount_type === 'percentage' ? 100 : undefined"
                    :placeholder="line.discount_type === 'percentage' ? '%' : 'Rs'"
                />
                <button
                    type="button"
                    class="flex h-9 w-full items-center justify-center border-[1.5px] border-border bg-bg-subtle text-[10px] font-bold text-text-muted hover:border-primary hover:text-primary"
                    title="Click to switch between % and Rs discount"
                    @click="$emit('toggle-discount-type', index)"
                >
                    {{ line.discount_type === 'percentage' ? '%' : 'Rs' }}
                </button>
                <span class="pt-2 text-right text-sm font-semibold text-text-strong">{{ lineTotalText(index) }}</span>
                <button
                    v-if="form.lines.length > 1"
                    type="button"
                    class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                    :aria-label="`Remove item row ${index + 1}`"
                    :title="`Remove item row ${index + 1}`"
                    @click="$emit('remove-line', index)"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>
            <div class="mt-1">
                <Input v-model="line.note" type="text" placeholder="Note for this item (optional)" aria-label="Note for this item" class="text-xs" />
                <p v-if="form.errors[`lines.${index}.note`]" class="mt-1 text-xs text-danger">
                    {{ form.errors[`lines.${index}.note`] }}
                </p>
            </div>
        </div>

        <Button variant="secondary" tone="purple" type="button" class="mt-1" @click="$emit('add-line')">
            <Plus class="h-3.5 w-3.5" aria-hidden="true" /> Add another item
        </Button>
    </div>
</template>
