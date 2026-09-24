<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
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
});

const emit = defineEmits(['cancel', 'posted']);

const itemOptions = computed(() => props.items.map((i) => ({ value: i.id, label: `${i.name} (${i.unit})` })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));

const typeOptions = [
    { value: 'production', label: 'Production' },
    { value: 'refining', label: 'Refining' },
    { value: 'repackaging', label: 'Repackaging' },
];

// Production, Refining, and Repackaging are the same underlying mechanism
// (see StockConversion's own docblock) - only the section labels change
// depending on which one is picked, matching the business language each
// one's users actually use. Repackaging is the arbitrary items-in ->
// items-out conversion legacy day_khata called "Stock Transfer" - renamed
// here since that label now belongs to the genuinely different store-to-
// store relocation feature (see StockConversionType's own docblock).
const sectionLabels = {
    production: { input: 'Raw materials consumed', output: 'Finished good produced' },
    refining: { input: 'Input material consumed', output: 'Refined output produced' },
    repackaging: { input: 'Items consumed', output: 'Items produced' },
};

function emptyLine() {
    return { item_id: null, quantity: '', unit_cost_rate: '', remarks: '' };
}

const form = useForm({
    type: 'production',
    // Today in Kathmandu, not the UTC day (contract C8).
    date: todayInKathmandu(),
    note: '',
    store_id: null,
    input_lines: [emptyLine()],
    output_lines: [emptyLine()],
});

const labels = computed(() => sectionLabels[form.type]);

function addLine(section) {
    form[section].push(emptyLine());
}

function removeLine(section, index) {
    form[section].splice(index, 1);
}

// Exact decimal arithmetic, never floats: multiplyMoney rounds the product
// once, the same way the server does, so the preview and the posted document
// always agree (contracts C1/C8).
function lineValue(line) {
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
        return sumMoney([...form.input_lines, ...form.output_lines].map(lineValue));
    } catch {
        return '0.00';
    }
});

// Sent as the strings the user typed, never through Number(): that is what
// let a 0.00004 quantity be accepted and stored as 0.0000 (audit P0-5). A
// blank rate stays null, meaning "no cost stated" rather than "free" - see
// StockConversion's docblock on deferred output costing.
function payloadLine(line) {
    return {
        item_id: line.item_id,
        quantity: String(line.quantity ?? '').trim(),
        unit_cost_rate: String(line.unit_cost_rate ?? '').trim() === '' ? null : String(line.unit_cost_rate).trim(),
        remarks: line.remarks || null,
    };
}

function submit() {
    form.transform((data) => ({
        ...data,
        input_lines: data.input_lines.map(payloadLine),
        output_lines: data.output_lines.map(payloadLine),
    })).post('/stock-conversions', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-text-strong">New production / refining entry</h3>
                <p class="text-sm text-text-muted">Input items are used up and output items are added to stock.</p>
            </div>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.input_lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.input_lines }}
        </p>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <div class="grid grid-cols-4 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Type <span class="text-danger">*</span></label>
                    <Select :model-value="form.type" :options="typeOptions" @update:model-value="(v) => (form.type = v)" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                    <Combobox
                        :model-value="form.store_id"
                        :options="storeOptions"
                        placeholder="Default store"
                        @update:model-value="(v) => (form.store_id = v)"
                    />
                    <p class="mt-1 text-xs text-text-faint">Where the stock is used and produced. Leave blank for your default store.</p>
                    <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Note</label>
                    <Input v-model="form.note" type="text" placeholder="Optional" />
                    <p v-if="form.errors.note" class="mt-1 text-sm text-danger">{{ form.errors.note }}</p>
                </div>
            </div>

            <div>
                <h4 class="mb-2 text-sm font-bold text-text-strong">{{ labels.input }}</h4>
                <div class="mb-2 grid grid-cols-[1fr_140px_140px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item <span class="text-danger">*</span></span>
                    <span>Quantity <span class="text-danger">*</span></span>
                    <span>Unit cost (optional)</span>
                    <span>Remarks</span>
                    <span></span>
                </div>

                <div
                    v-for="(line, index) in form.input_lines"
                    :key="index"
                    class="mb-2 grid grid-cols-[1fr_140px_140px_1fr_28px] items-start gap-2"
                >
                    <div>
                        <Combobox
                            :model-value="line.item_id"
                            :options="itemOptions"
                            placeholder="Select item"
                            @update:model-value="(v) => (line.item_id = v)"
                        />
                        <p v-if="form.errors[`input_lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`input_lines.${index}.item_id`] }}
                        </p>
                    </div>
                    <div>
                        <Input v-model="line.quantity" type="number" min="0.0001" step="0.0001" placeholder="0" required />
                        <p v-if="form.errors[`input_lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`input_lines.${index}.quantity`] }}
                        </p>
                    </div>
                    <Input v-model="line.unit_cost_rate" type="number" min="0" step="0.0001" placeholder="0.0000" />
                    <Input v-model="line.remarks" type="text" placeholder="Optional" />
                    <button
                        v-if="form.input_lines.length > 1"
                        type="button"
                        class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                        aria-label="Remove line"
                        @click="removeLine('input_lines', index)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <Button variant="secondary" tone="purple" type="button" class="mt-1" @click="addLine('input_lines')">
                    <Plus class="h-3.5 w-3.5" /> Add input item
                </Button>
            </div>

            <div class="border-t-[1.5px] border-border pt-4">
                <h4 class="mb-2 text-sm font-bold text-text-strong">{{ labels.output }}</h4>
                <div class="mb-2 grid grid-cols-[1fr_140px_140px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item <span class="text-danger">*</span></span>
                    <span>Quantity <span class="text-danger">*</span></span>
                    <span>Unit cost (optional)</span>
                    <span>Remarks</span>
                    <span></span>
                </div>

                <div
                    v-for="(line, index) in form.output_lines"
                    :key="index"
                    class="mb-2 grid grid-cols-[1fr_140px_140px_1fr_28px] items-start gap-2"
                >
                    <div>
                        <Combobox
                            :model-value="line.item_id"
                            :options="itemOptions"
                            placeholder="Select item"
                            @update:model-value="(v) => (line.item_id = v)"
                        />
                        <p v-if="form.errors[`output_lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`output_lines.${index}.item_id`] }}
                        </p>
                    </div>
                    <div>
                        <Input v-model="line.quantity" type="number" min="0.0001" step="0.0001" placeholder="0" required />
                        <p v-if="form.errors[`output_lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`output_lines.${index}.quantity`] }}
                        </p>
                    </div>
                    <Input v-model="line.unit_cost_rate" type="number" min="0" step="0.0001" placeholder="0.0000" />
                    <Input v-model="line.remarks" type="text" placeholder="Optional" />
                    <button
                        v-if="form.output_lines.length > 1"
                        type="button"
                        class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                        aria-label="Remove line"
                        @click="removeLine('output_lines', index)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <Button variant="secondary" tone="purple" type="button" class="mt-1" @click="addLine('output_lines')">
                    <Plus class="h-3.5 w-3.5" /> Add output item
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
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !form.date">
                    {{ form.processing ? 'Posting...' : 'Post entry' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
