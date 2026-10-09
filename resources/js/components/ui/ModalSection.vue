<script setup>
import InfoTip from '@/components/ui/InfoTip.vue';

/**
 * One titled group of fields inside a long modal form, so the form reads as a
 * few short steps instead of one wall of inputs. With `descriptionAsTooltip`
 * the description sits behind an info icon right of the title. The `actions`
 * slot puts a control at the right end of the title row.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    descriptionAsTooltip: { type: Boolean, default: true },
});
</script>

<template>
    <section class="flex flex-col gap-3">
        <div>
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-1">
                    <h3 class="text-[11px] font-bold tracking-wider text-text-muted uppercase">{{ title }}</h3>
                    <InfoTip v-if="description && descriptionAsTooltip" :text="description" />
                </div>
                <slot name="actions" />
            </div>
            <p v-if="description && !descriptionAsTooltip" class="mt-0.5 text-xs leading-relaxed text-text-muted">{{ description }}</p>
        </div>
        <slot />
    </section>
</template>
