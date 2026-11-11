<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * A compact branch name for a list row or a document line (plan 12, section
 * 5.10, BR12). Pass the row's loaded `branch` ({ name, code }) or just its
 * `branchId`, which is looked up among the user's branches in the shared
 * `branch` prop (an inactive branch is not there, so prefer `branch` for
 * lists). Renders nothing for a company that is not multi-branch, or when
 * the branch cannot be named.
 */
const props = defineProps({
    branch: { type: Object, default: null },
    branchId: { type: Number, default: null },
});

const page = usePage();
const { label } = useBranchLabel();

const resolved = computed(() => {
    if (page.props.branch?.isMulti !== true) {
        return null;
    }

    if (props.branch?.name) {
        return props.branch;
    }

    if (props.branchId === null) {
        return null;
    }

    return (page.props.branch?.allowed ?? []).find((branch) => branch.id === props.branchId) ?? null;
});
</script>

<template>
    <Badge
        v-if="resolved"
        variant="neutral"
        pill
        class="max-w-[140px]"
        :title="resolved.code ? `${label()}: ${resolved.name} (${resolved.code})` : `${label()}: ${resolved.name}`"
    >
        <span class="min-w-0 truncate">{{ resolved.name }}</span>
    </Badge>
</template>
