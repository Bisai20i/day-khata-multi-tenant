<script setup>
import Button from '@/components/ui/Button.vue';
import AreaSelect from '@/components/parties/AreaSelect.vue';

/**
 * The strip that appears above a party list once rows are ticked: pick the
 * target area (or "none" to clear it) and apply. Driven by usePartyAreaBulk().
 */
defineProps({
    count: { type: Number, required: true },
    noun: { type: String, required: true },
    areas: { type: Array, default: () => [] },
    applying: { type: Boolean, default: false },
});

const targetAreaId = defineModel('targetAreaId', { type: [Number, null], default: null });
const emit = defineEmits(['apply', 'clearArea', 'clear']);
</script>

<template>
    <div
        v-if="count > 0"
        class="mb-3 flex flex-wrap items-end gap-3 border-[1.5px] border-primary bg-primary-tint px-3 py-2.5"
        role="region"
        :aria-label="`Assign ${noun}s to an area`"
    >
        <p class="pb-2 text-[13px] font-semibold text-text-strong">{{ count }} {{ count === 1 ? noun : `${noun}s` }} selected</p>
        <div class="w-72">
            <AreaSelect
                v-model="targetAreaId"
                :areas="areas"
                :id="`bulk-area-${noun}`"
                label="Move to area"
                blank-label="- Choose an area -"
            />
        </div>
        <Button variant="primary" tone="purple" type="button" :loading="applying" :disabled="applying || targetAreaId === null" @click="emit('apply')">
            Assign
        </Button>
        <Button variant="secondary" tone="neutral" type="button" :disabled="applying" @click="emit('clearArea')">Remove area</Button>
        <Button variant="secondary" tone="neutral" type="button" :disabled="applying" @click="emit('clear')">Clear selection</Button>
    </div>
</template>
