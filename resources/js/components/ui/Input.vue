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
    <div class="relative flex w-full items-center">
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
                'w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none placeholder:text-text-faint',
                'focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]',
                'disabled:cursor-not-allowed disabled:opacity-50',
                icon ? 'pl-[28px]' : '',
                props.class,
            )"
            @input="onInput"
        />
    </div>
</template>
