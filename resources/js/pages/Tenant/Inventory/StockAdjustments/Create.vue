<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

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
    date: '',
    note: '',
    store_id: null,
    lines: [emptyLine()],
});

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

const totalValue = computed(() =>
    form.lines.reduce((sum, line) => {
        if (isZeroValue(line)) return sum;
        const qty = Number(line.quantity) || 0;
        const rate = Number(line.unit_cost_rate) || 0;
        return sum + qty * rate;
    }, 0),
);

function submit() {
    form.transform((data) => ({
        ...data,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            direction: line.reason_type === 'opening' ? 'in' : line.direction,
            reason_type: line.reason_type,
            quantity: Number(line.quantity) || 0,
            unit_cost_rate: isZeroValue(line) ? 0 : Number(line.unit_cost_rate) || 0,
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
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date</label>
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
                    <Input v-model="line.quantity" type="number" min="0" step="0.0001" placeholder="0" />
                    <Input
                        v-model="line.unit_cost_rate"
                        type="number"
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                        :disabled="isZeroValue(line)"
                    />
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
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !form.date">
                    Post adjustment
                </Button>
            </div>
        </form>
    </Card>
</template>
