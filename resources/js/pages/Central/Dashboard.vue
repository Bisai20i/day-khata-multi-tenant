<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CalendarDays, CalendarRange, CheckCircle2, CirclePause, CircleCheck, Clock, Hourglass, Users } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

defineOptions({ layout: AppLayout });

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
useLayoutChrome('Platform Admin Dashboard');

const statCards = [
    { key: 'total', label: 'Total tenants', value: props.stats.total, hint: 'All companies on the platform', href: '/tenants', icon: Users },
    { key: 'active', label: 'Active', value: props.stats.active, hint: 'Live and in use', href: '/tenants?status=active', icon: CircleCheck },
    { key: 'provisioning', label: 'Provisioning', value: props.stats.provisioning, hint: 'Still being set up', href: '/tenants?status=provisioning', icon: Hourglass },
    { key: 'suspended', label: 'Suspended', value: props.stats.suspended, hint: 'Access is blocked', href: '/tenants?status=suspended', icon: CirclePause },
    { key: 'created_this_week', label: 'New this week', value: props.stats.created_this_week, hint: 'Created in the last 7 days', href: '/tenants', icon: CalendarDays },
    { key: 'created_this_month', label: 'New this month', value: props.stats.created_this_month, hint: 'Created this calendar month', href: '/tenants', icon: CalendarRange },
];

/**
 * Turns an audit action key such as "tenant.suspended" into "Tenant suspended".
 */
function humanizeAction(action) {
    const text = String(action ?? '').replace(/[._-]+/g, ' ').trim();

    return text ? text.charAt(0).toUpperCase() + text.slice(1) : '-';
}
</script>

<template>
    <div>
        <PageHeader
            title="Dashboard"
            :description="`Overview of all tenants and recent platform activity. Signed in as ${page.props.auth.platformAdmin?.email ?? 'platform admin'}.`"
        />

        <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
            <Link
                v-for="card in statCards"
                :key="card.key"
                :href="card.href"
                class="block focus-visible:outline-2 focus-visible:outline-primary"
                :aria-label="`${card.label}: ${card.value}. View tenants`"
            >
                <Card variant="panel" class="h-full transition-colors duration-150 ease-out hover:border-primary">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-sm font-semibold text-text-muted">{{ card.label }}</p>
                        <component :is="card.icon" class="size-4 text-primary" aria-hidden="true" />
                    </div>
                    <p class="text-2xl font-bold text-text-strong">{{ card.value }}</p>
                    <p class="mt-1 text-xs text-text-muted">{{ card.hint }}</p>
                </Card>
            </Link>
        </div>

        <h3 class="mb-3 text-sm font-bold text-text-strong">Attention needed</h3>

        <Card
            v-if="tenantsPastGracePeriod.length === 0 && trialsExpiringSoon.length === 0"
            variant="panel"
            class="mb-6"
        >
            <div class="flex items-center gap-3 py-2 text-sm text-text-muted">
                <CheckCircle2 class="size-5 text-success" aria-hidden="true" />
                <span>All clear. No suspended tenants past their grace period and no trials expiring in the next 7 days.</span>
            </div>
        </Card>

        <div v-else class="mb-6 grid gap-4 lg:grid-cols-2">
            <Card v-if="tenantsPastGracePeriod.length > 0" variant="panel">
                <div class="mb-3 flex items-center gap-2">
                    <AlertTriangle class="size-4 text-danger" aria-hidden="true" />
                    <p class="text-sm font-bold text-text-strong">Suspended tenants past grace period</p>
                </div>
                <ul class="flex flex-col gap-2">
                    <li v-for="tenant in tenantsPastGracePeriod" :key="tenant.id" class="flex items-center justify-between gap-3 text-sm">
                        <Link :href="`/tenants/${tenant.id}`" class="font-semibold text-primary hover:underline">
                            {{ tenant.company_name }}
                        </Link>
                        <Badge variant="danger" pill>Suspended {{ tenant.suspended_at }}</Badge>
                    </li>
                </ul>
            </Card>

            <Card v-if="trialsExpiringSoon.length > 0" variant="panel">
                <div class="mb-3 flex items-center gap-2">
                    <Clock class="size-4 text-warning-text" aria-hidden="true" />
                    <p class="text-sm font-bold text-text-strong">Trials expiring within 7 days</p>
                </div>
                <ul class="flex flex-col gap-2">
                    <li v-for="tenant in trialsExpiringSoon" :key="tenant.id" class="flex items-center justify-between gap-3 text-sm">
                        <Link :href="`/tenants/${tenant.id}`" class="font-semibold text-primary hover:underline">
                            {{ tenant.company_name }}
                        </Link>
                        <Badge variant="warning" pill>Expires {{ tenant.trial_ends_at }}</Badge>
                    </li>
                </ul>
            </Card>
        </div>

        <Card variant="panel" title="Recent activity">
            <div v-if="recentActivity.length === 0" class="py-6 text-center text-sm text-text-muted">
                No activity recorded yet.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
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
                                <Badge variant="neutral" pill>{{ humanizeAction(entry.action) }}</Badge>
                            </td>
                            <td class="py-2 text-text-base">{{ entry.tenant ?? '-' }}</td>
                            <td class="py-2 text-text-muted">{{ entry.platform_admin ?? '-' }}</td>
                            <td class="py-2 text-text-muted">{{ entry.created_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 text-right">
                <Link href="/activity-log" class="text-sm font-semibold text-primary hover:underline">View full activity log</Link>
            </div>
        </Card>
    </div>
</template>
