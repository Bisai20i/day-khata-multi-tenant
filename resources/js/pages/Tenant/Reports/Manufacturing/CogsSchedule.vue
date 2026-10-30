<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import { usePermissions } from '@/composables/usePermissions';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Label from '@/components/ui/Label.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    schedule: { type: Object, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
});

useLayoutChrome('COGM and COGS Schedule');

const from = ref(props.from);
const to = ref(props.to);

function apply() {
    router.get(window.location.pathname, { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true });
}

function fileUrl(suffix) {
    return `${window.location.pathname}/${suffix}?${new URLSearchParams({ from: from.value, to: to.value })}`;
}

const rowClass = (style) => ({
    'font-semibold': style === 'subtotal',
    'font-bold border-t border-border': style === 'total',
    'font-bold text-red-600': style === 'alert',
});
</script>

<template>
    <div>
        <PageHeader title="COGM and COGS Schedule" description="Note to the Income Statement: raw material consumed, cost of goods manufactured and cost of goods sold.">
            <a v-if="can('manufacturing_reports.export')" :href="fileUrl('print')" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print</Button></a>
            <a v-if="can('manufacturing_reports.export')" :href="fileUrl('export')"><Button variant="secondary" tone="purple">Export</Button></a>
        </PageHeader>

        <p class="mb-4 text-[12.5px] text-text-muted">
            BS {{ formatBsDate(schedule.from) }} to BS {{ formatBsDate(schedule.to) }} (AD {{ schedule.from }} to {{ schedule.to }})
        </p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <Label class="mb-1">From (BS)</Label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <Label class="mb-1">To (BS)</Label>
                    <NepaliDateInput v-model="to" />
                </div>
                <Button variant="primary" tone="purple" @click="apply">Generate report</Button>
            </div>
        </Card>

        <div
            v-if="!schedule.reconciled"
            class="mb-4 border-[1.5px] border-red-600 bg-red-50 px-4 py-2 text-[13px] font-semibold text-red-700"
            role="alert"
        >
            The computed cost of goods sold does not match the ledger (EXE10) by {{ formatMoney(schedule.difference) }}. Review the reconciling line below before relying on this schedule.
        </div>

        <Card variant="panel">
            <table class="w-full text-[13px]">
                <tbody>
                    <tr v-for="line in schedule.lines" :key="line.key" class="border-b border-border/50" :class="rowClass(line.style)">
                        <td class="py-2">{{ line.label }}</td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(line.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </div>
</template>
