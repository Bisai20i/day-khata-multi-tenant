<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Trash2, Upload } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Tabs from '@/components/ui/Tabs.vue';
import FormActions from '@/components/ui/FormActions.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';

defineOptions({ layout: AppLayout });

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
    stores: {
        type: Array,
        default: () => [],
    },
    invoiceNumbering: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();

useLayoutChrome('Company Setup');

const settingsTabs = [
    { value: 'company', label: 'Company Profile' },
    { value: 'logo', label: 'Company Logo' },
    { value: 'invoice', label: 'Invoicing & Billing' },
    { value: 'numbering', label: 'Invoice Numbering' },
];

const activeTab = ref('company');

const paperSizeOptions = [
    { value: 'a4', label: 'A4' },
    { value: 'a5', label: 'A5' },
    { value: '58mm', label: 'Thermal 58mm' },
    { value: '80mm', label: 'Thermal 80mm' },
];

const invoiceTypeOptions = [
    { value: 'full', label: 'Full tax invoice', prefixKey: 'sale_full_prefix', prefixPlaceholder: 'SL' },
    { value: 'abbreviated', label: 'Abbreviated tax invoice', prefixKey: 'sale_abbreviated_prefix', prefixPlaceholder: 'SLA' },
    { value: 'pan', label: 'PAN invoice', prefixKey: 'sale_pan_prefix', prefixPlaceholder: 'SLP' },
];

