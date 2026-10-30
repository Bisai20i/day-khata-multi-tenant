<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import IssueNoteLines from '@/components/manufacturing/IssueNoteLines.vue';
import ManufacturingTotalsBar from '@/components/manufacturing/ManufacturingTotalsBar.vue';
import { formatMoney, sumMoney } from '@/lib/money.js';
import { todayInKathmandu } from '@/lib/format.js';
import CorrectionReasonField from '@/components/manufacturing/CorrectionReasonField.vue';
import { isInCorrectionYear } from '@/components/manufacturing/correctionYear.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    stores: { type: Array, default: () => [] },
    finishedItems: { type: Array, default: () => [] },
    issueNotes: { type: Array, default: () => [] },
    creditAccounts: { type: Array, default: () => [] },
    costTypes: { type: Array, default: () => [] },
    // The closed year reopened for correction, { id, name, start_date, end_date }, or null.
    correctionFiscalYear: { type: Object, default: null },
});

useLayoutChrome('New production entry');

const form = useForm({
    date: todayInKathmandu(),
    store_id: props.stores[0]?.id ?? null,
    finished_item_id: null,
    planned_quantity: '',
    narration: '',
    material_issue_note_ids: [],
    direct_costs: [],
    reason: '',
});

// The entry and every up-front cost share one correction reason: any of
// their dates in the reopened year needs it.
const needsReason = computed(() =>
    [form.date, ...form.direct_costs.map((cost) => cost.date)].some((date) => isInCorrectionYear(date, props.correctionFiscalYear)),
);

const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const itemOptions = computed(() => props.finishedItems.map((i) => ({ value: i.id, label: `${i.name} (${i.unit ?? ''})` })));
const typeOptions = computed(() => props.costTypes);
const accountOptions = computed(() => props.creditAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })));

// Only notes issued from the chosen store can feed the entry (the model
// rejects a mismatch too); the lines shown are exactly what will be costed.
const storeNotes = computed(() => props.issueNotes.filter((n) => n.store_id === form.store_id));

// Changing store drops any selection that no longer belongs to it.
watch(
    () => form.store_id,
    () => {
        const allowed = new Set(storeNotes.value.map((n) => n.id));
        form.material_issue_note_ids = form.material_issue_note_ids.filter((id) => allowed.has(id));
    },
);

const selectedNotes = computed(() => storeNotes.value.filter((n) => form.material_issue_note_ids.includes(n.id)));
const materialTotal = computed(() => sumMoney(selectedNotes.value.map((n) => n.total_cost)));

const directTotal = computed(() => {
    try {
        return sumMoney(form.direct_costs.map((c) => String(c.amount ?? '').trim() || '0'));
    } catch {
        return '0.00';
    }
});

const grandTotal = computed(() => sumMoney([materialTotal.value, directTotal.value]));

const totalsFigures = computed(() => [
    { label: 'Material issued', value: formatMoney(materialTotal.value) },
    { label: 'Direct costs', value: formatMoney(directTotal.value) },
    { label: 'Opening WIP', value: formatMoney(grandTotal.value) },
]);

const canSubmit = computed(
    () =>
        !!form.date &&
        !!form.store_id &&
        !!form.finished_item_id &&
        String(form.planned_quantity ?? '').trim() !== '' &&
        form.material_issue_note_ids.length > 0,
);

function toggleNote(id) {
    form.material_issue_note_ids = form.material_issue_note_ids.includes(id)
        ? form.material_issue_note_ids.filter((n) => n !== id)
        : [...form.material_issue_note_ids, id];
}

function addCost() {
    form.direct_costs.push({
        date: form.date,
        cost_type: props.costTypes[0]?.value ?? 'labor',
        description: '',
        amount: '',
        credit_account_id: null,
    });
}

function removeCost(index) {
    form.direct_costs.splice(index, 1);
}

function submit() {
    if (!canSubmit.value) return;

    form.transform((data) => ({ ...data, reason: needsReason.value ? data.reason : null })).post('/production-entries', { preserveScroll: true });
}
</script>

