<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Archive, ArrowLeft } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    fiscalYear: { type: Object, required: true },
    archive: { type: Object, required: true },
    voucher: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

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

const totalDebit = computed(() => props.lines.reduce((sum, line) => sum + Number(line.debit ?? 0), 0));
const totalCredit = computed(() => props.lines.reduce((sum, line) => sum + Number(line.credit ?? 0), 0));

function money(value) {
    return Number(value ?? 0).toFixed(2);
}
</script>

<template>
    <AppLayout :title="voucherLabel" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <Link
                    :href="`/fiscal-year-archives/${archive.id}`"
                    class="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-text-muted hover:text-primary"
                >
                    <ArrowLeft class="h-3 w-3" aria-hidden="true" />
                    Back to {{ fiscalYear.name }}
                </Link>
                <h2 class="text-base font-bold text-text-strong">{{ voucherLabel }}</h2>
                <p class="text-xs text-text-muted">{{ voucher.date }} &middot; {{ fiscalYear.bsLabel }}</p>
            </div>
            <Badge variant="neutral" pill>
                <Archive class="h-3 w-3" aria-hidden="true" />
                Archived Snapshot
            </Badge>
        </div>

        <Card variant="panel" class="mb-4">
            <p class="mb-3 text-[12.5px] text-text-muted">
                This voucher is read from the {{ fiscalYear.name }} cold-storage archive and cannot be edited,
                reversed, or deleted from here.
            </p>
            <div class="flex flex-wrap gap-x-8 gap-y-2 text-[12.5px]">
                <div><span class="text-text-muted">Narration:</span> <span class="font-semibold">{{ voucher.narration ?? '—' }}</span></div>
                <div v-if="voucher.reason"><span class="text-text-muted">Reason:</span> <span class="font-semibold">{{ voucher.reason }}</span></div>
                <div><span class="text-text-muted">Created by:</span> <span class="font-semibold">{{ voucher.createdByName }}</span></div>
            </div>
        </Card>

        <Card variant="panel">
            <div class="flex items-center px-1 pb-1 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                <div class="flex-1">Account</div>
                <div class="w-40 normal-case">Narration</div>
                <div class="w-28 text-right">Debit</div>
                <div class="w-28 text-right">Credit</div>
            </div>

            <div v-if="lines.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                This voucher has no lines in the archive.
            </div>

            <div v-else class="divide-y divide-border">
                <div v-for="line in lines" :key="line.id" class="flex items-center px-1 py-2 text-[13px] text-text-base">
                    <div class="flex-1">{{ line.accountName }} <span class="text-text-muted">&middot; {{ line.accountCode ?? '—' }}</span></div>
                    <div class="w-40 truncate text-[12.5px] text-text-muted">{{ line.narration ?? '—' }}</div>
                    <div class="w-28 text-right">{{ line.debit > 0 ? money(line.debit) : '—' }}</div>
                    <div class="w-28 text-right">{{ line.credit > 0 ? money(line.credit) : '—' }}</div>
                </div>
            </div>

            <div v-if="lines.length > 0" class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total Debit:</span> <span class="font-semibold">{{ money(totalDebit) }}</span></div>
                <div><span class="text-text-muted">Total Credit:</span> <span class="font-semibold">{{ money(totalCredit) }}</span></div>
            </div>
        </Card>
    </AppLayout>
</template>
