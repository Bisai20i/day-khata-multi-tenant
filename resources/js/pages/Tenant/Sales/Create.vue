<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import {
    calculateDocument,
    formatMoney,
    formatQuantity,
    moneyEquals,
    parseMoney,
    parseQuantity,
    percentOf,
    addMoney,
    rateExcludingVat,
} from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

/**
 * Every rupee shown on this form comes from calculateDocument(), the exact
 * mirror of the server's App\Support\Billing\DocumentCalculator (CONTRACTS
 * C3/C8). Nothing here adds up a bill on its own: the audit found the preview
 * quoting 56.49 for a bill the server stored as 56.50 (P0-8), because the
 * browser summed unrounded floats and rounded halves the other way.
 *
 * The preview's total is submitted as `expected_total`; if the server arrives
 * at anything else it refuses the save rather than booking a different amount
 * than the one on screen.
 */
const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    tdsAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
    // Saved boilerplate lines a cashier can drop into Narration below
    // (audit section 4 polish, "note templates") - see SaleController
    // ::storeNoteTemplate()/destroyNoteTemplate().
    noteTemplates: { type: Array, default: () => [] },
    // The tenant's protected walk-in customer (audit section 3 "Sales") -
    // preselected below so a counter sale with nobody to name still has a
    // valid customer_id without the cashier hunting for it.
    walkInCustomerId: { type: Number, default: null },
    invoiceSettings: {
        type: Object,
        default: () => ({
            default_vat_rate: '13.00',
            default_store_id: null,
            active_invoice_type: 'full',
        }),
    },
    // Set by the parent Index page when it bounces back here after the
    // inline "+ New customer" modal redirects away and back (see
    // submitCustomer() below) - restores the in-progress draft that would
    // otherwise be lost when Index re-mounts this component.
    initialDraft: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'posted']);
const { toast } = useToast();
const { confirm } = useConfirm();
const page = usePage();

/** Cancel straight away when nothing was entered, otherwise ask before discarding. */
async function requestCancel() {
    const hasEntries = form.isDirty || form.lines.length > 0;
    if (hasEntries) {
        const discard = await confirm({
            title: 'Discard this sale?',
            message: 'The items and details you entered have not been saved and will be lost.',
            tone: 'danger',
            confirmLabel: 'Discard sale',
            cancelLabel: 'Keep editing',
        });
        if (!discard) return;
    }
    emit('cancel');
}

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const bankAccountOptions = computed(() =>
    props.bankAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })),
);
const tdsAccountOptions = computed(() =>
    props.tdsAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })),
);
// searchValue lets a barcode match the item even though it isn't shown in
// the option's label - see Combobox.vue's searchText(). A scanner types the
// barcode then Enter, reka-ui's own filter narrows to that one item and
// highlights it, so Enter selects it exactly like picking from the list.
const itemOptions = computed(() =>
    props.items.map((i) => ({
        value: i.id,
        label: `${i.name} (${i.unit})`,
        searchValue: i.barcode ? `${i.name} ${i.barcode}` : i.name,
        // Stock shown right in the option row so a cashier can see availability
        // while picking, without a separate badge appearing after selection.
        meta: i.current_stock != null ? `${formatQuantity(i.current_stock)} ${i.unit}` : null,
        metaClass: stockStatus(i) === 'out' ? 'text-danger' : stockStatus(i) === 'low' ? 'text-[#92400E]' : 'text-text-muted',
    })),
);
const itemsById = computed(() => Object.fromEntries(props.items.map((i) => [i.id, i])));
const agentOptions = computed(() => props.agents.map((a) => ({ value: a.id, label: a.name })));
const agentsById = computed(() => Object.fromEntries(props.agents.map((a) => [a.id, a])));
// Saved-note dropdown (audit section 4 polish, "note templates"): picking one
// just fills Narration below - the cashier is still free to edit it
// afterwards, this never re-fires on its own.
const noteTemplateOptions = computed(() => props.noteTemplates.map((t) => ({ value: t.id, label: t.text })));

function applyNoteTemplate(templateId) {
    const template = props.noteTemplates.find((t) => t.id === templateId);
    if (template) form.narration = template.text;
}

const noteTemplateForm = useForm({ text: '' });

/** Saves the current Narration text as a reusable template for next time. */
function saveNoteTemplate() {
    const text = form.narration.trim();
    if (!text) return;

    noteTemplateForm.text = text;
    noteTemplateForm.post('/sales/note-templates', {
        preserveScroll: true,
        preserveState: true,
    });
}

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial (cash + bank)' },
    { value: 'credit', label: 'Credit' },
];

function emptyLine() {
    // item_unit_id '' means "the item's own base unit" (matches this app's
    // existing '' = None convention for optional Select fields, e.g. Items/
    // Index.vue's item_subcategory_id) - transformed to null on submit.
    //
    // `bonus_quantity` is the free-of-charge quantity handed over with the
    // line (audit section 3 "Sales"): it moves stock but is never priced, so
    // it is submitted to the server and deliberately kept out of the preview
    // below. `mrp` is the opposite - a browser-only entry aid that fills
    // `rate` and is never submitted (see applyLineMrp() and submit()).
    return { item_id: null, item_unit_id: '', quantity: '', bonus_quantity: '', mrp: '', rate: '', discount: '', discount_type: 'flat' };
}

/**
 * A quantity box as the server wants it: the typed string untouched, or '0'
 * when the box is empty. Never a number - the string goes straight into
 * Quantity::of() server-side, which refuses anything a float could have
 * distorted (C1).
 */
function enteredQuantity(value) {
    return value === '' || value === null || value === undefined ? '0' : String(value);
}

// Options for a line's unit dropdown: the item's own base unit first
// (value '' - always present, even for an item with zero alt units), then
// every active ItemUnit row. Only rendered at all when the item has at
// least one alt unit (see the template) - an item with none shows nothing
// here, unchanged from before this feature existed.
function unitOptionsFor(item) {
    if (!item) return [];

    return [{ value: '', label: item.unit }, ...(item.units ?? []).map((u) => ({ value: u.id, label: u.name }))];
}

