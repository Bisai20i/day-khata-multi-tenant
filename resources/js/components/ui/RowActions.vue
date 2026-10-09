<script setup>
import { Ellipsis, Pencil, Trash2 } from '@lucide/vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import Tooltip from '@/components/ui/Tooltip.vue';

/**
 * Edit and delete row buttons. Each can be hidden independently (canEdit,
 * canDelete) so a user with only one of the two permissions sees just that
 * action; both default to shown. Renders no buttons when both are hidden.
 *
 * A row with further actions passes them as DropdownMenuItems in the `more`
 * slot; they open from a trailing "More" button, which is only rendered when
 * the slot is given.
 */
defineProps({
    canEdit: { type: Boolean, default: true },
    canDelete: { type: Boolean, default: true },
    editLabel: { type: String, default: 'Edit' },
    deleteLabel: { type: String, default: 'Delete' },
    moreLabel: { type: String, default: 'More actions' },
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
        <DropdownMenu v-if="$slots.more" align="end">
            <template #trigger>
                <button
                    type="button"
                    class="flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 ease-out hover:bg-primary-tint hover:text-primary data-[state=open]:bg-primary-tint data-[state=open]:text-primary"
                    :aria-label="moreLabel"
                    :title="moreLabel"
                >
                    <Ellipsis class="h-[13px] w-[13px]" aria-hidden="true" />
                </button>
            </template>
            <slot name="more" />
        </DropdownMenu>
    </div>
</template>
