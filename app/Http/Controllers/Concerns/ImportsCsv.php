<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Shared CSV & Excel-parsing for bulk import endpoints (Customer, Supplier,
 * Item, Opening Stock, and Opening Balances).
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
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->parseExcelRows($file);
        }

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

    private function parseExcelRows(UploadedFile $file): ?array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($data)) {
            return null;
        }

        $rawHeader = array_shift($data);
        if (! is_array($rawHeader) || empty($rawHeader)) {
            return null;
        }

        $header = array_map(static fn ($col): string => strtolower(trim((string) $col)), $rawHeader);

        $rows = [];
        foreach ($data as $line) {
            $row = [];
            foreach ($header as $index => $key) {
                if ($key === '') {
                    continue;
                }
                $row[$key] = trim((string) ($line[$index] ?? ''));
            }

            if (implode('', $row) === '') {
                continue;
            }

            $rows[] = $row;
        }

        return $rows === [] ? null : $rows;
    }
}
