<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Ban, Printer } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DetailField from '@/components/manufacturing/DetailField.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    note: { type: Object, required: true },
});

const page = usePage();
const { toast } = useToast();
const { can } = usePermissions();
useLayoutChrome('Material Issue');

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const isCancelled = computed(() => props.note.status === 'cancelled');
// Only an unattached note can be cancelled here; the server enforces it too.
const canCancel = computed(() => !isCancelled.value && !props.note.production_entry_id && can('material_issues.cancel'));

const cancelling = ref(false);
const cancelForm = useForm({ reason: '' });

function submitCancel() {
    cancelForm.post(`/material-issues/${props.note.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = false;
            cancelForm.reset();
        },
    });
}
</script>

<template>
    <div>
        <PageHeader
            :title="`Material Issue MI-${note.number}`"
            description="Raw material issued from a store into work in process."
            back-href="/material-issues"
            back-label="Material Issues"
        >
            <template #meta>
                <Badge pill :variant="isCancelled ? 'danger' : 'success'">{{ isCancelled ? 'Cancelled' : 'Posted' }}</Badge>
            </template>
            <a v-if="can('material_issues.print')" :href="`/material-issues/${note.id}/print`" target="_blank" rel="noopener">
                <Button variant="secondary" tone="purple" type="button"><Printer class="size-4" /> Print</Button>
            </a>
            <Button v-if="canCancel" variant="secondary" tone="danger" type="button" @click="cancelling = true">
                <Ban class="size-4" /> Cancel note
            </Button>
        </PageHeader>

        <p v-if="cancelForm.errors.reason && !cancelling" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
            {{ cancelForm.errors.reason }}
        </p>

        <Card variant="panel" class="mb-4 !p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <DetailField label="Date (BS)">
                    {{ formatBsDate(note.date) }}
                    <span class="text-text-faint">({{ String(note.date ?? '').slice(0, 10) }})</span>
                </DetailField>
                <DetailField label="Store" :value="note.store?.name" />
                <DetailField label="Production entry" :value="note.production_entry ? `PE-${note.production_entry.number}` : 'Unattached'" />
                <DetailField v-if="note.narration" label="Narration" :value="note.narration" class="sm:col-span-3" />
                <DetailField v-if="isCancelled" label="Cancellation reason" class="sm:col-span-3">
                    <span class="text-danger">{{ note.cancel_reason }}</span>
                </DetailField>
            </div>
        </Card>

        <Card variant="panel" title="Raw materials issued" class="!p-4 overflow-x-auto">
            <table class="w-full min-w-[560px] text-sm">
                <thead>
                    <tr class="text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <th class="py-2">Item</th>
                        <th class="py-2 text-right">Quantity</th>
                        <th class="py-2 text-right">Base qty</th>
                        <th class="py-2 text-right">Unit cost</th>
                        <th class="py-2 text-right">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in note.items" :key="line.id" class="border-t border-border">
                        <td class="py-2">{{ line.item?.name }}</td>
                        <td class="py-2 text-right">{{ formatQuantity(line.quantity) }} {{ line.item_unit?.name ?? line.item?.unit ?? '' }}</td>
                        <td class="py-2 text-right">{{ formatQuantity(line.base_quantity) }} {{ line.item?.unit ?? '' }}</td>
                        <td class="py-2 text-right">{{ formatRate(line.unit_cost_rate) }}</td>
                        <td class="py-2 text-right">{{ formatMoney(line.line_cost) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-border font-bold text-text-strong">
                        <td class="py-2" colspan="4">Total cost</td>
                        <td class="py-2 text-right">{{ formatMoney(note.total_cost) }}</td>
                    </tr>
                </tfoot>
            </table>
        </Card>

        <Modal :open="cancelling" title="Cancel material issue note" size="compact" @update:open="(v) => (cancelling = v)">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This returns the materials to stock and reverses the ledger entry. This cannot be undone.
                </p>
                <div>
                    <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
                    <Input v-model="cancelForm.reason" type="text" placeholder="Reason for cancellation" required />
                    <p v-if="cancelForm.errors.reason" class="mt-1 text-sm text-danger">{{ cancelForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="neutral" type="button" @click="cancelling = false">Back</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="cancelForm.processing" @click="submitCancel">
                    Confirm cancellation
                </Button>
            </template>
        </Modal>
    </div>
</template>
