<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import ProductionDialog from '@/components/manufacturing/ProductionDialog.vue';
import IssueNoteLines from '@/components/manufacturing/IssueNoteLines.vue';
import CorrectionReasonField from '@/components/manufacturing/CorrectionReasonField.vue';
import { isInCorrectionYear } from '@/components/manufacturing/correctionYear.js';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, formatQuantity } from '@/lib/money.js';
import { todayInKathmandu } from '@/lib/format.js';

/**
 * The action buttons and dialogs of a production entry: attach notes, add a
 * direct cost, return material, complete (partial/final), abandon, cancel.
 * Every dialog posts to the entry's URL and shows the server's refusal
 * inside itself; success closes the dialog and the page reloads from the
 * redirect. A dated posting (cost, return, output) whose date falls in the
 * year reopened for correction also asks for the correction reason.
 */
const props = defineProps({
    entry: { type: Object, required: true },
    costSheet: { type: Object, required: true },
    availableNotes: { type: Array, default: () => [] },
    creditAccounts: { type: Array, default: () => [] },
    costTypes: { type: Array, default: () => [] },
    // The closed year reopened for correction, { id, name, start_date, end_date }, or null.
    correctionFiscalYear: { type: Object, default: null },
});

const { can } = usePermissions();
const base = computed(() => `/production-entries/${props.entry.id}`);
const inProgress = computed(() => props.entry.status === 'in_progress');
const canEdit = computed(() => inProgress.value && can('production_entries.edit'));
const canCancel = computed(() => can('production_entries.cancel'));
const canCancelEntry = computed(() => canCancel.value && inProgress.value);

const open = ref(null);

const attachForm = useForm({ material_issue_note_ids: [] });
const costForm = useForm({ date: todayInKathmandu(), cost_type: 'labor', description: '', amount: '', credit_account_id: null, reason: '' });
const returnForm = useForm({ item_id: null, quantity: '', date: todayInKathmandu(), reason: '' });
const completeForm = useForm({ date: todayInKathmandu(), quantity: '', is_final: false, reason: '' });
const abandonForm = useForm({ reason: '' });
const cancelForm = useForm({ reason: '' });

const forms = { attach: attachForm, cost: costForm, return: returnForm, complete: completeForm, abandon: abandonForm, cancel: cancelForm };
const urls = {
    attach: 'attach-notes',
    cost: 'direct-costs',
    return: 'return-material',
    complete: 'complete',
    abandon: 'abandon',
    cancel: 'cancel',
};

function firstError(name) {
    const errors = Object.values(forms[name].errors);
    return errors[0] ?? '';
}

function show(name) {
    forms[name].clearErrors();
    open.value = name;
}

function close() {
    open.value = null;
}

// The dated postings that may land in the year reopened for correction.
const datedForms = ['cost', 'return', 'complete'];

function needsReason(name) {
    return datedForms.includes(name) && isInCorrectionYear(forms[name].date, props.correctionFiscalYear);
}

function submit(name) {
    const form = datedForms.includes(name)
        ? forms[name].transform((data) => ({ ...data, reason: needsReason(name) ? data.reason : null }))
        : forms[name];

    form.post(`${base.value}/${urls[name]}`, {
        preserveScroll: true,
        onSuccess: () => {
            forms[name].reset();
            close();
        },
    });
}

function toggleNote(id) {
    const ids = attachForm.material_issue_note_ids;
    attachForm.material_issue_note_ids = ids.includes(id) ? ids.filter((n) => n !== id) : [...ids, id];
}

