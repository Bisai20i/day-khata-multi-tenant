import { computed, ref, watch } from 'vue';
// Relative (not `@/`) so the pure helpers below can be imported by a plain `node --test` file.
import { compareMoney, parseMoney, percentOf, subtractMoney, sumMoney } from '../lib/money.js';

/**
 * State, totals and validation for the editable grid of a draft payroll run
 * (Tenant/Hr/Payroll/Show.vue + components/hr/PayrollDraftEditor.vue).
 *
 * Everything money here is an exact-decimal string handled by lib/money.js:
 * the server sends `"15000.00"`, the inputs hold whatever the user typed, and
 * no value is ever turned into a JS number. A field that does not parse yet
 * counts as zero in the live totals and is reported by `validateDraft()`, so
 * a half-typed amount never throws inside a computed.
 *
 * The pure helpers (`draftFromRun`, `payrollDraftPayload`, `payrollLineFigures`,
 * `payrollDraftTotals`, `validatePayrollDraft`) take plain objects and touch no
 * component state; `usePayrollDraft()` wires them to the page's useForm.
 *
 * Validation mirrors SavePayrollRunRequest + PayrollDraft::validateLines() so
 * the user sees the refusal next to the field before a round trip. The server
 * stays the authority (it re-checks the advance balance under its own lock).
 */

/** Largest amount the server's `max:` rule accepts. */
const MAX_AMOUNT = '999999999999999999.99';

let keySeed = 0;

/** A local v-for key; never sent to the server. */
function nextKey() {
    keySeed += 1;

    return keySeed;
}

function trimmed(value) {
    return String(value ?? '').trim();
}

function isBlank(value) {
    return trimmed(value) === '';
}

/**
 * Canonical 2dp string for a valid entry, or null when blank or unparseable.
 *
 * @returns {string|null}
 */
function moneyOrNull(value) {
    if (isBlank(value)) {
        return null;
    }
    const parsed = parseMoney(trimmed(value));

    return parsed.ok ? parsed.value : null;
}

/**
 * The per-line amounts besides pay items and advance recovery. `withheld`
 * ones come out of the staff member's pay (they count toward the gross cap and
 * the net); the employer ones are extra employer cost and never touch either.
 * Each carries the percent-rate key of staffOptions used to pre-fill it.
 */
const TDS_FIELD = { field: 'tds', label: 'TDS', withheld: true };
const CONTRIBUTION_FIELDS = [
    { field: 'ssf_employee', label: 'SSF (employee)', rateKey: 'ssf_employee_rate', withheld: true },
    { field: 'ssf_employer', label: 'SSF (employer)', rateKey: 'ssf_employer_rate', withheld: false },
    { field: 'cit_employee', label: 'CIT (employee)', rateKey: 'cit_employee_rate', withheld: true },
    { field: 'cit_employer', label: 'CIT (employer)', rateKey: 'cit_employer_rate', withheld: false },
];
const MONEY_FIELDS = [TDS_FIELD, ...CONTRIBUTION_FIELDS];
const WITHHELD_MESSAGE = 'The advance recovery, TDS, SSF and CIT withheld together cannot exceed the gross pay.';

/** A canonical 2dp amount for the payload; blank is zero, an invalid entry is sent as typed so the server names it. */
function payloadAmount(value) {
    return isBlank(value) ? '0.00' : (moneyOrNull(value) ?? trimmed(value));
}

/**
 * Sum of the pay items whose component counts as basic pay for SSF / CIT
 * (`is_contribution_base`). Unparseable amounts count as zero.
 *
 * @param {object} line
 * @param {Array<object>} components
 * @returns {string}
 */
export function contributionBase(line, components) {
    const baseIds = new Set((components ?? []).filter((c) => c.is_contribution_base).map((c) => c.id));

    return sumMoney(
        (line.items ?? []).filter((item) => baseIds.has(item.pay_component_id)).map((item) => moneyOrNull(item.amount) ?? '0.00'),
    );
}

/** @returns {{key: number, pay_component_id: ?number, component_name: string, amount: string, note: string}} */
export function emptyPayItem() {
    return { key: nextKey(), pay_component_id: null, component_name: '', amount: '', note: '' };
}

