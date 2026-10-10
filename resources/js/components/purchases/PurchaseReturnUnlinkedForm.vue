<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import { addMoney, formatMoney, formatQuantity, moneyEquals, parseMoney } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';
import {
    blankUnlinkedLine,
    isFilledUnlinkedLine,
    unlinkedModeNeedsBank,
    unlinkedQuoteQuery,
    unlinkedRefundModeOptions,
} from '@/lib/purchaseReturnUnlinked';

/**
 * Unlinked purchase return (item 4): goods from opening stock, or bought
 * before go-live, that have no Purchase row to point at. The clerk names the
 * items, and the server values each line at the item's weighted average cost
 * unless a rate is typed here.
 *
 * The total always comes from the server (GET purchase-returns/unlinked/quote,
 * flags G-16): an average-cost line is priced there at 12 decimals, which the
 * browser cannot reproduce. The quoted total goes back as `expected_total`,
 * so if stock costs move between the quote and the save, the save is refused
 * and the clerk sees the new figure instead of booking a different one.
 */
const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    defaultVatRate: { type: String, default: '13.00' },
});

const emit = defineEmits(['cancel', 'posted']);

const supplierOptions = computed(() => props.suppliers.map((supplier) => ({ value: supplier.id, label: supplier.name })));
const storeOptions = computed(() => props.stores.map((store) => ({ value: store.id, label: store.name })));
const bankAccountOptions = computed(() =>
    props.bankAccounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);
const itemOptions = computed(() =>
    props.items.map((item) => ({ value: item.id, label: item.name, searchValue: `${item.name} ${item.barcode ?? ''}` })),
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
        ...(item.units ?? []).map((unit) => ({ value: unit.id, label: `${unit.name} (x${formatQuantity(unit.conversion_factor)})` })),
    ];
}

