<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import BranchSelect from '@/components/branch/BranchSelect.vue';
import { useBranchField } from '@/composables/useBranchField';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // Today in Kathmandu (AD, YYYY-MM-DD), the default posting date.
    today: { type: String, default: '' },
    // Branch picker (BranchFormOptions::props()).
    branchOptions: { type: Array, default: () => [] },
    defaultBranchId: { type: Number, default: null },
});

useLayoutChrome('New Payroll Run');

const form = useForm({
    period_label: '',
    period_start: '',
    period_end: '',
    date: props.today,
    narration: '',
    all_branches: false,
});

// Branches (plan 12, BR12): the run's branch (its origin) and, optionally,
// the staff of every branch the user may use, each payslip then filed in its
// staff member's branch (PayrollDraft). Neither is shown nor submitted unless
// the company runs several branches and the user may pick.
const { branchId, showPicker, withBranch } = useBranchField(
    () => props.branchOptions,
    () => props.defaultBranchId,
);

// ISO dates compare correctly as strings; the server re-validates.
const endBeforeStart = computed(() => !!form.period_start && !!form.period_end && form.period_end < form.period_start);

const canSubmit = computed(
    () => form.period_label.trim() !== '' && !!form.period_start && !!form.period_end && !!form.date && !endBeforeStart.value,
);

function submit() {
    if (!canSubmit.value) return;

    form
        .transform(({ all_branches: allBranches, ...data }) => {
            const payload = withBranch({
                ...data,
                period_label: data.period_label.trim(),
                narration: data.narration?.trim() ? data.narration.trim() : null,
            });

            return showPicker.value ? { ...payload, all_branches: allBranches } : payload;
        })
        .post('/hr/payroll', { preserveScroll: true });
}
</script>

<template>
    <div class="flex min-h-full flex-col">
        <PageHeader title="New Payroll Run" description="Open a draft payroll run for a pay period. Fields marked * are required." />

        <p v-if="form.errors.period_start" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
            {{ form.errors.period_start }}
        </p>

        <form class="flex flex-1 flex-col gap-4" @submit.prevent="submit">
            <Card variant="panel" class="!p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <template v-if="showPicker">
                        <BranchSelect v-model="branchId" :options="branchOptions" :error="form.errors.branch_id" />
                        <label class="flex items-center gap-2 self-end pb-2 text-sm text-text-base">
                            <input v-model="form.all_branches" type="checkbox" class="size-4" />
                            Include staff of every branch (each payslip stays in its staff member's branch)
                        </label>
                    </template>
                    <div>
                        <Label class="mb-1">Period label <span class="text-danger" aria-hidden="true">*</span></Label>
                        <Input v-model="form.period_label" type="text" maxlength="100" placeholder="e.g. Kartik 2083" required />
                        <p v-if="form.errors.period_label" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.period_label }}</p>
                        <p v-if="!showPicker && form.errors.branch_id" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.branch_id }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Voucher date (BS) <span class="text-danger" aria-hidden="true">*</span></Label>
                        <NepaliDateInput v-model="form.date" required />
                        <p class="mt-1 text-xs text-text-muted">The date the payroll is posted to the books.</p>
                        <p v-if="form.errors.date" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.date }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Period start (BS) <span class="text-danger" aria-hidden="true">*</span></Label>
                        <NepaliDateInput v-model="form.period_start" required />
                    </div>
                    <div>
                        <Label class="mb-1">Period end (BS) <span class="text-danger" aria-hidden="true">*</span></Label>
                        <NepaliDateInput v-model="form.period_end" required />
                        <p v-if="endBeforeStart" class="mt-1 text-sm text-danger" role="alert">Period end must be on or after the period start.</p>
                        <p v-else-if="form.errors.period_end" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.period_end }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <Label class="mb-1">Narration</Label>
                        <Input v-model="form.narration" type="text" maxlength="200" placeholder="Optional" />
                        <p v-if="form.errors.narration" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.narration }}</p>
                    </div>
                </div>
                <p class="mt-4 text-xs text-text-muted">
                    The draft is prefilled from each active staff member's recurring pay and can be edited before posting.
                </p>
            </Card>

            <div class="mt-auto flex flex-wrap items-center justify-end gap-2">
                <Link href="/hr/payroll">
                    <Button variant="secondary" tone="purple" type="button">Cancel</Button>
                </Link>
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="!canSubmit || form.processing">
                    {{ form.processing ? 'Creating...' : 'Create draft' }}
                </Button>
            </div>
        </form>
    </div>
</template>
