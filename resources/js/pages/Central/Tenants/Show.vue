<script setup>
import { computed, ref, watch } from 'vue';
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
    ShieldAlert,
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
import InfoTip from '@/components/ui/InfoTip.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import EditDomainModal from './EditDomainModal.vue';
import ModuleSelector from './ModuleSelector.vue';
import OwnerSection from './OwnerSection.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
    moduleCatalog: {
        type: Array,
        default: () => [],
    },
    owner: {
        type: Object,
        default: null,
    },
    ownerCandidates: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
const isOwner = computed(() => page.props.auth?.platformAdmin?.role === 'owner');
useLayoutChrome(() => props.tenant.company_name);

// Actions here redirect back to this same page, and Inertia patches the
// mounted instance rather than remounting it, so the flash prop is watched
// (immediate for the first load) instead of read once in onMounted: that way
// every save, including the modules save, shows its toast.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showDeleteModal = ref(false);
const deleteConfirmName = ref('');
const canConfirmDelete = computed(() => deleteConfirmName.value === props.tenant.company_name);
const deleteIntent = ref('delete');

const statusBadgeVariant = {
    active: 'success',
    provisioning: 'warning',
    suspended: 'danger',
};

const statusLabel = computed(() => {
    const status = String(props.tenant.status ?? '');

    return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown';
});

const summaryTiles = computed(() => [
    { label: 'Status', value: statusLabel.value },
    { label: 'Primary domain', value: props.tenant.domain || 'Not set' },
    { label: 'Trial ends', value: props.tenant.trial_ends_at || 'No expiry' },
    { label: 'Created', value: props.tenant.created_at || 'Unknown' },
]);

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

const modulesForm = useForm({ enabled_modules: [...(props.tenant.enabled_modules ?? [])] });

// Per-item errors (enabled_modules.2) are folded into one line under the list.
const modulesError = computed(
    () =>
        modulesForm.errors.enabled_modules ??
        Object.entries(modulesForm.errors).find(([key]) => key.startsWith('enabled_modules.'))?.[1] ??
        '',
);

function updateModules() {
    modulesForm.put(`/tenants/${props.tenant.id}/modules`, {
        preserveScroll: true,
        // Re-sync from the server's resolved list, which is the stored truth.
        onSuccess: () => {
            modulesForm.defaults({ enabled_modules: [...(props.tenant.enabled_modules ?? [])] });
            modulesForm.reset();
        },
    });
}

const domainForm = useForm({ domain: '' });

function addDomain() {
    domainForm.post(`/tenants/${props.tenant.id}/domains`, {
        onSuccess: () => domainForm.reset(),
    });
}

const showEditDomainModal = ref(false);
const editingDomain = ref(null);

