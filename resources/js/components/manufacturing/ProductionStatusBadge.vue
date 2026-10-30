<script setup>
import { computed } from 'vue';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps({ status: { type: String, required: true } });

const variants = { in_progress: 'info', completed: 'success', abandoned: 'warning', cancelled: 'danger' };
const labels = { in_progress: 'In progress', completed: 'Completed', abandoned: 'Abandoned', cancelled: 'Cancelled' };

const variant = computed(() => variants[props.status] ?? 'neutral');
const label = computed(() => labels[props.status] ?? props.status);
</script>

<template>
    <Badge :variant="variant" pill>{{ label }}</Badge>
</template>
