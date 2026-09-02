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
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    quotation: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'saved']);

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const itemOptions = computed(() => props.items.map((i) => ({ value: i.id, label: `${i.name} (${i.unit})` })));

function emptyLine() {
    return { item_id: null, quantity: '', rate: '', discount: '' };
}

function linesFromQuotation() {
    if (!props.quotation) return [emptyLine()];

    return props.quotation.lines.map((line) => ({
        item_id: line.item_id,
        quantity: String(line.quantity),
        rate: String(line.rate),
        discount: String(line.discount),
    }));
}

const form = useForm({
    customer_id: props.quotation?.customer_id ?? null,
    date: props.quotation?.date?.slice(0, 10) ?? '',
    discount: props.quotation ? String(props.quotation.discount) : '',
    vat_rate: props.quotation ? String(props.quotation.vat_rate) : '13',
    reference_number: props.quotation?.reference_number ?? '',
    narration: props.quotation?.narration ?? '',
    lines: linesFromQuotation(),
});

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

const total = computed(() =>
    form.lines.reduce((sum, line) => {
        const qty = Number(line.quantity) || 0;
        const rate = Number(line.rate) || 0;
        const discount = Number(line.discount) || 0;
        return sum + (qty * rate - discount);
    }, 0),
);

function submit() {
    const payload = (data) => ({
        ...data,
        discount: Number(data.discount) || 0,
        vat_rate: Number(data.vat_rate) || 0,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            quantity: Number(line.quantity) || 0,
            rate: Number(line.rate) || 0,
            discount: Number(line.discount) || 0,
        })),
    });

    form.transform(payload);

    if (props.quotation) {
        form.put(`/quotations/${props.quotation.id}`, {
            preserveScroll: true,
            onSuccess: () => emit('saved'),
        });
    } else {
        form.post('/quotations', {
            preserveScroll: true,
            onSuccess: () => emit('saved'),
        });
    }
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">{{ quotation ? 'Edit quotation' : 'New quotation' }}</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Customer</label>
                    <Combobox
                        :model-value="form.customer_id"
                        :options="customerOptions"
                        placeholder="Select customer"
                        @update:model-value="(v) => (form.customer_id = v)"
                    />
                    <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date</label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reference #</label>
                    <Input v-model="form.reference_number" type="text" placeholder="Optional" />
                </div>
            </div>

            <div>
                <div class="mb-2 grid grid-cols-[1fr_110px_110px_100px_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Quantity</span>
                    <span>Rate</span>
                    <span>Discount</span>
                    <span></span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_110px_110px_100px_28px] items-start gap-2">
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
                    <Input v-model="line.rate" type="number" min="0" step="0.01" placeholder="0.00" />
                    <Input v-model="line.discount" type="number" min="0" step="0.01" placeholder="0.00" />
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

            <div class="grid grid-cols-2 gap-4 border-t-[1.5px] border-border pt-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Header discount</label>
                    <Input v-model="form.discount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate (%)</label>
                    <Input v-model="form.vat_rate" type="number" min="0" step="0.01" />
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                <Input v-model="form.narration" type="text" placeholder="Optional" />
            </div>

            <div class="grid grid-cols-1 gap-2 border-t-[1.5px] border-border pt-3 text-sm">
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Estimated total (before VAT)</p>
                    <p class="font-bold text-text-strong">{{ (total - (Number(form.discount) || 0)).toFixed(2) }}</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !form.customer_id || !form.date">
                    {{ quotation ? 'Save changes' : 'Save quotation' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
