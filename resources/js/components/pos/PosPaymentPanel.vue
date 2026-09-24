<script setup>
import { ref } from 'vue';
import { CirclePause } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { addMoney, formatMoney } from '@/lib/money';

/**
 * Payment area, bottom half of the POS right-hand side: payment grid with
 * quick-pay buttons, advanced options, totals summary and the sticky
 * Hold / Save / Save & Print footer. Presentational only - `Sales/Pos.vue`
 * owns the form, totals and sale submission; this component reads them via
 * props and emits `quick-pay-*`, `toggle-header-discount-type`, `hold` and
 * `complete` (with 'save' or 'print'). The root uses `display: contents` so
 * the sticky footer still sticks inside the `.pos-right` scroll column.
 */
defineProps({
    form: { type: Object, required: true },
    totals: { type: Object, default: null },
    previewError: { type: String, default: null },
    settlementDue: { type: String, default: null },
    resolvedPaymentMode: { type: String, default: '' },
    paymentBalanced: { type: Boolean, default: false },
    showBankAccountField: { type: Boolean, default: false },
    hasDue: { type: Boolean, default: false },
    hasChange: { type: Boolean, default: false },
    dueAmount: { type: String, default: '0.00' },
    changeAmount: { type: String, default: '0.00' },
    bankAccountOptions: { type: Array, default: () => [] },
    tdsAccountOptions: { type: Array, default: () => [] },
    storeOptions: { type: Array, default: () => [] },
    submitBlockedReason: { type: String, default: '' },
    canSubmit: { type: Boolean, default: false },
});

const emit = defineEmits([
    'quick-pay-full-cash',
    'quick-pay-full-bank',
    'quick-pay-reset',
    'toggle-header-discount-type',
    'hold',
    'complete',
]);

const showAdvanced = ref(false);
</script>

