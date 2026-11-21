<?php

namespace App\Exports;

/**
 * Mirrors ItemWisePurchaseReportController's own report() payload, in the
 * exact layout of ItemWiseSalesExport (item summary with Total and per-unit
 * quantity rows, then the selected item's purchase and return lines), with
 * purchase wording.
 */
class ItemWisePurchaseExport extends ItemWiseSalesExport
{
    protected function title(): string
    {
        return 'Item-wise Purchase';
    }

    /**
     * @return array{quantity: string, value: string, vat: string, gross: string, documents: string, document: string, party: string, main_kind: string}
     */
    protected function labels(): array
    {
        return [
            'quantity' => 'Qty Purchased (base)',
            'value' => 'Purchase Value (excl. VAT)',
            'vat' => 'VAT',
            'gross' => 'Purchase incl. VAT',
            'documents' => 'Bills',
            'document' => 'Bill / Debit Note No',
            'party' => 'Supplier',
            'main_kind' => 'Purchase',
        ];
    }
}
