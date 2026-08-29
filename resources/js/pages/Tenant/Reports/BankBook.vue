<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    accountId: { type: [Number, null], default: null },
    entries: { type: Array, default: () => [] },
    openingBalance: { type: Number, default: 0 },
    closingBalance: { type: Number, default: 0 },
    from: { type: String, required: true },
    to: { type: String, required: true },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const from = ref(props.from);
const to = ref(props.to);
const accountId = ref(props.accountId);

const accountOptions = computed(() =>
    props.accounts.map((account) => ({ value: account.id, label: `${account.name} · ${account.code ?? '—'}` })),
);

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, account_id: accountId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
    sale: 'Sale',
    sale_abbreviated: 'Sale (Abbreviated)',
    sale_return: 'Sale Return',
    purchase: 'Purchase',
    purchase_return: 'Purchase Return',
};

function voucherLabel(entry) {
    return `${voucherTypeLabels[entry.voucherType] ?? entry.voucherType} #${entry.voucherNumber}`;
}

function money(value) {
    return Number(value ?? 0).toFixed(2);
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    { id: 'voucher', header: 'Voucher', numeric: false, cell: ({ row }) => voucherLabel(row.original) },
    { accessorKey: 'narration', header: 'Narration', numeric: false, cell: ({ row }) => row.original.narration ?? '—' },
    { accessorKey: 'debit', header: 'Debit', cell: ({ row }) => (row.original.debit > 0 ? money(row.original.debit) : '—') },
    { accessorKey: 'credit', header: 'Credit', cell: ({ row }) => (row.original.credit > 0 ? money(row.original.credit) : '—') },
    { accessorKey: 'balance', header: 'Balance', cell: ({ row }) => money(row.original.balance) },
];
</script>

<template>
    <AppLayout title="Bank Book" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Bank Book</h2>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-64">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Account</label>
                    <Select v-model="accountId" :options="accountOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <Input v-model="from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <Input v-model="to" type="date" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <p v-if="accountId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No accounts exist yet besides Cash In Hand.
            </p>

            <template v-else>
                <div class="mb-3 flex items-center justify-between border-b-[1.5px] border-border pb-3 text-[12.5px]">
                    <div><span class="text-text-muted">Opening Balance:</span> <span class="font-semibold">{{ money(openingBalance) }}</span></div>
                    <div><span class="text-text-muted">Closing Balance:</span> <span class="font-semibold">{{ money(closingBalance) }}</span></div>
                </div>

                <DataTable :columns="columns" :data="entries" :page-size="25" empty-message="No activity in this range" />
            </template>
        </Card>
    </AppLayout>
</template>
