<?php

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Pure, dependency-free Gregorian (AD) <-> Bikram Sambat (BS) date conversion.
 *
 * Ported from the legacy day_khata app's `App\Models\Nepali_Calendar` (see
 * `../day_khata/app/Models/Nepali_Calendar.php`). Legacy walked the calendar
 * one day at a time from a fixed epoch, incrementing a Nepali (or English)
 * date every iteration; this class keeps legacy's day-count table and epoch
 * anchor verbatim but sums whole years/months instead of iterating day by
 * day, which is mathematically equivalent (verified against legacy's own
 * algorithm across the full supported range before this class was written)
 * and avoids tens of thousands of loop iterations for a single conversion.
 *
 * Epoch anchor: BS 2000-01-01 == AD 1943-04-14 (the same anchor legacy's
 * `nep_to_eng()` uses).
 */
final class NepaliCalendar
{
    private const MIN_BS_YEAR = 2000;

    private const MAX_BS_YEAR = 2090;

    private const EPOCH_AD_YEAR = 1943;

    private const EPOCH_AD_MONTH = 4;

    private const EPOCH_AD_DAY = 14;

    /**
     * Shrawan - Nepal's fiscal year runs Shrawan 1 through the following
     * Ashad's last day.
     */
    public const FISCAL_YEAR_START_MONTH = 4;

