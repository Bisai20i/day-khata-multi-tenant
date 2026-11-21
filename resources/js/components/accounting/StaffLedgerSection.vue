<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import { compareMoney, formatMoney, sumMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    staff: { type: Object, required: true },
    staffSalaryEntries: { type: Array, default: () => [] },
    staffAdvanceEntries: { type: Array, default: () => [] },
    staffOpeningSalary: { type: String, default: '0.00' },
    staffOpeningAdvance: { type: String, default: '0.00' },
    staffClosingSalary: { type: String, default: '0.00' },
    staffClosingAdvance: { type: String, default: '0.00' },
    from: { type: [String, null], default: null },
});

const { can } = usePermissions();

// Period totals (exact decimal strings, never floats)
const staffTotalSalaryDebit = computed(() => sumMoney(props.staffSalaryEntries.map((e) => e.debit || '0.00')));
const staffTotalSalaryCredit = computed(() => sumMoney(props.staffSalaryEntries.map((e) => e.credit || '0.00')));
const staffTotalAdvanceDebit = computed(() => sumMoney(props.staffAdvanceEntries.map((e) => e.debit || '0.00')));
const staffTotalAdvanceCredit = computed(() => sumMoney(props.staffAdvanceEntries.map((e) => e.credit || '0.00')));

const staffTableHeadClass = 'px-3 py-2 text-[11px] font-bold tracking-wider text-text-muted uppercase whitespace-nowrap bg-bg-subtle';
const staffTableCellClass = 'border-t border-border px-3 py-2.5 text-[12.5px] align-middle';
const staffTableNumClass = `${staffTableCellClass} text-right whitespace-nowrap [font-variant-numeric:tabular-nums]`;
const staffTableTotalClass = 'border-t-2 border-border bg-primary-tint/30 px-3 py-2 text-xs font-bold text-text-strong';
</script>

