<script setup>
import { computed } from 'vue';
import { Check, ChevronDown } from '@lucide/vue';
import {
    SelectContent,
    SelectIcon,
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectValue,
    SelectViewport,
} from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Select…' },
    disabled: { type: Boolean, default: false },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['update:modelValue']);

const modelValue = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});
</script>

<template>
    <SelectRoot v-model="modelValue" :disabled="disabled">
        <SelectTrigger
            :class="cn(
                'flex w-full items-center justify-between border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none transition-colors duration-150',
                'data-[state=open]:border-primary data-[state=open]:bg-white data-[state=open]:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]',
                'disabled:cursor-not-allowed disabled:opacity-50',
                props.class,
            )"
        >
            <SelectValue class="truncate text-left" :placeholder="placeholder" />
            <SelectIcon as-child>
                <ChevronDown class="h-3.5 w-3.5 shrink-0 text-text-muted" />
            </SelectIcon>
        </SelectTrigger>
        <SelectPortal>
            <SelectContent
                class="z-50 min-w-[var(--reka-select-trigger-width)] border-[1.5px] border-border bg-white shadow-[0_8px_24px_rgba(0,0,0,.12)]"
                position="popper"
                :side-offset="4"
            >
                <SelectViewport class="p-1">
                    <SelectItem
                        v-for="option in options"
                        :key="option.value"
                        :value="option.value"
                        class="relative flex cursor-pointer select-none items-center justify-between px-2.5 py-2 text-[13px] text-text-base outline-none data-[highlighted]:bg-primary-tint data-[highlighted]:text-primary"
                    >
                        <SelectItemText>{{ option.label }}</SelectItemText>
                        <SelectItemIndicator>
                            <Check class="h-3.5 w-3.5 text-primary" />
                        </SelectItemIndicator>
                    </SelectItem>
                </SelectViewport>
            </SelectContent>
        </SelectPortal>
    </SelectRoot>
</template>
