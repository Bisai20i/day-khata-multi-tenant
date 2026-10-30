<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import ManufacturingTotalsBar from '@/components/manufacturing/ManufacturingTotalsBar.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import { calculateDocument, formatMoney, formatQuantity, formatRate, sumMoney } from '@/lib/money.js';
import { todayInKathmandu } from '@/lib/format.js';
import CorrectionReasonField from '@/components/manufacturing/CorrectionReasonField.vue';
import { isInCorrectionYear } from '@/components/manufacturing/correctionYear.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // Raw materials only: { id, name, unit, units[], on_hand{storeId: qty}, average_rate }.
    items: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    // The closed year reopened for correction, { id, name, start_date, end_date }, or null.
    correctionFiscalYear: { type: Object, default: null },
});

useLayoutChrome('New Material Issue');

const form = useForm({
    // Today in Kathmandu, not the UTC day (contract C8).
    date: todayInKathmandu(),
    store_id: props.stores[0]?.id ?? null,
    narration: '',
    reason: '',
    lines: [emptyLine()],
});

const needsReason = computed(() => isInCorrectionYear(form.date, props.correctionFiscalYear));

function emptyLine() {
    return { item_id: null, item_unit_id: 'base', quantity: '' };
}

const itemsById = computed(() => Object.fromEntries(props.items.map((item) => [item.id, item])));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));

const itemOptions = computed(() =>
    props.items.map((item) => {
        const onHand = item.on_hand?.[form.store_id] ?? '0';
        const rate = item.average_rate ? `, avg ${formatRate(item.average_rate)}` : '';

        return { value: item.id, label: `${item.name} (on hand ${formatQuantity(onHand)} ${item.unit ?? ''}${rate})` };
    }),
);

function unitOptions(line) {
    const item = itemsById.value[line.item_id];

    return [
        { value: 'base', label: item?.unit ?? 'Base unit' },
        ...(item?.units ?? []).map((unit) => ({ value: unit.id, label: `${unit.name} (x${formatQuantity(unit.conversion_factor)})` })),
    ];
}

function factorOf(line) {
    const unit = itemsById.value[line.item_id]?.units?.find((u) => u.id === line.item_unit_id);

    return unit ? unit.conversion_factor : '1';
}

// Preview only; the server costs the issue under the item locks at the moving
// average as of the document date and its figure is the one that posts. Exact
// arithmetic through the shared calculator (never floats): the first call
// resolves the base-unit quantity, the second prices it at the average rate.
function preview(line) {
    const item = itemsById.value[line.item_id];
    const quantity = String(line.quantity ?? '').trim();

    if (!item || quantity === '') return { base: null, cost: null };

    const sized = calculateDocument([{ quantity, rate: '1', conversion_factor: factorOf(line) }], { vat_rate: '0' });
    if (!sized.ok) return { base: null, cost: null };

    const base = sized.totals.lines[0].base_quantity;
    if (!item.average_rate) return { base, cost: null };

    const priced = calculateDocument([{ quantity: base, rate: item.average_rate }], { vat_rate: '0' });

    return { base, cost: priced.ok ? priced.totals.lines[0].line_total : null };
}

const totalCost = computed(() => {
    const costs = form.lines.map((line) => preview(line).cost).filter((cost) => cost !== null);

    return costs.length ? sumMoney(costs) : null;
});

const totalsFigures = computed(() => [{ label: 'Estimated total cost', value: totalCost.value === null ? '-' : formatMoney(totalCost.value) }]);

const canSubmit = computed(
    () => !!form.date && !!form.store_id && form.lines.some((line) => line.item_id && String(line.quantity ?? '').trim() !== ''),
);

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

// Quantities go as the strings the user typed, never through Number() (audit P0-5).
function submit() {
    if (!canSubmit.value) return;

    form
        .transform((data) => ({
            ...data,
            reason: needsReason.value ? data.reason : null,
            lines: data.lines.map((line) => ({
                item_id: line.item_id,
                item_unit_id: line.item_unit_id === 'base' ? null : line.item_unit_id,
                quantity: String(line.quantity ?? '').trim(),
            })),
        }))
        .post('/material-issues', { preserveScroll: true });
}
</script>

