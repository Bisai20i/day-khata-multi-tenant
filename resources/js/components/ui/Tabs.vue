<script setup>
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps({
    modelValue: { type: [String, Number], default: undefined },
    tabs: { type: Array, required: true },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['update:modelValue']);

function onModelValueChange(value) {
    emit('update:modelValue', value);
}
</script>

<template>
    <TabsRoot :model-value="modelValue" :class="cn(props.class)" @update:model-value="onModelValueChange">
        <TabsList class="flex items-center gap-1 border-b-[1.5px] border-border">
            <TabsTrigger
                v-for="tab in tabs"
                :key="tab.value"
                :value="tab.value"
                class="border-b-2 border-transparent px-3 py-2 text-[12.5px] font-bold text-text-muted outline-none transition-colors duration-150 hover:text-text-base data-[state=active]:border-primary data-[state=active]:text-primary"
            >
                {{ tab.label }}
            </TabsTrigger>
        </TabsList>
        <TabsContent
            v-for="tab in tabs"
            :key="tab.value"
            :value="tab.value"
            class="pt-4 outline-none"
        >
            <slot :name="tab.value" :value="tab.value">
                <slot :value="tab.value" />
            </slot>
        </TabsContent>
    </TabsRoot>
</template>
