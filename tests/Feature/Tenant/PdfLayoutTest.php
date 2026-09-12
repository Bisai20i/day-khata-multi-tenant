<?php

use App\Models\CompanySetting;

/**
 * `pdf/layout.blade.php` is the letterhead every printed document extends, so
 * the contract C9 print-compliance additions (copy stamp, BS date first, AD
 * date beside it, fiscal year, amount in words) are asserted here once rather
 * than in every document's own print test.
 *
 * The other half of what these tests protect is backwards compatibility:
 * T04-T07 wire their `print()` actions up in parallel, so until they land the
 * layout still has to render for a controller that passes none of the new
 * variables. No database is touched - the company settings row is built
 * unsaved, because the layout only reads attributes off it.
 */
function pdfLayoutCompany(): CompanySetting
{
    return new CompanySetting([
        'company_name' => 'Acme Traders Pvt. Ltd.',
        'address' => 'Baneshwor, Kathmandu',
        'pan_vat_number' => '600123456',
        'print_paper_size' => 'a4',
    ]);
}

test('the layout renders without any of the C9 variables and calls the print an Original', function () {
    $html = view('pdf.layout', [
        'company' => pdfLayoutCompany(),
        'documentNumber' => 'INV-0001',
        'documentDate' => '2024-04-13',
    ])->render();

    expect($html)->toContain('INV-0001')
        ->and($html)->toContain('Original')
        ->and($html)->not->toContain('Copy of Original')
        // The BS date is derived from documentDate when dateBs is not passed,
        // so an unconverted controller still prints a compliant date pair.
        ->and($html)->toContain('2081-01-01')
        ->and($html)->toContain('(AD 2024-04-13)')
        ->and($html)->not->toContain('Fiscal Year:')
        ->and($html)->not->toContain('Amount in Words:');
});

test('the layout stamps a reprint as Copy of Original minus one', function () {
    $html = view('pdf.layout', [
        'company' => pdfLayoutCompany(),
        'documentNumber' => 'INV-0001',
        'documentDate' => '2024-04-13',
        'copyNumber' => 3,
    ])->render();

    expect($html)->toContain('Copy of Original - 2');
});

test('copy number 1 is the original, copy number 2 is the first copy', function (int $copyNumber, string $expected) {
    $html = view('pdf.layout', [
        'company' => pdfLayoutCompany(),
        'documentNumber' => 'INV-0001',
        'documentDate' => '2024-04-13',
        'copyNumber' => $copyNumber,
    ])->render();

    expect($html)->toContain($expected);
})->with([
    [1, 'Original'],
    [2, 'Copy of Original - 1'],
    [3, 'Copy of Original - 2'],
]);

test('the layout renders every C9 variable a print action passes', function () {
    $html = view('pdf.layout', [
        'company' => pdfLayoutCompany(),
        'documentNumber' => 'INV-0042',
        'documentDate' => '2024-04-13',
        'copyNumber' => 2,
        'dateBs' => '2081-01-01',
        'dateAd' => '2024-04-13',
        'fiscalYearName' => '2081/82',
        'amountInWords' => 'Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Fifty Paisa Only',
    ])->render();

    expect($html)->toContain('INV-0042')
        ->and($html)->toContain('Copy of Original - 1')
        ->and($html)->toContain('Fiscal Year:')
        ->and($html)->toContain('2081/82')
        ->and($html)->toContain('Amount in Words:')
        ->and($html)->toContain('Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Fifty Paisa Only');
});

test('the BS date is printed before the AD date', function () {
    $html = view('pdf.layout', [
        'company' => pdfLayoutCompany(),
        'documentNumber' => 'INV-0001',
        'documentDate' => '2024-04-13',
        'dateBs' => '2081-01-01',
        'dateAd' => '2024-04-13',
    ])->render();

    expect(strpos($html, '2081-01-01'))->toBeLessThan(strpos($html, '(AD 2024-04-13)'));
});

test('a date outside the Nepali calendar table falls back to the AD date alone', function () {
    $html = view('pdf.layout', [
        'company' => pdfLayoutCompany(),
        'documentNumber' => 'INV-0001',
        'documentDate' => '2099-01-01',
    ])->render();

    expect($html)->toContain('Date (AD):')
        ->and($html)->toContain('2099-01-01')
        ->and($html)->not->toContain('Date (BS):');
});
