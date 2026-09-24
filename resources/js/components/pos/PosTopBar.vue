<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, CircleHelp, Maximize2, Merge, Minimize2, Plus, X } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';

/**
 * POS top bar: dashboard exit, FY badge, shortcuts button, bill date, invoice
 * badge, fullscreen toggle, plus the draft-cart tab strip. All state lives in
 * Sales/Pos.vue (usePosCarts); this component only renders and emits.
 *
 * The cart-tab scroller element is handed back through the `scroller` event
 * (function ref) so usePosCarts' `cartTabsScroller` ref and its scroll-into-view
 * watcher keep working unchanged.
 */
defineProps({
    currentFiscalYear: { type: String, default: '' },
    isPanInvoice: { type: Boolean, default: false },
    isFullscreen: { type: Boolean, default: false },
    date: { type: String, default: '' },
    carts: { type: Array, default: () => [] },
    activeCartIndex: { type: Number, default: 0 },
    cartLabel: { type: Function, required: true },
    cartLineCount: { type: Function, required: true },
});

const emit = defineEmits([
    'update:date',
    'open-shortcuts',
    'toggle-fullscreen',
    'switch-cart',
    'close-cart',
    'open-merge',
    'add-cart',
    'scroller',
]);

const bindScroller = (element) => emit('scroller', element);
</script>

<template>
    <!-- Top bar: always-visible dashboard exit, FY, help, date, invoice type, hints -->
    <div class="pos-bar flex shrink-0 flex-col lg:flex-row">
      <div class="pos-bar-section pos-bar-section--products flex items-center gap-2.5 overflow-x-auto">
        <Link href="/dashboard" aria-label="Back to dashboard" class="pos-bar-btn">
            <ChevronLeft class="h-4 w-4" /> <span class="hidden sm:inline">Dashboard</span>
        </Link>
        <div class="pos-bar-divider" />
        <span
            class="pos-fy-badge"
            :title="currentFiscalYear ? 'Current fiscal year' : 'No fiscal year is set up for this date - ask an admin to create one before selling.'"
        >
            FY: {{ currentFiscalYear || 'Not set' }}
        </span>
        <div class="pos-bar-divider" />
        <button type="button" class="pos-bar-btn" @click="emit('open-shortcuts')">
            <CircleHelp class="h-4 w-4" /> <span class="hidden sm:inline">Shortcuts</span> <kbd class="pos-bar-kbd">F1</kbd>
        </button>
        <div class="pos-bar-divider" />
        <div class="pos-date-box">
            <label>DATE</label>
            <NepaliDateInput :model-value="date" required class="pos-date-input" @update:model-value="(v) => emit('update:date', v)" />
        </div>
        <div class="pos-bar-divider" />
        <span class="pos-bill-badge" :title="isPanInvoice ? 'PAN invoices (no VAT) - set by your admin' : 'Tax invoices (VAT) - set by your admin'">
            {{ isPanInvoice ? 'PAN' : 'TAX' }} invoice
        </span>

        <Tooltip :label="isFullscreen ? 'Exit full screen' : 'Full screen'">
            <button type="button" :aria-label="isFullscreen ? 'Exit full screen' : 'Full screen'" class="pos-bar-btn pos-bar-btn--icon" @click="emit('toggle-fullscreen')">
                <Minimize2 v-if="isFullscreen" class="h-4 w-4" />
                <Maximize2 v-else class="h-4 w-4" />
            </button>
        </Tooltip>
      </div>

      <!-- Draft-cart tabs: sit over the cart column, product tools over the product column -->
      <div class="pos-bar-section pos-bar-section--carts flex items-end gap-1.5">
       <div :ref="bindScroller" class="pos-cart-scroll flex min-w-0 flex-1 items-end gap-1.5 self-stretch overflow-x-auto">
        <button
            v-for="(cart, index) in carts"
            :key="cart.id"
            type="button"
            class="pos-cart-tab flex shrink-0 cursor-pointer items-center gap-2 px-3.5 text-xs font-bold whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary"
            :class="index === activeCartIndex ? 'pos-cart-tab--active' : 'pos-cart-tab--idle'"
            :aria-pressed="index === activeCartIndex"
            @click="emit('switch-cart', index)"
        >
            <span>{{ cartLabel(cart, index) }}</span>
            <span v-if="cartLineCount(cart, index) > 0" class="text-[10px] font-semibold opacity-70">
                ({{ cartLineCount(cart, index) }})
            </span>
            <span
                v-if="carts.length > 1"
                role="button"
                tabindex="0"
                :aria-label="`Cancel held sale ${cartLabel(cart, index)}`"
                title="Cancel this held sale"
                class="flex h-6 w-6 cursor-pointer items-center justify-center opacity-60 hover:opacity-100 focus-visible:outline-2 focus-visible:outline-primary"
                @click.stop="emit('close-cart', index)"
                @keydown.enter.stop.prevent="emit('close-cart', index)"
            >
                <X class="h-3.5 w-3.5" />
            </span>
        </button>
       </div>
        <Tooltip label="Merge another cart into this one">
            <Button variant="icon" tone="success" aria-label="Merge another cart into this one" class="mb-[5px] shrink-0" @click="emit('open-merge')">
                <Merge class="h-4 w-4" />
            </Button>
        </Tooltip>
        <Button variant="icon" aria-label="New sale (F9 holds the current one first)" class="mb-[5px] shrink-0" @click="emit('add-cart')">
            <Plus class="h-4 w-4" />
        </Button>
      </div>
    </div>
