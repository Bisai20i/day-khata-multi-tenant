<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    admin: {
        type: Object,
        required: true,
    },
});

useLayoutChrome(() => `Edit ${props.admin.name}`);

const roleOptions = [
    { value: 'owner', label: 'Owner' },
    { value: 'support', label: 'Support' },
];

// Select's modelValue only accepts String/Number/null, so is_active (a
// boolean on the form) is bridged through 1/0 here rather than passed
// straight through - avoids a Vue prop-type warning on every keystroke
// (same bridge Tenant/Admin/Users.vue already uses for the same reason).
const statusOptions = [
    { value: 1, label: 'Active' },
    { value: 0, label: 'Inactive' },
];

const form = useForm({
    name: props.admin.name,
    email: props.admin.email,
    password: '',
    password_confirmation: '',
    role: props.admin.role,
    is_active: props.admin.is_active,
});

const isActiveOption = computed({
    get: () => (form.is_active ? 1 : 0),
    set: (value) => {
        form.is_active = value === 1;
    },
});

function submit() {
    form.put(`/platform-admins/${props.admin.id}`);
}
</script>

<template>
    <div>
        <PageHeader
            title="Edit platform admin"
            :description="`Update details, role and access for ${admin.name}. Set status to Inactive to block sign-in.`"
            back-href="/platform-admins"
            back-label="All platform admins"
        />

        <Card variant="panel" class="max-w-lg">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. Jane Doe" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email <span class="text-danger">*</span></label>
                    <Input id="email" v-model="form.email" type="email" placeholder="you@example.com" required />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="role" class="mb-1 block text-sm font-semibold text-text-base">Role <span class="text-danger">*</span></label>
                    <Select id="role" v-model="form.role" :options="roleOptions" />
                    <p v-if="form.errors.role" class="mt-1 text-sm text-danger">{{ form.errors.role }}</p>
                </div>

                <div>
                    <label for="is_active" class="mb-1 block text-sm font-semibold text-text-base">Status <span class="text-danger">*</span></label>
                    <Select id="is_active" v-model="isActiveOption" :options="statusOptions" />
                    <p v-if="form.errors.is_active" class="mt-1 text-sm text-danger">{{ form.errors.is_active }}</p>
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-semibold text-text-base">
                        New password (optional)
                    </label>
                    <Input id="password" v-model="form.password" type="password" placeholder="Leave blank to keep current" />
                    <p v-if="form.errors.password" class="mt-1 text-sm text-danger">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label for="password_confirmation" class="mb-1 block text-sm font-semibold text-text-base">Confirm password</label>
                    <Input id="password_confirmation" v-model="form.password_confirmation" type="password" placeholder="Confirm new password" />
                </div>

                <div class="flex gap-2">
                    <Button type="submit" variant="primary" tone="purple" :loading="form.processing" class="flex-1">
                        <Check class="size-4" />
                        Save changes
                    </Button>
                    <Button :as="Link" href="/platform-admins" variant="secondary" tone="purple" class="flex-1 justify-center">
                        Cancel
                    </Button>
                </div>
            </form>
        </Card>
    </div>
</template>
