<script setup>
import { computed } from 'vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';
import CheckboxField from '@/components/ui/CheckboxField.vue';

/**
 * The Area dropdown and "Group by area" checkbox shared by the Debtors,
 * Creditors and ageing reports (and any other area-aware list).
 *
 * `area` mirrors the server's filter value: '' = all areas, 'none' = parties
 * with no area, otherwise an area id as a string. reka-ui's Select cannot
 * hold an empty string, so "all" is shown through a sentinel and translated
 * both ways here - callers only ever see the server's values.
 */
const props = defineProps({
    area: { type: String, default: '' },
    groupByArea: { type: Boolean, default: false },
    areas: { type: Array, default: () => [] },
    idPrefix: { type: String, default: 'area-filter' },
    // The list pages group nothing, so they hide the checkbox.
    showGroup: { type: Boolean, default: true },
});

const emit = defineEmits(['update:area', 'update:groupByArea']);

const ALL = 'all';

const options = computed(() => [
    { value: ALL, label: 'All areas' },
    { value: 'none', label: 'Unassigned' },
    ...props.areas.map((area) => ({ value: String(area.id), label: area.name })),
]);

const selected = computed({
    get: () => (props.area === '' ? ALL : props.area),
    set: (value) => emit('update:area', value === ALL ? '' : value),
});
</script>

<template>
    <div class="flex flex-wrap items-end gap-3">
        <div class="w-56">
            <Label :for="`${idPrefix}-area`" class="mb-1">Area</Label>
            <Select :id="`${idPrefix}-area`" v-model="selected" :options="options" />
        </div>
        <CheckboxField
            v-if="showGroup"
            :id="`${idPrefix}-group`"
            :model-value="groupByArea"
            label="Group by area"
            hint="Lists each area under its own heading with a subtotal."
            class="pb-2"
            @update:model-value="(value) => emit('update:groupByArea', value)"
        />
    </div>
</template>