</template>

<style scoped>
.pos-bar {
    background: var(--color-primary);
    color: white;
}

/* The accent rule is drawn per section (not on the bar) so the active cart tab can sit on top of it and merge into the panel below. */
.pos-bar-section {
    position: relative;
}

.pos-bar-section::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 2px;
    background: var(--color-accent);
}

.pos-cart-tab {
    position: relative;
    z-index: 1;
}

/* Tabs scroll horizontally without a native scrollbar eating the bar's height. */
.pos-cart-scroll {
    scrollbar-width: none;
}

.pos-cart-scroll::-webkit-scrollbar {
    display: none;
}

.pos-cart-tab--active {
    height: 46px;
    background: var(--color-bg-subtle);
    color: var(--color-primary);
}

.pos-cart-tab--idle {
    height: 38px;
    margin-bottom: 4px;
    background: rgba(255, 255, 255, 0.12);
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    color: white;
}

.pos-cart-tab--idle:hover {
    background: rgba(255, 255, 255, 0.22);
}

.pos-bar-section {
    min-height: 52px;
    min-width: 0;
    padding: 0 14px;
}

.pos-bar-section--carts {
    border-top: 1px solid rgba(255, 255, 255, 0.25);
}

/* Same 60/40 split as the body below, so the tabs sit right over the cart column. */
@media (min-width: 1024px) {
    .pos-bar-section--products {
        width: 60%;
    }

    .pos-bar-section--carts {
        width: 40%;
        border-top: 0;
        border-left: 1px solid rgba(255, 255, 255, 0.25);
    }
}

.pos-bar-divider {
    width: 1px;
    height: 22px;
    background: rgba(255, 255, 255, 0.25);
    flex-shrink: 0;
}

.pos-bar-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 10px;
    flex-shrink: 0;
    font-size: 12px;
    font-weight: 700;
    color: white;
    background: rgba(255, 255, 255, 0.12);
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    cursor: pointer;
    white-space: nowrap;
}

.pos-bar-btn:hover {
    background: rgba(255, 255, 255, 0.22);
}

.pos-bar-btn--icon {
    width: 32px;
    padding: 0;
    justify-content: center;
}

.pos-bar-kbd {
    font-family: ui-monospace, monospace;
    font-size: 10px;
    opacity: 0.8;
}

.pos-fy-badge,
.pos-bill-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 10px;
    flex-shrink: 0;
    font-size: 11.5px;
    font-weight: 700;
    color: white;
    background: rgba(255, 255, 255, 0.12);
    white-space: nowrap;
}

.pos-date-box {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.pos-date-box label {
    font-size: 9px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.75);
    letter-spacing: 0.5px;
}

.pos-date-input {
    width: 96px;
}
</style>
