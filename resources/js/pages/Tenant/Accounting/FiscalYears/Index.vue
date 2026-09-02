<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Archive, Lock } from '@lucide/vue';
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
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    fiscalYears: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();

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
function archiveFiscalYear(fiscalYear) {
    if (! confirm(`Archive "${fiscalYear.name}"? This copies its ledger to a read-only snapshot and can only be done once.`)) {
        return;
    }

    router.post(`/fiscal-years/${fiscalYear.id}/archive`);
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'start_date', header: 'Start Date' },
    { accessorKey: 'end_date', header: 'End Date' },
    {
        accessorKey: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(
                Badge,
                { variant: row.original.status === 'open' ? 'success' : 'neutral' },
                { default: () => (row.original.status === 'open' ? 'Open' : 'Closed') },
            ),
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
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="name" v-model="form.name" type="text" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="start_date" class="mb-1 block text-sm font-semibold text-text-base">Start Date</label>
                    <NepaliDateInput id="start_date" v-model="form.start_date" required />
                    <p v-if="form.errors.start_date" class="mt-1 text-sm text-danger">{{ form.errors.start_date }}</p>
                </div>

                <div>
                    <label for="end_date" class="mb-1 block text-sm font-semibold text-text-base">End Date</label>
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
                        Next fiscal year
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
    </AppLayout>
</template>