// Selecting an alternate unit auto-fills the rate from that unit's own
// sale_rate override; switching back to the base unit ('') restores the
// item's own sale_rate, so a mis-click no longer leaves a Box rate sitting on
// a Piece line. Still freely editable afterwards - Sale::post() only ever
// uses the entered rate, never the unit's. Shared by the staging line and
// every already-added row - see selectStagingItem()/the lines grid below.
function selectLineUnit(line, unitId) {
    line.item_unit_id = unitId;
    // An MRP is a price for ONE of whatever unit was selected, so the number
    // in the box stops meaning anything the moment the unit changes.
    line.mrp = '';

    if (unitId === '' || unitId === null) {
        const item = itemsById.value[line.item_id];
        if (item?.sale_rate != null) line.rate = String(item.sale_rate);

        return;
    }

    const unit = itemsById.value[line.item_id]?.units?.find((u) => u.id === unitId);
    if (unit?.sale_rate != null) {
        line.rate = String(unit.sale_rate);
    }
}

// Changing the item invalidates whatever unit was selected for the
// previous item (a unit id from one item's alt-units list is meaningless
// for another item), so it's reset back to the base unit, and the new
// item's own sale rate is prefilled.
function selectLineItem(line, itemId) {
    line.item_id = itemId;
    line.item_unit_id = '';
    line.mrp = '';

    const item = itemsById.value[itemId];
    line.rate = item?.sale_rate != null ? String(item.sale_rate) : '';
}

/**
 * MRP / VAT-inclusive entry (audit section 3 "Sales").
 *
 * The shopkeeper types the sticker price and the line's rate is back-
 * calculated exactly - `rate = MRP / 1.13` for a vatable line at 13% - so the
 * printed bill shows rate 100 plus 13 VAT for an MRP of 113 instead of
 * charging VAT on top of a price that already contained it.
 *
 * The division runs in the money module (rateExcludingVat, scaled BigInt,
 * one HalfUp rounding to 4dp); nothing here touches Number() or parseFloat.
 * Only the resulting RATE is ever submitted - the server re-derives nothing
 * from the MRP and does not even receive it, so a rate typed by hand and a
 * rate produced here are the same thing to the books.
 *
 * A non-vatable line (or any line on a PAN invoice, which carries no VAT at
 * all) divides by 1: the MRP is the rate.
 */
function applyLineMrp(line, mrp) {
    line.mrp = mrp;

    if (mrp === '' || mrp === null || mrp === undefined) {
        return;
    }

    const item = itemsById.value[line.item_id];
    const vatRate = !isPanInvoice.value && item?.is_vatable ? effectiveVatRate.value : '0';
    const result = rateExcludingVat(mrp, vatRate);

    // A half-typed or malformed MRP just leaves the rate alone: the cashier is
    // still typing, and the rate field stays theirs to edit either way.
    if (result.ok) {
        line.rate = result.value;
    }
}

function defaultFormData() {
    return {
        // Defaults to the walk-in customer (audit section 3 "Sales") - still
        // freely changeable, this just saves the cashier a click on the
        // common case of a counter sale nobody bothers to name.
        customer_id: props.walkInCustomerId ?? null,
        store_id: props.invoiceSettings.default_store_id ?? null,
        chalani_number: '',
        // Asia/Kathmandu, not UTC: between midnight and 05:45 local time a
        // toISOString() default dated the bill to the previous day.
        date: todayInKathmandu(),
        payment_mode: 'cash',
        bank_account_id: null,
        discount: '',
        discount_type: 'flat',
        cash_amount: '',
        bank_amount: '',
        tds_account_id: null,
        tds_amount: '',
        agent_id: null,
        commission_amount: '',
        narration: '',
        // Items only ever enter the bill through the "Add item" staging
        // panel below (addStagingLine()) - no starter blank row here.
        lines: [],
    };
}

const form = useForm(props.initialDraft ?? defaultFormData());

// Progressive disclosure for the remaining rare fields (Store, header
// discount, TDS, agent) - Narration stays directly visible per the redesign,
// it's common enough not to hide.
const showMoreOptions = ref(false);
const showLineExtras = ref(false);
const lineGridColumns = computed(() =>
    showLineExtras.value
        ? '1fr 80px 90px 80px 90px 90px 120px 96px 28px'
        : '1fr 80px 90px 90px 120px 96px 28px',
);

function removeLine(index) {
    form.lines.splice(index, 1);
}

// --- Add item: staging panel -----------------------------------------------
// One row of entry fields, separate from the lines already committed to the
// bill below (the redesign's step 2/step 3 split). selectLineItem/
// selectLineUnit/applyLineMrp above are written generically against
// whatever `line` object is passed in, so the staging line reuses them
// exactly as the committed rows do.
const stagingLine = ref(emptyLine());
const stagingItemEl = ref(null);
const stagingQuantityEl = ref(null);

function focusStagingField(el) {
    nextTick(() => el.value?.querySelector('input')?.focus());
}

function selectStagingItem(itemId) {
    selectLineItem(stagingLine.value, itemId);
    focusStagingField(stagingQuantityEl);
}

function selectStagingUnit(unitId) {
    selectLineUnit(stagingLine.value, unitId);
}

function applyStagingMrp(mrp) {
    applyLineMrp(stagingLine.value, mrp);
}

function toggleStagingDiscountType() {
    toggleLineDiscountTypeOn(stagingLine.value);
}

const stagingItem = computed(() => itemsById.value[stagingLine.value.item_id] ?? null);

// -1/0/1 on two quantity strings, exact (scaled BigInt, never Number()) -
// only used here to compare current_stock against min_stock for the badge
// below, never to compute anything billed. A canonical decimal STRING can't
// be compared with `<`/`>` directly ("6.0000" sorts after "10.0000"
// lexicographically), so this scales both sides to an integer first -
// mirrors Pos.vue's own toScaledQuantity()/compareQuantity(), which money.js
// doesn't expose yet.
function compareQuantity(a, b) {
    const left = parseQuantity(a === '' || a === null || a === undefined ? '0' : a);
    const right = parseQuantity(b === '' || b === null || b === undefined ? '0' : b);
    if (!left.ok || !right.ok) return null;

    const toScaled = (value) => {
        const negative = value.startsWith('-');
        const scaled = BigInt((negative ? value.slice(1) : value).replace('.', ''));

        return negative ? -scaled : scaled;
    };

    const leftScaled = toScaled(left.value);
    const rightScaled = toScaled(right.value);

    return leftScaled === rightScaled ? 0 : leftScaled < rightScaled ? -1 : 1;
}

/** 'out' | 'low' | null - drives the stock badge next to Qty and per-line in the bill. */
function stockStatus(item) {
    if (!item?.is_stockable || item.current_stock == null) return null;
    if (compareQuantity(item.current_stock, '0') <= 0) return 'out';
    if (item.min_stock != null && compareQuantity(item.current_stock, item.min_stock) <= 0) return 'low';

    return null;
}


