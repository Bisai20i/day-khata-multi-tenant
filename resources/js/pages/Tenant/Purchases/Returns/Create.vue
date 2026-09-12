<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

const props = defineProps({
    // A LengthAwarePaginator page of purchases, each line already carrying its
    // unit name and how much of it is still returnable. Loading every posted
    // purchase with all its lines stopped being viable long before a real shop
    // stops buying things.
    searchablePurchases: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, prev_page_url: null, next_page_url: null }),
    },
    purchaseSearch: { type: String, default: null },
    refundAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const search = ref(props.purchaseSearch ?? '');
const searching = ref(false);

// The purchase list is filtered on the server, so a shop with ten thousand
// bills searches in the database rather than shipping them all to the browser.
function runSearch() {
    router.get(
        window.location.pathname,
        { purchase_search: search.value || undefined },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['searchablePurchases', 'purchaseSearch'],
            onStart: () => (searching.value = true),
            onFinish: () => (searching.value = false),
        },
    );
}

function goToPage(url) {
    if (!url) return;

    router.get(url, {}, { preserveState: true, preserveScroll: true, only: ['searchablePurchases', 'purchaseSearch'] });
}

const purchaseOptions = computed(() =>
    props.searchablePurchases.data.map((purchase) => ({
        value: purchase.id,
        label: `#${purchase.id} - ${purchase.supplier?.name ?? '-'} (${formatMoney(purchase.total)})`,
        searchValue: `${purchase.id} ${purchase.supplier?.name ?? ''} ${purchase.bill_number ?? ''}`,
    })),
);

const refundAccountOptions = computed(() =>
    props.refundAccounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));

const form = useForm({
    purchase_id: null,
    // todayInKathmandu(), never new Date().toISOString(): before 05:45 Nepal
    // time the UTC day is still yesterday.
    date: todayInKathmandu(),
    reason: '',
    refund_account_id: null,
    store_id: null,
    lines: [],
});

const selectedPurchase = computed(
    () => props.searchablePurchases.data.find((purchase) => purchase.id === form.purchase_id) ?? null,
);

watch(
    () => form.purchase_id,
    () => {
        const purchase = selectedPurchase.value;

        // The return leaves the store the goods were received into unless the
        // user picks another one.
        form.store_id = purchase?.store_id ?? null;
        form.lines = purchase
            ? purchase.lines.map((line) => ({
                  purchase_line_id: line.id,
                  item_name: line.item_name ?? '-',
                  unit_name: line.unit_name ?? '',
                  quantity_purchased: line.quantity,
                  quantity_remaining: line.remaining_quantity,
                  rate: line.rate,
                  quantity: '',
              }))
            : [];
    },
);

const hasReturnableLine = computed(() => form.lines.some((line) => line.quantity !== '' && line.quantity !== '0'));

function submit() {
    form.transform((data) => ({
        purchase_id: data.purchase_id,
        date: data.date,
        reason: data.reason || null,
        refund_account_id: data.refund_account_id || null,
        store_id: data.store_id || null,
        // Quantities go out as typed. Number() would round 0.00004 into a
        // charge with no stock behind it (audit P0-5).
        lines: data.lines
            .filter((line) => line.quantity !== '' && line.quantity !== '0')
            .map((line) => ({
                purchase_line_id: line.purchase_line_id,
                quantity: line.quantity,
            })),
    })).post('/purchase-returns', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New purchase return</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[260px] flex-1">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Find a purchase</label>
                    <Input v-model="search" type="text" placeholder="Bill number, supplier or purchase id" @keyup.enter="runSearch" />
                </div>
                <Button variant="secondary" tone="purple" type="button" :loading="searching" @click="runSearch">
                    <Search class="size-4" />
                    Search
                </Button>
                <div class="flex items-center gap-2 text-xs text-text-muted">
                    <button
                        type="button"
                        class="border-[1.5px] border-border bg-white px-2 py-1 font-semibold disabled:opacity-40"
                        :disabled="!searchablePurchases.prev_page_url"
                        @click="goToPage(searchablePurchases.prev_page_url)"
                    >
                        Previous
                    </button>
                    <span>Page {{ searchablePurchases.current_page }} of {{ searchablePurchases.last_page }}</span>
                    <button
                        type="button"
                        class="border-[1.5px] border-border bg-white px-2 py-1 font-semibold disabled:opacity-40"
                        :disabled="!searchablePurchases.next_page_url"
                        @click="goToPage(searchablePurchases.next_page_url)"
                    >
                        Next
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-5 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Purchase <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.purchase_id"
                        :options="purchaseOptions"
                        placeholder="Select the original purchase"
                        @update:model-value="(v) => (form.purchase_id = v)"
                    />
                    <p v-if="form.errors.purchase_id" class="mt-1 text-sm text-danger">{{ form.errors.purchase_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <Input v-model="form.reason" type="text" maxlength="255" placeholder="Optional" />
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-danger">{{ form.errors.reason }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Refund via</label>
                    <Combobox
                        :model-value="form.refund_account_id"
                        :options="refundAccountOptions"
                        placeholder="No refund (debit note only)"
                        @update:model-value="(v) => (form.refund_account_id = v)"
                    />
                    <p v-if="form.errors.refund_account_id" class="mt-1 text-sm text-danger">{{ form.errors.refund_account_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                    <Combobox
                        :model-value="form.store_id"
                        :options="storeOptions"
                        placeholder="The purchase's store"
                        @update:model-value="(v) => (form.store_id = v)"
                    />
                    <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
                </div>
            </div>

            <div v-if="selectedPurchase">
                <div class="mb-2 grid grid-cols-[1fr_90px_110px_110px_110px_140px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Unit</span>
                    <span>Rate</span>
                    <span>Purchased</span>
                    <span>Returnable</span>
                    <span>Return Qty</span>
                </div>

                <div
                    v-for="(line, index) in form.lines"
                    :key="line.purchase_line_id"
                    class="mb-2 grid grid-cols-[1fr_90px_110px_110px_110px_140px] items-start gap-2"
                >
                    <span class="pt-2 text-sm text-text-strong">{{ line.item_name }}</span>
                    <span class="pt-2 text-sm text-text-muted">{{ line.unit_name || '-' }}</span>
                    <span class="pt-2 text-sm text-text-muted">{{ formatRate(line.rate) }}</span>
                    <span class="pt-2 text-sm text-text-muted">{{ formatQuantity(line.quantity_purchased) }}</span>
                    <span class="pt-2 text-sm text-text-muted">{{ formatQuantity(line.quantity_remaining) }}</span>
                    <div>
                        <Input
                            v-model="form.lines[index].quantity"
                            type="number"
                            min="0"
                            step="0.0001"
                            :max="line.quantity_remaining"
                            placeholder="0"
                        />
                        <p v-if="form.errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.quantity`] }}
                        </p>
                    </div>
                </div>

                <p class="mt-1 text-xs text-text-muted">
                    Quantities are in the unit each line was purchased in. Returning one Box of twelve puts twelve pieces
                    back out of stock.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="submit"
                    :disabled="form.processing || !selectedPurchase || !hasReturnableLine"
                >
                    Create Purchase Return
                </Button>
            </div>
        </form>
    </Card>
</template>
