<script setup>
import { ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import SaleCreateCustomerCard from '@/components/sales/SaleCreateCustomerCard.vue';
import SaleCreateCustomerModal from '@/components/sales/SaleCreateCustomerModal.vue';
import SaleCreateStagingRow from '@/components/sales/SaleCreateStagingRow.vue';
import SaleCreateLinesTable from '@/components/sales/SaleCreateLinesTable.vue';
import SaleCreatePaymentNotes from '@/components/sales/SaleCreatePaymentNotes.vue';
import SaleCreateMoreOptions from '@/components/sales/SaleCreateMoreOptions.vue';
import SaleCreateTotalsBar from '@/components/sales/SaleCreateTotalsBar.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useSaleCreateItems } from '@/composables/useSaleCreateItems';
import { useSaleCreatePreview } from '@/composables/useSaleCreatePreview';
import { useSaleCreateCustomer } from '@/composables/useSaleCreateCustomer';
import { percentOf } from '@/lib/money';
import { toSaleCreatePayload } from '@/lib/saleCreate';
import { todayInKathmandu } from '@/lib/format';
import { usePermissions } from '@/composables/usePermissions';
import PosEstimateModal from '@/components/pos/PosEstimateModal.vue';
import { useSaleEstimate } from '@/composables/useSaleEstimate';
import { useToast } from '@/composables/useToast';

/**
 * Every rupee shown on this form comes from calculateDocument(), the exact
 * mirror of the server's App\Support\Billing\DocumentCalculator (CONTRACTS
 * C3/C8). Nothing here adds up a bill on its own: the audit found the preview
 * quoting 56.49 for a bill the server stored as 56.50 (P0-8), because the
 * browser summed unrounded floats and rounded halves the other way.
 *
 * The preview's total is submitted as `expected_total`; if the server arrives
 * at anything else it refuses the save rather than booking a different amount
 * than the one on screen.
 */
const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    tdsAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
    // Saved boilerplate lines a cashier can drop into Narration
    // (audit section 4 polish, "note templates") - see SaleController
    // ::storeNoteTemplate()/destroyNoteTemplate().
    noteTemplates: { type: Array, default: () => [] },
    // The tenant's protected walk-in customer (audit section 3 "Sales") -
    // preselected below so a counter sale with nobody to name still has a
    // valid customer_id without the cashier hunting for it.
    walkInCustomerId: { type: Number, default: null },
    invoiceSettings: {
        type: Object,
        default: () => ({
            default_vat_rate: '13.00',
            default_store_id: null,
            active_invoice_type: 'full',
        }),
    },
    // Set by the parent Index page when it bounces back here after the
    // inline "+ New customer" modal redirects away and back (see
    // useSaleCreateCustomer()) - restores the in-progress draft that would
    // otherwise be lost when Index re-mounts this component.
    initialDraft: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'posted']);
const { confirm } = useConfirm();
const page = usePage();
const { can } = usePermissions();

/** Cancel straight away when nothing was entered, otherwise ask before discarding. */
async function requestCancel() {
    const hasEntries = form.isDirty || form.lines.length > 0;
    if (hasEntries) {
        const discard = await confirm({
            title: 'Discard this sale?',
            message: 'The items and details you entered have not been saved and will be lost.',
            tone: 'danger',
            confirmLabel: 'Discard sale',
            cancelLabel: 'Keep editing',
        });
        if (!discard) return;
    }
    emit('cancel');
}

function defaultFormData() {
    return {
        // Defaults to the walk-in customer (audit section 3 "Sales") - still
        // freely changeable, this just saves the cashier a click on the
        // common case of a counter sale nobody bothers to name.
        customer_id: props.walkInCustomerId ?? null,
        store_id: props.invoiceSettings.default_store_id ?? null,
        chalani_number: '',
        // Asia/Kathmandu, not UTC: between midnight and 05:45 local time a
        // toISOString() default dated the bill to the previous day.
        date: todayInKathmandu(),
        payment_mode: 'cash',
        bank_account_id: null,
        discount: '',
        discount_type: 'flat',
        cash_amount: '',
        bank_amount: '',
        tds_account_id: null,
        tds_amount: '',
        agent_id: null,
        commission_amount: '',
        narration: '',
        // Items only ever enter the bill through the "Add item" staging
        // panel (SaleCreateStagingRow) - no starter blank row here.
        lines: [],
    };
}

const form = useForm(props.initialDraft ?? defaultFormData());

const { isPanInvoice, effectiveVatRate, itemOptions, itemsById, selectLineUnit, selectLineItem, applyLineMrp } =
    useSaleCreateItems(props);
const { totals, previewError, toggleHeaderDiscountType, showBankField, showPartialFields, partialBalanced, canSubmit } =
    useSaleCreatePreview(form, { itemsById, isPanInvoice, effectiveVatRate });
const { customerModalOpen, customerForm, openCustomerModal, closeCustomerModal, submitCustomer } = useSaleCreateCustomer(form, props);

// Estimate: the bill this form would post, previewed without saving. Sent
// to the sales-gated twin of the POS route, since this page needs no pos.view.
const { estimateOpen, estimateUrl, estimateLoading, openEstimate, closeEstimate } = useSaleEstimate({
    form,
    totals,
    previewError,
    toast: useToast().toast,
    url: '/sales/estimate',
    buildPayload: () => toSaleCreatePayload(form.data()),
});

