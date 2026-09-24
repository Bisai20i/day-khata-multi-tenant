<script setup>
import { computed } from 'vue';
import { Archive } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import { formatMoney, sumMoney, isZeroMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    fiscalYear: { type: Object, required: true },
    archive: { type: Object, required: true },
    voucher: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
});

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
    fixed_asset_purchase: 'Fixed Asset Purchase',
    depreciation: 'Depreciation',
    asset_disposal: 'Asset Disposal',
    receipt: 'Receipt',
    payment: 'Payment',
};

const voucherLabel = computed(() => {
    const label = voucherTypeLabels[props.voucher.voucherType] ?? props.voucher.voucherType;

    return `${label} #${props.voucher.voucherNumber}`;
});

useLayoutChrome(() => voucherLabel.value);

const totalDebit = computed(() => sumMoney(props.lines.map((line) => line.debit ?? '0.00')));
const totalCredit = computed(() => sumMoney(props.lines.map((line) => line.credit ?? '0.00')));

function money(value) {
    return formatMoney(value ?? '0.00');
}
</script>

<template>
    <div>
        <PageHeader
            :title="voucherLabel"
            :description="`${formatBsDate(voucher.date)} BS (${voucher.date}) · ${fiscalYear.bsLabel}`"
            :back-href="`/fiscal-year-archives/${archive.id}`"
            :back-label="`Back to ${fiscalYear.name}`"
        >
            <Badge variant="neutral" pill>
                <Archive class="h-3 w-3" aria-hidden="true" />
                Archived snapshot
            </Badge>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <p class="mb-3 text-[13px] text-text-muted">
                This voucher is read from the {{ fiscalYear.name }} cold-storage archive and cannot be edited,
                reversed, or deleted from here.
            </p>
            <dl class="grid grid-cols-1 gap-4 text-[13px] sm:grid-cols-3">
                <div><dt class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Narration</dt><dd class="font-semibold">{{ voucher.narration ?? '-' }}</dd></div>
                <div v-if="voucher.reason"><dt class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Reason</dt><dd class="font-semibold">{{ voucher.reason }}</dd></div>
                <div><dt class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Created by</dt><dd class="font-semibold">{{ voucher.createdByName }}</dd></div>
            </dl>
        </Card>

        <Card variant="panel">
            <div class="flex items-center px-1 pb-1 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                <div class="flex-1">Account</div>
                <div class="w-40">Line note</div>
                <div class="w-28 text-right">Debit</div>
                <div class="w-28 text-right">Credit</div>
            </div>

            <div v-if="lines.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                This voucher has no lines in the archive.
            </div>

            <div v-else class="divide-y divide-border">
                <div v-for="line in lines" :key="line.id" class="flex items-center px-1 py-2 text-[13px] text-text-base">
                    <div class="flex-1">{{ line.accountName }} <span class="text-text-muted">&middot; {{ line.accountCode ?? '-' }}</span></div>
                    <div class="w-40 truncate text-[12.5px] text-text-muted">{{ line.narration ?? '-' }}</div>
                    <div class="w-28 text-right">{{ isZeroMoney(line.debit) ? '-' : money(line.debit) }}</div>
                    <div class="w-28 text-right">{{ isZeroMoney(line.credit) ? '-' : money(line.credit) }}</div>
                </div>
            </div>

            <div v-if="lines.length > 0" class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total debit:</span> <span class="font-semibold tabular-nums">{{ money(totalDebit) }}</span></div>
                <div><span class="text-text-muted">Total credit:</span> <span class="font-semibold tabular-nums">{{ money(totalCredit) }}</span></div>
            </div>
        </Card>
    </div>
</template>
