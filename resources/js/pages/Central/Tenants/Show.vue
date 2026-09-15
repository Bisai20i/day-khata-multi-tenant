<script setup>
import { computed, ref, onMounted } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Check,
    CircleCheck,
    CirclePause,
    CirclePlay,
    LogIn,
    Pencil,
    Plus,
    RotateCw,
    Settings,
    Trash2,
    Users,
    X,
    XCircle,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import { useToast } from '@/composables/useToast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { toast } = useToast();
const isOwner = computed(() => page.props.auth?.platformAdmin?.role === 'owner');
useLayoutChrome(() => props.tenant.company_name);

onMounted(() => {
    if (page.props.flash?.status) {
        toast({ message: page.props.flash.status, variant: 'success' });
    }
});

const showDeleteModal = ref(false);
const deleteConfirmName = ref('');
const canConfirmDelete = computed(() => deleteConfirmName.value === props.tenant.company_name);
const deleteIntent = ref('delete');

const statusBadgeVariant = {
    active: 'success',
    provisioning: 'warning',
    suspended: 'danger',
};

// Each action tracks its own in-flight flag so its own button (and only its
// own button) shows a loading spinner - two actions on this page can never
// legitimately run at once, but tying the flag to the specific action still
// keeps the spinner honest if that ever changes.
const suspending = ref(false);
const resuming = ref(false);
const impersonating = ref(false);
const retrying = ref(false);
const forcingActive = ref(false);
const deleting = ref(false);

function suspend() {
    router.post(`/tenants/${props.tenant.id}/suspend`, {}, { onStart: () => (suspending.value = true), onFinish: () => (suspending.value = false) });
}

function resume() {
    router.post(`/tenants/${props.tenant.id}/resume`, {}, { onStart: () => (resuming.value = true), onFinish: () => (resuming.value = false) });
}

function impersonate() {
    // The response is an Inertia::location() (not a normal redirect) since
    // the target is the tenant's own domain - Inertia's client
    // automatically performs a full-page navigation there instead of
    // trying to follow it as a same-origin visit.
    router.post(
        `/tenants/${props.tenant.id}/impersonate`,
        {},
        { onStart: () => (impersonating.value = true), onFinish: () => (impersonating.value = false) },
    );
}

function retryProvisioning() {
    router.post(
        `/tenants/${props.tenant.id}/retry-provisioning`,
        {},
        { onStart: () => (retrying.value = true), onFinish: () => (retrying.value = false) },
    );
}

function forceActive() {
    router.post(
        `/tenants/${props.tenant.id}/force-active`,
        {},
        { onStart: () => (forcingActive.value = true), onFinish: () => (forcingActive.value = false) },
    );
}

function openDeleteModal(intent = 'delete') {
    deleteConfirmName.value = '';
    deleteIntent.value = intent;
    showDeleteModal.value = true;
}

