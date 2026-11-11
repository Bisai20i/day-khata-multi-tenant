<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // [{id, code, name, kind, expense_account_id, is_system, is_active, expense_account: {id, code, name}}]
    components: { type: Array, default: () => [] },
    expenseAccounts: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Pay components');

// UI mirror of routes/tenant-hr.php; each route still enforces its key.
const { can } = usePermissions();
const canManage = computed(() => can('pay_components.manage'));

// Saving redirects back to this same route + component, which Inertia
// re-renders in place - watch the flash prop.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const expenseAccountOptions = computed(() =>
    props.expenseAccounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

function accountLabel(account) {
    if (!account) return '-';

    return account.code ? `${account.code} - ${account.name}` : account.name;
}

// ---- Add -------------------------------------------------------------------

const addForm = useForm({ code: '', name: '', expense_account_id: null });

function submitAdd() {
    addForm.post('/hr/pay-components', {
        preserveScroll: true,
        onSuccess: () => addForm.reset(),
    });
}

// ---- Inline edit -----------------------------------------------------------

const editingId = ref(null);
const editForm = useForm({ name: '', expense_account_id: null, is_active: true });

const editingComponent = computed(() => props.components.find((component) => component.id === editingId.value) ?? null);

function startEdit(component) {
    editingId.value = component.id;
    editForm.defaults({
        name: component.name,
        expense_account_id: component.expense_account_id,
        is_active: !!component.is_active,
    });
    editForm.reset();
    editForm.clearErrors();
}

function stopEdit() {
    editingId.value = null;
    editForm.clearErrors();
}

function submitEdit() {
    editForm.put(`/hr/pay-components/${editingId.value}`, {
        preserveScroll: true,
        onSuccess: () => stopEdit(),
    });
}
</script>

<template>
    <div>
        <PageHeader
            title="Pay components"
            description="The earnings that make up a payslip. Each component's amounts are posted to the expense account chosen here."
        />

        <Card v-if="canManage" variant="panel" class="mb-4 !p-4" title="Add a pay component">
            <form class="grid grid-cols-1 gap-4 sm:grid-cols-4" @submit.prevent="submitAdd">
                <div>
                    <Label class="mb-1">Code <span class="text-danger">*</span></Label>
                    <Input v-model="addForm.code" type="text" maxlength="40" placeholder="e.g. OVERTIME" required />
                    <p class="mt-1 text-xs text-text-muted">Letters, numbers, dashes and underscores. Cannot be changed later.</p>
                    <p v-if="addForm.errors.code" class="mt-1 text-sm text-danger">{{ addForm.errors.code }}</p>
                </div>
                <div>
                    <Label class="mb-1">Name <span class="text-danger">*</span></Label>
                    <Input v-model="addForm.name" type="text" maxlength="100" placeholder="e.g. Overtime" required />
                    <p v-if="addForm.errors.name" class="mt-1 text-sm text-danger">{{ addForm.errors.name }}</p>
                </div>
                <div>
                    <Label class="mb-1">Expense account <span class="text-danger">*</span></Label>
                    <Combobox
                        :model-value="addForm.expense_account_id"
                        :options="expenseAccountOptions"
                        placeholder="Select expense account"
                        @update:model-value="(v) => (addForm.expense_account_id = v)"
                    />
                    <p v-if="addForm.errors.expense_account_id" class="mt-1 text-sm text-danger">{{ addForm.errors.expense_account_id }}</p>
                </div>
                <div class="flex items-end">
                    <Button
                        variant="primary"
                        tone="purple"
                        type="submit"
                        :loading="addForm.processing"
                        :disabled="addForm.processing || !addForm.code.trim() || !addForm.name.trim() || !addForm.expense_account_id"
                    >
                        Add component
                    </Button>
                </div>
            </form>
        </Card>

        <Card variant="panel" class="bg-white">
            <p v-if="components.length === 0" class="py-6 text-center text-sm text-text-muted">No pay components yet.</p>

            <div v-else class="flex flex-col gap-2">
                <div class="grid grid-cols-[110px_1fr_1fr_90px_90px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Code</span>
                    <span>Name</span>
                    <span>Expense account</span>
                    <span>Status</span>
                    <span v-if="canManage">Actions</span>
                </div>

                <template v-for="component in components" :key="component.id">
                    <div v-if="editingId !== component.id" class="grid grid-cols-[110px_1fr_1fr_90px_90px] items-center gap-2 border-t border-border pt-2">
                        <span class="text-sm font-semibold text-text-strong">{{ component.code }}</span>
                        <span class="text-sm text-text-base">
                            {{ component.name }}
                            <Badge v-if="component.is_system" variant="neutral" class="ml-1">Starter</Badge>
                        </span>
                        <span class="text-sm text-text-base">{{ accountLabel(component.expense_account) }}</span>
                        <span>
                            <Badge pill :variant="component.is_active ? 'success' : 'neutral'">{{ component.is_active ? 'Active' : 'Inactive' }}</Badge>
                        </span>
                        <span v-if="canManage">
                            <Tooltip label="Edit this pay component">
                                <button
                                    type="button"
                                    class="flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95"
                                    :aria-label="`Edit ${component.name}`"
                                    @click="startEdit(component)"
                                >
                                    <Pencil class="h-[13px] w-[13px]" aria-hidden="true" />
                                </button>
                            </Tooltip>
                        </span>
                    </div>

                    <form v-else class="grid grid-cols-1 gap-3 border-t border-border bg-bg-subtle p-3 sm:grid-cols-3" @submit.prevent="submitEdit">
                        <div>
                            <Label class="mb-1">Name <span class="text-danger">*</span></Label>
                            <Input v-model="editForm.name" type="text" maxlength="100" required />
                            <p v-if="editForm.errors.name" class="mt-1 text-sm text-danger">{{ editForm.errors.name }}</p>
                        </div>
                        <div>
                            <Label class="mb-1">Expense account <span class="text-danger">*</span></Label>
                            <Combobox
                                :model-value="editForm.expense_account_id"
                                :options="expenseAccountOptions"
                                placeholder="Select expense account"
                                @update:model-value="(v) => (editForm.expense_account_id = v)"
                            />
                            <p v-if="editForm.errors.expense_account_id" class="mt-1 text-sm text-danger">{{ editForm.errors.expense_account_id }}</p>
                        </div>
                        <div>
                            <Label class="mb-1">Active</Label>
                            <label class="flex items-center gap-2 text-sm text-text-base">
                                <input
                                    v-model="editForm.is_active"
                                    type="checkbox"
                                    class="size-4 border-[1.5px] border-border"
                                    :disabled="editingComponent?.is_system"
                                />
                                {{ editForm.is_active ? 'Active' : 'Inactive' }}
                            </label>
                            <p v-if="editingComponent?.is_system" class="mt-1 text-xs text-text-muted">
                                Starter components (Salary, Bonus, Allowance) can be renamed and remapped but never deactivated.
                            </p>
                            <p v-if="editForm.errors.is_active" class="mt-1 text-sm text-danger">{{ editForm.errors.is_active }}</p>
                        </div>
                        <div class="flex items-center justify-end gap-2 sm:col-span-3">
                            <Button variant="secondary" tone="purple" type="button" @click="stopEdit">Cancel</Button>
                            <Button
                                variant="primary"
                                tone="purple"
                                type="submit"
                                :loading="editForm.processing"
                                :disabled="editForm.processing || !editForm.name.trim() || !editForm.expense_account_id"
                            >
                                Save
                            </Button>
                        </div>
                    </form>
                </template>
            </div>
        </Card>
    </div>
</template>
