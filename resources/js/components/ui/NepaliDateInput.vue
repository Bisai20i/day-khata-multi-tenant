<script setup>
import { computed, ref, watch } from 'vue';
import { adToBs, bsToAdString, daysInBsMonth, MAX_BS_YEAR, MIN_BS_YEAR, NEPALI_MONTH_NAMES } from '@/lib/nepali-calendar';
import { cn } from '@/lib/utils';
import Select from './Select.vue';

/**
 * Masked Bikram Sambat (BS) date input. Drop-in replacement for the native
 * `<Input type="date">` this app otherwise uses: the v-model contract is the
 * same plain AD "YYYY-MM-DD" string either way - this component only changes
 * what the *user* sees and types (BS year/month/day), converting to/from AD
 * client-side via resources/js/lib/nepali-calendar.js so parent code (forms,
 * validation, submitted payloads) needs no changes to switch between them.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['update:modelValue']);

const monthOptions = NEPALI_MONTH_NAMES.map((label, index) => ({ value: index + 1, label }));

const bsYear = ref(null);
const bsMonth = ref(null);
const bsDay = ref(null);

// Tracks the AD string this component itself last emitted, so the
// prop-driven watcher below can tell "the parent changed modelValue out
// from under us" apart from "our own emit just came back down as a prop" -
// re-deriving BS fields from our own echoed value can otherwise fight an
// in-progress edit (e.g. clobber the day field while the user is still
// typing the year).
let lastEmitted = null;

watch(
    () => props.modelValue,
    (value) => {
        if (value === lastEmitted) {
            return;
        }

        if (!value) {
            bsYear.value = null;
            bsMonth.value = null;
            bsDay.value = null;
            return;
        }

        try {
            const bs = adToBs(value);
            bsYear.value = bs.year;
            bsMonth.value = bs.month;
            bsDay.value = bs.day;
        } catch {
            // Out of the supported BS range or unparsable - leave the
            // fields blank rather than showing a wrong date.
            bsYear.value = null;
            bsMonth.value = null;
            bsDay.value = null;
        }
    },
    { immediate: true },
);

const dayOptions = computed(() => {
    let maxDay = 32;

    if (bsYear.value && bsMonth.value) {
        try {
            maxDay = daysInBsMonth(bsYear.value, bsMonth.value);
        } catch {
            maxDay = 32;
        }
    }

    return Array.from({ length: maxDay }, (_, index) => ({ value: index + 1, label: String(index + 1) }));
});

function tryEmit() {
    if (!bsYear.value || !bsMonth.value || !bsDay.value) {
        if (props.modelValue !== '') {
            lastEmitted = '';
            emit('update:modelValue', '');
        }
        return;
    }

    if (bsYear.value < MIN_BS_YEAR || bsYear.value > MAX_BS_YEAR) {
        return;
    }

    try {
        const maxDay = daysInBsMonth(bsYear.value, bsMonth.value);
        if (bsDay.value > maxDay) {
            bsDay.value = maxDay;
        }

        const adString = bsToAdString(bsYear.value, bsMonth.value, bsDay.value);
        lastEmitted = adString;

        // Skip re-emitting a value the parent already has - avoids marking
        // an untouched form field dirty when this just ran because the BS
        // fields were (re)derived from an incoming prop, e.g. on mount.
        if (adString !== props.modelValue) {
            emit('update:modelValue', adString);
        }
    } catch {
        // Not a valid BS date yet (e.g. year still mid-type) - wait for
        // more input rather than emitting garbage.
    }
}

function onYearInput(event) {
    const digits = event.target.value.replace(/\D/g, '').slice(0, 4);
    event.target.value = digits;
    bsYear.value = digits ? Number(digits) : null;
    tryEmit();
}

watch([bsMonth, bsDay], () => tryEmit());
</script>

<template>
    <div :class="cn('flex w-full items-center gap-1.5', props.class)">
        <input
            type="text"
            inputmode="numeric"
            :value="bsYear ?? ''"
            placeholder="YYYY"
            :disabled="disabled"
            :required="required"
            class="w-[4.5rem] border-[1.5px] border-border bg-bg-subtle px-2 py-2 text-[13px] text-text-base outline-none transition-colors duration-150 placeholder:text-text-faint focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)] disabled:cursor-not-allowed disabled:opacity-50"
            @input="onYearInput"
        />
        <Select v-model="bsMonth" :options="monthOptions" placeholder="Month" :disabled="disabled" class="min-w-[6.5rem]" />
        <Select v-model="bsDay" :options="dayOptions" placeholder="Day" :disabled="disabled" class="w-[4.5rem]" />
        <span class="shrink-0 text-[11px] text-text-faint">BS</span>
    </div>
</template>
