<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-wide company info + invoice footer note. A singleton - there
 * should only ever be exactly one row. Always resolve it via current(),
 * never CompanySetting::find()/query() directly, so a fresh tenant that has
 * never visited /settings still gets a usable default row on first access.
 */
#[Fillable([
    'company_name', 'address', 'phone', 'email', 'pan_vat_number', 'invoice_footer_note',
])]
class CompanySetting extends Model
{
    public static function current(): self
    {
        return static::firstOrCreate([], ['company_name' => 'My Company']);
    }
}
