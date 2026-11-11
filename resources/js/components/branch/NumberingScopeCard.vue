<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Badge from '@/components/ui/Badge.vue';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * Per-branch numbering (plan 12, BR20) on the Fiscal Years page. Rendered
 * only when the page has the `numbering` prop, which the server sends only to
 * a multi-branch company (module on, more than one active branch), so a
 * Main-only company sees nothing new.
 *
 * Every year shows its numbering; the owner gets a change button on the years
 * whose numbering can still change (`numbering.changeable_year_ids`: never
 * closed, no voucher yet). The server re-checks every rule under a lock.
 */
const props = defineProps({
    fiscalYears: { type: Array, required: true },
    numbering: { type: Object, required: true },
});

const { label, plural } = useBranchLabel();

const changeable = computed(() => new Set((props.numbering.changeable_year_ids ?? []).map((id) => String(id))));
const missingSeriesCodes = computed(() => props.numbering.branches_missing_series_code ?? []);

function isPerBranch(year) {
    return year.numbering_scope === 'per_branch';
}

function canChange(year) {
    return props.numbering.can_change && changeable.value.has(String(year.id));
}

const modalOpen = ref(false);
const targetYear = ref(null);

const form = useForm({ numbering_scope: 'shared' });

const switchingToPerBranch = computed(() => form.numbering_scope === 'per_branch');

function openChange(year) {
    targetYear.value = year;
    form.reset();
    form.clearErrors();
    form.numbering_scope = isPerBranch(year) ? 'shared' : 'per_branch';
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    targetYear.value = null;
    form.reset();
    form.clearErrors();
}

function onOpenChange(value) {
    if (value) {
        modalOpen.value = true;
    } else {
        closeModal();
    }
}

function submit() {
    form.post(`/fiscal-years/${targetYear.value.id}/numbering-scope`, { preserveScroll: true, onSuccess: closeModal });
}
</script>

<template>
    <Card variant="panel" class="mt-4 bg-white">
        <div class="flex flex-col gap-3">
            <div>
                <p class="text-sm font-semibold text-text-strong">Document numbering</p>
                <p class="mt-1 text-xs text-text-muted">
                    Each fiscal year numbers invoices, notes and vouchers either in one shared series for the whole company
                    (the default) or in a separate series per {{ label('lower') }}, printed with the {{ label('lower') }}'s
                    series code (for example SI-KTM-12). The choice can only be made before the year's first document is
                    numbered. Check with your accountant or the IRD that separate series per {{ label('lower') }} are
                    acceptable for your business before you turn them on.
                </p>
            </div>

            <ul class="divide-y divide-border border border-border">
                <li v-for="year in fiscalYears" :key="year.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                    <div class="flex items-center gap-2">
                        <span class="text-[13px] font-semibold text-text-strong">{{ year.name }}</span>
                        <Badge :variant="isPerBranch(year) ? 'warning' : 'neutral'">
                            {{ isPerBranch(year) ? `Separate series per ${label('lower')}` : 'One shared series' }}
                        </Badge>
                    </div>
                    <Button v-if="canChange(year)" variant="secondary" tone="neutral" type="button" @click="openChange(year)">
                        {{ isPerBranch(year) ? 'Use one shared series' : `Number per ${label('lower')}` }}
                    </Button>
                </li>
            </ul>
        </div>

        <Modal :open="modalOpen" title="Change document numbering" size="compact" @update:open="onOpenChange">
            <div class="flex flex-col gap-3 text-sm text-text-muted">
                <template v-if="switchingToPerBranch">
                    <p>
                        "{{ targetYear?.name }}" will number every document type separately in each {{ label('lower') }}:
                        each {{ label('lower') }} starts at 1 and prints its series code in the number.
                    </p>
                    <p class="font-semibold text-text-strong">
                        Confirm with your accountant or the IRD that separate series per {{ label('lower') }} are acceptable
                        for your business before continuing.
                    </p>
                    <p v-if="missingSeriesCodes.length" class="text-danger">
                        These {{ plural('lower') }} have no series code yet: {{ missingSeriesCodes.join(', ') }}. Add one on the
                        {{ plural() }} page first.
                    </p>
                </template>
                <p v-else>"{{ targetYear?.name }}" will go back to one shared series for the whole company.</p>
                <p>This cannot be changed once the year's first document has been numbered.</p>
                <p v-if="form.errors.numbering_scope" class="text-danger">{{ form.errors.numbering_scope }}</p>
            </div>

            <template #footer>
                <Button variant="secondary" tone="neutral" type="button" @click="closeModal">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="button"
                    :loading="form.processing"
                    :disabled="form.processing || (switchingToPerBranch && missingSeriesCodes.length > 0)"
                    @click="submit"
                >
                    Change numbering
                </Button>
            </template>
        </Modal>
    </Card>
</template>
