<script setup>
import { computed } from 'vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';

// Rare fields (Store, header discount, TDS, agent) behind progressive
// disclosure; toggled from the sticky bar in SaleCreateTotalsBar.
const props = defineProps({
    form: { type: Object, required: true },
    stores: { type: Array, default: () => [] },
    tdsAccounts: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
});

defineEmits(['toggle-discount-type', 'select-agent']);

const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const tdsAccountOptions = computed(() =>
    props.tdsAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })),
);
const agentOptions = computed(() => props.agents.map((a) => ({ value: a.id, label: a.name })));
</script>

<template>
    <div class="flex flex-col gap-4 border-[1.5px] border-border bg-bg-subtle p-4">
        <div class="grid grid-cols-2 gap-4">
            <div v-if="storeOptions.length > 1">
                <label class="mb-1 block text-sm font-semibold text-text-base">Store (stock is taken from here)</label>
                <Combobox
                    :model-value="form.store_id"
                    :options="storeOptions"
                    placeholder="Default store"
                    @update:model-value="(v) => (form.store_id = v)"
                />
                <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Discount on whole bill</label>
                <p class="mb-1 text-xs text-text-muted">Applied to the subtotal. Use % or Rs with the button.</p>
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
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">TDS account (optional)</label>
                <p class="mb-1 text-xs text-text-muted">TDS = tax the customer deducts at source and pays to the government.</p>
                <Combobox
                    :model-value="form.tds_account_id"
                    :options="tdsAccountOptions"
                    placeholder="Select TDS account"
                    @update:model-value="(v) => (form.tds_account_id = v)"
                />
                <p v-if="form.errors.tds_account_id" class="mt-1 text-sm text-danger">{{ form.errors.tds_account_id }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">TDS amount</label>
                <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                <p v-if="form.errors.tds_amount" class="mt-1 text-sm text-danger">{{ form.errors.tds_amount }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Sales agent (optional)</label>
                <p class="mb-1 text-xs text-text-muted">Agent who brought this sale; earns the commission below.</p>
                <Combobox
                    :model-value="form.agent_id"
                    :options="agentOptions"
                    placeholder="Select agent"
                    @update:model-value="(v) => $emit('select-agent', v)"
                />
                <p v-if="form.errors.agent_id" class="mt-1 text-sm text-danger">{{ form.errors.agent_id }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Commission amount</label>
                <Input v-model="form.commission_amount" type="number" min="0" step="0.01" placeholder="0.00" :disabled="!form.agent_id" />
                <p v-if="form.errors.commission_amount" class="mt-1 text-sm text-danger">{{ form.errors.commission_amount }}</p>
            </div>
        </div>
    </div>
</template>
