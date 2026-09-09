<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Shared CSV-parsing for the Customer/Supplier bulk import endpoints (see
 * Tenant\Parties\CustomerController::import and SupplierController::import).
 * Columns are matched by header name (case-insensitive, trimmed) rather than
 * position, so a re-ordered or partially-filled copy of the downloaded
 * template still imports correctly.
 */
trait ImportsCsv
{
    /**
     * @return array<int, array<string, string>>|null null when the file has
     *                                                no readable header row or no data rows at all.
     */
    private function parseCsvRows(UploadedFile $file): ?array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return null;
        }

        $header = fgetcsv($handle);

        if (! is_array($header) || $header === []) {
            fclose($handle);

            return null;
        }

        $header = array_map(static fn (?string $column): string => strtolower(trim((string) $column)), $header);

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            $row = [];

            foreach ($header as $index => $key) {
                $row[$key] = trim((string) ($line[$index] ?? ''));
            }

            if (implode('', $row) === '') {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows === [] ? null : $rows;
    }
}
