<script setup>
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * The scope lines under an accounting report's filters (plan 12, section
 * 5.9, BR13), all taken from the page's `branchReport` prop: the scope the
 * server applied (`report.label`, never computed here), the note for a user
 * who only sees some branches, and the management-report note on the income
 * statement and balance sheet. `showNarrowedNote` adds the day book's
 * warning that cross-branch vouchers are shown in part.
 *
 * Render only when `report` is not null.
 */
defineProps({
    report: { type: Object, required: true },
    showNarrowedNote: { type: Boolean, default: false },
});

const { label, plural } = useBranchLabel();
</script>

<template>
    <div class="mt-2 space-y-1">
        <p class="text-xs font-semibold text-text-strong">{{ report.label }}</p>
        <p v-if="report.restricted" class="text-[12px] text-text-muted">
            Company-wide totals are not available to your account.
        </p>
        <p v-if="report.management" class="text-[12px] text-text-muted">
            <span class="font-semibold">Management report</span> - shared costs sit where posted until an allocation
            journal moves them.
        </p>
        <p v-if="showNarrowedNote && report.narrowed" class="text-[12px] text-text-muted">
            Showing entries of the selected {{ plural('lower') }} only; other lines of cross-{{ label('lower') }}
            vouchers are hidden.
        </p>
    </div>
</template>
