<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useConfirm } from '@/composables/useConfirm';
import { formatQuantity, formatRate } from '@/lib/money.js';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});

const { confirm } = useConfirm();

// --- Alternate units ("Box" = 12 "pcs", etc.) --------------------------
// Managed inline per item rather than a separate page, since an item's
// units only ever matter in the context of that one item (see ItemUnit's
// docblock). unitsItemId (not the item object itself) is what's tracked,
// so the list re-renders from the live `items` prop after every create/
// update/delete redirect instead of going stale.
const unitsModalOpen = ref(false);
const unitsItemId = ref(null);
const unitsItem = computed(() => props.items.find((item) => item.id === unitsItemId.value) ?? null);

const editingUnit = ref(null);
const unitForm = useForm({
    name: '',
    conversion_factor: '',
    purchase_rate: '',
    sale_rate: '',
    mrp: '',
    barcode: '',
    is_active: true,
});

unitForm.transform((data) => ({
    ...data,
    purchase_rate: data.purchase_rate === '' ? null : data.purchase_rate,
    sale_rate: data.sale_rate === '' ? null : data.sale_rate,
    mrp: data.mrp === '' ? null : data.mrp,
    barcode: data.barcode === '' ? null : data.barcode,
}));

function resetUnitForm() {
    editingUnit.value = null;
    unitForm.reset();
    unitForm.clearErrors();
}

function openUnits(item) {
    unitsItemId.value = item.id;
    resetUnitForm();
    unitsModalOpen.value = true;
}

function closeUnitsModal() {
    unitsModalOpen.value = false;
    unitsItemId.value = null;
    resetUnitForm();
}

function onUnitsModalOpenChange(value) {
    if (!value) closeUnitsModal();
}

function editUnit(unit) {
    editingUnit.value = unit;
    unitForm.clearErrors();
    unitForm.name = unit.name;
    unitForm.conversion_factor = unit.conversion_factor;
    unitForm.purchase_rate = unit.purchase_rate ?? '';
    unitForm.sale_rate = unit.sale_rate ?? '';
    unitForm.mrp = unit.mrp ?? '';
    unitForm.barcode = unit.barcode ?? '';
    unitForm.is_active = !!unit.is_active;
}

function submitUnit() {
    if (!unitsItem.value) return;

    if (editingUnit.value) {
        unitForm.put(`/items/${unitsItem.value.id}/units/${editingUnit.value.id}`, { onSuccess: resetUnitForm });
    } else {
        unitForm.post(`/items/${unitsItem.value.id}/units`, { onSuccess: resetUnitForm });
    }
}

async function destroyUnit(unit) {
    if (!unitsItem.value) return;
    if (!(await confirm({ message: `Delete the "${unit.name}" unit?`, tone: 'danger', confirmLabel: 'Delete unit' }))) return;
    router.delete(`/items/${unitsItem.value.id}/units/${unit.id}`, {
        onSuccess: () => {
            if (editingUnit.value?.id === unit.id) resetUnitForm();
        },
    });
}

defineExpose({ openUnits });
</script>

