<script setup>
import { computed } from 'vue';
import { AlertCircle, CreditCard, FileText, MapPin, Phone, Printer, Store, User } from '@lucide/vue';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { formatMoney, formatQuantity, formatRate, isZeroMoney, parseQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    open: { type: Boolean, default: false },
    sale: { type: Object, default: null },
});

const emit = defineEmits(['update:open']);

const { can } = usePermissions();

const invoiceTypeLabels = {
    full: 'Full Tax Invoice',
    abbreviated: 'Abbreviated Tax Invoice',
    pan: 'PAN Invoice',
};

const paymentModeLabels = {
    cash: 'Cash',
    bank: 'Bank',
    partial: 'Partial',
    credit: 'Credit',
};

const modalTitle = computed(() => {
    if (!props.sale) return 'Sale details';
    const type = invoiceTypeLabels[props.sale.invoice_type] ?? 'Invoice';
    const num = props.sale.invoice_number ?? `#${props.sale.id}`;

    return `${type} · ${num}`;
});

const buyerName = computed(() => {
    if (!props.sale) return '-';

    return props.sale.buyer_name || props.sale.customer?.name || 'Walk-in Customer';
});

const buyerPan = computed(() => {
    if (!props.sale) return null;

    return props.sale.buyer_pan || props.sale.customer?.tpin || null;
});

const buyerAddress = computed(() => {
    if (!props.sale) return null;

    return props.sale.buyer_address || props.sale.customer?.address || null;
});

const buyerPhone = computed(() => {
    if (!props.sale) return null;

    return props.sale.customer?.mobile_no || null;
});

const printUrl = computed(() => {
    if (!props.sale) return '#';

    return `/sales/${props.sale.id}/print`;
});

function hasBonusQuantity(line) {
    if (!line.bonus_quantity) return false;
    const parsed = parseQuantity(line.bonus_quantity);

    return parsed.ok && parsed.value !== '0.0000';
}

function hasLineDiscount(line) {
    return line.discount_amount && !isZeroMoney(line.discount_amount);
}

function hasHeaderDiscount() {
    return props.sale?.discount_amount && !isZeroMoney(props.sale.discount_amount);
}

function hasRoundOff() {
    return props.sale?.round_off && !isZeroMoney(props.sale.round_off);
}

function hasTds() {
    return props.sale?.tds_amount && !isZeroMoney(props.sale.tds_amount);
}

function hasNontaxable() {
    return props.sale?.nontaxable_amount && !isZeroMoney(props.sale.nontaxable_amount);
}

function close() {
    emit('update:open', false);
}
</script>

