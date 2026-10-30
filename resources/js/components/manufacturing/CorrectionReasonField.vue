<script setup>
import Input from '@/components/ui/Input.vue';
import Label from '@/components/ui/Label.vue';

/**
 * The required correction reason a manufacturing posting needs when its date
 * falls in the closed year reopened for correction (the parent decides with
 * isInCorrectionYear() and renders this with v-if). Same warning and wording
 * as the purchase and stock adjustment forms.
 */
defineProps({
    year: { type: Object, required: true },
    modelValue: { type: String, default: '' },
    error: { type: String, default: '' },
    id: { type: String, default: 'correction-reason' },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex flex-col gap-3 border-[1.5px] border-warning-text bg-warning-bg px-3 py-3">
        <p class="text-sm text-warning-text">
            {{ year.name }} is reopened for correction. This date falls in that year, so the posting goes into it and is
            rolled forward into the following year's opening balances.
        </p>
        <div>
            <Label :for="id" class="mb-1">Correction reason <span class="text-danger">*</span></Label>
            <Input
                :id="id"
                :model-value="modelValue"
                type="text"
                required
                placeholder="Explain why this correction is needed"
                @update:model-value="(value) => emit('update:modelValue', value)"
            />
            <p v-if="error" class="mt-1 text-sm text-danger">{{ error }}</p>
        </div>
    </div>
</template>
