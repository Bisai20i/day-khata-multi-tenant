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
    asOf: { type: String, required: true },
});

useLayoutChrome('Inventory Schedule');

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
        <PageHeader title="Inventory Schedule" description="Note to the Balance Sheet: stock by class, with the total of the Balance Sheet's stock lines.">
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
                    <tr v-for="row in schedule.rows" :key="row.key" class="border-b border-border/50">
                        <td class="py-2">{{ row.label }}</td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(row.gl) }}</td>
                        <td class="py-2 text-right tabular-nums">{{ row.subledger === null ? '-' : formatMoney(row.subledger) }}</td>
                        <td class="py-2 text-right tabular-nums" :class="row.difference !== null && row.difference !== '0.00' ? 'font-bold text-red-600' : ''">
                            {{ row.difference === null ? '-' : formatMoney(row.difference) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="font-bold">
                        <td class="py-2">Total inventory</td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(schedule.total) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </Card>
    </div>
</template>