/** Item + quantity is enough to commit a line; everything else can stay blank. */
const canAddStagingLine = computed(() => !!stagingLine.value.item_id && stagingLine.value.quantity !== '');

function addStagingLine() {
    if (!canAddStagingLine.value) {
        focusStagingField(stagingLine.value.item_id ? stagingQuantityEl : stagingItemEl);
        return;
    }

    form.lines.push({ ...stagingLine.value });
    stagingLine.value = emptyLine();
    focusStagingField(stagingItemEl);
}

// --- Totals: the single preview, mirroring the server step for step --------

// The invoice type is never a form field - it's fixed per tenant by the
// platform admin (TenantCompanySettingController), so this just reads the
// value the server already applies to every sale (see Sale::post()).
const isPanInvoice = computed(() => props.invoiceSettings.active_invoice_type === 'pan');

// A PAN invoice carries no VAT at all; every other type uses the tenant's
// configured rate. The rate is never editable here - the server ignores any
// rate the browser sends and always reads CompanySetting::default_vat_rate.
const effectiveVatRate = computed(() => (isPanInvoice.value ? '0.00' : String(props.invoiceSettings.default_vat_rate ?? '0')));

const preview = computed(() =>
    calculateDocument(
        form.lines.map((line) => {
            const item = itemsById.value[line.item_id];
            const unit = item?.units?.find((u) => u.id === line.item_unit_id);

            return {
                quantity: line.quantity,
                rate: line.rate,
                discount: line.discount,
                discount_type: line.discount_type,
                vatable: item?.is_vatable ?? false,
                conversion_factor: unit?.conversion_factor ?? 1,
            };
        }),
        {
            vat_rate: effectiveVatRate.value,
            discount: form.discount,
            discount_type: form.discount_type,
            tds_amount: form.tds_amount,
            force_non_taxable: isPanInvoice.value,
        },
    ),
);

const totals = computed(() => (preview.value.ok ? preview.value.totals : null));

// A half-typed line is not an error worth shouting about: the calculator says
// "the quantity is required" for every empty row, which would put a red box on
// screen before the cashier has typed anything.
const hasEnteredLines = computed(() =>
    form.lines.some((line) => line.item_id && line.quantity !== '' && line.rate !== ''),
);
const previewError = computed(() => {
    if (preview.value.ok) return null;

    // Items with no sale price are added with a blank rate, which the
    // calculator rejects for the whole bill - say so instead of silently
    // hiding the summary.
    const unpriced = form.lines.filter((line) => line.item_id && (line.rate === '' || line.rate === null));
    if (unpriced.length > 0) {
        const names = unpriced.map((line) => itemsById.value[line.item_id]?.name ?? 'an item');

        return `Enter a rate for ${names.join(', ')} to see the total.`;
    }

    return hasEnteredLines.value ? preview.value.message : null;
});

/** A line's own total, or null while the line is still incomplete. */
function lineTotal(index) {
    return totals.value ? totals.value.lines[index].line_total : null;
}

function lineDiscountAmount(index) {
    return totals.value ? totals.value.lines[index].discount_amount : null;
}

/**
 * Switching a discount between % and Rs, for any line object (staging or
 * already committed).
 *
 * Percentage to flat is exact: the calculator already knows the rupee amount
 * that percentage came to. The other direction is not - recovering a
 * percentage from an amount needs a division that the money module
 * deliberately does not offer - so the field is cleared and the cashier types
 * the percentage they mean, instead of a silently wrong number being carried
 * across.
 */
function toggleLineDiscountTypeOn(line, index = null) {
    if (line.discount_type === 'percentage') {
        const amount = index !== null ? lineDiscountAmount(index) : null;
        line.discount = amount && amount !== '0.00' ? amount : '';
        line.discount_type = 'flat';

        return;
    }

    line.discount = '';
    line.discount_type = 'percentage';
}

function toggleLineDiscountType(index) {
    toggleLineDiscountTypeOn(form.lines[index], index);
}

function toggleHeaderDiscountType() {
    if (form.discount_type === 'percentage') {
        const amount = totals.value?.header_discount;
        form.discount = amount && amount !== '0.00' ? amount : '';
        form.discount_type = 'flat';

        return;
    }

    form.discount = '';
    form.discount_type = 'percentage';
}

const showBankField = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialFields = computed(() => form.payment_mode === 'partial');

/** A user-typed amount as a canonical 2dp string, or null when it is not a valid amount. */
function enteredAmount(value) {
    const parsed = parseMoney(value === '' || value === null || value === undefined ? '0' : value);

    return parsed.ok ? parsed.value : null;
}

// Exact, no tolerance: the old `abs(sum - due) < 0.01` check let a one-paisa
// mismatch through to the server, which then left it on the customer's ledger
// forever (audit P0-4).
const partialBalanced = computed(() => {
    if (!showPartialFields.value) return true;
    if (!totals.value) return false;

    const cash = enteredAmount(form.cash_amount);
    const bank = enteredAmount(form.bank_amount);
    if (cash === null || bank === null) return false;

    return moneyEquals(addMoney(cash, bank), totals.value.settlement_due);
});

function selectAgent(agentId) {
    form.agent_id = agentId;

    if (!agentId) {
        form.commission_amount = '';
        return;
    }

    const agent = agentsById.value[agentId];
    if (agent?.commission_rate && totals.value) {
        form.commission_amount = percentOf(totals.value.total, String(agent.commission_rate));
    }
}

const canSubmit = computed(
    () =>
        !form.processing &&
        !!form.customer_id &&
        !!form.date &&
        form.lines.length > 0 &&
        !!totals.value &&
        (!showPartialFields.value || partialBalanced.value),
);

// "Save & Print" copy count (audit section 4 polish, "Save & Print N
// copies"): the backend already supports ?copies=N up to
// SaleController::MAX_PRINT_COPIES (5), this just wires a picker to it.
const PRINT_COPY_OPTIONS = [1, 2, 3, 4, 5];
const printCopies = ref(1);

