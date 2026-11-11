<script setup>
import { computed } from 'vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';

const props = defineProps({
    // Allowed precisions as raw strings, e.g. ['10', '1', '0.5', '0.1', '0.05'].
    precisions: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});

const enabled = defineModel('enabled', { type: Boolean, default: false });
const precision = defineModel('precision', { type: String, default: '1' });

// Pure string formatting (no arithmetic): '0.5' -> 'Rs 0.50', '10' -> 'Rs 10'.
function labelFor(value) {
    const [whole, fraction] = String(value).split('.');
    return fraction === undefined ? `Rs ${whole}` : `Rs ${whole}.${fraction.padEnd(2, '0')}`;
}

const precisionOptions = computed(() => props.precisions.map((value) => ({ value, label: labelFor(value) })));
</script>

<template>
    <div class="border-t border-border pt-4">
        <p class="mb-3 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Round off</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <div class="flex items-center gap-2">
                    <input id="round_off_enabled" v-model="enabled" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    <label for="round_off_enabled" class="text-sm font-semibold text-text-base">Enable round off on sales and purchase bills</label>
                </div>
                <p class="mt-1 text-xs text-text-muted">Adds a signed + / - Round Off field to the Sales and Purchase bills, with a suggested amount.</p>
                <p v-if="errors.round_off_enabled" class="mt-1 text-sm text-danger">{{ errors.round_off_enabled }}</p>
            </div>

            <div v-if="enabled">
                <Label class="mb-1">Round to nearest</Label>
                <Select v-model="precision" :options="precisionOptions" />
                <p v-if="errors.round_off_precision" class="mt-1 text-sm text-danger">{{ errors.round_off_precision }}</p>
            </div>
        </div>
    </div>
</template>
