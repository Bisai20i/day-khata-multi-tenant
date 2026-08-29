<script setup>
import { computed, h, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Eye } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';
import Create from './Create.vue';

const props = defineProps({
    journalVouchers: {
        type: Array,
        default: () => [],
    },
    accounts: {
        type: Array,
        default: () => [],
    },
    fiscalYears: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

// Flash status is watched (not just read on mount) because posting a voucher
// redirects back to this same route + component, which Inertia re-renders
// in place without an onMounted re-run.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

// There is no GET /journal-vouchers/create route on the backend (only index +
// store exist, and route files are off-limits for this task), so the create
// form is not a separate page. Instead it's toggled in-place on this same
// /journal-vouchers route: clicking "New journal voucher" swaps the list view
// for the <Create> component, and posting redirects back to this same index
// route, which Inertia re-renders with fresh props before we flip back to the
// list.
const showCreateForm = ref(false);

const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
};

const voucherTypePrefixes = {
    opening_balance: 'OB',
    journal: 'JV',
    closing_entry: 'CL',
    roll_forward_adjustment: 'RFA',
};

function voucherTypeLabel(type) {
    return voucherTypeLabels[type] ?? type;
}

function voucherLabel(voucher) {
    const prefix = voucherTypePrefixes[voucher.voucher_type] ?? 'JV';
    return `${prefix}-${voucher.voucher_number}`;
}

function voucherAmount(voucher) {
    return voucher.lines.reduce((sum, line) => sum + (Number(line.debit) || 0), 0);
}

const selectedVoucher = ref(null);

function viewVoucher(voucher) {
    selectedVoucher.value = voucher;
}

function onDetailOpenChange(value) {
    if (!value) selectedVoucher.value = null;
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'type',
        header: 'Type',
        numeric: false,
        cell: ({ row }) => voucherTypeLabel(row.original.voucher_type),
    },
    {
        id: 'voucher_number',
        header: 'Voucher #',
        numeric: false,
        cell: ({ row }) => voucherLabel(row.original),
    },
    { accessorKey: 'narration', header: 'Narration' },
    {
        id: 'fiscal_year',
        header: 'Fiscal Year',
        numeric: false,
        cell: ({ row }) => row.original.fiscal_year?.name ?? '—',
    },
    {
        id: 'amount',
        header: 'Amount',
        numeric: true,
        cell: ({ row }) => voucherAmount(row.original).toFixed(2),
    },
    {
        id: 'created_by',
        header: 'Created By',
        numeric: false,
        cell: ({ row }) => row.original.creator?.name ?? '—',
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h(Tooltip, { label: 'View lines' }, () =>
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                        'aria-label': 'View lines',
                        onClick: () => viewVoucher(row.original),
                    },
                    [h(Eye, { class: 'h-[13px] w-[13px]' })],
                ),
            ),
    },
];
</script>

<template>
    <AppLayout title="Journal Vouchers" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create
                :accounts="accounts"
                :fiscal-years="fiscalYears"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Journal Vouchers</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">New journal voucher</Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="journalVouchers" :page-size="10" empty-message="No journal vouchers yet" />
            </Card>
        </template>

        <Modal
            :open="!!selectedVoucher"
            :title="selectedVoucher ? `${voucherLabel(selectedVoucher)} · ${voucherTypeLabel(selectedVoucher.voucher_type)}` : ''"
            @update:open="onDetailOpenChange"
        >
            <div v-if="selectedVoucher" class="flex flex-col gap-4">
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Date</p>
                        <p class="text-text-base">{{ selectedVoucher.date }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Fiscal Year</p>
                        <p class="text-text-base">{{ selectedVoucher.fiscal_year?.name ?? '—' }}</p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Narration</p>
                        <p class="text-text-base">{{ selectedVoucher.narration }}</p>
                    </div>
                    <div v-if="selectedVoucher.reason" class="col-span-2">
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Reason</p>
                        <p class="text-text-base">{{ selectedVoucher.reason }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Created By</p>
                        <p class="text-text-base">{{ selectedVoucher.creator?.name ?? '—' }}</p>
                    </div>
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-soft text-left text-[11px] font-bold tracking-[.6px] text-text-muted uppercase">
                            <th class="pb-2 font-bold">Account</th>
                            <th class="pb-2 text-right font-bold">Debit</th>
                            <th class="pb-2 text-right font-bold">Credit</th>
                            <th class="pb-2 font-bold">Narration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in selectedVoucher.lines" :key="line.id" class="border-b border-border-soft last:border-0">
                            <td class="py-2 text-text-base">
                                {{ line.account?.code ? `${line.account.code} — ${line.account.name}` : line.account?.name }}
                            </td>
                            <td class="py-2 text-right [font-variant-numeric:tabular-nums]">{{ Number(line.debit).toFixed(2) }}</td>
                            <td class="py-2 text-right [font-variant-numeric:tabular-nums]">{{ Number(line.credit).toFixed(2) }}</td>
                            <td class="py-2 text-text-muted">{{ line.narration ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Modal>
    </AppLayout>
</template>
