<script setup>
import { computed, ref, onMounted } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    CirclePause,
    CirclePlay,
    History,
    LayoutDashboard,
    LogIn,
    Pencil,
    Plus,
    RotateCw,
    Settings as SettingsIcon,
    ShieldCheck,
    Trash2,
    Users,
    X,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { toast } = useToast();

onMounted(() => {
    if (page.props.flash?.status) {
        toast({ message: page.props.flash.status, variant: 'success' });
    }
});

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
    { label: 'Settings', href: '/settings', icon: SettingsIcon },
    { label: 'Platform admins', href: '/platform-admins', icon: ShieldCheck },
];

const showDeleteModal = ref(false);
const deleteConfirmName = ref('');
const canConfirmDelete = computed(() => deleteConfirmName.value === props.tenant.company_name);

const statusBadgeVariant = {
    active: 'success',
    provisioning: 'warning',
    suspended: 'danger',
};

function suspend() {
    router.post(`/tenants/${props.tenant.id}/suspend`);
}

function resume() {
    router.post(`/tenants/${props.tenant.id}/resume`);
}

function impersonate() {
    // The response is an Inertia::location() (not a normal redirect) since
    // the target is the tenant's own domain - Inertia's client
    // automatically performs a full-page navigation there instead of
    // trying to follow it as a same-origin visit.
    router.post(`/tenants/${props.tenant.id}/impersonate`);
}

function retryProvisioning() {
    router.post(`/tenants/${props.tenant.id}/retry-provisioning`);
}

function openDeleteModal() {
    deleteConfirmName.value = '';
    showDeleteModal.value = true;
}

function confirmDelete() {
    if (!canConfirmDelete.value) {
        return;
    }

    router.delete(`/tenants/${props.tenant.id}`, {
        onFinish: () => {
            showDeleteModal.value = false;
        },
    });
}

const domainForm = useForm({ domain: '' });

function addDomain() {
    domainForm.post(`/tenants/${props.tenant.id}/domains`, {
        onSuccess: () => domainForm.reset(),
    });
}

function removeDomain(domain) {
    router.delete(`/tenants/${props.tenant.id}/domains/${domain.id}`);
}
</script>

<template>
    <AppLayout :title="tenant.company_name" :nav-items="navItems">
        <Link href="/tenants" class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary">
            <ArrowLeft class="size-4" />
            All tenants
        </Link>

        <div v-if="tenant.status === 'provisioning' && tenant.provisioning_error" class="mb-4 max-w-lg border-[1.5px] border-danger bg-danger-bg p-3">
            <p class="text-sm font-semibold text-danger">Provisioning failed</p>
            <p class="mt-1 text-sm text-danger">{{ tenant.provisioning_error }}</p>
            <Button class="mt-3" variant="secondary" tone="purple" @click="retryProvisioning">
                <RotateCw class="size-4" />
                Retry provisioning
            </Button>
        </div>

        <Card variant="panel" title="Details" class="max-w-lg">
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
                <div v-if="tenant.trial_ends_at" class="flex items-center justify-between border-b border-border-soft py-2">
                    <dt class="text-text-muted">Trial ends</dt>
                    <dd class="text-text-strong">{{ tenant.trial_ends_at }}</dd>
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

                <Button v-if="tenant.status === 'active'" variant="secondary" tone="purple" @click="suspend">
                    <CirclePause class="size-4" />
                    Suspend
                </Button>
                <Button v-else-if="tenant.status === 'suspended'" variant="secondary" tone="blue" @click="resume">
                    <CirclePlay class="size-4" />
                    Resume
                </Button>

                <Button v-if="tenant.status === 'active'" variant="secondary" tone="blue" @click="impersonate">
                    <LogIn class="size-4" />
                    Impersonate admin
                </Button>

                <Button variant="secondary" tone="purple" @click="openDeleteModal">
                    <Trash2 class="size-4" />
                    Delete
                </Button>
            </div>
        </Card>

        <Card variant="panel" title="Domains" class="mt-4 max-w-lg">
            <ul class="mb-4 divide-y divide-border-soft text-sm">
                <li v-for="domain in tenant.domains" :key="domain.id" class="flex items-center justify-between py-2">
                    <span class="text-text-strong">{{ domain.domain }}</span>
                    <button
                        type="button"
                        class="text-text-muted transition-colors duration-150 hover:text-danger disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="tenant.domains.length <= 1"
                        :title="tenant.domains.length <= 1 ? 'A tenant must have at least one domain' : 'Remove domain'"
                        @click="removeDomain(domain)"
                    >
                        <X class="size-4" />
                    </button>
                </li>
            </ul>

            <form class="flex items-start gap-2" @submit.prevent="addDomain">
                <div class="flex-1">
                    <Input v-model="domainForm.domain" type="text" placeholder="extra.localhost" />
                    <p v-if="domainForm.errors.domain" class="mt-1 text-sm text-danger">{{ domainForm.errors.domain }}</p>
                </div>
                <Button type="submit" variant="secondary" tone="blue" :disabled="domainForm.processing">
                    <Plus class="size-4" />
                    Add
                </Button>
            </form>
        </Card>

        <Modal v-model:open="showDeleteModal" title="Delete tenant" size="compact">
            <p class="text-sm text-text-base">
                Delete this tenant and its database? This cannot be undone. Type
                <span class="font-semibold text-text-strong">{{ tenant.company_name }}</span>
                to confirm.
            </p>
            <Input v-model="deleteConfirmName" type="text" class="mt-3" :placeholder="tenant.company_name" />

            <template #footer>
                <Button variant="secondary" tone="purple" @click="showDeleteModal = false">Cancel</Button>
                <Button variant="primary" tone="purple" :disabled="!canConfirmDelete" @click="confirmDelete">Delete</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
