<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { useToast } from '@/composables/useToast';
import { addMoney, calculateDocument, formatMoney, moneyEquals, parseMoney, percentOf } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    // For the quick add-item modal (item 9): a category is required to
    // create an item, so this form offers the same short list Items/
    // Index.vue uses rather than sending the clerk away to that page.
    itemCategories: { type: Array, default: () => [] },
    // Two narrow pickers instead of the whole chart of accounts: money can only
    // leave through an asset account, and TDS can only be withheld into a
    // liability, so the server sends each list already filtered.
    bankAccounts: { type: Array, default: () => [] },
    tdsAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    // default_vat_rate and default_store_id, so the form opens on the tenant's
    // own settings instead of a hardcoded 13 and "no store".
    settings: { type: Object, default: () => ({}) },
    // The one closed fiscal year currently reopened for correction, or
    // null - this create form only ever offers this single alternate to
    // the currently open year (never any other closed year), per the
    // locked design decision in plans/invoicing-settings-sale-purchase-ux.
    // md ("Locked decisions" #3 / Phase D's recommended option (a)).
    correctionFiscalYear: { type: Object, default: null },
    // Set by the parent Index page when it bounces back here after the
    // inline "+ New supplier" modal redirects away and back (see
    // submitSupplier() below) - restores the in-progress draft that would
    // otherwise be lost when Index re-mounts this component.
    initialDraft: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'posted']);
const { toast } = useToast();
const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const isCorrectionSelected = computed(
    () => !!props.correctionFiscalYear && form.fiscal_year_id === props.correctionFiscalYear.id,
);
const fiscalYearOptions = computed(() =>
    props.correctionFiscalYear
        ? [{ value: props.correctionFiscalYear.id, label: `${props.correctionFiscalYear.name} (reopened for correction)` }]
        : [],
);

const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
// searchValue lets a barcode match the item even though it isn't shown in
// the option's label - see Combobox.vue's searchText().
const itemOptions = computed(() =>
    props.items.map((i) => ({
        value: i.id,
        label: `${i.name} (${i.unit})`,
        searchValue: i.barcode ? `${i.name} ${i.barcode}` : i.name,
    })),
);
function accountOptions(accounts) {
    return accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    }));
}

const bankAccountOptions = computed(() => accountOptions(props.bankAccounts));
const tdsAccountOptions = computed(() => accountOptions(props.tdsAccounts));

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial (Cash + Bank)' },
    { value: 'credit', label: 'Credit' },
];

const itemsById = computed(() => new Map(props.items.map((i) => [i.id, i])));

function emptyLine() {
    // item_unit_id '' means "the item's own base unit" - see Sales/
    // Create.vue's identical emptyLine() for the full rationale.
    // bonus_quantity (item 9): free units received alongside the paid ones -
    // stocked at (quantity + bonus) x factor but never billed (item 3), so
    // it is not part of previewLines/calculateDocument at all. note (item 9)
    // is a free-text per-line remark stored as-is on purchase_lines.note.
    return {
        item_id: null,
        item_unit_id: '',
        quantity: '',
        bonus_quantity: '',
        rate: '',
        discount: '',
        discount_type: 'flat',
        note: '',
    };
}

// Mirrors Sales/Create.vue's unitOptionsFor() exactly, adapted for this
// file's itemsById being a Map rather than a plain object.
function unitOptionsFor(item) {
    if (!item) return [];

    return [{ value: '', label: item.unit }, ...(item.units ?? []).map((u) => ({ value: u.id, label: u.name }))];
}

// Mirrors Sales/Create.vue's selectLineUnit() exactly, except this form
// auto-fills from a unit's purchase_rate override (not sale_rate). Switching
// BACK to the base unit restores the item's own purchase rate, which the old
// version left showing the alternate unit's rate against a base quantity.
function selectLineUnit(line, unitId) {
    line.item_unit_id = unitId;

    const item = itemsById.value.get(line.item_id);

    if (unitId === '' || unitId === null) {
        if (item?.purchase_rate != null) {
            line.rate = String(item.purchase_rate);
        }

        return;
    }

    const unit = item?.units?.find((u) => u.id === unitId);
    if (unit?.purchase_rate != null) {
        line.rate = String(unit.purchase_rate);
    }
}

// The stored conversion factor for the unit this line is entered in, as the
// decimal string the money module expects. '1' means the item's base unit.
function conversionFactorFor(line) {
    if (line.item_unit_id === '' || line.item_unit_id === null) {
        return '1';
    }

    const unit = itemsById.value.get(line.item_id)?.units?.find((u) => u.id === line.item_unit_id);

    return unit?.conversion_factor != null ? String(unit.conversion_factor) : '1';
}

