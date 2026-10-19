import { nextTick, onBeforeUnmount, onMounted, ref, toRaw } from 'vue';
import { useConfirm } from '@/composables/useConfirm';
import { canonicalRate, lineKey, pendingFor, priceChangePrompt, priceCheckOf, pricePayload } from '@/lib/purchasePriceUpdate';

/**
 * "Update the item's saved purchase price?" for Purchases/Create.vue.
 *
 * Asked when a line is added from the "Add item" row, and when a line
 * already on the bill changes: its rate once the clerk leaves that field
 * (never per keystroke), or its unit or item. Each line owns its answers,
 * kept on the line itself (`line.price_check`, see priceCheckOf()), so they
 * survive the quick-add draft bounce with the line and are gone when the
 * line is removed:
 * - Yes is in force only while the line keeps the item, unit and rate it was
 *   given for. Once the line changes it lapses for good, so going back to
 *   that rate asks again. One Yes per item: a Yes on one line clears the
 *   others' for that item. The server writes it when the bill is saved,
 *   inside the bill's own transaction (ItemPurchasePriceUpdater).
 * - No keeps the typed rate on this bill only, and that line is not asked
 *   again about any item, unit and rate it already declined.
 * An abandoned bill changes nothing.
 *
 * @param {object} form the purchase useForm(), carrying `lines`
 * @param {import('vue').Ref<Map<number, object>>} itemsById
 */
