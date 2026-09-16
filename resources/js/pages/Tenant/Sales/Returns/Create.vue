<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import {
    addMoney,
    allocateMoney,
    calculateDocument,
    formatMoney,
    formatQuantity,
    formatRate,
    moneyEquals,
    parseMoney,
    parseQuantity,
    subtractMoney,
} from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

const props = defineProps({
    // A length-aware paginator of sale summaries, searched server-side: the
    // page used to ship every posted sale with all of its lines.
    sales: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, prev_page_url: null, next_page_url: null }),
    },
    // The picked sale with its returnable lines and their C6 components,
    // pulled on demand (an Inertia optional prop).
    selectedSale: { type: Object, default: null },
    refundAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    saleSearch: { type: String, default: '' },
    // Unlinked mode only: the goods are priced here the way a fresh sale
    // would price them, so this form needs the same item picker and the same
    // company VAT rate Sales/Create.vue uses.
    items: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    walkInCustomerId: { type: Number, default: null },
    invoiceSettings: { type: Object, default: () => ({ default_vat_rate: '13.00' }) },
    // 'post' posts a return immediately (the original one-step flow); 'request'
    // submits it as a pending request instead - nothing posts until a second
    // person approves it from the Pending Requests section on the Index page
    // (see SalesReturn::request()'s docblock). 'unlinked' is a return with no
    // bill this system ever issued to point at (audit section 3 "Sales") -
    // see SalesReturn::postUnlinked(): the lines name an item and a rate
    // directly, VAT is the company rate, and it always posts straight away.
    mode: { type: String, default: 'post' },
});

const emit = defineEmits(['cancel', 'posted']);

const QUANTITY_DECIMALS = 4;

/**
 * Canonical 4dp quantity string -> scaled integer. Exact: the digits are read
 * as a BigInt, never through a float. money.js has no quantity subtraction or
 * comparison yet (see this pass's cross-file request to add
 * `subtractQuantity`/`compareQuantity`), and these two pieces of arithmetic
 * are what the return preview needs to tell "this finishes the line" from
 * "this is a part of it".
 */
function scaled(quantity) {
    const [whole, fraction = ''] = quantity.split('.');
    const negative = whole.startsWith('-');
    const digits = BigInt((negative ? whole.slice(1) : whole) + fraction.padEnd(QUANTITY_DECIMALS, '0'));

    return negative ? -digits : digits;
}

/** Scaled integer -> canonical 4dp quantity string. */
function unscaled(value) {
    const negative = value < 0n;
    const digits = (negative ? -value : value).toString().padStart(QUANTITY_DECIMALS + 1, '0');
    const text = `${digits.slice(0, -QUANTITY_DECIMALS)}.${digits.slice(-QUANTITY_DECIMALS)}`;

    return negative ? `-${text}` : text;
}

function quantityMinus(a, b) {
    return unscaled(scaled(a) - scaled(b));
}

/**
 * `round(amount x numerator / denominator)` with a single HalfUp rounding,
 * expressed through `allocateMoney`: for a two-way split the leftover paisa
 * goes to the larger fractional remainder, with ties to the first share,
 * which is exactly HalfUp of the first quotient. Same single rounding
 * `Money::multipliedByFraction()` does on the server.
 */
function fractionOf(amount, numerator, denominator) {
    return allocateMoney(amount, [numerator, quantityMinus(denominator, numerator)])[0];
}

const saleRows = computed(() => props.sales.data ?? []);
const searchTerm = ref(props.saleSearch ?? '');
const searching = ref(false);

/**
 * The Index page keeps its own filters in the query string; a partial reload
 * for the picker must not drop them, so every navigation starts from what is
 * already in the URL.
 */
function reload(params, only) {
    const current = Object.fromEntries(new URLSearchParams(window.location.search));

    router.get(
        window.location.pathname,
        { ...current, ...params },
        {
            only,
            preserveState: true,
            preserveScroll: true,
            onStart: () => (searching.value = true),
            onFinish: () => (searching.value = false),
        },
    );
}

