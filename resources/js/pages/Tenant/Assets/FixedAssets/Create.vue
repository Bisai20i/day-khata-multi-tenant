<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    pools: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);
const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name })));
const poolOptions = computed(() => props.pools.map((p) => ({ value: p.value, label: p.label })));
const poolsByValue = computed(() => new Map(props.pools.map((p) => [p.value, p])));

const methodOptions = [
    { value: 'slm', label: 'Straight-Line (SLM)' },
    { value: 'wdv', label: 'Written-Down Value (WDV)' },
];

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'credit', label: 'Credit' },
];

// Registering an asset the business already owned before this system went
// live (T14): no cash/bank movement, so payment mode/VAT are replaced by an
// opening accumulated-depreciation figure. Posts to a different endpoint
// (see submit()) - FixedAsset::registerExisting() rather than ::post().
const registeringExisting = ref(false);

const form = useForm({
    asset_name: '',
    category: '',
    purchase_date: '',
    cost: '',
    vat_rate: '',
    accumulated_depreciation: '',
    salvage_value: '',
    depreciation_method: 'wdv',
    depreciation_rate: '',
    payment_mode: 'cash',
    bank_account_id: null,
    supplier_id: null,
    narration: '',
});

// Pre-fills the statutory rate for the chosen pool - stays editable
// afterwards, matching legacy's "convenience pre-fill, not a lock" behavior.
// Pool E has no fixed rate, so it's left for the user to enter.
watch(
    () => form.category,
    (category) => {
        const rate = poolsByValue.value.get(category)?.defaultRate;
        if (rate !== null && rate !== undefined) {
            form.depreciation_rate = String(rate);
        }
    },
);

const showBankAccount = computed(() => form.payment_mode === 'bank');
const showSupplier = computed(() => form.payment_mode === 'credit');

/**
 * The decimal fields are submitted as the strings the user typed, not
 * through Number(): the server validates them with `decimal:0,2` and parses
 * them with Money, so a cost of 1,234.567 is refused with a clear message
 * instead of being silently rounded to fit the column (CONTRACTS C1/C8).
 * An empty optional box is normalised to "0" rather than to a float 0.
 *
 * `registeringExisting` (T14) submits to a different endpoint -
 * FixedAsset::registerExisting() rather than ::post() - with payment mode
 * and VAT replaced by an opening accumulated-depreciation figure, since
 * there is no cash/bank movement or supplier bill behind an asset the
 * business already owned before this system went live.
 */
