<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-wide company info + invoice footer note. A singleton - there
 * should only ever be exactly one row. Always resolve it via current(),
 * never CompanySetting::find()/query() directly, so a fresh tenant that has
 * never visited /settings still gets a usable default row on first access.
 */
#[Fillable([
    'company_name', 'address', 'phone', 'email', 'pan_vat_number', 'invoice_footer_note', 'print_paper_size',
    'default_vat_rate', 'allow_negative_stock', 'default_store_id',
    'sale_full_prefix', 'sale_full_enabled',
    'sale_abbreviated_prefix', 'sale_abbreviated_enabled',
    'sale_pan_prefix', 'sale_pan_enabled',
    'purchase_prefix', 'sale_return_prefix', 'purchase_return_prefix',
])]
class CompanySetting extends Model
{
    /**
     * default_vat_rate is a percentage held at 2 decimals, so it goes through
     * the Decimal cast like every other decimal column: a rate of 13.005 is
     * refused at the write rather than rounded by MySQL into a rate that no
     * longer matches what the settings screen shows.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_vat_rate' => Decimal::class.':2',
            // SQLite hands these back as 0 and 1, MySQL as a tinyint. Casting
            // here means a caller can trust the value without its own (bool).
            'allow_negative_stock' => 'boolean',
            'sale_full_enabled' => 'boolean',
            'sale_abbreviated_enabled' => 'boolean',
            'sale_pan_enabled' => 'boolean',
        ];
    }

    /**
     * Appended so the Settings edit page (which receives this model wholesale
     * as a single Inertia prop, not hand-shaped into an array by the
     * controller) can render the logo preview without a round trip.
     *
     * @var list<string>
     */
    protected $appends = ['logo_url'];

    public static function current(): self
    {
        return static::firstOrCreate([], ['company_name' => 'My Company']);
    }

    /**
     * Public URL for the uploaded logo, or null when none has been
     * uploaded yet. logo_path is stored disk-relative (see
     * SettingsController::uploadLogo()) rather than as a full URL, so the
     * URL is derived at read time instead of denormalized into a column
     * that could drift if the storage URL scheme ever changes.
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
        );
    }
}