// Mirrors Sales/Create.vue's selectLineItem() exactly.
function selectLineItem(line, itemId) {
    line.item_id = itemId;
    line.item_unit_id = '';
}

function defaultFormData() {
    return {
        supplier_id: null,
        store_id: props.settings.default_store_id ?? null,
        bill_number: '',
        pan_number: '',
        chalani_number: '',
        // todayInKathmandu(), never new Date().toISOString(): between 00:00 and
        // 05:44 Nepal time the UTC day is still yesterday, which dated every
        // bill entered early in the morning a day early.
        date: todayInKathmandu(),
        payment_mode: 'credit',
        bank_account_id: null,
        discount: '',
        discount_type: 'flat',
        vat_rate: String(props.settings.default_vat_rate ?? '13'),
        // PAN / non-VAT purchase mode: every line lands in the exempt column
        // and no VAT is charged at all. Re-defaulted from the supplier's own
        // is_vat_registered flag the moment a supplier is picked
        // (selectSupplier), which is the only sane default - an unregistered
        // supplier cannot legally issue a VAT bill.
        force_non_taxable: false,
        cash_amount: '',
        bank_amount: '',
        tds_account_id: null,
        // A rate takes precedence over a typed amount: the server recomputes
        // (taxable + nontaxable) x rate itself and stores both.
        tds_rate: '',
        tds_amount: '',
        narration: '',
        // Blank fiscal_year_id posts into whichever year is currently
        // open; the only other value the picker offers is
        // correctionFiscalYear's id, in which case reason becomes required
        // (see isCorrectionSelected/submit()).
        fiscal_year_id: null,
        reason: '',
        lines: [emptyLine()],
    };
}

const form = useForm(props.initialDraft ?? defaultFormData());

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

// --- Scan-to-add barcode (item 9) --------------------------------------
// Calls ItemController::lookupBarcode() (GET /items/lookup-barcode), which
// checks item_units.barcode first (a specific alternate unit) then falls
// back to items.barcode (the base unit, item_unit_id: null). Response
// shape and the 404 contract are documented in the T13 items 5-6 pass's
// cross-file request note. Only items already loaded into this page's own
// itemsById are addable - a match the browser has never seen (e.g. an
// inactive item) is reported through barcodeError rather than silently
// skipped.
const barcodeCode = ref('');
const barcodeError = ref(null);
const barcodeScanning = ref(false);

