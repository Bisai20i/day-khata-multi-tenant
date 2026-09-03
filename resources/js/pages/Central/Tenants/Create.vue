<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building2, History, LayoutDashboard } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
];

const form = useForm({
    company_name: '',
    subdomain: '',
    contact_email: '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
});

function submit() {
    form.post('/tenants');
}
</script>

<template>
    <AppLayout title="New Tenant" :nav-items="navItems">
        <Link href="/tenants" class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary">
            <ArrowLeft class="size-4" />
            All tenants
        </Link>

        <Card variant="panel" class="max-w-lg">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="company_name" class="mb-1 block text-sm font-semibold text-text-base">Company name</label>
                    <Input id="company_name" v-model="form.company_name" type="text" required />
                    <p v-if="form.errors.company_name" class="mt-1 text-sm text-danger">{{ form.errors.company_name }}</p>
                </div>

                <div>
                    <label for="subdomain" class="mb-1 block text-sm font-semibold text-text-base">Subdomain</label>
                    <div class="flex items-center gap-1.5">
                        <Input id="subdomain" v-model="form.subdomain" type="text" required class="flex-1" />
                        <span class="text-sm text-text-muted">.localhost</span>
                    </div>
                    <p v-if="form.errors.subdomain" class="mt-1 text-sm text-danger">{{ form.errors.subdomain }}</p>
                </div>

                <div>
                    <label for="contact_email" class="mb-1 block text-sm font-semibold text-text-base">Contact email</label>
                    <Input id="contact_email" v-model="form.contact_email" type="email" />
                    <p v-if="form.errors.contact_email" class="mt-1 text-sm text-danger">{{ form.errors.contact_email }}</p>
                </div>

                <hr class="border-border" />

                <p class="text-sm font-semibold text-text-base">First admin user</p>

                <div>
                    <label for="admin_name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="admin_name" v-model="form.admin_name" type="text" required />
                    <p v-if="form.errors.admin_name" class="mt-1 text-sm text-danger">{{ form.errors.admin_name }}</p>
                </div>

                <div>
                    <label for="admin_email" class="mb-1 block text-sm font-semibold text-text-base">Email</label>
                    <Input id="admin_email" v-model="form.admin_email" type="email" required />
                    <p v-if="form.errors.admin_email" class="mt-1 text-sm text-danger">{{ form.errors.admin_email }}</p>
                </div>

                <div>
                    <label for="admin_password" class="mb-1 block text-sm font-semibold text-text-base">Password</label>
                    <Input id="admin_password" v-model="form.admin_password" type="password" required />
                    <p v-if="form.errors.admin_password" class="mt-1 text-sm text-danger">{{ form.errors.admin_password }}</p>
                </div>

                <Button type="submit" variant="primary" tone="purple" :disabled="form.processing" class="w-full">
                    Create tenant
                </Button>
            </form>
        </Card>
    </AppLayout>
</template>