<template>
    <div class="space-y-4">
        <!-- Staff Summary Banner -->
        <Card variant="panel" class="border-primary/20 bg-primary/5 p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="rounded bg-primary px-2 py-0.5 text-xs font-bold text-white uppercase tracking-wider">Staff</span>
                        <h2 class="text-base font-bold text-text-strong">{{ staff.name }}</h2>
                        <span class="text-xs text-text-muted">({{ staff.staff_code }})</span>
                    </div>
                    <p v-if="staff.designation || staff.department" class="mt-1 text-xs text-text-muted">
                        {{ [staff.designation, staff.department].filter(Boolean).join(' • ') }}
                    </p>
                </div>

                <div v-if="can('hr_reports.view')" class="flex items-center gap-3">
                    <Button :as="Link" :href="`/hr/staff/${staff.id}/statement`" variant="secondary" tone="purple" class="text-xs">
                        Open Full HR Statement &rarr;
                    </Button>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 border-t border-primary/10 pt-3 sm:grid-cols-4">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Salary Payable</p>
                    <p class="mt-0.5 text-sm font-bold text-text-strong">Rs. {{ formatMoney(staffClosingSalary) }}</p>
                    <p class="text-[11px] text-text-muted">Business owes staff</p>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Staff Advance</p>
                    <p class="mt-0.5 text-sm font-bold text-text-strong">Rs. {{ formatMoney(staffClosingAdvance) }}</p>
                    <p class="text-[11px] text-text-muted">Staff owes business</p>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Period Salary Paid</p>
                    <p class="mt-0.5 text-xs font-semibold text-primary">Rs. {{ formatMoney(staffTotalSalaryDebit) }}</p>
                    <p class="text-[11px] text-text-muted">Earned: Rs. {{ formatMoney(staffTotalSalaryCredit) }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Period Advances Given</p>
                    <p class="mt-0.5 text-xs font-semibold text-danger">Rs. {{ formatMoney(staffTotalAdvanceDebit) }}</p>
                    <p class="text-[11px] text-text-muted">Recovered: Rs. {{ formatMoney(staffTotalAdvanceCredit) }}</p>
                </div>
            </div>
        </Card>

        <!-- 1. Salary Payable Ledger -->
        <Card variant="panel" class="overflow-hidden">
            <div class="border-b border-border bg-bg-subtle/50 px-4 py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-bold text-text-strong">Salary Payable Ledger</h3>
                        <p class="text-xs text-text-muted">Credit is net salary earned; debit is salary paid. Balance is the amount currently payable.</p>
                    </div>
                    <span class="text-xs font-semibold text-text-muted">
                        Closing Balance: <span class="font-bold text-text-strong">Rs. {{ formatMoney(staffClosingSalary) }}</span>
                    </span>
                </div>
            </div>

            <div class="w-full overflow-x-auto">
                <table class="w-full border-collapse text-[12.5px] text-text-base">
                    <thead>
                        <tr>
                            <th scope="col" :class="[staffTableHeadClass, 'text-left']">Date (BS)</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-left']">Date (AD)</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-left']">Reference</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-left']">Particulars / Description</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-right']">Debit (Rs.)</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-right']">Credit (Rs.)</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-right']">Balance (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="from" class="bg-primary-tint/20">
                            <td colspan="6" class="border-t border-border px-3 py-2 text-xs font-semibold text-text-strong">
                                Opening balance <span class="font-normal text-text-muted">(before {{ formatBsDate(from) }} / {{ from }})</span>
                            </td>
                            <td class="border-t border-border px-3 py-2 text-right text-xs font-bold text-text-strong whitespace-nowrap">
                                Rs. {{ formatMoney(staffOpeningSalary) }}
                            </td>
                        </tr>
                        <tr v-if="staffSalaryEntries.length === 0">
                            <td colspan="7" class="px-4 py-6 text-center text-xs font-medium text-text-muted">
                                No salary entries found in this period.
                            </td>
                        </tr>
                        <tr v-for="(entry, index) in staffSalaryEntries" :key="`salary-${index}`" class="hover:bg-bg-subtle/50 transition-colors">
                            <td :class="[staffTableCellClass, 'whitespace-nowrap font-medium text-text-strong']">
                                {{ entry.date_bs ? formatBsDate(entry.date_bs) : formatBsDate(entry.date) }}
                            </td>
                            <td :class="[staffTableCellClass, 'whitespace-nowrap text-text-muted']">{{ entry.date }}</td>
                            <td :class="[staffTableCellClass, 'whitespace-nowrap font-medium']">{{ entry.reference ?? '-' }}</td>
                            <td :class="staffTableCellClass">{{ entry.description }}</td>
                            <td :class="staffTableNumClass">
                                {{ compareMoney(entry.debit, '0.00') > 0 ? formatMoney(entry.debit) : '-' }}
                            </td>
                            <td :class="staffTableNumClass">
                                {{ compareMoney(entry.credit, '0.00') > 0 ? formatMoney(entry.credit) : '-' }}
                            </td>
                            <td :class="[staffTableNumClass, 'font-bold text-text-strong']">
                                Rs. {{ formatMoney(entry.balance) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" :class="staffTableTotalClass">Closing Balance (Salary Payable)</td>
                            <td :class="[staffTableTotalClass, 'text-right whitespace-nowrap']">
                                Rs. {{ formatMoney(staffClosingSalary) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>

        <!-- 2. Staff Advance Ledger -->
        <Card variant="panel" class="overflow-hidden">
            <div class="border-b border-border bg-bg-subtle/50 px-4 py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-bold text-text-strong">Staff Advance Ledger</h3>
                        <p class="text-xs text-text-muted">Debit is advance given; credit is advance recovered in payroll. Balance is the amount to recover.</p>
                    </div>
                    <span class="text-xs font-semibold text-text-muted">
                        Closing Balance: <span class="font-bold text-text-strong">Rs. {{ formatMoney(staffClosingAdvance) }}</span>
                    </span>
                </div>
            </div>

            <div class="w-full overflow-x-auto">
                <table class="w-full border-collapse text-[12.5px] text-text-base">
                    <thead>
                        <tr>
                            <th scope="col" :class="[staffTableHeadClass, 'text-left']">Date (BS)</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-left']">Date (AD)</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-left']">Reference</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-left']">Particulars / Description</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-right']">Debit (Rs.)</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-right']">Credit (Rs.)</th>
                            <th scope="col" :class="[staffTableHeadClass, 'text-right']">Balance (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="from" class="bg-primary-tint/20">
                            <td colspan="6" class="border-t border-border px-3 py-2 text-xs font-semibold text-text-strong">
                                Opening balance <span class="font-normal text-text-muted">(before {{ formatBsDate(from) }} / {{ from }})</span>
                            </td>
                            <td class="border-t border-border px-3 py-2 text-right text-xs font-bold text-text-strong whitespace-nowrap">
                                Rs. {{ formatMoney(staffOpeningAdvance) }}
                            </td>
                        </tr>
                        <tr v-if="staffAdvanceEntries.length === 0">
                            <td colspan="7" class="px-4 py-6 text-center text-xs font-medium text-text-muted">
                                No advance entries found in this period.
                            </td>
                        </tr>
                        <tr v-for="(entry, index) in staffAdvanceEntries" :key="`adv-${index}`" class="hover:bg-bg-subtle/50 transition-colors">
                            <td :class="[staffTableCellClass, 'whitespace-nowrap font-medium text-text-strong']">
                                {{ entry.date_bs ? formatBsDate(entry.date_bs) : formatBsDate(entry.date) }}
                            </td>
                            <td :class="[staffTableCellClass, 'whitespace-nowrap text-text-muted']">{{ entry.date }}</td>
                            <td :class="[staffTableCellClass, 'whitespace-nowrap font-medium']">{{ entry.reference ?? '-' }}</td>
                            <td :class="staffTableCellClass">{{ entry.description }}</td>
                            <td :class="staffTableNumClass">
                                {{ compareMoney(entry.debit, '0.00') > 0 ? formatMoney(entry.debit) : '-' }}
                            </td>
                            <td :class="staffTableNumClass">
                                {{ compareMoney(entry.credit, '0.00') > 0 ? formatMoney(entry.credit) : '-' }}
                            </td>
                            <td :class="[staffTableNumClass, 'font-bold text-text-strong']">
                                Rs. {{ formatMoney(entry.balance) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" :class="staffTableTotalClass">Closing Balance (Staff Advance)</td>
                            <td :class="[staffTableTotalClass, 'text-right whitespace-nowrap']">
                                Rs. {{ formatMoney(staffClosingAdvance) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>
    </div>
</template>
