<script setup>
import { computed, nextTick, ref } from 'vue';
import { Plus } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { formatQuantity } from '@/lib/money';
import { toggleDiscountTypeOn, unitOptionsFor } from '@/lib/saleCreate';
import { emptyPurchaseLine } from '@/lib/purchaseCreate';

// --- Add item: staging panel -----------------------------------------------
// Same step 2/step 3 split as SaleCreateStagingRow: one row of entry fields,
// separate from the lines already on the bill. selectItem/selectUnit are the
// generic line actions from Purchases/Create.vue, so the staging line reuses
// them exactly as the committed rows do.
const props = defineProps({
    itemOptions: { type: Array, default: () => [] },
    itemsById: { type: Map, required: true },
    showLineExtras: { type: Boolean, default: false },
    selectItem: { type: Function, required: true },
    selectUnit: { type: Function, required: true },
});

const emit = defineEmits(['add']);

const stagingLine = ref(emptyPurchaseLine());
const stagingLineEl = ref(null);
const stagingItemEl = ref(null);
const stagingQuantityEl = ref(null);

const stagingItem = computed(() => props.itemsById.get(stagingLine.value.item_id) ?? null);

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
    stagingLine.value = emptyPurchaseLine();
    focusStagingField(stagingItemEl);
}

/**
 * Puts an item (and optionally one of its alternate units) into the staging
 * row and moves the cursor to Quantity - used by the barcode scan and by the
 * quick "+ New item" modal once the new item comes back.
 */
function stage(itemId, unitId = '') {
    props.selectItem(stagingLine.value, itemId);
    if (unitId !== '' && unitId !== null) {
        props.selectUnit(stagingLine.value, unitId);
    }
    focusStagingField(stagingQuantityEl);
}

// --- Scan-to-add barcode (item 9) --------------------------------------
// Calls ItemController::lookupBarcode() (GET /items/lookup-barcode), which
// checks item_units.barcode first (a specific alternate unit) then falls
// back to items.barcode (the base unit, item_unit_id: null). Only items
// already loaded into this page are addable - a match the browser has never
// seen (e.g. an inactive item) is reported rather than silently skipped.
const barcodeCode = ref('');
const barcodeError = ref(null);
const barcodeScanning = ref(false);

async function scanBarcode() {
    const code = barcodeCode.value.trim();
    if (code === '') {
        return;
    }

    barcodeError.value = null;
    barcodeScanning.value = true;

    try {
        const response = await fetch(`/items/lookup-barcode?code=${encodeURIComponent(code)}`, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();

        if (!response.ok) {
            barcodeError.value = body.message ?? 'No item matches that barcode.';
            return;
        }

        const matchedItem = props.itemsById.get(body.item.id);
        if (!matchedItem) {
            barcodeError.value = `"${body.item.name}" is not available on this purchase (it may be inactive).`;
            return;
        }

        stage(matchedItem.id, body.item_unit_id ?? '');
        if (stagingLine.value.quantity === '') {
            stagingLine.value.quantity = '1';
        }
    } catch {
        barcodeError.value = 'Could not reach the server. Try again.';
    } finally {
        barcodeScanning.value = false;
        barcodeCode.value = '';
    }
}

// --- Enter key: advance, never submit --------------------------------------
// Same as SaleCreateStagingRow: Enter walks Qty -> Rate -> Discount and then
// commits the line, instead of submitting the whole bill from the Qty field.
const STAGING_FIELDS = ['quantity', 'rate', 'discount'];

function onStagingEnter(field) {
    const position = STAGING_FIELDS.indexOf(field);

    if (position < STAGING_FIELDS.length - 1) {
        stagingLineEl.value?.querySelector(`[data-staging-field="${STAGING_FIELDS[position + 1]}"] input`)?.focus();
        return;
    }

    addStagingLine();
}

defineExpose({ stage });
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap items-end gap-2">
            <div class="w-full sm:w-72">
                <label for="purchase-barcode" class="mb-1 block text-xs font-semibold text-text-muted">Scan barcode (incl. box / pack barcodes)</label>
                <Input
                    id="purchase-barcode"
                    v-model="barcodeCode"
                    type="text"
                    placeholder="Scan, then press Enter"
                    :disabled="barcodeScanning"
                    @keydown.enter.prevent="scanBarcode"
                />
            </div>
            <p v-if="barcodeError" class="pb-2 text-sm text-danger" role="alert">{{ barcodeError }}</p>
        </div>

        <div ref="stagingLineEl" class="grid grid-cols-[2.2fr_1fr_0.8fr_1fr_1.2fr] items-end gap-3">
            <div ref="stagingItemEl">
                <label class="mb-1 block text-sm font-semibold text-text-base">Item</label>
                <Combobox
                    :model-value="stagingLine.item_id"
                    :options="itemOptions"
                    placeholder="Search item"
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
                    min="0"
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
            <div v-if="showLineExtras" class="mt-3 grid grid-cols-2 gap-3 sm:[grid-template-columns:2.2fr_1fr_0.8fr_2.2fr]">
                <div class="sm:col-start-3">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Free qty</label>
                    <Input
                        v-model="stagingLine.bonus_quantity"
                        type="number"
                        min="0"
                        step="0.0001"
                        placeholder="0"
                        title="Bonus units received at no charge - stocked, never billed"
                    />
                </div>
                <div class="sm:col-start-4">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Note</label>
                    <Input v-model="stagingLine.note" type="text" placeholder="Note for this item (optional)" />
                </div>
            </div>
        </Transition>

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
/* Free qty / note staging row pops in/out via v-if; without this it would
   snap instantly and read as a layout jump rather than an intentional toggle. */
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
