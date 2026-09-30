<script setup>
import { computed, ref } from 'vue';
import { AlertTriangle } from '@lucide/vue';
import { requirementLabels, resolveModules, toggleModule } from '@/lib/moduleSelection';

/**
 * Module entitlement checkboxes shared by the tenant create and show pages.
 * v-model is the list of module keys; it is emitted already resolved
 * (always_on modules and requirements included). The server resolves the
 * selection again on save, so this only keeps the boxes consistent.
 */
const props = defineProps({
    catalog: { type: Array, required: true },
    modelValue: { type: Array, default: () => [] },
    idPrefix: { type: String, default: 'module' },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const selected = computed(() => resolveModules(props.catalog, props.modelValue));
const alwaysOn = computed(() => props.catalog.filter((module) => module.always_on));
const optional = computed(() => props.catalog.filter((module) => !module.always_on));
const warning = ref('');

function labelOf(key) {
    return props.catalog.find((module) => module.key === key)?.label ?? key;
}

function onToggle(module, checked) {
    const result = toggleModule(props.catalog, selected.value, module.key, checked);

    warning.value =
        result.removed.length > 0
            ? `Turning off ${module.label} also turned off ${result.removed.map(labelOf).join(', ')}, which need it.`
            : '';

    emit('update:modelValue', result.selected);
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <fieldset v-if="alwaysOn.length > 0">
            <legend class="mb-1 text-xs font-semibold text-text-muted">Always on</legend>
            <label v-for="module in alwaysOn" :key="module.key" :for="`${idPrefix}-${module.key}`" class="flex items-center gap-2 py-1 text-sm text-text-muted">
                <input :id="`${idPrefix}-${module.key}`" type="checkbox" class="size-4 border-[1.5px] border-border" checked disabled />
                {{ module.label }}
            </label>
        </fieldset>

        <fieldset>
            <legend class="mb-1 text-xs font-semibold text-text-muted">Optional modules</legend>
            <label
                v-for="module in optional"
                :key="module.key"
                :for="`${idPrefix}-${module.key}`"
                class="flex cursor-pointer items-center gap-2 py-1 text-sm text-text-base"
            >
                <input
                    :id="`${idPrefix}-${module.key}`"
                    type="checkbox"
                    class="size-4 border-[1.5px] border-border"
                    :checked="selected.includes(module.key)"
                    @change="onToggle(module, $event.target.checked)"
                />
                <span>{{ module.label }}</span>
                <span v-if="module.requires.length > 0" class="text-xs text-text-muted">(needs {{ requirementLabels(catalog, module.key).join(', ') }})</span>
            </label>
        </fieldset>

        <p v-if="warning" class="flex items-start gap-1.5 border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-sm text-warning-text" role="status">
            <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ warning }}
        </p>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
    </div>
</template>