function searchSales() {
    reload({ sale_search: searchTerm.value || undefined, sale_page: undefined }, ['sales']);
}

function goToPage(page) {
    reload({ sale_search: searchTerm.value || undefined, sale_page: page }, ['sales']);
}

function pickSale(sale) {
    reload({ sale_id: sale.id }, ['selectedSale']);
}

function clearSale() {
    reload({ sale_id: undefined }, ['selectedSale']);
}

const isUnlinked = computed(() => props.mode === 'unlinked');

const form = useForm({
    date: todayInKathmandu(),
    reason: '',
    refund_account_id: null,
    // The optional cash+bank split refund (audit section 4 polish): blank
    // means "no split", which keeps the original single-account behaviour.
    refund_cash_amount: '',
    refund_bank_amount: '',
    store_id: null,
    customer_id: null,
});

const quantities = reactive({});
// Bonus/free units returned alongside the paid quantity, keyed by sale line
// id: they restock and credit nothing (audit section 3 "Sales").
const bonusQuantities = reactive({});

function blankUnlinkedLine() {
    return { item_id: null, item_unit_id: null, quantity: '', bonus_quantity: '', rate: '' };
}

const unlinkedLines = reactive([blankUnlinkedLine()]);

const itemOptions = computed(() => props.items.map((item) => ({ value: item.id, label: item.name })));
const customerOptions = computed(() => props.customers.map((customer) => ({ value: customer.id, label: customer.name })));

function itemById(itemId) {
    return props.items.find((item) => item.id === itemId) ?? null;
}

function unitOptionsFor(line) {
    const item = itemById(line.item_id);

    return (item?.units ?? []).map((unit) => ({ value: unit.id, label: unit.name }));
}

/**
 * The line's unit conversion factor, as a string for the calculator: the
 * chosen unit's own factor, or 1 when the line is entered in the item's base
 * unit. Never parsed through `Number()` (C8).
 */
function conversionFactorFor(line) {
    const item = itemById(line.item_id);
    const unit = (item?.units ?? []).find((candidate) => candidate.id === line.item_unit_id);

    return unit ? String(unit.conversion_factor) : '1';
}

function addUnlinkedLine() {
    unlinkedLines.push(blankUnlinkedLine());
}

function removeUnlinkedLine(index) {
    unlinkedLines.splice(index, 1);

    if (unlinkedLines.length === 0) {
        unlinkedLines.push(blankUnlinkedLine());
    }
}

// Picking an item resets the unit (the old unit belongs to the old item) and
// prefills the rate the item is normally sold at, the way Sales/Create.vue's
// own item picker does.
function onUnlinkedItemPicked(line, itemId) {
    line.item_id = itemId;
    line.item_unit_id = null;

    const item = itemById(itemId);

    if (item && (line.rate === '' || line.rate === null)) {
        line.rate = String(item.sale_rate ?? '');
    }
}

const unlinkedPayloadLines = computed(() =>
    unlinkedLines
        .filter((line) => line.item_id && String(line.quantity).trim() !== '' && String(line.rate).trim() !== '')
        .map((line) => ({
            item_id: line.item_id,
            item_unit_id: line.item_unit_id ?? null,
            quantity: String(line.quantity).trim(),
            bonus_quantity: String(line.bonus_quantity ?? '').trim() === '' ? '0' : String(line.bonus_quantity).trim(),
            rate: String(line.rate).trim(),
            vatable: !!itemById(line.item_id)?.is_vatable,
            conversion_factor: conversionFactorFor(line),
        })),
);

/**
 * The unlinked preview, computed by the very same algorithm the server runs
 * (C3/C8): `calculateDocument` with the company VAT rate and no header
 * discount or TDS, which is exactly what SalesReturn::postUnlinked() asks
 * DocumentCalculator for. `{ error }` when the entered numbers cannot make a
 * document at all.
 */
const unlinkedTotals = computed(() => {
    if (unlinkedPayloadLines.value.length === 0) {
        return null;
    }

    const result = calculateDocument(
        unlinkedPayloadLines.value.map((line) => ({
            quantity: line.quantity,
            rate: line.rate,
            vatable: line.vatable,
            conversion_factor: line.conversion_factor,
        })),
        { vat_rate: props.invoiceSettings.default_vat_rate },
    );

    return result.ok ? result.totals : { error: result.message };
});

