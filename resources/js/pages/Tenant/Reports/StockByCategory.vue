<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotalValuation: { type: Number, default: 0 },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const asOf = ref(props.asOf);

function applyFilter() {
    router.get(window.location.pathname, { as_of: asOf.value }, { preserveState: true, preserveScroll: true });
}

function qty(value) {
    return Number(value ?? 0).toFixed(4);
}

function money(value) {
    return Number(value ?? 0).toFixed(2);
}
</script>

<template>
    <AppLayout title="Stock by Category" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Stock by Category</h2>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">As of</label>
                    <Input v-model="asOf" type="date" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <div v-if="rows.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No stockable item categories found.
            </div>

            <div v-else class="divide-y divide-border">
                <div class="flex items-center px-1 py-1.5 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <div class="flex-1">Category / Subcategory</div>
                    <div class="w-28 text-right">Quantity</div>
                    <div class="w-28 text-right">Avg Cost</div>
                    <div class="w-32 text-right">Valuation</div>
                </div>

                <template v-for="category in rows" :key="category.categoryId">
                    <div class="flex items-center px-1 py-1.5 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">{{ category.categoryName }}</div>
                        <div class="w-28 text-right">{{ qty(category.quantity) }}</div>
                        <div class="w-28 text-right">{{ money(category.avgCost) }}</div>
                        <div class="w-32 text-right">{{ money(category.valuation) }}</div>
                    </div>

                    <div
                        v-for="subcategory in category.subcategories"
                        :key="`sub-${category.categoryId}-${subcategory.subcategoryId ?? 'none'}`"
                        class="flex items-center px-1 py-1 pl-6 text-[13px] text-text-base"
                    >
                        <div class="flex-1">{{ subcategory.subcategoryName }}</div>
                        <div class="w-28 text-right">{{ qty(subcategory.quantity) }}</div>
                        <div class="w-28 text-right">{{ money(subcategory.avgCost) }}</div>
                        <div class="w-32 text-right">{{ money(subcategory.valuation) }}</div>
                    </div>
                </template>
            </div>

            <div class="mt-3 flex items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[13px] font-bold text-text-strong">
                <div>Grand Total Valuation</div>
                <div class="w-32 text-right">{{ money(grandTotalValuation) }}</div>
            </div>
        </Card>
    </AppLayout>
</template>
