<script setup>
import { Pencil, Trash2 } from '@lucide/vue';
import Tooltip from '@/components/ui/Tooltip.vue';

/**
 * Edit and delete row buttons. Each can be hidden independently (canEdit,
 * canDelete) so a user with only one of the two permissions sees just that
 * action; both default to shown. Renders no buttons when both are hidden.
 */
defineProps({
    canEdit: { type: Boolean, default: true },
    canDelete: { type: Boolean, default: true },
    editLabel: { type: String, default: 'Edit' },
    deleteLabel: { type: String, default: 'Delete' },
});

const emit = defineEmits(['edit', 'delete']);
</script>

<template>
    <div class="flex items-center gap-2">
        <Tooltip v-if="canEdit" :label="editLabel">
            <button
                type="button"
                class="flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95"
                :aria-label="editLabel"
                @click="emit('edit')"
            >
                <Pencil class="h-[13px] w-[13px]" aria-hidden="true" />
            </button>
        </Tooltip>
        <Tooltip v-if="canDelete" :label="deleteLabel">
            <button
                type="button"
                class="flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 ease-out hover:bg-danger-bg hover:text-danger"
                :aria-label="deleteLabel"
                @click="emit('delete')"
            >
                <Trash2 class="h-[13px] w-[13px]" aria-hidden="true" />
            </button>
        </Tooltip>
    </div>
</template>
