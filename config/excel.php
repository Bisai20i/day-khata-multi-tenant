<?php

use App\Support\Excel\SafeValueBinder;

/*
|--------------------------------------------------------------------------
| Excel (maatwebsite/excel) overrides
|--------------------------------------------------------------------------
|
| Only the keys this app changes; the package merges its own defaults for
| everything else.
|
| value_binder: a text value starting with `=` is stored as text, never as a
| formula, so free text in an export (notes, names) cannot run when the file
| is opened. See SafeValueBinder.
|
*/

return [
    'value_binder' => [
        'default' => SafeValueBinder::class,
    ],
];
