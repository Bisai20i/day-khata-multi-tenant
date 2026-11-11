<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { Ban, Printer, Save, Send, Trash2, Wallet } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import Label from '@/components/ui/Label.vue';
import DetailField from '@/components/manufacturing/DetailField.vue';
import PayrollDraftEditor from '@/components/hr/PayrollDraftEditor.vue';
import PayrollLinesTable from '@/components/hr/PayrollLinesTable.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { usePermissions } from '@/composables/usePermissions';
import { draftFromRun, payrollDraftPayload, usePayrollDraft } from '@/composables/usePayrollDraft.js';
import { compareMoney, formatMoney, sumMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

/**
 * One payroll run. A draft is edited in place (PayrollDraftEditor) and then
 * saved, posted or discarded from the header; a posted run is a read-only
 * payslip table that can be cancelled with a reason; a cancelled run is
 * read-only with the cancellation shown.
 *
 * Posting always posts what is stored, so it is blocked while the grid has
 * unsaved edits: the user saves first and posts the draft they reviewed.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    run: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    // Draft-only pickers; empty for a posted/cancelled run.
    components: { type: Array, default: () => [] },
    staffOptions: { type: Array, default: () => [] },
    // { staffId: "outstanding advance" }; PHP sends [] when nobody owes anything.
    advanceBalances: { type: [Object, Array], default: () => ({}) },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
const { can } = usePermissions();
useLayoutChrome('Payroll Run');

// Watches the flash object (new on every response), not its status string: the save keeps
// this component mounted (preserveState), so a second "Draft saved." would otherwise equal
// the first and never toast again.
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.status) toast({ message: flash.status, variant: 'success' });
    },
    { immediate: true },
);

const STATUS_META = {
    draft: { label: 'Draft', variant: 'warning' },
    posted: { label: 'Posted', variant: 'success' },
    cancelled: { label: 'Cancelled', variant: 'danger' },
};

const status = computed(() => STATUS_META[props.run.status] ?? { label: props.run.status, variant: 'neutral' });
const isDraft = computed(() => props.run.status === 'draft');
const isPosted = computed(() => props.run.status === 'posted');
const isCancelled = computed(() => props.run.status === 'cancelled');
const runUrl = computed(() => `/hr/payroll/${props.run.id}`);
// A draft has no run number yet (it is assigned at post).
const runLabel = computed(() => props.run.display_number ?? 'Draft');

// Errors that are not tied to one field: post/discard refusals.
const runError = computed(() => page.props.errors?.run ?? '');

// ---------------------------------------------------------------------------
// Draft: edit, save, post, discard
// ---------------------------------------------------------------------------

const form = useForm(draftFromRun(props.run, props.lines));
const draft = usePayrollDraft(form, () => props);
const canEditDraft = computed(() => isDraft.value && can('payroll.create'));
const canPostDraft = computed(() => isDraft.value && can('payroll.manage'));

const actionForm = useForm({});

function saveDraft() {
    if (!canEditDraft.value || form.processing) return;

    draft.attempted.value = true;
    if (draft.hasClientErrors.value) {
        toast({ message: 'Fix the highlighted fields before saving.', variant: 'danger' });
        return;
    }

    form.transform((data) => payrollDraftPayload(data)).put(runUrl.value, {
        preserveScroll: true,
        // Keep the user's edits on screen when the server refuses the save.
        preserveState: true,
        onSuccess: (response) => draft.reload(response.props),
    });
}

async function postDraft() {
    if (!canPostDraft.value || draft.isDirty.value || actionForm.processing) return;

    const confirmed = await confirm({
        title: 'Post payroll to the ledger',
        message: `Post this payroll run for Rs ${formatMoney(props.run.total_gross)} gross? This creates the ledger entry; net pay not yet paid becomes Salary Payable.`,
        confirmLabel: 'Post to ledger',
        safeDefault: true,
    });
    if (!confirmed) return;

    actionForm.post(`${runUrl.value}/post`, { preserveScroll: true });
}

async function discardDraft() {
    if (!canEditDraft.value || actionForm.processing) return;

    const confirmed = await confirm({
        title: 'Discard draft',
        message: `Discard this draft? Its payslips are deleted and nothing is posted. This cannot be undone.`,
        tone: 'danger',
        confirmLabel: 'Discard draft',
        safeDefault: true,
    });
    if (!confirmed) return;

    actionForm.delete(runUrl.value, { preserveScroll: true });
}

// ---------------------------------------------------------------------------
// Posted: cancel with reason, pay outstanding
// ---------------------------------------------------------------------------

const cancelling = ref(false);
const cancelForm = useForm({ reason: '' });
const canCancelRun = computed(() => isPosted.value && can('payroll.cancel'));

function submitCancel() {
    if (cancelForm.processing) return;

    cancelForm.post(`${runUrl.value}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = false;
            cancelForm.reset();
        },
    });
}

const totalOutstanding = computed(() => (isPosted.value ? sumMoney(props.lines.map((line) => line.outstanding ?? '0.00')) : '0.00'));
const totalPaid = computed(() => (isPosted.value ? sumMoney(props.lines.map((line) => line.paid ?? '0.00')) : '0.00'));
const hasOutstanding = computed(() => compareMoney(totalOutstanding.value, '0.00') > 0);

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

/** Live totals while editing a draft, the stored ones otherwise. */
const figures = computed(() => {
    if (isDraft.value) {
        const live = draft.totals.value;
        return [
            { label: 'Staff', value: String(live.count) },
            { label: 'Gross pay', value: formatMoney(live.gross) },
            { label: 'Advance recovered', value: formatMoney(live.recovery) },
            { label: 'Net pay', value: formatMoney(live.net) },
        ];
    }

    const stored = [
        { label: 'Staff', value: String(props.run.lines_count ?? props.lines.length) },
        { label: 'Gross pay', value: formatMoney(props.run.total_gross) },
        { label: 'Advance recovered', value: formatMoney(props.run.total_recovery) },
        { label: 'Net pay', value: formatMoney(props.run.total_net) },
    ];
    if (isPosted.value) {
        stored.push({ label: 'Paid', value: formatMoney(totalPaid.value) }, { label: 'Outstanding', value: formatMoney(totalOutstanding.value) });
    }

    return stored;
});

/** "2026-08-17 10:15:00" -> BS date and the time, for the audit fields. */
function formatStamp(stamp) {
    if (!stamp) return '';
    const text = String(stamp);

    return `${formatBsDate(text.slice(0, 10))} ${text.slice(11, 16)}`.trim();
}

function formatPeriodDate(iso) {
    return iso ? formatBsDate(String(iso).slice(0, 10)) : '-';
}
</script>

<template>
    <div class="flex min-h-full flex-col">
        <PageHeader
            :title="`Payroll ${runLabel}`"
            :description="isDraft ? 'Review each payslip, save the draft, then post it to the ledger.' : run.period_label"
            back-href="/hr/payroll"
            back-label="Payroll"
        >
            <template #meta>
                <Badge pill :variant="status.variant">{{ status.label }}</Badge>
                <Badge v-if="isDraft && draft.isDirty.value" pill variant="warning">Unsaved changes</Badge>
            </template>

            <template v-if="isDraft">
                <Button
                    v-if="canEditDraft"
                    variant="secondary"
                    tone="danger"
                    type="button"
                    :disabled="actionForm.processing || form.processing"
                    @click="discardDraft"
                >
                    <Trash2 class="size-4" /> Discard draft
                </Button>
                <Button
                    v-if="canEditDraft"
                    variant="secondary"
                    tone="purple"
                    type="button"
                    :disabled="!draft.isDirty.value || actionForm.processing"
                    :loading="form.processing"
                    @click="saveDraft"
                >
                    <Save class="size-4" /> {{ form.processing ? 'Saving...' : 'Save draft' }}
                </Button>
                <Button
                    v-if="canPostDraft"
                    variant="primary"
                    tone="purple"
                    type="button"
                    :disabled="draft.isDirty.value || form.processing || lines.length === 0"
                    :loading="actionForm.processing"
                    :title="draft.isDirty.value ? 'Save the draft before posting.' : ''"
                    @click="postDraft"
                >
                    <Send class="size-4" /> Post to ledger
                </Button>
            </template>

            <template v-else>
                <a v-if="run.journal_voucher_id && can('journal_vouchers.print')" :href="`/journal-vouchers/${run.journal_voucher_id}/print`" target="_blank" rel="noopener">
                    <Button variant="secondary" tone="purple" type="button"><Printer class="size-4" /> Voucher</Button>
                </a>
                <Link v-if="isPosted && hasOutstanding && can('payroll_payments.view') && can('payroll_payments.create')" href="/hr/salary-payments">
                    <Button variant="secondary" tone="purple" type="button"><Wallet class="size-4" /> Pay salaries</Button>
                </Link>
                <Button
                    v-if="canCancelRun"
                    variant="secondary"
                    tone="danger"
                    type="button"
                    :disabled="run.has_live_payments"
                    :title="run.has_live_payments ? 'This run has salary payments; cancel its salary payments first.' : ''"
                    @click="cancelling = true"
                >
                    <Ban class="size-4" /> Cancel run
                </Button>
            </template>
        </PageHeader>

        <p v-if="runError" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">{{ runError }}</p>
        <p v-if="cancelForm.errors.reason && !cancelling" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
            {{ cancelForm.errors.reason }}
        </p>
        <p v-if="isDraft && canPostDraft && draft.isDirty.value" class="mb-4 border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-sm text-warning-text" role="status">
            You have unsaved changes. Save the draft before posting it to the ledger.
        </p>
        <p v-if="canCancelRun && run.has_live_payments" class="mb-4 border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-sm text-text-muted" role="note">
            This run has posted salary payments, so it cannot be cancelled yet: cancel its salary payments first.
        </p>

        <Card variant="panel" class="mb-4 !p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <DetailField label="Period" :value="run.period_label" />
                <DetailField label="Period dates (BS)">{{ formatPeriodDate(run.period_start) }} to {{ formatPeriodDate(run.period_end) }}</DetailField>
                <DetailField label="Posting date (BS)">
                    {{ formatPeriodDate(run.date) }}
                    <span class="text-text-faint">({{ String(run.date ?? '').slice(0, 10) }})</span>
                </DetailField>
                <DetailField label="Fiscal year" :value="run.fiscal_year" />
                <DetailField label="Created by" :value="run.created_by" />
                <DetailField v-if="!isDraft" label="Ledger voucher" :value="run.journal_voucher_id ? `Voucher #${run.journal_voucher_id}` : ''" />
                <DetailField v-if="!isDraft" label="Posted by" :value="run.posted_by" />
                <DetailField v-if="!isDraft" label="Posted at" :value="formatStamp(run.posted_at)" />
                <DetailField v-if="isCancelled" label="Cancelled by" :value="run.cancelled_by" />
                <DetailField v-if="isCancelled" label="Cancelled at" :value="formatStamp(run.cancelled_at)" />
                <DetailField v-if="!isDraft && run.narration" label="Narration" :value="run.narration" class="sm:col-span-3" />
                <DetailField v-if="isCancelled" label="Cancellation reason" class="sm:col-span-3">
                    <span class="text-danger">{{ run.cancel_reason }}</span>
                </DetailField>
            </div>
        </Card>

        <Card variant="panel" class="mb-4 !p-4">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <DetailField v-for="figure in figures" :key="figure.label" :label="figure.label">
                    <span class="text-base font-bold">{{ figure.value }}</span>
                </DetailField>
            </div>
        </Card>

        <form v-if="isDraft" class="flex flex-1 flex-col" @submit.prevent="saveDraft">
            <PayrollDraftEditor :form="form" :draft="draft" :readonly="!canEditDraft" />
        </form>
        <PayrollLinesTable v-else :lines="lines" :show-payments="isPosted" />

        <Modal :open="cancelling" title="Cancel payroll run" size="compact" @update:open="(v) => (cancelling = v)">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This reverses the payroll's ledger entry and restores any advance it recovered. This cannot be undone.
                </p>
                <div>
                    <Label for="payroll-cancel-reason" class="mb-1">Reason <span class="text-danger">*</span></Label>
                    <Input id="payroll-cancel-reason" v-model="cancelForm.reason" type="text" placeholder="Reason for cancellation" required />
                    <p v-if="cancelForm.errors.reason" class="mt-1 text-sm text-danger">{{ cancelForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="neutral" type="button" @click="cancelling = false">Back</Button>
                <Button variant="primary" tone="danger" type="button" :disabled="cancelForm.processing || cancelForm.reason.trim() === ''" @click="submitCancel">
                    Confirm cancellation
                </Button>
            </template>
        </Modal>
    </div>
</template>
