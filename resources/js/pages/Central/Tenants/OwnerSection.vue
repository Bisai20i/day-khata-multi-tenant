<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, UserCog } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import { useConfirm } from '@/composables/useConfirm';

/**
 * Central recovery of a tenant's owner (plan section 5). Rendered by Show.vue
 * for platform owners only; the server enforces the same rule on the route.
 */
const props = defineProps({
    tenantId: { type: [String, Number], required: true },
    owner: { type: Object, default: null },
    candidates: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const form = useForm({ user_id: null });

// The current owner is not a valid target, so it is not offered.
const options = computed(() =>
    props.candidates
        .filter((user) => user.id !== props.owner?.id)
        .map((user) => ({
            value: user.id,
            label: `${user.name} (${user.email})${user.role ? `, ${user.role}` : ''}`,
        })),
);

const selected = computed(() => props.candidates.find((user) => user.id === form.user_id) ?? null);

async function reassign() {
    if (!selected.value) {
        return;
    }

    const confirmed = await confirm({
        title: 'Reassign owner',
        message: props.owner
            ? `Make ${selected.value.name} the owner? ${props.owner.name} stops being the owner immediately and is limited to their role's permissions.`
            : `Make ${selected.value.name} the owner of this company?`,
        tone: 'danger',
        confirmLabel: 'Reassign owner',
    });

    if (!confirmed) {
        return;
    }

    form.post(`/tenants/${props.tenantId}/owner`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Card variant="panel" title="Owner" class="bg-bg-surface p-4 sm:p-5">
        <div v-if="owner" class="mb-3 text-sm">
            <p class="text-text-muted">Current owner</p>
            <p class="font-semibold text-text-strong">{{ owner.name }}</p>
            <p class="text-text-muted">{{ owner.email }}</p>
        </div>
        <div v-else class="mb-3 flex items-start gap-2 border-[1.5px] border-warning bg-warning-bg p-3" role="alert">
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            <p class="text-sm text-text-base">No owner. Nobody can administer this company until you pick one below.</p>
        </div>

        <form class="flex flex-col gap-2" @submit.prevent="reassign">
            <label class="block text-sm font-semibold text-text-base">
                {{ owner ? 'Transfer ownership to' : 'Make this user the owner' }}
                <Select v-model="form.user_id" class="mt-1 font-normal" :options="options" placeholder="Choose an active user" />
            </label>
            <p v-if="form.errors.user_id" class="text-sm text-danger">{{ form.errors.user_id }}</p>
            <p v-if="options.length === 0" class="text-xs text-text-muted">This tenant has no other active users.</p>
            <div>
                <Button type="submit" variant="secondary" tone="danger" :loading="form.processing" :disabled="form.user_id === null">
                    <UserCog class="size-4" />
                    Reassign owner
                </Button>
            </div>
        </form>
    </Card>
</template>
