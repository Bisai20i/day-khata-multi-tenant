<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import { FileSpreadsheet } from '@lucide/vue';
import { formatMoney, compareMoney, subtractMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    outputVat: { type: Object, default: () => ({ gross: '0.00', capital: '0.00', fixedAssetVat: '0.00', cancelled: '0.00', returns: '0.00', net: '0.00' }) },
    inputVat: { type: Object, default: () => ({ gross: '0.00', capital: '0.00', fixedAssetVat: '0.00', cancelled: '0.00', returns: '0.00', net: '0.00' }) },
    netVatPayable: { type: String, default: '0.00' },
    reconciliation: { type: Object, default: () => ({ applicable: false }) },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
});

useLayoutChrome('VAT Summary');

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

const isLoading = ref(false);

const hasActiveFilter = computed(() => storeId.value !== null);

function resetFilters() {
    storeId.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, store_id: storeId.value ?? undefined },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: from.value, to: to.value });
    if (storeId.value) {
        params.set('store_id', storeId.value);
    }

    return `/reports/vat-summary/export?${params.toString()}`;
});

// Exact string comparison, never a float: "is this refundable" must not
// hinge on a rounding artefact.
const isPayable = computed(() => compareMoney(props.netVatPayable, '0.00') >= 0);
const netVatLabel = computed(() => (isPayable.value ? 'Net VAT Payable' : 'Net VAT Refundable'));
const netVatAmount = computed(() =>
    isPayable.value ? props.netVatPayable : subtractMoney('0.00', props.netVatPayable),
);

const reconciles = computed(
    () => props.reconciliation.applicable === true && compareMoney(props.reconciliation.difference, '0.00') === 0,
);
</script>

<template>
    <div>
        <PageHeader title="VAT Summary" description="VAT Summary: output VAT on sales less input VAT on purchases, showing what you owe or can claim back for the period.">
            <Button as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Net VAT payable or refundable for the filing period. Capital sales and capital purchases are
            included; a cancellation and a credit or debit note reduce the period they were recorded in, not
            the period of the original bill, so a filed month never changes.
        </p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From date (BS)</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To date (BS)</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <div class="mb-4 grid gap-4 md:grid-cols-2">
            <Card variant="panel" title="Output VAT (Sales)">
                <div class="divide-y divide-border text-[13px]">
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">VAT on sales</span>
                        <span class="font-semibold">{{ formatMoney(outputVat.gross) }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">VAT on capital sales</span>
                        <span class="font-semibold">{{ formatMoney(outputVat.capital) }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Less: cancelled invoices</span>
                        <span class="font-semibold">({{ formatMoney(outputVat.cancelled) }})</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Less: credit notes</span>
                        <span class="font-semibold">({{ formatMoney(outputVat.returns) }})</span>
                    </div>
                    <div class="flex items-center justify-between pt-2 text-text-strong">
                        <span class="font-bold">Net output VAT</span>
                        <span class="font-bold">{{ formatMoney(outputVat.net) }}</span>
                    </div>
                </div>
            </Card>

            <Card variant="panel" title="Input VAT (Purchases)">
                <div class="divide-y divide-border text-[13px]">
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">VAT on purchases</span>
                        <span class="font-semibold">{{ formatMoney(inputVat.gross) }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">VAT on capital purchases</span>
                        <span class="font-semibold">{{ formatMoney(inputVat.capital) }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5 pl-3">
                        <span class="text-[12px] text-text-muted">of which: fixed-asset lines (already in "VAT on purchases" above)</span>
                        <span class="text-[12px] font-semibold text-text-muted">{{ formatMoney(inputVat.fixedAssetVat) }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Less: cancelled bills</span>
                        <span class="font-semibold">({{ formatMoney(inputVat.cancelled) }})</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Less: debit notes</span>
                        <span class="font-semibold">({{ formatMoney(inputVat.returns) }})</span>
                    </div>
                    <div class="flex items-center justify-between pt-2 text-text-strong">
                        <span class="font-bold">Net input VAT</span>
                        <span class="font-bold">{{ formatMoney(inputVat.net) }}</span>
                    </div>
                </div>
            </Card>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">{{ netVatLabel }}</div>
                    <p class="mt-1 text-[12px] text-text-muted">
                        {{ isPayable ? 'Amount owed to the tax authority for this period.' : 'Refundable or carry-forward credit for this period.' }}
                    </p>
                </div>
                <div
                    class="px-3 py-1.5 text-lg font-bold"
                    :class="isPayable ? 'bg-danger-bg text-danger' : 'bg-success-bg text-success'"
                >
                    {{ formatMoney(netVatAmount) }}
                </div>
            </div>
        </Card>

        <Card variant="panel" title="Ledger reconciliation">
            <div v-if="reconciliation.applicable" class="divide-y divide-border text-[13px]">
                <div class="flex items-center justify-between py-1.5">
                    <span class="text-text-muted">Ledger VAT payable for the period (LIA20 less ASA23)</span>
                    <span class="font-semibold">{{ formatMoney(reconciliation.ledgerNetVatPayable) }}</span>
                </div>
                <div class="flex items-center justify-between py-1.5">
                    <span class="text-text-muted">Report total</span>
                    <span class="font-semibold">{{ formatMoney(reconciliation.reportNetVatPayable) }}</span>
                </div>
                <div class="flex items-center justify-between pt-2 text-text-strong">
                    <span class="font-bold">Difference (must be 0.00)</span>
                    <span class="font-bold" :class="reconciles ? 'text-success' : 'text-danger'">
                        {{ formatMoney(reconciliation.difference) }}
                    </span>
                </div>
            </div>

            <p v-else class="text-[12.5px] text-text-muted">
                The chart of accounts is not split by store, so the ledger comparison only applies to the
                whole business. Clear the store filter to see it.
            </p>

            <p v-if="reconciliation.applicable && !reconciles" class="mt-3 text-[11.5px] text-danger">
                VAT reached Output VAT or Input VAT by a route this report does not model, almost always a
                hand-written journal voucher. Check the Day Book for this period before filing.
            </p>
        </Card>
    </div>
</template>
