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

useLayoutChrome('Aged Payables');
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
        page-title="Aged Payables"
        description="How much you owe each supplier, aged from the oldest unpaid entry in their ledger as of the chosen date."
        party-label="Supplier"
        document-label="bills"
        opening-term="opening payable"
        advance-term="payment"
        party-param="supplier_id"
        counter-report-label="Creditors"
        export-permission="payables_reports.export"
        base-path="/reports/aged-payables"
        id-prefix="aged-payables"
    />
</template>
