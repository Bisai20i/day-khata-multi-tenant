<script setup>
import { computed } from 'vue';
import Input from '@/components/ui/Input.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney, parseMoney } from '@/lib/money';
import { suggestRoundOff } from '@/lib/roundOff';

/**
 * The signed Round Off box shared by the sale and purchase create screens:
 * + adds to the bill, - reduces it. Always manual; the chip only offers the
 * amount that takes the bill to the nearest multiple of the tenant's
 * Round Off step, and applies it on click.
 *
 * `totalBeforeRoundOff` is the bill total with Round Off taken back out (see
 * totalBeforeRoundOff() in @/lib/roundOff), or null while the bill cannot be
 * totalled yet, which hides the suggestion.
 */
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    totalBeforeRoundOff: { type: String, default: null },
    precision: { type: String, default: '1' },
    error: { type: String, default: '' },
    id: { type: String, default: 'round_off' },
});

const emit = defineEmits(['update:modelValue']);

const suggestion = computed(() =>
    props.totalBeforeRoundOff === null ? null : suggestRoundOff(props.totalBeforeRoundOff, props.precision),
);

// Already applied: nothing left to offer, and the chip would only repeat the box.
const showSuggestion = computed(() => {
    if (suggestion.value === null) return false;

    const entered = parseMoney(props.modelValue === '' ? '0' : props.modelValue);

    return !entered.ok || entered.value !== suggestion.value;
});

function signed(value) {
    return (value[0] === '-' ? '' : '+') + formatMoney(value);
}
</script>

<template>
    <div class="flex flex-col">
        <div class="mb-1 flex items-center gap-1">
            <Label :for="id">Round off</Label>
            <InfoTip text="A small + or - amount that rounds the bill total. + adds to the bill, - reduces it." />
        </div>
        <Input
            :id="id"
            :model-value="modelValue"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            placeholder="0.00"
            @update:model-value="(v) => emit('update:modelValue', v)"
        />
        <button
            v-if="showSuggestion"
            type="button"
            class="mt-1 self-start text-xs font-semibold text-primary hover:underline"
            :title="`Round the bill to the nearest ${precision}`"
            @click="emit('update:modelValue', suggestion)"
        >
            Suggested {{ signed(suggestion) }} &middot; Apply
        </button>
        <p v-if="error" class="mt-1 text-sm text-danger">{{ error }}</p>
    </div>
</template>
