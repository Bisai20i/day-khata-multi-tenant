<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Search, SlidersHorizontal, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Badge from '@/components/ui/Badge.vue';
import Label from '@/components/ui/Label.vue';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    logs: {
        type: Object,
        default: () => ({ data: [], links: [], current_page: 1, last_page: 1, total: 0 }),
    },
    subjectTypes: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ subject_type: null, from: null, to: null }),
    },
});

useLayoutChrome('Activity Log');

const subjectTypeOptions = computed(() => props.subjectTypes.map((option) => ({ value: option.value, label: option.label })));

const subjectType = ref(props.filters.subject_type ?? null);
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

const filtering = ref(false);
const showFilters = ref(false);

function applyFilters() {
    router.get(
        window.location.pathname,
        {
            subject_type: subjectType.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, preserveScroll: true, onStart: () => (filtering.value = true), onFinish: () => (filtering.value = false) },
    );
}

const activeFilterChips = computed(() => {
    const chips = [];

    if (props.filters.subject_type) {
        const option = props.subjectTypes.find((type) => type.value === props.filters.subject_type);
        chips.push({ key: 'subject_type', label: option?.label ?? 'Record type' });
    }
    if (props.filters.from) chips.push({ key: 'from', label: `From ${formatBsDate(props.filters.from)}` });
    if (props.filters.to) chips.push({ key: 'to', label: `To ${formatBsDate(props.filters.to)}` });

    return chips;
});

function removeFilter(key) {
    if (key === 'subject_type') subjectType.value = null;
    if (key === 'from') from.value = '';
    if (key === 'to') to.value = '';
    applyFilters();
}

function clearFilters() {
    subjectType.value = null;
    from.value = '';
    to.value = '';
    router.get(
        window.location.pathname,
        {},
        { preserveState: true, preserveScroll: true, onStart: () => (filtering.value = true), onFinish: () => (filtering.value = false) },
    );
}

const actionVariants = {
    created: 'success',
    updated: 'warning',
    deleted: 'danger',
};

function subjectLabel(subjectType) {
    return subjectType?.split('\\').pop() ?? subjectType;
}

function humanizeAction(action) {
    if (!action) return '-';
    const text = String(action).replace(/[_.-]+/g, ' ').trim();
    return text.charAt(0).toUpperCase() + text.slice(1);
}

const hasActiveFilters = computed(() => !!(props.filters.subject_type || props.filters.from || props.filters.to));
</script>

<template>
    <div>
        <PageHeader title="Activity Log" description="A record of who created, changed or deleted data in your company, and when." />

        <Card variant="panel" class="mb-4 bg-white">
            <div class="flex items-center justify-between gap-2 md:hidden">
                <Button variant="secondary" tone="neutral" type="button" :aria-expanded="showFilters" @click="showFilters = !showFilters">
                    <SlidersHorizontal class="size-4" />
                    Filters
                    <span v-if="activeFilterChips.length" class="bg-bg-muted px-1.5 text-[11px] text-text-strong">{{ activeFilterChips.length }}</span>
                </Button>
            </div>
            <div :class="[showFilters ? 'mt-3 flex' : 'hidden', 'flex-wrap items-end gap-3 md:mt-0 md:flex']">
                <div class="min-w-[220px]">
                    <Label for="log-subject-type" class="mb-1">Record type</Label>
                    <Select id="log-subject-type" v-model="subjectType" :options="subjectTypeOptions" placeholder="All types" @update:model-value="applyFilters" />
                </div>
                <div class="min-w-[160px]">
                    <Label for="log-from" class="mb-1">From date (BS)</Label>
                    <NepaliDateInput id="log-from" v-model="from" />
                </div>
                <div class="min-w-[160px]">
                    <Label for="log-to" class="mb-1">To date (BS)</Label>
                    <NepaliDateInput id="log-to" v-model="to" />
                </div>
                <Button variant="secondary" tone="neutral" :loading="filtering" @click="applyFilters">
                    <Search class="size-4" />
                    Filter
                </Button>
            </div>
            <div v-if="activeFilterChips.length" class="mt-3 flex flex-wrap items-center gap-2 border-t border-border pt-3">
                <button
                    v-for="chip in activeFilterChips"
                    :key="chip.key"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 border-[1.5px] border-border bg-bg-subtle px-2 py-1 text-xs font-semibold text-text-base transition-colors duration-150 hover:bg-bg-muted focus-visible:outline-2 focus-visible:outline-primary"
                    :aria-label="`Remove filter: ${chip.label}`"
                    @click="removeFilter(chip.key)"
                >
                    {{ chip.label }}
                    <X class="size-3 text-text-muted" />
                </button>
                <button type="button" class="cursor-pointer text-xs font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-primary" @click="clearFilters">
                    Clear all
                </button>
            </div>
        </Card>

        <Card variant="panel" class="bg-white">
            <div v-if="logs.data.length > 0" class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-text-muted" aria-live="polite">Showing {{ logs.from }}–{{ logs.to }} of {{ logs.total }}</p>
            </div>

            <div v-if="logs.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <template v-if="hasActiveFilters">
                    <p class="text-sm font-semibold text-text-strong">No activity matches these filters</p>
                    <Button variant="secondary" tone="neutral" @click="clearFilters">
                        <X class="size-4" />
                        Clear filters
                    </Button>
                </template>
                <template v-else>
                    <p class="text-sm font-semibold text-text-strong">No activity yet</p>
                    <p class="text-xs text-text-muted">Changes made by your team will appear here.</p>
                </template>
            </div>

            <div v-else class="w-full overflow-x-auto">
                <table class="w-full border-separate [border-spacing:0_4px] text-[12.5px] text-text-base">
                    <thead>
                        <tr>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Date</th>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">User</th>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Action</th>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Subject</th>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in logs.data" :key="log.id" class="group">
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle first:border-l-[1.5px] last:border-r-[1.5px]">
                                {{ log.created_at }}
                            </td>
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle">
                                {{ log.user?.name ?? 'System' }}
                            </td>
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle">
                                <Badge :variant="actionVariants[log.action] ?? 'neutral'" pill>{{ humanizeAction(log.action) }}</Badge>
                            </td>
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle">
                                {{ subjectLabel(log.subject_type) }} #{{ log.subject_id }}
                            </td>
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle last:border-r-[1.5px]">
                                {{ log.description ?? '-' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="logs.data.length > 0 && logs.last_page > 1" aria-label="Activity log pagination" class="mt-3 flex items-center justify-end gap-2">
                <Link
                    v-if="logs.prev_page_url"
                    :href="logs.prev_page_url"
                    preserve-state
                    preserve-scroll
                    aria-label="Previous page"
                    class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                >
                    Previous
                </Link>
                <span v-else aria-disabled="true" class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40">Previous</span>
                <span class="text-xs text-text-muted" aria-current="page">Page {{ logs.current_page }} of {{ logs.last_page }}</span>
                <Link
                    v-if="logs.next_page_url"
                    :href="logs.next_page_url"
                    preserve-state
                    preserve-scroll
                    aria-label="Next page"
                    class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                >
                    Next
                </Link>
                <span v-else aria-disabled="true" class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40">Next</span>
            </nav>
        </Card>
    </div>
</template>
