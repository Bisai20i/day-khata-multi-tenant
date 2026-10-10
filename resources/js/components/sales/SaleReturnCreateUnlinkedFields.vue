<script setup>
import { X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney } from '@/lib/money';

defineProps({
    form: { type: Object, required: true },
    invoiceSettings: { type: Object, required: true },
    customerOptions: { type: Array, default: () => [] },
    storeOptions: { type: Array, default: () => [] },
    itemOptions: { type: Array, default: () => [] },
    unlinkedLines: { type: Array, required: true },
    unlinkedTotals: { type: Object, default: null },
    unitOptionsFor: { type: Function, required: true },
});

defineEmits(['add-line', 'remove-line', 'item-picked']);
</script>

<template>
    <!-- Unlinked: no invoice to pick, no returnable quantities to cap
         against. The lines name an item and a rate directly and are
         taxed at the company VAT rate, exactly like a fresh sale of
         the same goods (SalesReturn::postUnlinked()). -->
    <div class="flex flex-col gap-4">
        <Card variant="panel" class="flex flex-col gap-4 !p-4">
        <p class="text-sm text-text-muted">
            Use this for goods returned against a bill this system never issued: a pre-cutover sale, a walk-in
            who lost their receipt, or a paper invoice from before you went live. It posts a credit note
            straight away at the company VAT rate of {{ invoiceSettings.default_vat_rate }}%.
        </p>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <Label class="mb-1">Customer <span class="text-danger">*</span></Label>
                <Combobox
                    :model-value="form.customer_id"
                    :options="customerOptions"
                    placeholder="Who is being credited"
                    @update:model-value="(value) => (form.customer_id = value)"
                />
                <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
            </div>
            <div>
                <Label class="mb-1">Return date <span class="text-danger">*</span></Label>
                <NepaliDateInput v-model="form.date" required />
                <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
            </div>
            <div>
                <Label class="mb-1">Store</Label>
                <Combobox
                    :model-value="form.store_id"
                    :options="storeOptions"
                    placeholder="Default store"
                    @update:model-value="(value) => (form.store_id = value)"
                />
                <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
            </div>
        </div>

        <div>
            <Label class="mb-1">Reason</Label>
            <Input v-model="form.reason" type="text" placeholder="Optional" />
            <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">{{ form.errors.reason }}</p>
        </div>
        </Card>

        <Card variant="panel" title="Items" class="!p-4">
            <div class="mb-2 grid grid-cols-[1fr_140px_100px_100px_110px_40px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                <span>Item</span>
                <span>Unit</span>
                <span>Quantity</span>
                <span>Bonus qty</span>
                <span>Rate</span>
                <span></span>
            </div>

            <div
                v-for="(line, index) in unlinkedLines"
                :key="index"
                class="mb-2 grid grid-cols-[1fr_140px_100px_100px_110px_40px] items-center gap-2"
            >
                <Combobox
                    :model-value="line.item_id"
                    :options="itemOptions"
                    placeholder="Pick an item"
                    @update:model-value="(value) => $emit('item-picked', line, value)"
                />
                <Combobox
                    :model-value="line.item_unit_id"
                    :options="unitOptionsFor(line)"
                    placeholder="Base unit"
                    @update:model-value="(value) => (line.item_unit_id = value)"
                />
                <Input v-model="line.quantity" type="text" inputmode="decimal" placeholder="0" />
                <Input v-model="line.bonus_quantity" type="text" inputmode="decimal" placeholder="0" />
                <Input v-model="line.rate" type="text" inputmode="decimal" placeholder="0.00" />
                <Button variant="secondary" tone="danger" type="button" @click="$emit('remove-line', index)">
                    <X class="size-4" />
                </Button>
            </div>

            <Button variant="secondary" tone="purple" type="button" @click="$emit('add-line')">Add line</Button>
            <p v-if="form.errors.lines" class="mt-1 text-sm text-danger">{{ form.errors.lines }}</p>
        </Card>

        <div v-if="unlinkedTotals?.error" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ unlinkedTotals.error }}
        </div>

        <Card v-else-if="unlinkedTotals" variant="panel" class="grid grid-cols-4 gap-3 !p-4 text-sm">
            <div>
                <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Taxable</p>
                <p class="font-bold text-text-strong">{{ formatMoney(unlinkedTotals.taxable_amount) }}</p>
            </div>
            <div>
                <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Non-taxable</p>
                <p class="font-bold text-text-strong">{{ formatMoney(unlinkedTotals.nontaxable_amount) }}</p>
            </div>
            <div>
                <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">VAT</p>
                <p class="font-bold text-text-strong">{{ formatMoney(unlinkedTotals.vat_amount) }}</p>
            </div>
            <div>
                <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Credit note total</p>
                <p class="font-bold text-text-strong">{{ formatMoney(unlinkedTotals.total) }}</p>
            </div>
        </Card>
    </div>
</template>