// A walk-in is the usual counterparty for a return with no bill, so the
// seeded walk-in customer is the default here (audit section 3 "Sales").
if (props.mode === 'unlinked' && props.walkInCustomerId) {
    form.customer_id = props.walkInCustomerId;
}

// A new sale means a new set of lines: drop anything typed against the
// previous one rather than carry it across invoices.
watch(
    () => props.selectedSale?.id,
    () => {
        for (const key of Object.keys(quantities)) {
            delete quantities[key];
        }

        for (const key of Object.keys(bonusQuantities)) {
            delete bonusQuantities[key];
        }

        if (props.selectedSale?.store_id) {
            form.store_id = props.selectedSale.store_id;
        }
    },
);

const refundAccountOptions = computed(() =>
    props.refundAccounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);
const storeOptions = computed(() => props.stores.map((store) => ({ value: store.id, label: store.name })));

/**
 * The credit this line would post, by the same rule the server applies
 * (CONTRACTS C6): a fraction of each component, except when the entered
 * quantity finishes the line, where it is the component minus everything
 * already credited for it. Returns `null` for an untouched line and
 * `{ error }` for one that cannot be returned as entered.
 */
function creditFor(line) {
    const raw = quantities[line.sale_line_id];

    if (raw === undefined || raw === null || String(raw).trim() === '') {
        return null;
    }

    const parsed = parseQuantity(raw);

    if (!parsed.ok) {
        return { error: 'Enter a quantity with at most 4 decimals.' };
    }

    const quantity = parsed.value;

    if (scaled(quantity) <= 0n) {
        return { error: 'Quantity must be greater than zero.' };
    }

    if (scaled(quantity) > scaled(line.remaining)) {
        return { error: `Only ${formatQuantity(line.remaining)} left to return.` };
    }

    const finishesLine = scaled(quantity) === scaled(line.remaining);

    const component = (whole, credited) =>
        finishesLine ? subtractMoney(whole, credited) : fractionOf(whole, quantity, line.quantity);

    return {
        quantity,
        vatable: line.vatable,
        net: component(line.net, line.credited_net),
        vat: component(line.vat, line.credited_vat),
        tds: component(line.tds, line.credited_tds),
    };
}

const credits = computed(() => {
    const rows = {};

    for (const line of props.selectedSale?.lines ?? []) {
        rows[line.sale_line_id] = creditFor(line);
    }

    return rows;
});

/**
 * Bonus units entered against a line, validated against what is left of that
 * line's own bonus quantity. Returns `null` for an untouched box, `{ error }`
 * for an impossible one, otherwise the canonical quantity string.
 */
function bonusFor(line) {
    const raw = bonusQuantities[line.sale_line_id];

    if (raw === undefined || raw === null || String(raw).trim() === '') {
        return null;
    }

    const parsed = parseQuantity(raw);

    if (!parsed.ok) {
        return { error: 'Enter a bonus quantity with at most 4 decimals.' };
    }

    if (scaled(parsed.value) < 0n) {
        return { error: 'Bonus quantity cannot be negative.' };
    }

    if (scaled(parsed.value) > scaled(line.bonus_remaining ?? '0')) {
        return { error: `Only ${formatQuantity(line.bonus_remaining ?? '0')} bonus units left to return.` };
    }

    return { quantity: parsed.value };
}

const bonuses = computed(() => {
    const rows = {};

    for (const line of props.selectedSale?.lines ?? []) {
        rows[line.sale_line_id] = bonusFor(line);
    }

    return rows;
});

const lineErrors = computed(() => [
    ...Object.entries(credits.value)
        .filter(([, credit]) => credit?.error)
        .map(([saleLineId, credit]) => ({ saleLineId, message: credit.error })),
    ...Object.entries(bonuses.value)
        .filter(([, bonus]) => bonus?.error)
        .map(([saleLineId, bonus]) => ({ saleLineId, message: bonus.error })),
]);

