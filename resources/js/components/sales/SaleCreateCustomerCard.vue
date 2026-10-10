<script setup>
import { computed } from 'vue';
import { Plus } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    form: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
});

defineEmits(['add-customer']);

// Quick-add posts to POST /customers, gated customers.create (ROUTE-MAP shared
// lookup item 3), so the "+" is hidden from a user who would get a 403.
const { can } = usePermissions();

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
</script>

<template>
    <Card variant="panel" class="!p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <Label>Customer <span class="text-danger" aria-hidden="true">*</span></Label>
                    <InfoTip text="Pick Walk-in customer for a counter sale." />
                </div>
                <Combobox
                    :model-value="form.customer_id"
                    :options="customerOptions"
                    placeholder="Select customer"
                    aria-describedby="sale-customer-help sale-customer-error"
                    @update:model-value="(v) => (form.customer_id = v)"
                >
                    <template v-if="can('customers.create')" #addon>
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
                <p id="sale-customer-help" class="sr-only">Pick Walk-in customer for a counter sale.</p>
                <p v-if="form.errors.customer_id" id="sale-customer-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.customer_id }}</p>
            </div>
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <Label for="sale-date">Sale date (BS) <span class="text-danger" aria-hidden="true">*</span></Label>
                    <InfoTip text="Bikram Sambat (Nepali) date of the bill." />
                </div>
                <NepaliDateInput v-model="form.date" required aria-describedby="sale-date-help sale-date-error" />
                <p id="sale-date-help" class="sr-only">Bikram Sambat (Nepali) date of the bill.</p>
                <p v-if="form.errors.date" id="sale-date-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.date }}</p>
            </div>
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <Label for="sale-chalani">Chalani (delivery challan) number</Label>
                    <InfoTip text="Only needed if this bill travels with a delivery challan." />
                </div>
                <Input id="sale-chalani" v-model="form.chalani_number" type="text" placeholder="Optional" aria-describedby="sale-chalani-help sale-chalani-error" />
                <p id="sale-chalani-help" class="sr-only">Only needed if this bill travels with a delivery challan.</p>
                <p v-if="form.errors.chalani_number" id="sale-chalani-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.chalani_number }}</p>
            </div>
        </div>
    </Card>
</template>
