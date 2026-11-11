<script setup>
import { computed, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { Check, Plus, Trash2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import CheckboxField from '@/components/ui/CheckboxField.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormSection from '@/components/ui/FormSection.vue';
import FormField from '@/components/ui/FormField.vue';
import FormActions from '@/components/ui/FormActions.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, parseMoney, sumMoney } from '@/lib/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // null on create.
    staff: { type: Object, default: null },
    // Recurring pay in force today: [{ pay_component_id, amount, effective_from }].
    payItems: { type: Array, default: () => [] },
    components: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    // { payable, advance } decimal strings, or null on create.
    balances: { type: Object, default: null },
    today: { type: String, default: '' },
});

const page = usePage();
const { toast } = useToast();
const { can } = usePermissions();

const isEdit = computed(() => props.staff !== null);
useLayoutChrome(() => (isEdit.value ? 'Edit staff' : 'New staff'));

// UI mirrors of routes/tenant-hr.php; the routes still enforce their own keys.
const canSave = computed(() => (isEdit.value ? can('hr_staff.edit') : can('hr_staff.create')));
const canViewStatement = computed(() => can('hr_reports.view'));

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

// Stable v-for keys for the pay rows (stripped before submit).
let nextRowKey = 0;
function newRow(componentId = null, amount = '') {
    nextRowKey += 1;

    return { _key: nextRowKey, pay_component_id: componentId, amount };
}

function initialData() {
    const staff = props.staff;

    return {
        staff_code: staff?.staff_code ?? '',
        name: staff?.name ?? '',
        mobile_no: staff?.mobile_no ?? '',
        address: staff?.address ?? '',
        designation: staff?.designation ?? '',
        department: staff?.department ?? '',
        join_date: staff?.join_date ?? '',
        end_date: staff?.end_date ?? '',
        pan_no: staff?.pan_no ?? '',
        bank_name: staff?.bank_name ?? '',
        bank_account_no: staff?.bank_account_no ?? '',
        user_id: staff?.user_id ?? null,
        is_active: staff?.is_active ?? true,
        pay_effective_from: props.today,
        pay_items: props.payItems.map((item) => newRow(item.pay_component_id, item.amount)),
    };
}

const form = useForm(initialData());

// Create redirects to the edit page of the same component; if Inertia keeps the
// component instance, re-seed the form from the fresh props.
watch(
    () => props.staff?.id ?? null,
    () => {
        form.defaults(initialData());
        form.reset();
        form.clearErrors();
    },
);

const userOptions = computed(() => [
    { value: null, label: 'No login linked' },
    ...props.users.map((user) => ({ value: user.id, label: user.email ? `${user.name} (${user.email})` : user.name })),
]);

const componentLabels = computed(() => Object.fromEntries(props.components.map((c) => [c.id, c.code ? `${c.name} (${c.code})` : c.name])));

/** A component can be picked in only one row: hide the ones other rows hold. */
function optionsForRow(index) {
    const takenElsewhere = new Set(form.pay_items.filter((_, i) => i !== index).map((row) => row.pay_component_id));

    return props.components
        .filter((component) => !takenElsewhere.has(component.id))
        .map((component) => ({ value: component.id, label: componentLabels.value[component.id] }));
}

const canAddRow = computed(() => form.pay_items.length < props.components.length);

function addRow() {
    if (!canAddRow.value) return;
    form.pay_items.push(newRow());
}

function removeRow(index) {
    form.pay_items.splice(index, 1);
}

// Exact sum of the rows that parse as valid money; incomplete rows are ignored
// here and rejected by the server on save.
const monthlyTotal = computed(() => {
    const valid = form.pay_items.map((row) => parseMoney(row.amount)).filter((parsed) => parsed.ok);

    return sumMoney(valid.map((parsed) => parsed.value));
});

function rowError(index, field) {
    return form.errors[`pay_items.${index}.${field}`] ?? '';
}

const payItemsError = computed(() => form.errors.pay_items ?? '');

function submit() {
    if (!canSave.value) return;

    const url = isEdit.value ? `/hr/staff/${props.staff.id}` : '/hr/staff';
    const method = isEdit.value ? 'put' : 'post';

    form.transform((data) => {
        const { is_active: isActive, pay_items: rows, ...rest } = data;
        const payload = {
            ...rest,
            // Blank code means "auto" on create and "keep current" on edit.
            staff_code: rest.staff_code === '' ? null : rest.staff_code,
            pay_items: rows.map((row) => {
                const parsed = parseMoney(row.amount);

                return { pay_component_id: row.pay_component_id, amount: parsed.ok ? parsed.value : row.amount };
            }),
        };

        return isEdit.value ? { ...payload, is_active: isActive } : payload;
    });

    form[method](url, { preserveScroll: true });
}

