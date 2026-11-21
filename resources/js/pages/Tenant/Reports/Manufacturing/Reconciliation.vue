<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
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
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    reconciliation: { type: Object, required: true },
    asOf: { type: String, required: true },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Inventory Reconciliation');

const asOfInput = ref(props.asOf);

// Follow what the server applied (it fills in the fiscal-year default).
watch(
    () => props.asOf,
    () => {
        asOfInput.value = props.asOf;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport);

// A malformed filter (a bad date) redirects back with validation errors; show them
// instead of leaving the page looking unchanged.
const page = usePage();
const filterErrors = computed(() => ['from', 'to'].map((key) => page.props.errors?.[key]).filter(Boolean));

function apply() {
    router.get(window.location.pathname, { to: asOfInput.value, ...branchFilter.toQuery() }, { preserveState: true, preserveScroll: true });
}

// Print and Export follow the applied props (what is on screen), never the unapplied input.
function fileUrl(suffix) {
    const params = new URLSearchParams({ to: props.asOf });
    branchFilter.appendTo(params);
    return `/reports/manufacturing/reconciliation/${suffix}?${params}`;
}

const printUrl = computed(() => fileUrl('print'));
const exportUrl = computed(() => fileUrl('export'));
</script>

<template>
    <div>
        <PageHeader title="Inventory Reconciliation" description="Ledger balance against subledger total for each perpetual class. The fiscal year cannot be closed while any difference is non-zero.">
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="mfg-reconciliation"
                hide-label
                @change="apply"
            />
            <Button v-if="can('manufacturing_reports.export')" as="a" :href="printUrl" target="_blank" rel="noopener" variant="secondary" tone="purple">Print</Button>
            <Button v-if="can('manufacturing_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">Export to Excel</Button>
        </PageHeader>

        <div
            v-if="filterErrors.length"
            class="mb-4 border-[1.5px] border-red-600 bg-red-50 px-4 py-2 text-[13px] font-semibold text-red-700"
            role="alert"
        >
            <p v-for="message in filterErrors" :key="message">{{ message }}</p>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">As of BS {{ formatBsDate(asOf) }} (AD {{ asOf }})</p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-40">
                    <Label class="mb-1">As of date (BS)</Label>
                    <NepaliDateInput v-model="asOfInput" />
                </div>
                <Button variant="primary" tone="purple" @click="apply">Generate report</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <div
            :class="reconciliation.balanced ? 'border-green-600 bg-green-50 text-green-800' : 'border-red-600 bg-red-50 text-red-700'"
            class="mb-4 border-[1.5px] px-4 py-2 text-[13px] font-semibold"
            role="status"
        >
            <template v-if="reconciliation.balanced">Reconciles: the ledger and the subledgers agree for every class.</template>
            <template v-else>
                Difference in {{ reconciliation.differingCount }} {{ reconciliation.differingCount === 1 ? 'class' : 'classes' }}: the ledger and the
                subledger disagree. Resolve it before closing the year.
            </template>
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
                <tfoot v-if="reconciliation.totals">
                    <tr class="font-bold">
                        <td class="py-2">Total</td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(reconciliation.totals.gl) }}</td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(reconciliation.totals.subledger) }}</td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(reconciliation.totals.difference) }}</td>
                    </tr>
                </tfoot>
            </table>
            <p class="mt-2 text-[12px] text-text-muted">The status is decided class by class, so differences that cancel out in the total still show.</p>
        </Card>
    </div>
</template>
