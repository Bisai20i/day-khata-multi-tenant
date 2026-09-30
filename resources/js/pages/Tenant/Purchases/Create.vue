<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import PurchaseCreateSupplierCard from '@/components/purchases/PurchaseCreateSupplierCard.vue';
import PurchaseCreateStagingRow from '@/components/purchases/PurchaseCreateStagingRow.vue';
import PurchaseCreateLinesTable from '@/components/purchases/PurchaseCreateLinesTable.vue';
import PurchaseCreatePaymentNotes from '@/components/purchases/PurchaseCreatePaymentNotes.vue';
import PurchaseCreateMoreOptions from '@/components/purchases/PurchaseCreateMoreOptions.vue';
import PurchaseCreateTotalsBar from '@/components/purchases/PurchaseCreateTotalsBar.vue';
import PurchaseCreateModals from '@/components/purchases/PurchaseCreateModals.vue';
import { useConfirm } from '@/composables/useConfirm';
import { usePermissions } from '@/composables/usePermissions';
import { usePurchaseCreatePreview } from '@/composables/usePurchaseCreatePreview';
import { usePurchaseCreateQuickAdd } from '@/composables/usePurchaseCreateQuickAdd';
import { emptyPurchaseLine, restorePurchaseDraft } from '@/lib/purchaseCreate';
import { todayInKathmandu } from '@/lib/format';

/**
 * Laid out exactly like Sales/Create.vue: supplier card, one "Add item" row
 * feeding the bill's lines, payment + notes, rare fields behind "More
 * options", and a sticky totals bar. Every rupee shown comes from
 * calculateDocument() via usePurchaseCreatePreview(), the mirror of the
 * server's DocumentCalculator, and the preview's total is submitted as
 * `expected_total` so the server refuses a save that would book anything
 * other than what is on screen (CONTRACTS C8).
 */
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
    // inline "+ New supplier"/"+ New item" modal redirects away and back
    // (see usePurchaseCreateQuickAdd()) - restores the in-progress draft
    // that would otherwise be lost when Index re-mounts this component.
    initialDraft: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'posted']);
const { confirm } = useConfirm();
const { can } = usePermissions();
// Posting into a reopened year needs fiscal_year.edit (the server re-checks
// it in Purchase::post()); quick-add needs the master's own create key.
const canPostCorrection = computed(() => can('fiscal_year.edit'));
const canAddSupplier = computed(() => can('suppliers.create'));
const canAddItem = computed(() => can('items.create'));

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
        // Items only ever enter the bill through the "Add item" staging row
        // (PurchaseCreateStagingRow) - no starter blank row here.
        lines: [],
    };
}

const form = useForm(props.initialDraft ? restorePurchaseDraft(props.initialDraft) : defaultFormData());

const isCorrectionSelected = computed(
    () => !!props.correctionFiscalYear && form.fiscal_year_id === props.correctionFiscalYear.id,
);
const fiscalYearOptions = computed(() =>
    props.correctionFiscalYear
        ? [{ value: props.correctionFiscalYear.id, label: `${props.correctionFiscalYear.name} (reopened for correction)` }]
        : [],
);

// searchValue lets a barcode match the item even though it isn't shown in
// the option's label - see Combobox.vue's searchText().
const itemOptions = computed(() =>
    props.items.map((i) => ({
        value: i.id,
        label: `${i.name} (${i.unit})`,
        searchValue: i.barcode ? `${i.name} ${i.barcode}` : i.name,
    })),
);
const itemsById = computed(() => new Map(props.items.map((i) => [i.id, i])));
const suppliersById = computed(() => new Map(props.suppliers.map((s) => [s.id, s])));

// Selecting an alternate unit auto-fills the rate from that unit's own
// purchase_rate override; switching BACK to the base unit restores the
// item's own purchase rate. Still freely editable afterwards.
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

// A unit id from one item's alt-units list is meaningless for another item,
// so changing the item resets the unit to the base one and prefills that
// item's own purchase rate - mirrors useSaleCreateItems().selectLineItem().
function selectLineItem(line, itemId) {
    line.item_id = itemId;
    line.item_unit_id = '';

    const item = itemsById.value.get(itemId);
    line.rate = item?.purchase_rate != null ? String(item.purchase_rate) : '';
}

// Picking a supplier re-defaults the PAN / non-VAT toggle from that
// supplier's registration: a supplier who is not VAT registered can only
// issue a PAN bill, so claiming VAT credit on their bill would be a false
// claim. The toggle stays editable afterwards - a registered supplier can
// still hand over a PAN bill for an exempt purchase.
function selectSupplier(supplierId) {
    form.supplier_id = supplierId;
    form.force_non_taxable = supplierId == null ? false : suppliersById.value.get(supplierId)?.is_vat_registered === false;
}

const stagingRow = ref(null);

const { totals, previewError, partialSplitError, canSubmit, preview, toggleLineDiscountType, toggleHeaderDiscountType } =
    usePurchaseCreatePreview(form, itemsById, isCorrectionSelected);

const {
    supplierModalOpen,
    supplierForm,
    openSupplierModal,
    closeSupplierModal,
    submitSupplier,
    itemModalOpen,
    itemForm,
    itemCategoryOptions,
    openItemModal,
    closeItemModal,
    submitItem,
} = usePurchaseCreateQuickAdd(form, props, {
    selectSupplier,
    stageItem: (itemId) => stagingRow.value?.stage(itemId),
});

