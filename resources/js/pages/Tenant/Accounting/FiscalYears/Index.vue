<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Archive, Lock, Plus, Unlock } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import Select from '@/components/ui/Select.vue';
import DataTable from '@/components/ui/DataTable.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Badge from '@/components/ui/Badge.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { formatBsDate, todayInKathmandu } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    fiscalYears: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Fiscal Years');

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

// Flash status is watched (not just read on mount) because create/close both
// redirect back to this same route + component, which Inertia re-renders in
// place without an onMounted re-run.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const createModalOpen = ref(false);

const form = useForm({
    name: '',
    start_date: '',
    end_date: '',
});

function openCreate() {
    form.reset();
    form.clearErrors();
    createModalOpen.value = true;
}

function closeCreateModal() {
    createModalOpen.value = false;
    form.reset();
    form.clearErrors();
}

function onCreateModalOpenChange(value) {
    if (value) {
        createModalOpen.value = true;
    } else {
        closeCreateModal();
    }
}

function submitCreate() {
    form.post('/fiscal-years', { onSuccess: closeCreateModal });
}

const closeModalOpen = ref(false);
const closingYear = ref(null);

const closeForm = useForm({
    next_fiscal_year_id: null,
    reason: '',
});

// A year that has not reached its end date yet can only be closed with a
// written reason, and only by an admin - the server enforces both (see
// App\Models\FiscalYear::close()); this just tells the user before they try.
const closingEarly = computed(() => {
    if (!closingYear.value?.end_date) {
        return false;
    }

    return todayInKathmandu() <= String(closingYear.value.end_date).slice(0, 10);
});

const eligibleNextYears = computed(() =>
    props.fiscalYears
        .filter((year) => year.status === 'closed')
        .map((year) => ({ value: year.id, label: year.name })),
);

function openClose(fiscalYear) {
    closingYear.value = fiscalYear;
    closeForm.reset();
    closeForm.clearErrors();
    closeModalOpen.value = true;
}

function closeCloseModal() {
    closeModalOpen.value = false;
    closingYear.value = null;
    closeForm.reset();
    closeForm.clearErrors();
}

function onCloseModalOpenChange(value) {
    if (value) {
        closeModalOpen.value = true;
    } else {
        closeCloseModal();
    }
}

function submitClose() {
    closeForm.post(`/fiscal-years/${closingYear.value.id}/close`, { onSuccess: closeCloseModal });
}

// Archiving copies a closed year's ledger out to its own read-only
// cold-storage file (see App\Support\FiscalYear\FiscalYearArchiver) -
// never deletes/moves anything, so it's safe to just confirm-and-fire
// rather than needing a dedicated modal the way close() does.
async function archiveFiscalYear(fiscalYear) {
    const confirmed = await confirm({
        title: 'Archive fiscal year',
        message: `Archive "${fiscalYear.name}"? This copies its ledger to a read-only snapshot. Nothing is deleted, and it can only be done once.`,
        confirmLabel: 'Archive year',
    });
    if (!confirmed) {
        return;
    }

    router.post(`/fiscal-years/${fiscalYear.id}/archive`);
}

// Reopen/relock - Phase D closed-period correction. A closed year with
// isOpenForCorrection === true (reopened_at set, relocked_at still null)
// lets Purchase/Journal Voucher/Stock Adjustment postings target it with a
// mandatory reason, per the locked design decision (see App\Support\
// ClosedFiscalYearGuard's docblock).
function isOpenForCorrection(fiscalYear) {
    return !!fiscalYear.reopened_at && !fiscalYear.relocked_at;
}

const reopenModalOpen = ref(false);
const reopeningYear = ref(null);

const reopenForm = useForm({
    reason: '',
});

function openReopen(fiscalYear) {
    reopeningYear.value = fiscalYear;
    reopenForm.reset();
    reopenForm.clearErrors();
    reopenModalOpen.value = true;
}

function closeReopenModal() {
    reopenModalOpen.value = false;
    reopeningYear.value = null;
    reopenForm.reset();
    reopenForm.clearErrors();
}

function onReopenModalOpenChange(value) {
    if (value) {
        reopenModalOpen.value = true;
    } else {
        closeReopenModal();
    }
}

function submitReopen() {
    reopenForm.post(`/fiscal-years/${reopeningYear.value.id}/reopen`, { onSuccess: closeReopenModal });
}