async function scanBarcode() {
    const code = barcodeCode.value.trim();
    if (code === '') {
        return;
    }

    barcodeError.value = null;
    barcodeScanning.value = true;

    try {
        const response = await fetch(`/items/lookup-barcode?code=${encodeURIComponent(code)}`, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();

        if (!response.ok) {
            barcodeError.value = body.message ?? 'No item matches that barcode.';
            return;
        }

        const matchedItem = itemsById.value.get(body.item.id);
        if (!matchedItem) {
            barcodeError.value = `"${body.item.name}" is not available on this purchase (it may be inactive).`;
            return;
        }

        const target = form.lines.find((line) => line.item_id === null) ?? emptyLine();
        if (!form.lines.includes(target)) {
            form.lines.push(target);
        }
        target.item_id = matchedItem.id;
        target.item_unit_id = body.item_unit_id ?? '';
        selectLineUnit(target, target.item_unit_id);
        if (target.quantity === '') {
            target.quantity = '1';
        }
    } catch {
        barcodeError.value = 'Could not reach the server. Try again.';
    } finally {
        barcodeScanning.value = false;
        barcodeCode.value = '';
    }
}

function isVatable(line) {
    return itemsById.value.get(line.item_id)?.is_vatable ?? false;
}

const suppliersById = computed(() => new Map(props.suppliers.map((s) => [s.id, s])));

// Picking a supplier re-defaults the PAN / non-VAT toggle from that
// supplier's registration: a supplier who is not VAT registered can only
// issue a PAN bill, so claiming VAT credit on their bill would be a false
// claim. The toggle stays editable afterwards - a registered supplier can
// still hand over a PAN bill for an exempt purchase.
function selectSupplier(supplierId) {
    form.supplier_id = supplierId;
    form.force_non_taxable = supplierId == null ? false : suppliersById.value.get(supplierId)?.is_vat_registered === false;
}

// The ONE source of truth for what this bill adds up to, step for step
// identical to App\Support\Billing\DocumentCalculator on the server. The old
// preview summed unrounded floats and never rounded the VAT at all, so a single
// line of 1,001.50 previewed VAT 130.19 while the server booked 130.20
// (audit P0-8). Nothing here parses money through Number().
const previewLines = computed(() =>
    form.lines.map((line) => ({
        quantity: line.quantity === '' ? '0' : line.quantity,
        rate: line.rate === '' ? '0' : line.rate,
        discount: line.discount === '' ? '0' : line.discount,
        discount_type: line.discount_type,
        vatable: isVatable(line),
        conversion_factor: conversionFactorFor(line),
    })),
);

// The header as the calculator sees it BEFORE any TDS: TDS never changes the
// bill's taxable, non-taxable, VAT or grand total, only what is left to pay,
// so the base pass below is what the TDS rate is applied to. Same two-pass
// shape as Purchase::post() on the server, deliberately.
function previewHeader(tdsAmount) {
    return {
        vat_rate: form.vat_rate === '' ? '0' : form.vat_rate,
        discount: form.discount === '' ? '0' : form.discount,
        discount_type: form.discount_type,
        force_non_taxable: form.force_non_taxable,
        tds_amount: tdsAmount,
    };
}

const basePreview = computed(() => calculateDocument(previewLines.value, previewHeader('0')));

// (taxable + nontaxable) x rate, one rounding, exactly as Money::percent()
// does it on the server. A typed amount is used as-is when no rate is given.
// percentOf throws on a rate the money module refuses (more than 2 decimals):
// that shows up as a plain 0.00 preview, and the server's decimal:0,2 rule is
// what reports it as a field error on submit.
const tdsPreviewAmount = computed(() => {
    if (form.tds_rate === '' || form.tds_rate === null) {
        return form.tds_amount === '' ? '0' : form.tds_amount;
    }

    if (!basePreview.value.ok) {
        return '0';
    }

    try {
        return percentOf(
            addMoney(basePreview.value.totals.taxable_amount, basePreview.value.totals.nontaxable_amount),
            form.tds_rate,
        );
    } catch {
        return '0';
    }
});

const preview = computed(() => calculateDocument(previewLines.value, previewHeader(tdsPreviewAmount.value)));

const totals = computed(() => (preview.value.ok ? preview.value.totals : null));
// An incomplete bill (no lines filled in yet) is not an error worth shouting
// about; anything else the calculator refuses is shown to the user as typed.
const previewError = computed(() =>
    preview.value.ok || preview.value.reason === 'subtotal_not_positive' ? null : preview.value.message,
);

function money(value) {
    return value == null ? '-' : formatMoney(value);
}

function lineTotalText(index) {
    return totals.value ? money(totals.value.lines[index].line_total) : '-';
}

// %/Rs toggle. Percentage to flat keeps the exact rupee amount the calculator
// already worked out, so nothing is recomputed in the browser. Flat to
// percentage clears the field instead of dividing: a rupee amount has no exact
// percentage, and inventing one here is how the preview and the bill drift
// apart. The raw value plus its type is what is submitted either way.
function toggleLineDiscountType(index) {
    const line = form.lines[index];

    if (line.discount_type === 'percentage') {
        line.discount = totals.value ? totals.value.lines[index].discount_amount : '';
        line.discount_type = 'flat';
    } else {
        line.discount = '';
        line.discount_type = 'percentage';
    }
}

function toggleHeaderDiscountType() {
    if (form.discount_type === 'percentage') {
        form.discount = totals.value ? totals.value.header_discount : '';
        form.discount_type = 'flat';
    } else {
        form.discount = '';
        form.discount_type = 'percentage';
    }
}

const showBankAccount = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialSplit = computed(() => form.payment_mode === 'partial');

// A partial settlement has to land EXACTLY on the amount due (the grand total
// less any TDS withheld). The server refuses anything else outright
// (DocumentCalculator::assertExactSplit), so the form says so up front rather
// than bouncing the user back from a 422. Exact string comparison, never a
// float subtraction inside a 0.01 tolerance (audit P0-4).
const partialSplitError = computed(() => {
    if (form.payment_mode !== 'partial' || !totals.value) {
        return null;
    }

    const cash = parseMoney(form.cash_amount === '' ? '0' : form.cash_amount);
    const bank = parseMoney(form.bank_amount === '' ? '0' : form.bank_amount);

    if (!cash.ok || !bank.ok) {
        return 'Enter the cash and bank amounts as plain rupee figures.';
    }

    const split = addMoney(cash.value, bank.value);

    return moneyEquals(split, totals.value.settlement_due)
        ? null
        : `Cash plus bank is ${formatMoney(split)}, but ${formatMoney(totals.value.settlement_due)} is due.`;
});

const canSubmit = computed(
    () => preview.value.ok
        && partialSplitError.value === null
        && (!isCorrectionSelected.value || form.reason.trim().length > 0),
);

// Every numeric field is submitted as the string the user typed. Number()
// would turn "1.005" into a float that no longer round-trips, and the server's
// decimal:0,N rules exist precisely to reject over-precise input rather than
// let MySQL round it away (audit P0-5).
function submit(print = false) {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        discount: data.discount === '' ? '0' : data.discount,
        vat_rate: data.vat_rate === '' ? '0' : data.vat_rate,
        cash_amount: data.payment_mode === 'partial' ? (data.cash_amount === '' ? '0' : data.cash_amount) : undefined,
        bank_amount: data.payment_mode === 'partial' ? (data.bank_amount === '' ? '0' : data.bank_amount) : undefined,
        // The rate wins on the server too, so the typed amount is not sent
        // alongside it: two sources for one figure is how a bill ends up
        // withholding something nobody chose.
        tds_rate: data.tds_rate === '' ? undefined : data.tds_rate,
        tds_amount: data.tds_rate === '' ? (data.tds_amount === '' ? '0' : data.tds_amount) : undefined,
        // The total the user is looking at. The server recomputes and refuses
        // the save if it lands anywhere else (CONTRACTS C8).
        expected_total: preview.value.totals.total,
        fiscal_year_id: data.fiscal_year_id || undefined,
        reason: isCorrectionSelected.value ? data.reason : undefined,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            item_unit_id: line.item_unit_id || null,
            quantity: line.quantity,
            bonus_quantity: line.bonus_quantity === '' ? '0' : line.bonus_quantity,
            rate: line.rate,
            discount: line.discount === '' ? '0' : line.discount,
            discount_type: line.discount_type,
            note: line.note === '' ? null : line.note,
        })),
    })).post('/purchases', {
        preserveScroll: true,
        onSuccess: (page) => {
            // The server flashes the bill it just posted (CONTRACTS C11).
            // Guessing "highest id on page 1" printed the wrong bill for a
            // back-dated entry or a second till (audit P0-6).
            const printUrl = page.props.flash?.created?.print_url;
            if (print && printUrl) {
                window.open(printUrl, '_blank');
            }
            emit('posted');
        },
    });
}

