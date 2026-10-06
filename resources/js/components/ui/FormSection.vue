<script setup>
import Card from '@/components/ui/Card.vue';

/**
 * One block of a settings-style form: a heading and plain-language
 * description in a left rail, the fields in a card on the right. The rail
 * stacks above the card below `lg`, so long forms use the full page width
 * on desktop instead of a narrow left-aligned column.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <section class="grid grid-cols-1 gap-x-10 gap-y-3 lg:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]">
        <div class="lg:pt-1">
            <h3 class="text-sm font-bold text-text-strong">{{ title }}</h3>
            <p v-if="description" class="mt-1 text-[13px] leading-relaxed text-text-muted">{{ description }}</p>
            <slot name="aside" />
        </div>
        <Card variant="panel" class="bg-bg-surface p-4 sm:p-5">
            <slot />
        </Card>
    </section>
</template>
