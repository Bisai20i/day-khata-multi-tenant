<?php

namespace App\Support\Excel;

use Maatwebsite\Excel\DefaultValueBinder;
use Override;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * The value binder every export is written with.
 *
 * PhpSpreadsheet turns any string that starts with `=` into a live formula.
 * Exports carry free text a user typed (a sale note, a party or item name), so
 * a value such as `=HYPERLINK("http://example.test","click")` would become a
 * formula that runs when an accountant opens the file. Such a string is
 * stored as plain text instead. Numbers, dates and every other string bind
 * exactly as before, so numeric cells stay numeric.
 */
class SafeValueBinder extends DefaultValueBinder
{
    #[Override]
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && str_starts_with($value, '=')) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