// --- Inline "+ New supplier" -------------------------------------------
// Mirrors Sales/Create.vue's inline "+ New customer" modal exactly - see
// that file for the full rationale on the sessionStorage draft bridge.
const DRAFT_KEY = 'purchases-create-draft';
const PENDING_SUPPLIER_KEY = 'purchases-create-pending-supplier';

const supplierModalOpen = ref(false);
const supplierForm = useForm({ name: '', mobile_no: '' });

function openSupplierModal() {
    supplierForm.reset();
    supplierForm.clearErrors();
    supplierModalOpen.value = true;
}

function closeSupplierModal() {
    supplierModalOpen.value = false;
    supplierForm.reset();
    supplierForm.clearErrors();
}

function submitSupplier() {
    const pendingSupplier = { name: supplierForm.name, mobile_no: supplierForm.mobile_no };

    supplierForm.post('/suppliers', {
        onSuccess: () => {
            try {
                sessionStorage.setItem(DRAFT_KEY, JSON.stringify(form.data()));
                sessionStorage.setItem(PENDING_SUPPLIER_KEY, JSON.stringify(pendingSupplier));
            } catch {
                // Storage unavailable - the modal still worked, the draft just
                // won't survive the bounce back to /purchases.
            }
            supplierModalOpen.value = false;
            router.visit('/purchases');
        },
    });
}

function applyPendingSupplier() {
    let raw;
    try {
        raw = sessionStorage.getItem(PENDING_SUPPLIER_KEY);
    } catch {
        return;
    }
    if (!raw) return;

    try {
        sessionStorage.removeItem(PENDING_SUPPLIER_KEY);
        const pending = JSON.parse(raw);
        const matches = props.suppliers.filter(
            (s) => s.name === pending.name && (pending.mobile_no ? s.mobile_no === pending.mobile_no : true),
        );
        const match = matches.sort((a, b) => b.id - a.id)[0];
        if (match) selectSupplier(match.id);
        toast({ message: 'Supplier added.', variant: 'success' });
    } catch {
        // malformed sessionStorage payload - nothing to recover, ignore.
    }
}

