<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { addMoney, calculateDocument, formatMoney, formatQuantity, formatRate, moneyEquals, parseMoney } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

const props = defineProps({
    // A LengthAwarePaginator page of purchases, each line already carrying its
    // unit name and how much of it is still returnable. Loading every posted
    // purchase with all its lines stopped being viable long before a real shop
    // stops buying things.
    searchablePurchases: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, prev_page_url: null, next_page_url: null }),
    },
    purchaseSearch: { type: String, default: null },
    refundAccounts: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    // Stockable items, each with its alternate units - the unlinked return
    // names an item directly instead of pointing at a purchase line.
    items: { type: Array, default: () => [] },
    defaultVatRate: { type: String, default: '13.00' },
});

const emit = defineEmits(['cancel', 'posted']);

const search = ref(props.purchaseSearch ?? '');
const searching = ref(false);

// The purchase list is filtered on the server, so a shop with ten thousand
// bills searches in the database rather than shipping them all to the browser.
function runSearch() {
    router.get(
        window.location.pathname,
        { purchase_search: search.value || undefined },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['searchablePurchases', 'purchaseSearch'],
            onStart: () => (searching.value = true),
            onFinish: () => (searching.value = false),
        },
    );
}

function goToPage(url) {
    if (!url) return;

    router.get(url, {}, { preserveState: true, preserveScroll: true, only: ['searchablePurchases', 'purchaseSearch'] });
}

const purchaseOptions = computed(() =>
    props.searchablePurchases.data.map((purchase) => ({
        value: purchase.id,
        label: `#${purchase.id} - ${purchase.supplier?.name ?? '-'} (${formatMoney(purchase.total)})`,
        searchValue: `${purchase.id} ${purchase.supplier?.name ?? ''} ${purchase.bill_number ?? ''}`,
    })),
);

const refundAccountOptions = computed(() =>
    props.refundAccounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));

const form = useForm({
    purchase_id: null,
    // todayInKathmandu(), never new Date().toISOString(): before 05:45 Nepal
    // time the UTC day is still yesterday.
    date: todayInKathmandu(),
    reason: '',
    refund_account_id: null,
    store_id: null,
    lines: [],
});

const selectedPurchase = computed(
    () => props.searchablePurchases.data.find((purchase) => purchase.id === form.purchase_id) ?? null,
);

watch(
    () => form.purchase_id,
    () => {
        const purchase = selectedPurchase.value;

        // The return leaves the store the goods were received into unless the
        // user picks another one.
        form.store_id = purchase?.store_id ?? null;
        form.lines = purchase
            ? purchase.lines.map((line) => ({
                  purchase_line_id: line.id,
                  item_name: line.item_name ?? '-',
                  unit_name: line.unit_name ?? '',
                  quantity_purchased: line.quantity,
                  // Free goods received on that line (item 3). They are part
                  // of remaining_quantity, so they can go back too - the
                  // server credits the paid units first and the free ones at
                  // zero.
                  bonus_quantity: line.bonus_quantity ?? '0',
                  quantity_remaining: line.remaining_quantity,
                  rate: line.rate,
                  quantity: '',
              }))
            : [];
    },
);

const hasReturnableLine = computed(() => form.lines.some((line) => line.quantity !== '' && line.quantity !== '0'));

// ---------------------------------------------------------------------------
// Unlinked returns (item 4)
// ---------------------------------------------------------------------------

/**
 * `linked` is a return against a bill this system posted; `unlinked` is goods
 * from opening stock, or bought before go-live, that have no Purchase row to
 * point at. The two post to different endpoints and share nothing but this
 * card, because an unlinked return prices itself (average cost or an entered
 * rate) instead of inheriting the original bill's money.
 */
const mode = ref('linked');

const supplierOptions = computed(() => props.suppliers.map((supplier) => ({ value: supplier.id, label: supplier.name })));