<template>
    <div class="flex min-h-full flex-col">
        <PageHeader
            title="New production entry"
            description="Pick the issued material, set the planned output and add any direct costs known so far. Fields marked * are required."
        />

        <form class="flex flex-1 flex-col gap-4" @submit.prevent="submit">
            <Card variant="panel" class="!p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <Label class="mb-1">Date (BS) <span class="text-danger" aria-hidden="true">*</span></Label>
                        <NepaliDateInput v-model="form.date" required />
                        <p v-if="form.errors.date" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.date }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Store <span class="text-danger" aria-hidden="true">*</span></Label>
                        <Combobox :model-value="form.store_id" :options="storeOptions" placeholder="Select store" @update:model-value="(v) => (form.store_id = v)" />
                        <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.store_id }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Finished good <span class="text-danger" aria-hidden="true">*</span></Label>
                        <Combobox :model-value="form.finished_item_id" :options="itemOptions" placeholder="Select item" @update:model-value="(v) => (form.finished_item_id = v)" />
                        <p v-if="form.errors.finished_item_id" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.finished_item_id }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Planned quantity <span class="text-danger" aria-hidden="true">*</span></Label>
                        <Input v-model="form.planned_quantity" type="text" inputmode="decimal" placeholder="0.0000" class="text-right" />
                        <p v-if="form.errors.planned_quantity" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.planned_quantity }}</p>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <Label class="mb-1">Narration</Label>
                        <Input v-model="form.narration" type="text" placeholder="Optional note" />
                        <p v-if="form.errors.narration" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.narration }}</p>
                    </div>
                </div>
            </Card>

            <CorrectionReasonField v-if="needsReason" v-model="form.reason" :year="correctionFiscalYear" :error="form.errors.reason ?? ''" />

            <Card variant="panel" class="!p-4">
                <template #title>
                    <div class="flex items-center gap-1.5">
                        <span>Material issue notes <span class="text-danger">*</span></span>
                        <span class="bg-primary-tint px-2 py-0.5 text-[11px] font-bold tracking-normal text-primary">{{ form.material_issue_note_ids.length }}</span>
                    </div>
                </template>
                <p class="mb-3 text-xs text-text-muted">Posted notes from this store that are not yet attached to a production entry. At least one is required.</p>
                <p v-if="form.errors.material_issue_note_ids" class="mb-3 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
                    {{ form.errors.material_issue_note_ids }}
                </p>
                <p v-if="storeNotes.length === 0" class="py-6 text-center text-sm text-text-faint">No unattached material issue notes for this store.</p>
                <div
                    v-for="note in storeNotes"
                    :key="note.id"
                    class="mb-3 border-[1.5px] last:mb-0"
                    :class="form.material_issue_note_ids.includes(note.id) ? 'border-primary' : 'border-border'"
                >
                    <label class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 bg-bg-subtle px-3 py-2 text-sm">
                        <input type="checkbox" class="size-4 border-[1.5px] border-border" :checked="form.material_issue_note_ids.includes(note.id)" @change="toggleNote(note.id)" />
                        <span class="font-semibold text-text-strong">MI-{{ note.number }}</span>
                        <span class="text-text-muted">{{ note.date }}</span>
                        <span class="ml-auto font-bold text-text-strong">{{ formatMoney(note.total_cost) }}</span>
                    </label>
                    <IssueNoteLines :lines="note.items" />
                </div>
            </Card>

            <Card variant="panel" class="!p-4">
                <template #title>
                    <div class="flex items-center justify-between gap-3">
                        <span>Direct costs</span>
                        <button type="button" class="flex items-center gap-1 text-xs font-semibold tracking-normal text-primary normal-case" @click="addCost">
                            <Plus class="h-3.5 w-3.5" /> Add cost
                        </button>
                    </div>
                </template>
                <p v-if="form.direct_costs.length === 0" class="py-6 text-center text-sm text-text-faint">No direct costs yet. They can also be added after the entry is created.</p>
                <div v-else class="overflow-x-auto">
                    <div class="min-w-[860px]">
                        <div class="mb-2 grid grid-cols-[130px_1fr_120px_150px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                            <span>Type</span>
                            <span>Description</span>
                            <span class="text-right">Amount <span class="text-danger">*</span></span>
                            <span>Date (BS)</span>
                            <span>Paid from / payable <span class="text-danger">*</span></span>
                            <span class="sr-only">Remove</span>
                        </div>
                        <div v-for="(cost, index) in form.direct_costs" :key="index" class="mb-2 grid grid-cols-[130px_1fr_120px_150px_1fr_28px] items-start gap-2">
                            <div>
                                <Select :model-value="cost.cost_type" :options="typeOptions" @update:model-value="(v) => (cost.cost_type = v)" />
                                <p v-if="form.errors[`direct_costs.${index}.cost_type`]" class="mt-1 text-xs text-danger">{{ form.errors[`direct_costs.${index}.cost_type`] }}</p>
                            </div>
                            <div>
                                <Input v-model="cost.description" type="text" placeholder="Description" aria-label="Description" />
                                <p v-if="form.errors[`direct_costs.${index}.description`]" class="mt-1 text-xs text-danger">{{ form.errors[`direct_costs.${index}.description`] }}</p>
                            </div>
                            <div>
                                <Input v-model="cost.amount" type="text" inputmode="decimal" placeholder="0.00" class="text-right" aria-label="Amount" />
                                <p v-if="form.errors[`direct_costs.${index}.amount`]" class="mt-1 text-xs text-danger">{{ form.errors[`direct_costs.${index}.amount`] }}</p>
                            </div>
                            <div>
                                <NepaliDateInput v-model="cost.date" />
                                <p v-if="form.errors[`direct_costs.${index}.date`]" class="mt-1 text-xs text-danger">{{ form.errors[`direct_costs.${index}.date`] }}</p>
                            </div>
                            <div>
                                <Combobox :model-value="cost.credit_account_id" :options="accountOptions" placeholder="Select account" @update:model-value="(v) => (cost.credit_account_id = v)" />
                                <p v-if="form.errors[`direct_costs.${index}.credit_account_id`]" class="mt-1 text-xs text-danger">{{ form.errors[`direct_costs.${index}.credit_account_id`] }}</p>
                            </div>
                            <button
                                type="button"
                                class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                                aria-label="Remove cost"
                                @click="removeCost(index)"
                            >
                                <X class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                </div>
            </Card>

            <ManufacturingTotalsBar
                :figures="totalsFigures"
                cancel-href="/production-entries"
                submit-label="Create production entry"
                processing-label="Creating..."
                :can-submit="canSubmit"
                :processing="form.processing"
            />
        </form>
    </div>
</template>
