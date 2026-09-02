<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Users, Truck, Package, BookOpen, Megaphone, AlertTriangle, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    notices: {
        type: Array,
        default: () => [],
    },
    kpis: {
        type: Object,
        default: () => ({
            customers: { total: 0, thisWeek: 0 },
            suppliers: { total: 0, thisWeek: 0 },
            items: { total: 0, thisWeek: 0 },
            accounts: { total: 0 },
        }),
    },
    recentCustomers: {
        type: Array,
        default: () => [],
    },
    accountHeadBreakdown: {
        type: Array,
        default: () => [],
    },
    expiringItemsCount: {
        type: Number,
        default: 0,
    },
});

// Dismissal is in-page only, not persisted anywhere - an active notice
// reappears on the next visit/reload by design (this MVP has no
// per-user "read" tracking).
const dismissedNoticeIds = ref(new Set());

const visibleNotices = computed(() => props.notices.filter((notice) => !dismissedNoticeIds.value.has(notice.id)));

function dismissNotice(id) {
    dismissedNoticeIds.value = new Set(dismissedNoticeIds.value).add(id);
}

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const navItems = computed(() => navGroups(isAdmin.value));

const kpiCards = computed(() => [
    { key: 'customers', label: 'Customers', icon: Users, total: props.kpis.customers.total, thisWeek: props.kpis.customers.thisWeek },
    { key: 'suppliers', label: 'Suppliers', icon: Truck, total: props.kpis.suppliers.total, thisWeek: props.kpis.suppliers.thisWeek },
    { key: 'items', label: 'Items', icon: Package, total: props.kpis.items.total, thisWeek: props.kpis.items.thisWeek },
    { key: 'accounts', label: 'Ledger Accounts', icon: BookOpen, total: props.kpis.accounts.total, thisWeek: null },
]);

const dotPalette = ['#6600FF', '#0EA5E9', '#F59E0B', '#10B981', '#EC4899'];

function initial(name) {
    return name?.trim()?.charAt(0)?.toUpperCase() ?? '?';
}
</script>

<template>
    <AppLayout title="Dashboard" :nav-items="navItems">
        <div v-if="visibleNotices.length" class="mb-5 flex flex-col gap-2">
            <div
                v-for="notice in visibleNotices"
                :key="notice.id"
                class="flex items-start gap-3 border-[1.5px] border-primary bg-primary-tint px-4 py-3"
            >
                <Megaphone class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-text-strong">{{ notice.title }}</p>
                    <p class="mt-0.5 text-sm whitespace-pre-line text-text-base">{{ notice.body }}</p>
                </div>
                <button
                    type="button"
                    class="shrink-0 text-text-muted transition-colors duration-150 hover:text-text-strong"
                    aria-label="Dismiss"
                    @click="dismissNotice(notice.id)"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>
        </div>

        <h2 class="mb-4 text-base font-bold text-text-strong">Dashboard</h2>

        <div class="mb-5 grid grid-cols-4 gap-4">
            <Card v-for="card in kpiCards" :key="card.key" variant="panel">
                <div class="flex items-start justify-between">
                    <div class="flex size-9 items-center justify-center bg-primary-tint">
                        <component :is="card.icon" class="size-5 text-primary" />
                    </div>
                    <span
                        v-if="card.thisWeek"
                        class="bg-success-bg px-2 py-0.5 text-[11px] font-semibold text-success"
                    >
                        +{{ card.thisWeek }} this week
                    </span>
                </div>
                <p class="mt-3 text-2xl font-bold text-text-strong">{{ card.total }}</p>
                <p class="text-sm text-text-muted">{{ card.label }}</p>
            </Card>
        </div>

        <Card v-if="expiringItemsCount > 0" variant="panel" class="mb-5">
            <div class="flex items-center gap-3">
                <div class="flex size-9 shrink-0 items-center justify-center bg-warning-bg">
                    <AlertTriangle class="size-5 text-warning-text" aria-hidden="true" />
                </div>
                <div>
                    <p class="text-sm font-bold text-text-strong">{{ expiringItemsCount }} item(s) expiring soon</p>
                    <p class="text-xs text-text-muted">Expiring within the next 30 days</p>
                </div>
            </div>
        </Card>

        <div class="grid grid-cols-[2fr_1fr] gap-5">
            <Card variant="panel" title="Recent Customers">
                <div v-if="recentCustomers.length === 0" class="py-6 text-center text-sm text-text-muted">
                    No customers yet
                </div>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-soft text-left text-[11px] font-bold tracking-[.6px] text-text-muted uppercase">
                            <th class="pb-2 font-bold">Name</th>
                            <th class="pb-2 font-bold">Mobile</th>
                            <th class="pb-2 font-bold">Ledger code</th>
                            <th class="pb-2 font-bold">Added</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="customer in recentCustomers" :key="customer.name + customer.added" class="border-b border-border-soft last:border-0">
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <span class="flex size-6 items-center justify-center rounded-full bg-primary-tint text-[11px] font-bold text-primary">
                                        {{ initial(customer.name) }}
                                    </span>
                                    <span class="text-text-base">{{ customer.name }}</span>
                                </div>
                            </td>
                            <td class="py-2 text-text-muted">{{ customer.mobile ?? '—' }}</td>
                            <td class="py-2">
                                <span class="border border-border px-1.5 py-0.5 text-xs text-text-muted">
                                    {{ customer.code ?? '—' }}
                                </span>
                            </td>
                            <td class="py-2 text-text-muted">{{ customer.added }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>

            <Card variant="panel" title="Chart of Accounts">
                <div v-if="accountHeadBreakdown.length === 0" class="py-6 text-center text-sm text-text-muted">
                    No account heads found
                </div>
                <ul v-else class="flex flex-col gap-3">
                    <li
                        v-for="(head, index) in accountHeadBreakdown"
                        :key="head.name"
                        class="flex items-center justify-between text-sm"
                    >
                        <span class="flex items-center gap-2 text-text-base">
                            <span
                                class="size-2.5 rounded-full"
                                :style="{ backgroundColor: dotPalette[index % dotPalette.length] }"
                            />
                            {{ head.name }}
                        </span>
                        <span class="font-semibold text-text-strong">{{ head.count }}</span>
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
