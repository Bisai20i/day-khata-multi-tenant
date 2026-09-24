<script setup>
import { computed, nextTick, ref } from 'vue';
import { Plus } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { formatQuantity } from '@/lib/money';
import { emptyLine, toggleDiscountTypeOn, unitOptionsFor } from '@/lib/saleCreate';

// --- Add item: staging panel -----------------------------------------------
// One row of entry fields, separate from the lines already committed to the
// bill (the redesign's step 2/step 3 split). selectItem/selectUnit/applyMrp
// are the generic line actions from useSaleCreateItems(), so the staging line
// reuses them exactly as the committed rows do.
const props = defineProps({
    itemOptions: { type: Array, default: () => [] },
    itemsById: { type: Object, default: () => ({}) },
    showLineExtras: { type: Boolean, default: false },
    selectItem: { type: Function, required: true },
    selectUnit: { type: Function, required: true },
    applyMrp: { type: Function, required: true },
});

const emit = defineEmits(['add']);

const stagingLine = ref(emptyLine());
const stagingLineEl = ref(null);
const stagingItemEl = ref(null);
const stagingQuantityEl = ref(null);

const stagingItem = computed(() => props.itemsById[stagingLine.value.item_id] ?? null);

function focusStagingField(el) {
    nextTick(() => el.value?.querySelector('input')?.focus());
}

function selectStagingItem(itemId) {
    props.selectItem(stagingLine.value, itemId);
    focusStagingField(stagingQuantityEl);
}

function selectStagingUnit(unitId) {
    props.selectUnit(stagingLine.value, unitId);
}

function applyStagingMrp(mrp) {
    props.applyMrp(stagingLine.value, mrp);
}

function toggleStagingDiscountType() {
    toggleDiscountTypeOn(stagingLine.value);
}

/** Item + quantity is enough to commit a line; everything else can stay blank. */
const canAddStagingLine = computed(() => !!stagingLine.value.item_id && stagingLine.value.quantity !== '');

function addStagingLine() {
    if (!canAddStagingLine.value) {
        focusStagingField(stagingLine.value.item_id ? stagingQuantityEl : stagingItemEl);
        return;
    }

    emit('add', { ...stagingLine.value });
    stagingLine.value = emptyLine();
    focusStagingField(stagingItemEl);
}

// --- Enter key: advance, never submit --------------------------------------
// Legacy adds the next line on Enter; here Enter submitted the whole bill from
// the Qty field (audit P1 "Workflow"). On the staging panel, Enter walks
// Qty -> Rate -> Discount and then commits the line (addStagingLine()) -
// mirrors legacy's "Enter adds to basket" and the barcode-scan flow in Pos.vue.
const STAGING_FIELDS = ['quantity', 'rate', 'discount'];

function onStagingEnter(field) {
    const position = STAGING_FIELDS.indexOf(field);

    if (position < STAGING_FIELDS.length - 1) {
        stagingLineEl.value?.querySelector(`[data-staging-field="${STAGING_FIELDS[position + 1]}"] input`)?.focus();
        return;
    }

    addStagingLine();
}
</script>

