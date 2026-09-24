<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { addMoney, calculateDocument, formatMoney, moneyEquals, parseMoney } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    defaultVatRate: { type: String, default: '13.00' },
    depreciationCategories: { type: Array, default: () => [] },
    depreciationMethods: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

const typeOptions = [
    { value: 'capital', label: 'Capital' },
    { value: 'service', label: 'Service' },
];

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial (Cash + Bank)' },
    { value: 'credit', label: 'Credit' },
];

const depreciationCategoryOptions = computed(() => props.depreciationCategories.map((c) => ({ value: c, label: c })));
const depreciationMethodOptions = computed(() => props.depreciationMethods.map((m) => ({ value: m, label: m.toUpperCase() })));

function emptyLine() {
    return {
        account_id: null,
        amount: '',
        narration: '',
        vatable: true,
        // Asset register (item 5): opt-in per line, capital purchases only.
        create_asset: false,
        asset_name: '',
        depreciation_category: null,
        depreciation_method: null,
        depreciation_rate: '',
        salvage_value: '',
    };
}

const form = useForm({
    supplier_id: null,
    supplier_pan: '',
    store_id: null,
    type: 'capital',
    bill_number: '',
    date: todayInKathmandu(),
    narration: '',
    payment_mode: 'cash',
    bank_account_id: null,
    cash_amount: '',
    bank_amount: '',
    vat_rate: props.defaultVatRate,
    lines: [emptyLine()],
});

// The PAN claimed against is snapshotted on the purchase, so it is prefilled
// from the supplier and stays editable for the case where the bill carries a
// different one.
watch(
    () => form.supplier_id,
    (supplierId) => {
        const supplier = props.suppliers.find((s) => s.id === supplierId);
        form.supplier_pan = supplier?.tpin ?? '';
    },
);

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

/** Blank means "nothing entered yet", which the calculator reads as zero. */
function orZero(value) {
    return value === '' || value === null || value === undefined ? '0' : String(value);
}

// A capital purchase has no items and no units, so every line is quantity 1 at
// a rate of the line amount - the same shape the server hands
// DocumentCalculator. Input VAT is computed from the vatable lines and the
// rate; it used to be a number the user typed with nothing tying it to the
// lines (audit P0-20).
const preview = computed(() =>
    calculateDocument(
        form.lines.map((line) => ({
            quantity: '1',
            rate: orZero(line.amount),
            discount: '0',
            discount_type: 'flat',
            vatable: line.vatable === true,
            conversion_factor: '1',
        })),
        { vat_rate: orZero(form.vat_rate), discount: '0', discount_type: 'flat' },
    ),
);

const totals = computed(() => (preview.value.ok ? preview.value.totals : null));

const hasLineInput = computed(() => form.lines.some((line) => line.amount !== ''));

const previewError = computed(() => (!preview.value.ok && hasLineInput.value ? preview.value.message : null));

const showBankAccount = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialSplit = computed(() => form.payment_mode === 'partial');
const supplierRequired = computed(() => form.payment_mode === 'credit' || form.payment_mode === 'partial');

// Exact to the paisa, no tolerance: the server refuses anything else, and the
// old 0.01 tolerance left the difference sitting on the supplier's ledger
// forever (audit P0-4).
const splitIsExact = computed(() => {
    if (!showPartialSplit.value || !totals.value) return true;

    const cash = parseMoney(orZero(form.cash_amount));
    const bank = parseMoney(orZero(form.bank_amount));
    if (!cash.ok || !bank.ok) return false;

    return moneyEquals(addMoney(cash.value, bank.value), totals.value.total);
});

const canSubmit = computed(
    () =>
        preview.value.ok &&
        !!form.date &&
        splitIsExact.value &&
        (!supplierRequired.value || !!form.supplier_id) &&
        (!showBankAccount.value || !!form.bank_account_id) &&
        !form.processing,
);

