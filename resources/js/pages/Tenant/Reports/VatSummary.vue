<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    outputVat: { type: Object, default: () => ({ gross: 0, returns: 0, net: 0 }) },
    inputVat: { type: Object, default: () => ({ gross: 0, returns: 0, net: 0 }) },
    netVatPayable: { type: Number, default: 0 },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, store_id: storeId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

function money(value) {
    return Number(value ?? 0).toFixed(2);
}

const isPayable = computed(() => props.netVatPayable >= 0);
const netVatLabel = computed(() => (isPayable.value ? 'Net VAT Payable' : 'Net VAT Refundable'));
const netVatAmount = computed(() => Math.abs(props.netVatPayable));
</script>

<template>
    <AppLayout title="VAT Summary" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">VAT Summary</h2>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Net VAT payable (or refundable) for the filing period — output VAT and input VAT each net out returns
            posted in this range, regardless of which period the original sale or purchase fell in.
        </p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <div class="mb-4 grid gap-4 md:grid-cols-2">
            <Card variant="panel" title="Output VAT (Sales)">
                <div class="divide-y divide-border text-[13px]">
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Gross output VAT</span>
                        <span class="font-semibold">{{ money(outputVat.gross) }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Less: sales returns VAT</span>
                        <span class="font-semibold">({{ money(outputVat.returns) }})</span>
                    </div>
                    <div class="flex items-center justify-between pt-2 text-text-strong">
                        <span class="font-bold">Net output VAT</span>
                        <span class="font-bold">{{ money(outputVat.net) }}</span>
                    </div>
                </div>
            </Card>

            <Card variant="panel" title="Input VAT (Purchases)">
                <div class="divide-y divide-border text-[13px]">
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Gross input VAT</span>
                        <span class="font-semibold">{{ money(inputVat.gross) }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Less: purchase returns VAT</span>
                        <span class="font-semibold">({{ money(inputVat.returns) }})</span>
                    </div>
                    <div class="flex items-center justify-between pt-2 text-text-strong">
                        <span class="font-bold">Net input VAT</span>
                        <span class="font-bold">{{ money(inputVat.net) }}</span>
                    </div>
                </div>
            </Card>
        </div>

        <Card variant="panel">
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
                    {{ money(netVatAmount) }}
                </div>
            </div>
        </Card>
    </AppLayout>
</template>
