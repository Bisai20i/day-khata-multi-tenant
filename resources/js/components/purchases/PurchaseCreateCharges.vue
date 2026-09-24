<script setup>
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { formatMoney } from '@/lib/money';

defineProps({
    form: { type: Object, required: true },
    tdsAccountOptions: { type: Array, default: () => [] },
    totals: { type: Object, default: null },
});

defineEmits(['toggle-header-discount-type']);

function money(value) {
    return value == null ? '-' : formatMoney(value);
}
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
            <label class="mb-1 block text-sm font-semibold text-text-base">Discount on whole bill</label>
            <div class="flex gap-2">
                <Input
                    v-model="form.discount"
                    type="number"
                    min="0"
                    :max="form.discount_type === 'percentage' ? 100 : undefined"
                    :placeholder="form.discount_type === 'percentage' ? '%' : '0.00'"
                />
                <button
                    type="button"
                    class="flex h-9 w-10 shrink-0 items-center justify-center border-[1.5px] border-border bg-bg-subtle text-[10px] font-bold text-text-muted hover:border-primary hover:text-primary"
                    title="Click to switch between % and Rs discount"
                    @click="$emit('toggle-header-discount-type')"
                >
                    {{ form.discount_type === 'percentage' ? '%' : 'Rs' }}
                </button>
            </div>
            <p class="mt-1 text-xs text-text-faint">Applied after item-level discounts. Use the button to switch between % and Rs.</p>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate (%)</label>
            <Input v-model="form.vat_rate" type="number" min="0" max="100" step="0.01" :disabled="form.force_non_taxable" />
            <p v-if="form.errors.vat_rate" class="mt-1 text-sm text-danger">{{ form.errors.vat_rate }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 sm:col-span-3">
            <input
                id="force_non_taxable"
                v-model="form.force_non_taxable"
                type="checkbox"
                class="size-4 border-[1.5px] border-border"
            />
            <label for="force_non_taxable" class="text-sm font-semibold text-text-base">
                PAN bill (no VAT)
            </label>
            <span class="text-xs text-text-faint">
                Every line is treated as exempt and no VAT is charged. Pre-ticked for a supplier who is
                not VAT registered.
            </span>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-text-base">TDS account (tax withheld)</label>
            <Combobox
                :model-value="form.tds_account_id"
                :options="tdsAccountOptions"
                placeholder="TDS Payable (default)"
                @update:model-value="(v) => (form.tds_account_id = v)"
            />
            <p v-if="form.errors.tds_account_id" class="mt-1 text-sm text-danger">{{ form.errors.tds_account_id }}</p>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-text-base">TDS rate (%)</label>
            <Input v-model="form.tds_rate" type="number" min="0" max="100" step="0.01" placeholder="Optional" />
            <p v-if="form.errors.tds_rate" class="mt-1 text-sm text-danger">{{ form.errors.tds_rate }}</p>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-text-base">TDS amount (Rs.)</label>
            <Input
                v-model="form.tds_amount"
                type="number"
                min="0"
                step="0.01"
                placeholder="0.00"
                :disabled="form.tds_rate !== ''"
            />
            <p v-if="form.tds_rate !== ''" class="mt-1 text-xs text-text-faint">
                Computed from the rate: {{ money(totals?.tds_amount) }}
            </p>
            <p v-if="form.errors.tds_amount" class="mt-1 text-sm text-danger">{{ form.errors.tds_amount }}</p>
        </div>
    </div>
</template>
