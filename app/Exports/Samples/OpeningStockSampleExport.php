<?php

namespace App\Exports\Samples;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OpeningStockSampleExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function headings(): array
    {
        return ['item', 'quantity', 'unit_cost_rate', 'note'];
    }

    public function collection(): Collection
    {
        return collect([
            [
                'item' => 'Standard Widget A',
                'quantity' => '100',
                'unit_cost_rate' => '100.00',
                'note' => 'Opening stock as of system setup',
            ],
            [
                'item' => 'Organic Green Tea',
                'quantity' => '50',
                'unit_cost_rate' => '200.00',
                'note' => 'Opening stock as of system setup',
            ],
        ]);
    }
}