// --- Inline "quick add item" (item 9) -----------------------------------
// Same sessionStorage draft-bridge technique as the "+ New supplier" modal
// above: ItemController::store() always redirects to the Items index (it
// has no JSON mode), so this form's own in-progress lines are stashed
// before the post and restored once Index.vue re-mounts this component
// back at /purchases.
const PENDING_ITEM_KEY = 'purchases-create-pending-item';

const itemModalOpen = ref(false);
const itemForm = useForm({ item_category_id: null, name: '', unit: '', purchase_rate: '', sale_rate: '' });
const itemCategoryOptions = computed(() => props.itemCategories.map((c) => ({ value: c.id, label: c.name })));

function openItemModal() {
    itemForm.reset();
    itemForm.clearErrors();
    itemModalOpen.value = true;
}

function closeItemModal() {
    itemModalOpen.value = false;
    itemForm.reset();
    itemForm.clearErrors();
}

function submitItem() {
    const pendingItem = { name: itemForm.name };

    itemForm.transform((data) => ({
        ...data,
        purchase_rate: data.purchase_rate === '' ? null : data.purchase_rate,
        sale_rate: data.sale_rate === '' ? null : data.sale_rate,
    })).post('/items', {
        onSuccess: () => {
            try {
                sessionStorage.setItem(DRAFT_KEY, JSON.stringify(form.data()));
                sessionStorage.setItem(PENDING_ITEM_KEY, JSON.stringify(pendingItem));
            } catch {
                // Storage unavailable - the modal still worked, the draft just
                // won't survive the bounce back to /purchases.
            }
            itemModalOpen.value = false;
            router.visit('/purchases');
        },
    });
}

// Mirrors applyPendingSupplier() exactly, matched case-insensitively since
// ItemController::uniqueNameRule() itself is case-insensitive (item 6).
function applyPendingItem() {
    let raw;
    try {
        raw = sessionStorage.getItem(PENDING_ITEM_KEY);
    } catch {
        return;
    }
    if (!raw) return;

    try {
        sessionStorage.removeItem(PENDING_ITEM_KEY);
        const pending = JSON.parse(raw);
        const matches = props.items.filter((i) => i.name.toLowerCase() === String(pending.name).toLowerCase());
        const match = matches.sort((a, b) => b.id - a.id)[0];
        if (match) {
            const target = form.lines.find((line) => line.item_id === null) ?? emptyLine();
            if (!form.lines.includes(target)) {
                form.lines.push(target);
            }
            selectLineItem(target, match.id);
        }
        toast({ message: 'Item added.', variant: 'success' });
    } catch {
        // malformed sessionStorage payload - nothing to recover, ignore.
    }
}

onMounted(() => {
    applyPendingSupplier();
    applyPendingItem();
});
</script>

