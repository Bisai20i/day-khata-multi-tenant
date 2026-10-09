<script setup>
import InfoTip from '@/components/ui/InfoTip.vue';

/**
 * A checkbox with its label and a one-line hint saying what ticking it does.
 * With `hintAsTooltip` the hint sits behind an info icon right of the label.
 */
defineProps({
    modelValue: { type: Boolean, default: false },
    id: { type: String, required: true },
    label: { type: String, required: true },
    hint: { type: String, default: '' },
    hintAsTooltip: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex min-w-0 items-start gap-2.5">
        <input
            :id="id"
            type="checkbox"
            :checked="modelValue"
            :aria-describedby="hint ? `${id}-hint` : undefined"
            class="mt-0.5 size-4 shrink-0 border-[1.5px] border-border"
            @change="emit('update:modelValue', $event.target.checked)"
        />
        <div class="min-w-0">
            <div class="flex items-center gap-1">
                <label :for="id" class="block text-[13px] font-semibold text-text-base">{{ label }}</label>
                <InfoTip v-if="hint && hintAsTooltip" :text="hint" />
            </div>
            <p v-if="hint" :id="`${id}-hint`" :class="hintAsTooltip ? 'sr-only' : 'mt-0.5 text-xs leading-relaxed text-text-muted'">{{ hint }}</p>
        </div>
    </div>
</template>
