<script setup>
import { Search } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { formatMoney } from '@/lib/money';

defineProps({
    sales: { type: Object, required: true },
    saleRows: { type: Array, default: () => [] },
    searchTerm: { type: String, default: '' },
    searching: { type: Boolean, default: false },
});

defineEmits(['update:searchTerm', 'search', 'page', 'pick']);
</script>

<template>
    <div>
        <label class="mb-1 block text-sm font-semibold text-text-base">Original invoice <span class="text-danger">*</span></label>
        <div class="mb-3 flex items-end gap-2">
            <Input
                :model-value="searchTerm"
                type="text"
                placeholder="Invoice number, sale number or customer name"
                @update:model-value="(value) => $emit('update:searchTerm', value)"
                @keydown.enter.prevent="$emit('search')"
            />
            <Button variant="primary" tone="purple" type="button" :loading="searching" @click="$emit('search')">
                <Search class="size-4" />
                Search
            </Button>
        </div>

        <div class="grid grid-cols-[130px_130px_1fr_130px_90px] gap-2 border-b-[1.5px] border-border pb-1 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
            <span>Invoice</span>
            <span>Date</span>
            <span>Customer</span>
            <span class="text-right">Total</span>
            <span></span>
        </div>
        <div
            v-for="sale in saleRows"
            :key="sale.id"
            class="grid grid-cols-[130px_130px_1fr_130px_90px] items-center gap-2 border-b border-border py-1.5 text-sm"
        >
            <span class="font-semibold text-text-strong">{{ sale.invoice_number ?? `#${sale.id}` }}</span>
            <span class="text-text-muted">{{ sale.date }}</span>
            <span class="text-text-base">{{ sale.customer ?? '-' }}</span>
            <span class="text-right text-text-base">{{ formatMoney(sale.total) }}</span>
            <Button variant="secondary" tone="purple" type="button" @click="$emit('pick', sale)">Select</Button>
        </div>
        <p v-if="saleRows.length === 0" class="py-4 text-center text-sm text-text-muted">No posted sales match that search.</p>

        <div v-if="saleRows.length > 0" class="mt-3 flex items-center justify-between gap-3">
            <p class="text-xs text-text-muted">Page {{ sales.current_page }} of {{ sales.last_page }} ({{ sales.total }} sales)</p>
            <div class="flex items-center gap-2">
                <Button
                    variant="secondary"
                    tone="purple"
                    type="button"
                    :disabled="!sales.prev_page_url"
                    @click="$emit('page', sales.current_page - 1)"
                >
                    Previous
                </Button>
                <Button
                    variant="secondary"
                    tone="purple"
                    type="button"
                    :disabled="!sales.next_page_url"
                    @click="$emit('page', sales.current_page + 1)"
                >
                    Next
                </Button>
            </div>
        </div>
    </div>
</template>
