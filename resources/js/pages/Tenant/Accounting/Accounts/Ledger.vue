<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    account: {
        type: Object,
        required: true,
    },
    fiscalYears: {
        type: Array,
        default: () => [],
    },
    fiscalYearId: {
        type: [Number, null],
        default: null,
    },
    entries: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
};

function voucherLabel(entry) {
    const type = voucherTypeLabels[entry.voucherType] ?? entry.voucherType;
    return `${type} #${entry.voucherNumber}`;
}

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

function onFiscalYearChange(value) {
    router.get(
        window.location.pathname,
        { fiscal_year_id: value },
        { preserveState: true, preserveScroll: true },
    );
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'voucher',
        header: 'Voucher',
        numeric: false,
        cell: ({ row }) => voucherLabel(row.original),
    },
    {
        accessorKey: 'narration',
        header: 'Narration',
        numeric: false,
        cell: ({ row }) => row.original.narration ?? '—',
    },
    {
        accessorKey: 'debit',
        header: 'Debit',
        cell: ({ row }) => row.original.debit.toFixed(2),
    },
    {
        accessorKey: 'credit',
        header: 'Credit',
        cell: ({ row }) => row.original.credit.toFixed(2),
    },
    {
        accessorKey: 'balance',
        header: 'Running Balance',
        cell: ({ row }) => row.original.balance.toFixed(2),
    },
];
</script>

<template>
    <AppLayout :title="`Ledger — ${account.name}`" :nav-items="navItems">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-text-strong">
                    {{ account.name }} <span class="font-normal text-text-muted">· {{ account.code ?? '—' }}</span>
                </h2>
            </div>
            <div class="w-56" v-if="fiscalYearId !== null">
                <Select :model-value="fiscalYearId" :options="fiscalYearOptions" @update:model-value="onFiscalYearChange" />
            </div>
        </div>

        <Card variant="panel">
            <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No fiscal year has been created yet.
            </p>
            <DataTable v-else :columns="columns" :data="entries" :page-size="25" empty-message="No activity in this fiscal year" />
        </Card>
    </AppLayout>
</template>
