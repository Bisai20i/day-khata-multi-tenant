<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PartyBalanceReport from '@/components/parties/PartyBalanceReport.vue';

defineOptions({ layout: AppLayout });

// Every prop the controller sends is declared so none leaks onto the root element as an attribute.
defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: String, default: '0.00' },
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    creditBalanceNote: { type: String, default: '' },
    areas: { type: Array, default: () => [] },
    area: { type: String, default: '' },
    areaLabel: { type: [String, null], default: null },
    groupByArea: { type: Boolean, default: false },
    groups: { type: [Array, null], default: null },
    // Branch filter and by-branch balance columns; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Debtors');
</script>

<template>
    <PartyBalanceReport
        :rows="rows"
        :total="total"
        :fiscal-years="fiscalYears"
        :fiscal-year-id="fiscalYearId"
        :credit-balance-note="creditBalanceNote"
        :areas="areas"
        :area="area"
        :area-label="areaLabel"
        :group-by-area="groupByArea"
        :groups="groups"
        :branch-report="branchReport"
        page-title="Debtors"
        description="Every account under Sundry Debtors (customers and any other account filed there) with its balance. Ties to the Balance Sheet's Sundry Debtors line and includes migrated opening dues no invoice explains."
        party-label="Customer"
        empty-message="No customer owes a balance in this fiscal year. Try another fiscal year."
        empty-area-message='No customer in "{area}" owes a balance in this fiscal year.'
        :credit-normal="false"
        export-permission="receivables_reports.export"
        base-path="/reports/debtors"
        id-prefix="debtors"
    />
</template>