function openEditDomainModal(domain) {
    editingDomain.value = domain;
    showEditDomainModal.value = true;
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
        <PageHeader :title="tenant.company_name" back-href="/tenants" back-label="All tenants">
            <template #meta>
                <Badge :variant="statusBadgeVariant[tenant.status] ?? 'neutral'" pill>{{ statusLabel }}</Badge>
                <Badge v-if="tenant.past_grace_period" variant="danger" pill>Past grace period</Badge>
                <Badge v-else-if="tenant.trial_expired" variant="warning" pill>Trial expired</Badge>
            </template>

            <Button v-if="tenant.status === 'active'" variant="secondary" tone="blue" :loading="impersonating" @click="impersonate">
                <LogIn class="size-4" />
                Impersonate
            </Button>
            <Button :as="Link" :href="`/tenants/${tenant.id}/users`" variant="secondary" tone="neutral">
                <Users class="size-4" />
                Users
            </Button>
            <Button :as="Link" :href="`/tenants/${tenant.id}/edit`" variant="secondary" tone="neutral">
                <Pencil class="size-4" />
                Edit
            </Button>
            <Button :as="Link" :href="`/tenants/${tenant.id}/settings`" variant="primary" tone="purple">
                <Settings class="size-4" />
                Tenant settings
            </Button>
        </PageHeader>

        <div v-if="tenant.status === 'provisioning' && tenant.provisioning_error" class="mb-5 border-[1.5px] border-danger bg-danger-bg p-4" role="alert">
            <p class="text-sm font-semibold text-danger">Provisioning failed</p>
            <p class="mt-1 text-sm break-words text-danger">{{ tenant.provisioning_error }}</p>
            <Button class="mt-3" variant="secondary" tone="purple" :loading="retrying" @click="retryProvisioning">
                <RotateCw class="size-4" />
                Retry provisioning
            </Button>
        </div>

        <div v-else-if="tenant.database_missing" class="mb-5 border-[1.5px] border-danger bg-danger-bg p-4" role="alert">
            <p class="text-sm font-semibold text-danger">Database missing</p>
            <p class="mt-1 text-sm text-danger">
                This tenant is marked "{{ tenant.status }}" but its database doesn't actually exist - an
                earlier provisioning run likely never finished. Users can't log in, and actions like
                "Users" or "Impersonate" will fail until this is re-provisioned.
            </p>
            <Button class="mt-3" variant="secondary" tone="purple" :loading="retrying" @click="retryProvisioning">
                <RotateCw class="size-4" />
                Re-provision database
            </Button>
        </div>

        <dl class="mb-5 grid grid-cols-2 border-[1.5px] border-border bg-bg-surface shadow-xs lg:grid-cols-4">
            <div
                v-for="(tile, index) in summaryTiles"
                :key="tile.label"
                class="min-w-0 border-border px-4 py-3"
                :class="[index % 2 === 1 ? 'border-l' : '', index >= 2 ? 'border-t lg:border-t-0' : '', index === 2 ? 'lg:border-l' : '']"
            >
                <dt class="text-[11px] font-bold tracking-[.6px] text-text-muted uppercase">{{ tile.label }}</dt>
                <dd class="mt-1 truncate text-sm font-semibold text-text-strong" :title="tile.value">{{ tile.value }}</dd>
            </div>
        </dl>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="flex min-w-0 flex-col gap-5">
                <Card variant="panel" title="Status and plan" class="bg-bg-surface p-4 sm:p-5">
                    <p class="text-sm leading-relaxed text-text-base">
                        <template v-if="tenant.status === 'active'">This tenant is live and its users can sign in.</template>
                        <template v-else-if="tenant.status === 'suspended'">This tenant is suspended. Its users cannot sign in, but no data has been deleted. Resume it to restore access.</template>
                        <template v-else-if="tenant.status === 'provisioning'">This tenant is still being set up (creating its database and first admin user) and is not usable yet.</template>
                        <template v-else>Current status: {{ tenant.status }}.</template>
                        <template v-if="tenant.trial_expired"> The free trial has ended.</template>
                        <template v-if="tenant.past_grace_period"> The grace period after the trial has also ended, so access is restricted until the trial end date is extended.</template>
                    </p>
                    <dl class="mt-3 text-sm">
                        <div v-if="tenant.suspended_at" class="flex items-center justify-between gap-3 border-t border-border-soft py-2.5">
                            <dt class="text-text-muted">Suspended on</dt>
                            <dd class="text-right text-text-strong">{{ tenant.suspended_at }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-t border-border-soft py-2.5">
                            <dt class="shrink-0 text-text-muted">Contact email</dt>
                            <dd class="min-w-0 text-right break-all text-text-strong">{{ tenant.contact_email || 'Not set' }}</dd>
                        </div>
                    </dl>
                </Card>

                <Card variant="panel" title="Modules" class="bg-bg-surface p-4 sm:p-5">
                    <p class="mb-4 text-sm text-text-muted">
                        Features this tenant may use. Turning a module off hides its pages and blocks its URLs for
                        everyone, the owner included. Role grants are kept and come back when it is turned on again.
                    </p>
                    <form class="flex flex-col gap-4" @submit.prevent="updateModules">
                        <ModuleSelector v-model="modulesForm.enabled_modules" :catalog="moduleCatalog" id-prefix="show-module" :error="modulesError" />
                        <div class="flex flex-col gap-2 border-t border-border-soft pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs text-text-muted">{{ modulesForm.isDirty ? 'You have unsaved module changes.' : 'Modules are up to date.' }}</p>
                            <Button type="submit" variant="primary" tone="purple" :loading="modulesForm.processing" :disabled="!modulesForm.isDirty">
                                <Check class="size-4" />
                                Save modules
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>

            <div class="flex min-w-0 flex-col gap-5">
                <Card v-if="tenant.status === 'provisioning' && isOwner" variant="panel" title="Provisioning controls" class="bg-bg-surface p-4 sm:p-5">
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

                <OwnerSection v-if="isOwner && tenant.status !== 'provisioning' && !tenant.database_missing" :tenant-id="tenant.id" :owner="owner" :candidates="ownerCandidates" />

                <Card v-if="isOwner" variant="panel" title="Trial end date" class="bg-bg-surface p-4 sm:p-5">
                    <form @submit.prevent="updateTrial">
                        <div class="mb-1.5 flex items-center gap-1">
                            <label for="trial_ends_at" class="block text-[13px] font-semibold text-text-base">Trial ends on</label>
                            <InfoTip text="Leave blank to remove the trial expiry." />
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row lg:flex-col xl:flex-row">
                            <Input
                                id="trial_ends_at"
                                v-model="trialForm.trial_ends_at"
                                type="date"
                                :aria-describedby="trialForm.errors.trial_ends_at ? 'trial_ends_at-error' : 'trial_ends_at-help'"
                            />
                            <Button type="submit" class="shrink-0" variant="secondary" tone="blue" :loading="trialForm.processing">
                                <Check class="size-4" />
                                Save date
                            </Button>
                        </div>
                        <p v-if="trialForm.errors.trial_ends_at" id="trial_ends_at-error" class="mt-1.5 text-sm text-danger" role="alert">{{ trialForm.errors.trial_ends_at }}</p>
                        <p v-else id="trial_ends_at-help" class="sr-only">Leave blank to remove the trial expiry.</p>
                    </form>
                </Card>

                <Card variant="panel" title="Domains" class="bg-bg-surface p-4 sm:p-5">
                    <p class="mb-3 text-sm text-text-muted">Addresses this tenant can be reached at. At least one is required.</p>
                    <ul class="mb-4 divide-y divide-border-soft border-y border-border-soft text-sm">
                        <li v-for="domain in tenant.domains" :key="domain.id" class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0 flex-1 break-all text-text-strong">{{ domain.domain }}</span>
                            <button
                                type="button"
                                class="flex size-9 shrink-0 cursor-pointer items-center justify-center text-text-muted transition-colors duration-150 hover:bg-bg-muted hover:text-text-strong focus-visible:outline-2 focus-visible:outline-primary"
                                title="Change domain"
                                :aria-label="`Change domain ${domain.domain}`"
                                @click="openEditDomainModal(domain)"
                            >
                                <Pencil class="size-4" />
                            </button>
                            <button
                                type="button"
                                class="flex size-9 shrink-0 cursor-pointer items-center justify-center text-text-muted transition-colors duration-150 hover:bg-danger-bg hover:text-danger focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-text-muted"
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

                    <form @submit.prevent="addDomain">
                        <label for="new_domain" class="mb-1.5 block text-[13px] font-semibold text-text-base">Add another domain</label>
                        <div class="flex flex-col gap-2 sm:flex-row lg:flex-col xl:flex-row">
                            <Input
                                id="new_domain"
                                v-model="domainForm.domain"
                                type="text"
                                placeholder="shop.example.com"
                                autocapitalize="none"
                                spellcheck="false"
                                :aria-describedby="domainForm.errors.domain ? 'new_domain-error' : undefined"
                            />
                            <Button type="submit" class="shrink-0" variant="secondary" tone="blue" :loading="domainForm.processing">
                                <Plus class="size-4" />
                                Add domain
                            </Button>
                        </div>
                        <p v-if="domainForm.errors.domain" id="new_domain-error" class="mt-1.5 text-sm text-danger" role="alert">{{ domainForm.errors.domain }}</p>
                    </form>
                </Card>
            </div>
        </div>

        <section class="mt-8 border-[1.5px] border-danger/40 bg-bg-surface" aria-labelledby="danger-zone-heading">
            <div class="flex items-center gap-2 border-b border-danger/20 bg-danger-bg/40 px-4 py-3 sm:px-5">
                <ShieldAlert class="size-4 text-danger" aria-hidden="true" />
                <h3 id="danger-zone-heading" class="text-sm font-bold text-danger">Danger zone</h3>
            </div>
            <div class="divide-y divide-border-soft">
                <div v-if="tenant.status === 'active'" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <div>
                        <p class="text-sm font-semibold text-text-strong">Suspend tenant</p>
                        <p class="text-sm text-text-muted">Blocks all of this tenant's users from signing in. Data is kept.</p>
                    </div>
                    <Button class="shrink-0" variant="secondary" tone="danger" :loading="suspending" @click="suspend">
                        <CirclePause class="size-4" />
                        Suspend tenant
                    </Button>
                </div>
                <div v-else-if="tenant.status === 'suspended'" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <div>
                        <p class="text-sm font-semibold text-text-strong">Resume tenant</p>
                        <p class="text-sm text-text-muted">Restores sign-in access for this tenant's users.</p>
                    </div>
                    <Button class="shrink-0" variant="secondary" tone="success" :loading="resuming" @click="resume">
                        <CirclePlay class="size-4" />
                        Resume tenant
                    </Button>
                </div>
                <div class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <div>
                        <p class="text-sm font-semibold text-text-strong">Delete tenant</p>
                        <p class="text-sm text-text-muted">Permanently deletes this tenant and its database. This cannot be undone.</p>
                    </div>
                    <Button class="shrink-0" variant="primary" tone="danger" @click="openDeleteModal('delete')">
                        <Trash2 class="size-4" />
                        Delete tenant
                    </Button>
                </div>
            </div>
        </section>

        <EditDomainModal v-model:open="showEditDomainModal" :tenant-id="tenant.id" :domain="editingDomain" />

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
                <Button variant="secondary" tone="neutral" @click="showDeleteModal = false">Keep tenant</Button>
                <Button variant="primary" tone="danger" :disabled="!canConfirmDelete" :loading="deleting" @click="confirmDelete">
                    {{ deleteIntent === 'cancel' ? 'Cancel provisioning' : 'Delete tenant' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