const bankAccountOptions = computed(() =>
    props.bankAccounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

const itemOptions = computed(() =>
    props.items.map((item) => ({
        value: item.id,
        label: item.name,
        searchValue: `${item.name} ${item.barcode ?? ''}`,
    })),
);

function itemById(itemId) {
    return props.items.find((item) => item.id === itemId) ?? null;
}

/** Base unit first (no item_unit_id, factor 1), then the item's alternate units. */
function unitOptionsFor(itemId) {
    const item = itemById(itemId);

    if (!item) {
        return [];
    }

    return [
        { value: null, label: `${item.unit} (base)` },
        ...(item.units ?? []).map((unit) => ({
            value: unit.id,
            label: `${unit.name} (x${formatQuantity(unit.conversion_factor)})`,
        })),
    ];
}

function blankUnlinkedLine() {
    return { item_id: null, item_unit_id: null, quantity: '', rate: '' };
}

const unlinkedForm = useForm({
    date: todayInKathmandu(),
    supplier_id: null,
    store_id: null,
    reason: '',
    vat_rate: props.defaultVatRate,
    payment_mode: 'cash',
    bank_account_id: null,
    cash_amount: '',
    bank_amount: '',
    lines: [blankUnlinkedLine()],
});

/** Picking a different item invalidates the unit chosen for the old one. */
function setUnlinkedItem(index, itemId) {
    unlinkedForm.lines[index].item_id = itemId;
    unlinkedForm.lines[index].item_unit_id = null;
}

function addUnlinkedLine() {
    unlinkedForm.lines.push(blankUnlinkedLine());
}

function removeUnlinkedLine(index) {
    unlinkedForm.lines.splice(index, 1);

    if (unlinkedForm.lines.length === 0) {
        unlinkedForm.lines.push(blankUnlinkedLine());
    }
}

/** The rows that carry an item and a quantity, each keeping its row index. */
const filledUnlinkedLines = computed(() =>
    unlinkedForm.lines
        .map((line, index) => ({ line, index }))
        .filter(({ line }) => line.item_id && line.quantity !== '' && line.quantity !== '0'),
);

/**
 * A line left without a rate is valued on the server at the item's weighted
 * average cost (App\Support\Inventory\StockCosting), which the browser has no
 * way to know. So the preview - and with it `expected_total` and the exact
 * cash/bank split - is only offered when every line carries a typed rate; the
 * server stays the only authority either way (CONTRACTS C8).
 */
const everyLinePriced = computed(
    () =>
        filledUnlinkedLines.value.length > 0 &&
        filledUnlinkedLines.value.every(({ line }) => line.rate !== '' && line.rate !== null),
);

const unlinkedPreview = computed(() => {
    if (!everyLinePriced.value) {
        return null;
    }

    const result = calculateDocument(
        filledUnlinkedLines.value.map(({ line }) => ({
            quantity: line.quantity,
            rate: line.rate,
            vatable: !!itemById(line.item_id)?.is_vatable,
        })),
        { vat_rate: unlinkedForm.vat_rate === '' ? '0' : unlinkedForm.vat_rate },
    );

    return result.ok ? result.totals : null;
});

/** Preview line total per FORM row index, so empty rows never shift the values. */
const unlinkedLineTotals = computed(() => {
    const totals = {};

    if (!unlinkedPreview.value) {
        return totals;
    }

    filledUnlinkedLines.value.forEach(({ index }, position) => {
        totals[index] = unlinkedPreview.value.lines[position]?.line_total ?? null;
    });

    return totals;
});

const unlinkedTotal = computed(() => unlinkedPreview.value?.total ?? null);

/**
 * The cash + bank split has to land EXACTLY on the total (the server refuses
 * anything else through DocumentCalculator::assertExactSplit), so the form
 * says so up front. Exact string comparison, never a float subtraction inside
 * a tolerance.
 */
const unlinkedSplitError = computed(() => {
    if (unlinkedForm.payment_mode !== 'partial' || unlinkedTotal.value === null) {
        return null;
    }

    const cash = parseMoney(unlinkedForm.cash_amount === '' ? '0' : unlinkedForm.cash_amount);
    const bank = parseMoney(unlinkedForm.bank_amount === '' ? '0' : unlinkedForm.bank_amount);

    if (!cash.ok || !bank.ok) {
        return 'Enter the cash and bank amounts as plain rupee figures.';
    }

    const split = addMoney(cash.value, bank.value);

    return moneyEquals(split, unlinkedTotal.value)
        ? null
        : `Cash plus bank is ${formatMoney(split)}, but ${formatMoney(unlinkedTotal.value)} is due.`;
});

// The partial split has to add up to the paisa (DocumentCalculator::
// assertExactSplit), so it is only offered once the total is known exactly.
const canSplitRefund = computed(() => unlinkedTotal.value !== null);

watch(canSplitRefund, (possible) => {
    if (!possible && unlinkedForm.payment_mode === 'partial') {
        unlinkedForm.payment_mode = 'cash';
    }
});

const hasUnlinkedLine = computed(() => filledUnlinkedLines.value.length > 0);

const paymentModeOptions = computed(() => [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    ...(canSplitRefund.value ? [{ value: 'partial', label: 'Cash + bank' }] : []),
]);

function submitUnlinked() {
    unlinkedForm
        .transform((data) => ({
            date: data.date,
            supplier_id: data.supplier_id || null,
            store_id: data.store_id || null,
            reason: data.reason || null,
            vat_rate: data.vat_rate === '' ? null : data.vat_rate,
            payment_mode: data.payment_mode,
            bank_account_id: data.payment_mode === 'cash' ? null : data.bank_account_id || null,
            cash_amount: data.payment_mode === 'partial' ? data.cash_amount || '0' : null,
            bank_amount: data.payment_mode === 'partial' ? data.bank_amount || '0' : null,
            // Only when the preview is exact - see everyLinePriced above.
            expected_total: unlinkedTotal.value,
            // Quantities and rates go out as typed, never through Number().
            lines: data.lines
                .filter((line) => line.item_id && line.quantity !== '' && line.quantity !== '0')
                .map((line) => ({
                    item_id: line.item_id,
                    item_unit_id: line.item_unit_id || null,
                    quantity: line.quantity,
                    rate: line.rate === '' ? null : line.rate,
                })),
        }))
        .post('/purchase-returns/unlinked', {
            preserveScroll: true,
            onSuccess: () => emit('posted'),
        });
}

function submit() {
    form.transform((data) => ({
        purchase_id: data.purchase_id,
        date: data.date,
        reason: data.reason || null,
        refund_account_id: data.refund_account_id || null,
        store_id: data.store_id || null,
        // Quantities go out as typed. Number() would round 0.00004 into a
        // charge with no stock behind it (audit P0-5).
        lines: data.lines
            .filter((line) => line.quantity !== '' && line.quantity !== '0')
            .map((line) => ({
                purchase_line_id: line.purchase_line_id,
                quantity: line.quantity,
            })),
    })).post('/purchase-returns', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New purchase return</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <Button :variant="mode === 'linked' ? 'primary' : 'secondary'" tone="purple" type="button" @click="mode = 'linked'">
                Against a purchase
            </Button>
            <Button :variant="mode === 'unlinked' ? 'primary' : 'secondary'" tone="purple" type="button" @click="mode = 'unlinked'">
                Without a purchase
            </Button>
            <span class="text-xs text-text-muted">
                Use "Without a purchase" for opening stock, or goods bought before this system went live.
            </span>
        </div>

        <p
            v-if="mode === 'linked' && form.errors.lines"
            class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger"
        >
            {{ form.errors.lines }}
        </p>

        <form v-if="mode === 'linked'" class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[260px] flex-1">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Find a purchase</label>
                    <Input v-model="search" type="text" placeholder="Bill number, supplier or purchase id" @keyup.enter="runSearch" />
                </div>
                <Button variant="secondary" tone="purple" type="button" :loading="searching" @click="runSearch">
                    <Search class="size-4" />
                    Search
                </Button>
                <div class="flex items-center gap-2 text-xs text-text-muted">
                    <button
                        type="button"
                        class="border-[1.5px] border-border bg-white px-2 py-1 font-semibold disabled:opacity-40"
                        :disabled="!searchablePurchases.prev_page_url"
                        @click="goToPage(searchablePurchases.prev_page_url)"
                    >
                        Previous
                    </button>
                    <span>Page {{ searchablePurchases.current_page }} of {{ searchablePurchases.last_page }}</span>
                    <button
                        type="button"
                        class="border-[1.5px] border-border bg-white px-2 py-1 font-semibold disabled:opacity-40"
                        :disabled="!searchablePurchases.next_page_url"
                        @click="goToPage(searchablePurchases.next_page_url)"
                    >
                        Next
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-5 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Purchase <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.purchase_id"
                        :options="purchaseOptions"
                        placeholder="Select the original purchase"
                        @update:model-value="(v) => (form.purchase_id = v)"
                    />
                    <p v-if="form.errors.purchase_id" class="mt-1 text-sm text-danger">{{ form.errors.purchase_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <Input v-model="form.reason" type="text" maxlength="255" placeholder="Optional" />
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">{{ form.errors.reason }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Refund via</label>
                    <Combobox
                        :model-value="form.refund_account_id"
                        :options="refundAccountOptions"
                        placeholder="No refund (debit note only)"
                        @update:model-value="(v) => (form.refund_account_id = v)"
                    />
                    <p v-if="form.errors.refund_account_id" class="mt-1 text-sm text-danger">{{ form.errors.refund_account_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                    <Combobox
                        :model-value="form.store_id"
                        :options="storeOptions"
                        placeholder="The purchase's store"
                        @update:model-value="(v) => (form.store_id = v)"
                    />
                    <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
                </div>
            </div>

            <div v-if="selectedPurchase">
                <div class="mb-2 grid grid-cols-[1fr_90px_110px_110px_90px_110px_140px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Unit</span>
                    <span>Rate</span>
                    <span>Purchased</span>
                    <span>Free</span>
                    <span>Returnable</span>
                    <span>Return Qty</span>
                </div>

                <div
                    v-for="(line, index) in form.lines"
                    :key="line.purchase_line_id"
                    class="mb-2 grid grid-cols-[1fr_90px_110px_110px_90px_110px_140px] items-start gap-2"
                >
                    <span class="pt-2 text-sm text-text-strong">{{ line.item_name }}</span>
                    <span class="pt-2 text-sm text-text-muted">{{ line.unit_name || '-' }}</span>
                    <span class="pt-2 text-sm text-text-muted">{{ formatRate(line.rate) }}</span>
                    <span class="pt-2 text-sm text-text-muted">{{ formatQuantity(line.quantity_purchased) }}</span>
                    <span class="pt-2 text-sm text-text-muted">{{ formatQuantity(line.bonus_quantity) }}</span>
                    <span class="pt-2 text-sm text-text-muted">{{ formatQuantity(line.quantity_remaining) }}</span>
                    <div>
                        <Input
                            v-model="form.lines[index].quantity"
                            type="number"
                            min="0"
                            step="0.0001"
                            :max="line.quantity_remaining"
                            placeholder="0"
                        />
                        <p v-if="form.errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.quantity`] }}
                        </p>
                    </div>
                </div>

                <p class="mt-1 text-xs text-text-muted">
                    Quantities are in the unit each line was purchased in. Returning one Box of twelve puts twelve pieces
                    back out of stock. Free goods can be returned too: paid units are credited first, so anything beyond
                    the billed quantity goes back at no value.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="submit"
                    :disabled="form.processing || !selectedPurchase || !hasReturnableLine"
                >
                    Create Purchase Return
                </Button>
            </div>
        </form>

        <form v-else class="flex flex-col gap-4" @submit.prevent="submitUnlinked">
            <!--
                Unlinked return (item 4): no purchase to point at, so the clerk
                names the items, and the server values each line at the item's
                weighted average cost unless a rate is typed here.
            -->
            <p v-if="unlinkedForm.errors.lines" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                {{ unlinkedForm.errors.lines }}
            </p>

            <div class="grid grid-cols-5 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="unlinkedForm.date" required />
                    <p v-if="unlinkedForm.errors.date" class="mt-1 text-sm text-danger">{{ unlinkedForm.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier</label>
                    <Combobox
                        :model-value="unlinkedForm.supplier_id"
                        :options="supplierOptions"
                        placeholder="Optional"
                        @update:model-value="(v) => (unlinkedForm.supplier_id = v)"
                    />
                    <p v-if="unlinkedForm.errors.supplier_id" class="mt-1 text-sm text-danger">{{ unlinkedForm.errors.supplier_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                    <Combobox
                        :model-value="unlinkedForm.store_id"
                        :options="storeOptions"
                        placeholder="Default store"
                        @update:model-value="(v) => (unlinkedForm.store_id = v)"
                    />
                    <p v-if="unlinkedForm.errors.store_id" class="mt-1 text-sm text-danger">{{ unlinkedForm.errors.store_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate %</label>
                    <Input v-model="unlinkedForm.vat_rate" type="number" min="0" max="100" step="0.01" />
                    <p v-if="unlinkedForm.errors.vat_rate" class="mt-1 text-sm text-danger">{{ unlinkedForm.errors.vat_rate }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <Input v-model="unlinkedForm.reason" type="text" maxlength="255" placeholder="Optional" />
                    <p v-if="unlinkedForm.errors.reason" class="mt-1 text-sm text-danger">{{ unlinkedForm.errors.reason }}</p>
                </div>
            </div>

            <div>
                <div class="mb-2 grid grid-cols-[1fr_180px_120px_140px_120px_40px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Unit</span>
                    <span>Quantity</span>
                    <span>Rate</span>
                    <span>Value</span>
                    <span></span>
                </div>

                <div
                    v-for="(line, index) in unlinkedForm.lines"
                    :key="index"
                    class="mb-2 grid grid-cols-[1fr_180px_120px_140px_120px_40px] items-start gap-2"
                >
                    <div>
                        <Combobox
                            :model-value="line.item_id"
                            :options="itemOptions"
                            placeholder="Select an item"
                            @update:model-value="(v) => setUnlinkedItem(index, v)"
                        />
                        <p v-if="unlinkedForm.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                            {{ unlinkedForm.errors[`lines.${index}.item_id`] }}
                        </p>
                    </div>
                    <Combobox
                        :model-value="line.item_unit_id"
                        :options="unitOptionsFor(line.item_id)"
                        placeholder="Base unit"
                        @update:model-value="(v) => (unlinkedForm.lines[index].item_unit_id = v)"
                    />
                    <div>
                        <Input v-model="unlinkedForm.lines[index].quantity" type="number" min="0" step="0.0001" placeholder="0" />
                        <p v-if="unlinkedForm.errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                            {{ unlinkedForm.errors[`lines.${index}.quantity`] }}
                        </p>
                    </div>
                    <div>
                        <Input v-model="unlinkedForm.lines[index].rate" type="number" min="0" step="0.0001" placeholder="Average cost" />
                        <p v-if="unlinkedForm.errors[`lines.${index}.rate`]" class="mt-1 text-xs text-danger">
                            {{ unlinkedForm.errors[`lines.${index}.rate`] }}
                        </p>
                    </div>
                    <span class="pt-2 text-sm text-text-muted">
                        {{ unlinkedLineTotals[index] ? formatMoney(unlinkedLineTotals[index]) : 'At cost' }}
                    </span>
                    <button
                        type="button"
                        class="mt-1 border-[1.5px] border-border bg-white p-2 text-text-muted hover:border-danger hover:text-danger"
                        @click="removeUnlinkedLine(index)"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <Button variant="secondary" tone="purple" type="button" @click="addUnlinkedLine">
                    <Plus class="size-4" />
                    Add line
                </Button>

                <p class="mt-2 text-xs text-text-muted">
                    Leave the rate blank to value a line at the item's current weighted average cost. Quantities are in
                    the unit picked on that line.
                </p>
            </div>

            <div class="grid grid-cols-4 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Refund <span class="text-danger">*</span></label>
                    <Select
                        :model-value="unlinkedForm.payment_mode"
                        :options="paymentModeOptions"
                        @update:model-value="(v) => (unlinkedForm.payment_mode = v)"
                    />
                    <p v-if="unlinkedForm.errors.payment_mode" class="mt-1 text-sm text-danger">{{ unlinkedForm.errors.payment_mode }}</p>
                </div>
                <div v-if="unlinkedForm.payment_mode !== 'cash'">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank account <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="unlinkedForm.bank_account_id"
                        :options="bankAccountOptions"
                        placeholder="Select the bank account"
                        @update:model-value="(v) => (unlinkedForm.bank_account_id = v)"
                    />
                    <p v-if="unlinkedForm.errors.bank_account_id" class="mt-1 text-sm text-danger">
                        {{ unlinkedForm.errors.bank_account_id }}
                    </p>
                </div>
                <template v-if="unlinkedForm.payment_mode === 'partial'">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Cash part</label>
                        <Input v-model="unlinkedForm.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                        <p v-if="unlinkedForm.errors.cash_amount" class="mt-1 text-sm text-danger">{{ unlinkedForm.errors.cash_amount }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Bank part</label>
                        <Input v-model="unlinkedForm.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                        <p v-if="unlinkedForm.errors.bank_amount" class="mt-1 text-sm text-danger">{{ unlinkedForm.errors.bank_amount }}</p>
                    </div>
                </template>
            </div>

            <p v-if="unlinkedSplitError" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                {{ unlinkedSplitError }}
            </p>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t-[1.5px] border-border pt-3">
                <div v-if="unlinkedPreview" class="text-sm text-text-muted">
                    Taxable {{ formatMoney(unlinkedPreview.taxable_amount) }} + Exempt
                    {{ formatMoney(unlinkedPreview.nontaxable_amount) }} + VAT {{ formatMoney(unlinkedPreview.vat_amount) }} =
                    <strong class="text-text-strong">{{ formatMoney(unlinkedPreview.total) }}</strong>
                </div>
                <div v-else class="text-sm text-text-muted">
                    Total is worked out on the server at each item's average cost. Type a rate on every line to see it here
                    and to split the refund between cash and bank.
                </div>

                <div class="flex items-center gap-2">
                    <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                    <Button
                        variant="primary"
                        tone="purple"
                        type="submit"
                        :disabled="unlinkedForm.processing || !hasUnlinkedLine || !!unlinkedSplitError"
                    >
                        Create Purchase Return
                    </Button>
                </div>
            </div>
        </form>
    </Card>
</template>
