<script setup>
import { computed, h, onMounted, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { BookOpen, Plus, Upload } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Modal from '@/components/ui/Modal.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import RowActions from '@/components/ui/RowActions.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    accountGroups: {
        type: Array,
        default: () => [],
    },
    accountSubgroups: {
        type: Array,
        default: () => [],
    },
    accounts: {
        type: Array,
        default: () => [],
    },
    // Every opening-balance import posted so far, newest first. A re-import
    // reverses whatever is still posted before writing the new batch, so at
    // most one row here is ever 'posted'.
    openingBalanceImports: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { can } = usePermissions();
useLayoutChrome('Accounts');

const { toast } = useToast();
const { confirm } = useConfirm();

onMounted(() => {
    if (page.props.flash?.status) {
        toast({ message: page.props.flash.status, variant: 'success' });
    }
});

const groupOptions = computed(() => props.accountGroups.map((group) => ({ value: group.id, label: group.name })));
const subgroupOptions = computed(() => props.accountSubgroups.map((subgroup) => ({ value: subgroup.id, label: subgroup.name })));

const parentTypeOptions = [
    { value: 'group', label: 'Group' },
    { value: 'subgroup', label: 'Subgroup' },
];

const showModal = ref(false);
const editing = ref(null);
const parentType = ref('group');

const importModalOpen = ref(false);
const importForm = useForm({ file: null, date: '' });
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
    importForm.post('/accounts/opening-balances/import', { forceFormData: true });
}

const form = useForm({
    account_group_id: null,
    account_subgroup_id: null,
    code: '',
    name: '',
    phone: '',
    address: '',
});

