<script setup>
import Input from '@/components/ui/Input.vue';
import Label from '@/components/ui/Label.vue';
import InfoTip from '@/components/ui/InfoTip.vue';

/**
 * The TDS, SSF and CIT inputs of one payslip in the draft payroll editor.
 * Employee amounts are withheld from the staff member's pay (they reduce net);
 * employer amounts are extra employer cost and never change gross or net.
 *
 * `line` is one entry of the page's useForm `lines` and is edited in place;
 * `draft` is usePayrollDraft()'s return value (it supplies the error lookup).
 */
const props = defineProps({
    line: { type: Object, required: true },
    lineIndex: { type: Number, required: true },
    draft: { type: Object, required: true },
    readonly: { type: Boolean, default: false },
});

const WITHHELD_HELP = 'Withheld from the staff member pay, so it reduces net pay. Pre-filled from the staff rate on basic pay components; you can edit it for this payslip.';
const EMPLOYER_HELP = 'Extra cost to the employer, paid to the fund on top of the salary. It does not change gross or net pay. Pre-filled from the staff rate on basic pay components; you can edit it for this payslip.';

const fields = [
    { field: 'tds', label: 'TDS', help: 'Tax withheld from the pay and owed to the tax office. Reduces net pay.' },
    { field: 'ssf_employee', label: 'SSF (employee)', help: WITHHELD_HELP },
    { field: 'ssf_employer', label: 'SSF (employer)', help: EMPLOYER_HELP },
    { field: 'cit_employee', label: 'CIT (employee)', help: WITHHELD_HELP },
    { field: 'cit_employer', label: 'CIT (employer)', help: EMPLOYER_HELP },
];

function err(field) {
    return props.draft.errorFor(`lines.${props.lineIndex}.${field}`);
}
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div v-for="item in fields" :key="item.field">
            <div class="mb-1 flex items-center gap-1">
                <Label :for="`payroll-${item.field}-${line.key}`">{{ item.label }}</Label>
                <InfoTip :text="item.help" />
            </div>
            <Input
                :id="`payroll-${item.field}-${line.key}`"
                v-model="line[item.field]"
                type="text"
                inputmode="decimal"
                placeholder="0.00"
                class="text-right"
                :disabled="readonly"
            />
            <p v-if="err(item.field)" class="mt-1 text-xs text-danger" role="alert">{{ err(item.field) }}</p>
        </div>
    </div>
</template>