const storeOptions = computed(() => [
    { value: null, label: 'None (first active store)' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const form = useForm({
    company_name: props.settings.company_name ?? '',
    address: props.settings.address ?? '',
    phone: props.settings.phone ?? '',
    email: props.settings.email ?? '',
    pan_vat_number: props.settings.pan_vat_number ?? '',
    invoice_footer_note: props.settings.invoice_footer_note ?? '',
    print_paper_size: props.settings.print_paper_size ?? 'a4',
    default_vat_rate: props.settings.default_vat_rate ?? '13.00',
    allow_negative_stock: props.settings.allow_negative_stock ?? false,
    default_store_id: props.settings.default_store_id ?? null,
    sale_full_prefix: props.settings.sale_full_prefix ?? 'SL',
    sale_abbreviated_prefix: props.settings.sale_abbreviated_prefix ?? 'SLA',
    sale_pan_prefix: props.settings.sale_pan_prefix ?? 'SLP',
    active_invoice_type: props.settings.active_invoice_type ?? 'full',
    purchase_prefix: props.settings.purchase_prefix ?? 'PU',
    sale_return_prefix: props.settings.sale_return_prefix ?? 'SR',
    purchase_return_prefix: props.settings.purchase_return_prefix ?? 'PR',
});

function submit() {
    form.put('/settings');
}

function discard() {
    form.reset();
    form.clearErrors();
}

// Starting number
const startingNumberForm = useForm({ voucher_type: '', next_number: '' });
const editingSeries = ref(null);

function openStartingNumber(row) {
    editingSeries.value = row.voucher_type;
    startingNumberForm.clearErrors();
    startingNumberForm.voucher_type = row.voucher_type;
    startingNumberForm.next_number = String(row.next_number);
}

function submitStartingNumber() {
    startingNumberForm.post('/settings/starting-number', {
        preserveScroll: true,
        onSuccess: () => {
            editingSeries.value = null;
            startingNumberForm.reset();
        },
    });
}

// Logo upload and delete
const logoForm = useForm({
    logo: null,
});

const logoPreviewUrl = ref(props.settings.logo_url ?? '');
const fileInput = ref(null);

watch(
    () => props.settings.logo_url,
    (url) => {
        logoPreviewUrl.value = url ?? '';
    },
);

function onLogoChange(event) {
    const file = event.target.files?.[0] ?? null;
    logoForm.logo = file;
    logoPreviewUrl.value = file ? URL.createObjectURL(file) : (props.settings.logo_url ?? '');
}

function submitLogo() {
    logoForm.post('/settings/logo', {
        preserveScroll: true,
        onSuccess: () => {
            logoForm.reset();
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

async function deleteLogo() {
    const ok = await confirm({
        title: 'Delete Company Logo',
        message: 'Are you sure you want to remove the company logo? This will remove it from all invoice printouts.',
        tone: 'danger',
        confirmLabel: 'Delete Logo',
    });
    if (!ok) return;

    router.delete('/settings/logo', {
        preserveScroll: true,
        onSuccess: () => {
            logoForm.reset();
            logoPreviewUrl.value = '';
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}
</script>

<template>
    <div>
        <PageHeader
            title="Company Setup"
            description="Manage company details, branding, invoice numbering rules, and default store policies."
        />

        <Tabs v-model="activeTab" :tabs="settingsTabs">
            <!-- Tab 1: Company Profile -->
            <template #company>
                <div class="flex flex-col gap-4">
                    <Card variant="panel" title="Company Profile" class="bg-bg-surface p-4 sm:p-5">
                        <div class="flex flex-col gap-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label for="company_name" class="mb-1">Company name <span class="text-danger">*</span></Label>
                                    <Input id="company_name" v-model="form.company_name" type="text" placeholder="e.g. Sharma Traders Pvt. Ltd." required />
                                    <p v-if="form.errors.company_name" class="mt-1 text-sm text-danger">{{ form.errors.company_name }}</p>
                                </div>

                                <div>
                                    <Label for="pan_vat_number" class="mb-1">PAN/VAT number</Label>
                                    <Input id="pan_vat_number" v-model="form.pan_vat_number" type="text" placeholder="e.g. 123456789" />
                                    <p v-if="form.errors.pan_vat_number" class="mt-1 text-sm text-danger">{{ form.errors.pan_vat_number }}</p>
                                </div>

                                <div class="sm:col-span-2">
                                    <Label for="address" class="mb-1">Address</Label>
                                    <Input id="address" v-model="form.address" type="text" placeholder="e.g. Kathmandu-10" />
                                    <p v-if="form.errors.address" class="mt-1 text-sm text-danger">{{ form.errors.address }}</p>
                                </div>

                                <div>
                                    <Label for="phone" class="mb-1">Phone</Label>
                                    <Input id="phone" v-model="form.phone" type="text" placeholder="98XXXXXXXX" />
                                    <p v-if="form.errors.phone" class="mt-1 text-sm text-danger">{{ form.errors.phone }}</p>
                                </div>

                                <div>
                                    <Label for="email" class="mb-1">Email</Label>
                                    <Input id="email" v-model="form.email" type="email" placeholder="name@example.com" />
                                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                                </div>

                                <div class="sm:col-span-2">
                                    <div class="mb-1 flex items-center gap-1">
                                        <Label for="invoice_footer_note">Invoice footer note</Label>
                                        <InfoTip text="Printed at the bottom of every invoice." />
                                    </div>
                                    <textarea
                                        id="invoice_footer_note"
                                        v-model="form.invoice_footer_note"
                                        rows="3"
                                        placeholder="e.g. Thank you for your business!"
                                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none transition-colors duration-150 focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                                    ></textarea>
                                    <p v-if="form.errors.invoice_footer_note" class="mt-1 text-sm text-danger">
                                        {{ form.errors.invoice_footer_note }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>
            </template>

            <!-- Tab 2: Company Logo -->
            <template #logo>
                <div class="flex flex-col gap-4">
                    <Card variant="panel" title="Company Logo" class="bg-bg-surface p-4 sm:p-5">
                        <div class="flex flex-col gap-4">
                            <p class="text-sm text-text-muted">
                                Upload a high-resolution logo to display on invoices, bills, and printed receipts. Recommended format: PNG or JPG under 2MB.
                            </p>

                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                                <div class="flex h-24 w-24 shrink-0 items-center justify-center border-[1.5px] border-border bg-white p-2">
                                    <img
                                        v-if="logoPreviewUrl"
                                        :src="logoPreviewUrl"
                                        alt="Company logo preview"
                                        class="max-h-full max-w-full object-contain"
                                    />
                                    <span v-else class="text-xs text-text-muted">No logo</span>
                                </div>

                                <div class="min-w-0 flex-1 space-y-2">
                                    <input
                                        ref="fileInput"
                                        id="logo"
                                        type="file"
                                        accept="image/*"
                                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none file:mr-3 file:border-0 file:bg-transparent file:text-[13px] file:font-semibold file:text-primary focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                                        @change="onLogoChange"
                                    />
                                    <p v-if="logoForm.errors.logo" class="text-sm text-danger">{{ logoForm.errors.logo }}</p>
                                    <div class="flex items-center gap-2">
                                        <Button
                                            variant="secondary"
                                            tone="purple"
                                            type="button"
                                            :disabled="!logoForm.logo || logoForm.processing"
                                            :loading="logoForm.processing"
                                            @click="submitLogo"
                                        >
                                            <Upload class="size-4" />
                                            Upload logo
                                        </Button>
                                        <Button
                                            v-if="settings.logo_path || logoPreviewUrl"
                                            variant="secondary"
                                            tone="danger"
                                            type="button"
                                            @click="deleteLogo"
                                        >
                                            <Trash2 class="size-4" />
                                            Delete logo
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>
            </template>

            <!-- Tab 3: Invoicing & Billing -->
            <template #invoice>
                <div class="flex flex-col gap-4">
                    <Card variant="panel" title="Invoicing & Billing" class="bg-bg-surface p-4 sm:p-5">
                        <div class="flex flex-col gap-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <div class="mb-1 flex items-center gap-1">
                                        <Label>Print paper size</Label>
                                        <InfoTip text="Paper format used when printing invoices." />
                                    </div>
                                    <Select v-model="form.print_paper_size" :options="paperSizeOptions" />
                                    <p v-if="form.errors.print_paper_size" class="mt-1 text-sm text-danger">{{ form.errors.print_paper_size }}</p>
                                </div>

                                <div>
                                    <div class="mb-1 flex items-center gap-1">
                                        <Label for="default_vat_rate">Default VAT rate (%)</Label>
                                        <InfoTip text="Pre-filled on new sale lines; can still be changed per item." />
                                    </div>
                                    <Input id="default_vat_rate" v-model="form.default_vat_rate" type="number" min="0" max="100" step="0.01" placeholder="13.00" />
                                    <p v-if="form.errors.default_vat_rate" class="mt-1 text-sm text-danger">{{ form.errors.default_vat_rate }}</p>
                                </div>
                            </div>

                            <div class="border-t border-border pt-4">
                                <p class="mb-1 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Invoice type (fixed per company)</p>
                                <p class="mb-3 text-sm text-text-muted">
                                    Set this to whichever invoice type your company is registered to issue under IRD - it is
                                    never a choice the cashier makes per sale. An abbreviated or PAN invoice may not be
                                    issued once a bill's taxable value exceeds NPR 10,000; use Full tax invoice for a
                                    business that regularly bills above that.
                                </p>
                                <p v-if="form.errors.active_invoice_type" class="mb-3 text-sm text-danger">{{ form.errors.active_invoice_type }}</p>
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-3 md:gap-4">
                                    <label
                                        v-for="option in invoiceTypeOptions"
                                        :key="option.value"
                                        class="flex cursor-pointer flex-col gap-2 border-[1.5px] p-3"
                                        :class="form.active_invoice_type === option.value ? 'border-primary bg-[#FAF5FF]' : 'border-border'"
                                    >
                                        <span class="flex items-center gap-2">
                                            <input v-model="form.active_invoice_type" type="radio" :value="option.value" class="size-4" />
                                            <span class="text-sm font-semibold text-text-base">{{ option.label }}</span>
                                        </span>
                                        <span class="text-xs text-text-muted">Number prefix (e.g. {{ option.prefixPlaceholder }}-1)</span>
                                        <Input
                                            v-model="form[option.prefixKey]"
                                            type="text"
                                            :placeholder="option.prefixPlaceholder"
                                            :aria-label="`${option.label} prefix`"
                                            @click.stop
                                        />
                                        <p v-if="form.errors[option.prefixKey]" class="text-sm text-danger">{{ form.errors[option.prefixKey] }}</p>
                                    </label>
                                </div>
                            </div>

                            <div class="border-t border-border pt-4">
                                <div class="mb-3 flex items-center gap-1">
                                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Purchase and return numbering</p>
                                    <InfoTip text="Every series needs its own prefix. Two series sharing one print the same number on two different documents." />
                                </div>
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-3 md:gap-4">
                                    <div>
                                        <Label for="purchase_prefix" class="mb-1">Purchase prefix</Label>
                                        <Input id="purchase_prefix" v-model="form.purchase_prefix" type="text" placeholder="PU" />
                                        <p v-if="form.errors.purchase_prefix" class="mt-1 text-sm text-danger">{{ form.errors.purchase_prefix }}</p>
                                    </div>

                                    <div>
                                        <Label for="sale_return_prefix" class="mb-1">Credit note prefix (sales returns)</Label>
                                        <Input id="sale_return_prefix" v-model="form.sale_return_prefix" type="text" placeholder="SR" />
                                        <p v-if="form.errors.sale_return_prefix" class="mt-1 text-sm text-danger">{{ form.errors.sale_return_prefix }}</p>
                                    </div>

                                    <div>
                                        <Label for="purchase_return_prefix" class="mb-1">Debit note prefix (purchase returns)</Label>
                                        <Input id="purchase_return_prefix" v-model="form.purchase_return_prefix" type="text" placeholder="PR" />
                                        <p v-if="form.errors.purchase_return_prefix" class="mt-1 text-sm text-danger">{{ form.errors.purchase_return_prefix }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="border-t border-border pt-4">
                                <p class="mb-3 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Stock & Store Policy</p>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <input id="allow_negative_stock" v-model="form.allow_negative_stock" type="checkbox" class="size-4 border-[1.5px] border-border" />
                                            <label for="allow_negative_stock" class="text-sm font-semibold text-text-base">Allow negative stock</label>
                                            <InfoTip text="When off, a sale that would drive an item's stock below zero is rejected server-side. When on, sales are allowed to oversell." />
                                        </div>
                                        <p v-if="form.errors.allow_negative_stock" class="mt-1 text-sm text-danger">{{ form.errors.allow_negative_stock }}</p>
                                    </div>

                                    <div>
                                        <div class="mb-1 flex items-center gap-1">
                                            <Label>Default store</Label>
                                            <InfoTip text="Store that sales and purchases use unless another is picked." />
                                        </div>
                                        <Select v-model="form.default_store_id" :options="storeOptions" />
                                        <p v-if="form.errors.default_store_id" class="mt-1 text-sm text-danger">{{ form.errors.default_store_id }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>
            </template>

            <!-- Tab 4: Invoice Numbering -->
            <template #numbering>
                <div class="flex flex-col gap-4">
                    <Card variant="panel" title="Invoice numbering" class="bg-bg-surface p-4 sm:p-5">
                        <p class="mb-3 text-sm text-text-muted">
                            The number the next document of each series will be issued under in
                            <template v-if="invoiceNumbering[0]?.fiscal_year">{{ invoiceNumbering[0].fiscal_year }}</template>
                            <template v-else>the open fiscal year</template>. A starting number can only be set while
                            that series has not issued anything yet: once a document carries a number, moving the
                            counter would either repeat a printed number or leave a gap in a series that has to be
                            gapless.
                        </p>
                        <p v-if="startingNumberForm.errors.next_number" class="mb-3 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                            {{ startingNumberForm.errors.next_number }}
                        </p>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[560px] text-left text-[13px]">
                                <thead class="bg-bg-subtle">
                                    <tr>
                                        <th class="px-2 py-1.5">Series</th>
                                        <th class="px-2 py-1.5">Prefix</th>
                                        <th class="px-2 py-1.5">Next number</th>
                                        <th class="px-2 py-1.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in invoiceNumbering" :key="row.voucher_type" class="border-t border-border">
                                        <td class="px-2 py-2">{{ row.label }}</td>
                                        <td class="px-2 py-2">{{ row.prefix }}</td>
                                        <td class="px-2 py-2 [font-variant-numeric:tabular-nums]">{{ row.prefix }}-{{ row.next_number }}</td>
                                        <td class="px-2 py-2 text-right">
                                            <div v-if="editingSeries === row.voucher_type" class="flex items-center justify-end gap-2">
                                                <div class="w-32">
                                                    <Input
                                                        v-model="startingNumberForm.next_number"
                                                        type="number"
                                                        min="1"
                                                        step="1"
                                                        inputmode="numeric"
                                                    />
                                                </div>
                                                <Button
                                                    variant="primary"
                                                    tone="purple"
                                                    type="button"
                                                    :disabled="startingNumberForm.processing"
                                                    @click="submitStartingNumber"
                                                >
                                                    Save
                                                </Button>
                                                <Button variant="secondary" tone="purple" type="button" @click="editingSeries = null">Cancel</Button>
                                            </div>
                                            <template v-else>
                                                <Button v-if="row.can_set" variant="secondary" tone="purple" type="button" @click="openStartingNumber(row)">
                                                    Set starting number
                                                </Button>
                                                <span v-else class="text-text-muted">Locked - already in use</span>
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </Card>
                </div>
            </template>
        </Tabs>

        <FormActions class="mt-6" :status="form.isDirty ? 'Unsaved changes' : 'All changes saved'" :emphasize="form.isDirty">
            <Button variant="secondary" tone="neutral" type="button" :disabled="!form.isDirty || form.processing" @click="discard">Discard</Button>
            <Button variant="primary" tone="purple" type="button" :loading="form.processing" :disabled="!form.isDirty" @click="submit">
                <Check class="size-4" />
                Save changes
            </Button>
        </FormActions>
    </div>
</template>
