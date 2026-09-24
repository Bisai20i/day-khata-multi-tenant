<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';

const props = defineProps({
    form: { type: Object, required: true },
    bankAccounts: { type: Array, default: () => [] },
    // Saved boilerplate lines a cashier can drop into Narration (audit
    // section 4 polish, "note templates") - see SaleController
    // ::storeNoteTemplate()/destroyNoteTemplate().
    noteTemplates: { type: Array, default: () => [] },
    showBankField: { type: Boolean, default: false },
    showPartialFields: { type: Boolean, default: false },
});

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial (cash + bank)' },
    { value: 'credit', label: 'Credit' },
];

const bankAccountOptions = computed(() =>
    props.bankAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })),
);

// Saved-note dropdown: picking one just fills Narration - the cashier is
// still free to edit it afterwards, this never re-fires on its own.
const noteTemplateOptions = computed(() => props.noteTemplates.map((t) => ({ value: t.id, label: t.text })));

function applyNoteTemplate(templateId) {
    const template = props.noteTemplates.find((t) => t.id === templateId);
    if (template) props.form.narration = template.text;
}

const noteTemplateForm = useForm({ text: '' });

/** Saves the current Narration text as a reusable template for next time. */
function saveNoteTemplate() {
    const text = props.form.narration.trim();
    if (!text) return;

    noteTemplateForm.text = text;
    noteTemplateForm.post('/sales/note-templates', {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <!-- Payment and Notes side by side - both are quick, secondary
         fields that don't need a full-width row each. -->
    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
        <!-- Payment: its own clearly-labelled section - burying this
             inside a generic "Notes" card made it too easy to miss that
             the payment type/details live down here now. -->
        <Card variant="panel" class="!p-4">
            <template #title>
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                    <span>Payment</span>
                    <span id="sale-payment-help" class="text-[11px] font-normal normal-case tracking-normal text-text-muted">
                        Credit = customer pays later and the amount goes on their account.
                    </span>
                </div>
            </template>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="sale-payment-mode" class="mb-1 block text-sm font-semibold text-text-base">Payment type <span class="text-danger" aria-hidden="true">*</span></label>
                    <Select id="sale-payment-mode" v-model="form.payment_mode" :options="paymentModeOptions" aria-describedby="sale-payment-help" />
                </div>
                <div v-if="showBankField">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank account <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.bank_account_id"
                        :options="bankAccountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (form.bank_account_id = v)"
                    />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>
            </div>

            <div v-if="showPartialFields" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="sale-cash-amount" class="mb-1 block text-sm font-semibold text-text-base">Cash received (Rs) <span class="text-danger" aria-hidden="true">*</span></label>
                    <Input id="sale-cash-amount" v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" required aria-describedby="sale-cash-error" />
                    <p v-if="form.errors.cash_amount" id="sale-cash-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.cash_amount }}</p>
                </div>
                <div>
                    <label for="sale-bank-amount" class="mb-1 block text-sm font-semibold text-text-base">Bank received (Rs) <span class="text-danger" aria-hidden="true">*</span></label>
                    <Input id="sale-bank-amount" v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" required aria-describedby="sale-bank-error" />
                    <p v-if="form.errors.bank_amount" id="sale-bank-error" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.bank_amount }}</p>
                </div>
            </div>
        </Card>

        <!-- Notes: narration only - kept as its own, visually lighter
             section now that Payment has its own card. -->
        <Card variant="panel" title="Notes" class="!p-4">
            <label for="sale-narration" class="mb-1 block text-sm font-semibold text-text-base">Narration (printed on the bill)</label>
            <div class="flex gap-2">
                <Input id="sale-narration" v-model="form.narration" type="text" placeholder="Optional" class="flex-1">
                    <template #addon>
                        <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center text-text-muted hover:text-primary disabled:cursor-not-allowed disabled:opacity-50"
                            title="Save this narration as a reusable note"
                            :disabled="!form.narration.trim() || noteTemplateForm.processing"
                            @click="saveNoteTemplate"
                        >
                            <Plus class="h-3.5 w-3.5" />
                        </button>
                    </template>
                </Input>
                <!-- Saved-note picker: fills Narration above, still freely
                     editable afterwards. -->
                <Select
                    v-if="noteTemplateOptions.length"
                    :model-value="null"
                    :options="noteTemplateOptions"
                    placeholder="Saved notes"
                    class="w-48"
                    @update:model-value="applyNoteTemplate"
                />
            </div>
        </Card>
    </div>
</template>
