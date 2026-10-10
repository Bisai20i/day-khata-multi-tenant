<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Previous/Next links for a Laravel LengthAwarePaginator prop, the same
 * markup the receipts list uses inline. The "Showing 1-25 of 80" summary is
 * rendered by the page, at the top of its table card.
 */
defineProps({
    paginator: { type: Object, required: true },
    label: { type: String, default: 'Pagination' },
});

const linkClass =
    'inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary';
const disabledClass =
    'inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40';
</script>

<template>
    <div v-if="paginator.data.length > 0">
        <nav v-if="paginator.last_page > 1" :aria-label="label" class="mt-3 flex items-center justify-end gap-2">
            <Link v-if="paginator.prev_page_url" :href="paginator.prev_page_url" preserve-state preserve-scroll aria-label="Previous page" :class="linkClass">
                Previous
            </Link>
            <span v-else aria-disabled="true" :class="disabledClass">Previous</span>
            <span class="text-xs text-text-muted" aria-current="page">Page {{ paginator.current_page }} of {{ paginator.last_page }}</span>
            <Link v-if="paginator.next_page_url" :href="paginator.next_page_url" preserve-state preserve-scroll aria-label="Next page" :class="linkClass">
                Next
            </Link>
            <span v-else aria-disabled="true" :class="disabledClass">Next</span>
        </nav>
    </div>
</template>
