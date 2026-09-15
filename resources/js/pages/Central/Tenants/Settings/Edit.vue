<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useToast } from '@/composables/useToast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
    settings: {
        type: Object,
        required: true,
    },
    stores: {
        type: Array,
        default: () => [],
    },
    // One row per independently numbered document series, with the number the
    // next document of that series will be issued under in the open fiscal
    // year. Read from VoucherSequence on the server; nothing here re-derives
    // a number.
    invoiceNumbering: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome(() => `${props.tenant.company_name} — Settings`);

const settingsTabs = [
    { value: 'company', label: 'Company Setup' },
    { value: 'invoice', label: 'Invoice Setup' },
    { value: 'stock', label: 'Stock & Discount Policy' },
];
const activeTab = ref('company');

const paperSizeOptions = [
    { value: 'a4', label: 'A4' },
    { value: 'a5', label: 'A5' },
    { value: '58mm', label: 'Thermal 58mm' },
    { value: '80mm', label: 'Thermal 80mm' },
];

const storeOptions = computed(() => [
    { value: null, label: 'None (first active store)' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

// Update redirects back to this same route/component - Inertia patches the
// already-mounted instance rather than remounting it, so watching the flash
// prop (with immediate: true to also cover the initial load) catches every
// update, not just the first one.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const settingsUrl = computed(() => `/tenants/${props.tenant.id}/settings`);

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
    sale_full_enabled: props.settings.sale_full_enabled ?? true,
    sale_abbreviated_prefix: props.settings.sale_abbreviated_prefix ?? 'SLA',
    sale_abbreviated_enabled: props.settings.sale_abbreviated_enabled ?? true,
    sale_pan_prefix: props.settings.sale_pan_prefix ?? 'SLP',
    sale_pan_enabled: props.settings.sale_pan_enabled ?? true,
    purchase_prefix: props.settings.purchase_prefix ?? 'PU',
    sale_return_prefix: props.settings.sale_return_prefix ?? 'SR',
    purchase_return_prefix: props.settings.purchase_return_prefix ?? 'PR',
});

function submit() {
    form.put(settingsUrl.value);
}

// One form reused across rows: only one starting number is ever being edited
// at a time, and the row is identified by the voucher_type it submits.
const startingNumberForm = useForm({ voucher_type: '', next_number: '' });
const editingSeries = ref(null);

function openStartingNumber(row) {
    editingSeries.value = row.voucher_type;
    startingNumberForm.clearErrors();
    startingNumberForm.voucher_type = row.voucher_type;
    startingNumberForm.next_number = String(row.next_number);
}

function submitStartingNumber() {
    startingNumberForm.post(`${settingsUrl.value}/starting-number`, {
        preserveScroll: true,
        onSuccess: () => {
            editingSeries.value = null;
            startingNumberForm.reset();
        },
    });
}

// Logo upload is a separate form/route from the main settings form (see
// TenantCompanySettingController::uploadLogo()) so a logo change doesn't
// require re-submitting the whole settings form.
const logoForm = useForm({
    logo: null,
});

// Local-only preview for the logo picker - shows the current logo when one
// exists, or a fresh blob preview once a new file is chosen. Not part of
// logoForm since the file input itself can't be pre-filled from an existing
// logo_path (browsers refuse to set <input type="file">'s value).
const logoPreviewUrl = ref(props.settings.logo_url ?? '');

function onLogoChange(event) {
    const file = event.target.files?.[0] ?? null;
    logoForm.logo = file;
    logoPreviewUrl.value = file ? URL.createObjectURL(file) : (props.settings.logo_url ?? '');
}

function submitLogo() {
    logoForm.post(`${settingsUrl.value}/logo`, {
        preserveScroll: true,
        onSuccess: () => {
            logoForm.reset();
        },
    });
}
</script>

<template>
    <div>
        <Link :href="`/tenants/${tenant.id}`" class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary">
            <ArrowLeft class="size-4" />
            Back to {{ tenant.company_name }}
        </Link>

        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">{{ tenant.company_name }} — Settings</h2>
        </div>

        <Tabs v-model="activeTab" :tabs="settingsTabs">
            <template #company>
                <div class="flex flex-col gap-4">
                    <Card variant="panel" title="Company Info">
                        <form class="flex flex-col gap-4" @submit.prevent="submit">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="company_name" class="mb-1 block text-sm font-semibold text-text-base">Company Name <span class="text-danger">*</span></label>
                                    <Input id="company_name" v-model="form.company_name" type="text" placeholder="e.g. Sharma Traders Pvt. Ltd." required />
                                    <p v-if="form.errors.company_name" class="mt-1 text-sm text-danger">{{ form.errors.company_name }}</p>
                                </div>

                                <div>
                                    <label for="pan_vat_number" class="mb-1 block text-sm font-semibold text-text-base">PAN/VAT Number</label>
                                    <Input id="pan_vat_number" v-model="form.pan_vat_number" type="text" placeholder="e.g. 123456789" />
                                    <p v-if="form.errors.pan_vat_number" class="mt-1 text-sm text-danger">{{ form.errors.pan_vat_number }}</p>
                                </div>

                                <div class="col-span-2">
                                    <label for="address" class="mb-1 block text-sm font-semibold text-text-base">Address</label>
                                    <Input id="address" v-model="form.address" type="text" placeholder="e.g. Kathmandu-10" />
                                    <p v-if="form.errors.address" class="mt-1 text-sm text-danger">{{ form.errors.address }}</p>
                                </div>

                                <div>
                                    <label for="phone" class="mb-1 block text-sm font-semibold text-text-base">Phone</label>
                                    <Input id="phone" v-model="form.phone" type="text" placeholder="98XXXXXXXX" />
                                    <p v-if="form.errors.phone" class="mt-1 text-sm text-danger">{{ form.errors.phone }}</p>
                                </div>

                                <div>
                                    <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email</label>
                                    <Input id="email" v-model="form.email" type="email" placeholder="name@example.com" />
                                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2">
                                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing">Save changes</Button>
                            </div>
                        </form>

                        <div class="mt-4 border-t border-border pt-4">
                            <label for="logo" class="mb-1 block text-sm font-semibold text-text-base">Company Logo</label>
                            <div class="flex items-end gap-4">
                                <img
                                    v-if="logoPreviewUrl"
                                    :src="logoPreviewUrl"
                                    alt="Company logo preview"
                                    class="h-16 w-16 shrink-0 border-[1.5px] border-border bg-white object-contain"
                                />
                                <div class="flex-1">
                                    <input
                                        id="logo"
                                        type="file"
                                        accept="image/*"
                                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none file:mr-3 file:border-0 file:bg-transparent file:text-[13px] file:font-semibold file:text-primary focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                                        @change="onLogoChange"
                                    />
                                    <p v-if="logoForm.errors.logo" class="mt-1 text-sm text-danger">{{ logoForm.errors.logo }}</p>
                                </div>
                                <Button
                                    variant="secondary"
                                    tone="purple"
                                    type="button"
                                    :disabled="!logoForm.logo || logoForm.processing"
                                    @click="submitLogo"
                                >
                                    Upload logo
                                </Button>
                            </div>
                        </div>
                    </Card>
                </div>
            </template>

            <template #invoice>
                <div class="flex flex-col gap-4">
                    <Card variant="panel" title="Invoicing">
                        <form class="flex flex-col gap-4" @submit.prevent="submit">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">Print Paper Size</label>
                                    <Select v-model="form.print_paper_size" :options="paperSizeOptions" />
                                    <p v-if="form.errors.print_paper_size" class="mt-1 text-sm text-danger">{{ form.errors.print_paper_size }}</p>
                                </div>

                                <div>
                                    <label for="default_vat_rate" class="mb-1 block text-sm font-semibold text-text-base">Default VAT Rate (%)</label>
                                    <Input id="default_vat_rate" v-model="form.default_vat_rate" type="number" min="0" max="100" step="0.01" placeholder="13.00" />
                                    <p v-if="form.errors.default_vat_rate" class="mt-1 text-sm text-danger">{{ form.errors.default_vat_rate }}</p>
                                </div>
                            </div>

                            <div class="border-t border-border pt-4">
                                <p class="mb-3 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Sale Invoice Types</p>
                                <div class="grid grid-cols-3 gap-4">
                                    <div>
                                        <label for="sale_full_prefix" class="mb-1 block text-sm font-semibold text-text-base">Full Invoice Prefix</label>
                                        <Input id="sale_full_prefix" v-model="form.sale_full_prefix" type="text" placeholder="SL" />
                                        <p v-if="form.errors.sale_full_prefix" class="mt-1 text-sm text-danger">{{ form.errors.sale_full_prefix }}</p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input id="sale_full_enabled" v-model="form.sale_full_enabled" type="checkbox" class="size-4 border-[1.5px] border-border" />
                                            <label for="sale_full_enabled" class="text-sm text-text-base">Enabled</label>
                                        </div>
                                    </div>

                                    <div>
                                        <label for="sale_abbreviated_prefix" class="mb-1 block text-sm font-semibold text-text-base">Abbreviated Invoice Prefix</label>
                                        <Input id="sale_abbreviated_prefix" v-model="form.sale_abbreviated_prefix" type="text" placeholder="SLA" />
                                        <p v-if="form.errors.sale_abbreviated_prefix" class="mt-1 text-sm text-danger">{{ form.errors.sale_abbreviated_prefix }}</p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input id="sale_abbreviated_enabled" v-model="form.sale_abbreviated_enabled" type="checkbox" class="size-4 border-[1.5px] border-border" />
                                            <label for="sale_abbreviated_enabled" class="text-sm text-text-base">Enabled</label>
                                        </div>
                                    </div>

                                    <div>
                                        <label for="sale_pan_prefix" class="mb-1 block text-sm font-semibold text-text-base">PAN Invoice Prefix</label>
                                        <Input id="sale_pan_prefix" v-model="form.sale_pan_prefix" type="text" placeholder="SLP" />
                                        <p v-if="form.errors.sale_pan_prefix" class="mt-1 text-sm text-danger">{{ form.errors.sale_pan_prefix }}</p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input id="sale_pan_enabled" v-model="form.sale_pan_enabled" type="checkbox" class="size-4 border-[1.5px] border-border" />
                                            <label for="sale_pan_enabled" class="text-sm text-text-base">Enabled</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border-t border-border pt-4">
                                <p class="mb-3 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Purchase and Returns</p>
                                <p class="mb-3 text-sm text-text-muted">
                                    Every series needs its own prefix. Two series sharing one print the same number on two
                                    different documents.
                                </p>
                                <div class="grid grid-cols-3 gap-4">
                                    <div>
                                        <label for="purchase_prefix" class="mb-1 block text-sm font-semibold text-text-base">Purchase Prefix</label>
                                        <Input id="purchase_prefix" v-model="form.purchase_prefix" type="text" placeholder="PU" />
                                        <p v-if="form.errors.purchase_prefix" class="mt-1 text-sm text-danger">{{ form.errors.purchase_prefix }}</p>
                                    </div>

                                    <div>
                                        <label for="sale_return_prefix" class="mb-1 block text-sm font-semibold text-text-base">Credit Note Prefix</label>
                                        <Input id="sale_return_prefix" v-model="form.sale_return_prefix" type="text" placeholder="SR" />
                                        <p v-if="form.errors.sale_return_prefix" class="mt-1 text-sm text-danger">{{ form.errors.sale_return_prefix }}</p>
                                    </div>

                                    <div>
                                        <label for="purchase_return_prefix" class="mb-1 block text-sm font-semibold text-text-base">Debit Note Prefix</label>
                                        <Input id="purchase_return_prefix" v-model="form.purchase_return_prefix" type="text" placeholder="PR" />
                                        <p v-if="form.errors.purchase_return_prefix" class="mt-1 text-sm text-danger">{{ form.errors.purchase_return_prefix }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="border-t border-border pt-4">
                                <label for="invoice_footer_note" class="mb-1 block text-sm font-semibold text-text-base">
                                    Invoice Footer Note
                                </label>
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

                            <div class="flex items-center justify-end gap-2">
                                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing">Save changes</Button>
                            </div>
                        </form>
                    </Card>

                    <Card variant="panel" title="Invoice Numbering">
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
                        <table class="w-full text-left text-[13px]">
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
                    </Card>
                </div>
            </template>

            <template #stock>
                <div class="flex flex-col gap-4">
                    <Card variant="panel" title="Stock & Discount Policy">
                        <form class="flex flex-col gap-4" @submit.prevent="submit">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <input id="allow_negative_stock" v-model="form.allow_negative_stock" type="checkbox" class="size-4 border-[1.5px] border-border" />
                                        <label for="allow_negative_stock" class="text-sm font-semibold text-text-base">Allow negative stock</label>
                                    </div>
                                    <p class="mt-1 text-sm text-text-muted">
                                        When off, a sale that would drive an item's stock below zero is rejected server-side. When on, sales are allowed to oversell.
                                    </p>
                                    <p v-if="form.errors.allow_negative_stock" class="mt-1 text-sm text-danger">{{ form.errors.allow_negative_stock }}</p>
                                </div>

                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">Default Store</label>
                                    <Select v-model="form.default_store_id" :options="storeOptions" />
                                    <p v-if="form.errors.default_store_id" class="mt-1 text-sm text-danger">{{ form.errors.default_store_id }}</p>
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2">
                                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing">Save changes</Button>
                            </div>
                        </form>
                    </Card>
                </div>
            </template>
        </Tabs>
    </div>
</template>