/**
 * Editable form state from the Show props of a draft run. Lines keep the
 * staff code/name for display; the payload drops them.
 *
 * @param {object} run
 * @param {Array<object>} lines
 * @returns {object}
 */
export function draftFromRun(run, lines) {
    return {
        period_label: run?.period_label ?? '',
        period_start: run?.period_start ?? '',
        period_end: run?.period_end ?? '',
        date: run?.date ?? '',
        narration: run?.narration ?? '',
        lines: (lines ?? []).map((line) => ({
            key: nextKey(),
            staff_id: line.staff_id,
            staff_code: line.staff_code ?? '',
            staff_name: line.staff_name ?? '',
            advance_recovery: line.advance_recovery ?? '',
            tds: line.tds ?? '0.00',
            ssf_employee: line.ssf_employee ?? '0.00',
            ssf_employer: line.ssf_employer ?? '0.00',
            cit_employee: line.cit_employee ?? '0.00',
            cit_employer: line.cit_employer ?? '0.00',
            note: line.note ?? '',
            items: (line.items ?? []).map((item) => ({
                key: nextKey(),
                pay_component_id: item.pay_component_id,
                component_name: item.component_name ?? '',
                amount: item.amount ?? '',
                note: item.note ?? '',
            })),
        })),
    };
}

/**
 * The PUT /hr/payroll/{id} body. Line and item order is kept exactly, so a
 * server error key such as `lines.0.items.1.amount` points at the same row
 * on screen. Valid amounts are sent canonical ("1500" -> "1500.00"); an
 * invalid one is sent as typed so the server's message names it.
 *
 * @param {object} state
 * @returns {object}
 */
export function payrollDraftPayload(state) {
    return {
        period_label: trimmed(state.period_label),
        period_start: state.period_start || '',
        period_end: state.period_end || '',
        date: state.date || '',
        narration: trimmed(state.narration) || null,
        lines: (state.lines ?? []).map((line) => ({
            staff_id: line.staff_id,
            advance_recovery: isBlank(line.advance_recovery) ? '0.00' : (moneyOrNull(line.advance_recovery) ?? trimmed(line.advance_recovery)),
            tds: payloadAmount(line.tds),
            ssf_employee: payloadAmount(line.ssf_employee),
            ssf_employer: payloadAmount(line.ssf_employer),
            cit_employee: payloadAmount(line.cit_employee),
            cit_employer: payloadAmount(line.cit_employer),
            note: trimmed(line.note) || null,
            items: (line.items ?? []).map((item) => ({
                pay_component_id: item.pay_component_id,
                amount: moneyOrNull(item.amount) ?? trimmed(item.amount),
                note: trimmed(item.note) || null,
            })),
        })),
    };
}

/**
 * Comparable fingerprint of the draft: equal fingerprints mean nothing the
 * server would store has changed ("1500" and "1500.00" are the same).
 *
 * @returns {string}
 */
export function payrollDraftFingerprint(state) {
    return JSON.stringify(payrollDraftPayload(state));
}

/**
 * Live gross / recovery / TDS / SSF / CIT / net of one staff line (net = gross
 * - recovery - TDS - employee SSF - employee CIT; the employer amounts are
 * extra employer cost and change neither gross nor net). Unparseable entries
 * count as zero here; validatePayrollDraft() is what reports them.
 *
 * @returns {{gross: string, recovery: string, tds: string, ssf_employee: string, ssf_employer: string, cit_employee: string, cit_employer: string, net: string}}
 */
export function payrollLineFigures(line) {
    const gross = sumMoney((line.items ?? []).map((item) => moneyOrNull(item.amount) ?? '0.00'));
    const recovery = moneyOrNull(line.advance_recovery) ?? '0.00';
    const tds = moneyOrNull(line.tds) ?? '0.00';
    const ssfEmployee = moneyOrNull(line.ssf_employee) ?? '0.00';
    const ssfEmployer = moneyOrNull(line.ssf_employer) ?? '0.00';
    const citEmployee = moneyOrNull(line.cit_employee) ?? '0.00';
    const citEmployer = moneyOrNull(line.cit_employer) ?? '0.00';

    return {
        gross,
        recovery,
        tds,
        ssf_employee: ssfEmployee,
        ssf_employer: ssfEmployer,
        cit_employee: citEmployee,
        cit_employer: citEmployer,
        net: subtractMoney(gross, sumMoney([recovery, tds, ssfEmployee, citEmployee])),
    };
}

