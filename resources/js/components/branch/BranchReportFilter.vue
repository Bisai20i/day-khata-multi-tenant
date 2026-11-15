<script setup>
import { computed } from 'vue';
import Label from '@/components/ui/Label.vue';
import Select from '@/components/ui/Select.vue';
import CheckboxField from '@/components/ui/CheckboxField.vue';
import { ALL_BRANCHES } from '@/composables/useBranch';
import { SELECTED_BRANCHES } from '@/composables/useBranchReportFilter';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * The branch controls of an accounting report's filter row (plan 12, section
 * 5.9, BR13): a branch dropdown and, on reports with branch columns, a
 * "Show by branch" tick.
 *
 * `report` is the page's `branchReport` prop; render this only when it is
 * not null, so a company without branches sees no new control. Bind
 * `v-model:choice` and `v-model:by-branch` to `useBranchReportFilter()` and
 * run the page's own filter submit on `@change`, so the date range, fiscal
 * year and account stay in the request.
 *
 * "All branches" is offered only to a user who may see them all. A user
 * limited to some branches gets those branches only, or a plain label when
 * they have a single one. The scope text (`report.label`) and the notes live
 * in `BranchReportNotes`.
 */
const props = defineProps({
    report: { type: Object, required: true },
    allowByBranch: { type: Boolean, default: false },
    idPrefix: { type: String, default: 'branch-report' },
    hideLabel: { type: Boolean, default: false },
});

const emit = defineEmits(['change']);

const choice = defineModel('choice', { type: [String, Number, null], default: null });
const byBranch = defineModel('byBranch', { type: Boolean, default: false });

const { label, plural } = useBranchLabel();

const branches = computed(() => props.report.options ?? []);
const isFixedScope = computed(() => props.report.restricted === true && branches.value.length <= 1);
const fixedScopeText = computed(() => {
    const only = branches.value[0];

    return only ? (only.code ? `${only.name} (${only.code})` : only.name) : props.report.label;
});
const showByBranch = computed(() => props.allowByBranch && branches.value.length > 1);

const options = computed(() => {
    const list = [];

    if (props.report.restricted !== true) {
        list.push({ value: ALL_BRANCHES, label: `All ${plural('lower')}` });
    } else if (branches.value.length > 1) {
        // Never "All": a restricted user's scope is their own branches.
        list.push({ value: ALL_BRANCHES, label: `Your ${plural('lower')}` });
    }

    if (choice.value === SELECTED_BRANCHES) {
        list.push({ value: SELECTED_BRANCHES, label: props.report.label });
    }

    for (const branch of branches.value) {
        const name = branch.code ? `${branch.name} (${branch.code})` : branch.name;

        list.push({ value: branch.id, label: branch.is_active === false ? `${name} (inactive)` : name });
    }

    return list;
});

const selectedValue = computed({
    get: () => choice.value,
    set: (value) => {
        if (value === choice.value) {
            return;
        }

        choice.value = value;
        emit('change');
    },
});

function onByBranch(value) {
    byBranch.value = value;
    emit('change');
}
</script>

<template>
    <div :class="['flex flex-wrap gap-3', hideLabel ? 'items-center' : 'items-end']">
        <div :class="hideLabel ? 'w-48 sm:w-56' : 'w-56'">
            <Label v-if="!hideLabel" class="mb-1">{{ label() }}</Label>
            <span
                v-if="isFixedScope"
                :class="[
                    'truncate text-[13px] font-semibold text-text-strong',
                    hideLabel
                        ? 'inline-flex h-9 items-center rounded border border-border bg-bg-subtle px-2.5 text-xs font-medium text-text-muted'
                        : 'flex h-9 items-center',
                ]"
            >
                {{ fixedScopeText }}
            </span>
            <Select
                v-else
                v-model="selectedValue"
                :options="options"
                :placeholder="report.label"
                :aria-label="label()"
            />
        </div>
        <CheckboxField
            v-if="showByBranch"
            :id="`${idPrefix}-by-branch`"
            :model-value="byBranch"
            :label="`Show by ${label('lower')}`"
            :hint="`Adds a column for each ${label('lower')} on screen.`"
            :class="hideLabel ? '' : 'pb-2'"
            @update:model-value="onByBranch"
        />
    </div>
</template>
