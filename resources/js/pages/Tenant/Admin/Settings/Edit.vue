<script setup>
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const navItems = computed(() => navGroups(isAdmin.value));

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

const form = useForm({
    company_name: props.settings.company_name ?? '',
    address: props.settings.address ?? '',
    phone: props.settings.phone ?? '',
    email: props.settings.email ?? '',
    pan_vat_number: props.settings.pan_vat_number ?? '',
    invoice_footer_note: props.settings.invoice_footer_note ?? '',
});

function submit() {
    form.put('/settings');
}
</script>

<template>
    <AppLayout title="Settings" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Invoice Setup</h2>
        </div>

        <Card variant="panel">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="company_name" class="mb-1 block text-sm font-semibold text-text-base">Company Name</label>
                        <Input id="company_name" v-model="form.company_name" type="text" required />
                        <p v-if="form.errors.company_name" class="mt-1 text-sm text-danger">{{ form.errors.company_name }}</p>
                    </div>

                    <div>
                        <label for="pan_vat_number" class="mb-1 block text-sm font-semibold text-text-base">PAN/VAT Number</label>
                        <Input id="pan_vat_number" v-model="form.pan_vat_number" type="text" />
                        <p v-if="form.errors.pan_vat_number" class="mt-1 text-sm text-danger">{{ form.errors.pan_vat_number }}</p>
                    </div>

                    <div class="col-span-2">
                        <label for="address" class="mb-1 block text-sm font-semibold text-text-base">Address</label>
                        <Input id="address" v-model="form.address" type="text" />
                        <p v-if="form.errors.address" class="mt-1 text-sm text-danger">{{ form.errors.address }}</p>
                    </div>

                    <div>
                        <label for="phone" class="mb-1 block text-sm font-semibold text-text-base">Phone</label>
                        <Input id="phone" v-model="form.phone" type="text" />
                        <p v-if="form.errors.phone" class="mt-1 text-sm text-danger">{{ form.errors.phone }}</p>
                    </div>

                    <div>
                        <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email</label>
                        <Input id="email" v-model="form.email" type="email" />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                    </div>

                    <div class="col-span-2">
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
                </div>

                <div class="flex items-center justify-end gap-2">
                    <Button variant="primary" tone="purple" type="submit" :disabled="form.processing">Save changes</Button>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>
