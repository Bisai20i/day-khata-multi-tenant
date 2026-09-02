<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Button from '@/components/ui/Button.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    vouchers: { type: Array, default: () => [] },
    totalDebit: { type: Number, default: 0 },
    totalCredit: { type: Number, default: 0 },
    from: { type: String, required: true },
    to: { type: String, required: true },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const from = ref(props.from);
const to = ref(props.to);

function applyFilter() {
    router.get(window.location.pathname, { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true });
}

const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
    sale: 'Sale',
    sale_abbreviated: 'Sale (Abbreviated)',
    sale_return: 'Sale Return',
    purchase: 'Purchase',
    purchase_return: 'Purchase Return',
};

function voucherLabel(voucher) {
    return `${voucherTypeLabels[voucher.voucherType] ?? voucher.voucherType} #${voucher.voucherNumber}`;
}

function money(value) {
    return Number(value ?? 0).toFixed(2);
}
</script>

<template>
    <AppLayout title="Day Book" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Day Book</h2>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <p v-if="vouchers.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No vouchers posted in this range.
            </p>

            <div v-else class="divide-y divide-border">
                <div v-for="voucher in vouchers" :key="`${voucher.voucherType}-${voucher.voucherNumber}-${voucher.date}`" class="py-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 px-1 pb-1">
                        <div class="text-[13px] font-bold text-text-strong">
                            {{ voucher.date }} · {{ voucherLabel(voucher) }}
                        </div>
                        <div class="text-[12.5px] text-text-muted">{{ voucher.narration ?? '—' }}</div>
                    </div>

                    <div class="flex items-center px-1 pb-1 pl-4 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <div class="flex-1">Account</div>
                        <div class="w-40 text-text-muted normal-case">Narration</div>
                        <div class="w-28 text-right">Debit</div>
                        <div class="w-28 text-right">Credit</div>
                    </div>

                    <div
                        v-for="(line, index) in voucher.lines"
                        :key="index"
                        class="flex items-center px-1 py-1 pl-4 text-[13px] text-text-base"
                    >
                        <div class="flex-1">{{ line.accountName }} <span class="text-text-muted">· {{ line.accountCode ?? '—' }}</span></div>
                        <div class="w-40 truncate text-[12.5px] text-text-muted">{{ line.narration ?? '—' }}</div>
                        <div class="w-28 text-right">{{ line.debit > 0 ? money(line.debit) : '—' }}</div>
                        <div class="w-28 text-right">{{ line.credit > 0 ? money(line.credit) : '—' }}</div>
                    </div>
                </div>
            </div>

            <div v-if="vouchers.length > 0" class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total Debit:</span> <span class="font-semibold">{{ money(totalDebit) }}</span></div>
                <div><span class="text-text-muted">Total Credit:</span> <span class="font-semibold">{{ money(totalCredit) }}</span></div>
            </div>
        </Card>
    </AppLayout>
</template>
