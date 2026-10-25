<?php

namespace App\Exports\Samples;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CustomerSampleExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function headings(): array
    {
        return ['name', 'address', 'mobile_no', 'email', 'tpin', 'citizenship'];
    }

    public function collection(): Collection
    {
        return collect([
            [
                'name' => 'Acme Trading Pvt. Ltd.',
                'address' => 'Tripureshwor, Kathmandu',
                'mobile_no' => '9841000001',
                'email' => 'info@acmetrading.com',
                'tpin' => '600123456',
                'citizenship' => '',
            ],
            [
                'name' => 'Ram Sharma',
                'address' => 'Patan, Lalitpur',
                'mobile_no' => '9851000002',
                'email' => 'ram@example.com',
                'tpin' => '',
                'citizenship' => '27-01-72-12345',
            ],
        ]);
    }
}
