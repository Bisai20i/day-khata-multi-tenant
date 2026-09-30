<?php

namespace App\Support;

/**
 * Carries "the reversal was dated the open year's last day" from
 * JournalVoucher::reverse() to the page, without every cancel controller
 * having to know about it (flags G-06, decision D1). reverse() records it
 * here, and HandleInertiaRequests appends it to whatever success message the
 * controller flashed, so "Sale cancelled." reads "Sale cancelled. The
 * reversing entry is dated 2026-12-31, the last day of the open fiscal year."
 */
class ReversalNotice
{
    private const SESSION_KEY = 'reversal_notice';

    public static function dated(string $reversalDate): void
    {
        if (! app()->bound('session')) {
            return;
        }

        session()->flash(self::SESSION_KEY, "The reversing entry is dated {$reversalDate}, the last day of the open fiscal year.");
    }

    /**
     * The controller's own message with the notice appended, if there is one.
     */
    public static function appendTo(?string $status): ?string
    {
        $notice = app()->bound('session') ? session()->get(self::SESSION_KEY) : null;

        if ($notice === null) {
            return $status;
        }

        return $status === null ? $notice : "{$status} {$notice}";
    }
}
