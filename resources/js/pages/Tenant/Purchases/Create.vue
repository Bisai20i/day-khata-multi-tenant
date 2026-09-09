<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
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
const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} — ${account.name}` : account.name,
    })),
);

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
    return { item_id: null, item_unit_id: '', quantity: '', rate: '', discount: '', discount_type: 'flat' };
}

// Mirrors Sales/Create.vue's unitOptionsFor() exactly, adapted for this
// file's itemsById being a Map rather than a plain object.
function unitOptionsFor(item) {
    if (!item) return [];

    return [{ value: '', label: item.unit }, ...(item.units ?? []).map((u) => ({ value: u.id, label: u.name }))];
}

// Mirrors Sales/Create.vue's selectLineUnit() exactly, except this form
// auto-fills from a unit's purchase_rate override (not sale_rate).
function selectLineUnit(line, unitId) {
    line.item_unit_id = unitId;

    const unit = itemsById.value.get(line.item_id)?.units?.find((u) => u.id === unitId);
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
        store_id: null,
        bill_number: '',
        pan_number: '',
        chalani_number: '',
        date: '',
        payment_mode: 'credit',
        bank_account_id: null,
        discount: '',
        discount_type: 'flat',
        vat_rate: '13',
        cash_amount: '',
        bank_amount: '',
        tds_account_id: null,
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

// Mirrors Sales/Create.vue's / Pos.vue's %/Rs discount toggle exactly - see
// Sales/Create.vue's lineDiscountAmount() for the full rationale. The raw
// value + its type are what's submitted; Purchase::post() computes the
// actual Rs amount itself now.
function lineDiscountAmount(line) {
    const qty = Number(line.quantity) || 0;
    const rate = Number(line.rate) || 0;
    const raw = Number(line.discount) || 0;

    if (line.discount_type === 'percentage') {
        return Math.round(qty * rate * (Math.min(100, Math.max(0, raw)) / 100) * 100) / 100;
    }

    return Math.max(0, raw);
}

function toggleLineDiscountType(index) {
    const line = form.lines[index];
    const qty = Number(line.quantity) || 0;
    const rate = Number(line.rate) || 0;
    const base = qty * rate;
    const currentAmount = lineDiscountAmount(line);

    if (line.discount_type === 'percentage') {
        line.discount = currentAmount ? String(currentAmount) : '';
        line.discount_type = 'flat';
    } else {
        const pct = base > 0 ? Math.round((currentAmount / base) * 10000) / 100 : 0;
        line.discount = pct ? String(pct) : '';
        line.discount_type = 'percentage';
    }
}

function toggleHeaderDiscountType() {
    const currentAmount = headerDiscountAmount.value;

    if (form.discount_type === 'percentage') {
        form.discount = currentAmount ? String(currentAmount) : '';
        form.discount_type = 'flat';
    } else {
        const pct = vatableSubtotal.value > 0 ? Math.round((currentAmount / vatableSubtotal.value) * 10000) / 100 : 0;
        form.discount = pct ? String(pct) : '';
        form.discount_type = 'percentage';
    }
}

function lineTotal(line) {
    const qty = Number(line.quantity) || 0;
    const rate = Number(line.rate) || 0;
    return qty * rate - lineDiscountAmount(line);
}

function isVatable(line) {
    return itemsById.value.get(line.item_id)?.is_vatable ?? false;
}

const vatableSubtotal = computed(() =>
    form.lines.filter(isVatable).reduce((sum, line) => sum + lineTotal(line), 0),
);
const nonVatableSubtotal = computed(() =>
    form.lines.filter((line) => !isVatable(line)).reduce((sum, line) => sum + lineTotal(line), 0),
);
const headerDiscountAmount = computed(() => {
    const raw = Number(form.discount) || 0;

    if (form.discount_type === 'percentage') {
        return Math.round(vatableSubtotal.value * (Math.min(100, Math.max(0, raw)) / 100) * 100) / 100;
    }

    return Math.max(0, raw);
});
const taxableAmount = computed(() => vatableSubtotal.value - headerDiscountAmount.value);
const vatAmount = computed(() => (taxableAmount.value * (Number(form.vat_rate) || 0)) / 100);
const grandTotal = computed(() => taxableAmount.value + nonVatableSubtotal.value + vatAmount.value);
const tdsAmountNumber = computed(() => Number(form.tds_amount) || 0);
const settlementDue = computed(() => grandTotal.value - tdsAmountNumber.value);

const showBankAccount = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialSplit = computed(() => form.payment_mode === 'partial');
const canSubmit = computed(() => !isCorrectionSelected.value || form.reason.trim().length > 0);

function submit(print = false) {
    form.transform((data) => ({
        ...data,
        discount: Number(data.discount) || 0,
        vat_rate: Number(data.vat_rate) || 0,
        cash_amount: data.payment_mode === 'partial' ? Number(data.cash_amount) || 0 : undefined,
        bank_amount: data.payment_mode === 'partial' ? Number(data.bank_amount) || 0 : undefined,
        tds_amount: Number(data.tds_amount) || 0,
        fiscal_year_id: data.fiscal_year_id || undefined,
        reason: isCorrectionSelected.value ? data.reason : undefined,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            item_unit_id: line.item_unit_id || null,
            quantity: Number(line.quantity) || 0,
            rate: Number(line.rate) || 0,
            discount: Number(line.discount) || 0,
            discount_type: line.discount_type,
        })),
    })).post('/purchases', {
        preserveScroll: true,
        onSuccess: (page) => {
            if (print) {
                const newestPurchaseId = (page.props.purchases ?? []).reduce((maxId, p) => Math.max(maxId, p.id), 0);
                if (newestPurchaseId) window.open(`/purchases/${newestPurchaseId}/print`, '_blank');
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
        if (match) form.supplier_id = match.id;
        toast({ message: 'Supplier added.', variant: 'success' });
    } catch {
        // malformed sessionStorage payload - nothing to recover, ignore.
    }
}

onMounted(() => applyPendingSupplier());
</script>

<template>
    <div>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New purchase</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
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

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier <span class="text-danger">*</span></label>
                    <div class="flex gap-2">
                        <Combobox
                            :model-value="form.supplier_id"
                            :options="supplierOptions"
                            placeholder="Select supplier"
                            class="flex-1"
                            @update:model-value="(v) => (form.supplier_id = v)"
                        />
                        <Button variant="secondary" tone="purple" type="button" class="!px-2.5" @click="openSupplierModal">
                            <Plus class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                    <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-danger">{{ form.errors.supplier_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bill Number</label>
                    <Input v-model="form.bill_number" type="text" placeholder="Supplier's bill no." />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">PAN Number</label>
                    <Input v-model="form.pan_number" type="text" placeholder="Optional" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Chalani Number</label>
                    <Input v-model="form.chalani_number" type="text" placeholder="Optional" />
                    <p v-if="form.errors.chalani_number" class="mt-1 text-sm text-danger">{{ form.errors.chalani_number }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                    <Combobox
                        :model-value="form.store_id"
                        :options="storeOptions"
                        placeholder="Default store"
                        @update:model-value="(v) => (form.store_id = v)"
                    />
                    <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Payment Mode <span class="text-danger">*</span></label>
                    <Select
                        :model-value="form.payment_mode"
                        :options="paymentModeOptions"
                        @update:model-value="(v) => (form.payment_mode = v)"
                    />
                </div>
                <div v-if="showBankAccount">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank Account</label>
                    <Combobox
                        :model-value="form.bank_account_id"
                        :options="accountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (form.bank_account_id = v)"
                    />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>
            </div>

            <div v-if="showPartialSplit" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Cash Amount</label>
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank Amount</label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
            </div>

            <div>
                <div class="mb-2 grid grid-cols-[1fr_90px_100px_100px_90px_40px_90px_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Unit</span>
                    <span>Qty</span>
                    <span>Rate</span>
                    <span>Discount</span>
                    <span></span>
                    <span>Total</span>
                    <span></span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_90px_100px_100px_90px_40px_90px_28px] items-start gap-2">
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
                        <span v-else class="block pt-2 text-xs text-text-muted">{{ itemsById.get(line.item_id)?.unit ?? '—' }}</span>
                    </div>
                    <Input v-model="line.quantity" type="number" min="0" step="0.0001" placeholder="0" />
                    <Input v-model="line.rate" type="number" min="0" step="0.01" placeholder="0.00" />
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
                    <span class="pt-2 text-right text-sm font-semibold text-text-strong">{{ lineTotal(line).toFixed(2) }}</span>
                    <button
                        v-if="form.lines.length > 1"
                        type="button"
                        class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                        aria-label="Remove line"
                        @click="removeLine(index)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <Button variant="secondary" tone="purple" type="button" class="mt-1" @click="addLine">
                    <Plus class="h-3.5 w-3.5" /> Add line
                </Button>
            </div>

            <div class="grid grid-cols-3 gap-4 border-t-[1.5px] border-border pt-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Header Discount</label>
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
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT Rate (%)</label>
                    <Input v-model="form.vat_rate" type="number" min="0" step="0.01" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                    <Input v-model="form.narration" type="text" placeholder="Optional" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS Account</label>
                    <Combobox
                        :model-value="form.tds_account_id"
                        :options="accountOptions"
                        placeholder="Optional"
                        @update:model-value="(v) => (form.tds_account_id = v)"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS Amount</label>
                    <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t-[1.5px] border-border pt-3 text-sm">
                <span class="text-text-muted">Taxable Amount</span>
                <span class="text-right font-semibold text-text-strong">{{ taxableAmount.toFixed(2) }}</span>
                <span class="text-text-muted">Non-Taxable Amount</span>
                <span class="text-right font-semibold text-text-strong">{{ nonVatableSubtotal.toFixed(2) }}</span>
                <span class="text-text-muted">VAT</span>
                <span class="text-right font-semibold text-text-strong">{{ vatAmount.toFixed(2) }}</span>
                <span class="font-bold text-text-strong">Grand Total</span>
                <span class="text-right font-bold text-text-strong">{{ grandTotal.toFixed(2) }}</span>
                <template v-if="tdsAmountNumber > 0">
                    <span class="text-text-muted">Amount Due (after TDS)</span>
                    <span class="text-right font-semibold text-text-strong">{{ settlementDue.toFixed(2) }}</span>
                </template>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="secondary" tone="purple" type="button" :disabled="form.processing || !canSubmit" @click="submit(true)">
                    Save &amp; Print
                </Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !canSubmit">
                    Create Purchase
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
    </div>
</template>
