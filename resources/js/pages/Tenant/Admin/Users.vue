<script setup>
import { LayoutDashboard, Users } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';

const navItems = [
    { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    { label: 'Manage users', href: '/admin/users', icon: Users },
];
</script>

<template>
    <AppLayout title="Manage Users" :nav-items="navItems">
        <Card variant="panel">
            <p class="text-sm text-text-muted">
                Admin-only stub page. Restricted by the "role:admin" middleware.
            </p>
        </Card>
    </AppLayout>
</template>