const showBankField = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialFields = computed(() => form.payment_mode === 'partial');

// Progressive disclosure for the rare fields - same as the sale form.
const showMoreOptions = ref(false);
const showLineExtras = ref(false);

// A server error on a field that lives behind "More options" would otherwise
// be invisible, so the panel opens itself to show it.
const MORE_OPTIONS_FIELDS = ['store_id', 'pan_number', 'chalani_number', 'discount', 'vat_rate', 'tds_account_id', 'tds_rate', 'tds_amount'];

watch(
    () => form.errors,
    (errors) => {
        if (MORE_OPTIONS_FIELDS.some((field) => errors[field])) showMoreOptions.value = true;
    },
);

/** Cancel straight away when nothing was entered, otherwise ask before discarding. */
async function requestCancel() {
    const hasEntries = form.isDirty || form.lines.length > 0;
    if (hasEntries) {
        const discard = await confirm({
            title: 'Discard this purchase?',
            message: 'The items and details you entered have not been saved and will be lost.',
            tone: 'danger',
            confirmLabel: 'Discard purchase',
            cancelLabel: 'Keep editing',
        });
        if (!discard) return;
    }
    emit('cancel');
}

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
</script>

<template>
    <div>
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h3 class="text-base font-bold text-text-strong">New purchase</h3>
            <p class="text-xs text-text-muted">Record a bill received from a supplier. Fields marked * are required. Totals update as you add items.</p>
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
        <Card v-if="canPostCorrection && correctionFiscalYear" variant="panel" title="Fiscal year" class="!p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="purchase-fiscal-year" class="mb-1 block text-sm font-semibold text-text-base">Post into</label>
                    <Select
                        id="purchase-fiscal-year"
                        v-model="form.fiscal_year_id"
                        :options="fiscalYearOptions"
                        placeholder="Currently open fiscal year"
                    />
                    <p v-if="form.errors.fiscal_year_id" class="mt-1 text-sm text-danger">{{ form.errors.fiscal_year_id }}</p>
                </div>
                <div v-if="isCorrectionSelected" class="sm:col-span-2">
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
            <p v-if="isCorrectionSelected" class="mt-3 border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-sm text-warning-text">
                {{ correctionFiscalYear.name }} is reopened for correction. This purchase will post into that
                year instead of the currently open one.
            </p>
        </Card>

        <PurchaseCreateSupplierCard
            :form="form"
            :suppliers="suppliers"
            :can-add-supplier="canAddSupplier"
            @select-supplier="selectSupplier"
            @add-supplier="openSupplierModal"
        />

        <!-- Items: staging row + the bill's committed lines, one section -
             same as the sale form. -->
        <Card variant="panel" class="!p-4">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Items <span class="text-danger">*</span></div>
                <div class="flex items-center gap-4">
                    <button v-if="canAddItem" type="button" class="flex items-center gap-1 text-xs font-semibold text-primary" @click="openItemModal">
                        <Plus class="h-3.5 w-3.5" /> New item
                    </button>
                    <button type="button" class="text-xs font-semibold text-primary" @click="showLineExtras = !showLineExtras">
                        {{ showLineExtras ? 'Hide' : 'Show' }} free qty &amp; note columns
                    </button>
                </div>
            </div>

            <PurchaseCreateStagingRow
                ref="stagingRow"
                :item-options="itemOptions"
                :items-by-id="itemsById"
                :show-line-extras="showLineExtras"
                :select-item="selectLineItem"
                :select-unit="selectLineUnit"
                @add="(line) => form.lines.push(line)"
            />

            <PurchaseCreateLinesTable
                :lines="form.lines"
                :errors="form.errors"
                :item-options="itemOptions"
                :items-by-id="itemsById"
                :show-line-extras="showLineExtras"
                :vat-rate="String(form.vat_rate)"
                :force-non-taxable="form.force_non_taxable"
                :totals="totals"
                :select-item="selectLineItem"
                :select-unit="selectLineUnit"
                @remove="(index) => form.lines.splice(index, 1)"
                @toggle-discount-type="toggleLineDiscountType"
            />

            <p v-if="previewError" class="mt-2 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                {{ previewError }}
            </p>
        </Card>

        <PurchaseCreatePaymentNotes
            :form="form"
            :bank-accounts="bankAccounts"
            :show-bank-field="showBankField"
            :show-partial-fields="showPartialFields"
        />

        <!-- Toggled from the sticky bar below; rendered here, directly above
             the totals, so it never fights the floating bar for space. -->
        <Transition name="extras">
            <PurchaseCreateMoreOptions
                v-if="showMoreOptions"
                :form="form"
                :stores="stores"
                :tds-accounts="tdsAccounts"
                :totals="totals"
                @toggle-discount-type="toggleHeaderDiscountType"
            />
        </Transition>

        <PurchaseCreateTotalsBar
            v-model:show-more-options="showMoreOptions"
            :totals="totals"
            :partial-split-error="partialSplitError"
            :can-submit="canSubmit"
            :processing="form.processing"
            @cancel="requestCancel"
            @print="submit(true)"
        />
    </form>

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

<style scoped>
/* The More-options panel pops in/out via v-if; without this it would snap
   instantly and read as a layout jump rather than an intentional toggle. */
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
