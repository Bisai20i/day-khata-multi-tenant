<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Badge from '@/components/ui/Badge.vue';
import { navGroups } from '@/lib/nav-items.js';

/**
 * Audit trail over document reprints (contract C9). Every column here comes
 * from the server already formatted, including the BS date, so this page
 * never converts or recomputes anything.
 */
const props = defineProps({
    logs: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0 }),
    },
    documentTypes: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ from: null, to: null, printable_type: null, copies_only: false }),
    },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const documentTypeOptions = computed(() =>
    props.documentTypes.map((option) => ({ value: option.value, label: option.label })),
);

const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const printableType = ref(props.filters.printable_type ?? null);
const copiesOnly = ref(Boolean(props.filters.copies_only));

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            from: from.value || undefined,
            to: to.value || undefined,
            printable_type: printableType.value || undefined,
            copies_only: copiesOnly.value ? 1 : undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function clearFilter() {
    from.value = '';
    to.value = '';
    printableType.value = null;
    copiesOnly.value = false;
    router.get(window.location.pathname, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Print Log" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Print Log</h2>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="min-w-[180px]">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Document type</label>
                    <Select v-model="printableType" :options="documentTypeOptions" placeholder="All types" />
                </div>
                <label class="flex items-center gap-2 pb-2 text-xs font-semibold text-text-muted">
                    <input v-model="copiesOnly" type="checkbox" class="h-3.5 w-3.5 accent-primary" />
                    Reprints only
                </label>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
                <Button variant="secondary" tone="purple" @click="clearFilter">Clear</Button>
            </div>
        </Card>

        <Card variant="panel">
            <p v-if="logs.data.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No documents printed in this range.
            </p>

            <div v-else class="w-full overflow-x-auto">
                <table class="w-full border-separate [border-spacing:0_4px] text-[12.5px] text-text-base">
                    <thead>
                        <tr>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Printed (BS)</th>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Printed (AD)</th>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Document</th>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Number</th>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Copy</th>
                            <th class="border-b-[1.5px] border-border px-[9px] py-2 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Printed by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in logs.data" :key="log.id" class="group">
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle first:border-l-[1.5px]">
                                {{ log.printed_at_bs || '-' }}
                            </td>
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle">
                                {{ log.printed_at_ad || '-' }}
                            </td>
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle">
                                {{ log.document_type }}
                            </td>
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle">
                                {{ log.document_number }}
                            </td>
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle">
                                <Badge :variant="log.is_copy ? 'warning' : 'neutral'" pill>
                                    {{ log.is_copy ? `Copy of Original - ${log.copy_number - 1}` : 'Original' }}
                                </Badge>
                            </td>
                            <td class="border-y-[1.5px] border-border bg-white px-[9px] py-2 align-middle last:border-r-[1.5px]">
                                {{ log.printed_by ?? 'System' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="logs.data.length > 0" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-text-muted">
                    Showing {{ logs.from }}-{{ logs.to }} of {{ logs.total }}
                </p>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="logs.prev_page_url"
                        :href="logs.prev_page_url"
                        preserve-state
                        preserve-scroll
                        class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                    >
                        Previous
                    </Link>
                    <span
                        v-else
                        class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40"
                    >
                        Previous
                    </span>
                    <span class="text-xs text-text-muted">Page {{ logs.current_page }} of {{ logs.last_page }}</span>
                    <Link
                        v-if="logs.next_page_url"
                        :href="logs.next_page_url"
                        preserve-state
                        preserve-scroll
                        class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                    >
                        Next
                    </Link>
                    <span
                        v-else
                        class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40"
                    >
                        Next
                    </span>
                </div>
            </div>
        </Card>
    </AppLayout>
</template>
