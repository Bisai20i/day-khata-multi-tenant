<script setup>
import { computed } from 'vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';

// Optional project (cost centre) picker shared by the purchase, purchase
// return and supplier payment forms. Every ledger line the document posts is
// tagged with the chosen project; blank means no project.
const props = defineProps({
    modelValue: { type: [Number, String, null], default: null },
    // [{ id, code, name }] from the controller's `projects` prop.
    projects: { type: Array, default: () => [] },
    error: { type: String, default: null },
    id: { type: String, default: 'document-project' },
});

const emit = defineEmits(['update:modelValue']);

const projectOptions = computed(() =>
    props.projects.map((project) => ({
        value: project.id,
        label: project.code ? `${project.code} - ${project.name}` : project.name,
    })),
);
</script>

<template>
    <div>
        <div class="mb-1 flex items-center justify-between gap-2">
            <Label :for="id">Project</Label>
            <button
                v-if="modelValue !== null && modelValue !== ''"
                type="button"
                class="text-xs font-semibold text-primary"
                @click="emit('update:modelValue', null)"
            >
                Clear
            </button>
        </div>
        <Select
            :id="id"
            :model-value="modelValue"
            :options="projectOptions"
            placeholder="No project"
            @update:model-value="(value) => emit('update:modelValue', value ?? null)"
        />
        <p v-if="error" class="mt-1 text-sm text-danger">{{ error }}</p>
    </div>
</template>
