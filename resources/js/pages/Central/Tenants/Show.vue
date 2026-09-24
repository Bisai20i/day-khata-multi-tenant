<script setup>
import { computed, ref, onMounted } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
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
import PageHeader from '@/components/ui/PageHeader.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
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

async function suspend() {
    const confirmed = await confirm({
        title: 'Suspend tenant',
        message: `Suspend ${props.tenant.company_name}? Its users will be blocked from signing in until you resume it. No data is deleted.`,
        tone: 'danger',
        confirmLabel: 'Suspend tenant',
    });

    if (!confirmed) {
        return;
    }

    router.post(`/tenants/${props.tenant.id}/suspend`, {}, { onStart: () => (suspending.value = true), onFinish: () => (suspending.value = false) });
}

async function resume() {
    const confirmed = await confirm({
        title: 'Resume tenant',
        message: `Resume ${props.tenant.company_name}? Its users will be able to sign in again.`,
        tone: 'success',
        confirmLabel: 'Resume tenant',
    });

    if (!confirmed) {
        return;
    }

    router.post(`/tenants/${props.tenant.id}/resume`, {}, { onStart: () => (resuming.value = true), onFinish: () => (resuming.value = false) });
}

async function impersonate() {
    const confirmed = await confirm({
        title: 'Impersonate admin',
        message: `You will be signed in to ${props.tenant.company_name} as its first admin user. Actions you take there are real and are attributed to that user.`,
        tone: 'blue',
        confirmLabel: 'Impersonate admin',
    });

    if (!confirmed) {
        return;
    }

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

async function forceActive() {
    const confirmed = await confirm({
        title: 'Mark tenant active',
        message: 'Only continue if you have confirmed the tenant database and admin user actually exist.',
        tone: 'success',
        confirmLabel: 'Mark active',
    });

    if (!confirmed) {
        return;
    }

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
        <PageHeader
            :title="tenant.company_name"
            :description="tenant.domain || 'No domain set'"
            back-href="/tenants"
            back-label="All tenants"
        >
            <Badge :variant="statusBadgeVariant[tenant.status] ?? 'neutral'" pill>{{ tenant.status }}</Badge>
            <Button :as="Link" :href="`/tenants/${tenant.id}/settings`" variant="primary" tone="purple">
                <Settings class="size-4" />
                Open tenant settings
            </Button>
            <Button :as="Link" :href="`/tenants/${tenant.id}/users`" variant="secondary" tone="blue">
                <Users class="size-4" />
                Manage users
            </Button>
            <Button :as="Link" :href="`/tenants/${tenant.id}/edit`" variant="secondary" tone="blue">
                <Pencil class="size-4" />
                Edit details
            </Button>
        </PageHeader>

        <div v-if="tenant.status === 'provisioning' && tenant.provisioning_error" class="mb-4 border-[1.5px] border-danger bg-danger-bg p-3" role="alert">
            <p class="text-sm font-semibold text-danger">Provisioning failed</p>
            <p class="mt-1 text-sm text-danger">{{ tenant.provisioning_error }}</p>
            <Button class="mt-3" variant="secondary" tone="purple" :loading="retrying" @click="retryProvisioning">
                <RotateCw class="size-4" />
                Retry provisioning
            </Button>
        </div>

        <div v-else-if="tenant.database_missing" class="mb-4 border-[1.5px] border-danger bg-danger-bg p-3" role="alert">
            <p class="text-sm font-semibold text-danger">Database missing</p>
            <p class="mt-1 text-sm text-danger">
                This tenant is marked "{{ tenant.status }}" but its database doesn't actually exist - an
                earlier provisioning run likely never finished. Users can't log in, and actions like
                "Manage users" or "Impersonate admin" will fail until this is re-provisioned.
            </p>
            <Button class="mt-3" variant="secondary" tone="purple" :loading="retrying" @click="retryProvisioning">
                <RotateCw class="size-4" />
                Re-provision database
            </Button>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[2fr_1fr]">
            <div class="flex flex-col gap-4">
                <Card variant="panel" title="Status and plan">
                    <p class="mb-3 text-sm text-text-muted">
                        <template v-if="tenant.status === 'active'">This tenant is live and its users can sign in.</template>
                        <template v-else-if="tenant.status === 'suspended'">This tenant is suspended. Its users cannot sign in, but no data has been deleted. Resume it to restore access.</template>
                        <template v-else-if="tenant.status === 'provisioning'">This tenant is still being set up (creating its database and first admin user) and is not usable yet.</template>
                        <template v-else>Current status: {{ tenant.status }}.</template>
                        <template v-if="tenant.trial_expired"> The free trial has ended.</template>
                        <template v-if="tenant.past_grace_period"> The grace period after the trial has also ended, so access is restricted until the trial end date is extended.</template>
                    </p>
                    <dl class="text-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-border-soft py-2">
                            <dt class="text-text-muted">Status</dt>
                            <dd class="flex items-center gap-1.5">
                                <Badge :variant="statusBadgeVariant[tenant.status] ?? 'neutral'" pill>{{ tenant.status }}</Badge>
                                <Badge v-if="tenant.past_grace_period" variant="danger" pill>Past grace period</Badge>
                                <Badge v-if="tenant.trial_expired" variant="warning" pill>Trial expired</Badge>
                            </dd>
                        </div>
                        <div v-if="tenant.suspended_at" class="flex items-center justify-between gap-3 border-b border-border-soft py-2">
                            <dt class="text-text-muted">Suspended on</dt>
                            <dd class="text-text-strong">{{ tenant.suspended_at }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-2">
                            <dt class="text-text-muted">Trial ends</dt>
                            <dd class="text-text-strong">{{ tenant.trial_ends_at || 'Not set' }}</dd>
                        </div>
                    </dl>
                </Card>

                <Card variant="panel" title="Company details">
                    <dl class="text-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-border-soft py-2">
                            <dt class="text-text-muted">Company name</dt>
                            <dd class="text-text-strong">{{ tenant.company_name }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-b border-border-soft py-2">
                            <dt class="text-text-muted">Primary domain</dt>
                            <dd class="text-text-strong">{{ tenant.domain || 'Not set' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-b border-border-soft py-2">
                            <dt class="text-text-muted">Contact email</dt>
                            <dd class="text-text-strong">{{ tenant.contact_email || 'Not set' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-2">
                            <dt class="text-text-muted">Created</dt>
                            <dd class="text-text-strong">{{ tenant.created_at || 'Not set' }}</dd>
                        </div>
                    </dl>
                </Card>

                <Card variant="panel" title="Support and account actions">
                    <div v-if="tenant.status === 'active'" class="mb-4">
                        <p class="mb-2 text-sm text-text-muted">Sign in to this tenant as its first admin, for support or troubleshooting.</p>
                        <Button variant="secondary" tone="blue" :loading="impersonating" @click="impersonate">
                            <LogIn class="size-4" />
                            Impersonate admin
                        </Button>
                    </div>

                    <div class="border-[1.5px] border-danger p-3">
                        <p class="text-sm font-semibold text-danger">Danger zone</p>
                        <div v-if="tenant.status === 'active'" class="mt-2">
                            <p class="mb-2 text-sm text-text-muted">Suspending blocks all of this tenant's users from signing in. Data is kept.</p>
                            <Button variant="secondary" tone="danger" :loading="suspending" @click="suspend">
                                <CirclePause class="size-4" />
                                Suspend tenant
                            </Button>
                        </div>
                        <div v-else-if="tenant.status === 'suspended'" class="mt-2">
                            <p class="mb-2 text-sm text-text-muted">Resuming restores sign-in access for this tenant's users.</p>
                            <Button variant="secondary" tone="success" :loading="resuming" @click="resume">
                                <CirclePlay class="size-4" />
                                Resume tenant
                            </Button>
                        </div>
                        <div class="mt-3">
                            <p class="mb-2 text-sm text-text-muted">Permanently deletes this tenant and its database. This cannot be undone.</p>
                            <Button variant="secondary" tone="danger" @click="openDeleteModal('delete')">
                                <Trash2 class="size-4" />
                                Delete tenant
                            </Button>
                        </div>
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

                <Card v-if="isOwner" variant="panel" title="Trial end date">
                    <form class="flex items-start gap-2" @submit.prevent="updateTrial">
                        <div class="flex-1">
                            <label for="trial_ends_at" class="mb-1 block text-sm font-semibold text-text-base">Trial end date</label>
                            <Input
                                id="trial_ends_at"
                                v-model="trialForm.trial_ends_at"
                                type="date"
                                :aria-describedby="trialForm.errors.trial_ends_at ? 'trial_ends_at-error' : 'trial_ends_at-help'"
                            />
                            <p v-if="trialForm.errors.trial_ends_at" id="trial_ends_at-error" class="mt-1 text-sm text-danger">{{ trialForm.errors.trial_ends_at }}</p>
                            <p id="trial_ends_at-help" class="mt-1 text-xs text-text-muted">Leave blank to remove the trial expiry.</p>
                        </div>
                        <Button type="submit" class="mt-6" variant="secondary" tone="blue" :loading="trialForm.processing">
                            <Check class="size-4" />
                            Save date
                        </Button>
                    </form>
                </Card>

                <Card variant="panel" title="Domains">
                    <p class="mb-3 text-sm text-text-muted">Addresses this tenant can be reached at. At least one is required.</p>
                    <ul class="mb-4 divide-y divide-border-soft text-sm">
                        <li v-for="domain in tenant.domains" :key="domain.id" class="flex items-center justify-between py-2">
                            <span class="text-text-strong">{{ domain.domain }}</span>
                            <button
                                type="button"
                                class="cursor-pointer text-text-muted transition-colors duration-150 hover:text-danger disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="tenant.domains.length <= 1 || removingDomainId === domain.id"
                                :title="tenant.domains.length <= 1 ? 'A tenant must have at least one domain' : 'Remove domain'"
                                :aria-label="`Remove domain ${domain.domain}`"
                                @click="removeDomain(domain)"
                            >
                                <RotateCw v-if="removingDomainId === domain.id" class="size-4 animate-spin" />
                                <X v-else class="size-4" />
                            </button>
                        </li>
                    </ul>

                    <form class="flex items-start gap-2" @submit.prevent="addDomain">
                        <div class="flex-1">
                            <label for="new_domain" class="mb-1 block text-sm font-semibold text-text-base">Add another domain</label>
                            <Input
                                id="new_domain"
                                v-model="domainForm.domain"
                                type="text"
                                placeholder="extra.localhost"
                                :aria-describedby="domainForm.errors.domain ? 'new_domain-error' : undefined"
                            />
                            <p v-if="domainForm.errors.domain" id="new_domain-error" class="mt-1 text-sm text-danger">{{ domainForm.errors.domain }}</p>
                        </div>
                        <Button type="submit" class="mt-6" variant="secondary" tone="blue" :loading="domainForm.processing">
                            <Plus class="size-4" />
                            Add domain
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
            <Input v-model="deleteConfirmName" type="text" class="mt-3" :placeholder="tenant.company_name" aria-label="Type the company name to confirm" />

            <template #footer>
                <Button variant="secondary" tone="purple" @click="showDeleteModal = false">Keep tenant</Button>
                <Button variant="primary" tone="danger" :disabled="!canConfirmDelete" :loading="deleting" @click="confirmDelete">
                    {{ deleteIntent === 'cancel' ? 'Cancel provisioning' : 'Delete tenant' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
