<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Search, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import { usePermissions } from '@/composables/usePermissions';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import { filterNavByPermission } from '@/lib/filterNavByPermission';
import { reportCategories } from '@/lib/report-catalog';

defineOptions({ layout: AppLayout });

useLayoutChrome('Reports');

const { can } = usePermissions();
const query = ref('');

// Only the reports this user may open. A category with none left is dropped,
// so a role limited to sales reports sees just that card.
const allowedCategories = computed(() => filterNavByPermission(reportCategories(), can));

const categories = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (needle === '') return allowedCategories.value;

    return allowedCategories.value
        .map((category) => ({
            ...category,
            items: category.items.filter(
                (item) =>
                    item.label.toLowerCase().includes(needle) ||
                    item.description.toLowerCase().includes(needle) ||
                    category.label.toLowerCase().includes(needle),
            ),
        }))
        .filter((category) => category.items.length > 0);
});
</script>

<template>
    <div class="flex min-h-full flex-col">
        <PageHeader title="Reports" description="Every report in one place. Pick one to set its period and filters, then print or export it." />

        <div class="relative mb-4 max-w-md">
            <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-text-faint" />
            <input
                v-model="query"
                type="text"
                aria-label="Search reports"
                placeholder="Search reports..."
                class="w-full border-[1.5px] border-border bg-white py-2 pr-8 pl-8 text-sm text-text-base placeholder:text-text-faint focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                @keydown.esc="query = ''"
            />
            <button
                v-if="query"
                type="button"
                class="absolute top-1/2 right-1.5 flex size-6 -translate-y-1/2 cursor-pointer items-center justify-center text-text-faint hover:text-text-strong"
                aria-label="Clear search"
                @click="query = ''"
            >
                <X class="size-4" />
            </button>
        </div>

        <p v-if="allowedCategories.length === 0" class="border-[1.5px] border-border bg-white px-4 py-6 text-center text-sm text-text-muted">
            You do not have access to any reports. Ask an administrator to grant a reports permission to your role.
        </p>
        <p v-else-if="categories.length === 0" class="border-[1.5px] border-border bg-white px-4 py-6 text-center text-sm text-text-muted">
            No reports match "{{ query.trim() }}".
        </p>

        <div v-else class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2 2xl:grid-cols-3">
            <Card v-for="category in categories" :key="category.label" variant="panel" class="!bg-white !p-0">
                <template #title>
                    <span class="flex items-center gap-1.5">
                        <component :is="category.icon" class="size-3.5" />
                        {{ category.label }}
                    </span>
                </template>

                <p class="border-b border-border px-3 py-2 text-xs text-text-muted">{{ category.description }}</p>
                <ul class="divide-y divide-border">
                    <li v-for="item in category.items" :key="item.href">
                        <Link
                            :href="item.href"
                            class="group flex items-center justify-between gap-3 px-3 py-2.5 hover:bg-bg-subtle focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                        >
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-text-strong group-hover:text-primary">{{ item.label }}</span>
                                <span class="block text-xs text-text-muted">{{ item.description }}</span>
                            </span>
                            <ChevronRight class="size-4 shrink-0 text-text-faint group-hover:text-primary" aria-hidden="true" />
                        </Link>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
