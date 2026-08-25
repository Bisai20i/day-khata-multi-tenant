<script setup>
import { computed } from 'vue';
import { Check, ChevronDown } from '@lucide/vue';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
} from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Search…' },
    disabled: { type: Boolean, default: false },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['update:modelValue']);

const modelValue = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

function displayValue(value) {
    return props.options.find((option) => option.value === value)?.label ?? '';
}
</script>

<template>
    <ComboboxRoot v-model="modelValue" :disabled="disabled" class="relative w-full">
        <ComboboxAnchor
            :class="cn(
                'flex w-full items-center justify-between border-[1.5px] border-border bg-bg-subtle px-3 py-2 transition-colors duration-150',
                'has-[input:focus]:border-primary has-[input:focus]:bg-white has-[input:focus]:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]',
                disabled ? 'cursor-not-allowed opacity-50' : '',
                props.class,
            )"
        >
            <ComboboxInput
                :display-value="displayValue"
                :placeholder="placeholder"
                :disabled="disabled"
                class="w-full bg-transparent text-[13px] text-text-base outline-none placeholder:text-text-faint disabled:cursor-not-allowed"
            />
            <ComboboxTrigger>
                <ChevronDown class="h-3.5 w-3.5 shrink-0 text-text-muted" />
            </ComboboxTrigger>
        </ComboboxAnchor>
        <ComboboxPortal>
            <ComboboxContent
                class="z-50 min-w-[var(--reka-combobox-trigger-width)] border-[1.5px] border-border bg-white shadow-[0_8px_24px_rgba(0,0,0,.12)]"
                position="popper"
                :side-offset="4"
            >
                <ComboboxViewport class="max-h-60 overflow-y-auto p-1">
                    <ComboboxEmpty class="px-2.5 py-2 text-[13px] text-text-faint">
                        No results found.
                    </ComboboxEmpty>
                    <ComboboxItem
                        v-for="option in options"
                        :key="option.value"
                        :value="option.value"
                        :text-value="option.label"
                        class="relative flex cursor-pointer select-none items-center justify-between px-2.5 py-2 text-[13px] text-text-base outline-none data-[highlighted]:bg-primary-tint data-[highlighted]:text-primary"
                    >
                        {{ option.label }}
                        <ComboboxItemIndicator>
                            <Check class="h-3.5 w-3.5 text-primary" />
                        </ComboboxItemIndicator>
                    </ComboboxItem>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>
</template>