<template>
    <Modal
        :open="open"
        :title="modalTitle"
        size="xl"
        @update:open="(val) => emit('update:open', val)"
    >
        <div v-if="sale" class="flex flex-col gap-4 text-text-base">
            <!-- Status & Meta Header Banner -->
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border-soft pb-3">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="sale.status === 'cancelled' ? 'danger' : 'success'">
                        {{ sale.status === 'cancelled' ? 'Cancelled' : 'Posted' }}
                    </Badge>
                    <Badge variant="neutral">
                        {{ invoiceTypeLabels[sale.invoice_type] ?? sale.invoice_type }}
                    </Badge>
                    <Badge variant="neutral">
                        Payment: {{ paymentModeLabels[sale.payment_mode] ?? sale.payment_mode }}
                    </Badge>
                    <Badge v-if="sale.branch?.name" variant="neutral">
                        Branch: {{ sale.branch.name }}
                    </Badge>
                </div>

                <div class="text-xs text-text-muted">
                    <span>Date: </span>
                    <strong class="text-text-strong">{{ formatBsDate(sale.date) || '-' }} BS</strong>
                    <span class="mx-1">·</span>
                    <span>{{ sale.date }} AD</span>
                </div>
            </div>

            <!-- Cancelled Notice (if cancelled) -->
            <div v-if="sale.status === 'cancelled'" class="rounded border border-danger-border bg-danger-bg p-3 text-xs text-danger">
                <div class="flex items-center gap-1.5 font-bold">
                    <AlertCircle class="h-4 w-4 shrink-0" />
                    <span>This sale invoice was cancelled</span>
                </div>
                <p v-if="sale.cancel_reason" class="mt-1">
                    <span class="font-semibold">Reason:</span> {{ sale.cancel_reason }}
                </p>
                <p v-if="sale.cancelled_at" class="mt-0.5 text-text-muted">
                    Cancelled at: {{ sale.cancelled_at }}
                </p>
            </div>

            <!-- Buyer & Invoice Information Grid -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3">
                <!-- Buyer Details -->
                <div class="rounded border border-border-soft bg-bg-subtle p-3">
                    <div class="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-text-muted uppercase">
                        <User class="h-3.5 w-3.5 text-primary" />
                        <span>Buyer Details</span>
                    </div>
                    <div class="text-sm font-semibold text-text-strong">{{ buyerName }}</div>
                    <div v-if="buyerPan" class="mt-1 flex items-center gap-1 text-xs text-text-muted">
                        <span class="font-medium">PAN / VAT:</span>
                        <span class="font-mono text-text-base">{{ buyerPan }}</span>
                    </div>
                    <div v-if="buyerAddress" class="mt-0.5 flex items-start gap-1 text-xs text-text-muted">
                        <MapPin class="mt-0.5 h-3 w-3 shrink-0 text-text-faint" />
                        <span>{{ buyerAddress }}</span>
                    </div>
                    <div v-if="buyerPhone" class="mt-0.5 flex items-center gap-1 text-xs text-text-muted">
                        <Phone class="h-3 w-3 shrink-0 text-text-faint" />
                        <span>{{ buyerPhone }}</span>
                    </div>
                </div>

                <!-- Invoice Details -->
                <div class="rounded border border-border-soft bg-bg-subtle p-3">
                    <div class="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-text-muted uppercase">
                        <FileText class="h-3.5 w-3.5 text-primary" />
                        <span>Invoice Metadata</span>
                    </div>
                    <div class="grid grid-cols-2 gap-y-1 text-xs">
                        <span class="text-text-muted">Invoice #:</span>
                        <span class="font-semibold text-text-strong">{{ sale.invoice_number ?? '-' }}</span>

                        <span class="text-text-muted">Fiscal Year:</span>
                        <span class="text-text-base">{{ sale.fiscal_year?.name ?? '-' }}</span>

                        <span v-if="sale.chalani_number" class="text-text-muted">Chalani #:</span>
                        <span v-if="sale.chalani_number" class="text-text-base">{{ sale.chalani_number }}</span>

                        <span class="text-text-muted">Created By:</span>
                        <span class="text-text-base">{{ sale.creator?.name ?? '-' }}</span>
                    </div>
                </div>

                <!-- Operational Context -->
                <div class="rounded border border-border-soft bg-bg-subtle p-3 sm:col-span-2 md:col-span-1">
                    <div class="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-text-muted uppercase">
                        <Store class="h-3.5 w-3.5 text-primary" />
                        <span>Context & Agent</span>
                    </div>
                    <div class="grid grid-cols-2 gap-y-1 text-xs">
                        <span class="text-text-muted">Store:</span>
                        <span class="text-text-base">{{ sale.store?.name ?? '-' }}</span>

                        <span class="text-text-muted">Sales Agent:</span>
                        <span class="text-text-base">{{ sale.agent?.name ?? '-' }}</span>

                        <span v-if="sale.commission_amount && !isZeroMoney(sale.commission_amount)" class="text-text-muted">Commission:</span>
                        <span v-if="sale.commission_amount && !isZeroMoney(sale.commission_amount)" class="font-mono text-text-base">Rs. {{ formatMoney(sale.commission_amount) }}</span>
                    </div>
                </div>
            </div>

            <!-- Line Items Table -->
            <div class="overflow-x-auto rounded border border-border-soft">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border bg-bg-muted text-[11px] font-bold tracking-wider text-text-muted uppercase">
                        <tr>
                            <th class="w-10 px-3 py-2 text-center">S.N.</th>
                            <th class="px-3 py-2">Item Description</th>
                            <th class="w-20 px-3 py-2">Unit</th>
                            <th class="w-24 px-3 py-2 text-right">Qty</th>
                            <th class="w-28 px-3 py-2 text-right">Rate (Rs.)</th>
                            <th class="w-24 px-3 py-2 text-right">Discount</th>
                            <th class="w-20 px-3 py-2 text-center">VAT</th>
                            <th class="w-32 px-3 py-2 text-right">Total (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-soft bg-bg-surface">
                        <tr v-for="(line, idx) in sale.lines" :key="line.id ?? idx" class="transition-colors hover:bg-bg-subtle">
                            <td class="px-3 py-2 text-center text-text-muted">{{ idx + 1 }}</td>
                            <td class="px-3 py-2">
                                <div class="font-semibold text-text-strong">{{ line.item?.name ?? 'Item' }}</div>
                                <div v-if="line.item?.code || line.item?.hs_code" class="text-[11px] text-text-faint">
                                    <span v-if="line.item?.code">Code: {{ line.item.code }}</span>
                                    <span v-if="line.item?.code && line.item?.hs_code" class="mx-1">·</span>
                                    <span v-if="line.item?.hs_code">HS: {{ line.item.hs_code }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-text-muted">
                                {{ line.item_unit?.name ?? line.item?.unit ?? '-' }}
                            </td>
                            <td class="px-3 py-2 text-right [font-variant-numeric:tabular-nums]">
                                <div class="font-medium text-text-strong">{{ formatQuantity(line.quantity) }}</div>
                                <div v-if="hasBonusQuantity(line)" class="text-[10px] font-bold text-success">
                                    +{{ formatQuantity(line.bonus_quantity) }} free
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right [font-variant-numeric:tabular-nums] text-text-base">
                                {{ formatRate(line.rate) }}
                            </td>
                            <td class="px-3 py-2 text-right [font-variant-numeric:tabular-nums] text-text-muted">
                                <span v-if="hasLineDiscount(line)">{{ formatMoney(line.discount_amount) }}</span>
                                <span v-else class="text-text-faint">-</span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span
                                    :class="[
                                        'inline-block px-1.5 py-0.5 text-[10px] font-semibold',
                                        line.vatable ? 'bg-primary-tint text-primary' : 'bg-bg-muted text-text-muted',
                                    ]"
                                >
                                    {{ line.vatable ? '13%' : 'Exempt' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold [font-variant-numeric:tabular-nums] text-text-strong">
                                {{ formatMoney(line.line_total) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Totals & Payment Breakdown -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <!-- Left: Settlement & Narration -->
                <div class="flex flex-col gap-3">
                    <div class="rounded border border-border-soft bg-bg-subtle p-3">
                        <div class="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-text-muted uppercase">
                            <CreditCard class="h-3.5 w-3.5 text-primary" />
                            <span>Payment & Settlement</span>
                        </div>
                        <div class="flex flex-col gap-1.5 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="text-text-muted">Payment Mode:</span>
                                <span class="font-semibold text-text-strong">{{ paymentModeLabels[sale.payment_mode] ?? sale.payment_mode }}</span>
                            </div>

                            <div v-if="sale.cash_amount && !isZeroMoney(sale.cash_amount)" class="flex items-center justify-between">
                                <span class="text-text-muted">Cash Tendered:</span>
                                <span class="font-mono text-text-base">Rs. {{ formatMoney(sale.cash_amount) }}</span>
                            </div>

                            <div v-if="sale.bank_amount && !isZeroMoney(sale.bank_amount)" class="flex items-center justify-between">
                                <span class="text-text-muted">Bank Transfer:</span>
                                <span class="font-mono text-text-base">
                                    Rs. {{ formatMoney(sale.bank_amount) }}
                                    <span v-if="sale.bank_account?.name" class="text-text-muted">({{ sale.bank_account.name }})</span>
                                </span>
                            </div>

                            <div v-if="hasTds()" class="flex items-center justify-between">
                                <span class="text-text-muted">TDS Withheld:</span>
                                <span class="font-mono text-danger">
                                    - Rs. {{ formatMoney(sale.tds_amount) }}
                                    <span v-if="sale.tds_account?.name" class="text-text-muted">({{ sale.tds_account.name }})</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Narration note -->
                    <div v-if="sale.narration" class="rounded border border-border-soft bg-bg-subtle p-3">
                        <div class="mb-1 text-[11px] font-bold tracking-wider text-text-muted uppercase">Narration</div>
                        <p class="text-xs text-text-base">{{ sale.narration }}</p>
                    </div>
                </div>

                <!-- Right: Financial Summary / Totals Breakdown -->
                <div class="rounded border border-border-soft bg-bg-subtle p-3">
                    <div class="mb-2 text-[11px] font-bold tracking-wider text-text-muted uppercase">Bill Summary</div>
                    <div class="flex flex-col gap-1.5 text-xs">
                        <div v-if="hasHeaderDiscount()" class="flex items-center justify-between text-text-muted">
                            <span>Header Discount:</span>
                            <span class="font-mono text-danger">- Rs. {{ formatMoney(sale.discount_amount) }}</span>
                        </div>

                        <div class="flex items-center justify-between text-text-muted">
                            <span>Taxable Amount:</span>
                            <span class="font-mono text-text-base">Rs. {{ formatMoney(sale.taxable_amount) }}</span>
                        </div>

                        <div v-if="hasNontaxable()" class="flex items-center justify-between text-text-muted">
                            <span>Non-Taxable Amount:</span>
                            <span class="font-mono text-text-base">Rs. {{ formatMoney(sale.nontaxable_amount) }}</span>
                        </div>

                        <div class="flex items-center justify-between text-text-muted">
                            <span>VAT ({{ formatRate(sale.vat_rate) }}%):</span>
                            <span class="font-mono text-text-base">Rs. {{ formatMoney(sale.vat_amount) }}</span>
                        </div>

                        <div v-if="hasRoundOff()" class="flex items-center justify-between text-text-muted">
                            <span>Round Off:</span>
                            <span class="font-mono text-text-base">Rs. {{ formatMoney(sale.round_off) }}</span>
                        </div>

                        <div class="mt-2 flex items-center justify-between border-t border-border pt-2 text-sm font-bold text-text-strong">
                            <span>Grand Total:</span>
                            <span class="font-mono text-base text-primary">Rs. {{ formatMoney(sale.total) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex w-full items-center justify-between gap-3">
                <div>
                    <Button
                        v-if="sale && can('sales.print')"
                        variant="secondary"
                        tone="neutral"
                        as="a"
                        :href="printUrl"
                        target="_blank"
                        rel="noopener"
                    >
                        <Printer class="size-4" />
                        Print Bill
                    </Button>
                </div>

                <Button variant="secondary" tone="neutral" @click="close">
                    Close
                </Button>
            </div>
        </template>
    </Modal>
</template>