watch(parentType, (value) => {
    if (value === 'group') {
        form.account_subgroup_id = null;
    } else {
        form.account_group_id = null;
    }
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    parentType.value = 'group';
    showModal.value = true;
}

function openEdit(account) {
    editing.value = account;
    form.clearErrors();
    form.code = account.code ?? '';
    form.name = account.name;
    form.phone = account.phone ?? '';
    form.address = account.address ?? '';
    form.account_group_id = account.account_group_id;
    form.account_subgroup_id = account.account_subgroup_id;
    parentType.value = account.account_subgroup_id ? 'subgroup' : 'group';
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    form.reset();
    form.clearErrors();
    editing.value = null;
    parentType.value = 'group';
}

function onModalOpenChange(value) {
    if (value) {
        showModal.value = true;
    } else {
        closeModal();
    }
}

function submit() {
    if (parentType.value === 'group') {
        form.account_subgroup_id = null;
    } else {
        form.account_group_id = null;
    }

    if (editing.value) {
        form.put(`/accounts/${editing.value.id}`, {
            onSuccess: () => {
                toast({ message: 'Account updated', variant: 'success' });
                closeModal();
            },
        });
    } else {
        form.post('/accounts', {
            onSuccess: () => {
                toast({ message: 'Account created', variant: 'success' });
                closeModal();
            },
        });
    }
}

async function clearOpeningBalanceImport(entry) {
    if (
        !(await confirm({
            message: 'Clear this opening balance import? Its ledger effect is reversed; the import and its reversal both stay on the ledger.',
            tone: 'danger',
            confirmLabel: 'Clear import',
        }))
    ) {
        return;
    }

    router.post(`/accounts/opening-balances/${entry.id}/reverse`, {}, { preserveScroll: true });
}

async function destroy(account) {
    if (!(await confirm({ message: `Delete account "${account.name}"? This cannot be undone, and it will be refused if the account already has ledger entries.`, tone: 'danger', confirmLabel: 'Delete account' }))) return;
    router.delete(`/accounts/${account.id}`, {
        onSuccess: () => toast({ message: 'Account deleted', variant: 'success' }),
    });
}

// Edit/delete are admin-only server side now (routes/tenant-business.php), so
// the row actions are hidden rather than left to fail with a 403.
const columns = [
    { accessorKey: 'code', header: 'Code' },
    { accessorKey: 'name', header: 'Account name' },
    {
        id: 'parent',
        header: 'Group / subgroup',
        numeric: false,
        cell: ({ row }) => row.original.group?.name ?? row.original.subgroup?.name ?? '-',
    },
    { accessorKey: 'phone', header: 'Phone' },
    { accessorKey: 'address', header: 'Address' },
    {
        id: 'actions',
        header: '',
        numeric: false,
        cell: ({ row }) => {
            const ledgerUrl = `/accounts/${row.original.id}/ledger`;

            if (!can('accounts.edit') && !can('accounts.delete')) {
                return h(Link, { href: ledgerUrl, class: 'text-xs font-semibold text-primary hover:underline' }, { default: () => 'Ledger' });
            }

            return h(
                RowActions,
                {
                    canEdit: can('accounts.edit'),
                    canDelete: can('accounts.delete'),
                    onEdit: () => openEdit(row.original),
                    onDelete: () => destroy(row.original),
                },
                {
                    more: () => [
                        h(DropdownMenuItem, { key: 'Ledger', onSelect: () => router.visit(ledgerUrl) }, () => [
                            h(BookOpen, { class: 'size-3.5', 'aria-hidden': 'true' }),
                            'Ledger',
                        ]),
                    ],
                },
            );
        },
    },
];
</script>

<template>
    <div>
        <PageHeader title="Accounts" description="Your chart of accounts: every ledger that money is recorded against. Open an account's Ledger to see its full history and running balance.">
            <Button v-if="can('accounts.create')" variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New account
            </Button>
        </PageHeader>

        <Card variant="panel" class="bg-white">
            <div v-if="can('opening_balances.import')" class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="ml-auto flex items-center gap-2">
                    <Button variant="secondary" tone="neutral" type="button" @click="openImport">
                        <Upload class="size-4" />
                        Import opening balances
                    </Button>
                </div>
            </div>

            <div v-if="accounts.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <p class="text-sm font-semibold text-text-strong">No accounts yet</p>
                <p v-if="can('accounts.create')" class="text-xs text-text-muted">Add your first account and it will be listed here.</p>
                <Button v-if="can('accounts.create')" variant="primary" tone="purple" @click="openCreate">
                    <Plus class="size-4" />
                    New account
                </Button>
            </div>
            <DataTable v-else :columns="columns" :data="accounts" :page-size="10" empty-message="No accounts" />
        </Card>

        <div v-if="openingBalanceImports.length" class="mt-4">
        <Card variant="panel" class="bg-white" title="Opening balance imports">
            <p v-if="page.props.errors?.opening_balance_import" class="mb-3 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                {{ page.props.errors.opening_balance_import }}
            </p>
            <table class="w-full text-left text-[12px]">
                <thead class="bg-bg-subtle">
                    <tr>
                        <th class="px-2 py-1.5">Date (BS)</th>
                        <th class="px-2 py-1.5">Date (AD)</th>
                        <th class="px-2 py-1.5">Fiscal year</th>
                        <th class="px-2 py-1.5 text-right">Accounts</th>
                        <th class="px-2 py-1.5 text-right">Total</th>
                        <th class="px-2 py-1.5">Status</th>
                        <th class="px-2 py-1.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in openingBalanceImports" :key="entry.id" class="border-t border-border">
                        <td class="px-2 py-1.5">{{ formatBsDate(entry.date) || '-' }}</td>
                        <td class="px-2 py-1.5">{{ entry.date }}</td>
                        <td class="px-2 py-1.5">{{ entry.fiscal_year ?? '-' }}</td>
                        <td class="px-2 py-1.5 text-right">{{ entry.line_count }}</td>
                        <td class="px-2 py-1.5 text-right">{{ formatMoney(entry.total) }}</td>
                        <td class="px-2 py-1.5">{{ entry.status === 'cancelled' ? 'Cleared' : 'In effect' }}</td>
                        <td class="px-2 py-1.5 text-right">
                            <button
                                v-if="can('opening_balances.cancel') && entry.can_clear"
                                type="button"
                                class="text-[12px] font-bold text-danger hover:underline"
                                @click="clearOpeningBalanceImport(entry)"
                            >
                                Clear import
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
        </div>

        <Modal :open="showModal" :title="editing ? 'Edit Account' : 'New Account'" @update:open="onModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <Label for="parent_type">File under</Label>
                        <InfoTip text="Choose whether this account sits directly under an account group or under a subgroup." />
                    </div>
                    <Select id="parent_type" v-model="parentType" :options="parentTypeOptions" />
                </div>

                <div v-if="parentType === 'group'">
                    <Label for="account_group_id" class="mb-1">Account group <span class="text-danger">*</span></Label>
                    <Select
                        id="account_group_id"
                        v-model="form.account_group_id"
                        :options="groupOptions"
                        placeholder="Select account group"
                    />
                    <p v-if="form.errors.account_group_id" class="mt-1 text-sm text-danger">{{ form.errors.account_group_id }}</p>
                </div>

                <div v-else>
                    <Label for="account_subgroup_id" class="mb-1">Account subgroup <span class="text-danger">*</span></Label>
                    <Select
                        id="account_subgroup_id"
                        v-model="form.account_subgroup_id"
                        :options="subgroupOptions"
                        placeholder="Select account subgroup"
                    />
                    <p v-if="form.errors.account_subgroup_id" class="mt-1 text-sm text-danger">{{ form.errors.account_subgroup_id }}</p>
                </div>

                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <Label for="code">Code</Label>
                        <InfoTip text="Optional short reference number. Leave blank to auto-assign." />
                    </div>
                    <Input id="code" v-model="form.code" type="text" placeholder="e.g. 1001" />
                    <p v-if="form.errors.code" class="mt-1 text-sm text-danger">{{ form.errors.code }}</p>
                </div>

                <div>
                    <Label for="name" class="mb-1">Name <span class="text-danger">*</span></Label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. Cash in Hand" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <Label for="phone" class="mb-1">Phone</Label>
                    <Input id="phone" v-model="form.phone" type="text" placeholder="98XXXXXXXX" />
                    <p v-if="form.errors.phone" class="mt-1 text-sm text-danger">{{ form.errors.phone }}</p>
                </div>

                <div>
                    <Label for="address" class="mb-1">Address</Label>
                    <Input id="address" v-model="form.address" type="text" placeholder="e.g. Kathmandu-10" />
                    <p v-if="form.errors.address" class="mt-1 text-sm text-danger">{{ form.errors.address }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="neutral" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" :disabled="form.processing" @click="submit">
                    {{ form.processing ? 'Saving...' : editing ? 'Save changes' : 'Save account' }}
                </Button>
            </template>
        </Modal>

        <Modal :open="importModalOpen" title="Import opening balances" @update:open="onImportModalOpenChange">
            <div v-if="!importResult" class="flex flex-col gap-4">
                <p class="text-[13px] text-text-muted">
                    Download the template, list one account per row with its opening debit or credit (accounts
                    are matched by code, or by name when no code is given), then upload the completed CSV file.
                    All rows are posted together as a single opening-balance journal voucher, so the file's
                    total debit must equal its total credit. If any row can't be resolved, nothing is imported
                    and every problem row is reported below - fix them and re-upload.
                </p>
                <a
                    href="/accounts/opening-balances/template"
                    class="inline-flex w-fit items-center gap-1.5 text-[13px] font-bold text-primary hover:underline"
                >
                    Download CSV template
                </a>
                <div>
                    <Label class="mb-1">Date <span class="text-danger">*</span></Label>
                    <NepaliDateInput v-model="importForm.date" required />
                    <p v-if="importForm.errors.date" class="mt-1 text-sm text-danger">{{ importForm.errors.date }}</p>
                </div>
                <div>
                    <Label for="opening-balance-import-file" class="mb-1">
                        CSV file <span class="text-danger">*</span>
                    </Label>
                    <input
                        id="opening-balance-import-file"
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
                    <template v-if="importResult.skipped.length">
                        No opening balances were imported - {{ importResult.skipped.length }} row(s) had a problem.
                    </template>
                    <template v-else>
                        Imported opening balances for {{ importResult.imported }} account(s).
                    </template>
                </p>
                <div v-if="importResult.skipped.length" class="max-h-64 overflow-auto border-[1.5px] border-border">
                    <table class="w-full text-left text-[12px]">
                        <thead class="bg-bg-subtle">
                            <tr>
                                <th class="px-2 py-1.5">Row</th>
                                <th class="px-2 py-1.5">Account</th>
                                <th class="px-2 py-1.5">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in importResult.skipped" :key="item.row" class="border-t border-border">
                                <td class="px-2 py-1.5">{{ item.row }}</td>
                                <td class="px-2 py-1.5">{{ item.name || '-' }}</td>
                                <td class="px-2 py-1.5">{{ item.reason }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <template #footer>
                <template v-if="!importResult">
                    <Button variant="secondary" tone="neutral" type="button" @click="closeImportModal">Cancel</Button>
                    <Button
                        variant="primary"
                        tone="purple"
                        type="button"
                        :loading="importForm.processing"
                        :disabled="importForm.processing || !importForm.file || !importForm.date"
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
