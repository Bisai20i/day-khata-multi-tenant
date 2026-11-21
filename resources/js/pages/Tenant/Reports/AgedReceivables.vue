<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import AgedPartyReport from '@/components/parties/AgedPartyReport.vue';

defineOptions({ layout: AppLayout });

// Every prop the controller sends is declared (an undeclared one would render
// as an HTML attribute) and forwarded to the shared report.
defineProps({
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: undefined },
    advances: { type: String, default: '0.00' },
    parties: { type: Array, default: () => [] },
    partyId: { type: [Number, null], default: null },
    partyName: { type: [String, null], default: null },
    hideZero: { type: Boolean, default: true },
    asOf: { type: String, required: true },
    fiscalYearId: { type: [Number, null], default: null },
    areas: { type: Array, default: () => [] },
    area: { type: String, default: '' },
    areaLabel: { type: [String, null], default: null },
    groupByArea: { type: Boolean, default: false },
    groups: { type: [Array, null], default: null },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Aged Receivables');
</script>

<template>
    <AgedPartyReport
        :rows="rows"
        :totals="totals"
        :advances="advances"
        :parties="parties"
        :party-id="partyId"
        :party-name="partyName"
        :hide-zero="hideZero"
        :as-of="asOf"
        :fiscal-year-id="fiscalYearId"
        :areas="areas"
        :area="area"
        :area-label="areaLabel"
        :group-by-area="groupByArea"
        :groups="groups"
        :branch-report="branchReport"
        page-title="Aged Receivables"
        description="How much each customer owes you, aged from the oldest unpaid entry in their ledger as of the chosen date."
        party-label="Customer"
        document-label="invoices"
        opening-term="opening due"
        advance-term="receipt"
        party-param="customer_id"
        counter-report-label="Debtors"
        export-permission="receivables_reports.export"
        base-path="/reports/aged-receivables"
        id-prefix="aged-receivables"
    />
</template>
