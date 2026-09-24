<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Calendar, ChevronLeft, ChevronRight } from '@lucide/vue';
import { PopoverAnchor, PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { adToBs, bsToAd, bsToAdString, daysInBsMonth, MAX_BS_YEAR, MIN_BS_YEAR, NEPALI_MONTH_NAMES } from '@/lib/nepali-calendar';
import { cn } from '@/lib/utils';

/**
 * Bikram Sambat (BS) date input with a calendar popup, modelled on the
 * legacy day_khata app's date picker UX. Drop-in replacement for the native
 * `<Input type="date">` this app otherwise uses: the v-model contract is the
 * same plain AD "YYYY-MM-DD" string either way - this component only changes
 * what the *user* sees and types (a BS date, with a 3-level drill-down
 * day/month/year picker), converting to/from AD client-side via
 * resources/js/lib/nepali-calendar.js so parent code (forms, validation,
 * submitted payloads) needs no changes.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['update:modelValue']);

// PopoverRoot's slot (PopoverAnchor + the teleported PopoverPortal) renders
// more than one root node, so Vue can't auto-inherit fallthrough attributes
// (e.g. an `id` paired with a `<label for>`) onto it - bind them onto the
// actual `<input>` below instead.
defineOptions({ inheritAttrs: false });

const DAY_HEADERS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
const YEAR_LIST = Array.from({ length: MAX_BS_YEAR - MIN_BS_YEAR + 1 }, (_, index) => MIN_BS_YEAR + index);

const navButtonClass =
    'flex h-7 w-7 shrink-0 items-center justify-center border border-border-soft text-text-muted transition-colors duration-150 hover:border-primary hover:bg-primary-tint hover:text-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-border-soft disabled:hover:bg-transparent disabled:hover:text-text-muted';
const headerButtonClass =
    'flex-1 truncate px-2 py-1 text-center text-[13px] font-semibold text-text-strong transition-colors duration-150 hover:text-primary';

const todayBs = adToBs(new Date());

const isOpen = ref(false);
const view = ref('day'); // 'day' | 'month' | 'year'

const displayValue = ref('');
const bsYear = ref(null);
const bsMonth = ref(null);
const bsDay = ref(null);

// The year/month currently shown in the day/month grids - independent of the
// selected date so navigating around doesn't change the selection until the
// user actually picks a day.
const viewYear = ref(todayBs.year);
const viewMonth = ref(todayBs.month);

const yearGridEl = ref(null);

function formatBs(year, month, day) {
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

// Tracks the AD string this component itself last emitted, so the
// prop-driven watcher below can tell "the parent changed modelValue out
// from under us" apart from "our own emit just came back down as a prop" -
// re-deriving BS fields from our own echoed value can otherwise fight an
// in-progress edit.
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
            displayValue.value = '';
            return;
        }

        try {
            const bs = adToBs(value);
            bsYear.value = bs.year;
            bsMonth.value = bs.month;
            bsDay.value = bs.day;
            displayValue.value = formatBs(bs.year, bs.month, bs.day);
            viewYear.value = bs.year;
            viewMonth.value = bs.month;
        } catch {
            // Out of the supported BS range or unparsable - leave the
            // fields blank rather than showing a wrong date.
            bsYear.value = null;
            bsMonth.value = null;
            bsDay.value = null;
            displayValue.value = '';
        }
    },
    { immediate: true },
);

// Whenever the popup opens (via the calendar button or focusing the input),
// jump the grids back to the day view for the currently selected date (or
// today, if nothing is selected yet) - mirrors the legacy picker re-syncing
// itself from the input on open.
watch(isOpen, (open) => {
    if (!open) {
        return;
    }

    view.value = 'day';
    viewYear.value = bsYear.value ?? todayBs.year;
    viewMonth.value = bsMonth.value ?? todayBs.month;
});

watch(view, async (value) => {
    if (value !== 'year') {
        return;
    }

    await nextTick();
    yearGridEl.value?.querySelector(`[data-year="${viewYear.value}"]`)?.scrollIntoView({ block: 'center' });
});

function emitAd(year, month, day) {
    try {
        const adString = bsToAdString(year, month, day);
        lastEmitted = adString;

        // Skip re-emitting a value the parent already has - avoids marking
        // an untouched form field dirty when this just ran because state
        // was (re)derived from an incoming prop.
        if (adString !== props.modelValue) {
            emit('update:modelValue', adString);
        }
    } catch {
        // Not a valid BS date - nothing to emit.
    }
}

/* ---------------- Typed input handling ---------------- */

function maskInput(raw) {
    const digits = raw.replace(/\D/g, '').slice(0, 8);
    let out = digits.slice(0, 4);
    if (digits.length > 4) {
        out += '-' + digits.slice(4, 6);
    }
    if (digits.length > 6) {
        out += '-' + digits.slice(6, 8);
    }
    return out;
}

function onInput(event) {
    const masked = maskInput(event.target.value);
    event.target.value = masked;
    displayValue.value = masked;
    isOpen.value = false;

    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(masked);
    if (!match) {
        if (!masked && props.modelValue !== '') {
            lastEmitted = '';
            emit('update:modelValue', '');
        }
        return;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);

    if (year < MIN_BS_YEAR || year > MAX_BS_YEAR || month < 1 || month > 12) {
        return;
    }

    let maxDay;
    try {
        maxDay = daysInBsMonth(year, month);
    } catch {
        return;
    }
    if (day < 1 || day > maxDay) {
        return;
    }

    bsYear.value = year;
    bsMonth.value = month;
    bsDay.value = day;
    viewYear.value = year;
    viewMonth.value = month;
    emitAd(year, month, day);
}

function onFocus() {
    isOpen.value = true;
}

function onBlur() {
    // Snap the field back to the last fully-valid date rather than leaving a
    // half-typed value sitting in it once the user moves on.
    displayValue.value = bsYear.value && bsMonth.value && bsDay.value ? formatBs(bsYear.value, bsMonth.value, bsDay.value) : '';
}

/* ---------------- Popup navigation ---------------- */

const monthLabel = computed(() => `${NEPALI_MONTH_NAMES[viewMonth.value - 1]} ${viewYear.value}`);

const isAtMinMonth = computed(() => viewYear.value <= MIN_BS_YEAR && viewMonth.value <= 1);
const isAtMaxMonth = computed(() => viewYear.value >= MAX_BS_YEAR && viewMonth.value >= 12);

const leadingBlanks = computed(() => {
    try {
        const ad = bsToAd(viewYear.value, viewMonth.value, 1);
        return new Date(ad.year, ad.month - 1, ad.day).getDay();
    } catch {
        return 0;
    }
});

const daysInView = computed(() => {
    try {
        return daysInBsMonth(viewYear.value, viewMonth.value);
    } catch {
        return 30;
    }
});

const dayCells = computed(() => [...Array(leadingBlanks.value).fill(null), ...Array.from({ length: daysInView.value }, (_, index) => index + 1)]);

function isToday(day) {
    return day === todayBs.day && viewMonth.value === todayBs.month && viewYear.value === todayBs.year;
}

function isSelectedDay(day) {
    return day === bsDay.value && viewMonth.value === bsMonth.value && viewYear.value === bsYear.value;
}

function shiftMonth(delta) {
    let year = viewYear.value;
    let month = viewMonth.value + delta;

    if (month < 1) {
        month = 12;
        year -= 1;
    } else if (month > 12) {
        month = 1;
        year += 1;
    }

    if (year < MIN_BS_YEAR || year > MAX_BS_YEAR) {
        return;
    }

    viewYear.value = year;
    viewMonth.value = month;
}

function shiftYear(delta) {
    const year = viewYear.value + delta;
    if (year < MIN_BS_YEAR || year > MAX_BS_YEAR) {
        return;
    }
    viewYear.value = year;
}

function pickDay(day) {
    bsYear.value = viewYear.value;
    bsMonth.value = viewMonth.value;
    bsDay.value = day;
    displayValue.value = formatBs(viewYear.value, viewMonth.value, day);
    emitAd(viewYear.value, viewMonth.value, day);
    isOpen.value = false;
}

function pickMonth(month) {
    viewMonth.value = month;
    view.value = 'day';
}

function pickYear(year) {
    viewYear.value = year;
    view.value = 'month';
}
</script>

<template>
    <PopoverRoot v-model:open="isOpen">
        <PopoverAnchor as-child>
            <div :class="cn('relative flex w-full items-center', props.class)">
                <input
                    v-bind="$attrs"
                    type="text"
                    inputmode="numeric"
                    :value="displayValue"
                    placeholder="YYYY-MM-DD"
                    :disabled="disabled"
                    :required="required"
                    class="h-9 w-full border-[1.5px] border-border bg-bg-subtle pr-9 pl-3 text-[13px] text-text-base outline-none transition-colors duration-150 placeholder:text-text-faint focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)] disabled:cursor-not-allowed disabled:opacity-50"
                    @input="onInput"
                    @focus="onFocus"
                    @blur="onBlur"
                    @keydown.esc="isOpen = false"
                />
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        :disabled="disabled"
                        tabindex="-1"
                        aria-label="Open calendar"
                        class="absolute right-1.5 flex h-6 w-6 shrink-0 items-center justify-center text-text-muted transition-colors duration-150 hover:text-primary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Calendar class="h-3.5 w-3.5" />
                    </button>
                </PopoverTrigger>
            </div>
        </PopoverAnchor>

        <PopoverPortal>
            <PopoverContent
                class="z-50 w-[272px] border-[1.5px] border-border bg-white p-3 shadow-[0_8px_24px_rgba(0,0,0,.12)]"
                position="popper"
                side="bottom"
                align="start"
                :side-offset="4"
                @open-auto-focus.prevent
            >
                <div v-if="view === 'day'">
                    <div class="mb-2.5 flex items-center justify-between gap-1">
                        <button type="button" :class="navButtonClass" :disabled="isAtMinMonth" aria-label="Previous month" @click="shiftMonth(-1)">
                            <ChevronLeft class="h-3.5 w-3.5" />
                        </button>
                        <button type="button" :class="headerButtonClass" @click="view = 'month'">
                            {{ monthLabel }}
                        </button>
                        <button type="button" :class="navButtonClass" :disabled="isAtMaxMonth" aria-label="Next month" @click="shiftMonth(1)">
                            <ChevronRight class="h-3.5 w-3.5" />
                        </button>
                    </div>
                    <div class="grid grid-cols-7 gap-0.5">
                        <div v-for="day in DAY_HEADERS" :key="day" class="py-1 text-center text-[10px] font-semibold tracking-wide text-text-faint uppercase">
                            {{ day }}
                        </div>
                        <template v-for="(cell, index) in dayCells" :key="index">
                            <div v-if="cell === null" />
                            <button
                                v-else
                                type="button"
                                :class="
                                    cn(
                                        'flex h-8 w-8 items-center justify-center text-[12.5px] font-medium text-text-base transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                                        isSelectedDay(cell) && 'bg-primary font-semibold text-white hover:bg-primary hover:text-white',
                                        isToday(cell) && !isSelectedDay(cell) && 'text-primary font-semibold ring-1 ring-primary ring-inset',
                                    )
                                "
                                @click="pickDay(cell)"
                            >
                                {{ cell }}
                            </button>
                        </template>
                    </div>
                </div>

                <div v-else-if="view === 'month'">
                    <div class="mb-2.5 flex items-center justify-between gap-1">
                        <button type="button" :class="navButtonClass" :disabled="viewYear <= MIN_BS_YEAR" aria-label="Previous year" @click="shiftYear(-1)">
                            <ChevronLeft class="h-3.5 w-3.5" />
                        </button>
                        <button type="button" :class="headerButtonClass" @click="view = 'year'">
                            {{ viewYear }}
                        </button>
                        <button type="button" :class="navButtonClass" :disabled="viewYear >= MAX_BS_YEAR" aria-label="Next year" @click="shiftYear(1)">
                            <ChevronRight class="h-3.5 w-3.5" />
                        </button>
                    </div>
                    <div class="grid grid-cols-3 gap-1.5">
                        <button
                            v-for="(name, index) in NEPALI_MONTH_NAMES"
                            :key="name"
                            type="button"
                            :class="
                                cn(
                                    'px-1 py-2.5 text-center text-[12px] font-medium text-text-base transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                                    index + 1 === viewMonth && 'bg-primary font-semibold text-white hover:bg-primary hover:text-white',
                                )
                            "
                            @click="pickMonth(index + 1)"
                        >
                            {{ name }}
                        </button>
                    </div>
                </div>

                <div v-else>
                    <div class="mb-2.5 text-center text-[13px] font-semibold text-text-strong">Select year</div>
                    <div ref="yearGridEl" class="grid max-h-[196px] grid-cols-4 gap-1 overflow-y-auto pr-0.5">
                        <button
                            v-for="year in YEAR_LIST"
                            :key="year"
                            type="button"
                            :data-year="year"
                            :class="
                                cn(
                                    'px-1 py-2 text-center text-[12px] font-medium text-text-base transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                                    year === viewYear && 'bg-primary font-semibold text-white hover:bg-primary hover:text-white',
                                )
                            "
                            @click="pickYear(year)"
                        >
                            {{ year }}
                        </button>
                    </div>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
