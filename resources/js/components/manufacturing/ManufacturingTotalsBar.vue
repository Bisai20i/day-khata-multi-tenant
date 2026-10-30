<script setup>
import { Link } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';

/**
 * Sticky bottom bar for the manufacturing create forms, the same shape as
 * PurchaseCreateTotalsBar: the document's figures in a bordered strip (the
 * last one highlighted as the headline figure), then Cancel and the submit
 * button, pinned so a long form never scrolls them out of view.
 */
defineProps({
    // [{ label, value }] - values are already formatted strings.
    figures: { type: Array, default: () => [] },
    note: { type: String, default: '' },
    cancelHref: { type: String, required: true },
    submitLabel: { type: String, required: true },
    processingLabel: { type: String, default: 'Posting...' },
    canSubmit: { type: Boolean, default: true },
    processing: { type: Boolean, default: false },
});
</script>

<template>
    <div class="sticky -bottom-4 z-10 -mx-4 -mb-4 mt-auto flex flex-col gap-3 border-t-[1.5px] border-border bg-white px-4 py-3 shadow-[0_-4px_16px_rgba(0,0,0,.08)] sm:-bottom-6 sm:-mx-6 sm:-mb-6 sm:px-6">
        <div v-if="figures.length" class="overflow-x-auto border-[1.5px] border-border">
            <table class="w-full table-auto border-collapse text-sm">
                <thead>
                    <tr class="divide-x divide-border border-b-[1.5px] border-border bg-bg-subtle">
                        <th
                            v-for="(figure, index) in figures"
                            :key="figure.label"
                            class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] uppercase"
                            :class="index === figures.length - 1 ? 'bg-primary-tint text-primary' : 'text-text-muted'"
                        >
                            {{ figure.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="divide-x divide-border">
                        <td
                            v-for="(figure, index) in figures"
                            :key="figure.label"
                            class="px-3 py-1.5"
                            :class="index === figures.length - 1 ? 'bg-primary-tint text-base font-extrabold text-primary' : 'font-bold text-text-strong'"
                        >
                            {{ figure.value }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="note" class="text-xs text-text-muted">{{ note }}</p>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <Link :href="cancelHref">
                <Button variant="secondary" tone="purple" type="button">Cancel</Button>
            </Link>
            <Button variant="primary" tone="purple" type="submit" :loading="processing" :disabled="!canSubmit || processing">
                {{ processing ? processingLabel : submitLabel }}
            </Button>
        </div>
    </div>
</template>
