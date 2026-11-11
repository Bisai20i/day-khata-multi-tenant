import { computed, ref, watch } from 'vue';
// Relative (not `@/`) so the pure helpers below can be imported by a plain `node --test` file.
import { compareMoney, parseMoney, subtractMoney, sumMoney } from '../lib/money.js';

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
 * Live gross / recovery / net of one staff line. Unparseable entries count
 * as zero here; validatePayrollDraft() is what reports them.
 *
 * @returns {{gross: string, recovery: string, net: string}}
 */
export function payrollLineFigures(line) {
    const gross = sumMoney((line.items ?? []).map((item) => moneyOrNull(item.amount) ?? '0.00'));
    const recovery = moneyOrNull(line.advance_recovery) ?? '0.00';

    return { gross, recovery, net: subtractMoney(gross, recovery) };
}

/** @returns {{count: number, gross: string, recovery: string, net: string}} */
export function payrollDraftTotals(lines) {
    const figures = (lines ?? []).map((line) => payrollLineFigures(line));

    return {
        count: figures.length,
        gross: sumMoney(figures.map((f) => f.gross)),
        recovery: sumMoney(figures.map((f) => f.recovery)),
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
            note: '',
            items: [emptyPayItem()],
        });
    }

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
