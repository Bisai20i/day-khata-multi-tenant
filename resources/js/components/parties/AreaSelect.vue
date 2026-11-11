<script setup>
import { computed, nextTick, ref } from 'vue';
import { Plus } from '@lucide/vue';
import FormField from '@/components/ui/FormField.vue';
import Select from '@/components/ui/Select.vue';
import QuickAddModal from '@/components/ui/QuickAddModal.vue';
import { findCreatedByName } from '@/lib/formModal.js';
import { usePermissions } from '@/composables/usePermissions';

/**
 * The optional Area field of a customer / supplier form. `modelValue` is the
 * area id (or null for "no area"). Users who may manage areas also get a
 * "+ New area" shortcut that creates one without leaving the form and selects
 * it: the page reloads its `areas` prop and the new row is found by name.
 *
 * `blankLabel` renames the empty choice, e.g. for the bulk-assign bar where
 * choosing it clears the area.
 */
const props = defineProps({
    modelValue: { type: [Number, null], default: null },
    areas: { type: Array, default: () => [] },
    id: { type: String, required: true },
    label: { type: String, default: 'Area' },
    blankLabel: { type: String, default: '- None -' },
    error: { type: String, default: '' },
    help: { type: String, default: '' },
    allowCreate: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue']);

const { can } = usePermissions();

// reka-ui's Select cannot hold an empty-string value, so "no area" is a sentinel.
const BLANK = 'none';

const options = computed(() => [
    { value: BLANK, label: props.blankLabel },
    ...props.areas.map((area) => ({ value: String(area.id), label: area.name })),
]);

const selected = computed({
    get: () => (props.modelValue === null || props.modelValue === undefined ? BLANK : String(props.modelValue)),
    set: (value) => emit('update:modelValue', value === BLANK ? null : Number(value)),
});

const quickAddOpen = ref(false);

const canCreate = computed(() => props.allowCreate && can('areas.manage'));

async function onCreated(name) {
    // The page's props are patched in place after the redirect; wait a tick
    // so `areas` already holds the new row.
    await nextTick();
    // The server collapses runs of whitespace in the name; match on the same form.
    const created = findCreatedByName(props.areas, String(name ?? '').replace(/\s+/g, ' '));
    if (created) emit('update:modelValue', created.id);
}
</script>

<template>
    <FormField :label="label" :for="id" :error="error" :help="help">
        <div class="flex items-center gap-2">
            <Select :id="id" v-model="selected" :options="options" class="min-w-0 flex-1" />
            <button
                v-if="canCreate"
                type="button"
                class="inline-flex h-9 shrink-0 items-center gap-1 border-[1.5px] border-border bg-white px-2.5 text-[12px] font-semibold text-text-muted transition-colors duration-150 hover:border-primary hover:text-primary"
                @click="quickAddOpen = true"
            >
                <Plus class="size-3.5" aria-hidden="true" />
                New
            </button>
        </div>

        <QuickAddModal
            v-if="canCreate"
            v-model:open="quickAddOpen"
            title="New area"
            description="A place you group customers and suppliers by, e.g. a city, district or sales route."
            url="/areas"
            placeholder="e.g. Pokhara"
            @created="onCreated"
        />
    </FormField>
</template>
