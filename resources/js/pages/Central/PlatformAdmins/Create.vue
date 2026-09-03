<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building2, History, LayoutDashboard, Settings as SettingsIcon, ShieldCheck } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
    { label: 'Settings', href: '/settings', icon: SettingsIcon },
    { label: 'Platform admins', href: '/platform-admins', icon: ShieldCheck },
];

const roleOptions = [
    { value: 'owner', label: 'Owner' },
    { value: 'support', label: 'Support' },
];

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'support',
});

function submit() {
    form.post('/platform-admins');
}
</script>

<template>
    <AppLayout title="New Platform Admin" :nav-items="navItems">
        <Link href="/platform-admins" class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary">
            <ArrowLeft class="size-4" />
            All platform admins
        </Link>

        <Card variant="panel" class="max-w-lg">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="name" v-model="form.name" type="text" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email</label>
                    <Input id="email" v-model="form.email" type="email" required />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="role" class="mb-1 block text-sm font-semibold text-text-base">Role</label>
                    <Select id="role" v-model="form.role" :options="roleOptions" />
                    <p v-if="form.errors.role" class="mt-1 text-sm text-danger">{{ form.errors.role }}</p>
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-semibold text-text-base">Password</label>
                    <Input id="password" v-model="form.password" type="password" required />
                    <p v-if="form.errors.password" class="mt-1 text-sm text-danger">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label for="password_confirmation" class="mb-1 block text-sm font-semibold text-text-base">Confirm password</label>
                    <Input id="password_confirmation" v-model="form.password_confirmation" type="password" required />
                </div>

                <Button type="submit" variant="primary" tone="purple" :disabled="form.processing" class="w-full">
                    Create platform admin
                </Button>
            </form>
        </Card>
    </AppLayout>
</template>
