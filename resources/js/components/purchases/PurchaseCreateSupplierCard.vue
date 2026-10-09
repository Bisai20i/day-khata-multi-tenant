<script setup>
import { computed } from 'vue';
import { Plus } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';

// Mirrors SaleCreateCustomerCard: who the bill is from, when, and the number
// printed on it. The PAN / non-VAT toggle sits here rather than behind
// "More options" because picking a supplier re-defaults it (see
// Purchases/Create.vue's selectSupplier()) - hiding a field that changes
// on its own would read as the VAT silently vanishing.
const props = defineProps({
    form: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    // POST /suppliers is gated suppliers.create (ROUTE-MAP shared lookup
    // item 3), so the quick-add button is hidden without it.
    canAddSupplier: { type: Boolean, default: true },
});

defineEmits(['select-supplier', 'add-supplier']);

const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name })));
</script>

<template>
    <Card variant="panel" title="Supplier & bill" class="!p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Supplier <span class="text-danger" aria-hidden="true">*</span></label>
                <Combobox
                    :model-value="form.supplier_id"
                    :options="supplierOptions"
                    placeholder="Select supplier"
                    aria-describedby="purchase-supplier-error"
                    @update:model-value="(v) => $emit('select-supplier', v)"
                >
                    <template v-if="canAddSupplier" #addon>
                        <button
                            type="button"
                            class="flex items-center justify-center text-text-muted hover:text-primary"
                            aria-label="Add new supplier"
                            title="Add new supplier"
                            @click="$emit('add-supplier')"
                        >
                            <Plus class="h-3.5 w-3.5" />
                        </button>
                    </template>
                </Combobox>
                <div class="mt-2 flex items-center gap-1">
                    <label class="flex cursor-pointer items-center gap-2 text-xs text-text-muted">
                        <input v-model="form.force_non_taxable" type="checkbox" class="size-4 border-[1.5px] border-border" />
                        <span class="font-semibold text-text-base">PAN bill (no VAT)</span>
                    </label>
                    <InfoTip text="Ticked for a supplier who is not VAT registered." />
                </div>
                <p v-if="form.errors.supplier_id" id="purchase-supplier-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.supplier_id }}</p>
            </div>
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <label for="purchase-date" class="block text-sm font-semibold text-text-base">Purchase date (BS) <span class="text-danger" aria-hidden="true">*</span></label>
                    <InfoTip text="Bikram Sambat date printed on the supplier's bill." />
                </div>
                <NepaliDateInput v-model="form.date" required aria-describedby="purchase-date-help purchase-date-error" />
                <p id="purchase-date-help" class="sr-only">Bikram Sambat date printed on the supplier's bill.</p>
                <p v-if="form.errors.date" id="purchase-date-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.date }}</p>
            </div>
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <label for="purchase-bill-number" class="block text-sm font-semibold text-text-base">Supplier bill number</label>
                    <InfoTip text="Optional. Helps you match this entry to the paper bill." />
                </div>
                <Input id="purchase-bill-number" v-model="form.bill_number" type="text" placeholder="Number printed on the bill" aria-describedby="purchase-bill-help purchase-bill-error" />
                <p id="purchase-bill-help" class="sr-only">Optional. Helps you match this entry to the paper bill.</p>
                <p v-if="form.errors.bill_number" id="purchase-bill-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.bill_number }}</p>
            </div>
        </div>
    </Card>
</template>
