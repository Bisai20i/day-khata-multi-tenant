<script setup>
import { ref } from 'vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';

// --- Barcode label printing ---------------------------------------------
// A small "how many labels" prompt before opening the print sheet in a new
// tab - mirrors how the Sale/Purchase Index pages open their print PDF via
// a plain link, except this one needs a quantity first, so a tiny modal
// stands in for the plain anchor. See BarcodeLabelController's docblock for
// why an item with no barcode still gets a (barcode-less) label rather than
// being blocked entirely.
const barcodeModalOpen = ref(false);
const barcodeItem = ref(null);
const barcodeQuantity = ref(1);

function openBarcodeModal(item) {
    barcodeItem.value = item;
    barcodeQuantity.value = 1;
    barcodeModalOpen.value = true;
}

function closeBarcodeModal() {
    barcodeModalOpen.value = false;
    barcodeItem.value = null;
}

function onBarcodeModalOpenChange(value) {
    if (!value) closeBarcodeModal();
}

function printBarcodeLabels() {
    if (!barcodeItem.value) return;

    const params = new URLSearchParams();
    params.set('items[0][item_id]', barcodeItem.value.id);
    params.set('items[0][quantity]', String(barcodeQuantity.value));

    window.open(`/items/barcode-labels/print?${params.toString()}`, '_blank', 'noopener');
    closeBarcodeModal();
}

defineExpose({ openBarcodeModal });
</script>

<template>
        <Modal
            :open="barcodeModalOpen"
            :title="barcodeItem ? `Print Barcode - ${barcodeItem.name}` : 'Print Barcode'"
            size="compact"
            @update:open="onBarcodeModalOpenChange"
        >
            <div v-if="barcodeItem" class="flex flex-col gap-4">
                <p v-if="barcodeItem.barcode" class="text-[13px] text-text-muted">
                    Prints a sheet of CODE-128 labels for <strong>{{ barcodeItem.name }}</strong>
                    (barcode <strong>{{ barcodeItem.barcode }}</strong>).
                </p>
                <p v-else class="text-[13px] text-text-muted">
                    <strong>{{ barcodeItem.name }}</strong> has no barcode set - the printed label will show its
                    name/price only, without a scannable code. Set a barcode on this item first if you need one.
                </p>

                <div>
                    <label for="barcode_quantity" class="mb-1 block text-sm font-semibold text-text-base">Quantity</label>
                    <Input id="barcode_quantity" v-model.number="barcodeQuantity" type="number" min="1" max="500" class="max-w-[160px]" />
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeBarcodeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="button" @click="printBarcodeLabels">Print</Button>
            </template>
        </Modal>
</template>
