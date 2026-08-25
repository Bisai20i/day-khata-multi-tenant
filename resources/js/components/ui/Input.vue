<script setup>
import { cn } from '@/lib/utils';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    icon: { type: [Object, Function], default: null },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['update:modelValue']);

function onInput(event) {
    emit('update:modelValue', event.target.value);
}
</script>

<template>
    <div class="relative flex w-full items-center">
        <component
            :is="icon"
            v-if="icon"
            class="pointer-events-none absolute left-[9px] h-3.5 w-3.5 text-text-faint"
        />
        <input
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :class="cn(
                'w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none placeholder:text-text-faint',
                'focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]',
                'disabled:cursor-not-allowed disabled:opacity-50',
                icon ? 'pl-[28px]' : '',
                props.class,
            )"
            @input="onInput"
        />
    </div>
</template>
