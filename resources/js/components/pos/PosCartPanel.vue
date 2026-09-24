<script setup>
import { ChevronDown, Minus, Plus, ScanBarcode, SplitSquareHorizontal, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { formatMoney } from '@/lib/money';

/**
 * Cart column, top half of the POS right-hand side: customer picker plus the
 * cart lines list. Presentational only - `Sales/Pos.vue` owns the cart state
 * and every mutation below (quantity stepping, MRP entry, splitting, removal)
 * arrives as an emitted event. The root uses `display: contents` so its
 * children still lay out as direct flex items of the `.pos-right` column.
 * The customer field wrapper is handed back through `customer-wrapper` (a
 * function ref) so the F4 shortcut can still focus it.
 */
const props = defineProps({
    form: { type: Object, required: true },
    customerOptions: { type: Array, default: () => [] },
    itemsById: { type: Object, default: () => ({}) },
    lineTotal: { type: Function, required: true },
    isRateMissing: { type: Function, required: true },
});

const emit = defineEmits([
    'new-customer',
    'clear-cart',
    'remove-line',
    'increment-qty',
    'decrement-qty',
    'warn-overstock',
    'toggle-discount-type',
    'toggle-more',
    'apply-mrp',
    'split-line',
    'customer-wrapper',
]);

const bindCustomerWrapper = (element) => emit('customer-wrapper', element);
</script>

<template>
    <div class="contents">
        <div class="shrink-0">
            <div class="flex items-center gap-2">
                <div :ref="bindCustomerWrapper" class="relative flex-1">
                    <Combobox
                        :model-value="form.customer_id"
                        :options="customerOptions"
                        placeholder="Select customer… (F4)"
                        @update:model-value="(v) => (form.customer_id = v)"
                    />
                </div>
                <Button variant="secondary" tone="purple" type="button" class="h-9 cursor-pointer" aria-label="Add a new customer" @click="emit('new-customer')">
                    <Plus class="h-3.5 w-3.5" /> New
                </Button>
            </div>
            <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
        </div>

        <Card
            variant="panel"
            class="shrink !border-b-0 lg:min-h-[240px] lg:flex-1 lg:overflow-y-auto lg:overflow-x-hidden"
        >
            <div class="mb-2 flex items-center justify-between">
                <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Cart</p>
                <button
                    v-if="form.lines.length > 0"
                    type="button"
                    class="h-7 cursor-pointer px-2 text-xs font-semibold text-text-muted hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    @click="emit('clear-cart')"
                >
                    Clear cart
                </button>
            </div>
            <div v-if="form.lines.length === 0" class="flex flex-col items-center gap-1 py-6 text-center">
                <ScanBarcode class="h-8 w-8 text-text-faint" />
                <p class="text-sm font-semibold text-text-base">Scan or search an item to start a sale</p>
                <p class="text-xs text-text-faint">Tap an item tile, press <kbd class="font-mono">F2</kbd> to search or <kbd class="font-mono">F7</kbd> to scan a barcode.</p>
            </div>
            <div v-for="(line, index) in form.lines" :key="index" class="mb-1.5 flex flex-col gap-1.5 border-[1.5px] border-border bg-white p-2 last:mb-0">
                <div class="flex items-center gap-2">
                    <p class="min-w-0 flex-1 truncate text-sm font-semibold text-text-strong">
                        {{ itemsById[line.item_id]?.name ?? 'Item' }}
                    </p>
                    <Badge :variant="itemsById[line.item_id]?.is_vatable ? 'tax' : 'free'">
                        {{ itemsById[line.item_id]?.is_vatable ? 'TAX' : 'VAT-FREE' }}
                    </Badge>
                    <span class="shrink-0 text-sm font-bold tabular-nums" :class="lineTotal(index) === null ? 'text-text-faint' : 'text-text-strong'">
                        {{ lineTotal(index) === null ? '—' : formatMoney(lineTotal(index)) }}
                    </span>
                    <Tooltip label="More: MRP, free units, split">
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                            aria-label="Show more options for this line"
                            :aria-expanded="!!line.showMore"
                            @click="emit('toggle-more', line)"
                        >
                            <ChevronDown class="h-4 w-4 transition-transform" :class="line.showMore ? 'rotate-180' : ''" />
                        </button>
                    </Tooltip>
                    <Tooltip label="Remove this item">
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center text-text-muted hover:text-danger focus-visible:outline-2 focus-visible:outline-primary"
                            aria-label="Remove this item from the cart"
                            @click="emit('remove-line', index)"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </Tooltip>
                </div>
                <p v-if="form.errors[`lines.${index}.item_id`]" class="text-xs text-danger">
                    {{ form.errors[`lines.${index}.item_id`] }}
                </p>
                <div class="flex flex-wrap items-center gap-1.5">
                    <div class="flex w-32 shrink-0 items-stretch border-[1.5px] border-border bg-white focus-within:border-primary">
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center bg-bg-subtle text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                            aria-label="Decrease quantity"
                            title="Decrease quantity"
                            @click="emit('decrement-qty', index)"
                        >
                            <Minus class="h-4 w-4" />
                        </button>
                        <Input
                            v-model="line.quantity"
                            type="number"
                            min="0"
                            step="0.0001"
                            placeholder="Qty"
                            aria-label="Quantity"
                            class="!h-8 min-w-0 flex-1 !border-0 !bg-white text-center !shadow-none !outline-none"
                            @blur="emit('warn-overstock', line.item_id)"
                        />
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center bg-bg-subtle text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                            aria-label="Increase quantity"
                            title="Increase quantity"
                            @click="emit('increment-qty', index)"
                        >
                            <Plus class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="w-20 shrink-0" :data-rate-index="index">
                        <Input
                            v-model="line.rate"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="Rate"
                            aria-label="Rate"
                            :class="isRateMissing(line) ? '!h-8 !border-danger text-center' : '!h-8 text-center'"
                        />
                    </div>
                    <div class="flex w-28 shrink-0 items-stretch border-[1.5px] border-border bg-white focus-within:border-primary">
                        <Input
                            v-model="line.discount"
                            type="number"
                            min="0"
                            :max="line.discountType === 'percent' ? 100 : undefined"
                            :placeholder="line.discountType === 'percent' ? '%' : 'Disc.'"
                            aria-label="Line discount"
                            class="!h-8 min-w-0 flex-1 !border-0 !bg-white text-center !shadow-none !outline-none"
                        />
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center bg-bg-subtle text-xs font-bold text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                            title="Click to switch between % and Rs discount"
                            :aria-label="`Discount type: ${line.discountType === 'percent' ? 'percent' : 'rupees'}. Click to switch`"
                            @click="emit('toggle-discount-type', index)"
                        >
                            {{ line.discountType === 'percent' ? '%' : 'Rs' }}
                        </button>
                    </div>
                </div>
                <!-- MRP is VAT-inclusive entry (audit section 3
                     "Sales"): typing it fills Rate above with
                     MRP / 1.13 for a vatable item. Browser-only,
                     never submitted. Bonus units move stock and
                     are never billed, so the line total above and
                     the bill total below ignore them. Collapsed by
                     default - edited far less often than
                     qty/rate/discount. -->
                <div v-if="line.showMore" class="flex items-center gap-1.5">
                    <div class="w-20 shrink-0">
                        <Input
                            :model-value="line.mrp"
                            type="number"
                            min="0"
                            step="0.0001"
                            placeholder="MRP"
                            title="VAT-inclusive price: fills Rate with MRP / (1 + VAT%)"
                            class="!h-8 text-center"
                            @update:model-value="(v) => emit('apply-mrp', line, v)"
                        />
                    </div>
                    <div class="w-20 shrink-0">
                        <Input
                            v-model="line.bonus_quantity"
                            type="number"
                            min="0"
                            step="0.0001"
                            placeholder="Free"
                            title="Free units given with this line - moves stock, never billed"
                            class="!h-8 text-center"
                            @blur="emit('warn-overstock', line.item_id)"
                        />
                    </div>
                    <button
                        type="button"
                        class="ml-auto flex h-8 cursor-pointer items-center gap-1.5 px-2 text-xs font-semibold text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                        @click="emit('split-line', index)"
                    >
                        <SplitSquareHorizontal class="h-4 w-4" /> Split to new cart
                    </button>
                    <span v-if="form.errors[`lines.${index}.bonus_quantity`]" class="text-xs text-danger">
                        {{ form.errors[`lines.${index}.bonus_quantity`] }}
                    </span>
                </div>
            </div>
        </Card>
    </div>
</template>