function submit() {
    form.transform((data) => ({
        ...data,
        vat_rate: orZero(data.vat_rate),
        bill_number: data.bill_number || undefined,
        supplier_pan: data.supplier_pan || undefined,
        expected_total: totals.value?.total,
        cash_amount: data.payment_mode === 'partial' ? orZero(data.cash_amount) : undefined,
        bank_amount: data.payment_mode === 'partial' ? orZero(data.bank_amount) : undefined,
        lines: data.lines.map((line) => ({
            account_id: line.account_id,
            amount: orZero(line.amount),
            vatable: line.vatable === true,
            narration: line.narration || undefined,
            // Only meaningful on a "capital" purchase; the server also
            // re-checks type === 'capital' before honouring it.
            create_asset: data.type === 'capital' && line.create_asset === true,
            asset_name: line.create_asset ? line.asset_name || undefined : undefined,
            depreciation_category: line.create_asset ? line.depreciation_category || undefined : undefined,
            depreciation_method: line.create_asset ? line.depreciation_method || undefined : undefined,
            depreciation_rate: line.create_asset ? orZero(line.depreciation_rate) : undefined,
            salvage_value: line.create_asset ? orZero(line.salvage_value) : undefined,
        })),
    })).post('/capital-purchases', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <PageHeader title="New capital purchase" description="Record a long-term asset or service bill, such as equipment or furniture. Fields marked * are required.">
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </PageHeader>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>
        <p v-if="form.errors.expected_total" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.expected_total }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Supplier &amp; date</h4>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Purchase date (BS) <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p class="mt-1 text-xs text-text-faint">Bikram Sambat date on the supplier's bill.</p>
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Purchase type <span class="text-danger">*</span></label>
                    <Select :model-value="form.type" :options="typeOptions" @update:model-value="(v) => (form.type = v)" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">
                        Supplier <span v-if="supplierRequired" class="text-danger">*</span>
                    </label>
                    <Combobox
                        :model-value="form.supplier_id"
                        :options="supplierOptions"
                        placeholder="Optional unless credit/partial"
                        @update:model-value="(v) => (form.supplier_id = v)"
                    />
                    <p class="mt-1 text-xs text-text-faint">Required when you pay on credit or part-pay.</p>
                    <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-danger">{{ form.errors.supplier_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier bill number</label>
                    <Input v-model="form.bill_number" type="text" placeholder="As printed on the bill" />
                    <p v-if="form.errors.bill_number" class="mt-1 text-sm text-danger">{{ form.errors.bill_number }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier PAN number</label>
                    <Input v-model="form.supplier_pan" type="text" placeholder="From the supplier record" />
                    <p v-if="form.errors.supplier_pan" class="mt-1 text-sm text-danger">{{ form.errors.supplier_pan }}</p>
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
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank account <span class="text-danger">*</span></label>
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
                    <label class="mb-1 block text-sm font-semibold text-text-base">Paid in cash (Rs.)</label>
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Paid by bank (Rs.)</label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" />
                </div>
                <p v-if="!splitIsExact" class="col-span-2 text-sm text-danger">
                    Cash and bank must add up to the grand total exactly.
                </p>
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Items <span class="text-danger">*</span></h4>
            <div>
                <div class="mb-2 grid grid-cols-[1fr_130px_70px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Account</span>
                    <span>Amount (Rs.)</span>
                    <span>VAT</span>
                    <span>Narration</span>
                    <span class="sr-only">Remove</span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 border-b-[1.5px] border-border/40 pb-2">
                    <div class="grid grid-cols-[1fr_130px_70px_1fr_28px] items-start gap-2">
                        <div>
                            <Combobox
                                :model-value="line.account_id"
                                :options="accountOptions"
                                placeholder="Select account"
                                @update:model-value="(v) => (line.account_id = v)"
                            />
                            <p v-if="form.errors[`lines.${index}.account_id`]" class="mt-1 text-xs text-danger">
                                {{ form.errors[`lines.${index}.account_id`] }}
                            </p>
                        </div>
                        <Input v-model="line.amount" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required />
                        <label class="flex h-[34px] items-center gap-1.5 text-xs text-text-muted">
                            <input v-model="line.vatable" type="checkbox" class="size-4 border-[1.5px] border-border" />
                            Taxable
                        </label>
                        <Input v-model="line.narration" type="text" placeholder="Optional" />
                        <button
                            v-if="form.lines.length > 1"
                            type="button"
                            class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                            :aria-label="`Remove row ${index + 1}`"
                            :title="`Remove row ${index + 1}`"
                            @click="removeLine(index)"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <!-- Asset register (item 5): a "capital" line may optionally create its
                         own tracked, depreciating FixedAsset using this line's cost and date. -->
                    <div v-if="form.type === 'capital'" class="mt-1.5 pl-1">
                        <label class="flex items-center gap-1.5 text-xs text-text-muted">
                            <input v-model="line.create_asset" type="checkbox" class="size-4 border-[1.5px] border-border" />
                            Register as a fixed asset
                        </label>

                        <div v-if="line.create_asset" class="mt-2 grid grid-cols-4 gap-2">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-text-base">Asset Name <span class="text-danger">*</span></label>
                                <Input v-model="line.asset_name" type="text" placeholder="e.g. Delivery van" required />
                                <p v-if="form.errors[`lines.${index}.asset_name`]" class="mt-1 text-xs text-danger">
                                    {{ form.errors[`lines.${index}.asset_name`] }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-text-base">Category <span class="text-danger">*</span></label>
                                <Select
                                    :model-value="line.depreciation_category"
                                    :options="depreciationCategoryOptions"
                                    placeholder="Pool"
                                    @update:model-value="(v) => (line.depreciation_category = v)"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-text-base">Method <span class="text-danger">*</span></label>
                                <Select
                                    :model-value="line.depreciation_method"
                                    :options="depreciationMethodOptions"
                                    placeholder="Method"
                                    @update:model-value="(v) => (line.depreciation_method = v)"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-text-base">Rate (%) <span class="text-danger">*</span></label>
                                <Input v-model="line.depreciation_rate" type="number" min="0" max="100" step="0.01" inputmode="decimal" required />
                            </div>
                            <div class="col-span-2">
                                <label class="mb-1 block text-[11px] font-semibold text-text-base">Salvage Value</label>
                                <Input v-model="line.salvage_value" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" />
                            </div>
                        </div>
                        <p class="mt-1 text-[11px] text-text-muted">
                            The account for this line must be filed under "Fixed Assets".
                        </p>
                    </div>
                </div>

                <Button variant="secondary" tone="purple" type="button" class="mt-1" @click="addLine">
                    <Plus class="h-3.5 w-3.5" aria-hidden="true" /> Add another row
                </Button>
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Charges &amp; notes</h4>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate (%) <span class="text-danger">*</span></label>
                    <Input v-model="form.vat_rate" type="number" min="0" max="100" step="0.01" inputmode="decimal" required />
                    <p class="mt-1 text-xs text-text-faint">Charged only on rows ticked Taxable.</p>
                    <p v-if="form.errors.vat_rate" class="mt-1 text-sm text-danger">{{ form.errors.vat_rate }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                    <Input v-model="form.narration" type="text" placeholder="Optional" />
                </div>
            </div>

            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Bill summary</h4>
            <div class="text-sm">
                <p v-if="previewError" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                    {{ previewError }}
                </p>
                <div v-else-if="totals" class="grid grid-cols-2 gap-1">
                    <span class="text-text-muted">Taxable amount</span>
                    <span class="text-right font-semibold text-text-strong">{{ formatMoney(totals.taxable_amount) }}</span>
                    <template v-if="totals.nontaxable_amount !== '0.00'">
                        <span class="text-text-muted">Non-taxable amount</span>
                        <span class="text-right font-semibold text-text-strong">{{ formatMoney(totals.nontaxable_amount) }}</span>
                    </template>
                    <span class="text-text-muted">VAT ({{ totals.vat_rate }}%)</span>
                    <span class="text-right font-semibold text-text-strong">{{ formatMoney(totals.vat_amount) }}</span>
                    <span class="font-bold text-text-strong">Bill total</span>
                    <span class="text-right font-bold text-text-strong">{{ formatMoney(totals.total) }}</span>
                </div>
                <p v-else class="text-text-muted">Add a line to see the bill total.</p>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="!canSubmit">
                    Create capital purchase
                </Button>
            </div>
        </form>
    </Card>
</template>
