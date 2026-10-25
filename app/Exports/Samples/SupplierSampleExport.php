<?php

namespace App\Exports\Samples;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SupplierSampleExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function headings(): array
    {
        return ['name', 'address', 'mobile_no', 'email', 'tpin'];
    }

    public function collection(): Collection
    {
        return collect([
            [
                'name' => 'Himalayan Distributors',
                'address' => 'Birgunj, Parsa',
                'mobile_no' => '9801000001',
                'email' => 'himalayan@distributors.com',
                'tpin' => '300123456',
            ],
            [
                'name' => 'Valley Suppliers Pvt. Ltd.',
                'address' => 'Teku, Kathmandu',
                'mobile_no' => '9811000002',
                'email' => 'supply@valley.com',
                'tpin' => '600987654',
            ],
        ]);
    }
}