function confirmDelete() {
    if (!canConfirmDelete.value) {
        return;
    }

    router.delete(`/tenants/${props.tenant.id}`, {
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
}

const trialForm = useForm({ trial_ends_at: props.tenant.trial_ends_at ?? '' });

function updateTrial() {
    trialForm.put(`/tenants/${props.tenant.id}/trial`);
}

const domainForm = useForm({ domain: '' });

function addDomain() {
    domainForm.post(`/tenants/${props.tenant.id}/domains`, {
        onSuccess: () => domainForm.reset(),
    });
}

const removingDomainId = ref(null);

function removeDomain(domain) {
    removingDomainId.value = domain.id;
    router.delete(`/tenants/${props.tenant.id}/domains/${domain.id}`, {
        onFinish: () => (removingDomainId.value = null),
    });
}
</script>

<template>
    <div>
        <Link href="/tenants" class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary">
            <ArrowLeft class="size-4" />
            All tenants
        </Link>

        <div v-if="tenant.status === 'provisioning' && tenant.provisioning_error" class="mb-4 border-[1.5px] border-danger bg-danger-bg p-3">
            <p class="text-sm font-semibold text-danger">Provisioning failed</p>
            <p class="mt-1 text-sm text-danger">{{ tenant.provisioning_error }}</p>
            <Button class="mt-3" variant="secondary" tone="purple" :loading="retrying" @click="retryProvisioning">
                <RotateCw class="size-4" />
                Retry provisioning
            </Button>
        </div>

        <div v-else-if="tenant.database_missing" class="mb-4 border-[1.5px] border-danger bg-danger-bg p-3">
            <p class="text-sm font-semibold text-danger">Database missing</p>
            <p class="mt-1 text-sm text-danger">
                This tenant is marked "{{ tenant.status }}" but its database doesn't actually exist - an
                earlier provisioning run likely never finished. Users can't log in, and actions like
                "View users" or "Impersonate admin" will fail until this is re-provisioned.
            </p>
            <Button class="mt-3" variant="secondary" tone="purple" :loading="retrying" @click="retryProvisioning">
                <RotateCw class="size-4" />
                Re-provision database
            </Button>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[2fr_1fr]">
            <div class="flex flex-col gap-4">
                <Card variant="panel" title="Details">
                    <dl class="mb-6 text-sm">
                        <div class="flex items-center justify-between border-b border-border-soft py-2">
                            <dt class="text-text-muted">Status</dt>
                            <dd class="flex items-center gap-1.5">
                                <Badge :variant="statusBadgeVariant[tenant.status] ?? 'neutral'" pill>{{ tenant.status }}</Badge>
                                <Badge v-if="tenant.past_grace_period" variant="danger" pill>Past grace period</Badge>
                                <Badge v-if="tenant.trial_expired" variant="warning" pill>Trial expired</Badge>
                            </dd>
                        </div>
                        <div v-if="tenant.suspended_at" class="flex items-center justify-between border-b border-border-soft py-2">
                            <dt class="text-text-muted">Suspended on</dt>
                            <dd class="text-text-strong">{{ tenant.suspended_at }}</dd>
                        </div>
                        <div class="flex items-center justify-between border-b border-border-soft py-2">
                            <dt class="text-text-muted">Trial ends</dt>
                            <dd class="text-text-strong">{{ tenant.trial_ends_at || '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between border-b border-border-soft py-2">
                            <dt class="text-text-muted">Domain</dt>
                            <dd class="text-text-strong">{{ tenant.domain || '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between border-b border-border-soft py-2">
                            <dt class="text-text-muted">Contact email</dt>
                            <dd class="text-text-strong">{{ tenant.contact_email || '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between py-2">
                            <dt class="text-text-muted">Created</dt>
                            <dd class="text-text-strong">{{ tenant.created_at || '—' }}</dd>
                        </div>
                    </dl>

                    <div class="flex flex-wrap gap-2">
                        <Button :as="Link" :href="`/tenants/${tenant.id}/edit`" variant="secondary" tone="blue">
                            <Pencil class="size-4" />
                            Edit
                        </Button>

                        <Button :as="Link" :href="`/tenants/${tenant.id}/users`" variant="secondary" tone="blue">
                            <Users class="size-4" />
                            View users
                        </Button>

                        <Button :as="Link" :href="`/tenants/${tenant.id}/settings`" variant="secondary" tone="blue">
                            <Settings class="size-4" />
                            Settings
                        </Button>

                        <Button v-if="tenant.status === 'active'" variant="secondary" tone="purple" :loading="suspending" @click="suspend">
                            <CirclePause class="size-4" />
                            Suspend
                        </Button>
                        <Button v-else-if="tenant.status === 'suspended'" variant="secondary" tone="success" :loading="resuming" @click="resume">
                            <CirclePlay class="size-4" />
                            Resume
                        </Button>

                        <Button v-if="tenant.status === 'active'" variant="secondary" tone="blue" :loading="impersonating" @click="impersonate">
                            <LogIn class="size-4" />
                            Impersonate admin
                        </Button>

                        <Button variant="secondary" tone="danger" @click="openDeleteModal('delete')">
                            <Trash2 class="size-4" />
                            Delete
                        </Button>
                    </div>
                </Card>
            </div>

            <div class="flex flex-col gap-4">
                <Card v-if="tenant.status === 'provisioning' && isOwner" variant="panel" title="Provisioning controls">
                    <p class="mb-3 text-sm text-text-muted">
                        Manual overrides for a tenant stuck at Provisioning. Only use "Mark active" once you've
                        confirmed its database and admin user actually exist.
                    </p>
                    <div class="flex flex-col gap-2">
                        <Button variant="secondary" tone="success" :loading="forcingActive" @click="forceActive">
                            <CircleCheck class="size-4" />
                            Mark active
                        </Button>
                        <Button variant="secondary" tone="danger" @click="openDeleteModal('cancel')">
                            <XCircle class="size-4" />
                            Cancel provisioning
                        </Button>
                    </div>
                </Card>

                <Card v-if="isOwner" variant="panel" title="Trial expiry">
                    <form class="flex items-start gap-2" @submit.prevent="updateTrial">
                        <div class="flex-1">
                            <Input v-model="trialForm.trial_ends_at" type="date" />
                            <p v-if="trialForm.errors.trial_ends_at" class="mt-1 text-sm text-danger">{{ trialForm.errors.trial_ends_at }}</p>
                            <p class="mt-1 text-xs text-text-muted">Leave blank to remove the trial expiry.</p>
                        </div>
                        <Button type="submit" variant="secondary" tone="blue" :loading="trialForm.processing">
                            <Check class="size-4" />
                            Save
                        </Button>
                    </form>
                </Card>

                <Card variant="panel" title="Domains">
                    <ul class="mb-4 divide-y divide-border-soft text-sm">
                        <li v-for="domain in tenant.domains" :key="domain.id" class="flex items-center justify-between py-2">
                            <span class="text-text-strong">{{ domain.domain }}</span>
                            <button
                                type="button"
                                class="cursor-pointer text-text-muted transition-colors duration-150 hover:text-danger disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="tenant.domains.length <= 1 || removingDomainId === domain.id"
                                :title="tenant.domains.length <= 1 ? 'A tenant must have at least one domain' : 'Remove domain'"
                                @click="removeDomain(domain)"
                            >
                                <RotateCw v-if="removingDomainId === domain.id" class="size-4 animate-spin" />
                                <X v-else class="size-4" />
                            </button>
                        </li>
                    </ul>

                    <form class="flex items-start gap-2" @submit.prevent="addDomain">
                        <div class="flex-1">
                            <Input v-model="domainForm.domain" type="text" placeholder="extra.localhost" />
                            <p v-if="domainForm.errors.domain" class="mt-1 text-sm text-danger">{{ domainForm.errors.domain }}</p>
                        </div>
                        <Button type="submit" variant="secondary" tone="blue" :loading="domainForm.processing">
                            <Plus class="size-4" />
                            Add
                        </Button>
                    </form>
                </Card>
            </div>
        </div>

        <Modal v-model:open="showDeleteModal" :title="deleteIntent === 'cancel' ? 'Cancel provisioning' : 'Delete tenant'" size="compact">
            <div class="flex items-start gap-2">
                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-danger" aria-hidden="true" />
                <p class="text-sm text-text-base">
                    {{ deleteIntent === 'cancel' ? 'Cancel provisioning and delete this tenant?' : 'Delete this tenant and its database?' }}
                    This cannot be undone. Type
                    <span class="font-semibold text-text-strong">{{ tenant.company_name }}</span>
                    to confirm.
                </p>
            </div>
            <Input v-model="deleteConfirmName" type="text" class="mt-3" :placeholder="tenant.company_name" />

            <template #footer>
                <Button variant="secondary" tone="purple" @click="showDeleteModal = false">Cancel</Button>
                <Button variant="primary" tone="danger" :disabled="!canConfirmDelete" :loading="deleting" @click="confirmDelete">
                    {{ deleteIntent === 'cancel' ? 'Cancel provisioning' : 'Delete' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
