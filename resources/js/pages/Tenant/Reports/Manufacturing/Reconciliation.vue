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
    reconciliation: { type: Object, required: true },
    asOf: { type: String, required: true },
});

useLayoutChrome('Inventory Reconciliation');

const asOfInput = ref(props.asOf);

function apply() {
    router.get(window.location.pathname, { to: asOfInput.value }, { preserveState: true, preserveScroll: true });
}

function fileUrl(suffix) {
    return `${window.location.pathname}/${suffix}?${new URLSearchParams({ to: asOfInput.value })}`;
}
</script>

<template>
    <div>
        <PageHeader title="Inventory Reconciliation" description="Ledger balance against subledger total for each perpetual class. The fiscal year cannot be closed while any difference is non-zero.">
            <a v-if="can('manufacturing_reports.export')" :href="fileUrl('print')" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print</Button></a>
            <a v-if="can('manufacturing_reports.export')" :href="fileUrl('export')"><Button variant="secondary" tone="purple">Export</Button></a>
        </PageHeader>

        <p class="mb-4 text-[12.5px] text-text-muted">As of BS {{ formatBsDate(asOf) }} (AD {{ asOf }})</p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-40">
                    <Label class="mb-1">As of date (BS)</Label>
                    <NepaliDateInput v-model="asOfInput" />
                </div>
                <Button variant="primary" tone="purple" @click="apply">Generate report</Button>
            </div>
        </Card>

        <div
            :class="reconciliation.balanced ? 'border-green-600 bg-green-50 text-green-800' : 'border-red-600 bg-red-50 text-red-700'"
            class="mb-4 border-[1.5px] px-4 py-2 text-[13px] font-semibold"
            role="status"
        >
            {{ reconciliation.balanced ? 'The ledger and the subledgers agree for every class.' : 'The ledger and a subledger disagree. Resolve the difference before closing the year.' }}
        </div>

        <Card variant="panel">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-border text-left text-text-muted">
                        <th class="py-2">Inventory</th>
                        <th class="py-2 text-right">Per ledger</th>
                        <th class="py-2 text-right">Per subledger</th>
                        <th class="py-2 text-right">Difference</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in reconciliation.rows" :key="row.key" class="border-b border-border/50">
                        <td class="py-2">{{ row.label }}</td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(row.gl) }}</td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(row.subledger) }}</td>
                        <td class="py-2 text-right tabular-nums" :class="row.difference !== '0.00' ? 'font-bold text-red-600' : ''">{{ formatMoney(row.difference) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </div>
</template>
