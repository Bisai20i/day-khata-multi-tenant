<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Archive, Lock, Unlock } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
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
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    fiscalYears: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const navItems = computed(() => navGroups(isAdmin.value));

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
        message: `Archive "${fiscalYear.name}"? This copies its ledger to a read-only snapshot and can only be done once.`,
        confirmLabel: 'Archive',
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
        message: `Relock "${fiscalYear.name}"? Purchase, Journal Voucher, and Stock Adjustment postings will no longer be able to target it.`,
        confirmLabel: 'Relock',
    });
    if (!confirmed) {
        return;
    }

    router.post(`/fiscal-years/${fiscalYear.id}/lock`);
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'start_date', header: 'Start Date' },
    { accessorKey: 'end_date', header: 'End Date' },
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
                            h(Badge, { variant: 'warning' }, { default: () => `Reopened for correction — ${fiscalYear.reopen_reason ?? ''}` }),
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

            if (fiscalYear.status === 'open') {
                buttons.push(
                    h(
                        Tooltip,
                        { label: 'Close fiscal year' },
                        {
                            default: () =>
                                h(
                                    'button',
                                    {
                                        type: 'button',
                                        class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                                        'aria-label': 'Close fiscal year',
                                        onClick: () => openClose(fiscalYear),
                                    },
                                    [h(Lock, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                                ),
                        },
                    ),
                );
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
                                              class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                                              'aria-label': 'View archived year',
                                          },
                                          [h(Archive, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                                      ),
                              },
                          )
                        : h(
                              Tooltip,
                              { label: 'Archive fiscal year' },
                              {
                                  default: () =>
                                      h(
                                          'button',
                                          {
                                              type: 'button',
                                              class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                                              'aria-label': 'Archive fiscal year',
                                              onClick: () => archiveFiscalYear(fiscalYear),
                                          },
                                          [h(Archive, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                                      ),
                              },
                          ),
                );
            }

            // Reopen/relock - only offered for a closed, unarchived year
            // (matches FiscalYear::reopen()'s own guards), admin-gated in
            // the UI same as archive above; the actual authorization is
            // enforced server-side regardless.
            if (isAdmin.value && fiscalYear.status === 'closed' && !fiscalYear.archive && !isOpenForCorrection(fiscalYear)) {
                buttons.push(
                    h(
                        Tooltip,
                        { label: 'Reopen for correction' },
                        {
                            default: () =>
                                h(
                                    'button',
                                    {
                                        type: 'button',
                                        class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                                        'aria-label': 'Reopen for correction',
                                        onClick: () => openReopen(fiscalYear),
                                    },
                                    [h(Unlock, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                                ),
                        },
                    ),
                );
            }

            if (isAdmin.value && isOpenForCorrection(fiscalYear)) {
                buttons.push(
                    h(
                        Tooltip,
                        { label: 'Relock fiscal year' },
                        {
                            default: () =>
                                h(
                                    'button',
                                    {
                                        type: 'button',
                                        class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                                        'aria-label': 'Relock fiscal year',
                                        onClick: () => relockFiscalYear(fiscalYear),
                                    },
                                    [h(Lock, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                                ),
                        },
                    ),
                );
            }

            return buttons.length ? h('div', { class: 'flex items-center gap-1.5' }, buttons) : null;
        },
    },
];
</script>

<template>
    <AppLayout title="Fiscal Years" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Fiscal Years</h2>
            <Button variant="primary" tone="purple" @click="openCreate">New fiscal year</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="fiscalYears" :page-size="10" empty-message="No fiscal years yet" />
        </Card>

        <Modal :open="createModalOpen" title="New fiscal year" @update:open="onCreateModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCreate">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. FY 2082/83" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="start_date" class="mb-1 block text-sm font-semibold text-text-base">Start Date <span class="text-danger">*</span></label>
                    <NepaliDateInput id="start_date" v-model="form.start_date" required />
                    <p v-if="form.errors.start_date" class="mt-1 text-sm text-danger">{{ form.errors.start_date }}</p>
                </div>

                <div>
                    <label for="end_date" class="mb-1 block text-sm font-semibold text-text-base">End Date <span class="text-danger">*</span></label>
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
                    @click="submitCreate"
                >
                    Create fiscal year
                </Button>
            </template>
        </Modal>

        <Modal :open="closeModalOpen" title="Close fiscal year" size="compact" @update:open="onCloseModalOpenChange">
            <div class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    This will run the year-end closing entries and carry balances forward into the selected year.
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
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeCloseModal">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="button"
                    :disabled="closeForm.processing || !closeForm.next_fiscal_year_id"
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
                    :disabled="reopenForm.processing || !reopenForm.reason.trim()"
                    @click="submitReopen"
                >
                    Reopen for correction
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
