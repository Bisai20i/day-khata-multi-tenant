<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { navGroups } from '@/lib/nav-items.js';
import { formatMoney } from '@/lib/money.js';
import { todayInKathmandu } from '@/lib/format.js';
import Create from './Create.vue';

const props = defineProps({
    fixedAssets: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    pools: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

// Store/dispose/post-depreciation all redirect back to this same route +
// component, which Inertia re-renders in place without an onMounted re-run
// - watch flash status instead (same pattern as Purchases/Index.vue).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

const disposing = ref(null);
const disposeForm = useForm({
    disposal_date: '',
    disposal_amount: '',
    disposal_mode: 'cash',
    bank_account_id: null,
});

const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} — ${account.name}` : account.name,
    })),
);

const disposalModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
];

function openDispose(asset) {
    disposing.value = asset;
    disposeForm.reset();
    disposeForm.clearErrors();
    // todayInKathmandu(), not toISOString(): UTC is 5h45 behind Nepal, so
    // between midnight and 05:45 local the old default dated the disposal
    // yesterday (audit P1, timezone).
    disposeForm.disposal_date = todayInKathmandu();
}

function onDisposeModalOpenChange(value) {
    if (!value) disposing.value = null;
}

/**
 * The proceeds go over the wire as the string the user typed; the server
 * validates `decimal:0,2` and parses with Money, so nothing is rounded
 * silently in the browser (CONTRACTS C1/C8).
 */
function submitDispose() {
    disposeForm.transform((data) => ({
        ...data,
        disposal_amount: String(data.disposal_amount ?? '').trim() === '' ? '0' : String(data.disposal_amount).trim(),
    })).post(`/fixed-assets/${disposing.value.id}/dispose`, {
        preserveScroll: true,
        onSuccess: () => {
            disposing.value = null;
        },
    });
}

async function postDepreciation() {
    const confirmed = await confirm({
        title: 'Post depreciation',
        message: "Post this fiscal year's depreciation for every eligible asset?",
        confirmLabel: 'Post',
    });
    if (!confirmed) {
        return;
    }
    useForm({}).post('/fixed-assets/post-depreciation', { preserveScroll: true });
}

const columns = [
    { accessorKey: 'asset_code', header: 'Code' },
    { accessorKey: 'asset_name', header: 'Name' },
    { accessorKey: 'category', header: 'Pool' },
    {
        id: 'method',
        header: 'Method',
        numeric: false,
        cell: ({ row }) => row.original.depreciation_method.toUpperCase(),
    },
    {
        id: 'cost',
        header: 'Cost',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.cost),
    },
    {
        id: 'accumulated_depreciation',
        header: 'Accum. Depr.',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.accumulated_depreciation),
    },
    {
        id: 'wdv',
        header: 'WDV',
        numeric: true,
        // wdv is appended by the FixedAsset model as an exact string, so the
        // browser never subtracts two money values itself.
        cell: ({ row }) => formatMoney(row.original.wdv),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) => (row.original.status === 'disposed' ? 'Disposed' : 'Active'),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'active'
                ? h(Button, {
                      variant: 'secondary',
                      tone: 'purple',
                      type: 'button',
                      onClick: () => openDispose(row.original),
                  }, () => 'Dispose')
                : '—',
    },
];
</script>

<template>
    <AppLayout title="Fixed Assets" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create :accounts="accounts" :suppliers="suppliers" :pools="pools" @cancel="showCreateForm = false" @posted="showCreateForm = false" />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Fixed Assets</h2>
                <div class="flex items-center gap-2">
                    <Button v-if="isAdmin" variant="secondary" tone="purple" @click="postDepreciation">
                        Post Depreciation
                    </Button>
                    <Button variant="primary" tone="purple" @click="showCreateForm = true">New asset</Button>
                </div>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="fixedAssets" :page-size="10" empty-message="No fixed assets yet" />
            </Card>
        </template>

        <Modal
            :open="!!disposing"
            title="Dispose asset"
            @update:open="onDisposeModalOpenChange"
        >
            <div v-if="disposing" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    Disposing "{{ disposing.asset_name }}" ({{ disposing.asset_code }}) removes it from the books and
                    posts any gain or loss on disposal. This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Disposal Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="disposeForm.disposal_date" required />
                    <p v-if="disposeForm.errors.disposal_date" class="mt-1 text-sm text-danger">{{ disposeForm.errors.disposal_date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Proceeds</label>
                    <Input v-model="disposeForm.disposal_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Settlement Mode <span class="text-danger">*</span></label>
                    <Select
                        :model-value="disposeForm.disposal_mode"
                        :options="disposalModeOptions"
                        @update:model-value="(v) => (disposeForm.disposal_mode = v)"
                    />
                </div>
                <div v-if="disposeForm.disposal_mode === 'bank'">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank Account</label>
                    <Combobox
                        :model-value="disposeForm.bank_account_id"
                        :options="accountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (disposeForm.bank_account_id = v)"
                    />
                    <p v-if="disposeForm.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ disposeForm.errors.bank_account_id }}</p>
                </div>
                <p v-if="disposeForm.errors.disposal_amount" class="text-sm text-danger">{{ disposeForm.errors.disposal_amount }}</p>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="disposing = null">Back</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="disposeForm.processing" @click="submitDispose">
                    Confirm disposal
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