<template>
    <div>
    <Card variant="panel">
        <PageHeader title="New purchase" description="Record a bill received from a supplier. Stock and the supplier's balance update when you create it. Fields marked * are required.">
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </PageHeader>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <p v-if="form.errors.expected_total" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.expected_total }}
        </p>

        <p v-if="previewError" class="mb-4 border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-sm text-warning-text">
            {{ previewError }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit(false)">
            <div v-if="isAdmin && correctionFiscalYear">
                <label for="purchase-fiscal-year" class="mb-1 block text-sm font-semibold text-text-base">Fiscal year</label>
                <Select
                    id="purchase-fiscal-year"
                    v-model="form.fiscal_year_id"
                    :options="fiscalYearOptions"
                    placeholder="Currently open fiscal year"
                />
                <p v-if="form.errors.fiscal_year_id" class="mt-1 text-sm text-danger">{{ form.errors.fiscal_year_id }}</p>
            </div>

            <div v-if="isCorrectionSelected" class="flex flex-col gap-3 border-[1.5px] border-warning-text bg-warning-bg px-3 py-3">
                <p class="text-sm text-warning-text">
                    {{ correctionFiscalYear.name }} is reopened for correction. This purchase will post into that
                    year instead of the currently open one.
                </p>
                <div>
                    <label for="purchase-reason" class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <textarea
                        id="purchase-reason"
                        v-model="form.reason"
                        rows="2"
                        placeholder="Explain why this correction is needed"
                        required
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none transition-colors duration-150 focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                    ></textarea>
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">{{ form.errors.reason }}</p>
                </div>
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Supplier &amp; date</h4>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier <span class="text-danger">*</span></label>
                    <div class="flex gap-2">
                        <Combobox
                            :model-value="form.supplier_id"
                            :options="supplierOptions"
                            placeholder="Select supplier"
                            class="flex-1"
                            @update:model-value="selectSupplier"
                        />
                        <Button variant="secondary" tone="purple" type="button" class="!px-2.5" aria-label="Add a new supplier" title="Add a new supplier" @click="openSupplierModal">
                            <Plus class="h-3.5 w-3.5" aria-hidden="true" />
                        </Button>
                    </div>
                    <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-danger">{{ form.errors.supplier_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Purchase date (BS) <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p class="mt-1 text-xs text-text-faint">Bikram Sambat date printed on the supplier's bill.</p>
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier bill number</label>
                    <Input v-model="form.bill_number" type="text" placeholder="Number printed on the bill" />
                    <p class="mt-1 text-xs text-text-faint">Optional. Helps you match this entry to the paper bill.</p>
                    <p v-if="form.errors.bill_number" class="mt-1 text-sm text-danger">{{ form.errors.bill_number }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier PAN number</label>
                    <Input v-model="form.pan_number" type="text" placeholder="Optional" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Chalani (dispatch) number</label>
                    <Input v-model="form.chalani_number" type="text" placeholder="Optional" />
                    <p v-if="form.errors.chalani_number" class="mt-1 text-sm text-danger">{{ form.errors.chalani_number }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Receive stock into store</label>
                    <Combobox
                        :model-value="form.store_id"
                        :options="storeOptions"
                        placeholder="Default store"
                        @update:model-value="(v) => (form.store_id = v)"
                    />
                    <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
                </div>
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Payment</h4>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Payment mode <span class="text-danger">*</span></label>
                    <Select
                        :model-value="form.payment_mode"
                        :options="paymentModeOptions"
                        @update:model-value="(v) => (form.payment_mode = v)"
                    />
                </div>
                <div v-if="showBankAccount">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank account</label>
                    <Combobox
                        :model-value="form.bank_account_id"
                        :options="bankAccountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (form.bank_account_id = v)"
                    />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>
            </div>

            <div v-if="showPartialSplit" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Paid in cash (Rs.)</label>
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Paid by bank (Rs.)</label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <p v-if="partialSplitError" class="col-span-2 text-sm text-danger">{{ partialSplitError }}</p>
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Items <span class="text-danger">*</span></h4>
            <div>
                <div class="mb-3 flex items-end gap-2">
                    <div class="flex-1">
                        <label class="mb-1 block text-sm font-semibold text-text-base">Scan barcode</label>
                        <Input
                            v-model="barcodeCode"
                            type="text"
                            placeholder="Scan or paste a barcode, then press Enter"
                            @keydown.enter.prevent="scanBarcode"
                        />
                    </div>
                    <Button variant="secondary" tone="purple" type="button" :disabled="barcodeScanning" @click="scanBarcode">
                        Add
                    </Button>
                    <Button variant="secondary" tone="purple" type="button" @click="openItemModal">
                        <Plus class="h-3.5 w-3.5" /> New item
                    </Button>
                </div>
                <p v-if="barcodeError" class="mb-2 text-sm text-danger">{{ barcodeError }}</p>

                <div class="mb-2 grid grid-cols-[1fr_90px_100px_80px_100px_90px_40px_90px_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Unit</span>
                    <span>Quantity</span>
                    <span title="Bonus quantity received at no charge">Free qty</span>
                    <span>Rate (Rs.)</span>
                    <span>Discount</span>
                    <span title="Discount type">Type</span>
                    <span class="text-right">Line total</span>
                    <span class="sr-only">Remove</span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 border-b border-border pb-2 last:border-b-0">
                    <div class="grid grid-cols-[1fr_90px_100px_80px_100px_90px_40px_90px_28px] items-start gap-2">
                        <div>
                            <Combobox
                                :model-value="line.item_id"
                                :options="itemOptions"
                                placeholder="Select item"
                                @update:model-value="(v) => selectLineItem(line, v)"
                            />
                            <p v-if="form.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                                {{ form.errors[`lines.${index}.item_id`] }}
                            </p>
                        </div>
                        <div>
                            <Select
                                v-if="itemsById.get(line.item_id)?.units?.length"
                                :model-value="line.item_unit_id"
                                :options="unitOptionsFor(itemsById.get(line.item_id))"
                                @update:model-value="(v) => selectLineUnit(line, v)"
                            />
                            <span v-else class="block pt-2 text-xs text-text-muted">{{ itemsById.get(line.item_id)?.unit ?? '-' }}</span>
                        </div>
                        <Input v-model="line.quantity" type="number" min="0" step="0.0001" placeholder="0" required />
                        <Input v-model="line.bonus_quantity" type="number" min="0" step="0.0001" placeholder="0" />
                        <Input v-model="line.rate" type="number" min="0" step="0.0001" placeholder="0.0000" required />
                        <Input
                            v-model="line.discount"
                            type="number"
                            min="0"
                            :max="line.discount_type === 'percentage' ? 100 : undefined"
                            :placeholder="line.discount_type === 'percentage' ? '%' : 'Rs'"
                        />
                        <button
                            type="button"
                            class="flex h-9 w-full items-center justify-center border-[1.5px] border-border bg-bg-subtle text-[10px] font-bold text-text-muted hover:border-primary hover:text-primary"
                            title="Click to switch between % and Rs discount"
                            @click="toggleLineDiscountType(index)"
                        >
                            {{ line.discount_type === 'percentage' ? '%' : 'Rs' }}
                        </button>
                        <span class="pt-2 text-right text-sm font-semibold text-text-strong">{{ lineTotalText(index) }}</span>
                        <button
                            v-if="form.lines.length > 1"
                            type="button"
                            class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                            :aria-label="`Remove item row ${index + 1}`"
                            :title="`Remove item row ${index + 1}`"
                            @click="removeLine(index)"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>
                    <div class="mt-1">
                        <Input v-model="line.note" type="text" placeholder="Note for this item (optional)" aria-label="Note for this item" class="text-xs" />
                        <p v-if="form.errors[`lines.${index}.note`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.note`] }}
                        </p>
                    </div>
                </div>

                <Button variant="secondary" tone="purple" type="button" class="mt-1" @click="addLine">
                    <Plus class="h-3.5 w-3.5" aria-hidden="true" /> Add another item
                </Button>
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Charges &amp; discount</h4>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Discount on whole bill</label>
                    <div class="flex gap-2">
                        <Input
                            v-model="form.discount"
                            type="number"
                            min="0"
                            :max="form.discount_type === 'percentage' ? 100 : undefined"
                            :placeholder="form.discount_type === 'percentage' ? '%' : '0.00'"
                        />
                        <button
                            type="button"
                            class="flex h-9 w-10 shrink-0 items-center justify-center border-[1.5px] border-border bg-bg-subtle text-[10px] font-bold text-text-muted hover:border-primary hover:text-primary"
                            title="Click to switch between % and Rs discount"
                            @click="toggleHeaderDiscountType"
                        >
                            {{ form.discount_type === 'percentage' ? '%' : 'Rs' }}
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-text-faint">Applied after item-level discounts. Use the button to switch between % and Rs.</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate (%)</label>
                    <Input v-model="form.vat_rate" type="number" min="0" max="100" step="0.01" :disabled="form.force_non_taxable" />
                    <p v-if="form.errors.vat_rate" class="mt-1 text-sm text-danger">{{ form.errors.vat_rate }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 sm:col-span-3">
                    <input
                        id="force_non_taxable"
                        v-model="form.force_non_taxable"
                        type="checkbox"
                        class="size-4 border-[1.5px] border-border"
                    />
                    <label for="force_non_taxable" class="text-sm font-semibold text-text-base">
                        PAN bill (no VAT)
                    </label>
                    <span class="text-xs text-text-faint">
                        Every line is treated as exempt and no VAT is charged. Pre-ticked for a supplier who is
                        not VAT registered.
                    </span>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS account (tax withheld)</label>
                    <Combobox
                        :model-value="form.tds_account_id"
                        :options="tdsAccountOptions"
                        placeholder="TDS Payable (default)"
                        @update:model-value="(v) => (form.tds_account_id = v)"
                    />
                    <p v-if="form.errors.tds_account_id" class="mt-1 text-sm text-danger">{{ form.errors.tds_account_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS rate (%)</label>
                    <Input v-model="form.tds_rate" type="number" min="0" max="100" step="0.01" placeholder="Optional" />
                    <p v-if="form.errors.tds_rate" class="mt-1 text-sm text-danger">{{ form.errors.tds_rate }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS amount (Rs.)</label>
                    <Input
                        v-model="form.tds_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                        :disabled="form.tds_rate !== ''"
                    />
                    <p v-if="form.tds_rate !== ''" class="mt-1 text-xs text-text-faint">
                        Computed from the rate: {{ money(totals?.tds_amount) }}
                    </p>
                    <p v-if="form.errors.tds_amount" class="mt-1 text-sm text-danger">{{ form.errors.tds_amount }}</p>
                </div>
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Notes</h4>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                <Input v-model="form.narration" type="text" placeholder="Optional note kept with this purchase" />
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Bill summary</h4>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <template v-if="totals && totals.header_discount !== '0.00'">
                    <span class="text-text-muted">Discount on whole bill</span>
                    <span class="text-right font-semibold text-text-strong">-{{ money(totals.header_discount) }}</span>
                </template>
                <span class="text-text-muted">Taxable amount</span>
                <span class="text-right font-semibold text-text-strong">{{ money(totals?.taxable_amount) }}</span>
                <span class="text-text-muted">Non-taxable amount</span>
                <span class="text-right font-semibold text-text-strong">{{ money(totals?.nontaxable_amount) }}</span>
                <span class="text-text-muted">VAT</span>
                <span class="text-right font-semibold text-text-strong">{{ money(totals?.vat_amount) }}</span>
                <span class="font-bold text-text-strong">Bill total</span>
                <span class="text-right font-bold text-text-strong">{{ money(totals?.total) }}</span>
                <template v-if="totals && totals.tds_amount !== '0.00'">
                    <span class="text-text-muted">TDS Withheld</span>
                    <span class="text-right font-semibold text-text-strong">-{{ money(totals.tds_amount) }}</span>
                    <span class="text-text-muted">Amount payable to supplier (after TDS)</span>
                    <span class="text-right font-semibold text-text-strong">{{ money(totals.settlement_due) }}</span>
                </template>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="secondary" tone="purple" type="button" :disabled="form.processing || !canSubmit" @click="submit(true)">
                    Create &amp; print bill
                </Button>
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="form.processing || !canSubmit">
                    Create purchase
                </Button>
            </div>
        </form>
    </Card>

    <!-- Quick "+ New supplier" -->
    <Modal :open="supplierModalOpen" title="New supplier" size="compact" @update:open="(v) => (v ? null : closeSupplierModal())">
        <form class="flex flex-col gap-4" @submit.prevent="submitSupplier">
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                <Input v-model="supplierForm.name" type="text" placeholder="e.g. ABC Traders" required />
                <p v-if="supplierForm.errors.name" class="mt-1 text-sm text-danger">{{ supplierForm.errors.name }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Mobile No</label>
                <Input v-model="supplierForm.mobile_no" type="text" placeholder="98XXXXXXXX" />
                <p v-if="supplierForm.errors.mobile_no" class="mt-1 text-sm text-danger">{{ supplierForm.errors.mobile_no }}</p>
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="closeSupplierModal">Cancel</Button>
            <Button variant="primary" tone="purple" type="button" :disabled="supplierForm.processing" @click="submitSupplier">
                Create supplier
            </Button>
        </template>
    </Modal>

    <!-- Quick "+ New item" (item 9) -->
    <Modal :open="itemModalOpen" title="New item" size="compact" @update:open="(v) => (v ? null : closeItemModal())">
        <form class="flex flex-col gap-4" @submit.prevent="submitItem">
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                <Input v-model="itemForm.name" type="text" placeholder="e.g. Coke 500ml" required />
                <p v-if="itemForm.errors.name" class="mt-1 text-sm text-danger">{{ itemForm.errors.name }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Category</label>
                <Combobox
                    :model-value="itemForm.item_category_id"
                    :options="itemCategoryOptions"
                    placeholder="Select category"
                    @update:model-value="(v) => (itemForm.item_category_id = v)"
                />
                <p v-if="itemForm.errors.item_category_id" class="mt-1 text-sm text-danger">{{ itemForm.errors.item_category_id }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Unit</label>
                <Input v-model="itemForm.unit" type="text" placeholder="e.g. pcs" required />
                <p v-if="itemForm.errors.unit" class="mt-1 text-sm text-danger">{{ itemForm.errors.unit }}</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Purchase Rate</label>
                    <Input v-model="itemForm.purchase_rate" type="number" min="0" step="0.0001" placeholder="Optional" />
                    <p v-if="itemForm.errors.purchase_rate" class="mt-1 text-sm text-danger">{{ itemForm.errors.purchase_rate }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Sale Rate</label>
                    <Input v-model="itemForm.sale_rate" type="number" min="0" step="0.0001" placeholder="Optional" />
                    <p v-if="itemForm.errors.sale_rate" class="mt-1 text-sm text-danger">{{ itemForm.errors.sale_rate }}</p>
                </div>
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="closeItemModal">Cancel</Button>
            <Button variant="primary" tone="purple" type="button" :disabled="itemForm.processing" @click="submitItem">
                Create item
            </Button>
        </template>
    </Modal>
    </div>
</template>