const accountOptions = computed(() => props.creditAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })));
const materialOptions = computed(() =>
    props.costSheet.materials.map((m) => ({ value: m.item_id, label: `${m.item_name} (in WIP: ${formatQuantity(m.net_quantity)})` })),
);
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-2">
        <Button v-if="canEdit" variant="secondary" tone="purple" type="button" @click="show('attach')">Attach issue notes</Button>
        <Button v-if="canEdit" variant="secondary" tone="purple" type="button" @click="show('cost')">Add direct cost</Button>
        <Button v-if="canEdit" variant="secondary" tone="purple" type="button" @click="show('return')">Return material</Button>
        <Button v-if="canEdit" variant="primary" tone="purple" type="button" @click="show('complete')">Record output</Button>
        <Button v-if="inProgress && canCancel" variant="secondary" tone="danger" type="button" @click="show('abandon')">Abandon</Button>
        <Button v-if="canCancelEntry" variant="secondary" tone="danger" type="button" @click="show('cancel')">Cancel entry</Button>
    </div>

    <ProductionDialog :open="open === 'attach'" title="Attach material issue notes" submit-label="Attach" :processing="attachForm.processing" :error="firstError('attach')" @close="close" @submit="submit('attach')">
        <p v-if="availableNotes.length === 0" class="text-sm text-text-muted">No unattached posted notes for this store.</p>
        <div v-for="note in availableNotes" :key="note.id" class="border border-border-soft">
            <label class="flex cursor-pointer items-center gap-3 bg-bg-subtle px-3 py-2 text-sm">
                <input type="checkbox" :checked="attachForm.material_issue_note_ids.includes(note.id)" @change="toggleNote(note.id)" />
                <span class="font-semibold">Note {{ note.number }}</span>
                <span class="text-text-muted">{{ note.date }}</span>
                <span class="ml-auto font-semibold">{{ formatMoney(note.total_cost) }}</span>
            </label>
            <IssueNoteLines :lines="note.items" />
        </div>
    </ProductionDialog>

    <ProductionDialog :open="open === 'cost'" title="Add direct cost" submit-label="Add cost" :processing="costForm.processing" :error="firstError('cost')" @close="close" @submit="submit('cost')">
        <div>
            <Label class="mb-1">Type</Label>
            <Select :model-value="costForm.cost_type" :options="costTypes" @update:model-value="(v) => (costForm.cost_type = v)" />
        </div>
        <div>
            <Label class="mb-1">Description</Label>
            <Input v-model="costForm.description" type="text" />
        </div>
        <div>
            <Label class="mb-1">Amount <span class="text-danger">*</span></Label>
            <Input v-model="costForm.amount" type="text" inputmode="decimal" placeholder="0.00" />
        </div>
        <div>
            <Label class="mb-1">Date</Label>
            <NepaliDateInput v-model="costForm.date" />
        </div>
        <div>
            <Label class="mb-1">Paid from / payable account <span class="text-danger">*</span></Label>
            <Select :model-value="costForm.credit_account_id" :options="accountOptions" placeholder="Select account" @update:model-value="(v) => (costForm.credit_account_id = v)" />
        </div>
        <CorrectionReasonField v-if="needsReason('cost')" id="cost-correction-reason" v-model="costForm.reason" :year="correctionFiscalYear" />

    </ProductionDialog>

    <ProductionDialog :open="open === 'return'" title="Return unused material" submit-label="Return" :processing="returnForm.processing" :error="firstError('return')" @close="close" @submit="submit('return')">
        <div>
            <Label class="mb-1">Material</Label>
            <Select :model-value="returnForm.item_id" :options="materialOptions" placeholder="Select material" @update:model-value="(v) => (returnForm.item_id = v)" />
        </div>
        <div>
            <Label class="mb-1">Quantity <span class="text-danger">*</span></Label>
            <Input v-model="returnForm.quantity" type="text" inputmode="decimal" placeholder="0.0000" />
        </div>
        <div>
            <Label class="mb-1">Date</Label>
            <NepaliDateInput v-model="returnForm.date" />
        </div>
        <CorrectionReasonField v-if="needsReason('return')" id="return-correction-reason" v-model="returnForm.reason" :year="correctionFiscalYear" />
    </ProductionDialog>

    <ProductionDialog :open="open === 'complete'" title="Record finished output" submit-label="Record output" :processing="completeForm.processing" :error="firstError('complete')" @close="close" @submit="submit('complete')">
        <div>
            <Label class="mb-1">Quantity produced <span class="text-danger">*</span></Label>
            <Input v-model="completeForm.quantity" type="text" inputmode="decimal" placeholder="0.0000" />
        </div>
        <div>
            <Label class="mb-1">Date</Label>
            <NepaliDateInput v-model="completeForm.date" />
        </div>
        <CorrectionReasonField v-if="needsReason('complete')" id="complete-correction-reason" v-model="completeForm.reason" :year="correctionFiscalYear" />
        <label class="flex items-center gap-2 text-sm">
            <input v-model="completeForm.is_final" type="checkbox" />
            Final output: take all remaining WIP cost and close this entry
        </label>
    </ProductionDialog>

    <ProductionDialog :open="open === 'abandon'" title="Abandon production" submit-label="Abandon and write off WIP" danger :processing="abandonForm.processing" :error="firstError('abandon')" @close="close" @submit="submit('abandon')">
        <p class="text-sm text-text-muted">The remaining WIP balance is written off to Production Loss. This cannot be undone.</p>
        <div>
            <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
            <Input v-model="abandonForm.reason" type="text" />
        </div>
    </ProductionDialog>

    <ProductionDialog :open="open === 'cancel'" title="Cancel production entry" submit-label="Confirm cancellation" danger :processing="cancelForm.processing" :error="firstError('cancel')" @close="close" @submit="submit('cancel')">
        <p class="text-sm text-text-muted">Direct costs are reversed and the issue notes become available again. Only possible while nothing has been completed.</p>
        <div>
            <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
            <Input v-model="cancelForm.reason" type="text" />
        </div>
    </ProductionDialog>
  </div>
</template>
