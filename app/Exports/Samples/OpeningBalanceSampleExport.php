<?php

namespace App\Exports\Samples;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OpeningBalanceSampleExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function headings(): array
    {
        return ['code', 'name', 'debit', 'credit'];
    }

    public function collection(): Collection
    {
        return collect([
            [
                'code' => 'AS1',
                'name' => 'Cash on hand',
                'debit' => '50000.00',
                'credit' => '0.00',
            ],
            [
                'code' => 'EQ1',
                'name' => 'Capital Account',
                'debit' => '0.00',
                'credit' => '50000.00',
            ],
        ]);
    }
}
