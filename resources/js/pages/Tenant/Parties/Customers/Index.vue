<script setup>
import { h, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';

defineOptions({ layout: AppLayout });

defineProps({
    customers: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Customers');

// Flash status is watched (not just read on mount) because create/edit/delete
// all redirect back to this same route + component, which Inertia re-renders
// in place without an onMounted re-run.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const modalOpen = ref(false);
const editing = ref(null);

const importModalOpen = ref(false);
const importForm = useForm({ file: null });
const importResult = ref(null);

// Same flash-watch reasoning as flash.status above: the import submit
// redirects back to this same route + component instead of navigating away.
watch(
    () => page.props.flash?.importResult,
    (result) => {
        if (result) importResult.value = result;
    },
);

function openImport() {
    importForm.reset();
    importForm.clearErrors();
    importResult.value = null;
    importModalOpen.value = true;
}

function closeImportModal() {
    importModalOpen.value = false;
    importForm.reset();
    importForm.clearErrors();
    importResult.value = null;
}

function onImportModalOpenChange(value) {
    if (value) {
        importModalOpen.value = true;
    } else {
        closeImportModal();
    }
}

function onImportFileChange(event) {
    importForm.file = event.target.files[0] ?? null;
}

function submitImport() {
    importForm.post('/customers/import', { forceFormData: true });
}

const form = useForm({
    name: '',
    address: '',
    mobile_no: '',
    email: '',
    tpin: '',
    citizenship: '',
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(customer) {
    editing.value = customer;
    form.clearErrors();
    form.name = customer.name ?? '';
    form.address = customer.address ?? '';
    form.mobile_no = customer.mobile_no ?? '';
    form.email = customer.email ?? '';
    form.tpin = customer.tpin ?? '';
    form.citizenship = customer.citizenship ?? '';
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function onModalOpenChange(value) {
    if (value) {
        modalOpen.value = true;
    } else {
        closeModal();
    }
}

function submit() {
    if (editing.value) {
        form.put(`/customers/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/customers', { onSuccess: closeModal });
    }
}

async function destroyCustomer(customer) {
    if (!(await confirm({ message: `Delete ${customer.name}?`, tone: 'danger', confirmLabel: 'Delete' }))) return;
    router.delete(`/customers/${customer.id}`);
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
    {
        accessorKey: 'mobile_no',
        header: 'Mobile No',
        numeric: false,
        cell: ({ row }) => row.original.mobile_no ?? '—',
    },
    {
        accessorKey: 'email',
        header: 'Email',
        numeric: false,
        cell: ({ row }) => row.original.email ?? '—',
    },
    {
        accessorKey: 'tpin',
        header: 'TPIN',
        numeric: false,
        cell: ({ row }) => row.original.tpin ?? '—',
    },
    {
        id: 'ledger_code',
        header: 'Ledger Code',
        numeric: false,
        cell: ({ row }) => row.original.account?.code ?? '—',
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h(RowActions, {
                onEdit: () => openEdit(row.original),
                onDelete: () => destroyCustomer(row.original),
            }),
    },
];
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Customers</h2>
            <div class="flex items-center gap-2">
                <Button variant="secondary" tone="purple" @click="openImport">Bulk import</Button>
                <Button variant="primary" tone="purple" @click="openCreate">New customer</Button>
            </div>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="customers" :page-size="10" />
        </Card>

        <Modal :open="modalOpen" :title="editing ? 'Edit customer' : 'New customer'" @update:open="onModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. Ram Sharma" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="address" class="mb-1 block text-sm font-semibold text-text-base">Address</label>
                    <Input id="address" v-model="form.address" type="text" placeholder="e.g. Kathmandu-10" />
                    <p v-if="form.errors.address" class="mt-1 text-sm text-danger">{{ form.errors.address }}</p>
                </div>

                <div>
                    <label for="mobile_no" class="mb-1 block text-sm font-semibold text-text-base">Mobile No</label>
                    <Input id="mobile_no" v-model="form.mobile_no" type="text" placeholder="98XXXXXXXX" />
                    <p v-if="form.errors.mobile_no" class="mt-1 text-sm text-danger">{{ form.errors.mobile_no }}</p>
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email</label>
                    <Input id="email" v-model="form.email" type="email" placeholder="name@example.com" />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="tpin" class="mb-1 block text-sm font-semibold text-text-base">TPIN</label>
                    <Input id="tpin" v-model="form.tpin" type="text" placeholder="e.g. 123456789" />
                    <p v-if="form.errors.tpin" class="mt-1 text-sm text-danger">{{ form.errors.tpin }}</p>
                </div>

                <div>
                    <label for="citizenship" class="mb-1 block text-sm font-semibold text-text-base">Citizenship</label>
                    <Input id="citizenship" v-model="form.citizenship" type="text" placeholder="Citizenship number" />
                    <p v-if="form.errors.citizenship" class="mt-1 text-sm text-danger">{{ form.errors.citizenship }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Create customer' }}
                </Button>
            </template>
        </Modal>

        <Modal :open="importModalOpen" title="Bulk import customers" @update:open="onImportModalOpenChange">
            <div v-if="!importResult" class="flex flex-col gap-4">
                <p class="text-[13px] text-text-muted">
                    Download the template, fill in one customer per row, then upload the completed CSV file. Rows
                    with a missing name, an invalid email, or a mobile number already in use (or repeated in the
                    file) are skipped and reported after import.
                </p>
                <a
                    href="/customers/import/template"
                    class="inline-flex w-fit items-center gap-1.5 text-[13px] font-bold text-primary hover:underline"
                >
                    Download CSV template
                </a>
                <div>
                    <label for="customer-import-file" class="mb-1 block text-sm font-semibold text-text-base">
                        CSV file <span class="text-danger">*</span>
                    </label>
                    <input
                        id="customer-import-file"
                        type="file"
                        accept=".csv,text/csv"
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none file:mr-3 file:cursor-pointer file:border-0 file:bg-primary-tint file:px-3 file:py-1.5 file:text-[12px] file:font-bold file:text-primary"
                        @change="onImportFileChange"
                    />
                    <p v-if="importForm.errors.file" class="mt-1 text-sm text-danger">{{ importForm.errors.file }}</p>
                </div>
            </div>

            <div v-else class="flex flex-col gap-4">
                <p class="text-[13px] font-semibold text-text-base">
                    Imported {{ importResult.imported }} of {{ importResult.imported + importResult.skipped.length }} row(s).
                </p>
                <div v-if="importResult.skipped.length" class="max-h-64 overflow-auto border-[1.5px] border-border">
                    <table class="w-full text-left text-[12px]">
                        <thead class="bg-bg-subtle">
                            <tr>
                                <th class="px-2 py-1.5">Row</th>
                                <th class="px-2 py-1.5">Name</th>
                                <th class="px-2 py-1.5">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in importResult.skipped" :key="item.row" class="border-t border-border">
                                <td class="px-2 py-1.5">{{ item.row }}</td>
                                <td class="px-2 py-1.5">{{ item.name || '—' }}</td>
                                <td class="px-2 py-1.5">{{ item.reason }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <template #footer>
                <template v-if="!importResult">
                    <Button variant="secondary" tone="purple" type="button" @click="closeImportModal">Cancel</Button>
                    <Button
                        variant="primary"
                        tone="purple"
                        type="button"
                        :loading="importForm.processing"
                        :disabled="importForm.processing || !importForm.file"
                        @click="submitImport"
                    >
                        Import
                    </Button>
                </template>
                <template v-else>
                    <Button variant="primary" tone="purple" type="button" @click="closeImportModal">Done</Button>
                </template>
            </template>
        </Modal>
    </div>
</template>
