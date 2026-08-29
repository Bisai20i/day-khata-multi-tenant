<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';

const props = defineProps({
    sales: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const saleOptions = computed(() =>
    props.sales.map((sale) => ({
        value: sale.id,
        label: `${sale.date} — ${sale.customer?.name ?? 'Unknown'} (${Number(sale.total).toFixed(2)})`,
    })),
);
const accountOptions = computed(() =>
    props.accounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} — ${a.name}` : a.name })),
);

const selectedSaleId = ref(null);
const selectedSale = computed(() => props.sales.find((sale) => sale.id === selectedSaleId.value) ?? null);

const returnQuantities = ref({});

function onSaleChange(value) {
    selectedSaleId.value = value;
    returnQuantities.value = {};
}

const form = useForm({
    sale_id: null,
    date: '',
    reason: '',
    refund_account_id: null,
    lines: [],
});

function submit() {
    const lines = Object.entries(returnQuantities.value)
        .filter(([, quantity]) => Number(quantity) > 0)
        .map(([saleLineId, quantity]) => ({ sale_line_id: Number(saleLineId), quantity: Number(quantity) }));

    form
        .transform((data) => ({
            sale_id: selectedSaleId.value,
            date: data.date,
            reason: data.reason || null,
            refund_account_id: data.refund_account_id || null,
            lines,
        }))
        .post('/sales-returns', {
            preserveScroll: true,
            onSuccess: () => emit('posted'),
        });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New sales return</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Original sale</label>
                    <Combobox :model-value="selectedSaleId" :options="saleOptions" placeholder="Select sale" @update:model-value="onSaleChange" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Return date</label>
                    <Input v-model="form.date" type="date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
            </div>

            <div v-if="selectedSale">
                <div class="mb-2 grid grid-cols-[1fr_100px_100px_120px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Sold qty</span>
                    <span>Rate</span>
                    <span>Return qty</span>
                </div>

                <div
                    v-for="line in selectedSale.lines"
                    :key="line.id"
                    class="mb-2 grid grid-cols-[1fr_100px_100px_120px] items-center gap-2"
                >
                    <span class="text-sm text-text-base">{{ line.item?.name }} ({{ line.item?.unit }})</span>
                    <span class="text-sm text-text-muted">{{ Number(line.quantity).toFixed(4) }}</span>
                    <span class="text-sm text-text-muted">{{ Number(line.rate).toFixed(2) }}</span>
                    <Input
                        :model-value="returnQuantities[line.id] ?? ''"
                        type="number"
                        min="0"
                        :max="line.quantity"
                        step="0.0001"
                        placeholder="0"
                        @update:model-value="(v) => (returnQuantities[line.id] = v)"
                    />
                </div>
            </div>
            <p v-else class="py-4 text-center text-sm text-text-muted">Select a sale to view its lines.</p>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <Input v-model="form.reason" type="text" placeholder="Optional" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Refund via (optional)</label>
                    <Combobox
                        :model-value="form.refund_account_id"
                        :options="accountOptions"
                        placeholder="No refund — credit note only"
                        @update:model-value="(v) => (form.refund_account_id = v)"
                    />
                    <p v-if="form.errors.refund_account_id" class="mt-1 text-sm text-danger">{{ form.errors.refund_account_id }}</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="submit"
                    :disabled="form.processing || !selectedSaleId || !form.date"
                >
                    Post return
                </Button>
            </div>
        </form>
    </Card>
</template>
