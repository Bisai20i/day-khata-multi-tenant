<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, History, LayoutDashboard } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';

const page = usePage();

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
];
</script>

<template>
    <AppLayout title="Platform Admin Dashboard" :nav-items="navItems">
        <Card variant="panel" title="Overview">
            <p class="mb-4 text-sm text-text-muted">
                Signed in as {{ page.props.auth.platformAdmin?.email }}
            </p>

            <Button :as="Link" href="/tenants" variant="secondary" tone="purple">View tenants</Button>
        </Card>
    </AppLayout>
</template>
