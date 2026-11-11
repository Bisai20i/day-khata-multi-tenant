<script setup>
import { computed, h, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Power, PowerOff } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Select from '@/components/ui/Select.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import Label from '@/components/ui/Label.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';
import FormField from '@/components/ui/FormField.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { useCrudModal } from '@/composables/useCrudModal';

defineOptions({ layout: AppLayout });

const props = defineProps({
    branches: { type: Array, default: () => [] },
    branchLabel: { type: String, default: 'branch' },
    branchLabels: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();

const LABELS = {
    branch: { singular: 'Branch', plural: 'Branches' },
    site: { singular: 'Site', plural: 'Sites' },
    outlet: { singular: 'Outlet', plural: 'Outlets' },
    project: { singular: 'Project', plural: 'Projects' },
};

const label = computed(() => LABELS[props.branchLabel] ?? LABELS.branch);
const lower = computed(() => label.value.singular.toLowerCase());

useLayoutChrome('Branches');

// Every action redirects back to this page, which Inertia patches in place,
// so the flash is watched rather than read once on mount.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

// A refused deactivation comes back as a validation error listing what to
// clear first; it is long, so it is shown in a panel, not a toast.
const deactivateError = computed(() => page.props.errors?.deactivate ?? '');

const form = useForm({
    name: '',
    code: '',
    series_code: '',
    address: '',
    phone: '',
    email: '',
    pan_number: '',
    default_store_id: null,
    sort_order: 0,
});

const { showModal, editing, addAnother, savedNotice, isDirty, openCreate, openEdit, closeModal, onModalOpenChange, submit } = useCrudModal({
    form,
    url: '/branches',
    formId: 'branch-form',
    fill: (branch) => {
        form.name = branch.name ?? '';
        form.code = branch.code ?? '';
        form.series_code = branch.series_code ?? '';
        form.address = branch.address ?? '';
        form.phone = branch.phone ?? '';
        form.email = branch.email ?? '';
        form.pan_number = branch.pan_number ?? '';
        form.default_store_id = branch.default_store_id ?? null;
        form.sort_order = branch.sort_order ?? 0;
    },
});

const labelForm = useForm({ branch_label: props.branchLabel });

watch(
    () => props.branchLabel,
    (value) => {
        labelForm.branch_label = value;
        labelForm.defaults({ branch_label: value });
    },
);

const labelOptions = computed(() =>
    props.branchLabels.map((value) => ({ value, label: LABELS[value]?.singular ?? value })),
);

function saveLabel() {
    labelForm.put('/branch-label', { preserveScroll: true });
}

async function deactivate(branch) {
    const restricted = branch.restricted_users_count;
    const usersNote = restricted > 0
        ? ` ${restricted} user(s) limited to selected ${label.value.plural.toLowerCase()} lose access to it; anyone limited to this ${lower.value} alone is left with no ${lower.value} to work in.`
        : '';
    const ok = await confirm({
        title: `Deactivate ${branch.name}?`,
        message: `Nobody can record anything in this ${lower.value} until it is reactivated. Its history stays in every report.${usersNote}`,
        tone: 'danger',
        confirmLabel: `Deactivate ${lower.value}`,
    });
    if (!ok) return;
    router.post(
        `/branches/${branch.id}/deactivate`,
        {},
        {
            preserveScroll: true,
            // Re-show the panel even when the refusal text is identical to
            // one dismissed earlier (the watch alone would not fire).
            onStart: () => {
                deactivateErrorOpen.value = true;
            },
        },
    );
}

async function reactivate(branch) {
    const ok = await confirm({
        title: `Reactivate ${branch.name}?`,
        message: `Users with access to this ${lower.value} can record documents in it again.`,
        tone: 'purple',
        confirmLabel: `Reactivate ${lower.value}`,
    });
    if (!ok) return;
    router.post(`/branches/${branch.id}/reactivate`, {}, { preserveScroll: true });
}

function iconButton({ label: text, tone, icon, onClick }) {
    const tones = {
        primary: 'bg-primary-tint text-primary hover:brightness-95',
        danger: 'bg-danger-bg text-danger hover:bg-danger hover:text-white',
        success: 'bg-success-bg-soft text-success hover:brightness-95',
    };
    return h(Tooltip, { label: text }, () =>
        h(
            'button',
            {
                type: 'button',
                class: `flex h-[26px] w-[26px] items-center justify-center transition-[filter,background-color] duration-150 ease-out ${tones[tone]}`,
                'aria-label': text,
                onClick,
            },
            [h(icon, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
        ),
    );
}

const columns = computed(() => [
    {
        id: 'name',
        header: 'Name',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                h('span', { class: 'font-semibold text-text-strong' }, row.original.name),
                row.original.is_main ? h(Badge, { variant: 'warning', pill: true }, () => 'Main') : null,
            ]),
    },
    { accessorKey: 'code', header: 'Code' },
    {
        id: 'stores',
        header: 'Stores',
        numeric: false,
        cell: ({ row }) => {
            const stores = row.original.stores;
            if (!stores.length) return h('span', { class: 'text-text-faint' }, 'No stores');
            const defaultId = row.original.default_store_id;
            return h(
                'span',
                { class: 'text-[13px]' },
                stores.map((store) => (store.id === defaultId ? `${store.name} (default)` : store.name)).join(', '),
            );
        },
    },
    { accessorKey: 'phone', header: 'Phone' },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { variant: row.original.is_active ? 'success' : 'neutral', pill: true }, () =>
                row.original.is_active ? 'Active' : 'Inactive',
            ),
    },
    {
        id: 'actions',
        header: '',
        numeric: false,
        cell: ({ row }) => {
            if (!props.canManage) return null;
            const branch = row.original;
            const actions = [iconButton({ label: `Edit ${branch.name}`, tone: 'primary', icon: Pencil, onClick: () => openEdit(branch) })];
            if (!branch.is_main && branch.is_active) {
                actions.push(iconButton({ label: `Deactivate ${branch.name}`, tone: 'danger', icon: PowerOff, onClick: () => deactivate(branch) }));
            }
            if (!branch.is_active) {
                actions.push(iconButton({ label: `Reactivate ${branch.name}`, tone: 'success', icon: Power, onClick: () => reactivate(branch) }));
            }
            return h('div', { class: 'flex items-center gap-2' }, actions);
        },
    },
]);

