<script setup>
import { ref, onMounted } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Building2, CirclePause, CirclePlay, LayoutDashboard, LogIn, Trash2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
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
];

const showDeleteModal = ref(false);

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

function confirmDelete() {
    router.delete(`/tenants/${props.tenant.id}`, {
        onFinish: () => {
            showDeleteModal.value = false;
        },
    });
}
</script>

<template>
    <AppLayout :title="tenant.company_name" :nav-items="navItems">
        <Link href="/tenants" class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary">
            <ArrowLeft class="size-4" />
            All tenants
        </Link>

        <Card variant="panel" title="Details" class="max-w-lg">
            <dl class="mb-6 text-sm">
                <div class="flex items-center justify-between border-b border-border-soft py-2">
                    <dt class="text-text-muted">Status</dt>
                    <dd>
                        <Badge :variant="statusBadgeVariant[tenant.status] ?? 'neutral'" pill>{{ tenant.status }}</Badge>
                    </dd>
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

            <div class="flex gap-2">
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

                <Button variant="secondary" tone="purple" @click="showDeleteModal = true">
                    <Trash2 class="size-4" />
                    Delete
                </Button>
            </div>
        </Card>

        <Modal v-model:open="showDeleteModal" title="Delete tenant" size="compact">
            <p class="text-sm text-text-base">Delete this tenant and its database? This cannot be undone.</p>

            <template #footer>
                <Button variant="secondary" tone="purple" @click="showDeleteModal = false">Cancel</Button>
                <Button variant="primary" tone="purple" @click="confirmDelete">Delete</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