<template>
    <div>
        <div ref="stagingLineEl" class="grid grid-cols-[2.2fr_1fr_0.8fr_1fr_1.2fr] items-end gap-3">
            <div ref="stagingItemEl">
                <label class="mb-1 block text-sm font-semibold text-text-base">Item or scan barcode</label>
                <Combobox
                    :model-value="stagingLine.item_id"
                    :options="itemOptions"
                    placeholder="Search or scan barcode"
                    @update:model-value="selectStagingItem"
                />
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Unit</label>
                <Select
                    v-if="stagingItem?.units?.length"
                    :model-value="stagingLine.item_unit_id"
                    :options="unitOptionsFor(stagingItem)"
                    @update:model-value="selectStagingUnit"
                />
                <span v-else class="block h-9 pt-2 text-xs text-text-muted">{{ stagingItem?.unit ?? '-' }}</span>
            </div>
            <div ref="stagingQuantityEl" data-staging-field="quantity">
                <label class="mb-1 block text-sm font-semibold text-text-base">Quantity</label>
                <Input
                    v-model="stagingLine.quantity"
                    type="number"
                    step="0.0001"
                    placeholder="0"
                    @keydown.enter.prevent="onStagingEnter('quantity')"
                />
            </div>
            <div data-staging-field="rate">
                <label class="mb-1 block text-sm font-semibold text-text-base">Rate</label>
                <Input
                    v-model="stagingLine.rate"
                    type="number"
                    min="0"
                    step="0.0001"
                    placeholder="0.00"
                    @keydown.enter.prevent="onStagingEnter('rate')"
                />
            </div>
            <div class="flex items-end gap-2">
                <div data-staging-field="discount" class="flex-1">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Discount</label>
                    <Input
                        v-model="stagingLine.discount"
                        type="number"
                        min="0"
                        step="0.01"
                        :max="stagingLine.discount_type === 'percentage' ? 100 : undefined"
                        :placeholder="stagingLine.discount_type === 'percentage' ? '%' : 'Rs'"
                        @keydown.enter.prevent="onStagingEnter('discount')"
                    >
                        <template #addon>
                            <button
                                type="button"
                                class="flex h-9 w-9 shrink-0 items-center justify-center text-[10px] font-bold text-text-muted hover:text-primary"
                                title="Click to switch between % and Rs discount"
                                aria-label="Discount type: switch between percent and rupees"
                                @click="toggleStagingDiscountType"
                            >
                                {{ stagingLine.discount_type === 'percentage' ? '%' : 'Rs' }}
                            </button>
                        </template>
                    </Input>
                </div>
                <Button variant="primary" tone="purple" type="button" :disabled="!canAddStagingLine" @click="addStagingLine">
                    <Plus class="h-3.5 w-3.5" /> Add item
                </Button>
            </div>
        </div>

        <Transition name="extras">
            <div v-if="showLineExtras" class="mt-3 grid grid-cols-2 gap-3 sm:[grid-template-columns:2.2fr_1fr_0.8fr_1fr_1.2fr]">
                <div class="sm:col-start-3">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bonus</label>
                    <Input
                        v-model="stagingLine.bonus_quantity"
                        type="number"
                        min="0"
                        step="0.0001"
                        placeholder="0"
                        title="Free units given with this line - moves stock, never billed"
                    />
                </div>
                <div class="sm:col-start-4">
                    <label class="mb-1 block text-sm font-semibold text-text-base">MRP</label>
                    <Input
                        :model-value="stagingLine.mrp"
                        type="number"
                        min="0"
                        step="0.0001"
                        placeholder="Incl. VAT"
                        title="VAT-inclusive price: fills Rate with MRP / (1 + VAT%)"
                        @update:model-value="applyStagingMrp"
                    />
                </div>
            </div>
        </Transition>

        <!-- Stock now shows directly beside the item name in the search
             dropdown (see itemOptions' `meta`), so this only needs to
             surface the unit conversion once a non-base unit is picked. -->
        <div v-if="stagingLine.item_unit_id" class="mt-3 flex items-center gap-2">
            <span class="text-xs text-text-muted">
                (1 {{ stagingItem.units.find((u) => u.id === stagingLine.item_unit_id)?.name }} =
                {{ formatQuantity(stagingItem.units.find((u) => u.id === stagingLine.item_unit_id)?.conversion_factor ?? 1) }}
                {{ stagingItem.unit }})
            </span>
        </div>
    </div>
</template>

<style scoped>
/* Bonus/MRP staging row pops in/out via v-if; without this it would snap
   instantly and read as a layout jump rather than an intentional toggle. */
.extras-enter-active,
.extras-leave-active {
    transition:
        opacity 150ms ease,
        transform 150ms ease;
}
.extras-enter-from,
.extras-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>