function submit(print = false, copies = 1) {
    if (!totals.value) return;

    const expectedTotal = totals.value.total;

    form.transform((data) => ({
        ...data,
        discount: data.discount === '' ? '0' : data.discount,
        cash_amount: data.payment_mode === 'partial' ? (data.cash_amount === '' ? '0' : data.cash_amount) : undefined,
        bank_amount: data.payment_mode === 'partial' ? (data.bank_amount === '' ? '0' : data.bank_amount) : undefined,
        tds_amount: data.tds_amount === '' ? '0' : data.tds_amount,
        commission_amount: data.agent_id ? (data.commission_amount === '' ? '0' : data.commission_amount) : undefined,
        expected_total: expectedTotal,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            item_unit_id: line.item_unit_id || null,
            quantity: line.quantity,
            // Free units: sent as an explicit '0' when the box is empty, and
            // never as `undefined`. `mrp` is NOT sent - it only ever existed
            // to fill `rate` above (see applyLineMrp()).
            bonus_quantity: enteredQuantity(line.bonus_quantity),
            rate: line.rate,
            discount: line.discount === '' ? '0' : line.discount,
            discount_type: line.discount_type,
        })),
    })).post('/sales', {
        preserveScroll: true,
        onSuccess: () => {
            // C11: the server flashes exactly which document it just created,
            // so this opens that bill's print view instead of guessing the
            // newest id out of the list it was redirected to.
            const created = page.props.flash?.created;
            if (print && created?.print_url) {
                window.open(`${created.print_url}?copies=${copies}`, '_blank');
            }
            emit('posted');
        },
    });
}

/** Save & Print with a specific copy count, chosen from the dropdown. */
function submitAndPrint(copies) {
    printCopies.value = copies;
    submit(true, copies);
}

// --- Enter key: advance, never submit --------------------------------------
// Legacy adds the next line on Enter; here Enter submitted the whole bill from
// the Qty field (audit P1 "Workflow"). On the staging panel, Enter walks
// Qty -> Rate -> Discount and then commits the line (addStagingLine()) -
// mirrors legacy's "Enter adds to basket" and the barcode-scan flow in
// Pos.vue. On an already-added row, Enter just walks the same three fields;
// there's no line left to auto-create at the end since new items only ever
// enter through the staging panel now.
const LINE_FIELDS = ['quantity', 'rate', 'discount'];
const linesEl = ref(null);

function focusLineField(index, field) {
    const target = linesEl.value?.querySelector(`[data-line-field="${field}-${index}"] input`);
    target?.focus();
    target?.select?.();
}

function onLineEnter(index, field) {
    const position = LINE_FIELDS.indexOf(field);
    if (position < LINE_FIELDS.length - 1) {
        focusLineField(index, LINE_FIELDS[position + 1]);
    }
}

function onStagingEnter(field) {
    const position = LINE_FIELDS.indexOf(field);

    if (position < LINE_FIELDS.length - 1) {
        stagingLineEl.value?.querySelector(`[data-staging-field="${LINE_FIELDS[position + 1]}"] input`)?.focus();
        return;
    }

    addStagingLine();
}

const stagingLineEl = ref(null);

// --- Inline "+ New customer" ------------------------------------------
// Ports Pos.vue's openCustomerModal()/submitCustomer() pattern: POST
// /customers always redirects to the customers index, so this bounces back
// to /sales and best-effort auto-selects the new customer by matching
// name/mobile against the freshly reloaded customers list. Unlike Pos.vue
// (a standalone page whose in-progress cart already survives a remount via
// localStorage), this form is a child of Sales/Index.vue that unmounts
// entirely while showCreateForm is false - so the in-progress draft is
// stashed alongside the pending-customer marker and handed back in via the
// initialDraft prop once Index.vue reopens this form (see its onMounted).
const DRAFT_KEY = 'sales-create-draft';
const PENDING_CUSTOMER_KEY = 'sales-create-pending-customer';

const customerModalOpen = ref(false);
const customerForm = useForm({ name: '', mobile_no: '' });

function openCustomerModal() {
    customerForm.reset();
    customerForm.clearErrors();
    customerModalOpen.value = true;
}

function closeCustomerModal() {
    customerModalOpen.value = false;
    customerForm.reset();
    customerForm.clearErrors();
}

function submitCustomer() {
    const pendingCustomer = { name: customerForm.name, mobile_no: customerForm.mobile_no };

    customerForm.post('/customers', {
        onSuccess: () => {
            try {
                sessionStorage.setItem(DRAFT_KEY, JSON.stringify(form.data()));
                sessionStorage.setItem(PENDING_CUSTOMER_KEY, JSON.stringify(pendingCustomer));
            } catch {
                // Storage unavailable - the modal still worked, the draft just
                // won't survive the bounce back to /sales.
            }
            customerModalOpen.value = false;
            router.visit('/sales');
        },
    });
}

function applyPendingCustomer() {
    let raw;
    try {
        raw = sessionStorage.getItem(PENDING_CUSTOMER_KEY);
    } catch {
        return;
    }
    if (!raw) return;

    try {
        sessionStorage.removeItem(PENDING_CUSTOMER_KEY);
        const pending = JSON.parse(raw);
        const matches = props.customers.filter(
            (c) => c.name === pending.name && (pending.mobile_no ? c.mobile_no === pending.mobile_no : true),
        );
        const match = matches.sort((a, b) => b.id - a.id)[0];
        if (match) form.customer_id = match.id;
        toast({ message: 'Customer added.', variant: 'success' });
    } catch {
        // malformed sessionStorage payload - nothing to recover, ignore.
    }
}

onMounted(() => applyPendingCustomer());
</script>

