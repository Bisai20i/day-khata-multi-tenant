<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';

const props = defineProps({
    purchases: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const purchaseOptions = computed(() =>
    props.purchases.map((purchase) => ({
        value: purchase.id,
        label: `#${purchase.id} — ${purchase.supplier?.name ?? '—'} (${Number(purchase.total).toFixed(2)})`,
    })),
);

const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} — ${account.name}` : account.name,
    })),
);

function emptyLineFor(purchaseLine) {
    return {
        purchase_line_id: purchaseLine.id,
        item_name: purchaseLine.item?.name ?? '—',
        unit: purchaseLine.item?.unit ?? '',
        original_quantity: purchaseLine.quantity,
        quantity: '',
    };
}

const form = useForm({
    purchase_id: null,
    date: '',
    reason: '',
    refund_account_id: null,
    lines: [],
});

const selectedPurchase = computed(() => props.purchases.find((purchase) => purchase.id === form.purchase_id) ?? null);

watch(
    () => form.purchase_id,
    () => {
        form.lines = selectedPurchase.value ? selectedPurchase.value.lines.map(emptyLineFor) : [];
    },
);

function submit() {
    form.transform((data) => ({
        purchase_id: data.purchase_id,
        date: data.date,
        reason: data.reason || null,
        refund_account_id: data.refund_account_id || null,
        lines: data.lines
            .filter((line) => Number(line.quantity) > 0)
            .map((line) => ({
                purchase_line_id: line.purchase_line_id,
                quantity: Number(line.quantity),
            })),
    })).post('/purchase-returns', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New purchase return</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-4 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Purchase</label>
                    <Combobox
                        :model-value="form.purchase_id"
                        :options="purchaseOptions"
                        placeholder="Select the original purchase"
                        @update:model-value="(v) => (form.purchase_id = v)"
                    />
                    <p v-if="form.errors.purchase_id" class="mt-1 text-sm text-danger">{{ form.errors.purchase_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date</label>
                    <Input v-model="form.date" type="date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <Input v-model="form.reason" type="text" placeholder="Optional" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Refund via</label>
                    <Combobox
                        :model-value="form.refund_account_id"
                        :options="accountOptions"
                        placeholder="No refund (credit note only)"
                        @update:model-value="(v) => (form.refund_account_id = v)"
                    />
                    <p v-if="form.errors.refund_account_id" class="mt-1 text-sm text-danger">{{ form.errors.refund_account_id }}</p>
                </div>
            </div>

            <div v-if="selectedPurchase">
                <div class="mb-2 grid grid-cols-[1fr_110px_140px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Purchased Qty</span>
                    <span>Return Qty</span>
                </div>

                <div v-for="(line, index) in form.lines" :key="line.purchase_line_id" class="mb-2 grid grid-cols-[1fr_110px_140px] items-start gap-2">
                    <span class="pt-2 text-sm text-text-strong">{{ line.item_name }} <span class="text-text-muted">({{ line.unit }})</span></span>
                    <span class="pt-2 text-sm text-text-muted">{{ Number(line.original_quantity).toFixed(2) }}</span>
                    <div>
                        <Input v-model="form.lines[index].quantity" type="number" min="0" step="0.0001" placeholder="0" />
                        <p v-if="form.errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.quantity`] }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !selectedPurchase">
                    Post return
                </Button>
            </div>
        </form>
    </Card>
</template>
