<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building2, History, LayoutDashboard } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
});

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
];

const form = useForm({
    company_name: props.tenant.company_name,
    contact_email: props.tenant.contact_email ?? '',
});

function submit() {
    form.put(`/tenants/${props.tenant.id}`);
}
</script>

<template>
    <AppLayout :title="`Edit ${tenant.company_name}`" :nav-items="navItems">
        <Link :href="`/tenants/${tenant.id}`" class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary">
            <ArrowLeft class="size-4" />
            Back to tenant
        </Link>

        <Card variant="panel" class="max-w-lg">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="company_name" class="mb-1 block text-sm font-semibold text-text-base">Company name</label>
                    <Input id="company_name" v-model="form.company_name" type="text" required />
                    <p v-if="form.errors.company_name" class="mt-1 text-sm text-danger">{{ form.errors.company_name }}</p>
                </div>

                <div>
                    <label for="contact_email" class="mb-1 block text-sm font-semibold text-text-base">Contact email</label>
                    <Input id="contact_email" v-model="form.contact_email" type="email" />
                    <p v-if="form.errors.contact_email" class="mt-1 text-sm text-danger">{{ form.errors.contact_email }}</p>
                </div>

                <div class="flex gap-2">
                    <Button type="submit" variant="primary" tone="purple" :disabled="form.processing" class="flex-1">
                        Save
                    </Button>
                    <Button :as="Link" :href="`/tenants/${tenant.id}`" variant="secondary" tone="purple" class="flex-1 justify-center">
                        Cancel
                    </Button>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>
