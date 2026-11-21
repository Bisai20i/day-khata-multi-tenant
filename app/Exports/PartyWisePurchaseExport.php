<?php

namespace App\Exports;

/**
 * Mirrors PartyWisePurchaseReportController::report(): the supplier summary,
 * or the selected supplier's bills and debit notes. Same layout, number
 * formats and C1 float boundary as PartyWiseSalesExport; only the words and
 * the drill-down's reference cell (the supplier's own bill number) differ.
 */
class PartyWisePurchaseExport extends PartyWiseSalesExport
{
    /**
     * @return array{title: string, party: string, documents: string, number: string, reference: string, returns_count: string, returns: string, received: string, receipts: string, against: string}
     */
    protected function labels(): array
    {
        return [
            'title' => 'Supplier-wise Purchase',
            'party' => 'Supplier',
            'documents' => 'Bills',
            'number' => 'Purchase No',
            'reference' => 'Supplier Bill No',
            'returns_count' => 'Debit Notes',
            'returns' => 'Returns',
            'received' => 'Paid',
            'receipts' => 'Payments in the period',
            'against' => 'Against Bill',
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function documentReference(array $document): string
    {
        return (string) ($document['bill_number'] ?? '');
    }
}