/** @returns {{count: number, gross: string, recovery: string, tds: string, ssf_employee: string, ssf_employer: string, cit_employee: string, cit_employer: string, net: string}} */
export function payrollDraftTotals(lines) {
    const figures = (lines ?? []).map((line) => payrollLineFigures(line));

    return {
        count: figures.length,
        gross: sumMoney(figures.map((f) => f.gross)),
        recovery: sumMoney(figures.map((f) => f.recovery)),
        tds: sumMoney(figures.map((f) => f.tds)),
        ssf_employee: sumMoney(figures.map((f) => f.ssf_employee)),
        ssf_employer: sumMoney(figures.map((f) => f.ssf_employer)),
        cit_employee: sumMoney(figures.map((f) => f.cit_employee)),
        cit_employer: sumMoney(figures.map((f) => f.cit_employer)),
        net: sumMoney(figures.map((f) => f.net)),
    };
}

/**
 * Every editable value of the draft keyed by the server's error path
 * (`period_end`, `lines.2.advance_recovery`, `lines.0.items.1.amount`,
 * `lines.0.items` = item count). Used to spot which fields the user touched.
 *
 * @param {object} state
 * @returns {Record<string, string|number|null>}
 */
export function payrollDraftFieldValues(state) {
    const values = {
        period_label: state.period_label,
        period_start: state.period_start,
        period_end: state.period_end,
        date: state.date,
        narration: state.narration,
    };

    (state.lines ?? []).forEach((line, lineIndex) => {
        const base = `lines.${lineIndex}`;

        values[`${base}.staff_id`] = line.staff_id;
        values[`${base}.advance_recovery`] = line.advance_recovery;
        MONEY_FIELDS.forEach(({ field }) => {
            values[`${base}.${field}`] = line[field];
        });
        values[`${base}.note`] = line.note;
        values[`${base}.items`] = (line.items ?? []).length;
        (line.items ?? []).forEach((item, itemIndex) => {
            const itemBase = `${base}.items.${itemIndex}`;

            values[`${itemBase}.pay_component_id`] = item.pay_component_id;
            values[`${itemBase}.amount`] = item.amount;
            values[`${itemBase}.note`] = item.note;
        });
    });

    return values;
}

/**
 * Error paths whose value differs between two snapshots from
 * payrollDraftFieldValues() (added, removed or edited fields).
 *
 * @returns {string[]}
 */
export function changedPayrollDraftPaths(before, after) {
    const paths = new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})]);

    return [...paths].filter((path) => before?.[path] !== after?.[path]);
}

/**
 * Outstanding advance of a staff member as a 2dp string. The server sends a
 * map keyed by staff id with zero balances left out (and `[]` when nobody
 * owes anything), so a missing key means zero.
 *
 * @returns {string}
 */
export function advanceBalanceFor(advanceBalances, staffId) {
    const value = advanceBalances?.[staffId];

    return moneyOrNull(value) ?? '0.00';
}

function amountError(value, label) {
    if (isBlank(value)) {
        return `Enter the ${label}.`;
    }
    const parsed = parseMoney(trimmed(value));
    if (!parsed.ok) {
        return parsed.reason === 'too_many_decimals'
            ? `The ${label} can have at most 2 decimal places.`
            : `The ${label} must be a number.`;
    }

    return '';
}

/**
 * Client-side mirror of the save rules. Keys use the server's error paths
 * (`period_end`, `lines`, `lines.2.advance_recovery`, `lines.0.items.1.amount`)
 * so one lookup serves both client and server messages.
 *
 * @param {object} state
 * @param {{advanceBalances?: object, activeComponentIds?: ?Set<number>, activeStaffIds?: ?Set<number>}} context
 * @returns {Record<string, string>}
 */