export function usePurchasePriceConfirm(form, itemsById) {
    const { confirm } = useConfirm();

    // True while a prompt is open, so the bill can't be saved mid-question.
    const asking = ref(false);
    // Raw line -> {line, options}: lines to ask about once the open prompt
    // closes, so a question that comes up while one is showing isn't lost.
    const queued = new Map();

    function updateCheck(line, changes) {
        line.price_check = { ...priceCheckOf(line), ...changes };
    }

    /**
     * Call right after the form itself put a saved rate into `line.rate`
     * (item pick, unit switch): adding the line at that rate never prompts.
     * A unit switch that keeps the old rate (no saved price for the new unit)
     * must not call it - that carried-over rate is the clerk's, so it asks.
     */
    function notePrefill(line) {
        updateCheck(line, { prefilled: lineKey(line) });
    }

    function promptNote(prompt) {
        if (prompt.onlyThisUnit) {
            return 'This unit has no conversion factor, so only its own price changes. Applied when this bill is saved; earlier bills keep their rates.';
        }
        if (prompt.rescaleSkipped) {
            return 'Only this unit changes: in proportion, another unit would be zero or too large. Applied when this bill is saved; earlier bills keep their rates.';
        }

        return 'Applied when this bill is saved. Earlier bills keep their rates.';
    }

    /**
     * Asks about `line` if its rate differs from the saved price. Resolves
     * once answered (or straight away when there is nothing to ask). Called
     * while another prompt is open, the line is asked about once that one
     * closes instead, re-checked against whatever was answered meanwhile.
     *
     * @param {object} line
     * @param {{returnFocus?: Function}} [options] where focus goes when the
     *   dialog closes; defaults to whatever had focus when it opened.
     */
    async function confirmLine(line, options = {}) {
        if (asking.value) {
            queued.set(toRaw(line), { line, options });
            return;
        }

        await askLine(line, options);

        // One prompt at a time: askLine() sets `asking` before it awaits, so
        // anything arriving meanwhile is queued rather than opened.
        while (!asking.value && queued.size) {
            const [raw, next] = queued.entries().next().value;
            queued.delete(raw);
            // A line removed from the bill while it waited is not asked about.
            if (form.lines.includes(next.line)) await askLine(next.line, next.options);
        }
    }

    async function askLine(line, { returnFocus } = {}) {
        // A Yes given for an earlier rate, unit or item of this line lapses
        // for good, so coming back to it asks again.
        const { confirmed } = priceCheckOf(line);
        if (confirmed !== null && confirmed !== lineKey(line)) updateCheck(line, { confirmed: null });

        const item = itemsById.value.get(line.item_id);
        if (!item) return;

        const prompt = priceChangePrompt({ item, line, pending: pendingFor(form.lines, line.item_id, line) });
        if (!prompt) return;

        asking.value = true;
        // Let the page finish moving focus for the line it just added, so
        // the default "return to what had focus" lands on that field.
        await nextTick();
        const focusedAtOpen = document.activeElement;

        const details = prompt.others.map((other) => `${other.name}: ${other.oldLabel} → ${other.newLabel}`);
        const yes = await confirm({
            title: 'Update saved purchase price?',
            message:
                `Purchase price of ${item.name} (${prompt.unitName}) changed from ${prompt.oldLabel} to ${prompt.newLabel}. ` +
                "Also update the item's saved purchase price?",
            details,
            detailsTitle: details.length ? 'Other units will be updated in proportion:' : '',
            note: promptNote(prompt),
            confirmLabel: 'Yes, update saved price',
            cancelLabel: 'No, this bill only',
            safeDefault: true,
            returnFocus: returnFocus ?? (() => focusedAtOpen?.isConnected && focusedAtOpen.focus?.()),
        });
        asking.value = false;

        if (!yes) {
            updateCheck(line, { declined: [...priceCheckOf(line).declined, prompt.key] });
            return;
        }

        // One Yes per item, as every unit is rescaled from one chosen price.
        for (const other of form.lines) {
            if (toRaw(other) !== toRaw(line) && String(other.item_id) === String(line.item_id) && priceCheckOf(other).confirmed !== null) {
                updateCheck(other, { confirmed: null });
            }
        }
        updateCheck(line, { confirmed: prompt.key });
    }

    // --- A pointer press that ends a rate edit -----------------------------
    // The prompt opens during the mousedown that took focus off the rate, so
    // the dialog's overlay is up before the mouseup and the click on Save,
    // Remove or any other button never lands. The press is tracked here so
    // the blur can tell a click from a Tab, and that button is clicked once
    // the question is answered.
    const POINTER_EVENTS = ['pointerdown', 'pointerup', 'pointercancel'];
    let pointerDown = false;

    function onPointer(event) {
        pointerDown = event.type === 'pointerdown';
    }

    onMounted(() => POINTER_EVENTS.forEach((type) => document.addEventListener(type, onPointer, true)));
    onBeforeUnmount(() => POINTER_EVENTS.forEach((type) => document.removeEventListener(type, onPointer, true)));

    // --- Rate edits on lines already on the bill --------------------------
    // The rate in force when the clerk entered the field; leaving it with a
    // different value is the "line updated" moment for an inline row.
    const rateOnFocus = new WeakMap();

    function onLineRateFocus(line) {
        rateOnFocus.set(toRaw(line), line.rate);
    }

    /**
     * @param {object} line
     * @param {FocusEvent} [event] the rate input's native blur. During it
     *   `document.activeElement` is still <body>, so where the clerk was going
     *   (Tab, a click) is read from `relatedTarget` instead, falling back to
     *   the rate input itself when that target is gone. No `relatedTarget`
     *   (a click on empty space) leaves focus where the clerk put it. A
     *   button of this form pressed with the pointer is clicked again once
     *   the question is answered (see above).
     */
    function onLineRateBlur(line, event) {
        const before = rateOnFocus.get(toRaw(line));
        rateOnFocus.delete(toRaw(line));
        if (before === undefined || canonicalRate(before) === canonicalRate(line.rate)) return;

        const goingTo = event?.relatedTarget ?? null;
        const rateInput = event?.target ?? null;
        const pressedButton =
            pointerDown && goingTo instanceof HTMLButtonElement && goingTo.form !== null && goingTo.form === rateInput?.form ? goingTo : null;
        // Rows are keyed by index, so once a row above is removed this same
        // <input> (and Remove button) shows another line: they are only this
        // line's while the line is still at the index it has now.
        const lineIndex = form.lines.indexOf(line);
        const lineUnmoved = () => lineIndex !== -1 && form.lines.indexOf(line) === lineIndex;

        confirmLine(
            line,
            event
                ? {
                      returnFocus: () => {
                          if (!goingTo) return;
                          if (pressedButton?.isConnected && lineUnmoved()) {
                              pressedButton.focus();
                              pressedButton.click();
                          } else if (goingTo.isConnected) {
                              goingTo.focus?.();
                          } else if (rateInput?.isConnected && lineUnmoved()) {
                              rateInput.focus?.();
                          }
                      },
                  }
                : {},
        );
    }

    /** `price_updates` for the save request, from the lines' own answers. */
    function payload(lines) {
        return pricePayload(lines);
    }

    return { asking, notePrefill, confirmLine, onLineRateFocus, onLineRateBlur, payload };
}
