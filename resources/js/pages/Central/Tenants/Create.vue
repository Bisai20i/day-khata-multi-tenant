<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: AppLayout });
useLayoutChrome('New Tenant');

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
    <div>
        <Link href="/tenants" class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary">
            <ArrowLeft class="size-4" />
            All tenants
        </Link>

        <Card variant="panel" class="max-w-lg">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="company_name" class="mb-1 block text-sm font-semibold text-text-base">Company name <span class="text-danger">*</span></label>
                    <Input id="company_name" v-model="form.company_name" type="text" placeholder="e.g. Acme Traders" required />
                    <p v-if="form.errors.company_name" class="mt-1 text-sm text-danger">{{ form.errors.company_name }}</p>
                </div>

                <div>
                    <label for="subdomain" class="mb-1 block text-sm font-semibold text-text-base">Subdomain <span class="text-danger">*</span></label>
                    <div class="flex items-center gap-1.5">
                        <Input id="subdomain" v-model="form.subdomain" type="text" placeholder="acme" required class="flex-1" />
                        <span class="text-sm text-text-muted">.localhost</span>
                    </div>
                    <p v-if="form.errors.subdomain" class="mt-1 text-sm text-danger">{{ form.errors.subdomain }}</p>
                </div>

                <div>
                    <label for="contact_email" class="mb-1 block text-sm font-semibold text-text-base">Contact email</label>
                    <Input id="contact_email" v-model="form.contact_email" type="email" placeholder="you@example.com" />
                    <p v-if="form.errors.contact_email" class="mt-1 text-sm text-danger">{{ form.errors.contact_email }}</p>
                </div>

                <hr class="border-border" />

                <p class="text-sm font-semibold text-text-base">First admin user</p>

                <div>
                    <label for="admin_name" class="mb-1 block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                    <Input id="admin_name" v-model="form.admin_name" type="text" placeholder="e.g. Jane Doe" required />
                    <p v-if="form.errors.admin_name" class="mt-1 text-sm text-danger">{{ form.errors.admin_name }}</p>
                </div>

                <div>
                    <label for="admin_email" class="mb-1 block text-sm font-semibold text-text-base">Email <span class="text-danger">*</span></label>
                    <Input id="admin_email" v-model="form.admin_email" type="email" placeholder="you@example.com" required />
                    <p v-if="form.errors.admin_email" class="mt-1 text-sm text-danger">{{ form.errors.admin_email }}</p>
                </div>

                <div>
                    <label for="admin_password" class="mb-1 block text-sm font-semibold text-text-base">Password <span class="text-danger">*</span></label>
                    <Input id="admin_password" v-model="form.admin_password" type="password" placeholder="Enter a password" required />
                    <p v-if="form.errors.admin_password" class="mt-1 text-sm text-danger">{{ form.errors.admin_password }}</p>
                </div>

                <Button type="submit" variant="primary" tone="purple" :loading="form.processing" class="w-full">
                    <Plus class="size-4" />
                    Create tenant
                </Button>
            </form>
        </Card>
    </div>
</template>