const totals = computed(() => {
    let taxable = '0.00';
    let nontaxable = '0.00';
    let vat = '0.00';
    let tds = '0.00';

    for (const credit of Object.values(credits.value)) {
        if (!credit || credit.error) {
            continue;
        }

        if (credit.vatable) {
            taxable = addMoney(taxable, credit.net);
        } else {
            nontaxable = addMoney(nontaxable, credit.net);
        }

        vat = addMoney(vat, credit.vat);
        tds = addMoney(tds, credit.tds);
    }

    const total = addMoney(addMoney(taxable, nontaxable), vat);

    return { taxable, nontaxable, vat, tds, total, credited: subtractMoney(total, tds) };
});

const payloadLines = computed(() =>
    Object.entries(credits.value)
        .filter(([, credit]) => credit && !credit.error)
        .map(([saleLineId, credit]) => ({
            sale_line_id: Number(saleLineId),
            quantity: credit.quantity,
            bonus_quantity: bonuses.value[saleLineId]?.error ? '0' : (bonuses.value[saleLineId]?.quantity ?? '0'),
        })),
);

/**
 * What a refund would have to pay out: the credit note total less any TDS
 * reversed on a linked return (that share never reaches the customer's
 * account), or simply the total on an unlinked one, which carries no TDS.
 * This is the exact amount SalesReturn::postRefund() splits, so the check
 * below and the server's assertExactSplit() agree to the paisa (C3).
 */
const refundDue = computed(() => {
    if (isUnlinked.value) {
        return unlinkedTotals.value && !unlinkedTotals.value.error ? unlinkedTotals.value.total : '0.00';
    }

    return totals.value.credited;
});

const hasRefundSplit = computed(
    () => String(form.refund_cash_amount).trim() !== '' || String(form.refund_bank_amount).trim() !== '',
);

/**
 * The cash+bank split must cover the refund due exactly, with neither leg
 * negative (C3's assertExactSplit, mirrored here so the form says so before
 * the round trip). Leaving both boxes blank is not a split at all: the
 * chosen account then refunds the whole amount, exactly as before.
 */
const refundSplitError = computed(() => {
    if (!hasRefundSplit.value) {
        return null;
    }

    const cash = parseMoney(String(form.refund_cash_amount).trim() === '' ? '0' : form.refund_cash_amount);
    const bank = parseMoney(String(form.refund_bank_amount).trim() === '' ? '0' : form.refund_bank_amount);

    if (!cash.ok || !bank.ok) {
        return 'Enter refund amounts with at most 2 decimals.';
    }

    if (cash.value.startsWith('-') || bank.value.startsWith('-')) {
        return 'A refund amount cannot be negative.';
    }

    if (!moneyEquals(addMoney(cash.value, bank.value), refundDue.value)) {
        return `Cash and bank must add up to the refund due, ${formatMoney(refundDue.value)}.`;
    }

    if (!moneyEquals(bank.value, '0.00') && !form.refund_account_id) {
        return 'Choose the bank account the bank portion is paid out of.';
    }

    return null;
});

const submitUrl = computed(() => {
    if (props.mode === 'request') return '/sales-returns/request';
    if (props.mode === 'unlinked') return '/sales-returns/unlinked';

    return '/sales-returns';
});

const heading = computed(() => {
    if (props.mode === 'request') return 'Request sales return';
    if (props.mode === 'unlinked') return 'Return without a bill';

    return 'New sales return';
});

const submitLabel = computed(() => (props.mode === 'request' ? 'Submit for approval' : 'Create Sales Return'));

const canSubmit = computed(() => {
    if (form.processing || !form.date || refundSplitError.value) {
        return false;
    }

    if (isUnlinked.value) {
        return (
            !!form.customer_id &&
            unlinkedPayloadLines.value.length > 0 &&
            !!unlinkedTotals.value &&
            !unlinkedTotals.value.error
        );
    }

    return !!props.selectedSale && payloadLines.value.length > 0 && lineErrors.value.length === 0;
});