export function validatePayrollDraft(state, { advanceBalances = {}, activeComponentIds = null, activeStaffIds = null } = {}) {
    const errors = {};

    if (isBlank(state.period_label)) {
        errors.period_label = 'Enter the period label.';
    } else if (trimmed(state.period_label).length > 100) {
        errors.period_label = 'The period label can be at most 100 characters.';
    }
    if (!state.period_start) {
        errors.period_start = 'Choose the period start.';
    }
    if (!state.period_end) {
        errors.period_end = 'Choose the period end.';
    } else if (state.period_start && state.period_end < state.period_start) {
        // ISO dates compare correctly as strings.
        errors.period_end = 'The period end must be on or after the start.';
    }
    if (!state.date) {
        errors.date = 'Choose the posting date.';
    }
    if (trimmed(state.narration).length > 200) {
        errors.narration = 'The narration can be at most 200 characters.';
    }

    const lines = state.lines ?? [];
    if (lines.length === 0) {
        errors.lines = 'A payroll run needs at least one staff member.';
    }

    const seenStaff = new Set();
    lines.forEach((line, lineIndex) => {
        const base = `lines.${lineIndex}`;

        if (seenStaff.has(line.staff_id)) {
            errors[`${base}.staff_id`] = 'This staff member is already in the run.';
        } else if (activeStaffIds && !activeStaffIds.has(line.staff_id)) {
            // The server refuses an inactive staff member in a saved run.
            errors[`${base}.staff_id`] = 'This staff member is no longer active; remove them from the run.';
        }
        seenStaff.add(line.staff_id);

        const items = line.items ?? [];
        if (items.length === 0) {
            errors[`${base}.items`] = 'Add at least one pay item.';
        }

        const seenComponents = new Set();
        items.forEach((item, itemIndex) => {
            const itemBase = `${base}.items.${itemIndex}`;

            if (item.pay_component_id === null || item.pay_component_id === undefined || item.pay_component_id === '') {
                errors[`${itemBase}.pay_component_id`] = 'Choose a pay component.';
            } else if (seenComponents.has(item.pay_component_id)) {
                errors[`${itemBase}.pay_component_id`] = 'Already added for this staff member; combine them into one amount.';
            } else if (activeComponentIds && !activeComponentIds.has(item.pay_component_id)) {
                errors[`${itemBase}.pay_component_id`] = 'This pay component is no longer active; choose another.';
            }
            seenComponents.add(item.pay_component_id);

            const invalidAmount = amountError(item.amount, 'amount');
            if (invalidAmount) {
                errors[`${itemBase}.amount`] = invalidAmount;
            } else {
                const amount = moneyOrNull(item.amount);
                if (compareMoney(amount, '0.00') <= 0) {
                    errors[`${itemBase}.amount`] = 'The amount must be greater than zero.';
                } else if (compareMoney(amount, MAX_AMOUNT) > 0) {
                    errors[`${itemBase}.amount`] = 'The amount is too large.';
                }
            }

            if (trimmed(item.note).length > 255) {
                errors[`${itemBase}.note`] = 'The note can be at most 255 characters.';
            }
        });

        if (!isBlank(line.advance_recovery)) {
            const invalidRecovery = amountError(line.advance_recovery, 'advance recovery');
            if (invalidRecovery) {
                errors[`${base}.advance_recovery`] = invalidRecovery;
            } else {
                const recovery = moneyOrNull(line.advance_recovery);
                const { gross } = payrollLineFigures(line);
                const balance = advanceBalanceFor(advanceBalances, line.staff_id);

                if (compareMoney(recovery, '0.00') < 0) {
                    errors[`${base}.advance_recovery`] = 'The advance recovery cannot be negative.';
                } else if (compareMoney(recovery, gross) > 0) {
                    errors[`${base}.advance_recovery`] = 'The advance recovery cannot exceed the gross pay.';
                } else if (compareMoney(recovery, '0.00') > 0 && compareMoney(recovery, balance) > 0) {
                    errors[`${base}.advance_recovery`] = 'The advance recovery is more than the outstanding advance.';
                }
            }
        }

        // TDS, SSF and CIT amounts: each >= 0 and not above MAX_AMOUNT; the
        // withheld ones (TDS + employee SSF + employee CIT) together with the
        // advance recovery may not exceed gross. Employer amounts have no gross cap.
        // net = gross - everything withheld, so a negative net means the cap is broken.
        const withheldOverGross = compareMoney(payrollLineFigures(line).net, '0.00') < 0;

        MONEY_FIELDS.forEach(({ field, label, withheld }) => {
            if (isBlank(line[field])) {
                return;
            }
            const invalid = amountError(line[field], label);
            if (invalid) {
                errors[`${base}.${field}`] = invalid;

                return;
            }
            const amount = moneyOrNull(line[field]);

            if (compareMoney(amount, '0.00') < 0) {
                errors[`${base}.${field}`] = `The ${label} cannot be negative.`;
            } else if (compareMoney(amount, MAX_AMOUNT) > 0) {
                errors[`${base}.${field}`] = `The ${label} is too large.`;
            } else if (withheld && withheldOverGross && compareMoney(amount, '0.00') > 0) {
                errors[`${base}.${field}`] = WITHHELD_MESSAGE;
            }
        });

        if (trimmed(line.note).length > 255) {
            errors[`${base}.note`] = 'The note can be at most 255 characters.';
        }
    });

    return errors;
}

