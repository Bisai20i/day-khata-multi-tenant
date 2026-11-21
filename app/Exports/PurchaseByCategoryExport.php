<?php

namespace App\Exports;

/**
 * Purchase by Category: the same rows and layout as SalesByCategoryExport,
 * over purchases and debit notes.
 */
class PurchaseByCategoryExport extends SalesByCategoryExport
{
    protected function title(): string
    {
        return 'Purchase by Category';
    }

    protected function valueHeading(): string
    {
        return 'Purchases';
    }

    protected function returnsHeading(): string
    {
        return 'Purchase Returns';
    }
}