// Progressive disclosure for the remaining rare fields (Store, header
// discount, TDS, agent) - Narration stays directly visible per the redesign,
// it's common enough not to hide.
const showMoreOptions = ref(false);
const showLineExtras = ref(false);

function selectAgent(agentId) {
    form.agent_id = agentId;

    if (!agentId) {
        form.commission_amount = '';
        return;
    }

    const agent = props.agents.find((a) => a.id === agentId);
    if (agent?.commission_rate && totals.value) {
        form.commission_amount = percentOf(totals.value.total, String(agent.commission_rate));
    }
}

function submit(print = false, copies = 1) {
    if (!totals.value) return;

    const expectedTotal = totals.value.total;

    form.transform((data) => ({ ...toSaleCreatePayload(data), expected_total: expectedTotal })).post('/sales', {
        preserveScroll: true,
        onSuccess: () => {
            // C11: the server flashes exactly which document it just created,
            // so this opens that bill's print view instead of guessing the
            // newest id out of the list it was redirected to.
            const created = page.props.flash?.created;
            if (print && created?.print_url && can('sales.print')) {
                window.open(`${created.print_url}?copies=${copies}`, '_blank');
            }
            emit('posted');
        },
    });
}
</script>

<template>
    <div>
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h3 class="text-base font-bold text-text-strong">New sale</h3>
            <p class="text-xs text-text-muted">Fields marked * are required. Totals update as you add items.</p>
        </div>
        <Button variant="secondary" tone="purple" type="button" @click="requestCancel">Cancel</Button>
    </div>

    <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
        {{ form.errors.lines }}
    </p>
    <p v-if="form.errors.expected_total" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
        {{ form.errors.expected_total }}
    </p>

    <form class="flex flex-col gap-4 pb-4" @submit.prevent="submit(false)">
            <SaleCreateCustomerCard :form="form" :customers="customers" @add-customer="openCustomerModal" />

            <!-- Items: staging row + the bill's committed lines, merged into
                 one section instead of two separate cards. -->
            <Card variant="panel" class="!p-4">
                <template #title>
                    <div class="flex items-center justify-between">
                        <span>Items</span>
                        <button type="button" class="text-xs font-semibold tracking-normal text-primary normal-case" @click="showLineExtras = !showLineExtras">
                            {{ showLineExtras ? 'Hide' : 'Show' }} bonus &amp; MRP columns
                        </button>
                    </div>
                </template>

                <SaleCreateStagingRow
                    :item-options="itemOptions"
                    :items-by-id="itemsById"
                    :show-line-extras="showLineExtras"
                    :select-item="selectLineItem"
                    :select-unit="selectLineUnit"
                    :apply-mrp="applyLineMrp"
                    @add="(line) => form.lines.push(line)"
                />

                <SaleCreateLinesTable
                    :lines="form.lines"
                    :errors="form.errors"
                    :item-options="itemOptions"
                    :items-by-id="itemsById"
                    :show-line-extras="showLineExtras"
                    :effective-vat-rate="effectiveVatRate"
                    :totals="totals"
                    :select-item="selectLineItem"
                    :select-unit="selectLineUnit"
                    :apply-mrp="applyLineMrp"
                    @remove="(index) => form.lines.splice(index, 1)"
                />

                <p v-if="previewError" class="mt-2 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                    {{ previewError }}
                </p>
            </Card>

            <SaleCreatePaymentNotes
                :form="form"
                :bank-accounts="bankAccounts"
                :note-templates="noteTemplates"
                :show-bank-field="showBankField"
                :show-partial-fields="showPartialFields"
            />

            <!-- Toggled from the sticky bar below (left side, chevron
                 indicator) - the panel itself still renders here, directly
                 above the totals, so it never fights the floating bar for
                 space. -->
            <Transition name="extras">
                <SaleCreateMoreOptions
                    v-if="showMoreOptions"
                    :form="form"
                    :stores="stores"
                    :tds-accounts="tdsAccounts"
                    :agents="agents"
                    @toggle-discount-type="toggleHeaderDiscountType"
                    @select-agent="selectAgent"
                />
            </Transition>

            <SaleCreateTotalsBar
                v-model:show-more-options="showMoreOptions"
                :totals="totals"
                :show-partial-fields="showPartialFields"
                :partial-balanced="partialBalanced"
                :can-submit="canSubmit"
                :processing="form.processing"
                :can-estimate="form.lines.length > 0"
                @cancel="requestCancel"
                @estimate="openEstimate"
                @print="(copies) => submit(true, copies)"
            />
        </form>

    <SaleCreateCustomerModal
        :open="customerModalOpen"
        :customer-form="customerForm"
        @close="closeCustomerModal"
        @submit="submitCustomer"
    />

    <PosEstimateModal :open="estimateOpen" :url="estimateUrl" :loading="estimateLoading" @close="closeEstimate" />
    </div>
</template>

<style scoped>
/* The More-options panel pops in/out via v-if; without this it would snap
   instantly and read as a layout jump rather than an intentional toggle. */
.extras-enter-active,
.extras-leave-active {
    transition:
        opacity 150ms ease,
        transform 150ms ease;
}
.extras-enter-from,
.extras-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>
