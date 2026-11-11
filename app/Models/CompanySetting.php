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
    'sale_full_prefix', 'sale_abbreviated_prefix', 'sale_pan_prefix', 'active_invoice_type',
    'purchase_prefix', 'sale_return_prefix', 'purchase_return_prefix',
    'logo_path',
    'round_off_enabled', 'round_off_precision',
    'branch_label',
])]
class CompanySetting extends Model
{
    /**
     * The steps Round Off can be suggested to. A fixed list rather than free
     * text: it feeds the settings dropdown, the server validation and the
     * suggestion in resources/js/lib/roundOff.js. 0.01 is left out on purpose -
     * every amount already has 2 decimals, so it could only suggest 0.00.
     *
     * @var list<string>
     */
    public const ROUND_OFF_PRECISIONS = ['10', '1', '0.5', '0.1', '0.05'];

    /**
     * The largest Round Off (either sign) a bill accepts. Round Off is a small
     * rounding adjustment; the cap keeps a typo from overflowing the money
     * columns (a 500 on MySQL) and is far above any real rounding step.
     */
    public const ROUND_OFF_LIMIT = '99999.99';

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
            'round_off_enabled' => 'boolean',
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

    /**
     * The tenant's one settings row, created on first use. A freshly created
     * model only carries the attributes it was given, not the column
     * defaults the database filled in (default_vat_rate 13.00, and so on),
     * so it is re-read once - otherwise the very first sale of a new tenant
     * saw a null VAT rate and was refused.
     *
     * New tenants start with the "S" full-invoice prefix (invoices S-1, S-2...).
     */
    public static function current(): self
    {
        $settings = static::firstOrCreate([], [
            'company_name' => 'My Company',
            'active_invoice_type' => 'full',
            'sale_full_prefix' => 'S',
        ]);

        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }

    /**
     * The Round Off a bill may actually carry: whatever the request asked for
     * while the tenant has the feature on, and always zero while it is off.
     * Enforced here, on the server, so a stale or hand-built request cannot
     * slip a Round Off past a tenant that has not enabled it.
     */
    public function effectiveRoundOff(mixed $requested): mixed
    {
        if (! $this->round_off_enabled || $requested === null || $requested === '') {
            return '0';
        }

        // Handed over untouched: DocumentCalculator parses strings, ints and
        // floats exactly (a float is never cast to a string here), and refuses
        // anything else with a clean billing error.
        return $requested;
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
