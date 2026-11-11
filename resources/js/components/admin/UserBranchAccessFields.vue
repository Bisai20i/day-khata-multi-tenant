<script setup>
import { computed, watch } from 'vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';
import InfoTip from '@/components/ui/InfoTip.vue';

/**
 * Branch access on the users page (plan 12, BR10): every branch, or a chosen
 * set plus a default branch. The server (UserBranchAccess) re-checks all of
 * it, including that nobody hands out access they do not hold; this only
 * avoids offering choices that would be refused.
 */
const props = defineProps({
    // Every branch: { id, name, code, is_main, is_active }.
    branches: { type: Array, default: () => [] },
    // Branch ids the signed-in user may hand out, or null for every branch.
    actorAllowedBranchIds: { type: Array, default: null },
    isOwnerRow: { type: Boolean, default: false },
    // The signed-in non-owner editing their own row: access is fixed.
    locked: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const allBranches = defineModel('allBranches', { type: Boolean, default: true });
const branchIds = defineModel('branchIds', { type: Array, default: () => [] });
const defaultBranchId = defineModel('defaultBranchId', { type: [Number, null], default: null });

const actorIsRestricted = computed(() => Array.isArray(props.actorAllowedBranchIds));

function actorMayGrant(branch) {
    return !actorIsRestricted.value || props.actorAllowedBranchIds.includes(branch.id);
}

// Inactive branches stay visible while assigned, so removing one is possible.
const assignable = computed(() => props.branches.filter((branch) => branch.is_active || branchIds.value.includes(branch.id)));

const accessLocked = computed(() => props.locked || props.isOwnerRow);

function toggleBranch(branch, checked) {
    const next = new Set(branchIds.value);
    if (checked) next.add(branch.id);
    else next.delete(branch.id);
    branchIds.value = [...next].sort((a, b) => a - b);
}

const defaultOptions = computed(() => {
    const pool = allBranches.value
        ? props.branches.filter((branch) => branch.is_active)
        : props.branches.filter((branch) => branch.is_active && branchIds.value.includes(branch.id));
    return [{ value: null, label: 'None (Main, or the first allowed)' }, ...pool.map((branch) => ({ value: branch.id, label: branch.name }))];
});

// A default outside the allowed set would be refused; drop it as soon as the
// set no longer contains it.
watch([allBranches, branchIds], () => {
    if (defaultBranchId.value === null) return;
    if (!defaultOptions.value.some((option) => option.value === defaultBranchId.value)) defaultBranchId.value = null;
});
</script>

<template>
    <div class="flex flex-col gap-3 border-t border-border pt-4">
        <div class="flex flex-col gap-1">
            <div class="flex items-center gap-1">
                <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Branch access</p>
                <InfoTip text="Limits which branches this employee can see and record in." />
            </div>
            <!-- Shown, not tucked into the tip: an admin restricting a user must see that HR stays company-wide (plan 12, section 5.7). -->
            <p class="text-xs text-text-muted">This does not limit HR: anyone with HR permissions sees all staff.</p>
        </div>

        <div class="flex items-center gap-2">
            <input
                id="all_branches"
                v-model="allBranches"
                type="checkbox"
                class="size-4 border-[1.5px] border-border"
                :disabled="accessLocked || (actorIsRestricted && !allBranches)"
            />
            <label for="all_branches" class="text-sm font-semibold text-text-base">All branches</label>
        </div>
        <p v-if="isOwnerRow" class="text-xs text-text-faint">The owner always has access to every branch.</p>
        <p v-else-if="locked" class="text-xs text-text-faint">You cannot change your own branch access.</p>
        <p v-if="errors.all_branches" class="text-sm text-danger">{{ errors.all_branches }}</p>

        <fieldset v-if="!allBranches" class="flex flex-col gap-2">
            <legend class="mb-1 text-sm font-semibold text-text-base">Branches <span class="text-danger">*</span></legend>
            <label v-for="branch in assignable" :key="branch.id" class="flex items-center gap-2 text-sm text-text-base">
                <input
                    type="checkbox"
                    class="size-4 border-[1.5px] border-border"
                    :checked="branchIds.includes(branch.id)"
                    :disabled="accessLocked || !actorMayGrant(branch) || (!branch.is_active && !branchIds.includes(branch.id))"
                    @change="toggleBranch(branch, $event.target.checked)"
                />
                <span>{{ branch.name }}</span>
                <span class="text-xs text-text-faint">{{ branch.code }}</span>
                <span v-if="!branch.is_active" class="text-xs text-text-faint">(inactive)</span>
            </label>
            <p v-if="errors.branch_ids" class="text-sm text-danger">{{ errors.branch_ids }}</p>
        </fieldset>

        <div>
            <div class="mb-1 flex items-center gap-1">
                <Label for="default_branch_id">Default branch</Label>
                <InfoTip text="Where new documents are recorded when this employee has not picked a branch." />
            </div>
            <Select id="default_branch_id" v-model="defaultBranchId" :options="defaultOptions" />
            <p v-if="errors.default_branch_id" class="mt-1 text-sm text-danger">{{ errors.default_branch_id }}</p>
        </div>
    </div>
</template>
