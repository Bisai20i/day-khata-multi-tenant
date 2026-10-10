<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Label from '@/components/ui/Label.vue';
import PermissionMatrix from './PermissionMatrix.vue';
import { changedKeys } from '@/lib/roleMatrix';

defineOptions({ layout: AppLayout });

/**
 * Create and edit screen for one role (role is null when creating). The
 * server sends only the entitled, non-owner-only part of the catalog
 * (`modules`) and only the role's keys from that part (`granted`); grants in
 * switched-off modules stay on the server and are kept on save
 * (`dormantCount` just tells the owner they exist).
 */
const props = defineProps({
    role: { type: Object, default: null },
    granted: { type: Array, default: () => [] },
    dormantCount: { type: Number, default: 0 },
    modules: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();

const isEditing = computed(() => props.role !== null);
const isSystem = computed(() => Boolean(props.role?.is_system));
useLayoutChrome(() => (isEditing.value ? `Edit role: ${props.role.name}` : 'New role'));

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const form = useForm({
    name: props.role?.name ?? '',
    permissions: [...props.granted],
    updated_at: props.role?.updated_at ?? null,
});

/** The saved selection the matrix highlights changes against. */
const original = ref([...props.granted]);

const changeCount = computed(() => changedKeys(original.value, form.permissions).size);
const isDirty = computed(() => form.isDirty);

const permissionErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([field]) => field === 'permissions' || field.startsWith('permissions.'))
        .map(([, message]) => message),
);

/**
 * After a save the server redirects back to this screen with fresh props
 * (new updated_at, normalised key list). Re-seed the form from them so the
 * next save carries the new timestamp and the page is clean again.
 */
function reseedFromProps() {
    form.name = props.role?.name ?? '';
    form.permissions = [...props.granted];
    form.updated_at = props.role?.updated_at ?? null;
    form.defaults();
    original.value = [...props.granted];
}

function submit() {
    const options = { preserveScroll: true, onSuccess: reseedFromProps };

    if (isEditing.value) {
        form.put(`/admin/roles/${props.role.id}`, options);
    } else {
        form.post('/admin/roles', options);
    }
}

function discardChanges() {
    form.reset();
    form.clearErrors();
}

/*
 * Unsaved-changes guard. Inertia fires a cancelable `before` event for every
 * visit; returning false from a router.on() listener cancels it. Only GET
 * visits (links, menu items) are guarded: our own PUT/POST must go through.
 * The confirm dialog is async, so the visit is cancelled first and replayed
 * once the owner agrees. beforeunload covers reloads and closing the tab.
 * Browser back/forward restores history without a `before` event, so it is
 * not guarded.
 */
let leaveConfirmed = false;

function leaveTo(url) {
    leaveConfirmed = true;
    router.visit(url);
}

async function askToLeave(url) {
    const ok = await confirm({
        title: 'Leave without saving?',
        message: `You have ${changeCount.value || 'some'} unsaved ${changeCount.value === 1 ? 'change' : 'changes'} to this role. They will be lost.`,
        tone: 'danger',
        confirmLabel: 'Leave page',
        cancelLabel: 'Stay',
    });
    if (ok) leaveTo(url);
}

const removeBeforeListener = router.on('before', (event) => {
    const visit = event.detail.visit;
    // Background partial reloads and prefetches do not leave the page.
    const isBackground = visit.async || visit.prefetch || visit.only.length > 0 || visit.except.length > 0;
    if (leaveConfirmed || !isDirty.value || visit.method !== 'get' || isBackground) return undefined;
    askToLeave(visit.url.href ?? String(visit.url));
    return false;
});

function onBeforeUnload(event) {
    if (!isDirty.value) return;
    event.preventDefault();
    event.returnValue = '';
}

onMounted(() => window.addEventListener('beforeunload', onBeforeUnload));
onBeforeUnmount(() => {
    removeBeforeListener();
    window.removeEventListener('beforeunload', onBeforeUnload);
});

/** Stale-save recovery: drop local edits and load what the other session saved. */
function reloadLatest() {
    leaveConfirmed = true;
    router.visit(window.location.href, { preserveState: false, preserveScroll: true });
}
</script>

<template>
    <div>
        <PageHeader
            :title="isEditing ? role.name : 'New role'"
            description="Tick what people with this role may see and do. Only modules enabled for your company are listed."
            back-href="/admin/roles"
            back-label="Roles"
        >
            <Badge v-if="isSystem" variant="neutral" pill>Built-in role</Badge>
        </PageHeader>

        <p
            v-if="form.errors.updated_at"
            class="mb-3 flex flex-wrap items-center gap-3 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm font-semibold text-danger"
            role="alert"
        >
            <span class="flex-1">{{ form.errors.updated_at }}</span>
            <Button variant="secondary" tone="danger" type="button" @click="reloadLatest">Reload latest</Button>
        </p>

        <p v-if="isSystem" class="mb-3 border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-sm text-warning-text">
            This is a built-in role, so its name cannot be changed. You can still change its permissions, and the change
            applies to everyone who holds it
            <template v-if="role.users_count"> ({{ role.users_count }} {{ role.users_count === 1 ? 'user' : 'users' }})</template>
            on their next page load.
        </p>
        <p v-else-if="isEditing && role.users_count" class="mb-3 text-sm text-text-muted">
            {{ role.users_count }} {{ role.users_count === 1 ? 'user holds' : 'users hold' }} this role. Changes apply on their next page load.
        </p>

        <Card variant="panel" class="mb-4">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="max-w-md">
                    <Label for="role_name" class="mb-1">Role name <span class="text-danger">*</span></Label>
                    <Input id="role_name" v-model="form.name" type="text" placeholder="e.g. Cashier" :disabled="isSystem" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>
            </form>
        </Card>

        <p v-if="dormantCount" class="mb-3 text-sm text-text-muted">
            This role also holds {{ dormantCount }} {{ dormantCount === 1 ? 'permission' : 'permissions' }} from modules that are
            currently switched off for your company. They are kept and take effect again if those modules are switched back on.
        </p>

        <div v-if="permissionErrors.length" class="mb-3 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
            <p class="font-semibold">Some permissions could not be saved:</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="(message, index) in permissionErrors" :key="index">{{ message }}</li>
            </ul>
        </div>

        <PermissionMatrix v-model="form.permissions" :modules="modules" :original="original" :disabled="form.processing" />

        <div class="sticky bottom-0 mt-4 flex flex-wrap items-center justify-end gap-3 border-t-[1.5px] border-border bg-white py-3">
            <span v-if="changeCount" class="mr-auto text-sm font-semibold text-warning-text">
                {{ changeCount }} unsaved {{ changeCount === 1 ? 'permission change' : 'permission changes' }}
            </span>
            <Button v-if="isDirty" variant="secondary" tone="purple" type="button" :disabled="form.processing" @click="discardChanges">
                Discard changes
            </Button>
            <Button variant="primary" tone="purple" type="button" :disabled="form.processing" @click="submit">
                {{ form.processing ? 'Saving...' : isEditing ? 'Save role' : 'Create role' }}
            </Button>
        </div>
    </div>
</template>
