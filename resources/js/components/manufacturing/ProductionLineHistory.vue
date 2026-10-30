<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Label from '@/components/ui/Label.vue';
import ProductionDialog from '@/components/manufacturing/ProductionDialog.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

/**
 * The completions and material returns of a production entry, with a Cancel
 * action on the one row of each kind that can be undone right now: the
 * latest live one (the server says which; it re-checks under the entry
 * lock). Earlier rows were costed from the WIP the later ones left, so only
 * the latest can be undone without re-costing anything.
 */
const props = defineProps({
    entry: { type: Object, required: true },
    // [{ voucher_id, date, item_name, unit, quantity, value, cancelled, cancellable }]
    materialReturns: { type: Array, default: () => [] },
    cancellableCompletionId: { type: Number, default: null },
});

const { can } = usePermissions();
const base = computed(() => `/production-entries/${props.entry.id}`);
const canCancelCompletion = computed(() => can('production_entries.cancel'));
const canCancelReturn = computed(() => can('production_entries.edit'));

// The row being cancelled: { url, title, description }, or null.
const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

function openCompletionCancel(completion) {
    open({
        url: `${base.value}/completions/${completion.id}/cancel`,
        title: completion.is_final ? 'Cancel final completion' : 'Cancel completion',
        description: completion.is_final
            ? 'The finished goods posting is reversed, the cost goes back into work in process and the entry is in progress again.'
            : 'The finished goods posting is reversed and the cost goes back into work in process.',
    });
}

function openReturnCancel(row) {
    open({
        url: `${base.value}/returns/${row.voucher_id}/cancel`,
        title: 'Cancel material return',
        description: `${formatQuantity(row.quantity)} ${row.unit ?? ''} of ${row.item_name} goes back into work in process and its return posting is reversed.`,
    });
}

function open(target) {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelling.value = target;
}

function submit() {
    cancelForm.post(cancelling.value.url, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <Card v-if="entry.completions.length" variant="panel" title="Completions" class="!p-4 overflow-x-auto">
            <table class="w-full min-w-[520px] text-sm">
                <thead>
                    <tr class="border-b border-border text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <th class="px-3 py-2">Date (BS)</th>
                        <th class="px-3 py-2">Kind</th>
                        <th class="px-3 py-2 text-right">Quantity</th>
                        <th class="px-3 py-2 text-right">Cost</th>
                        <th class="px-3 py-2 text-right">Unit cost</th>
                        <th class="px-3 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="completion in entry.completions" :key="completion.id" class="border-b border-border-soft" :class="{ 'text-text-faint line-through': completion.cancelled_at }">
                        <td class="whitespace-nowrap px-3 py-2">{{ formatBsDate(completion.date) }}</td>
                        <td class="px-3 py-2">{{ completion.is_final ? 'Final' : 'Partial' }}</td>
                        <td class="px-3 py-2 text-right">{{ formatQuantity(completion.quantity) }}</td>
                        <td class="px-3 py-2 text-right">{{ formatMoney(completion.cost) }}</td>
                        <td class="px-3 py-2 text-right">{{ formatRate(completion.unit_cost) }} / unit</td>
                        <td class="w-20 px-3 py-2 text-right no-underline">
                            <button
                                v-if="canCancelCompletion && completion.id === cancellableCompletionId"
                                type="button"
                                class="text-xs font-semibold text-danger hover:underline"
                                @click="openCompletionCancel(completion)"
                            >
                                Cancel
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Card v-if="materialReturns.length" variant="panel" title="Material returns" class="!p-4 overflow-x-auto">
            <table class="w-full min-w-[480px] text-sm">
                <thead>
                    <tr class="border-b border-border text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Material</th>
                        <th class="px-3 py-2 text-right">Quantity</th>
                        <th class="px-3 py-2 text-right">Value</th>
                        <th class="px-3 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in materialReturns" :key="row.voucher_id" class="border-b border-border-soft" :class="{ 'text-text-faint line-through': row.cancelled }">
                        <td class="px-3 py-2">{{ row.date }}</td>
                        <td class="px-3 py-2">{{ row.item_name }}</td>
                        <td class="px-3 py-2 text-right">{{ formatQuantity(row.quantity) }} {{ row.unit }}</td>
                        <td class="px-3 py-2 text-right">{{ formatMoney(row.value) }}</td>
                        <td class="w-20 px-3 py-2 text-right no-underline">
                            <button
                                v-if="canCancelReturn && row.cancellable"
                                type="button"
                                class="text-xs font-semibold text-danger hover:underline"
                                @click="openReturnCancel(row)"
                            >
                                Cancel
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <ProductionDialog
            :open="!!cancelling"
            :title="cancelling?.title ?? 'Cancel'"
            submit-label="Confirm cancellation"
            danger
            :processing="cancelForm.processing"
            :error="cancelForm.errors.reason ?? ''"
            @close="cancelling = null"
            @submit="submit"
        >
            <p class="text-sm text-text-muted">{{ cancelling?.description }} This cannot be undone.</p>
            <div>
                <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
                <Input v-model="cancelForm.reason" type="text" />
            </div>
        </ProductionDialog>
    </div>
</template>