/**
 * Both shapes send the same optional refund split; blanks stay blank so the
 * server reads them as "not given" rather than as a zero.
 */
function refundPayload(data) {
    return {
        refund_account_id: data.refund_account_id || null,
        refund_cash_amount: String(data.refund_cash_amount).trim() === '' ? null : String(data.refund_cash_amount).trim(),
        refund_bank_amount: String(data.refund_bank_amount).trim() === '' ? null : String(data.refund_bank_amount).trim(),
    };
}

function submit() {
    if (isUnlinked.value) {
        form
            .transform((data) => ({
                customer_id: data.customer_id,
                date: data.date,
                reason: data.reason || null,
                store_id: data.store_id || null,
                ...refundPayload(data),
                // The server recomputes and refuses a total that no longer
                // matches what was previewed here (C8).
                expected_total: unlinkedTotals.value.total,
                lines: unlinkedPayloadLines.value.map((line) => ({
                    item_id: line.item_id,
                    item_unit_id: line.item_unit_id,
                    quantity: line.quantity,
                    bonus_quantity: line.bonus_quantity,
                    rate: line.rate,
                })),
            }))
            .post(submitUrl.value, {
                preserveScroll: true,
                onSuccess: () => emit('posted'),
            });

        return;
    }

    form
        .transform((data) => ({
            sale_id: props.selectedSale.id,
            date: data.date,
            reason: data.reason || null,
            store_id: data.store_id || null,
            ...refundPayload(data),
            expected_total: totals.value.total,
            lines: payloadLines.value,
        }))
        .post(submitUrl.value, {
            preserveScroll: true,
            onSuccess: () => emit('posted'),
        });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">{{ heading }}</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>
        <p v-if="form.errors.expected_total" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.expected_total }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div v-if="!isUnlinked && !selectedSale">
                <label class="mb-1 block text-sm font-semibold text-text-base">Original invoice <span class="text-danger">*</span></label>
                <div class="mb-3 flex items-end gap-2">
                    <Input
                        v-model="searchTerm"
                        type="text"
                        placeholder="Invoice number, sale number or customer name"
                        @keydown.enter.prevent="searchSales"
                    />
                    <Button variant="primary" tone="purple" type="button" :loading="searching" @click="searchSales">
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
                    <Button variant="secondary" tone="purple" type="button" @click="pickSale(sale)">Select</Button>
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
                            @click="goToPage(sales.current_page - 1)"
                        >
                            Previous
                        </Button>
                        <Button
                            variant="secondary"
                            tone="purple"
                            type="button"
                            :disabled="!sales.next_page_url"
                            @click="goToPage(sales.current_page + 1)"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>

            <template v-else-if="!isUnlinked">
                <div class="flex items-center justify-between border-[1.5px] border-border bg-bg-subtle px-3 py-2">
                    <div class="text-sm">
                        <span class="font-bold text-text-strong">{{ selectedSale.invoice_number ?? `#${selectedSale.id}` }}</span>
                        <span class="text-text-muted"> - {{ selectedSale.customer ?? '-' }} - {{ selectedSale.date }} - {{ formatMoney(selectedSale.total) }}</span>
                    </div>
                    <Button variant="secondary" tone="purple" type="button" @click="clearSale">Change invoice</Button>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Return date <span class="text-danger">*</span></label>
                        <NepaliDateInput v-model="form.date" required />
                        <p class="mt-1 text-xs text-text-muted">On or after the invoice date {{ selectedSale.date }}.</p>
                        <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                        <Input v-model="form.reason" type="text" placeholder="Optional" />
                        <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">{{ form.errors.reason }}</p>
                    </div>
                </div>

                <div>
                    <div class="mb-2 grid grid-cols-[1fr_80px_80px_80px_90px_100px_100px_100px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <span>Item</span>
                        <span class="text-right">Sold</span>
                        <span class="text-right">Returned</span>
                        <span class="text-right">Left</span>
                        <span class="text-right">Rate</span>
                        <span>Return qty</span>
                        <span>Bonus qty</span>
                        <span class="text-right">Credit</span>
                    </div>

                    <div
                        v-for="line in selectedSale.lines"
                        :key="line.sale_line_id"
                        class="mb-2 grid grid-cols-[1fr_80px_80px_80px_90px_100px_100px_100px] items-center gap-2"
                    >
                        <span class="text-sm text-text-base">
                            {{ line.item }}
                            <span v-if="line.unit" class="text-text-faint">({{ line.unit }})</span>
                        </span>
                        <span class="text-right text-sm text-text-muted">{{ formatQuantity(line.quantity) }}</span>
                        <span class="text-right text-sm text-text-muted">{{ formatQuantity(line.returned) }}</span>
                        <span class="text-right text-sm font-semibold text-text-base">{{ formatQuantity(line.remaining) }}</span>
                        <span class="text-right text-sm text-text-muted">{{ formatRate(line.rate) }}</span>
                        <Input
                            :model-value="quantities[line.sale_line_id] ?? ''"
                            type="text"
                            inputmode="decimal"
                            placeholder="0"
                            @update:model-value="(value) => (quantities[line.sale_line_id] = value)"
                        />
                        <!-- Bonus/free units come back at zero value and only
                             restock (audit section 3 "Sales"); a line that
                             carried no bonus has nothing to return. -->
                        <Input
                            :model-value="bonusQuantities[line.sale_line_id] ?? ''"
                            type="text"
                            inputmode="decimal"
                            :disabled="!line.bonus_quantity || line.bonus_remaining === '0.0000'"
                            :placeholder="line.bonus_remaining && line.bonus_remaining !== '0.0000' ? `0 of ${formatQuantity(line.bonus_remaining)}` : '-'"
                            @update:model-value="(value) => (bonusQuantities[line.sale_line_id] = value)"
                        />
                        <span class="text-right text-sm text-text-base">
                            <template v-if="credits[line.sale_line_id]?.error">
                                <span class="text-danger">{{ credits[line.sale_line_id].error }}</span>
                            </template>
                            <template v-else-if="bonuses[line.sale_line_id]?.error">
                                <span class="text-danger">{{ bonuses[line.sale_line_id].error }}</span>
                            </template>
                            <template v-else-if="credits[line.sale_line_id]">
                                {{ formatMoney(credits[line.sale_line_id].net) }}
                            </template>
                            <template v-else>-</template>
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                        <Combobox
                            :model-value="form.store_id"
                            :options="storeOptions"
                            placeholder="Store the invoice went out of"
                            @update:model-value="(value) => (form.store_id = value)"
                        />
                        <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-5 gap-3 border-t-[1.5px] border-border pt-3 text-sm">
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Taxable</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(totals.taxable) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Non-taxable</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(totals.nontaxable) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">VAT reversed</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(totals.vat) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">TDS reversed</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(totals.tds) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Credit note total</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(totals.total) }}</p>
                    </div>
                </div>
            </template>

            <!-- Unlinked: no invoice to pick, no returnable quantities to cap
                 against. The lines name an item and a rate directly and are
                 taxed at the company VAT rate, exactly like a fresh sale of
                 the same goods (SalesReturn::postUnlinked()). -->
            <template v-if="isUnlinked">
                <p class="border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-sm text-text-muted">
                    Use this for goods returned against a bill this system never issued: a pre-cutover sale, a walk-in
                    who lost their receipt, or a paper invoice from before you went live. It posts a credit note
                    straight away at the company VAT rate of {{ invoiceSettings.default_vat_rate }}%.
                </p>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Customer <span class="text-danger">*</span></label>
                        <Combobox
                            :model-value="form.customer_id"
                            :options="customerOptions"
                            placeholder="Who is being credited"
                            @update:model-value="(value) => (form.customer_id = value)"
                        />
                        <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Return date <span class="text-danger">*</span></label>
                        <NepaliDateInput v-model="form.date" required />
                        <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                        <Combobox
                            :model-value="form.store_id"
                            :options="storeOptions"
                            placeholder="Default store"
                            @update:model-value="(value) => (form.store_id = value)"
                        />
                        <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <Input v-model="form.reason" type="text" placeholder="Optional" />
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">{{ form.errors.reason }}</p>
                </div>

                <div>
                    <div class="mb-2 grid grid-cols-[1fr_140px_100px_100px_110px_40px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <span>Item</span>
                        <span>Unit</span>
                        <span>Quantity</span>
                        <span>Bonus qty</span>
                        <span>Rate</span>
                        <span></span>
                    </div>

                    <div
                        v-for="(line, index) in unlinkedLines"
                        :key="index"
                        class="mb-2 grid grid-cols-[1fr_140px_100px_100px_110px_40px] items-center gap-2"
                    >
                        <Combobox
                            :model-value="line.item_id"
                            :options="itemOptions"
                            placeholder="Pick an item"
                            @update:model-value="(value) => onUnlinkedItemPicked(line, value)"
                        />
                        <Combobox
                            :model-value="line.item_unit_id"
                            :options="unitOptionsFor(line)"
                            placeholder="Base unit"
                            @update:model-value="(value) => (line.item_unit_id = value)"
                        />
                        <Input v-model="line.quantity" type="text" inputmode="decimal" placeholder="0" />
                        <Input v-model="line.bonus_quantity" type="text" inputmode="decimal" placeholder="0" />
                        <Input v-model="line.rate" type="text" inputmode="decimal" placeholder="0.00" />
                        <Button variant="secondary" tone="danger" type="button" @click="removeUnlinkedLine(index)">
                            <X class="size-4" />
                        </Button>
                    </div>

                    <Button variant="secondary" tone="purple" type="button" @click="addUnlinkedLine">Add line</Button>
                    <p v-if="form.errors.lines" class="mt-1 text-sm text-danger">{{ form.errors.lines }}</p>
                </div>

                <div v-if="unlinkedTotals?.error" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                    {{ unlinkedTotals.error }}
                </div>

                <div v-else-if="unlinkedTotals" class="grid grid-cols-4 gap-3 border-t-[1.5px] border-border pt-3 text-sm">
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Taxable</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(unlinkedTotals.taxable_amount) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Non-taxable</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(unlinkedTotals.nontaxable_amount) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">VAT</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(unlinkedTotals.vat_amount) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Credit note total</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(unlinkedTotals.total) }}</p>
                    </div>
                </div>
            </template>

            <!-- The refund, shared by both shapes: either a single account
                 pays the whole credit back (leave both amounts blank), or the
                 cash and bank legs are entered and must add up to the refund
                 due exactly (CONTRACTS C3, assertExactSplit). -->
            <div v-if="isUnlinked || selectedSale" class="grid grid-cols-3 gap-4 border-t-[1.5px] border-border pt-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Refund via (optional)</label>
                    <Combobox
                        :model-value="form.refund_account_id"
                        :options="refundAccountOptions"
                        placeholder="No refund - credit note only"
                        @update:model-value="(value) => (form.refund_account_id = value)"
                    />
                    <p class="mt-1 text-xs text-text-muted">Cash and bank accounts only.</p>
                    <p v-if="form.errors.refund_account_id" class="mt-1 text-sm text-danger">{{ form.errors.refund_account_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Refund in cash</label>
                    <Input v-model="form.refund_cash_amount" type="text" inputmode="decimal" placeholder="Leave blank for no split" />
                    <p v-if="form.errors.refund_cash_amount" class="mt-1 text-sm text-danger">{{ form.errors.refund_cash_amount }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Refund from bank</label>
                    <Input v-model="form.refund_bank_amount" type="text" inputmode="decimal" placeholder="Leave blank for no split" />
                    <p class="mt-1 text-xs text-text-muted">Refund due: {{ formatMoney(refundDue) }}</p>
                    <p v-if="form.errors.refund_bank_amount" class="mt-1 text-sm text-danger">{{ form.errors.refund_bank_amount }}</p>
                </div>
                <p v-if="refundSplitError" class="col-span-3 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                    {{ refundSplitError }}
                </p>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="!canSubmit">
                    {{ submitLabel }}
                </Button>
            </div>
        </form>
    </Card>
</template>
