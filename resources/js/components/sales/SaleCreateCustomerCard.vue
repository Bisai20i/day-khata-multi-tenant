<script setup>
import { computed } from 'vue';
import { Plus } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';

const props = defineProps({
    form: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
});

defineEmits(['add-customer']);

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
</script>

<template>
    <Card variant="panel" title="Customer & date" class="!p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Customer <span class="text-danger" aria-hidden="true">*</span></label>
                <Combobox
                    :model-value="form.customer_id"
                    :options="customerOptions"
                    placeholder="Select customer"
                    aria-describedby="sale-customer-help sale-customer-error"
                    @update:model-value="(v) => (form.customer_id = v)"
                >
                    <template #addon>
                        <button
                            type="button"
                            class="flex items-center justify-center text-text-muted hover:text-primary"
                            aria-label="Add new customer"
                            title="Add new customer"
                            @click="$emit('add-customer')"
                        >
                            <Plus class="h-3.5 w-3.5" />
                        </button>
                    </template>
                </Combobox>
                <p id="sale-customer-help" class="mt-1 text-xs text-text-muted">Pick Walk-in customer for a counter sale.</p>
                <p v-if="form.errors.customer_id" id="sale-customer-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.customer_id }}</p>
            </div>
            <div>
                <label for="sale-date" class="mb-1 block text-sm font-semibold text-text-base">Sale date (BS) <span class="text-danger" aria-hidden="true">*</span></label>
                <NepaliDateInput v-model="form.date" required aria-describedby="sale-date-help sale-date-error" />
                <p id="sale-date-help" class="mt-1 text-xs text-text-muted">Bikram Sambat (Nepali) date of the bill.</p>
                <p v-if="form.errors.date" id="sale-date-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.date }}</p>
            </div>
            <div>
                <label for="sale-chalani" class="mb-1 block text-sm font-semibold text-text-base">Chalani (delivery challan) number</label>
                <Input id="sale-chalani" v-model="form.chalani_number" type="text" placeholder="Optional" aria-describedby="sale-chalani-help sale-chalani-error" />
                <p id="sale-chalani-help" class="mt-1 text-xs text-text-muted">Only needed if this bill travels with a delivery challan.</p>
                <p v-if="form.errors.chalani_number" id="sale-chalani-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.chalani_number }}</p>
            </div>
        </div>
    </Card>
</template>
