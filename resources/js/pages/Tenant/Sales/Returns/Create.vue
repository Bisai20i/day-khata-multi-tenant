<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import SaleReturnCreateLinkedLines from '@/components/sales/SaleReturnCreateLinkedLines.vue';
import SaleReturnCreateRefundFields from '@/components/sales/SaleReturnCreateRefundFields.vue';
import SaleReturnCreateSalePicker from '@/components/sales/SaleReturnCreateSalePicker.vue';
import SaleReturnCreateUnlinkedFields from '@/components/sales/SaleReturnCreateUnlinkedFields.vue';
import { useSaleReturnCreateLinked } from '@/composables/useSaleReturnCreateLinked';
import { useSaleReturnCreateSalePicker } from '@/composables/useSaleReturnCreateSalePicker';
import { useSaleReturnCreateUnlinked } from '@/composables/useSaleReturnCreateUnlinked';
import { addMoney, formatMoney, moneyEquals, parseMoney } from '@/lib/money';
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

const { saleRows, searchTerm, searching, searchSales, goToPage, pickSale, clearSale } = useSaleReturnCreateSalePicker(props);

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

const {
    unlinkedLines,
    itemOptions,
    customerOptions,
    unitOptionsFor,
    addUnlinkedLine,
    removeUnlinkedLine,
    onUnlinkedItemPicked,
    unlinkedPayloadLines,
    unlinkedTotals,
} = useSaleReturnCreateUnlinked(props, form);

const { quantities, bonusQuantities, credits, bonuses, lineErrors, totals, payloadLines } = useSaleReturnCreateLinked(props, form);

const refundAccountOptions = computed(() =>
    props.refundAccounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);
const storeOptions = computed(() => props.stores.map((store) => ({ value: store.id, label: store.name })));

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

const submitLabel = computed(() => (props.mode === 'request' ? 'Submit for approval' : 'Post sales return'));

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
            <div>
                <h3 class="text-base font-bold text-text-strong">{{ heading }}</h3>
                <p class="mt-0.5 text-sm text-text-muted">
                    <template v-if="mode === 'request'">Sends the return to an approver. Nothing is posted until it is approved.</template>
                    <template v-else-if="mode === 'unlinked'">For goods returned with no bill from this system. Posts immediately as a credit note.</template>
                    <template v-else>Pick the original bill, then enter how many units came back. Posting adds the stock back and credits the customer.</template>
                </p>
            </div>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>
        <p v-if="form.errors.expected_total" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.expected_total }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <SaleReturnCreateSalePicker
                v-if="!isUnlinked && !selectedSale"
                v-model:search-term="searchTerm"
                :sales="sales"
                :sale-rows="saleRows"
                :searching="searching"
                @search="searchSales"
                @page="goToPage"
                @pick="pickSale"
            />

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

                <SaleReturnCreateLinkedLines
                    :lines="selectedSale.lines"
                    :quantities="quantities"
                    :bonus-quantities="bonusQuantities"
                    :credits="credits"
                    :bonuses="bonuses"
                    @update:quantity="(saleLineId, value) => (quantities[saleLineId] = value)"
                    @update:bonus-quantity="(saleLineId, value) => (bonusQuantities[saleLineId] = value)"
                />

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

            <SaleReturnCreateUnlinkedFields
                v-if="isUnlinked"
                :form="form"
                :invoice-settings="invoiceSettings"
                :customer-options="customerOptions"
                :store-options="storeOptions"
                :item-options="itemOptions"
                :unlinked-lines="unlinkedLines"
                :unlinked-totals="unlinkedTotals"
                :unit-options-for="unitOptionsFor"
                @add-line="addUnlinkedLine"
                @remove-line="removeUnlinkedLine"
                @item-picked="onUnlinkedItemPicked"
            />

            <SaleReturnCreateRefundFields
                v-if="isUnlinked || selectedSale"
                :form="form"
                :refund-account-options="refundAccountOptions"
                :refund-due="refundDue"
                :refund-split-error="refundSplitError"
            />

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="!canSubmit" :loading="form.processing">
                    {{ submitLabel }}
                </Button>
            </div>
        </form>
    </Card>
</template>
