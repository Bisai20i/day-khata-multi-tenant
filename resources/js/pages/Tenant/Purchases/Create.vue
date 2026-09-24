<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import PurchaseCreateCharges from '@/components/purchases/PurchaseCreateCharges.vue';
import PurchaseCreateLines from '@/components/purchases/PurchaseCreateLines.vue';
import PurchaseCreateModals from '@/components/purchases/PurchaseCreateModals.vue';
import PurchaseCreateSummary from '@/components/purchases/PurchaseCreateSummary.vue';
import { useToast } from '@/composables/useToast';
import { usePurchaseCreatePreview } from '@/composables/usePurchaseCreatePreview';
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

const { totals, previewError, partialSplitError, canSubmit, preview, toggleLineDiscountType, toggleHeaderDiscountType } =
    usePurchaseCreatePreview(form, itemsById, isCorrectionSelected);

const showBankAccount = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialSplit = computed(() => form.payment_mode === 'partial');

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
            <PurchaseCreateLines
                v-model:barcode-code="barcodeCode"
                :form="form"
                :item-options="itemOptions"
                :items-by-id="itemsById"
                :totals="totals"
                :barcode-error="barcodeError"
                :barcode-scanning="barcodeScanning"
                @scan="scanBarcode"
                @new-item="openItemModal"
                @add-line="addLine"
                @remove-line="removeLine"
                @select-item="selectLineItem"
                @select-unit="selectLineUnit"
                @toggle-discount-type="toggleLineDiscountType"
            />

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Charges &amp; discount</h4>
            <PurchaseCreateCharges
                :form="form"
                :tds-account-options="tdsAccountOptions"
                :totals="totals"
                @toggle-header-discount-type="toggleHeaderDiscountType"
            />

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Notes</h4>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                <Input v-model="form.narration" type="text" placeholder="Optional note kept with this purchase" />
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Bill summary</h4>
            <PurchaseCreateSummary :totals="totals" />

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

    <PurchaseCreateModals
        :supplier-open="supplierModalOpen"
        :supplier-form="supplierForm"
        :item-open="itemModalOpen"
        :item-form="itemForm"
        :item-category-options="itemCategoryOptions"
        @close-supplier="closeSupplierModal"
        @submit-supplier="submitSupplier"
        @close-item="closeItemModal"
        @submit-item="submitItem"
    />
    </div>
</template>
