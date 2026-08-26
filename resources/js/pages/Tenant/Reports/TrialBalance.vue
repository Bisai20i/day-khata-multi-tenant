<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    heads: { type: Array, default: () => [] },
    totalDebit: { type: Number, default: 0 },
    totalCredit: { type: Number, default: 0 },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

function onFiscalYearChange(value) {
    router.get(window.location.pathname, { fiscal_year_id: value }, { preserveState: true, preserveScroll: true });
}

function money(value) {
    return Number(value ?? 0).toFixed(2);
}
</script>

<template>
    <AppLayout title="Trial Balance" :nav-items="navItems">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold text-text-strong">Trial Balance</h2>
            <div class="w-56" v-if="fiscalYearId !== null">
                <Select :model-value="fiscalYearId" :options="fiscalYearOptions" @update:model-value="onFiscalYearChange" />
            </div>
        </div>

        <Card variant="panel">
            <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No fiscal year has been created yet.
            </p>

            <template v-else>
                <p v-if="heads.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                    No activity in this fiscal year.
                </p>

                <div v-else class="divide-y divide-border">
                    <div class="flex items-center px-1 pb-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <div class="flex-1">Account</div>
                        <div class="w-28 text-right">Debit</div>
                        <div class="w-28 text-right">Credit</div>
                    </div>

                    <template v-for="head in heads" :key="head.name">
                        <div class="px-1 py-1.5 text-[13px] font-bold text-text-strong">{{ head.name }}</div>

                        <template v-for="group in head.groups" :key="group.name">
                            <div class="px-1 py-1 pl-4 text-[13px] font-semibold text-text-base">{{ group.name }}</div>

                            <div
                                v-for="account in group.accounts"
                                :key="`acc-${account.id}`"
                                class="flex items-center px-1 py-1 pl-8 text-[13px] text-text-base"
                            >
                                <div class="flex-1">{{ account.name }} <span class="text-text-muted">· {{ account.code ?? '—' }}</span></div>
                                <div class="w-28 text-right">{{ account.debit > 0 ? money(account.debit) : '—' }}</div>
                                <div class="w-28 text-right">{{ account.credit > 0 ? money(account.credit) : '—' }}</div>
                            </div>

                            <template v-for="subgroup in group.subgroups" :key="subgroup.name">
                                <div class="px-1 py-1 pl-8 text-[13px] font-semibold text-text-base">{{ subgroup.name }}</div>

                                <div
                                    v-for="account in subgroup.accounts"
                                    :key="`acc-${account.id}`"
                                    class="flex items-center px-1 py-1 pl-12 text-[13px] text-text-base"
                                >
                                    <div class="flex-1">{{ account.name }} <span class="text-text-muted">· {{ account.code ?? '—' }}</span></div>
                                    <div class="w-28 text-right">{{ account.debit > 0 ? money(account.debit) : '—' }}</div>
                                    <div class="w-28 text-right">{{ account.credit > 0 ? money(account.credit) : '—' }}</div>
                                </div>
                            </template>
                        </template>
                    </template>

                    <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">Total</div>
                        <div class="w-28 text-right">{{ money(totalDebit) }}</div>
                        <div class="w-28 text-right">{{ money(totalCredit) }}</div>
                    </div>
                </div>
            </template>
        </Card>
    </AppLayout>
</template>
