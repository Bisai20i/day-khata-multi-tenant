<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { calculateDocument, formatMoney } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    quotation: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'saved']);

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const itemOptions = computed(() => props.items.map((i) => ({ value: i.id, label: `${i.name} (${i.unit})` })));

// Whether a line is vatable is the item's property, never something the user
// picks here - that is what makes the preview split the subtotal the same way
// the server (and the sale this quotation converts into) will.
const vatableByItemId = computed(() =>
    props.items.reduce((map, item) => {
        map[item.id] = item.is_vatable === true || item.is_vatable === 1;
        return map;
    }, {}),
);

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
    date: props.quotation?.date?.slice(0, 10) ?? todayInKathmandu(),
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

/** Blank means "nothing entered yet", which the calculator reads as zero. */
function orZero(value) {
    return value === '' || value === null || value === undefined ? '0' : String(value);
}

const documentLines = computed(() =>
    form.lines.map((line) => ({
        quantity: orZero(line.quantity),
        rate: orZero(line.rate),
        discount: orZero(line.discount),
        discount_type: 'flat',
        vatable: vatableByItemId.value[line.item_id] === true,
        conversion_factor: '1',
    })),
);

// The one place this screen works out a total: the exact mirror of the
// server's DocumentCalculator. Every figure below is a decimal string, never a
// JS number, so what the customer is quoted is what the server stores (C8).
const preview = computed(() =>
    calculateDocument(documentLines.value, {
        vat_rate: orZero(form.vat_rate),
        discount: orZero(form.discount),
        discount_type: 'flat',
    }),
);

const totals = computed(() => (preview.value.ok ? preview.value.totals : null));

const hasLineInput = computed(() => form.lines.some((line) => line.item_id && line.quantity !== '' && line.rate !== ''));

const previewError = computed(() => (!preview.value.ok && hasLineInput.value ? preview.value.message : null));

const canSubmit = computed(() => preview.value.ok && !!form.customer_id && !!form.date && !form.processing);

function submit() {
    form.transform((data) => ({
        ...data,
        discount: orZero(data.discount),
        vat_rate: orZero(data.vat_rate),
        expected_total: totals.value?.total,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            quantity: orZero(line.quantity),
            rate: orZero(line.rate),
            discount: orZero(line.discount),
        })),
    }));

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
        <p v-if="form.errors.expected_total" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.expected_total }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Customer <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.customer_id"
                        :options="customerOptions"
                        placeholder="Select customer"
                        @update:model-value="(v) => (form.customer_id = v)"
                    />
                    <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
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
                    <Input v-model="line.quantity" type="number" min="0" step="0.0001" inputmode="decimal" placeholder="0" required />
                    <Input v-model="line.rate" type="number" min="0" step="0.0001" inputmode="decimal" placeholder="0.00" required />
                    <Input v-model="line.discount" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" />
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
                    <Input v-model="form.discount" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate (%)</label>
                    <Input v-model="form.vat_rate" type="number" min="0" max="100" step="0.01" inputmode="decimal" required />
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                <Input v-model="form.narration" type="text" placeholder="Optional" />
            </div>

            <div class="border-t-[1.5px] border-border pt-3 text-sm">
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
                    <template v-if="totals.header_discount !== '0.00'">
                        <span class="text-text-muted">Discount</span>
                        <span class="text-right font-semibold text-text-strong">-{{ formatMoney(totals.header_discount) }}</span>
                    </template>
                    <span class="text-text-muted">VAT ({{ totals.vat_rate }}%)</span>
                    <span class="text-right font-semibold text-text-strong">{{ formatMoney(totals.vat_amount) }}</span>
                    <span class="font-bold text-text-strong">Grand total</span>
                    <span class="text-right font-bold text-text-strong">{{ formatMoney(totals.total) }}</span>
                </div>
                <p v-else class="text-text-muted">Add a line to see the quotation total.</p>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="!canSubmit">
                    {{ quotation ? 'Save changes' : 'Save quotation' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