async function relockFiscalYear(fiscalYear) {
    const confirmed = await confirm({
        title: 'Relock fiscal year',
        message: `Relock "${fiscalYear.name}"? Purchase, Journal Voucher, and Stock Adjustment postings will no longer be able to target it, and a supplementary closing entry will sweep whatever the corrections left unswept so the year's Balance Sheet balances again.`,
        confirmLabel: 'Relock year',
    });
    if (!confirmed) {
        return;
    }

    router.post(`/fiscal-years/${fiscalYear.id}/lock`);
}

const columns = [
    { accessorKey: 'name', header: 'Fiscal year' },
    { accessorKey: 'start_date', header: 'Start date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.start_date) },
    { accessorKey: 'end_date', header: 'End date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.end_date) },
    {
        accessorKey: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) => {
            const fiscalYear = row.original;
            const statusBadge = h(
                Badge,
                { variant: fiscalYear.status === 'open' ? 'success' : 'neutral' },
                { default: () => (fiscalYear.status === 'open' ? 'Open' : 'Closed') },
            );

            if (!isOpenForCorrection(fiscalYear)) {
                return statusBadge;
            }

            return h('div', { class: 'flex flex-wrap items-center gap-1.5' }, [
                statusBadge,
                h(
                    Tooltip,
                    { label: fiscalYear.reopen_reason || 'Reopened for correction' },
                    {
                        default: () =>
                            h(Badge, { variant: 'warning' }, { default: () => `Reopened for correction - ${fiscalYear.reopen_reason ?? ''}` }),
                    },
                ),
            ]);
        },
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) => {
            const buttons = [];
            const fiscalYear = row.original;
            const actionClass =
                'inline-flex h-[26px] items-center gap-1 whitespace-nowrap bg-primary-tint px-2 text-[12px] font-semibold text-primary transition-[filter] duration-150 ease-out hover:brightness-95';
            const actionButton = (tip, text, icon, onClick) =>
                h(
                    Tooltip,
                    { label: tip },
                    {
                        default: () =>
                            h('button', { type: 'button', class: actionClass, 'aria-label': tip, onClick }, [
                                h(icon, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' }),
                                text,
                            ]),
                    },
                );

            if (fiscalYear.status === 'open') {
                buttons.push(actionButton('Close fiscal year', 'Close', Lock, () => openClose(fiscalYear)));
            }

            if (isAdmin.value && fiscalYear.status === 'closed') {
                buttons.push(
                    fiscalYear.archive
                        ? h(
                              Tooltip,
                              { label: 'View archived year' },
                              {
                                  default: () =>
                                      h(
                                          Link,
                                          {
                                              href: `/fiscal-year-archives/${fiscalYear.archive.id}`,
                                              class: actionClass,
                                              'aria-label': 'View archived year',
                                          },
                                          () => [h(Archive, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' }), 'View archive'],
                                      ),
                              },
                          )
                        : actionButton('Archive fiscal year', 'Archive', Archive, () => archiveFiscalYear(fiscalYear)),
                );
            }

            // Reopen/relock - only offered for a closed, unarchived year
            // (matches FiscalYear::reopen()'s own guards), admin-gated in
            // the UI same as archive above; the actual authorization is
            // enforced server-side regardless.
            if (isAdmin.value && fiscalYear.status === 'closed' && !fiscalYear.archive && !isOpenForCorrection(fiscalYear)) {
                buttons.push(actionButton('Reopen for correction', 'Reopen', Unlock, () => openReopen(fiscalYear)));
            }

            if (isAdmin.value && isOpenForCorrection(fiscalYear)) {
                buttons.push(actionButton('Relock fiscal year', 'Relock', Lock, () => relockFiscalYear(fiscalYear)));
            }

            return buttons.length ? h('div', { class: 'flex items-center gap-1.5' }, buttons) : null;
        },
    },
];
</script>

<template>
    <div>
        <PageHeader title="Fiscal Years" description="Your accounting periods. Close a year when it ends to lock its books and carry balances forward; archive a closed year to keep a read-only copy of its ledger.">
            <Button variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New fiscal year
            </Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="fiscalYears" :page-size="10" empty-message="No fiscal years yet. Use New fiscal year to create your first accounting period." />
        </Card>

        <Modal :open="createModalOpen" title="New fiscal year" @update:open="onCreateModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCreate">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. FY 2082/83" required />
                    <p class="mt-1 text-xs text-text-muted">A label for this accounting period, shown on reports and ledgers.</p>
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="start_date" class="mb-1 block text-sm font-semibold text-text-base">Start date (BS) <span class="text-danger">*</span></label>
                    <NepaliDateInput id="start_date" v-model="form.start_date" required />
                    <p v-if="form.errors.start_date" class="mt-1 text-sm text-danger">{{ form.errors.start_date }}</p>
                </div>

                <div>
                    <label for="end_date" class="mb-1 block text-sm font-semibold text-text-base">End date (BS) <span class="text-danger">*</span></label>
                    <NepaliDateInput id="end_date" v-model="form.end_date" required />
                    <p v-if="form.errors.end_date" class="mt-1 text-sm text-danger">{{ form.errors.end_date }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeCreateModal">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="button"
                    :disabled="form.processing"
                    :loading="form.processing"
                    @click="submitCreate"
                >
                    Save fiscal year
                </Button>
            </template>
        </Modal>

        <Modal :open="closeModalOpen" title="Close fiscal year" size="compact" @update:open="onCloseModalOpenChange">
            <div class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    This posts depreciation, the opening and closing stock entries, the year-end closing entries,
                    and carries balances forward into the selected year. A database backup is taken first.
                    Closing locks this year's books, so it cannot be undone casually (an admin can later reopen it for corrections).
                </p>

                <div>
                    <label for="next_fiscal_year_id" class="mb-1 block text-sm font-semibold text-text-base">
                        Next fiscal year <span class="text-danger">*</span>
                    </label>
                    <Select
                        id="next_fiscal_year_id"
                        v-model="closeForm.next_fiscal_year_id"
                        :options="eligibleNextYears"
                        placeholder="Select next fiscal year…"
                    />
                    <p v-if="closeForm.errors.next_fiscal_year_id" class="mt-1 text-sm text-danger">
                        {{ closeForm.errors.next_fiscal_year_id }}
                    </p>
                </div>

                <div v-if="closingEarly">
                    <label for="close_reason" class="mb-1 block text-sm font-semibold text-text-base">
                        Reason for closing early <span class="text-danger">*</span>
                    </label>
                    <Input
                        id="close_reason"
                        v-model="closeForm.reason"
                        type="text"
                        maxlength="500"
                        placeholder="Why is this year being closed before it ends?"
                        required
                    />
                    <p class="mt-1 text-xs text-text-muted">
                        This year has not finished yet. Closing it now freezes a period that can still receive
                        documents, so an admin has to say why.
                    </p>
                    <p v-if="closeForm.errors.reason" class="mt-1 text-sm text-danger">{{ closeForm.errors.reason }}</p>
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeCloseModal">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="button"
                    :loading="closeForm.processing"
                    :disabled="closeForm.processing || !closeForm.next_fiscal_year_id || (closingEarly && !closeForm.reason.trim())"
                    @click="submitClose"
                >
                    Close fiscal year
                </Button>
            </template>
        </Modal>

        <Modal :open="reopenModalOpen" title="Reopen for correction" size="compact" @update:open="onReopenModalOpenChange">
            <div class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    Reopening "{{ reopeningYear?.name }}" lets Purchase, Journal Voucher, and Stock Adjustment
                    postings target it (with a reason) until it's relocked. Sales stay locked to the current
                    open year regardless.
                </p>

                <div>
                    <label for="reopen_reason" class="mb-1 block text-sm font-semibold text-text-base">
                        Reason <span class="text-danger">*</span>
                    </label>
                    <textarea
                        id="reopen_reason"
                        v-model="reopenForm.reason"
                        rows="3"
                        placeholder="Explain why this fiscal year needs to be reopened"
                        required
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none transition-colors duration-150 focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                    ></textarea>
                    <p v-if="reopenForm.errors.reason" class="mt-1 text-sm text-danger">{{ reopenForm.errors.reason }}</p>
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeReopenModal">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="button"
                    :loading="reopenForm.processing"
                    :disabled="reopenForm.processing || !reopenForm.reason.trim()"
                    @click="submitReopen"
                >
                    Reopen for correction
                </Button>
            </template>
        </Modal>
    </div>
</template>
