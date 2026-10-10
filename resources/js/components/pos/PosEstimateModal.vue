<script setup>
import { ref } from 'vue';
import { Printer } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';

/**
 * Estimate preview: the cart (or the sales entry form) rendered by the server
 * as the bill it would become (see useSaleEstimate), shown as the PDF itself
 * so what the cashier sees is what prints. `url` is an object URL of that PDF.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    url: { type: String, default: '' },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const frame = ref(null);

function printEstimate() {
    try {
        frame.value.contentWindow.focus();
        frame.value.contentWindow.print();
    } catch {
        // Some browsers will not script their built-in PDF viewer; a tab of
        // its own still offers the viewer's print button.
        window.open(props.url, '_blank');
    }
}
</script>

<template>
    <Modal
        :open="open"
        title="Estimate"
        description="A preview of the bill for this cart. Nothing is saved and no invoice number is issued."
        size="xl"
        @update:open="(v) => (v ? null : emit('close'))"
    >
        <!-- Sized from the Modal's own max height (100dvh - 1.5rem, 85vh from sm)
             less its header, footer and body padding, so the modal body never
             scrolls and the PDF viewer's scrollbar is the only one. -->
        <div class="h-[calc(100dvh-15rem)] overflow-hidden border-[1.5px] border-border bg-bg-subtle sm:h-[calc(85vh-13rem)]">
            <iframe v-if="url" ref="frame" :src="url" title="Estimate preview" class="block h-full w-full" />
            <div v-else class="flex h-full items-center justify-center text-sm text-text-muted">
                {{ loading ? 'Preparing estimate…' : 'No estimate to show.' }}
            </div>
        </div>
        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="emit('close')">Close</Button>
            <Button variant="primary" tone="purple" type="button" :disabled="!url" @click="printEstimate">
                <Printer class="h-3.5 w-3.5" /> Print
            </Button>
        </template>
    </Modal>
</template>
