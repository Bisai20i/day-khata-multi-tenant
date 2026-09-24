import { computed } from 'vue';
import { formatQuantity, rateExcludingVat } from '@/lib/money';
import { stockStatus } from '@/lib/saleCreate';

/**
 * Item lookups and the generic line-editing actions for Sales/Create.vue.
 * The actions are written against whatever `line` object is passed in, so the
 * staging row and every committed row share them.
 */
export function useSaleCreateItems(props) {
    // The invoice type is never a form field - it's fixed per tenant by the
    // platform admin (TenantCompanySettingController), so this just reads the
    // value the server already applies to every sale (see Sale::post()).
    const isPanInvoice = computed(() => props.invoiceSettings.active_invoice_type === 'pan');

    // A PAN invoice carries no VAT at all; every other type uses the tenant's
    // configured rate. The rate is never editable here - the server ignores any
    // rate the browser sends and always reads CompanySetting::default_vat_rate.
    const effectiveVatRate = computed(() => (isPanInvoice.value ? '0.00' : String(props.invoiceSettings.default_vat_rate ?? '0')));

    // searchValue lets a barcode match the item even though it isn't shown in
    // the option's label - see Combobox.vue's searchText(). A scanner types the
    // barcode then Enter, reka-ui's own filter narrows to that one item and
    // highlights it, so Enter selects it exactly like picking from the list.
    const itemOptions = computed(() =>
        props.items.map((i) => ({
            value: i.id,
            label: `${i.name} (${i.unit})`,
            searchValue: i.barcode ? `${i.name} ${i.barcode}` : i.name,
            // Stock shown right in the option row so a cashier can see availability
            // while picking, without a separate badge appearing after selection.
            meta: i.current_stock != null ? `${formatQuantity(i.current_stock)} ${i.unit}` : null,
            metaClass: stockStatus(i) === 'out' ? 'text-danger' : stockStatus(i) === 'low' ? 'text-[#92400E]' : 'text-text-muted',
        })),
    );
    const itemsById = computed(() => Object.fromEntries(props.items.map((i) => [i.id, i])));

    // Selecting an alternate unit auto-fills the rate from that unit's own
    // sale_rate override; switching back to the base unit ('') restores the
    // item's own sale_rate, so a mis-click no longer leaves a Box rate sitting on
    // a Piece line. Still freely editable afterwards - Sale::post() only ever
    // uses the entered rate, never the unit's.
    function selectLineUnit(line, unitId) {
        line.item_unit_id = unitId;
        // An MRP is a price for ONE of whatever unit was selected, so the number
        // in the box stops meaning anything the moment the unit changes.
        line.mrp = '';

        if (unitId === '' || unitId === null) {
            const item = itemsById.value[line.item_id];
            if (item?.sale_rate != null) line.rate = String(item.sale_rate);

            return;
        }

        const unit = itemsById.value[line.item_id]?.units?.find((u) => u.id === unitId);
        if (unit?.sale_rate != null) {
            line.rate = String(unit.sale_rate);
        }
    }

    // Changing the item invalidates whatever unit was selected for the
    // previous item (a unit id from one item's alt-units list is meaningless
    // for another item), so it's reset back to the base unit, and the new
    // item's own sale rate is prefilled.
    function selectLineItem(line, itemId) {
        line.item_id = itemId;
        line.item_unit_id = '';
        line.mrp = '';

        const item = itemsById.value[itemId];
        line.rate = item?.sale_rate != null ? String(item.sale_rate) : '';
    }

    /**
     * MRP / VAT-inclusive entry (audit section 3 "Sales").
     *
     * The shopkeeper types the sticker price and the line's rate is back-
     * calculated exactly - `rate = MRP / 1.13` for a vatable line at 13% - so the
     * printed bill shows rate 100 plus 13 VAT for an MRP of 113 instead of
     * charging VAT on top of a price that already contained it.
     *
     * The division runs in the money module (rateExcludingVat, scaled BigInt,
     * one HalfUp rounding to 4dp); nothing here touches Number() or parseFloat.
     * Only the resulting RATE is ever submitted - the server re-derives nothing
     * from the MRP and does not even receive it.
     *
     * A non-vatable line (or any line on a PAN invoice, which carries no VAT at
     * all) divides by 1: the MRP is the rate.
     */
    function applyLineMrp(line, mrp) {
        line.mrp = mrp;

        if (mrp === '' || mrp === null || mrp === undefined) {
            return;
        }

        const item = itemsById.value[line.item_id];
        const vatRate = !isPanInvoice.value && item?.is_vatable ? effectiveVatRate.value : '0';
        const result = rateExcludingVat(mrp, vatRate);

        // A half-typed or malformed MRP just leaves the rate alone: the cashier is
        // still typing, and the rate field stays theirs to edit either way.
        if (result.ok) {
            line.rate = result.value;
        }
    }

    return { isPanInvoice, effectiveVatRate, itemOptions, itemsById, selectLineUnit, selectLineItem, applyLineMrp };
}
