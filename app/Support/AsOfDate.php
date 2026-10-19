<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Resolves the `as_of` query parameter of the point-in-time reports.
 *
 * Anything that is not a real `Y-m-d` calendar date (empty, malformed,
 * `2026-02-31`, an array from `?as_of[]=`) falls back to today instead of
 * letting `Carbon::parse()` throw a 500.
 */
final class AsOfDate
{
    public static function resolve(mixed $value): string
    {
        return self::resolveCarbon($value)->toDateString();
    }

    public static function resolveCarbon(mixed $value): CarbonInterface
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value)) === 1) {
            $date = substr(trim($value), 0, 10);

            try {
                $parsed = Carbon::createFromFormat('!Y-m-d', $date);

                if ($parsed !== false && $parsed->format('Y-m-d') === $date) {
                    return $parsed;
                }
            } catch (Throwable) {
                // fall through to today
            }
        }

        return Carbon::today();
    }
}