<template>
        <Modal
            :open="unitsModalOpen"
            :title="unitsItem ? `Units - ${unitsItem.name}` : 'Units'"
            @update:open="onUnitsModalOpenChange"
        >
            <div v-if="unitsItem" class="flex flex-col gap-4">
                <p class="text-[13px] text-text-muted">
                    Alternate units this item can be bought/sold in, alongside its base unit
                    (<strong>{{ unitsItem.unit }}</strong>). E.g. a "Box" with a conversion of 12 means
                    selling 1 Box moves 12 {{ unitsItem.unit }} out of stock. The base unit is always the
                    smallest one, so a conversion is never below 1: to sell in halves, make the half the base
                    unit instead.
                </p>

                <div v-if="unitsItem.units?.length" class="border-[1.5px] border-border">
                    <table class="w-full text-left text-[12px]">
                        <thead class="bg-bg-subtle">
                            <tr>
                                <th class="px-2 py-1.5">Name</th>
                                <th class="px-2 py-1.5">Equals</th>
                                <th class="px-2 py-1.5">Purchase rate</th>
                                <th class="px-2 py-1.5">Sale rate</th>
                                <th class="px-2 py-1.5">MRP</th>
                                <th class="px-2 py-1.5">Barcode</th>
                                <th class="px-2 py-1.5">Active</th>
                                <th class="px-2 py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="unit in unitsItem.units" :key="unit.id" class="border-t border-border">
                                <td class="px-2 py-1.5 font-semibold text-text-strong">{{ unit.name }}</td>
                                <td class="px-2 py-1.5">{{ formatQuantity(unit.conversion_factor) }} {{ unitsItem.unit }}</td>
                                <td class="px-2 py-1.5">{{ unit.purchase_rate != null ? formatRate(unit.purchase_rate) : '-' }}</td>
                                <td class="px-2 py-1.5">{{ unit.sale_rate != null ? formatRate(unit.sale_rate) : '-' }}</td>
                                <td class="px-2 py-1.5">{{ unit.mrp != null ? formatRate(unit.mrp) : '-' }}</td>
                                <td class="px-2 py-1.5">{{ unit.barcode ?? '-' }}</td>
                                <td class="px-2 py-1.5">
                                    <Badge :variant="unit.is_active ? 'success' : 'neutral'" pill>
                                        {{ unit.is_active ? 'Active' : 'Inactive' }}
                                    </Badge>
                                </td>
                                <td class="px-2 py-1.5">
                                    <RowActions @edit="editUnit(unit)" @delete="destroyUnit(unit)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-[13px] text-text-faint">No alternate units yet.</p>

                <form id="item-unit-form" class="grid grid-cols-2 gap-4 border-t-[1.5px] border-border pt-4" @submit.prevent="submitUnit">
                    <div>
                        <label for="unit_name" class="mb-1 block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                        <Input id="unit_name" v-model="unitForm.name" type="text" placeholder="e.g. Box" required />
                        <p v-if="unitForm.errors.name" class="mt-1 text-sm text-danger">{{ unitForm.errors.name }}</p>
                    </div>
                    <div>
                        <label for="unit_conversion_factor" class="mb-1 block text-sm font-semibold text-text-base">
                            Equals (in {{ unitsItem.unit }}) <span class="text-danger">*</span>
                        </label>
                        <Input id="unit_conversion_factor" v-model="unitForm.conversion_factor" type="number" min="1" step="0.0001" placeholder="e.g. 12" required />
                        <p v-if="unitForm.errors.conversion_factor" class="mt-1 text-sm text-danger">{{ unitForm.errors.conversion_factor }}</p>
                    </div>
                    <div>
                        <label for="unit_purchase_rate" class="mb-1 block text-sm font-semibold text-text-base">Purchase rate</label>
                        <Input id="unit_purchase_rate" v-model="unitForm.purchase_rate" type="number" step="0.01" min="0" placeholder="Defaults to item's rate" />
                        <p v-if="unitForm.errors.purchase_rate" class="mt-1 text-sm text-danger">{{ unitForm.errors.purchase_rate }}</p>
                    </div>
                    <div>
                        <label for="unit_sale_rate" class="mb-1 block text-sm font-semibold text-text-base">Sale rate</label>
                        <Input id="unit_sale_rate" v-model="unitForm.sale_rate" type="number" step="0.01" min="0" placeholder="Defaults to item's rate" />
                        <p v-if="unitForm.errors.sale_rate" class="mt-1 text-sm text-danger">{{ unitForm.errors.sale_rate }}</p>
                    </div>
                    <div>
                        <label for="unit_mrp" class="mb-1 block text-sm font-semibold text-text-base">MRP</label>
                        <Input id="unit_mrp" v-model="unitForm.mrp" type="number" step="0.01" min="0" placeholder="Optional" />
                        <p v-if="unitForm.errors.mrp" class="mt-1 text-sm text-danger">{{ unitForm.errors.mrp }}</p>
                    </div>
                    <div>
                        <label for="unit_barcode" class="mb-1 block text-sm font-semibold text-text-base">Barcode</label>
                        <Input id="unit_barcode" v-model="unitForm.barcode" type="text" placeholder="Scan or type a barcode for this unit" />
                        <p v-if="unitForm.errors.barcode" class="mt-1 text-sm text-danger">{{ unitForm.errors.barcode }}</p>
                    </div>
                    <div class="flex items-end gap-2 pb-2.5">
                        <input id="unit_is_active" v-model="unitForm.is_active" type="checkbox" class="size-4 border-[1.5px] border-border" />
                        <label for="unit_is_active" class="text-sm font-semibold text-text-base">Active</label>
                    </div>
                </form>
            </div>

            <template #footer>
                <Button v-if="editingUnit" variant="secondary" tone="purple" type="button" @click="resetUnitForm">Cancel edit</Button>
                <Button variant="secondary" tone="purple" type="button" @click="closeUnitsModal">Close</Button>
                <Button variant="primary" tone="purple" type="submit" form="item-unit-form" :disabled="unitForm.processing">
                    {{ editingUnit ? 'Save unit' : 'Add unit' }}
                </Button>
            </template>
        </Modal>
</template>
