<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import { navGroups } from '@/lib/nav-items.js';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotal: { type: Object, default: () => ({ value: '0.00', quantities: [] }) },
    stores: { type: Array, default: () => [] },
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

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

// Quantities are a per-base-unit breakdown, never one number: 12 Boxes plus
// 30 Kilograms is not "42". An empty list renders as a dash.
function quantityLabel(quantities) {
    if (!quantities || quantities.length === 0) {
        return '—';
    }

    return quantities.map((entry) => `${formatQuantity(entry.quantity)} ${entry.unit}`.trim()).join(', ');
}
</script>

<template>
    <AppLayout title="Purchase by Category" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Purchase by Category</h2>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">
            {{ rangeLabel }}. quantities are in base units and listed per unit, so two different units are
            never added together.
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

        <Card variant="panel">
            <div v-if="rows.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No purchase activity in this range.
            </div>

            <div v-else class="divide-y divide-border">
                <div class="flex items-center px-1 py-1.5 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <div class="flex-1">Category / Subcategory</div>
                    <div class="w-56 text-right">Quantity</div>
                    <div class="w-32 text-right">Value</div>
                </div>

                <template v-for="category in rows" :key="category.categoryId">
                    <div class="flex items-center px-1 py-1.5 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">{{ category.categoryName }}</div>
                        <div class="w-56 text-right">{{ quantityLabel(category.quantities) }}</div>
                        <div class="w-32 text-right">{{ formatMoney(category.value) }}</div>
                    </div>

                    <div
                        v-for="subcategory in category.subcategories"
                        :key="`sub-${category.categoryId}-${subcategory.subcategoryId ?? 'none'}`"
                        class="flex items-center px-1 py-1 pl-6 text-[13px] text-text-base"
                    >
                        <div class="flex-1">{{ subcategory.subcategoryName }}</div>
                        <div class="w-56 text-right">{{ quantityLabel(subcategory.quantities) }}</div>
                        <div class="w-32 text-right">{{ formatMoney(subcategory.value) }}</div>
                    </div>
                </template>
            </div>

            <div class="mt-3 flex items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[13px] font-bold text-text-strong">
                <div>Grand Total</div>
                <div class="w-56 text-right">{{ quantityLabel(grandTotal.quantities) }}</div>
                <div class="w-32 text-right">{{ formatMoney(grandTotal.value) }}</div>
            </div>
        </Card>
    </AppLayout>
</template>