<template>
    <div>
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h3 class="text-base font-bold text-text-strong">New sale</h3>
            <p class="text-xs text-text-muted">Fields marked * are required. Totals update as you add items.</p>
        </div>
        <Button variant="secondary" tone="purple" type="button" @click="requestCancel">Cancel</Button>
    </div>

    <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
        {{ form.errors.lines }}
    </p>
    <p v-if="form.errors.expected_total" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
        {{ form.errors.expected_total }}
    </p>

    <form class="flex flex-col gap-4 pb-4" @submit.prevent="submit(false)">
            <!-- Bill info -------------------------------------------------- -->
            <Card variant="panel" title="Customer & date" class="!p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Customer <span class="text-danger" aria-hidden="true">*</span></label>
                        <Combobox
                            :model-value="form.customer_id"
                            :options="customerOptions"
                            placeholder="Select customer"
                            aria-describedby="sale-customer-help sale-customer-error"
                            @update:model-value="(v) => (form.customer_id = v)"
                        >
                            <template #addon>
                                <button
                                    type="button"
                                    class="flex items-center justify-center text-text-muted hover:text-primary"
                                    aria-label="Add new customer"
                                    title="Add new customer"
                                    @click="openCustomerModal"
                                >
                                    <Plus class="h-3.5 w-3.5" />
                                </button>
                            </template>
                        </Combobox>
                        <p id="sale-customer-help" class="mt-1 text-xs text-text-muted">Pick Walk-in customer for a counter sale.</p>
                        <p v-if="form.errors.customer_id" id="sale-customer-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.customer_id }}</p>
                    </div>
                    <div>
                        <label for="sale-date" class="mb-1 block text-sm font-semibold text-text-base">Sale date (BS) <span class="text-danger" aria-hidden="true">*</span></label>
                        <NepaliDateInput v-model="form.date" required aria-describedby="sale-date-help sale-date-error" />
                        <p id="sale-date-help" class="mt-1 text-xs text-text-muted">Bikram Sambat (Nepali) date of the bill.</p>
                        <p v-if="form.errors.date" id="sale-date-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.date }}</p>
                    </div>
                    <div>
                        <label for="sale-chalani" class="mb-1 block text-sm font-semibold text-text-base">Chalani (delivery challan) number</label>
                        <Input id="sale-chalani" v-model="form.chalani_number" type="text" placeholder="Optional" aria-describedby="sale-chalani-help sale-chalani-error" />
                        <p id="sale-chalani-help" class="mt-1 text-xs text-text-muted">Only needed if this bill travels with a delivery challan.</p>
                        <p v-if="form.errors.chalani_number" id="sale-chalani-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.chalani_number }}</p>
                    </div>
                </div>
            </Card>

            <!-- Items: staging row + the bill's committed lines, merged into
                 one section instead of two separate cards. -->
            <Card variant="panel" class="!p-4">
                <div class="mb-3 flex items-center justify-between">
                    <div class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Items</div>
                    <button type="button" class="text-xs font-semibold text-primary" @click="showLineExtras = !showLineExtras">
                        {{ showLineExtras ? 'Hide' : 'Show' }} bonus &amp; MRP columns
                    </button>
                </div>

                <div ref="stagingLineEl" class="grid grid-cols-[2.2fr_1fr_0.8fr_1fr_1.2fr] items-end gap-3">
                    <div ref="stagingItemEl">
                        <label class="mb-1 block text-sm font-semibold text-text-base">Item or scan barcode</label>
                        <Combobox
                            :model-value="stagingLine.item_id"
                            :options="itemOptions"
                            placeholder="Search or scan barcode"
                            @update:model-value="selectStagingItem"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Unit</label>
                        <Select
                            v-if="stagingItem?.units?.length"
                            :model-value="stagingLine.item_unit_id"
                            :options="unitOptionsFor(stagingItem)"
                            @update:model-value="selectStagingUnit"
                        />
                        <span v-else class="block h-9 pt-2 text-xs text-text-muted">{{ stagingItem?.unit ?? '-' }}</span>
                    </div>
                    <div ref="stagingQuantityEl" data-staging-field="quantity">
                        <label class="mb-1 block text-sm font-semibold text-text-base">Quantity</label>
                        <Input
                            v-model="stagingLine.quantity"
                            type="number"
                            step="0.0001"
                            placeholder="0"
                            @keydown.enter.prevent="onStagingEnter('quantity')"
                        />
                    </div>
                    <div data-staging-field="rate">
                        <label class="mb-1 block text-sm font-semibold text-text-base">Rate</label>
                        <Input
                            v-model="stagingLine.rate"
                            type="number"
                            min="0"
                            step="0.0001"
                            placeholder="0.00"
                            @keydown.enter.prevent="onStagingEnter('rate')"
                        />
                    </div>
                    <div class="flex items-end gap-2">
                        <div data-staging-field="discount" class="flex-1">
                            <label class="mb-1 block text-sm font-semibold text-text-base">Discount</label>
                            <Input
                                v-model="stagingLine.discount"
                                type="number"
                                min="0"
                                step="0.01"
                                :max="stagingLine.discount_type === 'percentage' ? 100 : undefined"
                                :placeholder="stagingLine.discount_type === 'percentage' ? '%' : 'Rs'"
                                @keydown.enter.prevent="onStagingEnter('discount')"
                            >
                                <template #addon>
                                    <button
                                        type="button"
                                        class="flex h-9 w-9 shrink-0 items-center justify-center text-[10px] font-bold text-text-muted hover:text-primary"
                                        title="Click to switch between % and Rs discount"
                                        aria-label="Discount type: switch between percent and rupees"
                                        @click="toggleStagingDiscountType"
                                    >
                                        {{ stagingLine.discount_type === 'percentage' ? '%' : 'Rs' }}
                                    </button>
                                </template>
                            </Input>
                        </div>
                        <Button variant="primary" tone="purple" type="button" :disabled="!canAddStagingLine" @click="addStagingLine">
                            <Plus class="h-3.5 w-3.5" /> Add item
                        </Button>
                    </div>
                </div>

                <Transition name="extras">
                    <div v-if="showLineExtras" class="mt-3 grid grid-cols-2 gap-3 sm:[grid-template-columns:2.2fr_1fr_0.8fr_1fr_1.2fr]">
                        <div class="sm:col-start-3">
                            <label class="mb-1 block text-sm font-semibold text-text-base">Bonus</label>
                            <Input
                                v-model="stagingLine.bonus_quantity"
                                type="number"
                                min="0"
                                step="0.0001"
                                placeholder="0"
                                title="Free units given with this line - moves stock, never billed"
                            />
                        </div>
                        <div class="sm:col-start-4">
                            <label class="mb-1 block text-sm font-semibold text-text-base">MRP</label>
                            <Input
                                :model-value="stagingLine.mrp"
                                type="number"
                                min="0"
                                step="0.0001"
                                placeholder="Incl. VAT"
                                title="VAT-inclusive price: fills Rate with MRP / (1 + VAT%)"
                                @update:model-value="applyStagingMrp"
                            />
                        </div>
                    </div>
                </Transition>

                <!-- Stock now shows directly beside the item name in the search
                     dropdown (see itemOptions' `meta`), so this only needs to
                     surface the unit conversion once a non-base unit is picked. -->
                <div v-if="stagingLine.item_unit_id" class="mt-3 flex items-center gap-2">
                    <span class="text-xs text-text-muted">
                        (1 {{ stagingItem.units.find((u) => u.id === stagingLine.item_unit_id)?.name }} =
                        {{ formatQuantity(stagingItem.units.find((u) => u.id === stagingLine.item_unit_id)?.conversion_factor ?? 1) }}
                        {{ stagingItem.unit }})
                    </span>
                </div>

                <div class="my-4 border-t border-border" />

                <div class="mb-3 flex items-center gap-1.5">
                    <span class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">On this bill</span>
                    <span class="bg-primary-tint px-2 py-0.5 text-[11px] font-bold text-primary">{{ form.lines.length }}</span>
                </div>

                <p v-if="form.lines.length === 0" class="py-6 text-center text-sm text-text-faint">
                    No items yet. Search or scan an item above, enter quantity and rate, then press "Add item" (or Enter) to put it on the bill.
                </p>

                <div v-else ref="linesEl">
                    <div
                        class="mb-2 grid gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase transition-[grid-template-columns] duration-150"
                        :style="{ gridTemplateColumns: lineGridColumns }"
                    >
                        <span>Item</span>
                        <span>Unit</span>
                        <span class="text-right">Qty</span>
                        <template v-if="showLineExtras">
                            <span>Bonus (free)</span>
                            <span>MRP (incl. VAT)</span>
                        </template>
                        <span class="text-right">Rate</span>
                        <span class="text-right">Discount</span>
                        <span class="text-right">Amount</span>
                        <span class="sr-only">Remove</span>
                    </div>

                    <div
                        v-for="(line, index) in form.lines"
                        :key="index"
                        class="mb-2 grid items-start gap-2 transition-[grid-template-columns] duration-150"
                        :style="{ gridTemplateColumns: lineGridColumns }"
                    >
                        <div>
                            <Combobox
                                :model-value="line.item_id"
                                :options="itemOptions"
                                placeholder="Select item"
                                @update:model-value="(v) => selectLineItem(line, v)"
                            />
                            <!-- Stock is always quoted in the item's own base unit
                                 with that unit named, plus the conversion factor
                                 when the line is entered in an alternate unit -
                                 the cashier can see both numbers instead of a
                                 base-unit figure silently labelled as Boxes. A
                                 coloured badge (not just text) flags low/out of
                                 stock, alongside the line's own VAT status. -->
                            <p v-if="itemsById[line.item_id]?.current_stock != null" class="mt-1 flex flex-wrap items-center gap-1">
                                <span
                                    v-if="stockStatus(itemsById[line.item_id])"
                                    class="inline-flex px-1.5 py-0.5 text-[10px] font-bold"
                                    :class="stockStatus(itemsById[line.item_id]) === 'out' ? 'bg-danger-bg text-danger' : 'bg-[#FEF9C3] text-[#92400E]'"
                                >
                                    {{ stockStatus(itemsById[line.item_id]) === 'out' ? 'Out of stock' : 'Low stock' }}
                                </span>
                                <span
                                    class="inline-flex px-1.5 py-0.5 text-[10px] font-bold"
                                    :class="itemsById[line.item_id]?.is_vatable ? 'bg-[#D9EDF7] text-[#245269]' : 'bg-[#EEEEEE] text-[#555555]'"
                                >
                                    {{ itemsById[line.item_id]?.is_vatable ? `VAT ${effectiveVatRate}%` : 'Non taxable' }}
                                </span>
                                <span class="text-xs text-text-muted">
                                    Stock: {{ formatQuantity(itemsById[line.item_id].current_stock) }} {{ itemsById[line.item_id].unit }}
                                    <template v-if="line.item_unit_id">
                                        (1 {{ itemsById[line.item_id].units.find((u) => u.id === line.item_unit_id)?.name }} =
                                        {{ formatQuantity(itemsById[line.item_id].units.find((u) => u.id === line.item_unit_id)?.conversion_factor ?? 1) }}
                                        {{ itemsById[line.item_id].unit }})
                                    </template>
                                </span>
                            </p>
                            <p v-if="form.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                                {{ form.errors[`lines.${index}.item_id`] }}
                            </p>
                        </div>
                        <div>
                            <Select
                                v-if="itemsById[line.item_id]?.units?.length"
                                :model-value="line.item_unit_id"
                                :options="unitOptionsFor(itemsById[line.item_id])"
                                @update:model-value="(v) => selectLineUnit(line, v)"
                            />
                            <span v-else class="block pt-2 text-xs text-text-muted">{{ itemsById[line.item_id]?.unit ?? '-' }}</span>
                        </div>
                        <!-- No min="0": a negative quantity is a valid in-bill
                             return/adjustment line (see SaleController::store()'s
                             validation comment). -->
                        <div :data-line-field="`quantity-${index}`">
                            <Input
                                v-model="line.quantity"
                                class="text-right"
                                type="number"
                                step="0.0001"
                                placeholder="0"
                                required
                                @keydown.enter.prevent="onLineEnter(index, 'quantity')"
                            />
                            <p v-if="form.errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                                {{ form.errors[`lines.${index}.quantity`] }}
                            </p>
                        </div>
                        <!-- Free / bonus units handed over with the line: they
                             move stock but are never priced, so the preview and
                             the bill total below ignore them entirely (audit
                             section 3 "Sales"). -->
                        <div v-if="showLineExtras">
                            <Input
                                v-model="line.bonus_quantity"
                                type="number"
                                min="0"
                                step="0.0001"
                                placeholder="0"
                                title="Free units given with this line - moves stock, never billed"
                            />
                            <p v-if="form.errors[`lines.${index}.bonus_quantity`]" class="mt-1 text-xs text-danger">
                                {{ form.errors[`lines.${index}.bonus_quantity`] }}
                            </p>
                        </div>
                        <!-- MRP / VAT-inclusive entry: typing the sticker price
                             fills Rate to the right with MRP / 1.13 for a vatable
                             line (applyLineMrp()). Browser-only - the server is
                             sent the rate, never the MRP. -->
                        <div v-if="showLineExtras">
                            <!-- Explicit :model-value + @update:model-value rather
                                 than v-model: the rate has to be recalculated from
                                 the value the cashier just typed, and a plain
                                 @input listener would fire before v-model had
                                 written it back. -->
                            <Input
                                :model-value="line.mrp"
                                type="number"
                                min="0"
                                step="0.0001"
                                placeholder="Incl. VAT"
                                title="VAT-inclusive price: fills Rate with MRP / (1 + VAT%)"
                                @update:model-value="(v) => applyLineMrp(line, v)"
                            />
                        </div>
                        <div :data-line-field="`rate-${index}`">
                            <Input
                                v-model="line.rate"
                                class="text-right"
                                type="number"
                                min="0"
                                step="0.0001"
                                placeholder="0.00"
                                required
                                @keydown.enter.prevent="onLineEnter(index, 'rate')"
                            />
                            <p v-if="form.errors[`lines.${index}.rate`]" class="mt-1 text-xs text-danger">
                                {{ form.errors[`lines.${index}.rate`] }}
                            </p>
                        </div>
                        <div :data-line-field="`discount-${index}`">
                            <Input
                                v-model="line.discount"
                                class="text-right"
                                type="number"
                                min="0"
                                step="0.01"
                                :max="line.discount_type === 'percentage' ? 100 : undefined"
                                :placeholder="line.discount_type === 'percentage' ? '%' : 'Rs'"
                                @keydown.enter.prevent="onLineEnter(index, 'discount')"
                            >
                                <template #addon>
                                    <button
                                        type="button"
                                        class="flex h-9 w-9 shrink-0 items-center justify-center text-[10px] font-bold text-text-muted hover:text-primary"
                                        title="Click to switch between % and Rs discount"
                                        :aria-label="`Discount type for line ${index + 1}: switch between percent and rupees`"
                                        @click="toggleLineDiscountType(index)"
                                    >
                                        {{ line.discount_type === 'percentage' ? '%' : 'Rs' }}
                                    </button>
                                </template>
                            </Input>
                        </div>
                        <span class="block pt-2 text-right text-[13px] font-semibold text-text-strong">
                            {{ lineTotal(index) === null ? '-' : formatMoney(lineTotal(index)) }}
                        </span>
                        <button
                            type="button"
                            class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                            :aria-label="`Remove ${itemsById[line.item_id]?.name ?? 'item'} (line ${index + 1}) from bill`"
                            :title="`Remove line ${index + 1}`"
                            @click="removeLine(index)"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>

                <p v-if="previewError" class="mt-2 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                    {{ previewError }}
                </p>
            </Card>

            <!-- Payment and Notes side by side - both are quick, secondary
                 fields that don't need a full-width row each. -->
            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <!-- Payment: its own clearly-labelled section - burying this
                     inside a generic "Notes" card made it too easy to miss that
                     the payment type/details live down here now. -->
                <Card variant="panel" class="!p-4">
                    <template #title>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                            <span>Payment</span>
                            <span id="sale-payment-help" class="text-[11px] font-normal normal-case tracking-normal text-text-muted">
                                Credit = customer pays later and the amount goes on their account.
                            </span>
                        </div>
                    </template>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="sale-payment-mode" class="mb-1 block text-sm font-semibold text-text-base">Payment type <span class="text-danger" aria-hidden="true">*</span></label>
                            <Select id="sale-payment-mode" v-model="form.payment_mode" :options="paymentModeOptions" aria-describedby="sale-payment-help" />
                        </div>
                        <div v-if="showBankField">
                            <label class="mb-1 block text-sm font-semibold text-text-base">Bank account <span class="text-danger">*</span></label>
                            <Combobox
                                :model-value="form.bank_account_id"
                                :options="bankAccountOptions"
                                placeholder="Select bank account"
                                @update:model-value="(v) => (form.bank_account_id = v)"
                            />
                            <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                        </div>
                    </div>

                    <div v-if="showPartialFields" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="sale-cash-amount" class="mb-1 block text-sm font-semibold text-text-base">Cash received (Rs) <span class="text-danger" aria-hidden="true">*</span></label>
                            <Input id="sale-cash-amount" v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" required aria-describedby="sale-cash-error" />
                            <p v-if="form.errors.cash_amount" id="sale-cash-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.cash_amount }}</p>
                        </div>
                        <div>
                            <label for="sale-bank-amount" class="mb-1 block text-sm font-semibold text-text-base">Bank received (Rs) <span class="text-danger" aria-hidden="true">*</span></label>
                            <Input id="sale-bank-amount" v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" required aria-describedby="sale-bank-error" />
                            <p v-if="form.errors.bank_amount" id="sale-bank-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.bank_amount }}</p>
                        </div>
                    </div>
                </Card>

                <!-- Notes: narration only - kept as its own, visually lighter
                     section now that Payment has its own card. -->
                <Card variant="panel" title="Notes" class="!p-4">
                    <label for="sale-narration" class="mb-1 block text-sm font-semibold text-text-base">Narration (printed on the bill)</label>
                    <div class="flex gap-2">
                        <Input id="sale-narration" v-model="form.narration" type="text" placeholder="Optional" class="flex-1">
                            <template #addon>
                                <button
                                    type="button"
                                    class="flex h-9 w-9 shrink-0 items-center justify-center text-text-muted hover:text-primary disabled:cursor-not-allowed disabled:opacity-50"
                                    title="Save this narration as a reusable note"
                                    :disabled="!form.narration.trim() || noteTemplateForm.processing"
                                    @click="saveNoteTemplate"
                                >
                                    <Plus class="h-3.5 w-3.5" />
                                </button>
                            </template>
                        </Input>
                        <!-- Saved-note picker (audit section 4 polish, "note
                             templates"): fills Narration above, still freely
                             editable afterwards. -->
                        <Select
                            v-if="noteTemplateOptions.length"
                            :model-value="null"
                            :options="noteTemplateOptions"
                            placeholder="Saved notes"
                            class="w-48"
                            @update:model-value="applyNoteTemplate"
                        />
                    </div>
                </Card>
            </div>

            <!-- Toggled from the sticky bar below (left side, chevron
                 indicator) - the panel itself still renders here, directly
                 above the totals, so it never fights the floating bar for
                 space. -->
            <Transition name="extras">
                <div v-if="showMoreOptions" class="flex flex-col gap-4 border-[1.5px] border-border bg-bg-subtle p-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div v-if="storeOptions.length > 1">
                            <label class="mb-1 block text-sm font-semibold text-text-base">Store (stock is taken from here)</label>
                            <Combobox
                                :model-value="form.store_id"
                                :options="storeOptions"
                                placeholder="Default store"
                                @update:model-value="(v) => (form.store_id = v)"
                            />
                            <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">Discount on whole bill</label>
                            <p class="mb-1 text-xs text-text-muted">Applied to the subtotal. Use % or Rs with the button.</p>
                            <Input
                                v-model="form.discount"
                                type="number"
                                min="0"
                                step="0.01"
                                :max="form.discount_type === 'percentage' ? 100 : undefined"
                                :placeholder="form.discount_type === 'percentage' ? '%' : '0.00'"
                            >
                                <template #addon>
                                    <button
                                        type="button"
                                        class="flex h-9 w-9 shrink-0 items-center justify-center text-[10px] font-bold text-text-muted hover:text-primary"
                                        title="Click to switch between % and Rs discount"
                                        aria-label="Bill discount type: switch between percent and rupees"
                                        @click="toggleHeaderDiscountType"
                                    >
                                        {{ form.discount_type === 'percentage' ? '%' : 'Rs' }}
                                    </button>
                                </template>
                            </Input>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">TDS account (optional)</label>
                            <p class="mb-1 text-xs text-text-muted">TDS = tax the customer deducts at source and pays to the government.</p>
                            <Combobox
                                :model-value="form.tds_account_id"
                                :options="tdsAccountOptions"
                                placeholder="Select TDS account"
                                @update:model-value="(v) => (form.tds_account_id = v)"
                            />
                            <p v-if="form.errors.tds_account_id" class="mt-1 text-sm text-danger">{{ form.errors.tds_account_id }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">TDS amount</label>
                            <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                            <p v-if="form.errors.tds_amount" class="mt-1 text-sm text-danger">{{ form.errors.tds_amount }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">Sales agent (optional)</label>
                            <p class="mb-1 text-xs text-text-muted">Agent who brought this sale; earns the commission below.</p>
                            <Combobox
                                :model-value="form.agent_id"
                                :options="agentOptions"
                                placeholder="Select agent"
                                @update:model-value="selectAgent"
                            />
                            <p v-if="form.errors.agent_id" class="mt-1 text-sm text-danger">{{ form.errors.agent_id }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">Commission amount</label>
                            <Input v-model="form.commission_amount" type="number" min="0" step="0.01" placeholder="0.00" :disabled="!form.agent_id" />
                            <p v-if="form.errors.commission_amount" class="mt-1 text-sm text-danger">{{ form.errors.commission_amount }}</p>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- Totals + actions: pinned to the bottom of the scroll area so a
                 long bill's grand total and Save buttons never scroll out of
                 view (audit UX pass). -->
            <div class="sticky bottom-0 z-10 flex flex-col gap-3 border-[1.5px] border-border bg-white px-4 py-3 shadow-[0_-4px_16px_rgba(0,0,0,.08)]">
                <!-- Subtotal, Discount, Taxable, Non-taxable, VAT, Total: the same
                     order and the same six figures the printed bill shows, so the
                     cashier can reconcile the screen against the paper line by
                     line instead of having to trust that Taxable + VAT reaches the
                     Total on a mixed bill. -->
                <div v-if="totals" class="border-[1.5px] border-border">
                    <table class="w-full table-auto border-collapse text-sm">
                        <thead>
                            <tr class="divide-x divide-border border-b-[1.5px] border-border bg-bg-subtle">
                                <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Subtotal</th>
                                <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Discount</th>
                                <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Amount on which VAT is charged">Taxable amount</th>
                                <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Amount with no VAT">Non-taxable amount</th>
                                <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase" title="Value Added Tax">VAT</th>
                                <th class="bg-primary-tint px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-primary uppercase">Total to pay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="divide-x divide-border">
                                <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(addMoney(totals.vatable_subtotal, totals.non_vatable_subtotal)) }}</td>
                                <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.header_discount) }}</td>
                                <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.taxable_amount) }}</td>
                                <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.nontaxable_amount) }}</td>
                                <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(totals.vat_amount) }}</td>
                                <td class="bg-primary-tint px-3 py-1.5 text-base font-extrabold text-primary">{{ formatMoney(totals.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="totals && showPartialFields && !partialBalanced" class="text-xs font-semibold text-danger">
                    Cash + bank amounts must add up to exactly {{ formatMoney(totals.settlement_due) }}.
                </p>

                <div class="flex items-center justify-between gap-2">
                    <button
                        type="button"
                        class="flex items-center gap-1 text-xs font-semibold text-text-muted hover:text-primary"
                        @click="showMoreOptions = !showMoreOptions"
                    >
                        <ChevronUp v-if="showMoreOptions" class="h-3.5 w-3.5" />
                        <ChevronDown v-else class="h-3.5 w-3.5" />
                        Charges &amp; more options (store, bill discount, TDS, agent)
                    </button>

                    <div class="flex items-center gap-2">
                    <Button variant="secondary" tone="purple" type="button" @click="requestCancel">Cancel</Button>
                    <div class="flex">
                        <Button
                            variant="secondary"
                            tone="purple"
                            type="button"
                            class="!rounded-r-none"
                            :disabled="!canSubmit"
                            @click="submitAndPrint(printCopies)"
                        >
                            Save &amp; Print ({{ printCopies }} {{ printCopies === 1 ? 'copy' : 'copies' }})
                        </Button>
                        <DropdownMenu align="end">
                            <template #trigger>
                                <Button variant="secondary" tone="purple" type="button" class="!rounded-l-none !border-l-0 !px-2" :disabled="!canSubmit">
                                    <ChevronDown class="size-3.5" />
                                </Button>
                            </template>
                            <DropdownMenuItem v-for="n in PRINT_COPY_OPTIONS" :key="n" @select="submitAndPrint(n)">
                                {{ n }} {{ n === 1 ? 'copy' : 'copies' }}
                            </DropdownMenuItem>
                        </DropdownMenu>
                    </div>
                    <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="!canSubmit">
                        {{ form.processing ? 'Posting...' : 'Save & post sale' }}
                    </Button>
                    <p v-if="!canSubmit && !form.processing" class="sr-only" role="status">
                        Choose a customer and date and add at least one item to save.
                    </p>
                    </div>
                </div>
            </div>
        </form>

    <!-- Quick "+ New customer" -->
    <Modal :open="customerModalOpen" title="New customer" size="compact" @update:open="(v) => (v ? null : closeCustomerModal())">
        <form class="flex flex-col gap-4" @submit.prevent="submitCustomer">
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                <Input v-model="customerForm.name" type="text" placeholder="e.g. Ram Sharma" required />
                <p v-if="customerForm.errors.name" class="mt-1 text-sm text-danger">{{ customerForm.errors.name }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Mobile No</label>
                <Input v-model="customerForm.mobile_no" type="text" placeholder="98XXXXXXXX" />
                <p v-if="customerForm.errors.mobile_no" class="mt-1 text-sm text-danger">{{ customerForm.errors.mobile_no }}</p>
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="closeCustomerModal">Cancel</Button>
            <Button variant="primary" tone="purple" type="button" :disabled="customerForm.processing" @click="submitCustomer">
                Create customer
            </Button>
        </template>
    </Modal>
    </div>
</template>

<style scoped>
/* Bonus/MRP staging row and the More-options panel both pop in/out via
   v-if; without this they'd snap instantly and read as a layout jump
   rather than an intentional toggle. */
.extras-enter-active,
.extras-leave-active {
    transition:
        opacity 150ms ease,
        transform 150ms ease;
}
.extras-enter-from,
.extras-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>
