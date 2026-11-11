<script setup>
import { computed } from 'vue';
import { Plus, Trash2, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { compareMoney, formatMoney } from '@/lib/money.js';

/**
 * The editable grid of a draft payroll run: the run's header fields, then one
 * block per staff member holding their pay items (component + amount + note),
 * the advance recovery and a note, with live gross/net from usePayrollDraft.
 *
 * `form` is the page's useForm instance and is edited in place (the same
 * pattern as PurchaseCreatePaymentNotes); `draft` is usePayrollDraft()'s
 * return value. Saving, posting and discarding live in the page header.
 */
const props = defineProps({
    form: { type: Object, required: true },
    draft: { type: Object, required: true },
    // Read-only when the user may view but not edit drafts (no payroll.create).
    readonly: { type: Boolean, default: false },
});

const staffChoices = computed(() =>
    props.draft.availableStaff.value.map((staff) => ({
        value: staff.id,
        label: staff.staff_code ? `${staff.name} (${staff.staff_code})` : staff.name,
    })),
);

function addStaff(staffId) {
    if (staffId !== null && staffId !== undefined) {
        props.draft.addStaff(staffId);
    }
}

/** Shown under the recovery input: what this staff member still owes. */
function advanceHint(line) {
    const balance = props.draft.advanceFor(line.staff_id);

    return compareMoney(balance, '0.00') > 0 ? `Outstanding advance: Rs ${formatMoney(balance)}` : 'No outstanding advance.';
}

function hasAdvance(line) {
    return compareMoney(props.draft.advanceFor(line.staff_id), '0.00') > 0;
}

function err(path) {
    return props.draft.errorFor(path);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <Card variant="panel" title="Pay period" class="!p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2 lg:col-span-1">
                    <Label for="payroll-period-label" class="mb-1">Period label <span class="text-danger" aria-hidden="true">*</span></Label>
                    <Input id="payroll-period-label" v-model="form.period_label" type="text" placeholder="e.g. Shrawan 2083" :disabled="readonly" />
                    <p v-if="err('period_label')" class="mt-1 text-sm text-danger" role="alert">{{ err('period_label') }}</p>
                </div>
                <div>
                    <Label class="mb-1">Period start (BS) <span class="text-danger" aria-hidden="true">*</span></Label>
                    <NepaliDateInput v-model="form.period_start" required :disabled="readonly" />
                    <p v-if="err('period_start')" class="mt-1 text-sm text-danger" role="alert">{{ err('period_start') }}</p>
                </div>
                <div>
                    <Label class="mb-1">Period end (BS) <span class="text-danger" aria-hidden="true">*</span></Label>
                    <NepaliDateInput v-model="form.period_end" required :disabled="readonly" />
                    <p v-if="err('period_end')" class="mt-1 text-sm text-danger" role="alert">{{ err('period_end') }}</p>
                </div>
                <div>
                    <Label class="mb-1">Posting date (BS) <span class="text-danger" aria-hidden="true">*</span></Label>
                    <NepaliDateInput v-model="form.date" required :disabled="readonly" />
                    <p v-if="err('date')" class="mt-1 text-sm text-danger" role="alert">{{ err('date') }}</p>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <Label for="payroll-narration" class="mb-1">Narration</Label>
                    <Input id="payroll-narration" v-model="form.narration" type="text" placeholder="Optional note for the ledger entry" :disabled="readonly" />
                    <p v-if="err('narration')" class="mt-1 text-sm text-danger" role="alert">{{ err('narration') }}</p>
                </div>
            </div>
        </Card>

        <Card variant="panel" class="!p-4">
            <template #title>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-1.5">
                        <span>Staff <span class="text-danger">*</span></span>
                        <span class="bg-primary-tint px-2 py-0.5 text-[11px] font-bold tracking-normal text-primary">{{ form.lines.length }}</span>
                    </div>
                    <div v-if="!readonly" class="w-full tracking-normal normal-case sm:w-72">
                        <!-- Re-keyed on every add so the picker always comes back empty. -->
                        <Combobox
                            :key="form.lines.length"
                            :model-value="null"
                            :options="staffChoices"
                            :disabled="staffChoices.length === 0"
                            :placeholder="staffChoices.length === 0 ? 'Every active staff member is in this run' : 'Add staff member...'"
                            aria-label="Add staff member"
                            @update:model-value="addStaff"
                        />
                    </div>
                </div>
            </template>

            <p class="mb-3 text-xs text-text-muted">
                Each staff member needs at least one pay item. Advance recovery is deducted from gross pay and cannot exceed either the gross pay or the staff member's outstanding advance.
            </p>

            <p v-if="err('lines')" class="mb-3 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
                {{ err('lines') }}
            </p>

            <p v-if="form.lines.length === 0" class="py-6 text-center text-sm text-text-faint">No staff in this run yet. Add a staff member above.</p>

            <div
                v-for="(line, lineIndex) in form.lines"
                :key="line.key"
                class="mb-3 border-[1.5px] last:mb-0"
                :class="err(`lines.${lineIndex}.staff_id`) || err(`lines.${lineIndex}.items`) ? 'border-danger' : 'border-border'"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-bg-subtle px-3 py-2 text-sm">
                    <span class="font-semibold text-text-strong">{{ line.staff_name }}</span>
                    <span class="text-text-muted">{{ line.staff_code }}</span>
                    <Badge v-if="!draft.isActiveStaff(line.staff_id)" pill variant="danger">Inactive</Badge>
                    <div class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-muted">
                        <span>Gross <span class="font-bold text-text-strong">{{ formatMoney(draft.figuresFor(line).gross) }}</span></span>
                        <span>Recovery <span class="font-bold text-text-strong">{{ formatMoney(draft.figuresFor(line).recovery) }}</span></span>
                        <span>Net <span class="font-bold text-text-strong">{{ formatMoney(draft.figuresFor(line).net) }}</span></span>
                    </div>
                    <button
                        v-if="!readonly"
                        type="button"
                        class="flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                        :aria-label="`Remove ${line.staff_name} from this run`"
                        @click="draft.removeStaff(lineIndex)"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                    </button>
                </div>

                <div class="p-3">
                    <p v-if="!draft.isActiveStaff(line.staff_id)" class="mb-2 text-xs text-danger">
                        This staff member is no longer active and cannot be paid in a new run. Remove them before saving.
                    </p>
                    <p v-if="err(`lines.${lineIndex}.staff_id`)" class="mb-2 text-xs text-danger" role="alert">{{ err(`lines.${lineIndex}.staff_id`) }}</p>

                    <div class="overflow-x-auto">
                        <div class="min-w-[620px]">
                            <div class="mb-2 grid grid-cols-[1fr_150px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                                <span>Pay component <span class="text-danger">*</span></span>
                                <span class="text-right">Amount <span class="text-danger">*</span></span>
                                <span>Note</span>
                                <span class="sr-only">Remove</span>
                            </div>
                            <div
                                v-for="(item, itemIndex) in line.items"
                                :key="item.key"
                                class="mb-2 grid grid-cols-[1fr_150px_1fr_28px] items-start gap-2"
                            >
                                <div>
                                    <Select
                                        :model-value="item.pay_component_id"
                                        :options="draft.componentOptionsFor(line, item)"
                                        placeholder="Select component"
                                        :disabled="readonly"
                                        @update:model-value="(v) => draft.setComponent(item, v)"
                                    />
                                    <p v-if="err(`lines.${lineIndex}.items.${itemIndex}.pay_component_id`)" class="mt-1 text-xs text-danger">
                                        {{ err(`lines.${lineIndex}.items.${itemIndex}.pay_component_id`) }}
                                    </p>
                                </div>
                                <div>
                                    <Input
                                        v-model="item.amount"
                                        type="text"
                                        inputmode="decimal"
                                        placeholder="0.00"
                                        class="text-right"
                                        aria-label="Amount"
                                        :disabled="readonly"
                                    />
                                    <p v-if="err(`lines.${lineIndex}.items.${itemIndex}.amount`)" class="mt-1 text-xs text-danger">
                                        {{ err(`lines.${lineIndex}.items.${itemIndex}.amount`) }}
                                    </p>
                                </div>
                                <div>
                                    <Input v-model="item.note" type="text" placeholder="Optional" aria-label="Pay item note" :disabled="readonly" />
                                    <p v-if="err(`lines.${lineIndex}.items.${itemIndex}.note`)" class="mt-1 text-xs text-danger">
                                        {{ err(`lines.${lineIndex}.items.${itemIndex}.note`) }}
                                    </p>
                                </div>
                                <button
                                    v-if="!readonly"
                                    type="button"
                                    class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                                    aria-label="Remove pay item"
                                    @click="draft.removeItem(line, itemIndex)"
                                >
                                    <X class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <p v-if="err(`lines.${lineIndex}.items`)" class="mb-2 text-xs text-danger" role="alert">{{ err(`lines.${lineIndex}.items`) }}</p>

                    <button
                        v-if="!readonly && draft.canAddItem(line)"
                        type="button"
                        class="mb-3 flex items-center gap-1 text-xs font-semibold text-primary"
                        @click="draft.addItem(line)"
                    >
                        <Plus class="h-3.5 w-3.5" /> Add pay item
                    </button>

                    <div class="grid grid-cols-1 gap-3 border-t border-border-soft pt-3 sm:grid-cols-[200px_1fr]">
                        <div>
                            <Label :for="`payroll-recovery-${line.key}`" class="mb-1">Advance recovery</Label>
                            <Input
                                :id="`payroll-recovery-${line.key}`"
                                v-model="line.advance_recovery"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                                class="text-right"
                                :disabled="readonly || (!hasAdvance(line) && compareMoney(draft.figuresFor(line).recovery, '0.00') === 0)"
                            />
                            <p class="mt-1 text-xs" :class="hasAdvance(line) ? 'text-text-muted' : 'text-text-faint'">{{ advanceHint(line) }}</p>
                            <p v-if="err(`lines.${lineIndex}.advance_recovery`)" class="mt-1 text-xs text-danger" role="alert">
                                {{ err(`lines.${lineIndex}.advance_recovery`) }}
                            </p>
                        </div>
                        <div>
                            <Label :for="`payroll-note-${line.key}`" class="mb-1">Note</Label>
                            <Input :id="`payroll-note-${line.key}`" v-model="line.note" type="text" placeholder="Optional note on this payslip" :disabled="readonly" />
                            <p v-if="err(`lines.${lineIndex}.note`)" class="mt-1 text-xs text-danger" role="alert">{{ err(`lines.${lineIndex}.note`) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </Card>
    </div>
</template>
