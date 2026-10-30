<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import DetailField from '@/components/manufacturing/DetailField.vue';
import Input from '@/components/ui/Input.vue';
import Label from '@/components/ui/Label.vue';
import ProductionStatusBadge from '@/components/manufacturing/ProductionStatusBadge.vue';
import ProductionCostSheet from '@/components/manufacturing/ProductionCostSheet.vue';
import ProductionActions from '@/components/manufacturing/ProductionActions.vue';
import ProductionDialog from '@/components/manufacturing/ProductionDialog.vue';
import ProductionLineHistory from '@/components/manufacturing/ProductionLineHistory.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, formatQuantity } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    entry: { type: Object, required: true },
    costSheet: { type: Object, required: true },
    timeline: { type: Array, default: () => [] },
    availableNotes: { type: Array, default: () => [] },
    creditAccounts: { type: Array, default: () => [] },
    costTypes: { type: Array, default: () => [] },
    materialReturns: { type: Array, default: () => [] },
    cancellableCompletionId: { type: Number, default: null },
    correctionFiscalYear: { type: Object, default: null },
});

const { can } = usePermissions();
const page = usePage();
const { toast } = useToast();
useLayoutChrome('Production entry');

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const title = computed(() => `Production entry PE-${props.entry.number}`);

// Cancelling one direct cost: the table owns the trigger, this page the dialog.
const cancellingCost = ref(null);
const costCancelForm = useForm({ reason: '' });

function openCostCancel(cost) {
    costCancelForm.reset();
    costCancelForm.clearErrors();
    cancellingCost.value = cost;
}

function submitCostCancel() {
    costCancelForm.post(`/production-entries/${props.entry.id}/direct-costs/${cancellingCost.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancellingCost.value = null;
        },
    });
}
</script>

<template>
    <div>
        <PageHeader :title="title" :description="entry.narration || ''" back-href="/production-entries" back-label="Production">
            <template #meta>
                <ProductionStatusBadge :status="entry.status" />
            </template>
            <a v-if="can('production_entries.print')" :href="`/production-entries/${entry.id}/print`" target="_blank" rel="noopener">
                <Button variant="secondary" tone="purple" type="button"><Printer class="size-4" /> Print cost sheet</Button>
            </a>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Card variant="panel" class="!p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <DetailField label="Date (BS)">
                        {{ formatBsDate(entry.date) }}
                        <span class="text-text-faint">({{ String(entry.date ?? '').slice(0, 10) }})</span>
                    </DetailField>
                    <DetailField label="Store" :value="entry.store?.name" />
                    <DetailField label="Finished good" :value="entry.finished_item?.name" />
                    <DetailField label="Planned quantity" :value="`${formatQuantity(entry.planned_quantity)} ${entry.finished_item?.unit ?? ''}`.trim()" />
                </div>
            </Card>

            <p v-if="entry.cancel_reason" class="border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-sm text-warning-text" role="status">Reason: {{ entry.cancel_reason }}</p>

            <ProductionActions
                :entry="entry"
                :cost-sheet="costSheet"
                :available-notes="availableNotes"
                :credit-accounts="creditAccounts"
                :cost-types="costTypes"
                :correction-fiscal-year="correctionFiscalYear"
            />

            <ProductionCostSheet :sheet="costSheet" :unit="entry.finished_item?.unit ?? ''" />

            <Card variant="panel" title="Direct costs" class="!p-4 overflow-x-auto">
                <p v-if="entry.direct_costs.length === 0" class="py-4 text-center text-sm text-text-faint">None recorded.</p>
                <table v-else class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                            <th class="px-3 py-2">Date (BS)</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Description</th>
                            <th class="px-3 py-2">Paid from / payable</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-3 py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="cost in entry.direct_costs" :key="cost.id" class="border-b border-border-soft" :class="{ 'text-text-faint line-through': cost.cancelled_at }">
                            <td class="whitespace-nowrap px-3 py-2">{{ formatBsDate(cost.date) }}</td>
                            <td class="px-3 py-2 capitalize">{{ cost.cost_type }}</td>
                            <td class="px-3 py-2">{{ cost.description }}</td>
                            <td class="px-3 py-2">{{ cost.credit_account?.name }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(cost.amount) }}</td>
                            <td class="px-3 py-2 text-right no-underline">
                                <button
                                    v-if="!cost.cancelled_at && entry.status === 'in_progress' && can('production_entries.edit')"
                                    type="button"
                                    class="text-xs font-semibold text-danger hover:underline"
                                    @click="openCostCancel(cost)"
                                >
                                    Cancel
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>

            <ProductionLineHistory :entry="entry" :material-returns="materialReturns" :cancellable-completion-id="cancellableCompletionId" />

            <Card variant="panel" title="Timeline" class="!p-4">
                <ol class="flex flex-col gap-2 text-sm">
                    <li v-for="(event, index) in timeline" :key="index" class="flex gap-3" :class="{ 'text-text-faint line-through': event.cancelled }">
                        <span class="w-24 shrink-0 text-text-muted">{{ event.date }}</span>
                        <span class="flex-1">{{ event.label }}</span>
                        <span v-if="event.amount" class="text-right">{{ formatMoney(event.amount) }}</span>
                    </li>
                </ol>
            </Card>
        </div>

        <ProductionDialog
            :open="!!cancellingCost"
            title="Cancel direct cost"
            submit-label="Confirm cancellation"
            danger
            :processing="costCancelForm.processing"
            :error="costCancelForm.errors.reason ?? ''"
            @close="cancellingCost = null"
            @submit="submitCostCancel"
        >
            <p class="text-sm text-text-muted">The cost posting is reversed. This cannot be undone.</p>
            <div>
                <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
                <Input v-model="costCancelForm.reason" type="text" />
            </div>
        </ProductionDialog>
    </div>
</template>
