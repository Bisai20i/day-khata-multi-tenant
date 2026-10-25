<?php

namespace App\Exports\Samples;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ItemSampleExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function headings(): array
    {
        return [
            'name',
            'category',
            'subcategory',
            'unit',
            'hs_code',
            'barcode',
            'min_stock',
            'purchase_rate',
            'sale_rate',
            'is_vatable',
            'is_stockable',
            'posting_account',
        ];
    }

    public function collection(): Collection
    {
        return collect([
            [
                'name' => 'Standard Widget A',
                'category' => 'General',
                'subcategory' => 'Widgets',
                'unit' => 'pcs',
                'hs_code' => '8517.12.00',
                'barcode' => '8901234567890',
                'min_stock' => '10',
                'purchase_rate' => '100.00',
                'sale_rate' => '150.00',
                'is_vatable' => 'yes',
                'is_stockable' => 'yes',
                'posting_account' => '',
            ],
            [
                'name' => 'Organic Green Tea',
                'category' => 'Food & Beverage',
                'subcategory' => 'Tea',
                'unit' => 'packet',
                'hs_code' => '0902.10.00',
                'barcode' => '8909876543210',
                'min_stock' => '5',
                'purchase_rate' => '200.00',
                'sale_rate' => '250.00',
                'is_vatable' => 'no',
                'is_stockable' => 'yes',
                'posting_account' => '',
            ],
        ]);
    }
}
