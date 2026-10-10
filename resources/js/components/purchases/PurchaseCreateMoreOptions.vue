<script setup>
import { computed } from 'vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney } from '@/lib/money';

// Rare fields behind progressive disclosure, toggled from the sticky bar in
// PurchaseCreateTotalsBar - same pattern as SaleCreateMoreOptions.
const props = defineProps({
    form: { type: Object, required: true },
    stores: { type: Array, default: () => [] },
    // TDS can only be withheld into a liability, so the server sends this
    // list already narrowed to the Liabilities head.
    tdsAccounts: { type: Array, default: () => [] },
    totals: { type: Object, default: null },
});

defineEmits(['toggle-discount-type']);

const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const tdsAccountOptions = computed(() =>
    props.tdsAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })),
);
</script>

<template>
    <div class="flex flex-col gap-4 border-[1.5px] border-border bg-bg-subtle p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div v-if="storeOptions.length > 1">
                <Label class="mb-1">Store (stock is received here)</Label>
                <Combobox
                    :model-value="form.store_id"
                    :options="storeOptions"
                    placeholder="Default store"
                    @update:model-value="(v) => (form.store_id = v)"
                />
                <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
            </div>
            <div>
                <Label for="purchase-pan-number" class="mb-1">Supplier PAN number</Label>
                <Input id="purchase-pan-number" v-model="form.pan_number" type="text" placeholder="Optional" />
                <p v-if="form.errors.pan_number" class="mt-1 text-sm text-danger">{{ form.errors.pan_number }}</p>
            </div>
            <div>
                <Label for="purchase-chalani" class="mb-1">Chalani (dispatch) number</Label>
                <Input id="purchase-chalani" v-model="form.chalani_number" type="text" placeholder="Optional" />
                <p v-if="form.errors.chalani_number" class="mt-1 text-sm text-danger">{{ form.errors.chalani_number }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <Label>Discount on whole bill</Label>
                    <InfoTip text="Applied after item discounts. Use % or Rs with the button." />
                </div>
                <Input
                    v-model="form.discount"
                    type="number"
                    min="0"
                    step="0.01"
                    :max="form.discount_type === 'percentage' ? 100 : undefined"
                    :placeholder="form.discount_type === 'percentage' ? '%' : '0.00'"
                >
                    <template #addon>
                        <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center text-[10px] font-bold text-text-muted hover:text-primary"
                            title="Click to switch between % and Rs discount"
                            aria-label="Bill discount type: switch between percent and rupees"
                            @click="$emit('toggle-discount-type')"
                        >
                            {{ form.discount_type === 'percentage' ? '%' : 'Rs' }}
                        </button>
                    </template>
                </Input>
                <p v-if="form.errors.discount" class="mt-1 text-sm text-danger">{{ form.errors.discount }}</p>
            </div>
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <Label for="purchase-vat-rate">VAT rate (%)</Label>
                    <InfoTip :text="form.force_non_taxable ? 'Not used on a PAN bill.' : 'Charged on the taxable items.'" />
                </div>
                <Input id="purchase-vat-rate" v-model="form.vat_rate" type="number" min="0" max="100" step="0.01" :disabled="form.force_non_taxable" />
                <p v-if="form.errors.vat_rate" class="mt-1 text-sm text-danger">{{ form.errors.vat_rate }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <Label>TDS account (tax withheld)</Label>
                    <InfoTip text="TDS = tax you deduct from the supplier and pay to the government." />
                </div>
                <Combobox
                    :model-value="form.tds_account_id"
                    :options="tdsAccountOptions"
                    placeholder="TDS Payable (default)"
                    @update:model-value="(v) => (form.tds_account_id = v)"
                />
                <p v-if="form.errors.tds_account_id" class="mt-1 text-sm text-danger">{{ form.errors.tds_account_id }}</p>
            </div>
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <Label for="purchase-tds-rate">TDS rate (%)</Label>
                    <InfoTip text="A rate takes precedence over a typed amount." />
                </div>
                <Input id="purchase-tds-rate" v-model="form.tds_rate" type="number" min="0" max="100" step="0.01" placeholder="Optional" />
                <p v-if="form.errors.tds_rate" class="mt-1 text-sm text-danger">{{ form.errors.tds_rate }}</p>
            </div>
            <div>
                <div class="mb-1 flex items-center gap-1">
                    <Label for="purchase-tds-amount">TDS amount (Rs)</Label>
                    <InfoTip v-if="form.tds_rate === ''" text="Or type the amount withheld." />
                </div>
                <Input id="purchase-tds-amount" v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" :disabled="form.tds_rate !== ''" />
                <p v-if="form.tds_rate !== ''" class="mt-1 text-xs text-text-muted">
                    Computed from the rate: {{ totals ? formatMoney(totals.tds_amount) : '-' }}
                </p>
                <p v-if="form.errors.tds_amount" class="mt-1 text-sm text-danger">{{ form.errors.tds_amount }}</p>
            </div>
        </div>
    </div>
</template>
