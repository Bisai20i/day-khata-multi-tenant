<script setup>
import { computed } from 'vue';
import { Building2, Check, ChevronDown } from '@lucide/vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
import { ALL_BRANCHES, useBranch } from '@/composables/useBranch';
import { useBranchLabel } from '@/composables/useBranchLabel';

// Top-bar branch picker. Renders nothing for a single-branch company, or for a
// user who has only one branch to choose from, so a Main-only tenant looks
// exactly as before.
const { branches, current, currentBranch, canViewAll, isMultiBranch, isAllSelected, switching, switchBranch } = useBranch();
const { label, plural } = useBranchLabel();

const choiceCount = computed(() => branches.value.length + (canViewAll.value ? 1 : 0));
const visible = computed(() => isMultiBranch.value && choiceCount.value > 1);

const triggerText = computed(() => {
    if (isAllSelected.value) {
        return `All ${plural('lower')}`;
    }

    return currentBranch.value?.name ?? label();
});

const triggerLabel = computed(() => `Switch ${label('lower')}, current: ${triggerText.value}`);
</script>

<template>
    <DropdownMenu v-if="visible" align="end">
        <template #trigger>
            <button
                type="button"
                :aria-label="triggerLabel"
                :title="`Switch ${label('lower')}`"
                class="flex max-w-[160px] cursor-pointer items-center gap-1.5 border border-border bg-bg-subtle px-2.5 py-1.5 text-[12.5px] font-semibold text-text-strong hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
                <Building2 class="size-4 shrink-0 text-text-muted" />
                <span class="hidden min-w-0 truncate sm:block">{{ triggerText }}</span>
                <ChevronDown class="size-3.5 shrink-0 text-text-muted" />
            </button>
        </template>

        <DropdownMenuItem v-if="canViewAll" :disabled="switching" @select="() => switchBranch(ALL_BRANCHES)">
            <Check class="size-4 shrink-0" :class="isAllSelected ? 'text-primary' : 'invisible'" />
            <span class="min-w-0 flex-1 truncate">All {{ plural('lower') }}</span>
            <span v-if="isAllSelected" class="sr-only">(current)</span>
        </DropdownMenuItem>
        <DropdownMenuItem v-for="branch in branches" :key="branch.id" :disabled="switching" @select="() => switchBranch(branch.id)">
            <Check class="size-4 shrink-0" :class="branch.id === current ? 'text-primary' : 'invisible'" />
            <span class="min-w-0 flex-1 truncate">{{ branch.name }}</span>
            <span v-if="branch.id === current" class="sr-only">(current)</span>
            <span class="shrink-0 text-[11px] text-text-faint">{{ branch.code }}</span>
        </DropdownMenuItem>
    </DropdownMenu>
</template>
