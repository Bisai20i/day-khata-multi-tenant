<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import { formatMoney, isZeroMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    vouchers: { type: Array, default: () => [] },
    totalDebit: { type: String, default: '0.00' },
    totalCredit: { type: String, default: '0.00' },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    voucherType: { type: [String, null], default: null },
    voucherTypeOptions: { type: Array, default: () => [] },
});

useLayoutChrome('Day Book');

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

const fiscalYear = ref(props.fiscalYearId);
const from = ref(props.from);
const to = ref(props.to);
const voucherType = ref(props.voucherType ?? '');

// Kept per page rather than shared, matching this app's existing
// per-page-file convention (mem.md gotcha #5).
const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
    reversal: 'Reversal',
    sale: 'Sale',
    sale_abbreviated: 'Sale (Abbreviated)',
    sale_pan: 'Sale (PAN)',
    sale_return: 'Sale Return',
    purchase: 'Purchase',
    purchase_return: 'Purchase Return',
    capital_sale: 'Capital Sale',
    capital_purchase: 'Capital Purchase',
    fixed_asset_purchase: 'Fixed Asset Purchase',
    depreciation: 'Depreciation',
    asset_disposal: 'Asset Disposal',
    receipt: 'Receipt',
    payment: 'Payment',
    cash_receipt: 'Cash Receipt',
    cash_payment: 'Cash Payment',
    bank_receipt: 'Bank Receipt',
    bank_payment: 'Bank Payment',
    contra: 'Contra',
};

// See CashBook.vue: a book is only ever read inside one fiscal year.
watch(fiscalYear, (value) => {
    const chosen = props.fiscalYears.find((year) => year.id === value);
    from.value = chosen?.startDate ?? null;
    to.value = chosen?.endDate ?? null;
    applyFilter();
});

const voucherTypeFilterOptions = computed(() => [
    { value: '', label: 'All types' },
    ...props.voucherTypeOptions.map((type) => ({ value: type, label: voucherTypeLabels[type] ?? type })),
]);

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            fiscal_year_id: fiscalYear.value ?? undefined,
            from: from.value ?? undefined,
            to: to.value ?? undefined,
            voucher_type: voucherType.value || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function printUrl() {
    const params = new URLSearchParams({
        fiscal_year_id: fiscalYear.value ?? '',
        from: from.value ?? '',
        to: to.value ?? '',
        voucher_type: voucherType.value ?? '',
    });
    return `/reports/day-book/print?${params.toString()}`;
}

function exportUrl() {
    const params = new URLSearchParams({
        fiscal_year_id: fiscalYear.value ?? '',
        from: from.value ?? '',
        to: to.value ?? '',
        voucher_type: voucherType.value ?? '',
    });
    return `/reports/day-book/export?${params.toString()}`;
}

function voucherLabel(voucher) {
    return `${voucherTypeLabels[voucher.voucherType] ?? voucher.voucherType} #${voucher.voucherNumber}`;
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Day Book</h2>
            <div v-if="fiscalYearId !== null" class="flex items-center gap-2">
                <a :href="printUrl()" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print</Button></a>
                <a :href="exportUrl()"><Button variant="secondary" tone="purple">Export</Button></a>
            </div>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Fiscal Year</label>
                    <Select v-model="fiscalYear" :options="fiscalYearOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Voucher Type</label>
                    <Select v-model="voucherType" :options="voucherTypeFilterOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No fiscal year has been created yet.
            </p>

            <p v-else-if="vouchers.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No vouchers posted in this range.
            </p>

            <div v-else class="divide-y divide-border">
                <div v-for="voucher in vouchers" :key="`${voucher.voucherType}-${voucher.voucherNumber}-${voucher.date}`" class="py-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 px-1 pb-1">
                        <div class="text-[13px] font-bold text-text-strong">
                            {{ formatBsDate(voucher.date) }} <span class="font-normal text-text-muted">({{ voucher.date }})</span> ·
                            {{ voucherLabel(voucher) }}
                        </div>
                        <div class="text-[12.5px] text-text-muted">{{ voucher.narration ?? '—' }}</div>
                    </div>

                    <div class="flex items-center px-1 pb-1 pl-4 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <div class="flex-1">Account</div>
                        <div class="w-40 text-text-muted normal-case">Narration</div>
                        <div class="w-28 text-right">Debit</div>
                        <div class="w-28 text-right">Credit</div>
                    </div>

                    <div
                        v-for="(line, index) in voucher.lines"
                        :key="index"
                        class="flex items-center px-1 py-1 pl-4 text-[13px] text-text-base"
                    >
                        <div class="flex-1">{{ line.accountName }} <span class="text-text-muted">· {{ line.accountCode ?? '—' }}</span></div>
                        <div class="w-40 truncate text-[12.5px] text-text-muted">{{ line.narration ?? '—' }}</div>
                        <div class="w-28 text-right">{{ isZeroMoney(line.debit) ? '—' : formatMoney(line.debit) }}</div>
                        <div class="w-28 text-right">{{ isZeroMoney(line.credit) ? '—' : formatMoney(line.credit) }}</div>
                    </div>
                </div>
            </div>

            <div v-if="vouchers.length > 0" class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total Debit:</span> <span class="font-semibold">{{ formatMoney(totalDebit) }}</span></div>
                <div><span class="text-text-muted">Total Credit:</span> <span class="font-semibold">{{ formatMoney(totalCredit) }}</span></div>
            </div>
        </Card>
    </div>
</template>
