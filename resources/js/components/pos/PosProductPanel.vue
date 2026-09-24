<script setup>
import { Package, ScanBarcode } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import { formatQuantity, formatRate, parseQuantity } from '@/lib/money';
import { compareQuantity, toScaledQuantity } from '@/lib/quantity';

/**
 * POS left panel: product search + barcode inputs, category chips and the item
 * tile grid. Filtering and add-to-cart logic stay in Sales/Pos.vue; this
 * component renders and emits. The two input wrapper elements are handed back
 * through `search-wrapper` / `barcode-wrapper` (function refs) so
 * usePosShortcuts' focus refs keep working unchanged.
 */
const props = defineProps({
    searchQuery: { type: String, default: '' },
    barcodeQuery: { type: String, default: '' },
    activeCategoryId: { type: [Number, String], default: null },
    categories: { type: Array, default: () => [] },
    filteredItems: { type: Array, default: () => [] },
    visibleItems: { type: Array, default: () => [] },
    tileCap: { type: Number, required: true },
    lines: { type: Array, default: () => [] },
    isOutOfStock: { type: Function, required: true },
});

const emit = defineEmits([
    'update:searchQuery',
    'update:barcodeQuery',
    'select-category',
    'search-keydown',
    'barcode-submit',
    'add-item',
    'search-wrapper',
    'barcode-wrapper',
]);

const bindSearchWrapper = (element) => emit('search-wrapper', element);
const bindBarcodeWrapper = (element) => emit('barcode-wrapper', element);

function quantityInCart(itemId) {
    const line = props.lines.find((l) => l.item_id === itemId);

    return line ? (toScaledQuantity(line.quantity) === null ? '0.0000' : parseQuantity(line.quantity || '0').value) : '0.0000';
}

function hasQuantityInCart(itemId) {
    return compareQuantity(quantityInCart(itemId), '0') > 0;
}
</script>

<template>
    <div class="pos-left flex min-w-0 shrink-0 flex-col gap-3 p-3 lg:h-full lg:min-h-0 lg:shrink lg:overflow-hidden">
        <div class="flex shrink-0 gap-2">
            <div :ref="bindSearchWrapper" class="relative min-w-0 flex-1">
                <Input
                    :model-value="searchQuery"
                    type="text"
                    placeholder="Search products… (F2)"
                    aria-label="Search products (F2)"
                    :icon="ScanBarcode"
                    class="pr-9"
                    @update:model-value="(v) => emit('update:searchQuery', v)"
                    @keydown="(event) => emit('search-keydown', event)"
                />
                <kbd class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 font-mono text-[10px] font-normal text-text-faint">F2</kbd>
            </div>
            <div :ref="bindBarcodeWrapper" class="relative w-[155px] shrink-0">
                <Input
                    :model-value="barcodeQuery"
                    type="text"
                    placeholder="Barcode… (F7)"
                    aria-label="Scan a barcode (F7)"
                    class="pr-9"
                    @update:model-value="(v) => emit('update:barcodeQuery', v)"
                    @keydown.enter.prevent="emit('barcode-submit')"
                    @change="emit('barcode-submit')"
                />
                <kbd class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 font-mono text-[10px] font-normal text-text-faint">F7</kbd>
            </div>
        </div>

        <div v-if="categories.length" class="flex shrink-0 gap-1.5 overflow-x-auto pb-1">
            <button
                type="button"
                class="shrink-0 cursor-pointer border-[1.5px] px-2.5 py-1 text-xs font-bold whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                :class="
                    activeCategoryId === null
                        ? 'border-primary bg-primary-tint text-primary'
                        : 'border-border bg-bg-subtle text-text-muted hover:text-text-base'
                "
                @click="emit('select-category', null)"
            >
                All
            </button>
            <button
                v-for="category in categories"
                :key="category.id"
                type="button"
                class="shrink-0 cursor-pointer border-[1.5px] px-2.5 py-1 text-xs font-bold whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                :class="
                    activeCategoryId === category.id
                        ? 'border-primary bg-primary-tint text-primary'
                        : 'border-border bg-bg-subtle text-text-muted hover:text-text-base'
                "
                @click="emit('select-category', category.id)"
            >
                {{ category.name }}
            </button>
        </div>

        <div class="pos-grid grid content-start gap-2.5 lg:min-h-0 lg:flex-1 lg:overflow-y-auto">
            <Card
                v-for="item in visibleItems"
                :key="item.id"
                variant="product"
                class="relative"
                :class="[
                    isOutOfStock(item) ? 'pointer-events-none opacity-50' : 'cursor-pointer select-none',
                    hasQuantityInCart(item.id) ? 'border-primary hover:border-primary' : '',
                ]"
                @click="emit('add-item', item)"
            >
                <Badge :variant="item.is_vatable ? 'tax' : 'free'" class="absolute top-1.5 right-1.5 z-10">
                    {{ item.is_vatable ? 'TAX' : 'VAT-FREE' }}
                </Badge>
                <div class="relative mb-2">
                    <img
                        v-if="item.image_path"
                        :src="`/storage/${item.image_path}`"
                        :alt="item.name"
                        class="h-16 w-full rounded-none border-[1.5px] border-border object-cover"
                    />
                    <div
                        v-else
                        class="flex h-16 w-full items-center justify-center border-[1.5px] border-border bg-bg-subtle text-text-faint"
                    >
                        <Package class="h-6 w-6" />
                    </div>
                    <span
                        v-if="hasQuantityInCart(item.id)"
                        class="absolute right-1 bottom-1 flex h-5 min-w-5 items-center justify-center bg-primary px-1 text-[11px] font-bold text-white"
                        :title="`${formatQuantity(quantityInCart(item.id))} in cart`"
                    >
                        {{ formatQuantity(quantityInCart(item.id)) }}
                    </span>
                </div>
                <p class="text-sm font-bold text-text-strong">{{ item.name }}</p>
                <p v-if="item.sale_rate != null" class="mt-1 text-sm font-bold text-primary tabular-nums">
                    {{ formatRate(item.sale_rate) }}
                </p>
                <p v-else class="mt-1 inline-block bg-warning-bg px-1.5 py-0.5 text-[11px] font-bold text-warning-text">No price set</p>
                <p v-if="isOutOfStock(item)" class="mt-1 text-[10px] font-bold text-danger uppercase">Out of stock</p>
                <p v-else-if="item.is_stockable" class="mt-1 text-[10px] text-text-faint">
                    Stock: {{ formatQuantity(item.current_stock) }}
                </p>
            </Card>
            <div v-if="filteredItems.length === 0" class="col-span-full flex flex-col items-center gap-1 py-8 text-center">
                <Package class="h-8 w-8 text-text-faint" />
                <p class="text-sm font-semibold text-text-base">
                    {{ searchQuery.trim() ? `No items match “${searchQuery.trim()}”` : 'No items in this category' }}
                </p>
                <p class="text-xs text-text-faint">Check the spelling or barcode, or clear the search and category filter.</p>
            </div>
        </div>
        <p v-if="filteredItems.length > tileCap" class="shrink-0 text-xs text-text-faint">
            Showing {{ visibleItems.length }} of {{ filteredItems.length }} items - refine your search to see more.
        </p>
    </div>
</template>

<style scoped>
.pos-left {
    background: var(--color-bg-surface);
    border-right: 1px solid var(--color-border);
}

@media (min-width: 1024px) {
    .pos-left {
        width: 60%;
    }
}

.pos-grid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    /* Room for the hover shadow, which the scroll container would otherwise crop. */
    padding: 14px 16px 20px;
}

@media (max-width: 600px) {
    .pos-grid {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    }
}
</style>
