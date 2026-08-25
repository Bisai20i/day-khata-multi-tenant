<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, Users } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';

const page = usePage();

const user = computed(() => page.props.auth.user);
const isAdmin = computed(() => user.value?.role?.slug === 'admin');

const navItems = computed(() => {
    const items = [{ label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard }];

    if (isAdmin.value) {
        items.push({ label: 'Manage users', href: '/admin/users', icon: Users });
    }

    return items;
});
</script>

<template>
    <AppLayout title="Dashboard" :nav-items="navItems">
        <Card variant="panel" title="Overview">
            <p class="mb-4 text-sm text-text-muted">
                Signed in as {{ user?.email }}
                <span v-if="user?.role">({{ user.role.name }})</span>
            </p>

            <Button v-if="isAdmin" :as="Link" href="/admin/users" variant="secondary" tone="purple">
                Manage users
            </Button>
        </Card>
    </AppLayout>
</template>
