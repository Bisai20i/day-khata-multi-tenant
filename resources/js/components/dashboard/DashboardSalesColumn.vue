<script setup>
import { Link } from '@inertiajs/vue3';
import { Receipt, ReceiptText, Percent, ScanBarcode } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import { formatMoney } from '@/lib/money.js';

/**
 * The dashboard's right-hand column: today's sales and purchases, this
 * week's tax summary and the POS shortcut. Each card renders only when its
 * figure was sent (see DashboardController::index); amounts are exact
 * decimal strings, formatted, never computed on.
 */
defineProps({
    kpis: { type: Object, required: true },
    taxSummary: { type: [Object, null], default: null },
    canStartPosSale: { type: Boolean, default: false },
});

function formatAmount(amount) {
    return formatMoney(amount ?? '0.00');
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <Card v-if="kpis.sales" variant="panel">
            <div class="flex items-start justify-between">
                <div class="flex size-9 items-center justify-center bg-primary-tint">
                    <Receipt class="size-5 text-primary" />
                </div>
                <span
                    v-if="kpis.sales.thisWeek.count"
                    class="bg-success-bg px-2 py-0.5 text-[11px] font-semibold text-success"
                >
                    {{ kpis.sales.thisWeek.count }} this week
                </span>
            </div>
            <p class="mt-3 text-2xl font-bold text-text-strong">{{ formatAmount(kpis.sales.today.total) }}</p>
            <p class="text-sm text-text-muted">Today's Sales - {{ kpis.sales.today.count }} bills</p>
        </Card>

        <Card v-if="kpis.purchases" variant="panel">
            <div class="flex items-start justify-between">
                <div class="flex size-9 items-center justify-center bg-primary-tint">
                    <ReceiptText class="size-5 text-primary" />
                </div>
                <span
                    v-if="kpis.purchases.thisWeek.count"
                    class="bg-success-bg px-2 py-0.5 text-[11px] font-semibold text-success"
                >
                    {{ kpis.purchases.thisWeek.count }} this week
                </span>
            </div>
            <p class="mt-3 text-2xl font-bold text-text-strong">{{ formatAmount(kpis.purchases.today.total) }}</p>
            <p class="text-sm text-text-muted">Today's Purchases - {{ kpis.purchases.today.count }} bills</p>
        </Card>

        <Card v-if="taxSummary" variant="panel">
            <div class="flex items-start justify-between">
                <div class="flex size-9 items-center justify-center bg-primary-tint">
                    <Percent class="size-5 text-primary" />
                </div>
            </div>
            <p class="mt-3 text-sm font-bold text-text-strong">Tax Summary (This Week)</p>
            <dl class="mt-2 flex flex-col gap-1 text-sm">
                <div class="flex items-center justify-between">
                    <dt class="text-text-muted">Taxable</dt>
                    <dd class="font-semibold text-text-strong">{{ formatAmount(taxSummary.taxable) }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-text-muted">Non-taxable</dt>
                    <dd class="font-semibold text-text-strong">{{ formatAmount(taxSummary.nontaxable) }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-text-muted">VAT</dt>
                    <dd class="font-semibold text-text-strong">{{ formatAmount(taxSummary.vat) }}</dd>
                </div>
            </dl>
        </Card>

        <Card v-if="canStartPosSale" variant="panel">
            <div class="flex size-9 items-center justify-center bg-primary-tint">
                <ScanBarcode class="size-5 text-primary" aria-hidden="true" />
            </div>
            <p class="mt-3 text-sm font-bold text-text-strong">Start a new sale</p>
            <p class="text-xs text-text-muted">Jump straight into POS for a quick walk-in sale.</p>
            <Button :as="Link" href="/pos" variant="primary" tone="purple" class="mt-3 w-full justify-center">
                <ScanBarcode class="size-4" aria-hidden="true" />
                Go to POS
            </Button>
        </Card>
    </div>
</template>
