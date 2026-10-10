<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { formatMoney } from '@/lib/money.js';
import { todayInKathmandu } from '@/lib/format.js';
import Create from './Create.vue';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const { hasOpenFiscalYear } = useOpenFiscalYear();

const props = defineProps({
    fixedAssets: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    pools: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Fixed Assets');

// UI mirrors of routes/tenant-fixed-assets.php: fixed_assets.create adds
// assets, fixed_assets.manage disposes and runs depreciation.
const { can } = usePermissions();
const canCreateAsset = computed(() => can('fixed_assets.create'));
const canManageAssets = computed(() => can('fixed_assets.manage'));

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
        label: account.code ? `${account.code} - ${account.name}` : account.name,
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
        title: 'Post depreciation?',
        message: "This posts this fiscal year's depreciation to the books for every eligible asset. Each asset's accumulated depreciation and value will change.",
        confirmLabel: 'Post depreciation',
    });
    if (!confirmed) {
        return;
    }
    useForm({}).post('/fixed-assets/post-depreciation', { preserveScroll: true });
}

const columns = [
    { accessorKey: 'asset_code', header: 'Code' },
    { accessorKey: 'asset_name', header: 'Name' },
    { accessorKey: 'category', header: 'Depreciation pool' },
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
        header: 'Accumulated depreciation',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.accumulated_depreciation),
    },
    {
        id: 'wdv',
        header: 'Book value (WDV)',
        numeric: true,
        // wdv is appended by the FixedAsset model as an exact string, so the
        // browser never subtracts two money values itself.
        cell: ({ row }) => formatMoney(row.original.wdv),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { variant: row.original.status === 'disposed' ? 'neutral' : 'success', pill: true }, () =>
                row.original.status === 'disposed' ? 'Disposed' : 'Active',
            ),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'active' && canManageAssets.value
                ? h(Button, {
                      variant: 'secondary',
                      tone: 'neutral',
                      type: 'button',
                      onClick: () => openDispose(row.original),
                      'aria-label': `Dispose ${row.original.asset_name}`,
                  }, () => 'Dispose')
                : '-',
    },
];
</script>

<template>
    <div>
        <template v-if="showCreateForm">
            <Create :accounts="accounts" :suppliers="suppliers" :pools="pools" @cancel="showCreateForm = false" @posted="showCreateForm = false" />
        </template>

        <template v-else>
            <PageHeader title="Fixed Assets" description="Long-lived assets and their depreciation.">
                <Button v-if="canManageAssets && hasOpenFiscalYear" variant="secondary" tone="neutral" @click="postDepreciation">
                    Post depreciation
                </Button>
                <Button v-if="canCreateAsset && hasOpenFiscalYear" variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" />
                    New asset
                </Button>
            </PageHeader>

            <Card variant="panel" class="bg-white">
                <div v-if="fixedAssets.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <p class="text-sm font-semibold text-text-strong">No fixed assets yet</p>
                    <p class="text-xs text-text-muted">Add equipment, vehicles or property to track their value and depreciation.</p>
                    <Button v-if="canCreateAsset && hasOpenFiscalYear" variant="primary" tone="purple" @click="showCreateForm = true">
                        <Plus class="size-4" />
                        New asset
                    </Button>
                </div>
                <DataTable v-else :columns="columns" :data="fixedAssets" :page-size="10" empty-message="No fixed assets" />
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
                    <Label class="mb-1">Disposal Date <span class="text-danger">*</span></Label>
                    <NepaliDateInput v-model="disposeForm.disposal_date" required />
                    <p v-if="disposeForm.errors.disposal_date" class="mt-1 text-sm text-danger">{{ disposeForm.errors.disposal_date }}</p>
                </div>
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <Label>Proceeds</Label>
                        <InfoTip text="Amount received from selling the asset. Leave blank if scrapped for nothing." />
                    </div>
                    <Input v-model="disposeForm.disposal_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div>
                    <Label class="mb-1">Settlement Mode <span class="text-danger">*</span></Label>
                    <Select
                        :model-value="disposeForm.disposal_mode"
                        :options="disposalModeOptions"
                        @update:model-value="(v) => (disposeForm.disposal_mode = v)"
                    />
                </div>
                <div v-if="disposeForm.disposal_mode === 'bank'">
                    <Label class="mb-1">Bank Account</Label>
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
                <Button variant="secondary" tone="neutral" type="button" @click="disposing = null">Back</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="disposeForm.processing" @click="submitDispose">
                    {{ disposeForm.processing ? 'Disposing...' : 'Confirm disposal' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