    /**
     * Days in each of the 12 BS months, keyed by BS year. Copied verbatim
     * from legacy's `Nepali_Calendar::$bs` table (BS 2000 through 2090)
     * rather than recomputed, since a wrong day-count here would silently
     * produce wrong dates.
     *
     * @var array<int, array<int, int>>
     */
    private const BS_MONTH_DAYS = [
        2000 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2001 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2002 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2003 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2004 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2005 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2006 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2007 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2008 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
        2009 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2010 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2011 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2012 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2013 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2014 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2015 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2016 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2017 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2018 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2019 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2020 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2021 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2022 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2023 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2024 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2025 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2026 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2027 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2028 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2029 => [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
        2030 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2031 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2032 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2033 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2034 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2035 => [30, 32, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
        2036 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2037 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2038 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2039 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2040 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2041 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2042 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2043 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2044 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2045 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2046 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2047 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2048 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2049 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2050 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2051 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2052 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2053 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2054 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2055 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2056 => [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
        2057 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2058 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2059 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2060 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2061 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2062 => [30, 32, 31, 32, 31, 31, 29, 30, 29, 30, 29, 31],
        2063 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2064 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2065 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2066 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
        2067 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2068 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2069 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2070 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2071 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2072 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2073 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2074 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2075 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2076 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2077 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2078 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2079 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2080 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2081 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2082 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2083 => [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
        2084 => [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
        2085 => [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 30, 30],
        2086 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2087 => [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
        2088 => [30, 31, 32, 32, 30, 31, 30, 30, 29, 30, 30, 30],
        2089 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2090 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
    ];

    /**
     * Converts an AD (Gregorian) date to its BS (Bikram Sambat) equivalent.
     *
     * @return array{year: int, month: int, day: int}
     */
    public static function adToBs(Carbon|string $adDate): array
    {
        $date = ($adDate instanceof Carbon ? $adDate->copy() : Carbon::parse($adDate))->startOfDay();

        $daysSinceEpoch = (int) round(self::epoch()->diffInDays($date, false));

        if ($daysSinceEpoch < 0) {
            throw new InvalidArgumentException(
                "Date {$date->toDateString()} is before the supported Nepali calendar range (starts BS ".self::MIN_BS_YEAR.'-01-01).'
            );
        }

        $remaining = $daysSinceEpoch;
        $bsYear = self::MIN_BS_YEAR;

        while (true) {
            $yearDays = self::yearTotalDays($bsYear);

            if ($remaining < $yearDays) {
                break;
            }

            $remaining -= $yearDays;
            $bsYear++;

            if ($bsYear > self::MAX_BS_YEAR) {
                throw new InvalidArgumentException(
                    "Date {$date->toDateString()} is after the supported Nepali calendar range (ends BS ".self::MAX_BS_YEAR.').'
                );
            }
        }

        $bsMonth = 1;

        foreach (self::BS_MONTH_DAYS[$bsYear] as $daysInMonth) {
            if ($remaining < $daysInMonth) {
                break;
            }

            $remaining -= $daysInMonth;
            $bsMonth++;
        }

        return ['year' => $bsYear, 'month' => $bsMonth, 'day' => $remaining + 1];
    }

    /**
     * Formats a stored AD date as its BS equivalent, zero-padded `YYYY-MM-DD`
     * (contract C9) - what every PDF and print-log screen shows first, with
     * the AD date beside it.
     *
     * Returns an empty string for a blank, unparsable or out-of-range date
     * rather than throwing or printing a wrong one. Two reasons: a compliance
     * document must never show a date that is not the real one, and a single
     * bad row must not take a whole invoice PDF down with a 500. This mirrors
     * `formatBsDate()` in `resources/js/lib/format.js` exactly, so the browser
     * and the printed paper always agree.
     *
     * `adToBs()` itself is untouched: it still throws for anything outside
     * BS 2000-2090, which is what callers doing arithmetic need.
     */
    public static function formatBs(Carbon|string|null $adDate): string
    {
        if ($adDate === null || (is_string($adDate) && trim($adDate) === '')) {
            return '';
        }

        try {
            $bs = self::adToBs($adDate);
        } catch (\Throwable) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $bs['year'], $bs['month'], $bs['day']);
    }

    /**
     * Converts a BS (Bikram Sambat) date to its AD (Gregorian) equivalent.
     */
    public static function bsToAd(int $bsYear, int $bsMonth, int $bsDay): Carbon
    {
        if ($bsYear < self::MIN_BS_YEAR || $bsYear > self::MAX_BS_YEAR) {
            throw new InvalidArgumentException(
                'BS year must be between '.self::MIN_BS_YEAR.' and '.self::MAX_BS_YEAR.", got {$bsYear}."
            );
        }

        if ($bsMonth < 1 || $bsMonth > 12) {
            throw new InvalidArgumentException("BS month must be between 1 and 12, got {$bsMonth}.");
        }

        $monthDays = self::BS_MONTH_DAYS[$bsYear];
        $daysInMonth = $monthDays[$bsMonth - 1];

        if ($bsDay < 1 || $bsDay > $daysInMonth) {
            throw new InvalidArgumentException("BS {$bsYear}-{$bsMonth} only has {$daysInMonth} days, got day {$bsDay}.");
        }

        $daysSinceEpoch = 0;

        for ($year = self::MIN_BS_YEAR; $year < $bsYear; $year++) {
            $daysSinceEpoch += self::yearTotalDays($year);
        }

        for ($month = 1; $month < $bsMonth; $month++) {
            $daysSinceEpoch += $monthDays[$month - 1];
        }

        $daysSinceEpoch += $bsDay - 1;

        return self::epoch()->addDays($daysSinceEpoch);
    }

    /**
     * Nepal's fiscal year that starts in BS year $startBsYear: Shrawan 1
     * (BS month 4) of that year through the last day of Ashad (BS month 3)
     * of the next one. The end is computed as "the day before the next
     * Shrawan 1" so it always lands on Ashad's real last day, whatever that
     * year's day-count table says (29 to 32 days).
     *
     * @return array{start: Carbon, end: Carbon, name: string}
     */
    public static function fiscalYear(int $startBsYear): array
    {
        if ($startBsYear < self::MIN_BS_YEAR || $startBsYear >= self::MAX_BS_YEAR) {
            throw new InvalidArgumentException(
                'A fiscal year must start between BS '.self::MIN_BS_YEAR.' and '.(self::MAX_BS_YEAR - 1).", got {$startBsYear}."
            );
        }

        return [
            'start' => self::bsToAd($startBsYear, self::FISCAL_YEAR_START_MONTH, 1),
            'end' => self::bsToAd($startBsYear + 1, self::FISCAL_YEAR_START_MONTH, 1)->subDay(),
            'name' => sprintf('%d/%02d', $startBsYear, ($startBsYear + 1) % 100),
        ];
    }

    /**
     * The BS year in which the fiscal year containing $adDate started -
     * Baishakh-Ashad (BS months 1-3) still belong to the previous BS year's
     * fiscal year.
     */
    public static function fiscalYearStartBsYear(Carbon|string $adDate): int
    {
        $bs = self::adToBs($adDate);

        return $bs['month'] >= self::FISCAL_YEAR_START_MONTH ? $bs['year'] : $bs['year'] - 1;
    }

    private static function epoch(): Carbon
    {
        return Carbon::create(self::EPOCH_AD_YEAR, self::EPOCH_AD_MONTH, self::EPOCH_AD_DAY)->startOfDay();
    }

    private static function yearTotalDays(int $bsYear): int
    {
        if (! isset(self::BS_MONTH_DAYS[$bsYear])) {
            throw new InvalidArgumentException("No calendar data for BS year {$bsYear}.");
        }

        return array_sum(self::BS_MONTH_DAYS[$bsYear]);
    }
}
