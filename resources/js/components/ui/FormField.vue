<script setup>
import { computed } from 'vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import { cn } from '@/lib/utils';

/**
 * Label + control + help/error line. The control goes in the default slot,
 * which receives `describedBy` to bind as its aria-describedby so screen
 * readers announce whichever line is showing.
 *
 * With `helpAsTooltip` the help moves off the line under the control and
 * behind an info icon right of the label, for forms too dense to spare it.
 */
const props = defineProps({
    label: { type: String, required: true },
    for: { type: String, default: '' },
    required: { type: Boolean, default: false },
    help: { type: String, default: '' },
    helpAsTooltip: { type: Boolean, default: true },
    error: { type: String, default: '' },
    class: { type: [String, Array, Object], default: '' },
});

const helpId = computed(() => (props.for ? `${props.for}-help` : undefined));
const errorId = computed(() => (props.for ? `${props.for}-error` : undefined));
const describedBy = computed(() => (props.error ? errorId.value : props.help ? helpId.value : undefined));
</script>

<template>
    <div data-form-field :class="cn('flex min-w-0 flex-col', props.class)">
        <div class="mb-1.5 flex items-center gap-1">
            <label :for="props.for || undefined" class="block text-[13px] font-semibold text-text-base">
                {{ label }}
                <span v-if="required" class="text-danger" aria-hidden="true">*</span>
                <slot name="label-suffix" />
            </label>
            <InfoTip v-if="help && helpAsTooltip" :text="help" />
        </div>
        <slot :describedBy="describedBy" />
        <p v-if="error" :id="errorId" class="mt-1.5 text-[13px] text-danger" role="alert">{{ error }}</p>
        <p v-else-if="help" :id="helpId" :class="helpAsTooltip ? 'sr-only' : 'mt-1.5 text-xs leading-relaxed text-text-muted'">{{ help }}</p>
    </div>
</template>