/**
 * Binds the helpers above to the page's useForm instance.
 *
 * `source()` returns the current Show props ({ run, lines, components,
 * staffOptions, advanceBalances }). The form is NOT rebuilt from props on
 * every response: a failed save re-renders the page with the stored lines,
 * and rebuilding then would wipe the user's edits. Only a successful save
 * calls `reload(page.props)`, which also resets the "unsaved" baseline.
 *
 * @param {object} form useForm(draftFromRun(...)) instance
 * @param {() => object} source
 */
export function usePayrollDraft(form, source) {
    const baseline = ref(payrollDraftFingerprint(form));
    // Client messages appear once the user has tried to save, so a freshly
    // added empty row is not shouted at while it is being filled in.
    const attempted = ref(false);

    const components = computed(() => source().components ?? []);
    const staffOptions = computed(() => source().staffOptions ?? []);
    const activeComponentIds = computed(() => new Set(components.value.map((c) => c.id)));
    const activeStaffIds = computed(() => new Set(staffOptions.value.map((s) => s.id)));

    const isDirty = computed(() => payrollDraftFingerprint(form) !== baseline.value);
    const totals = computed(() => payrollDraftTotals(form.lines));
    const lineFigures = computed(() => new Map(form.lines.map((line) => [line.key, payrollLineFigures(line)])));

    const clientErrors = computed(() =>
        validatePayrollDraft(form, {
            advanceBalances: source().advanceBalances,
            activeComponentIds: activeComponentIds.value,
            activeStaffIds: activeStaffIds.value,
        }),
    );
    const hasClientErrors = computed(() => Object.keys(clientErrors.value).length > 0);

    const availableStaff = computed(() => {
        const inRun = new Set(form.lines.map((line) => line.staff_id));

        return staffOptions.value.filter((staff) => !inRun.has(staff.id));
    });

    // A server message describes the value that was saved, so it goes away as
    // soon as the user edits that field; the banner-level `lines` / `run`
    // messages go away on any edit.
    watch(
        () => payrollDraftFieldValues(form),
        (after, before) => {
            const changed = changedPayrollDraftPaths(before, after).filter((path) => form.errors[path] !== undefined);
            const banners = ['lines', 'run'].filter((key) => form.errors[key] !== undefined);

            if (changed.length > 0 || banners.length > 0) {
                form.clearErrors(...changed, ...banners);
            }
        },
    );

    /** Server message first (it is the authority), then the client mirror. */
    function errorFor(path) {
        return form.errors[path] || (attempted.value ? clientErrors.value[path] : '') || '';
    }

    function figuresFor(line) {
        return lineFigures.value.get(line.key) ?? payrollLineFigures(line);
    }

    function advanceFor(staffId) {
        return advanceBalanceFor(source().advanceBalances, staffId);
    }

    function isActiveStaff(staffId) {
        return activeStaffIds.value.has(staffId);
    }

    /**
     * Component choices for one item: the active components not already used
     * by another item of the same staff member, plus the item's own current
     * component even when it has since been deactivated (so it still shows).
     */
    function componentOptionsFor(line, item) {
        const usedElsewhere = new Set(line.items.filter((other) => other.key !== item.key).map((other) => other.pay_component_id));
        const options = components.value
            .filter((c) => !usedElsewhere.has(c.id))
            .map((c) => ({ value: c.id, label: c.code ? `${c.name} (${c.code})` : c.name }));

        if (item.pay_component_id && !activeComponentIds.value.has(item.pay_component_id)) {
            options.unshift({ value: item.pay_component_id, label: `${item.component_name || 'Component'} (inactive)` });
        }

        return options;
    }

    function canAddItem(line) {
        const used = new Set(line.items.map((item) => item.pay_component_id));

        return components.value.some((c) => !used.has(c.id));
    }

    function addStaff(staffId) {
        const staff = staffOptions.value.find((s) => s.id === staffId);
        if (!staff || form.lines.some((line) => line.staff_id === staffId)) {
            return;
        }
        form.lines.push({
            key: nextKey(),
            staff_id: staff.id,
            staff_code: staff.staff_code ?? '',
            staff_name: staff.name ?? '',
            advance_recovery: '0.00',
            tds: '0.00',
            // Local only (never sent): the staff member's TDS rate and the last
            // value the editor itself wrote into `tds`. While `tds` still equals
            // that value the user has not touched it, so it follows the gross.
            tds_rate: moneyOrNull(staff.tds_rate),
            tds_auto_value: '0.00',
            // Same idea for SSF / CIT, but their rates apply to the basic pay
            // components (is_contribution_base), not to the whole gross.
            ...Object.fromEntries(
                CONTRIBUTION_FIELDS.flatMap(({ field, rateKey }) => [
                    [field, '0.00'],
                    [`${field}_rate`, moneyOrNull(staff[rateKey])],
                    [`${field}_auto_value`, '0.00'],
                ]),
            ),
            note: '',
            items: [emptyPayItem()],
        });
    }

    // Pre-fill TDS for newly added staff as percentOf(gross, rate), and SSF / CIT
    // as percentOf(basic pay, rate). A value the user has typed over is never replaced.
    watch(
        () =>
            form.lines
                .map((line) => `${payrollLineFigures(line).gross}:${contributionBase(line, components.value)}`)
                .join('|'),
        () => {
            form.lines.forEach((line) => {
                if (line.tds_rate && line.tds === line.tds_auto_value) {
                    const next = percentOf(payrollLineFigures(line).gross, line.tds_rate);

                    line.tds = next;
                    line.tds_auto_value = next;
                }

                const base = contributionBase(line, components.value);

                CONTRIBUTION_FIELDS.forEach(({ field }) => {
                    const rate = line[`${field}_rate`];

                    if (!rate || line[field] !== line[`${field}_auto_value`]) {
                        return;
                    }
                    const next = percentOf(base, rate);

                    line[field] = next;
                    line[`${field}_auto_value`] = next;
                });
            });
        },
    );

    // Removing a row shifts the indices the server's error keys point at,
    // so stale server messages are dropped rather than shown on the wrong row.
    function removeStaff(lineIndex) {
        form.lines.splice(lineIndex, 1);
        form.clearErrors();
    }

    function addItem(line) {
        line.items.push(emptyPayItem());
    }

    function removeItem(line, itemIndex) {
        line.items.splice(itemIndex, 1);
        form.clearErrors();
    }

    function setComponent(item, componentId) {
        item.pay_component_id = componentId;
        item.component_name = components.value.find((c) => c.id === componentId)?.name ?? item.component_name;
    }

    /** After a successful save: adopt the stored draft and forget the edits. */
    function reload(pageProps) {
        const fresh = draftFromRun(pageProps.run, pageProps.lines);
        Object.keys(fresh).forEach((field) => {
            form[field] = fresh[field];
        });
        form.clearErrors();
        baseline.value = payrollDraftFingerprint(form);
        attempted.value = false;
    }

    return {
        attempted,
        isDirty,
        totals,
        clientErrors,
        hasClientErrors,
        availableStaff,
        errorFor,
        figuresFor,
        advanceFor,
        isActiveStaff,
        componentOptionsFor,
        canAddItem,
        addStaff,
        removeStaff,
        addItem,
        removeItem,
        setComponent,
        reload,
    };
}
