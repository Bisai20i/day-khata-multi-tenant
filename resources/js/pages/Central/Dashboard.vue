<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Building2, Clock, History, LayoutDashboard, Settings as SettingsIcon, ShieldCheck } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            total: 0,
            active: 0,
            provisioning: 0,
            suspended: 0,
            created_this_week: 0,
            created_this_month: 0,
        }),
    },
    tenantsPastGracePeriod: { type: Array, default: () => [] },
    trialsExpiringSoon: { type: Array, default: () => [] },
    recentActivity: { type: Array, default: () => [] },
});

const page = usePage();

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
    { label: 'Settings', href: '/settings', icon: SettingsIcon },
    { label: 'Platform admins', href: '/platform-admins', icon: ShieldCheck },
];

const statCards = [
    { key: 'total', label: 'Total tenants', value: props.stats.total },
    { key: 'active', label: 'Active', value: props.stats.active },
    { key: 'provisioning', label: 'Provisioning', value: props.stats.provisioning },
    { key: 'suspended', label: 'Suspended', value: props.stats.suspended },
    { key: 'created_this_week', label: 'Created this week', value: props.stats.created_this_week },
    { key: 'created_this_month', label: 'Created this month', value: props.stats.created_this_month },
];
</script>

<template>
    <AppLayout title="Platform Admin Dashboard" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Dashboard</h2>
            <p class="text-sm text-text-muted">Signed in as {{ page.props.auth.platformAdmin?.email }}</p>
        </div>

        <div class="mb-5 grid grid-cols-3 gap-4 lg:grid-cols-6">
            <Card v-for="card in statCards" :key="card.key" variant="panel">
                <p class="text-2xl font-bold text-text-strong">{{ card.value }}</p>
                <p class="text-sm text-text-muted">{{ card.label }}</p>
            </Card>
        </div>

        <Card v-if="tenantsPastGracePeriod.length > 0" variant="panel" class="mb-5">
            <div class="mb-3 flex items-center gap-2">
                <AlertTriangle class="size-4 text-danger" aria-hidden="true" />
                <p class="text-sm font-bold text-text-strong">Suspended tenants past grace period</p>
            </div>
            <ul class="flex flex-col gap-2">
                <li v-for="tenant in tenantsPastGracePeriod" :key="tenant.id" class="flex items-center justify-between text-sm">
                    <Link :href="`/tenants/${tenant.id}`" class="font-semibold text-primary hover:underline">
                        {{ tenant.company_name }}
                    </Link>
                    <Badge variant="danger" pill>Suspended {{ tenant.suspended_at }}</Badge>
                </li>
            </ul>
        </Card>

        <Card v-if="trialsExpiringSoon.length > 0" variant="panel" class="mb-5">
            <div class="mb-3 flex items-center gap-2">
                <Clock class="size-4 text-warning-text" aria-hidden="true" />
                <p class="text-sm font-bold text-text-strong">Trials expiring within 7 days</p>
            </div>
            <ul class="flex flex-col gap-2">
                <li v-for="tenant in trialsExpiringSoon" :key="tenant.id" class="flex items-center justify-between text-sm">
                    <Link :href="`/tenants/${tenant.id}`" class="font-semibold text-primary hover:underline">
                        {{ tenant.company_name }}
                    </Link>
                    <Badge variant="warning" pill>Expires {{ tenant.trial_ends_at }}</Badge>
                </li>
            </ul>
        </Card>

        <Card variant="panel" title="Recent activity">
            <div v-if="recentActivity.length === 0" class="py-6 text-center text-sm text-text-muted">
                No activity recorded yet.
            </div>
            <table v-else class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-soft text-left text-[11px] font-bold tracking-[.6px] text-text-muted uppercase">
                        <th class="pb-2 font-bold">Action</th>
                        <th class="pb-2 font-bold">Tenant</th>
                        <th class="pb-2 font-bold">Platform admin</th>
                        <th class="pb-2 font-bold">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in recentActivity" :key="entry.id" class="border-b border-border-soft last:border-0">
                        <td class="py-2">
                            <Badge variant="neutral" pill>{{ entry.action }}</Badge>
                        </td>
                        <td class="py-2 text-text-base">{{ entry.tenant ?? '—' }}</td>
                        <td class="py-2 text-text-muted">{{ entry.platform_admin ?? '—' }}</td>
                        <td class="py-2 text-text-muted">{{ entry.created_at }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-3 text-right">
                <Link href="/activity-log" class="text-sm font-semibold text-primary hover:underline">View full activity log</Link>
            </div>
        </Card>
    </AppLayout>
</template>