function submit() {
    if (registeringExisting.value) {
        form.transform((data) => ({
            asset_name: data.asset_name,
            category: data.category,
            purchase_date: data.purchase_date,
            cost: String(data.cost ?? '').trim(),
            accumulated_depreciation: String(data.accumulated_depreciation ?? '').trim() === '' ? '0' : String(data.accumulated_depreciation).trim(),
            salvage_value: String(data.salvage_value ?? '').trim() === '' ? '0' : String(data.salvage_value).trim(),
            depreciation_method: data.depreciation_method,
            depreciation_rate: String(data.depreciation_rate ?? '').trim(),
            narration: data.narration,
        })).post('/fixed-assets/existing', {
            preserveScroll: true,
            onSuccess: () => emit('posted'),
        });

        return;
    }

    form.transform((data) => ({
        ...data,
        cost: String(data.cost ?? '').trim(),
        vat_rate: String(data.vat_rate ?? '').trim() === '' ? '0' : String(data.vat_rate).trim(),
        salvage_value: String(data.salvage_value ?? '').trim() === '' ? '0' : String(data.salvage_value).trim(),
        depreciation_rate: String(data.depreciation_rate ?? '').trim(),
    })).post('/fixed-assets', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">
                {{ registeringExisting ? 'Register an existing asset' : 'New fixed asset' }}
            </h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <label class="mb-4 flex items-center gap-2 text-sm text-text-base">
            <input v-model="registeringExisting" type="checkbox" class="h-4 w-4" />
            This asset was already owned before this system went live (no payment, register its opening cost)
        </label>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Asset Name <span class="text-danger">*</span></label>
                    <Input v-model="form.asset_name" type="text" placeholder="e.g. Office Laptop" required />
                    <p v-if="form.errors.asset_name" class="mt-1 text-sm text-danger">{{ form.errors.asset_name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Depreciation Pool <span class="text-danger">*</span></label>
                    <p class="mb-1 text-xs text-text-faint">The tax group that sets the standard rate.</p>
                    <Select
                        :model-value="form.category"
                        :options="poolOptions"
                        placeholder="Select pool"
                        @update:model-value="(v) => (form.category = v)"
                    />
                    <p v-if="form.errors.category" class="mt-1 text-sm text-danger">{{ form.errors.category }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Purchase Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.purchase_date" required />
                    <p v-if="form.errors.purchase_date" class="mt-1 text-sm text-danger">{{ form.errors.purchase_date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Cost <span class="text-danger">*</span></label>
                    <Input v-model="form.cost" type="number" min="0.01" step="0.01" placeholder="e.g. 50000" required />
                    <p class="mt-1 text-xs text-text-faint">Purchase cost before VAT, in rupees.</p>
                    <p v-if="form.errors.cost" class="mt-1 text-sm text-danger">{{ form.errors.cost }}</p>
                </div>
                <div v-if="registeringExisting">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Accumulated Depreciation So Far</label>
                    <Input v-model="form.accumulated_depreciation" type="number" min="0" step="0.01" placeholder="0.00" />
                    <p class="mt-1 text-xs text-text-faint">Total depreciation already written off before this system.</p>
                    <p v-if="form.errors.accumulated_depreciation" class="mt-1 text-sm text-danger">{{ form.errors.accumulated_depreciation }}</p>
                </div>
                <div v-else>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT Rate (%)</label>
                    <Input v-model="form.vat_rate" type="number" min="0" max="100" step="0.01" placeholder="0.00" />
                    <p class="mt-1 text-xs text-text-faint">Leave blank if no VAT was charged.</p>
                    <p v-if="form.errors.vat_rate" class="mt-1 text-sm text-danger">{{ form.errors.vat_rate }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Salvage Value</label>
                    <Input v-model="form.salvage_value" type="number" min="0" step="0.01" placeholder="0.00" />
                    <p class="mt-1 text-xs text-text-faint">Expected value at the end of its life. Leave blank for 0.</p>
                    <p v-if="form.errors.salvage_value" class="mt-1 text-sm text-danger">{{ form.errors.salvage_value }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Depreciation Method <span class="text-danger">*</span></label>
                    <Select
                        :model-value="form.depreciation_method"
                        :options="methodOptions"
                        @update:model-value="(v) => (form.depreciation_method = v)"
                    />
                    <p class="mt-1 text-xs text-text-faint">Straight-Line writes off the same amount every year; Written-Down Value applies the rate to the remaining balance.</p>
                    <p v-if="form.errors.depreciation_method" class="mt-1 text-sm text-danger">{{ form.errors.depreciation_method }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Depreciation Rate (%) <span class="text-danger">*</span></label>
                    <Input v-model="form.depreciation_rate" type="number" min="0" max="100" step="0.01" placeholder="e.g. 15" required />
                    <p class="mt-1 text-xs text-text-faint">Yearly percentage. Pre-filled from the chosen pool; you can change it.</p>
                    <p v-if="form.errors.depreciation_rate" class="mt-1 text-sm text-danger">{{ form.errors.depreciation_rate }}</p>
                </div>
                <template v-if="!registeringExisting">
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
                    <div v-if="showSupplier">
                        <label class="mb-1 block text-sm font-semibold text-text-base">Supplier</label>
                        <Combobox
                            :model-value="form.supplier_id"
                            :options="supplierOptions"
                            placeholder="Select supplier"
                            @update:model-value="(v) => (form.supplier_id = v)"
                        />
                        <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-danger">{{ form.errors.supplier_id }}</p>
                    </div>
                </template>
                <div class="md:col-span-3">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                    <Input v-model="form.narration" type="text" placeholder="Optional" />
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : registeringExisting ? 'Register existing asset' : 'Add fixed asset' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
