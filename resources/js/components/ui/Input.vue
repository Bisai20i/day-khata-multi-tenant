<script setup>
import { cn } from '@/lib/utils';

// Everything this component doesn't declare as a prop belongs on the native <input>, not on
// the positioning wrapper it renders. With the default fallthrough those attributes landed on
// the wrapper <div>, so `step` never reached the input: a `type="number"` field kept the
// default step of 1 and the browser rejected 12.5 or qty 1.5 with a stepMismatch on submit,
// blocking every decimal entry in the app (audit P0-7). `id`, `min`, `max`, `inputmode`,
// `name`, `required`, `autocomplete`, `aria-*` and listeners were misplaced the same way.
// `class` stays a declared prop, so it is not in `$attrs` and the cn() merge below is still
// the only thing that styles the input.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    icon: { type: [Object, Function], default: null },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['update:modelValue']);

function onInput(event) {
    emit('update:modelValue', event.target.value);
}
</script>

<template>
    <div
        :class="cn(
            'relative flex w-full items-center',
            $slots.addon
                ? 'h-9 border-[1.5px] border-border bg-bg-subtle transition-colors duration-150 focus-within:border-primary focus-within:bg-white focus-within:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]'
                : '',
        )"
    >
        <component
            :is="icon"
            v-if="icon"
            class="pointer-events-none absolute left-[9px] h-3.5 w-3.5 text-text-faint"
        />
        <input
            v-bind="$attrs"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :class="cn(
                'h-9 w-full px-3 text-[13px] text-text-base outline-none placeholder:text-text-faint',
                $slots.addon
                    ? 'border-0 bg-transparent'
                    : 'border-[1.5px] border-border bg-bg-subtle transition-colors duration-150 focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)] disabled:cursor-not-allowed disabled:opacity-50',
                icon ? 'pl-[28px]' : '',
                props.class,
            )"
            @input="onInput"
        />
        <!-- Trailing action tied to this specific field (e.g. a discount-type
             toggle or a "+ add" shortcut) - rendered inside the same bordered
             box instead of floating beside it as its own button. -->
        <div v-if="$slots.addon" class="flex h-9 shrink-0 items-center border-l-[1.5px] border-border">
            <slot name="addon" />
        </div>
    </div>
</template>