<template>
    <div class="contents">
        <!-- Payment -->
        <Card variant="panel" class="-mt-3 shrink-0">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-1.5">
                <p class="text-xs font-bold tracking-[.8px] text-text-muted uppercase">Payment</p>
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Quick payment">
                    <Button variant="secondary" tone="blue" type="button" class="h-6 cursor-pointer !px-2 !text-[11px]" @click="emit('quick-pay-full-cash')">
                        Full Cash
                    </Button>
                    <Button variant="secondary" tone="purple" type="button" class="h-6 cursor-pointer !px-2 !text-[11px]" @click="emit('quick-pay-full-bank')">
                        Full Bank
                    </Button>
                    <Button variant="secondary" tone="danger" type="button" class="h-6 cursor-pointer !px-2 !text-[11px]" @click="emit('quick-pay-reset')">
                        Reset
                    </Button>
                </div>
            </div>

            <!-- Legacy's fixed 2x2 layout: Cash | Bank / Note | Bank account -->
            <div class="pos-pay-fields grid gap-2.5">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Cash Paid</label>
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" class="!h-8" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Bank / QR Paid</label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" class="!h-8" />
                </div>
                <div v-if="showBankAccountField" class="col-span-2">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank account</label>
                    <Combobox
                        :model-value="form.bank_account_id"
                        :options="bankAccountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (form.bank_account_id = v)"
                    />
                </div>
            </div>
            <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>

            <p v-if="resolvedPaymentMode === 'partial' && !paymentBalanced" class="mt-1 text-xs font-semibold text-danger">
                Cash + bank must add up to exactly {{ settlementDue ? formatMoney(settlementDue) : '-' }} for a split payment.
            </p>

            <button type="button" class="mt-3 h-9 cursor-pointer text-sm font-semibold text-primary focus-visible:outline-2 focus-visible:outline-primary" @click="showAdvanced = !showAdvanced">
                {{ showAdvanced ? 'Hide' : 'Show' }} more options
            </button>

            <!-- Fields legacy never had at all (store, header discount,
                 TDS, chalani number) stay tucked away here - Date moved
                 to the always-visible top bar since legacy shows it
                 there and it's required to submit. -->
            <div v-if="showAdvanced" class="mt-3 flex flex-col gap-3 border-t border-border pt-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                        <Combobox
                            :model-value="form.store_id"
                            :options="storeOptions"
                            placeholder="Default store"
                            @update:model-value="(v) => (form.store_id = v)"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-text-muted">TDS amount</label>
                        <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Header discount</label>
                        <div class="flex gap-1.5">
                            <Input
                                v-model="form.discount"
                                type="number"
                                min="0"
                                :max="form.discount_type === 'percent' ? 100 : undefined"
                                :placeholder="form.discount_type === 'percent' ? '%' : '0.00'"
                            />
                            <button
                                type="button"
                                class="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center border-[1.5px] border-border bg-bg-subtle text-xs font-bold text-text-muted hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                title="Click to switch between % and Rs discount"
                                :aria-label="`Discount type: ${form.discount_type === 'percent' ? 'percent' : 'rupees'}. Click to switch`"
                                @click="emit('toggle-header-discount-type')"
                            >
                                {{ form.discount_type === 'percent' ? '%' : 'Rs' }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-text-muted">TDS account</label>
                        <Combobox
                            :model-value="form.tds_account_id"
                            :options="tdsAccountOptions"
                            placeholder="Optional"
                            @update:model-value="(v) => (form.tds_account_id = v)"
                        />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Chalani number</label>
                        <Input v-model="form.chalani_number" type="text" placeholder="Optional" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Note</label>
                        <Input v-model="form.narration" type="text" placeholder="Optional" />
                    </div>
                </div>
            </div>
        </Card>

        <!-- Totals -->
        <Card v-if="totals || form.errors.lines || form.errors.expected_total" variant="panel" class="shrink-0">
            <dl v-if="totals" class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-[13px]" aria-live="polite">
                <dt class="text-text-muted">Subtotal</dt>
                <dd class="text-right font-medium text-text-strong tabular-nums">{{ formatMoney(addMoney(totals.vatable_subtotal, totals.non_vatable_subtotal)) }}</dd>
                <template v-if="totals.header_discount && totals.header_discount !== '0.00'">
                    <dt class="text-text-muted">Discount</dt>
                    <dd class="text-right font-medium text-text-strong tabular-nums">− {{ formatMoney(totals.header_discount) }}</dd>
                </template>
                <dt class="text-text-muted">Taxable amount</dt>
                <dd class="text-right font-medium text-text-strong tabular-nums">{{ formatMoney(totals.taxable_amount) }}</dd>
                <dt class="text-text-muted">Non-taxable amount</dt>
                <dd class="text-right font-medium text-text-strong tabular-nums">{{ formatMoney(totals.nontaxable_amount) }}</dd>
                <dt class="text-text-muted">VAT ({{ Number(totals.vat_rate) }}%)</dt>
                <dd class="text-right font-medium text-text-strong tabular-nums">{{ formatMoney(totals.vat_amount) }}</dd>
                <dt class="pos-grand mt-1 border-t border-border pt-2">Grand total</dt>
                <dd class="pos-grand-amt mt-1 border-t border-border pt-2 text-right tabular-nums">{{ formatMoney(totals.total) }}</dd>
            </dl>
            <p v-else-if="!previewError" class="text-sm text-text-faint">Totals appear once the cart has items.</p>
            <p v-if="form.errors.lines" class="mt-2 text-sm text-danger">{{ form.errors.lines }}</p>
            <p v-if="form.errors.expected_total" class="mt-2 text-sm text-danger">{{ form.errors.expected_total }}</p>
        </Card>

        <!-- Actions: legacy's Hold / Save / Save & Print row -->
        <div class="pos-footer sticky bottom-0 z-10 -mx-3 -mb-3 mt-3 flex shrink-0 flex-col gap-2 border-t border-border bg-white px-3 pt-3 pb-2 shadow-[0_-6px_10px_-6px_rgba(0,0,0,0.12)]">
            <div v-if="hasDue || hasChange" class="flex shrink-0 items-center justify-between px-3 py-1.5 text-sm font-bold" :class="hasDue ? 'bg-warning-bg text-warning-text' : 'bg-success-bg text-success'">
                <span>{{ hasDue ? 'Balance due' : 'Change to return' }}</span>
                <span>{{ formatMoney(hasDue ? dueAmount : changeAmount) }}</span>
            </div>
            <p v-if="submitBlockedReason" id="pos-submit-reason" class="sr-only" role="status">{{ submitBlockedReason }}</p>
            <div class="pos-actions grid shrink-0 gap-1.5">
                <Button variant="secondary" tone="purple" type="button" class="h-11 cursor-pointer justify-center" @click="emit('hold')">
                    <CirclePause class="h-4 w-4" /> Hold <kbd class="ml-1 font-mono text-[10px] opacity-70">F9</kbd>
                </Button>
                <Button
                    variant="secondary"
                    tone="blue"
                    type="button"
                    class="h-11 cursor-pointer justify-center"
                    :disabled="!canSubmit"
                    :loading="form.processing"
                    @click="emit('complete', 'save')"
                >
                    Save
                </Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="button"
                    class="h-11 cursor-pointer justify-center"
                    :disabled="!canSubmit"
                    :loading="form.processing"
                    :aria-describedby="submitBlockedReason ? 'pos-submit-reason' : undefined"
                    @click="emit('complete', 'print')"
                >
                    Save & Print <kbd class="ml-1 font-mono text-[10px] font-normal opacity-80">F8</kbd>
                </Button>
            </div>
            <p v-if="form.processing" class="shrink-0 text-center text-xs text-text-faint" role="status">Completing sale…</p>
        </div>
    </div>
</template>

<style scoped>
.pos-pay-fields {
    grid-template-columns: 1fr 1fr;
}

.pos-grand {
    font-size: 13px;
    font-weight: 800;
    color: var(--color-text-strong);
}

.pos-grand-amt {
    font-size: 16px;
    font-weight: 800;
    color: var(--color-primary);
}

.pos-actions {
    grid-template-columns: 1fr 1fr 1.3fr;
}
</style>
