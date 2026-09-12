<script setup>
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { formatMoney, multiplyMoney, sumMoney } from '@/lib/money.js';
import { todayInKathmandu } from '@/lib/format.js';

const props = defineProps({
    items: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    // The one closed fiscal year currently reopened for correction, or
    // null - this create form only ever offers this single alternate to
    // the currently open year (never any other closed year), per the
    // locked design decision in plans/invoicing-settings-sale-purchase-ux.
    // md ("Locked decisions" #3 / Phase D's recommended option (a)).
    correctionFiscalYear: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'posted']);
const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const fiscalYearOptions = computed(() =>
    props.correctionFiscalYear
        ? [{ value: props.correctionFiscalYear.id, label: `${props.correctionFiscalYear.name} (reopened for correction)` }]
        : [],
);

const itemOptions = computed(() => props.items.map((i) => ({ value: i.id, label: `${i.name} (${i.unit})` })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));

const directionOptions = [
    { value: 'in', label: 'In (add stock)' },
    { value: 'out', label: 'Out (remove stock)' },
];

const reasonOptions = [
    { value: 'damage', label: 'Damage' },
    { value: 'lost', label: 'Lost' },
    { value: 'correction', label: 'Correction' },
    { value: 'found', label: 'Found' },
    { value: 'opening', label: 'Opening stock' },
    { value: 'other', label: 'Other' },
];

const zeroValueReasons = ['damage', 'lost'];

function emptyLine() {
    return { item_id: null, direction: 'in', reason_type: 'correction', quantity: '', unit_cost_rate: '', remarks: '' };
}

const form = useForm({
    // Today in Kathmandu, not the UTC day: toISOString() named yesterday
    // between midnight and 05:44 local time, which could back-date a stock
    // movement into the previous fiscal year (contract C8).
    date: todayInKathmandu(),
    note: '',
    store_id: null,
    // Blank fiscal_year_id posts into whichever year is currently open;
    // the only other value the picker offers is correctionFiscalYear's
    // id, in which case reason becomes required (see
    // isCorrectionSelected/submit()).
    fiscal_year_id: null,
    reason: '',
    lines: [emptyLine()],
});

const isCorrectionSelected = computed(
    () => !!props.correctionFiscalYear && form.fiscal_year_id === props.correctionFiscalYear.id,
);
const canSubmit = computed(() => !isCorrectionSelected.value || form.reason.trim().length > 0);

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

// Opening stock is always an addition - force and lock direction to 'in'
// the moment the reason is picked, matching the model-layer invariant.
watch(
    () => form.lines.map((line) => line.reason_type),
    (reasons) => {
        reasons.forEach((reason, index) => {
            if (reason === 'opening') form.lines[index].direction = 'in';
        });
    },
    { deep: true },
);

function isZeroValue(line) {
    return zeroValueReasons.includes(line.reason_type);
}

// Exact decimal arithmetic, never floats: multiplyMoney rounds the product
// once, the same way App\Support\Money\Money::round() does server-side, so
// the preview and the posted document always agree (contracts C1/C8).
// A line that is not yet fillable contributes nothing rather than NaN.
function lineValue(line) {
    if (isZeroValue(line)) return '0.00';

    const quantity = String(line.quantity ?? '').trim();
    const rate = String(line.unit_cost_rate ?? '').trim();

    if (quantity === '' || rate === '') return '0.00';

    try {
        return multiplyMoney(rate, quantity);
    } catch {
        return '0.00';
    }
}

const totalValue = computed(() => {
    try {
        return sumMoney(form.lines.map(lineValue));
    } catch {
        return '0.00';
    }
});

function submit() {
    form.transform((data) => ({
        ...data,
        fiscal_year_id: data.fiscal_year_id || undefined,
        reason: isCorrectionSelected.value ? data.reason : undefined,
        // Values go over the wire as the strings the user typed. Number()
        // here is what turned 0.00004 into a charged-for line that stored as
        // 0.0000 (audit P0-5); a blank rate stays null, which means "no cost
        // basis" rather than "free".
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            direction: line.reason_type === 'opening' ? 'in' : line.direction,
            reason_type: line.reason_type,
            quantity: String(line.quantity ?? '').trim(),
            unit_cost_rate: isZeroValue(line) || String(line.unit_cost_rate ?? '').trim() === ''
                ? null
                : String(line.unit_cost_rate).trim(),
            remarks: line.remarks || null,
        })),
    })).post('/stock-adjustments', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New stock adjustment</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div v-if="isAdmin && correctionFiscalYear">
                <label for="adjustment-fiscal-year" class="mb-1 block text-sm font-semibold text-text-base">Fiscal year</label>
                <Select
                    id="adjustment-fiscal-year"
                    v-model="form.fiscal_year_id"
                    :options="fiscalYearOptions"
                    placeholder="Currently open fiscal year"
                />
                <p v-if="form.errors.fiscal_year_id" class="mt-1 text-sm text-danger">{{ form.errors.fiscal_year_id }}</p>
            </div>

            <div v-if="isCorrectionSelected" class="flex flex-col gap-3 border-[1.5px] border-warning-text bg-warning-bg px-3 py-3">
                <p class="text-sm text-warning-text">
                    {{ correctionFiscalYear.name }} is reopened for correction. This adjustment will post into
                    that year's window instead of the currently open one.
                </p>
                <div>
                    <label for="adjustment-reason" class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <textarea
                        id="adjustment-reason"
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
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Note</label>
                    <Input v-model="form.note" type="text" placeholder="Optional" />
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

            <div>
                <div class="mb-2 grid grid-cols-[1fr_130px_140px_100px_110px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Direction</span>
                    <span>Reason</span>
                    <span>Quantity</span>
                    <span>Unit cost</span>
                    <span>Remarks</span>
                    <span></span>
                </div>

                <div
                    v-for="(line, index) in form.lines"
                    :key="index"
                    class="mb-2 grid grid-cols-[1fr_130px_140px_100px_110px_1fr_28px] items-start gap-2"
                >
                    <div>
                        <Combobox
                            :model-value="line.item_id"
                            :options="itemOptions"
                            placeholder="Select item"
                            @update:model-value="(v) => (line.item_id = v)"
                        />
                        <p v-if="form.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.item_id`] }}
                        </p>
                    </div>
                    <Select v-model="line.direction" :options="directionOptions" :disabled="line.reason_type === 'opening'" />
                    <Select v-model="line.reason_type" :options="reasonOptions" />
                    <div>
                        <Input v-model="line.quantity" type="number" min="0.0001" step="0.0001" placeholder="0" required />
                        <p v-if="form.errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.quantity`] }}
                        </p>
                    </div>
                    <div>
                        <Input
                            v-model="line.unit_cost_rate"
                            type="number"
                            min="0"
                            step="0.0001"
                            placeholder="0.0000"
                            :disabled="isZeroValue(line)"
                        />
                        <p v-if="form.errors[`lines.${index}.unit_cost_rate`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.unit_cost_rate`] }}
                        </p>
                    </div>
                    <Input v-model="line.remarks" type="text" placeholder="Optional" />
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

            <div class="grid grid-cols-1 gap-2 border-t-[1.5px] border-border pt-3 text-sm">
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Total value</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(totalValue) }}</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !form.date || !canSubmit">
                    Create Stock Adjustment
                </Button>
            </div>
        </form>
    </Card>
</template>
