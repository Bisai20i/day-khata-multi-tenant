<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, CalendarDays, CalendarRange, CheckCircle2, CirclePause, CircleCheck, Clock, Hourglass, Plus, Users } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';

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
useLayoutChrome('Dashboard');

const statCards = [
    { key: 'total', label: 'Total tenants', value: props.stats.total, hint: 'All companies on the platform', href: '/tenants', icon: Users, tone: 'bg-primary-tint text-primary' },
    { key: 'active', label: 'Active', value: props.stats.active, hint: 'Live and in use', href: '/tenants?status=active', icon: CircleCheck, tone: 'bg-success-bg text-success' },
    { key: 'provisioning', label: 'Provisioning', value: props.stats.provisioning, hint: 'Still being set up', href: '/tenants?status=provisioning', icon: Hourglass, tone: 'bg-warning-bg text-warning-text' },
    { key: 'suspended', label: 'Suspended', value: props.stats.suspended, hint: 'Access is blocked', href: '/tenants?status=suspended', icon: CirclePause, tone: 'bg-danger-bg text-danger' },
    { key: 'created_this_week', label: 'New this week', value: props.stats.created_this_week, hint: 'Created in the last 7 days', href: '/tenants', icon: CalendarDays, tone: 'bg-bg-muted text-text-muted' },
    { key: 'created_this_month', label: 'New this month', value: props.stats.created_this_month, hint: 'Created this calendar month', href: '/tenants', icon: CalendarRange, tone: 'bg-bg-muted text-text-muted' },
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
        >
            <Button :as="Link" href="/tenants/create" variant="primary" tone="purple">
                <Plus class="size-4" />
                New tenant
            </Button>
        </PageHeader>

        <div class="mb-8 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 md:grid-cols-3 xl:grid-cols-6">
            <Link
                v-for="card in statCards"
                :key="card.key"
                :href="card.href"
                class="group block focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                :aria-label="`${card.label}: ${card.value}. View tenants`"
            >
                <Card variant="panel" class="flex h-full flex-col bg-bg-surface p-4 transition-colors duration-150 ease-out group-hover:border-primary">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-[13px] font-semibold text-text-muted">{{ card.label }}</p>
                        <span class="flex size-8 shrink-0 items-center justify-center" :class="card.tone">
                            <component :is="card.icon" class="size-4" aria-hidden="true" />
                        </span>
                    </div>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-text-strong tabular-nums">{{ card.value }}</p>
                    <p class="mt-1 text-xs text-text-muted">{{ card.hint }}</p>
                </Card>
            </Link>
        </div>

        <h3 class="mb-3 text-sm font-bold text-text-strong">Attention needed</h3>

        <Card
            v-if="tenantsPastGracePeriod.length === 0 && trialsExpiringSoon.length === 0"
            variant="panel"
            class="mb-8 bg-bg-surface p-4 sm:p-5"
        >
            <div class="flex items-center gap-3 text-sm text-text-muted">
                <CheckCircle2 class="size-5 shrink-0 text-success" aria-hidden="true" />
                <span>All clear. No suspended tenants past their grace period and no trials expiring in the next 7 days.</span>
            </div>
        </Card>

        <div v-else class="mb-8 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card v-if="tenantsPastGracePeriod.length > 0" variant="panel" class="bg-bg-surface p-4 sm:p-5">
                <div class="mb-3 flex items-center gap-2">
                    <AlertTriangle class="size-4 text-danger" aria-hidden="true" />
                    <p class="text-sm font-bold text-text-strong">Suspended tenants past grace period</p>
                </div>
                <ul class="divide-y divide-border-soft">
                    <li v-for="tenant in tenantsPastGracePeriod" :key="tenant.id" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2.5 text-sm">
                        <Link :href="`/tenants/${tenant.id}`" class="min-w-0 font-semibold break-words text-primary hover:underline">
                            {{ tenant.company_name }}
                        </Link>
                        <Badge variant="danger" pill>Suspended {{ tenant.suspended_at }}</Badge>
                    </li>
                </ul>
            </Card>

            <Card v-if="trialsExpiringSoon.length > 0" variant="panel" class="bg-bg-surface p-4 sm:p-5">
                <div class="mb-3 flex items-center gap-2">
                    <Clock class="size-4 text-warning-text" aria-hidden="true" />
                    <p class="text-sm font-bold text-text-strong">Trials expiring within 7 days</p>
                </div>
                <ul class="divide-y divide-border-soft">
                    <li v-for="tenant in trialsExpiringSoon" :key="tenant.id" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2.5 text-sm">
                        <Link :href="`/tenants/${tenant.id}`" class="min-w-0 font-semibold break-words text-primary hover:underline">
                            {{ tenant.company_name }}
                        </Link>
                        <Badge variant="warning" pill>Expires {{ tenant.trial_ends_at }}</Badge>
                    </li>
                </ul>
            </Card>
        </div>

        <Card variant="panel" class="bg-bg-surface p-0">
            <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
                <h3 class="text-sm font-bold text-text-strong">Recent activity</h3>
                <Link href="/activity-log" class="inline-flex items-center gap-1 text-[13px] font-semibold text-primary hover:underline">
                    View all
                    <ArrowRight class="size-3.5" aria-hidden="true" />
                </Link>
            </div>
            <div v-if="recentActivity.length === 0" class="px-4 py-10 text-center text-sm text-text-muted">
                No activity recorded yet.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-soft bg-bg-subtle text-left text-[11px] font-bold tracking-[.6px] text-text-muted uppercase">
                            <th scope="col" class="px-4 py-2.5 font-bold sm:px-5">Action</th>
                            <th scope="col" class="px-4 py-2.5 font-bold">Tenant</th>
                            <th scope="col" class="hidden px-4 py-2.5 font-bold md:table-cell">Platform admin</th>
                            <th scope="col" class="px-4 py-2.5 font-bold whitespace-nowrap sm:px-5">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in recentActivity" :key="entry.id" class="border-b border-border-soft last:border-0 hover:bg-bg-subtle">
                            <td class="px-4 py-2.5 sm:px-5">
                                <Badge variant="neutral" pill class="whitespace-nowrap">{{ humanizeAction(entry.action) }}</Badge>
                            </td>
                            <td class="px-4 py-2.5 text-text-base">{{ entry.tenant ?? '-' }}</td>
                            <td class="hidden px-4 py-2.5 text-text-muted md:table-cell">{{ entry.platform_admin ?? '-' }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-text-muted tabular-nums sm:px-5">{{ entry.created_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
