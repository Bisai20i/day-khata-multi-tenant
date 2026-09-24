<script setup>
import Input from '@/components/ui/Input.vue';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';

defineProps({
    lines: { type: Array, default: () => [] },
    quantities: { type: Object, required: true },
    bonusQuantities: { type: Object, required: true },
    credits: { type: Object, required: true },
    bonuses: { type: Object, required: true },
});

defineEmits(['update:quantity', 'update:bonusQuantity']);
</script>

<template>
    <div>
        <div class="mb-2 grid grid-cols-[1fr_80px_80px_80px_90px_100px_100px_100px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
            <span>Item</span>
            <span class="text-right">Sold</span>
            <span class="text-right">Returned</span>
            <span class="text-right">Left</span>
            <span class="text-right">Rate</span>
            <span>Return qty (max: Left)</span>
            <span>Bonus qty</span>
            <span class="text-right">Credit</span>
        </div>

        <div
            v-for="line in lines"
            :key="line.sale_line_id"
            class="mb-2 grid grid-cols-[1fr_80px_80px_80px_90px_100px_100px_100px] items-center gap-2"
        >
            <span class="text-sm text-text-base">
                {{ line.item }}
                <span v-if="line.unit" class="text-text-faint">({{ line.unit }})</span>
            </span>
            <span class="text-right text-sm text-text-muted">{{ formatQuantity(line.quantity) }}</span>
            <span class="text-right text-sm text-text-muted">{{ formatQuantity(line.returned) }}</span>
            <span class="text-right text-sm font-semibold text-text-base">{{ formatQuantity(line.remaining) }}</span>
            <span class="text-right text-sm text-text-muted">{{ formatRate(line.rate) }}</span>
            <Input
                :model-value="quantities[line.sale_line_id] ?? ''"
                type="text"
                inputmode="decimal"
                placeholder="0"
                @update:model-value="(value) => $emit('update:quantity', line.sale_line_id, value)"
            />
            <!-- Bonus/free units come back at zero value and only
                 restock (audit section 3 "Sales"); a line that
                 carried no bonus has nothing to return. -->
            <Input
                :model-value="bonusQuantities[line.sale_line_id] ?? ''"
                type="text"
                inputmode="decimal"
                :disabled="!line.bonus_quantity || line.bonus_remaining === '0.0000'"
                :placeholder="line.bonus_remaining && line.bonus_remaining !== '0.0000' ? `0 of ${formatQuantity(line.bonus_remaining)}` : '-'"
                @update:model-value="(value) => $emit('update:bonusQuantity', line.sale_line_id, value)"
            />
            <span class="text-right text-sm text-text-base">
                <template v-if="credits[line.sale_line_id]?.error">
                    <span class="text-danger">{{ credits[line.sale_line_id].error }}</span>
                </template>
                <template v-else-if="bonuses[line.sale_line_id]?.error">
                    <span class="text-danger">{{ bonuses[line.sale_line_id].error }}</span>
                </template>
                <template v-else-if="credits[line.sale_line_id]">
                    {{ formatMoney(credits[line.sale_line_id].net) }}
                </template>
                <template v-else>-</template>
            </span>
        </div>
    </div>
</template>
