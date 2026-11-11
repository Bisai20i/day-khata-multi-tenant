<script setup>
import { computed, useId } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Label from '@/components/ui/Label.vue';
import Select from '@/components/ui/Select.vue';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * The branch picker of a document form (plan 12, section 5.10, BR12).
 *
 * `options` is the page's `branchOptions` prop (id, name, code, is_main) and
 * `v-model` the branch id, usually `useBranchField().branchId`. Renders
 * nothing for a company that is not multi-branch or a user with a single
 * branch, so those forms look exactly as before; the server then files the
 * document in the user's posting branch. The caption follows the company's
 * own word for a branch (Branch, Site, Outlet, Project).
 */
const props = defineProps({
    options: { type: Array, default: () => [] },
    error: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: true },
    help: { type: String, default: '' },
});

const branchId = defineModel({ type: [Number, null], default: null });

const page = usePage();
const { label } = useBranchLabel();

// Ties the caption to the trigger, so the label names the picker for screen
// readers and a click on it opens the list (unique per instance on the page).
const selectId = useId();

const visible = computed(() => page.props.branch?.isMulti === true && props.options.length > 1);

const selectOptions = computed(() =>
    props.options.map((branch) => ({
        value: branch.id,
        label: branch.code ? `${branch.name} (${branch.code})` : branch.name,
    })),
);
</script>

<template>
    <div v-if="visible">
        <Label :for="selectId" class="mb-1">{{ label() }} <span v-if="required" class="text-danger">*</span></Label>
        <Select :id="selectId" v-model="branchId" :options="selectOptions" :disabled="disabled" :placeholder="`Select ${label('lower')}`" />
        <p v-if="help && !error" class="mt-1 text-xs text-text-muted">{{ help }}</p>
        <p v-if="error" class="mt-1 text-sm text-danger" role="alert">{{ error }}</p>
    </div>
</template>
