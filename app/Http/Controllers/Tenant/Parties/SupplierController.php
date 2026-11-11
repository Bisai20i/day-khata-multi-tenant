<?php

namespace App\Http\Controllers\Tenant\Parties;

use App\Exports\Samples\SupplierSampleExport;
use App\Http\Controllers\Concerns\HandlesPartyAreas;
use App\Http\Controllers\Concerns\ImportsCsv;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierController extends Controller
{
    use HandlesPartyAreas, ImportsCsv;

    /**
     * Columns of the bulk-import template, in the order they're written to
     * the downloadable CSV. Also doubles as the set of fields read back out
     * of an uploaded file's header row (see ImportsCsv::parseCsvRows).
     *
     * @var list<string>
     */
    private const IMPORT_COLUMNS = ['name', 'address', 'mobile_no', 'email', 'tpin', 'area'];

    public function index(): Response
    {
        return Inertia::render('Tenant/Parties/Suppliers/Index', [
            'suppliers' => Supplier::query()->with(['account:id,code', 'area:id,name'])->latest()->get(),
            'areas' => Area::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create($this->validated($request));

        return redirect()->route('tenant.suppliers.index')->with('status', 'Supplier added.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return redirect()->route('tenant.suppliers.index')->with('status', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()->route('tenant.suppliers.index')->with('status', 'Supplier deleted.');
    }

    /**
     * Downloads a blank CSV template with the exact header this app's import
     * expects, plus one example row.
     */
        public function importSampleExcel()
    {
        return Excel::download(new SupplierSampleExport(), 'supplier-import-sample.xlsx');
    }
    public function importTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::IMPORT_COLUMNS);
            fputcsv($handle, ['ABC Traders', 'Kathmandu-10', '9800000001', 'contact@abctraders.com', '123456789', '']);
            fclose($handle);
        }, 'supplier-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Row-by-row validated bulk import. Rows that fail validation, or repeat
     * a mobile number already seen earlier in the same file, are skipped and
     * reported rather than aborting the whole import. The rows that do pass
     * are created one at a time (not a raw bulk insert) so Supplier's
     * HasLedgerAccount model event still fires for each one, and the whole
     * batch is wrapped in a transaction so a failure partway through can't
     * leave a half-imported file.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
        ]);

        $rows = $this->parseCsvRows($request->file('file'));

        if ($rows === null || ! array_key_exists('name', $rows[0])) {
            return back()->withErrors(['file' => 'That file could not be read. Make sure it matches the downloaded template and has a "name" column.']);
        }

        $seenMobiles = [];
        $skipped = [];
        $validRows = [];
        $areaIds = Area::idsByLowerName();

        foreach ($rows as $index => $row) {
            $data = [
                'name' => $row['name'] ?? '',
                'address' => $row['address'] ?? '',
                'mobile_no' => $row['mobile_no'] ?? '',
                'email' => $row['email'] ?? '',
                'tpin' => $row['tpin'] ?? '',
            ];
            $areaName = Area::normalizeName($row['area'] ?? '');

            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'address' => ['nullable', 'string', 'max:255'],
                'mobile_no' => ['nullable', 'string', 'max:20', Rule::unique('suppliers', 'mobile_no')],
                'email' => ['nullable', 'email', 'max:255'],
                'tpin' => ['nullable', 'string', 'max:50'],
            ]);

            $rowNumber = $index + 2; // +1 for the 0-based index, +1 for the header row.

            if ($validator->fails()) {
                $skipped[] = ['row' => $rowNumber, 'name' => $data['name'], 'reason' => $validator->errors()->first()];

                continue;
            }

            // Before the mobile is registered as seen: a row skipped for its area must not
            // make a later, valid row with the same mobile look like a duplicate.
            if ($areaName !== '' && ! isset($areaIds[mb_strtolower($areaName)])) {
                $skipped[] = ['row' => $rowNumber, 'name' => $data['name'], 'reason' => "Unknown area \"{$areaName}\". Create it on the Areas page first."];

                continue;
            }

            $mobileKey = strtolower($data['mobile_no']);

            if ($mobileKey !== '' && isset($seenMobiles[$mobileKey])) {
                $skipped[] = ['row' => $rowNumber, 'name' => $data['name'], 'reason' => 'Duplicate mobile number already used earlier in this file.'];

                continue;
            }

            if ($mobileKey !== '') {
                $seenMobiles[$mobileKey] = true;
            }

            $validRows[] = array_map(static fn (string $value): ?string => $value === '' ? null : $value, $data)
                + ['area_id' => $areaName === '' ? null : $areaIds[mb_strtolower($areaName)]];
        }

        $imported = 0;

        if ($validRows !== []) {
            DB::transaction(function () use ($validRows, &$imported): void {
                foreach ($validRows as $data) {
                    Supplier::create($data);
                    $imported++;
                }
            });
        }

        $total = $imported + count($skipped);

        return redirect()->route('tenant.suppliers.index')
            ->with('status', "Imported {$imported} of {$total} supplier(s).")
            ->with('importResult', ['imported' => $imported, 'skipped' => $skipped]);
    }

    /**
     * Sets or clears the area of the selected suppliers.
     */
    public function assignArea(Request $request): RedirectResponse
    {
        return $this->bulkAssignArea($request, Supplier::class, 'tenant.suppliers.index', 'supplier');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:20', Rule::unique('suppliers')->ignore($supplier)],
            'email' => ['nullable', 'email', 'max:255'],
            'tpin' => ['nullable', 'string', 'max:50'],
            // PAN / non-VAT purchase mode default (item 2): a supplier who
            // is not VAT registered can only issue a PAN bill, so a purchase
            // against them opens with the toggle already checked.
            'is_vat_registered' => ['boolean'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
        ]);
    }
}