function discard() {
    form.reset();
    form.clearErrors();
}
</script>

<template>
    <div class="flex flex-1 flex-col">
        <PageHeader
            :title="isEdit ? `Edit ${staff.name}` : 'New staff member'"
            :description="isEdit ? 'Update their details and recurring pay. Past payroll is never changed.' : 'Add an employee and set what they are paid each month. Fields marked * are required.'"
            back-href="/hr/staff"
            back-label="All staff"
        >
            <Button v-if="isEdit && canViewStatement" :as="Link" :href="`/hr/staff/${staff.id}/statement`" variant="secondary" tone="neutral">
                View statement
            </Button>
        </PageHeader>

        <form class="flex flex-1 flex-col gap-8" @submit.prevent="submit">
            <p v-if="!canSave" class="text-[13px] text-text-muted" role="status">You can view this staff member but not change them.</p>

            <fieldset :disabled="!canSave" class="contents">
                <div class="flex flex-col gap-8">
                    <FormSection title="Personal details" description="Who the employee is and how to reach them.">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <FormField v-slot="{ describedBy }" label="Name" for="name" required :error="form.errors.name">
                                <Input id="name" v-model="form.name" type="text" placeholder="e.g. Sita Sharma" required :aria-describedby="describedBy" />
                            </FormField>
                            <FormField
                                v-slot="{ describedBy }"
                                label="Staff code"
                                for="staff_code"
                                help="Leave blank to number automatically."
                                :error="form.errors.staff_code"
                            >
                                <Input id="staff_code" v-model="form.staff_code" type="text" placeholder="auto" :aria-describedby="describedBy" />
                            </FormField>
                            <FormField v-slot="{ describedBy }" label="Mobile no." for="mobile_no" :error="form.errors.mobile_no">
                                <Input id="mobile_no" v-model="form.mobile_no" type="text" placeholder="98XXXXXXXX" :aria-describedby="describedBy" />
                            </FormField>
                            <FormField v-slot="{ describedBy }" label="Address" for="address" :error="form.errors.address">
                                <Input id="address" v-model="form.address" type="text" placeholder="e.g. Kathmandu-10" :aria-describedby="describedBy" />
                            </FormField>
                            <FormField v-slot="{ describedBy }" label="PAN no." for="pan_no" :error="form.errors.pan_no">
                                <Input id="pan_no" v-model="form.pan_no" type="text" :aria-describedby="describedBy" />
                            </FormField>
                        </div>
                    </FormSection>

                    <FormSection title="Employment" description="Role and dates. Set an end date when someone leaves, and untick Active to hide them from new payroll runs.">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <FormField v-slot="{ describedBy }" label="Designation" for="designation" :error="form.errors.designation">
                                <Input id="designation" v-model="form.designation" type="text" placeholder="e.g. Accountant" :aria-describedby="describedBy" />
                            </FormField>
                            <FormField v-slot="{ describedBy }" label="Department" for="department" :error="form.errors.department">
                                <Input id="department" v-model="form.department" type="text" placeholder="e.g. Finance" :aria-describedby="describedBy" />
                            </FormField>
                            <FormField v-slot="{ describedBy }" label="Join date (BS)" for="join_date" :error="form.errors.join_date">
                                <NepaliDateInput id="join_date" v-model="form.join_date" :aria-describedby="describedBy" />
                            </FormField>
                            <FormField v-slot="{ describedBy }" label="End date (BS)" for="end_date" :error="form.errors.end_date">
                                <NepaliDateInput id="end_date" v-model="form.end_date" :aria-describedby="describedBy" />
                            </FormField>
                        </div>
                        <div v-if="isEdit" class="mt-4 border-t border-border pt-4">
                            <CheckboxField id="is_active" v-model="form.is_active" label="Active" hint="Inactive staff are kept for history but left out of new payroll runs." />
                            <p v-if="form.errors.is_active" class="mt-1 text-sm text-danger">{{ form.errors.is_active }}</p>
                        </div>
                    </FormSection>

                    <FormSection title="Bank and login" description="Where salary is paid, and the sign-in account this person uses, if any.">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <FormField v-slot="{ describedBy }" label="Bank name" for="bank_name" :error="form.errors.bank_name">
                                <Input id="bank_name" v-model="form.bank_name" type="text" :aria-describedby="describedBy" />
                            </FormField>
                            <FormField v-slot="{ describedBy }" label="Bank account no." for="bank_account_no" :error="form.errors.bank_account_no">
                                <Input id="bank_account_no" v-model="form.bank_account_no" type="text" :aria-describedby="describedBy" />
                            </FormField>
                            <FormField
                                label="Linked login"
                                for="user_id"
                                help="Optional. Only users not already linked to another staff member are listed."
                                :error="form.errors.user_id"
                                class="sm:col-span-2"
                            >
                                <Select v-model="form.user_id" :options="userOptions" placeholder="No login linked" />
                            </FormField>
                        </div>
                    </FormSection>

                    <FormSection
                        title="Recurring pay"
                        description="What this person earns each pay period. Changes apply from the date you choose; earlier payroll keeps the amounts it was run with."
                    >
                        <div class="flex flex-col gap-4">
                            <div class="max-w-xs">
                                <FormField v-slot="{ describedBy }" label="Applies from (BS)" for="pay_effective_from" :error="form.errors.pay_effective_from">
                                    <NepaliDateInput id="pay_effective_from" v-model="form.pay_effective_from" :aria-describedby="describedBy" />
                                </FormField>
                            </div>

                            <p v-if="components.length === 0" class="text-[13px] text-text-muted">
                                No pay components exist yet. Add some under Pay components, then come back to set this person's pay.
                            </p>

                            <div v-if="form.pay_items.length > 0" class="flex flex-col gap-3">
                                <div
                                    v-for="(row, index) in form.pay_items"
                                    :key="row._key"
                                    class="grid grid-cols-1 items-start gap-3 border-t border-border pt-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,12rem)_auto]"
                                >
                                    <FormField label="Component" :for="`pay-component-${row._key}`" required :error="rowError(index, 'pay_component_id')">
                                        <Select v-model="row.pay_component_id" :options="optionsForRow(index)" placeholder="Choose a component" />
                                    </FormField>
                                    <FormField v-slot="{ describedBy }" label="Amount (Rs.)" :for="`pay-amount-${row._key}`" required :error="rowError(index, 'amount')">
                                        <Input
                                            :id="`pay-amount-${row._key}`"
                                            v-model="row.amount"
                                            type="text"
                                            inputmode="decimal"
                                            placeholder="0.00"
                                            class="text-right"
                                            :aria-describedby="describedBy"
                                        />
                                    </FormField>
                                    <div class="sm:pt-[26px]">
                                        <Button variant="icon" tone="danger" type="button" aria-label="Remove this pay row" @click="removeRow(index)">
                                            <Trash2 class="size-4" aria-hidden="true" />
                                        </Button>
                                    </div>
                                </div>
                            </div>
                            <p v-else-if="components.length > 0" class="text-[13px] text-text-muted">No recurring pay set yet.</p>

                            <p v-if="payItemsError" class="text-[13px] text-danger" role="alert">{{ payItemsError }}</p>

                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
                                <Button variant="secondary" tone="neutral" type="button" :disabled="!canAddRow" @click="addRow">
                                    <Plus class="size-4" aria-hidden="true" />
                                    Add pay row
                                </Button>
                                <p class="text-[13px] text-text-muted">
                                    Total per period
                                    <span class="ml-1 text-sm font-bold text-text-strong">Rs. {{ formatMoney(monthlyTotal) }}</span>
                                </p>
                            </div>
                        </div>
                    </FormSection>

                    <FormSection
                        v-if="isEdit && balances"
                        title="Balances"
                        description="Worked out from posted payroll, salary payments and advances. These can only change through those entries."
                    >
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Salary payable (we owe)</p>
                                <p class="text-base font-bold text-text-strong">Rs. {{ formatMoney(balances.payable) }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Advance receivable (they owe)</p>
                                <p class="text-base font-bold text-text-strong">Rs. {{ formatMoney(balances.advance) }}</p>
                            </div>
                        </div>
                    </FormSection>
                </div>
            </fieldset>

            <FormActions class="mt-auto" :status="form.isDirty ? 'Unsaved changes' : ''" :emphasize="form.isDirty">
                <Button v-if="canSave" variant="secondary" tone="neutral" type="button" :disabled="!form.isDirty || form.processing" @click="discard">Discard</Button>
                <Button :as="Link" href="/hr/staff" variant="secondary" tone="neutral">Back to list</Button>
                <Button v-if="canSave" variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="form.processing">
                    <Check class="size-4" aria-hidden="true" />
                    {{ isEdit ? 'Save changes' : 'Save staff member' }}
                </Button>
            </FormActions>
        </form>
    </div>
</template>
