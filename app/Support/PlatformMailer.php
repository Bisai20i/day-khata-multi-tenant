<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Config;

/**
 * Applies PlatformSetting::current()'s mail configuration to the runtime
 * mail.* config, then busts the cached Mail manager so the next send picks
 * it up. Called explicitly right before any central mail send (not a
 * global boot-time hook) so a long-running queue worker process never
 * sends with stale config from whichever settings were current when the
 * worker booted - this app has no tenant-side mail to guard against, so
 * there is nothing a boot-time hook would need to avoid affecting.
 */
class PlatformMailer
{
    public static function apply(): void
    {
        $settings = PlatformSetting::current();

        if ($settings->mail_mailer !== null) {
            Config::set('mail.default', $settings->mail_mailer);
        }

        Config::set('mail.mailers.smtp.host', $settings->mail_host ?? config('mail.mailers.smtp.host'));
        Config::set('mail.mailers.smtp.port', $settings->mail_port ?? config('mail.mailers.smtp.port'));
        Config::set('mail.mailers.smtp.username', $settings->mail_username ?? config('mail.mailers.smtp.username'));
        Config::set('mail.mailers.smtp.password', $settings->mail_password ?? config('mail.mailers.smtp.password'));
        Config::set('mail.mailers.smtp.encryption', $settings->mail_encryption ?? config('mail.mailers.smtp.encryption'));

        Config::set('mail.from.address', $settings->mail_from_address ?? config('mail.from.address'));
        Config::set('mail.from.name', $settings->mail_from_name ?? config('mail.from.name'));

        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
    }
}