const form = useForm({
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
function setItem(index, itemId) {
    form.lines[index].item_id = itemId;
    form.lines[index].item_unit_id = null;
}

function addLine() {
    form.lines.push(blankUnlinkedLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);

    if (form.lines.length === 0) {
        form.lines.push(blankUnlinkedLine());
    }
}

/** The rows that carry an item and a quantity, each keeping its row index. */
const filledLines = computed(() => form.lines.map((line, index) => ({ line, index })).filter(({ line }) => isFilledUnlinkedLine(line)));

// ---------------------------------------------------------------------------
// Server quote
// ---------------------------------------------------------------------------

const quote = ref(null);
const quoteError = ref(null);
const quoting = ref(false);
let quoteTimer = null;
let quoteRequest = 0;

async function fetchQuote() {
    const request = ++quoteRequest;

    if (filledLines.value.length === 0 || !form.date) {
        quote.value = null;
        quoteError.value = null;
        quoting.value = false;
        return;
    }

    quoting.value = true;

    try {
        const query = unlinkedQuoteQuery(
            { date: form.date, vat_rate: form.vat_rate },
            filledLines.value.map(({ line }) => line),
        );
        const response = await fetch(`/purchase-returns/unlinked/quote?${query}`, { headers: { Accept: 'application/json' } });
        const body = await response.json();

        // A newer edit already asked again; this answer is for old lines.
        if (request !== quoteRequest) {
            return;
        }

        if (!response.ok) {
            quote.value = null;
            quoteError.value = body.message ?? 'The return could not be priced. Check the lines.';
            return;
        }

        quote.value = body;
        quoteError.value = null;
    } catch {
        if (request === quoteRequest) {
            quote.value = null;
            quoteError.value = 'Could not reach the server to price the return. Try again.';
        }
    } finally {
        if (request === quoteRequest) {
            quoting.value = false;
        }
    }
}

watch(
    () => [form.date, form.vat_rate, JSON.stringify(filledLines.value.map(({ line }) => line))],
    () => {
        clearTimeout(quoteTimer);
        quote.value = null;
        quoteTimer = setTimeout(fetchQuote, 300);
    },
);

onBeforeUnmount(() => clearTimeout(quoteTimer));

/** Quoted line value per FORM row index, so empty rows never shift the values. */
const lineValues = computed(() => {
    const values = {};

    if (!quote.value) {
        return values;
    }

    filledLines.value.forEach(({ index }, position) => {
        values[index] = quote.value.lines[position] ?? null;
    });

    return values;
});

const total = computed(() => quote.value?.total ?? null);

// ---------------------------------------------------------------------------
// Refund
// ---------------------------------------------------------------------------

const refundModeOptions = computed(() => unlinkedRefundModeOptions({ canSplit: total.value !== null, hasSupplier: !!form.supplier_id }));

// A mode that stops being offered (no exact total to split, supplier cleared)
// falls back to cash rather than silently posting something unavailable.
watch(refundModeOptions, (options) => {
    if (!options.some((option) => option.value === form.payment_mode)) {
        form.payment_mode = 'cash';
    }
});

const needsBank = computed(() => unlinkedModeNeedsBank(form.payment_mode));

/**
 * The cash + bank split has to land EXACTLY on the total (the server refuses
 * anything else through DocumentCalculator::assertExactSplit). Exact string
 * comparison, never a float subtraction inside a tolerance.
 */
const splitError = computed(() => {
    if (form.payment_mode !== 'partial' || total.value === null) {
        return null;
    }

    const cash = parseMoney(form.cash_amount === '' ? '0' : form.cash_amount);
    const bank = parseMoney(form.bank_amount === '' ? '0' : form.bank_amount);

    if (!cash.ok || !bank.ok) {
        return 'Enter the cash and bank amounts as plain rupee figures.';
    }

    const split = addMoney(cash.value, bank.value);

    return moneyEquals(split, total.value) ? null : `Cash plus bank is ${formatMoney(split)}, but ${formatMoney(total.value)} is due.`;
});

const canSubmit = computed(
    () => !form.processing && !quoting.value && total.value !== null && form.reason.trim() !== '' && !splitError.value,
);

function submit() {
    form.transform((data) => ({
        date: data.date,
        supplier_id: data.supplier_id || null,
        store_id: data.store_id || null,
        reason: data.reason,
        vat_rate: data.vat_rate === '' ? null : data.vat_rate,
        payment_mode: data.payment_mode,
        bank_account_id: unlinkedModeNeedsBank(data.payment_mode) ? data.bank_account_id || null : null,
        cash_amount: data.payment_mode === 'partial' ? data.cash_amount || '0' : null,
        bank_amount: data.payment_mode === 'partial' ? data.bank_amount || '0' : null,
        expected_total: total.value,
        // Quantities and rates go out as typed, never through Number().
        lines: data.lines
            .filter((line) => isFilledUnlinkedLine(line))
            .map((line) => ({
                item_id: line.item_id,
                item_unit_id: line.item_unit_id || null,
                quantity: line.quantity,
                rate: line.rate === '' ? null : line.rate,
            })),
    })).post('/purchase-returns/unlinked', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
        // A "total changed" refusal means costs moved: price it again.
        onError: () => fetchQuote(),
    });
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <p v-if="form.errors.lines || form.errors.expected_total" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines ?? form.errors.expected_total }}
        </p>

        <Card variant="panel" class="!p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <Label class="mb-1">Return date (BS) <span class="text-danger">*</span></Label>
                <NepaliDateInput v-model="form.date" required />
                <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
            </div>
            <div>
                <Label class="mb-1">Supplier</Label>
                <Combobox :model-value="form.supplier_id" :options="supplierOptions" placeholder="Optional" @update:model-value="(v) => (form.supplier_id = v)" />
                <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-danger">{{ form.errors.supplier_id }}</p>
            </div>
            <div>
                <Label class="mb-1">Store</Label>
                <Combobox :model-value="form.store_id" :options="storeOptions" placeholder="Default store" @update:model-value="(v) => (form.store_id = v)" />
                <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
            </div>
            <div>
                <Label class="mb-1">VAT rate (%)</Label>
                <Input v-model="form.vat_rate" type="number" min="0" max="100" step="0.01" />
                <p v-if="form.errors.vat_rate" class="mt-1 text-sm text-danger">{{ form.errors.vat_rate }}</p>
            </div>
            <div>
                <Label class="mb-1">Reason for return <span class="text-danger">*</span></Label>
                <Input v-model="form.reason" type="text" maxlength="255" placeholder="e.g. Damaged goods" required />
                <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">{{ form.errors.reason }}</p>
            </div>
        </div>
        </Card>

        <Card variant="panel" class="!p-4">
            <template #title>
                <div class="flex items-center gap-1">
                    <span>Items to return <span class="text-danger">*</span></span>
                    <InfoTip
                        text="Leave the rate blank to value a line at the item's weighted average cost on the return date. Quantities are in the unit picked on that line."
                    />
                </div>
            </template>
            <div class="mb-2 grid grid-cols-[1fr_180px_120px_140px_120px_40px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                <span>Item</span>
                <span>Unit</span>
                <span>Quantity</span>
                <span>Rate (Rs.)</span>
                <span>Line value</span>
                <span class="sr-only">Remove</span>
            </div>

            <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_180px_120px_140px_120px_40px] items-start gap-2">
                <div>
                    <Combobox :model-value="line.item_id" :options="itemOptions" placeholder="Select an item" @update:model-value="(v) => setItem(index, v)" />
                    <p v-if="form.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">{{ form.errors[`lines.${index}.item_id`] }}</p>
                </div>
                <Combobox
                    :model-value="line.item_unit_id"
                    :options="unitOptionsFor(line.item_id)"
                    placeholder="Base unit"
                    @update:model-value="(v) => (form.lines[index].item_unit_id = v)"
                />
                <div>
                    <Input v-model="form.lines[index].quantity" type="number" min="0" step="0.0001" placeholder="0" />
                    <p v-if="form.errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">{{ form.errors[`lines.${index}.quantity`] }}</p>
                </div>
                <div>
                    <Input v-model="form.lines[index].rate" type="number" min="0" step="0.0001" placeholder="Average cost" />
                    <p v-if="form.errors[`lines.${index}.rate`]" class="mt-1 text-xs text-danger">{{ form.errors[`lines.${index}.rate`] }}</p>
                </div>
                <span class="pt-2 text-sm text-text-muted">{{ lineValues[index] ? formatMoney(lineValues[index]) : '-' }}</span>
                <button
                    type="button"
                    class="mt-1 border-[1.5px] border-border bg-white p-2 text-text-muted hover:border-danger hover:text-danger"
                    :aria-label="`Remove item row ${index + 1}`"
                    :title="`Remove item row ${index + 1}`"
                    @click="removeLine(index)"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>

            <Button variant="secondary" tone="purple" type="button" @click="addLine">
                <Plus class="size-4" aria-hidden="true" />
                Add another item
            </Button>
        </Card>

        <Card variant="panel" title="Refund" class="!p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <Label>Refund received as <span class="text-danger">*</span></Label>
                    <InfoTip v-if="form.payment_mode === 'credit'" text="Reduces what you owe this supplier. No money changes hands now." />
                </div>
                <Select :model-value="form.payment_mode" :options="refundModeOptions" @update:model-value="(v) => (form.payment_mode = v)" />
                <p v-if="form.errors.payment_mode" class="mt-1 text-sm text-danger">{{ form.errors.payment_mode }}</p>
            </div>
            <div v-if="needsBank">
                <Label class="mb-1">Bank account <span class="text-danger">*</span></Label>
                <Combobox
                    :model-value="form.bank_account_id"
                    :options="bankAccountOptions"
                    placeholder="Select the bank account"
                    @update:model-value="(v) => (form.bank_account_id = v)"
                />
                <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
            </div>
            <template v-if="form.payment_mode === 'partial'">
                <div>
                    <Label class="mb-1">Refunded in cash (Rs.)</Label>
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                    <p v-if="form.errors.cash_amount" class="mt-1 text-sm text-danger">{{ form.errors.cash_amount }}</p>
                </div>
                <div>
                    <Label class="mb-1">Refunded to bank (Rs.)</Label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                    <p v-if="form.errors.bank_amount" class="mt-1 text-sm text-danger">{{ form.errors.bank_amount }}</p>
                </div>
            </template>
        </div>
        </Card>

        <p v-if="splitError" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">{{ splitError }}</p>
        <p v-if="quoteError" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">{{ quoteError }}</p>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t-[1.5px] border-border pt-3">
            <div v-if="quote" class="text-sm text-text-muted">
                Taxable {{ formatMoney(quote.taxable_amount) }} + Exempt {{ formatMoney(quote.nontaxable_amount) }} + VAT
                {{ formatMoney(quote.vat_amount) }} =
                <strong class="text-text-strong">{{ formatMoney(quote.total) }}</strong>
            </div>
            <div v-else class="text-sm text-text-muted">
                {{ quoting ? 'Working out the total...' : 'Add an item and a quantity to see the total.' }}
            </div>

            <div class="flex items-center gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="!canSubmit">Create purchase return</Button>
            </div>
        </div>
    </form>
</template>