<template>
    <div class="flex min-h-full flex-col">
        <PageHeader title="New Material Issue" description="Issue raw material from a store into work in process. Fields marked * are required." />

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-1 flex-col gap-4" @submit.prevent="submit">
            <Card variant="panel" class="!p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <Label class="mb-1">Date (BS) <span class="text-danger" aria-hidden="true">*</span></Label>
                        <NepaliDateInput v-model="form.date" required />
                        <p v-if="form.errors.date" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.date }}</p>
                    </div>
                    <div>
                        <div class="mb-1 flex items-center gap-1">
                            <Label>Store <span class="text-danger" aria-hidden="true">*</span></Label>
                            <InfoTip text="The store the raw material is taken from. Stock is checked against this store." />
                        </div>
                        <Combobox :model-value="form.store_id" :options="storeOptions" placeholder="Select store" @update:model-value="(v) => (form.store_id = v)" />
                        <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.store_id }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Narration</Label>
                        <Input v-model="form.narration" type="text" placeholder="Optional" />
                        <p v-if="form.errors.narration" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.narration }}</p>
                    </div>
                </div>
            </Card>

            <CorrectionReasonField v-if="needsReason" v-model="form.reason" :year="correctionFiscalYear" :error="form.errors.reason ?? ''" />

            <Card variant="panel" class="!p-4">
                <template #title>
                    <div class="flex items-center justify-between gap-3">
                        <span>Raw materials issued <span class="text-danger">*</span></span>
                        <button type="button" class="flex items-center gap-1 text-xs font-semibold tracking-normal text-primary normal-case" @click="addLine">
                            <Plus class="h-3.5 w-3.5" /> Add raw material
                        </button>
                    </div>
                </template>

                <div class="overflow-x-auto">
                    <div class="min-w-[760px]">
                        <div class="mb-2 grid grid-cols-[1fr_150px_130px_120px_130px_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                            <span>Raw material</span>
                            <span>Unit</span>
                            <span class="text-right">Quantity</span>
                            <span class="text-right">Base qty</span>
                            <span class="text-right">Est. cost</span>
                            <span class="sr-only">Remove</span>
                        </div>

                        <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_150px_130px_120px_130px_28px] items-start gap-2">
                            <div>
                                <Combobox
                                    :model-value="line.item_id"
                                    :options="itemOptions"
                                    placeholder="Select raw material"
                                    @update:model-value="(v) => { line.item_id = v; line.item_unit_id = 'base'; }"
                                />
                                <p v-if="form.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">{{ form.errors[`lines.${index}.item_id`] }}</p>
                            </div>
                            <Select :model-value="line.item_unit_id" :options="unitOptions(line)" @update:model-value="(v) => (line.item_unit_id = v)" />
                            <div>
                                <Input v-model="line.quantity" class="text-right" type="number" min="0.0001" step="0.0001" placeholder="0" required />
                                <p v-if="form.errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">{{ form.errors[`lines.${index}.quantity`] }}</p>
                            </div>
                            <p class="pt-2 text-right text-sm text-text-base">{{ preview(line).base === null ? '-' : formatQuantity(preview(line).base) }}</p>
                            <p class="pt-2 text-right text-sm text-text-base">{{ preview(line).cost === null ? '-' : formatMoney(preview(line).cost) }}</p>
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
                    </div>
                </div>
            </Card>

            <ManufacturingTotalsBar
                :figures="totalsFigures"
                note="An estimate at the current moving-average rate. The posted cost is calculated by the system when you save."
                cancel-href="/material-issues"
                submit-label="Post material issue"
                :can-submit="canSubmit"
                :processing="form.processing"
            />
        </form>
    </div>
</template>
