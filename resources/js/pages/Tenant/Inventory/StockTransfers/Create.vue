<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const itemOptions = computed(() => props.items.map((i) => ({ value: i.id, label: `${i.name} (${i.unit})` })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));

function emptyLine() {
    return { item_id: null, quantity: '', unit_cost_rate: '', remarks: '' };
}

const form = useForm({
    date: '',
    from_store_id: null,
    to_store_id: null,
    note: '',
    lines: [emptyLine()],
});

// Mirrors the model-layer guard in StockTransfer::post() - surfaced early
// here so the user sees the problem before submitting, not just after a
// round trip.
const sameStoreSelected = computed(
    () => !!form.from_store_id && !!form.to_store_id && form.from_store_id === form.to_store_id,
);

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

const totalValue = computed(() =>
    form.lines.reduce((sum, line) => {
        const qty = Number(line.quantity) || 0;
        const rate = Number(line.unit_cost_rate) || 0;
        return sum + qty * rate;
    }, 0),
);

const canSubmit = computed(
    () => !!form.date && !!form.from_store_id && !!form.to_store_id && !sameStoreSelected.value,
);

function submit() {
    form.transform((data) => ({
        ...data,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            quantity: Number(line.quantity) || 0,
            unit_cost_rate: line.unit_cost_rate === '' ? null : Number(line.unit_cost_rate),
            remarks: line.remarks || null,
        })),
    })).post('/stock-transfers', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New stock transfer</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-4 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">From store <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.from_store_id"
                        :options="storeOptions"
                        placeholder="Source store"
                        @update:model-value="(v) => (form.from_store_id = v)"
                    />
                    <p v-if="form.errors.from_store_id" class="mt-1 text-sm text-danger">{{ form.errors.from_store_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">To store <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.to_store_id"
                        :options="storeOptions"
                        placeholder="Destination store"
                        @update:model-value="(v) => (form.to_store_id = v)"
                    />
                    <p v-if="form.errors.to_store_id" class="mt-1 text-sm text-danger">{{ form.errors.to_store_id }}</p>
                    <p v-else-if="sameStoreSelected" class="mt-1 text-sm text-danger">The source and destination store must be different.</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Note</label>
                    <Input v-model="form.note" type="text" placeholder="Optional" />
                </div>
            </div>

            <div>
                <div class="mb-2 grid grid-cols-[1fr_120px_140px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Quantity</span>
                    <span>Unit cost</span>
                    <span>Remarks</span>
                    <span></span>
                </div>

                <div
                    v-for="(line, index) in form.lines"
                    :key="index"
                    class="mb-2 grid grid-cols-[1fr_120px_140px_1fr_28px] items-start gap-2"
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
                    <Input v-model="line.quantity" type="number" min="0" step="0.0001" placeholder="0" />
                    <Input v-model="line.unit_cost_rate" type="number" min="0" step="0.01" placeholder="0.00" />
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
                    <p class="font-bold text-text-strong">{{ totalValue.toFixed(2) }}</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !canSubmit">
                    Create Stock Transfer
                </Button>
            </div>
        </form>
    </Card>
</template>
