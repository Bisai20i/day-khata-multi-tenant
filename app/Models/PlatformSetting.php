<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Central-wide platform configuration (mail, branding, trial/grace-period
 * defaults). A singleton - there should only ever be exactly one row.
 * Always resolve it via current(), never PlatformSetting::find()/query()
 * directly, so a fresh install still gets a usable default row on first
 * access. Mirrors CompanySetting's tenant-side singleton pattern.
 */
#[Fillable([
    'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption',
    'mail_from_address', 'mail_from_name', 'platform_name', 'support_email',
    'default_trial_days', 'default_grace_period_days',
])]
class PlatformSetting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mail_password' => 'encrypted',
            'default_trial_days' => 'integer',
            'default_grace_period_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate([]);
    }
}
