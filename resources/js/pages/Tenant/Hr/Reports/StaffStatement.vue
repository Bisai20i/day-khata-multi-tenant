<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Button from '@/components/ui/Button.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    staff: { type: Object, required: true },
    filters: { type: Object, default: () => ({ from: null, to: null }) },
    openingSalary: { type: String, default: '0.00' },
    openingAdvance: { type: String, default: '0.00' },
    salaryEntries: { type: Array, default: () => [] },
    advanceEntries: { type: Array, default: () => [] },
});

useLayoutChrome('Staff Statement');

const from = ref(props.filters.from ?? null);
const to = ref(props.filters.to ?? null);
const isLoading = ref(false);

watch(
    () => props.filters,
    (applied) => {
        from.value = applied.from ?? null;
        to.value = applied.to ?? null;
    },
);

// Reset shows once a range is applied or the inputs differ from what is applied.
const hasActiveFilter = computed(
    () => Boolean(from.value || to.value || props.filters.from || props.filters.to),
);

const page = usePage();

// ISO dates compare correctly as strings. The server enforces the same rule
// (`to` after_or_equal `from`) and its refusal is shown when it still comes back.
const isRangeInverted = computed(() => Boolean(from.value && to.value && to.value < from.value));
const rangeError = computed(() => {
    if (isRangeInverted.value) {
        return 'The to date must be on or after the from date.';
    }

    return page.props.errors?.from || page.props.errors?.to || '';
});

function applyFilter() {
    if (isRangeInverted.value) {
        return;
    }

    const params = {};

    if (from.value) {
        params.from = from.value;
    }

    if (to.value) {
        params.to = to.value;
    }

    router.get(`/hr/staff/${props.staff.id}/statement`, params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (isLoading.value = true),
        onFinish: () => (isLoading.value = false),
    });
}

function resetFilters() {
    from.value = null;
    to.value = null;
    applyFilter();
}

const staffLine = computed(() =>
    [props.staff.staff_code, props.staff.designation, props.staff.department].filter(Boolean).join(' - '),
);

// The running balance already carries the sign; the closing line is simply the last one,
// or the opening balance when nothing happened in the period.
function closingBalance(entries, opening) {
    return entries.length > 0 ? entries[entries.length - 1].balance : opening;
}

const ledgers = computed(() => [
    {
        key: 'salary',
        title: 'Salary payable ledger',
        note: 'Credit is what the business owes the staff member (payroll posted); debit is salary paid. The balance is the amount still payable.',
        closingLabel: 'Closing balance (salary payable)',
        opening: props.openingSalary,
        entries: props.salaryEntries,
    },
    {
        key: 'advance',
        title: 'Advance ledger',
        note: 'Debit is an advance given to the staff member; credit is the amount recovered in payroll. The balance is the amount still to recover.',
        closingLabel: 'Closing balance (advance receivable)',
        opening: props.openingAdvance,
        entries: props.advanceEntries,
    },
]);

const headerClass = 'px-[9px] py-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase whitespace-nowrap';
const cellClass = 'border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle first:border-l-[1.5px] last:border-r-[1.5px]';
const numericCellClass = `${cellClass} text-right whitespace-nowrap [font-variant-numeric:tabular-nums]`;
const totalCellClass = 'border-y-[1.5px] border-border bg-primary-tint/40 px-[9px] py-2 font-bold text-text-strong first:border-l-[1.5px] last:border-r-[1.5px]';
</script>

<template>
    <div>
        <PageHeader :title="`Staff Statement - ${staff.name}`" :description="staffLine">
            <Button :as="Link" href="/hr/staff" variant="secondary" tone="purple">
                <ArrowLeft class="h-[14px] w-[14px]" aria-hidden="true" />
                Back to staff
            </Button>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <Label for="statement-from" class="mb-1">From date (BS)</Label>
                    <NepaliDateInput id="statement-from" v-model="from" />
                </div>
                <div>
                    <Label for="statement-to" class="mb-1">To date (BS)</Label>
                    <NepaliDateInput id="statement-to" v-model="to" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" :disabled="isRangeInverted" @click="applyFilter">Generate statement</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
            <p v-if="rangeError" class="mt-2 text-sm text-danger" role="alert">{{ rangeError }}</p>
        </Card>

        <Card v-for="ledger in ledgers" :key="ledger.key" variant="panel" class="mb-4">
            <h2 class="text-[13px] font-bold text-text-strong">{{ ledger.title }}</h2>
            <p class="mb-3 text-[12px] text-text-muted">{{ ledger.note }}</p>

            <div
                v-if="ledger.entries.length === 0 && !filters.from"
                class="px-6 py-10 text-center text-[13px] font-semibold text-text-base"
            >
                {{ filters.to ? 'No entries in this period.' : 'No entries yet.' }}
            </div>

            <div v-else class="w-full overflow-x-auto">
                <table class="w-full border-separate [border-spacing:0_4px] text-[12.5px] text-text-base">
                    <thead>
                        <tr>
                            <th scope="col" :class="[headerClass, 'text-left']">Date</th>
                            <th scope="col" :class="[headerClass, 'text-left']">Reference</th>
                            <th scope="col" :class="[headerClass, 'text-left']">Description</th>
                            <th scope="col" :class="[headerClass, 'text-right']">Debit</th>
                            <th scope="col" :class="[headerClass, 'text-right']">Credit</th>
                            <th scope="col" :class="[headerClass, 'text-right']">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="filters.from">
                            <td :class="totalCellClass" colspan="5">
                                Opening balance
                                <span class="font-normal text-text-muted">(before {{ formatBsDate(filters.from) }} / {{ filters.from }})</span>
                            </td>
                            <td :class="[totalCellClass, 'text-right whitespace-nowrap']">{{ formatMoney(ledger.opening) }}</td>
                        </tr>
                        <tr v-if="ledger.entries.length === 0">
                            <td :class="[cellClass, 'text-center font-semibold']" colspan="6">No entries in this period.</td>
                        </tr>
                        <tr v-for="(entry, index) in ledger.entries" :key="`${ledger.key}-${index}`">
                            <td :class="[cellClass, 'whitespace-nowrap']">
                                {{ formatBsDate(entry.date) }}
                                <span class="block text-[11px] text-text-faint">{{ entry.date }}</span>
                            </td>
                            <td :class="[cellClass, 'whitespace-nowrap']">{{ entry.reference ?? '-' }}</td>
                            <td :class="cellClass">{{ entry.description }}</td>
                            <td :class="numericCellClass">{{ formatMoney(entry.debit) }}</td>
                            <td :class="numericCellClass">{{ formatMoney(entry.credit) }}</td>
                            <td :class="[numericCellClass, 'font-semibold']">{{ formatMoney(entry.balance) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td :class="totalCellClass" colspan="5">{{ ledger.closingLabel }}</td>
                            <td :class="[totalCellClass, 'text-right whitespace-nowrap']">{{ formatMoney(closingBalance(ledger.entries, ledger.opening)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div v-if="ledger.entries.length === 0 && !filters.from" class="mt-3 flex justify-end border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <span class="font-bold text-text-strong">{{ ledger.closingLabel }}:</span>
                <span class="ml-2 font-bold">{{ formatMoney(closingBalance(ledger.entries, ledger.opening)) }}</span>
            </div>
        </Card>
    </div>
</template>