// The default store can only be one of the branch's own active stores; a new
// branch owns none yet (assign stores on the Stores page first).
// The currently saved default stays listed even if it has since gone
// inactive, so the Select never shows blank while submitting its id.
const storeOptions = computed(() => [
    { value: null, label: 'None' },
    ...(editing.value?.stores ?? [])
        .filter((store) => store.is_active || store.id === editing.value?.default_store_id)
        .map((store) => ({ value: store.id, label: store.is_active ? store.name : `${store.name} (inactive)` })),
]);
const deactivateErrorOpen = ref(true);

watch(deactivateError, (value) => {
    if (value) deactivateErrorOpen.value = true;
});
</script>

<template>
    <div>
        <PageHeader
            :title="label.plural"
            :description="`Your company's ${label.plural.toLowerCase()}. Every ${lower} shares one set of books, items and parties; each document is filed in one ${lower}.`"
        >
            <Button v-if="canManage" variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New {{ lower }}
            </Button>
        </PageHeader>

        <div
            v-if="deactivateError && deactivateErrorOpen"
            class="mb-4 border-[1.5px] border-danger bg-danger-bg px-4 py-3 text-[13px] text-danger"
            role="alert"
        >
            <div class="flex items-start justify-between gap-3">
                <p>{{ deactivateError }}</p>
                <button type="button" class="shrink-0 text-xs font-semibold underline" @click="deactivateErrorOpen = false">Dismiss</button>
            </div>
        </div>

        <Card variant="panel" class="mb-4 bg-white">
            <p class="text-[13px] text-text-muted">
                Tip: switch on a new {{ lower }} at the start of a fiscal year. Existing history stays where it was recorded. To move a
                business into a new {{ lower }}, create a store under it, transfer the stock, and move any cash, bank or party balance with a
                journal entry. A store's {{ lower }} can only change while the store has never been used.
            </p>
        </Card>

        <Card variant="panel" class="bg-white">
            <DataTable :columns="columns" :data="branches" :page-size="10" :empty-message="`No ${label.plural.toLowerCase()}`" />
        </Card>

        <Card v-if="canManage" variant="panel" class="mt-4 bg-white">
            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="saveLabel">
                <div class="sm:w-64">
                    <div class="mb-1 flex items-center gap-1">
                        <Label for="branch_label">Call them</Label>
                        <InfoTip text="The word used on screens, for example Site for a construction company. Reports and documents use the same word." />
                    </div>
                    <Select id="branch_label" v-model="labelForm.branch_label" :options="labelOptions" />
                    <p v-if="labelForm.errors.branch_label" class="mt-1 text-sm text-danger">{{ labelForm.errors.branch_label }}</p>
                </div>
                <Button variant="secondary" tone="purple" type="submit" :disabled="labelForm.processing || !labelForm.isDirty">
                    {{ labelForm.processing ? 'Saving...' : 'Save label' }}
                </Button>
            </form>
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? `Edit ${lower}` : `New ${lower}`"
            :description="editing ? '' : `A ${lower} is a finance and access unit; its stores hold the stock.`"
            :dirty="isDirty"
            @update:open="onModalOpenChange"
        >
            <form id="branch-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <p v-if="savedNotice" class="border-[1.5px] border-success bg-success-bg-soft px-3 py-2 text-[13px] text-success" role="status">
                    {{ savedNotice }}
                </p>

                <p v-if="editing?.is_main" class="bg-primary-tint px-3 py-2 text-xs text-text-base">
                    This is the Main {{ lower }}. It can be renamed but never deactivated, and existing history recorded before
                    {{ label.plural.toLowerCase() }} were switched on stays in it.
                </p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <FormField v-slot="{ describedBy }" class="sm:col-span-2" label="Name" for="branch-name" required :error="form.errors.name">
                        <Input id="branch-name" v-model="form.name" type="text" placeholder="e.g. Pokhara" :aria-describedby="describedBy" required data-autofocus />
                    </FormField>
                    <FormField
                        v-slot="{ describedBy }"
                        label="Code"
                        for="branch-code"
                        required
                        help="Short unique code, up to 10 letters, digits or hyphens."
                        :error="form.errors.code"
                    >
                        <Input id="branch-code" v-model="form.code" type="text" maxlength="10" placeholder="e.g. PKR" :aria-describedby="describedBy" required />
                    </FormField>
                </div>

                <p class="text-xs text-text-muted">
                    Address, phone, email and PAN print in the header of this {{ lower }}'s invoices, receipts and vouchers
                    in place of the company's. Leave a field blank to print the company's.
                </p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Address" for="branch-address" :error="form.errors.address">
                        <Input id="branch-address" v-model="form.address" type="text" placeholder="e.g. Street, City" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Phone" for="branch-phone" :error="form.errors.phone">
                        <Input id="branch-phone" v-model="form.phone" type="tel" placeholder="e.g. 98XXXXXXXX" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Email" for="branch-email" :error="form.errors.email">
                        <Input id="branch-email" v-model="form.email" type="email" placeholder="branch@example.com" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField
                        v-slot="{ describedBy }"
                        label="PAN on prints"
                        for="branch-pan"
                        help="Leave blank to print the company PAN."
                        :error="form.errors.pan_number"
                    >
                        <Input id="branch-pan" v-model="form.pan_number" type="text" maxlength="20" :aria-describedby="describedBy" />
                    </FormField>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <FormField
                        v-if="editing"
                        label="Default store"
                        for="branch-default-store"
                        help="Documents of this branch start with this store."
                        :error="form.errors.default_store_id"
                    >
                        <Select id="branch-default-store" v-model="form.default_store_id" :options="storeOptions" />
                    </FormField>
                    <FormField
                        v-slot="{ describedBy }"
                        label="Series code"
                        for="branch-series-code"
                        help="Up to 6 letters or digits. Used only if a fiscal year numbers documents per branch."
                        :error="form.errors.series_code"
                    >
                        <Input id="branch-series-code" v-model="form.series_code" type="text" maxlength="6" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Sort order" for="branch-sort-order" :error="form.errors.sort_order">
                        <Input id="branch-sort-order" v-model="form.sort_order" type="number" min="0" max="9999" step="1" :aria-describedby="describedBy" />
                    </FormField>
                </div>
            </form>

            <template #footer>
                <label v-if="!editing" class="mr-auto flex items-center gap-2 text-[13px] text-text-base">
                    <input v-model="addAnother" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    Add another
                </label>
                <Button variant="secondary" tone="neutral" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="branch-form" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : editing ? `Save ${lower}` : `Create ${lower}` }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
