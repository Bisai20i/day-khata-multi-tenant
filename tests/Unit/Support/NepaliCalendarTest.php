<?php

use App\Support\NepaliCalendar;
use Carbon\Carbon;

/**
 * Reference AD<->BS pairs used across these tests. Cross-checked against the
 * legacy day_khata app's `Nepali_Calendar::eng_to_nep()`/`nep_to_eng()`
 * (../day_khata/app/Models/Nepali_Calendar.php) before this class was
 * written - see App\Support\NepaliCalendar's class doc comment.
 */
dataset('known_ad_bs_pairs', [
    'BS epoch (2000-01-01)' => ['1943-04-14', 2000, 1, 1],
    'Nepali new year 2081' => ['2024-04-13', 2081, 1, 1],
    'Nepali new year 2082' => ['2025-04-14', 2082, 1, 1],
    'mid-table date' => ['1990-01-15', 2046, 10, 2],
    'last day of table (BS 2090-12-30)' => ['2034-04-13', 2090, 12, 30],
]);

test('adToBs converts a known AD date to its BS equivalent', function (string $ad, int $bsYear, int $bsMonth, int $bsDay) {
    $bs = NepaliCalendar::adToBs($ad);

    expect($bs)->toBe(['year' => $bsYear, 'month' => $bsMonth, 'day' => $bsDay]);
})->with('known_ad_bs_pairs');

test('bsToAd converts a known BS date to its AD equivalent', function (string $ad, int $bsYear, int $bsMonth, int $bsDay) {
    $result = NepaliCalendar::bsToAd($bsYear, $bsMonth, $bsDay);

    expect($result)->toBeInstanceOf(Carbon::class)
        ->and($result->toDateString())->toBe($ad);
})->with('known_ad_bs_pairs');

test('adToBs accepts a Carbon instance as well as a string', function () {
    $bs = NepaliCalendar::adToBs(Carbon::create(2024, 4, 13));

    expect($bs)->toBe(['year' => 2081, 'month' => 1, 'day' => 1]);
});

test('adToBs and bsToAd round-trip across the supported BS range', function () {
    for ($year = 2000; $year <= 2089; $year += 7) {
        foreach ([1, 6, 12] as $month) {
            $ad = NepaliCalendar::bsToAd($year, $month, 1);
            $bs = NepaliCalendar::adToBs($ad);

            expect($bs)->toBe(['year' => $year, 'month' => $month, 'day' => 1]);
        }
    }
});

test('adToBs rejects a date before the supported range', function () {
    NepaliCalendar::adToBs('1943-04-13');
})->throws(InvalidArgumentException::class);

test('adToBs rejects a date after the supported range', function () {
    NepaliCalendar::adToBs('2034-04-14');
})->throws(InvalidArgumentException::class);

test('bsToAd rejects a BS year outside the supported range', function () {
    NepaliCalendar::bsToAd(1999, 1, 1);
})->throws(InvalidArgumentException::class);

test('bsToAd rejects a day beyond the month it belongs to', function () {
    // BS 2081 month 1 (Baishak) only has 31 days per the ported table.
    NepaliCalendar::bsToAd(2081, 1, 32);
})->throws(InvalidArgumentException::class);

test('bsToAd rejects a month outside 1-12', function () {
    NepaliCalendar::bsToAd(2081, 13, 1);
})->throws(InvalidArgumentException::class);

/**
 * `formatBs()` is what every PDF and print-log row shows (contract C9). It
 * wraps `adToBs()` without changing it: same conversion, zero-padded, and an
 * empty string instead of an exception for anything it cannot convert - a
 * printed document must never show a date that is not the real one, and one
 * bad row must not take a whole invoice PDF down. `formatBsDate()` in
 * `resources/js/lib/format.js` behaves identically.
 */
test('formatBs zero-pads a known AD date into its BS equivalent', function (string $ad, int $bsYear, int $bsMonth, int $bsDay) {
    $expected = sprintf('%04d-%02d-%02d', $bsYear, $bsMonth, $bsDay);

    expect(NepaliCalendar::formatBs($ad))->toBe($expected);
})->with('known_ad_bs_pairs');

test('formatBs pads single-digit months and days', function () {
    // BS 2081-01-01: both parts need a leading zero, which is exactly what
    // the JS twin does with padStart(2, '0').
    expect(NepaliCalendar::formatBs('2024-04-13'))->toBe('2081-01-01');
});

test('formatBs accepts a Carbon instance and ignores the time of day', function () {
    expect(NepaliCalendar::formatBs(Carbon::create(2024, 4, 13, 23, 59, 59)))->toBe('2081-01-01');
});

test('formatBs returns an empty string rather than a wrong or missing date', function (?string $adDate) {
    expect(NepaliCalendar::formatBs($adDate))->toBe('');
})->with([
    'null' => [null],
    'empty string' => [''],
    'blank string' => ['   '],
    'not a date' => ['not-a-date'],
    'before the supported range' => ['1943-04-13'],
    'after the supported range' => ['2034-04-14'],
]);

test('formatBs agrees with adToBs for every date adToBs can convert', function () {
    foreach (['1943-04-14', '1990-01-15', '2024-04-13', '2025-04-14', '2034-04-13'] as $ad) {
        $bs = NepaliCalendar::adToBs($ad);

        expect(NepaliCalendar::formatBs($ad))
            ->toBe(sprintf('%04d-%02d-%02d', $bs['year'], $bs['month'], $bs['day']));
    }
});

test('fiscalYear runs from Shrawan 1 to the last day of the following Ashad', function () {
    $fiscalYear = NepaliCalendar::fiscalYear(2082);

    expect($fiscalYear['name'])->toBe('2082/83')
        ->and($fiscalYear['start']->toDateString())->toBe('2025-07-17')
        ->and($fiscalYear['end']->toDateString())->toBe('2026-07-16')
        ->and(NepaliCalendar::adToBs($fiscalYear['start']))->toBe(['year' => 2082, 'month' => 4, 'day' => 1])
        ->and(NepaliCalendar::adToBs($fiscalYear['end'])['year'])->toBe(2083)
        ->and(NepaliCalendar::adToBs($fiscalYear['end'])['month'])->toBe(3)
        ->and(NepaliCalendar::adToBs($fiscalYear['end']->copy()->addDay()))->toBe(['year' => 2083, 'month' => 4, 'day' => 1]);
});

test('fiscalYear rejects a start year whose Ashad end falls outside the supported range', function (int $bsYear) {
    NepaliCalendar::fiscalYear($bsYear);
})->with([1999, 2090])->throws(InvalidArgumentException::class);

test('fiscalYearStartBsYear places Baishakh-Ashad in the previous BS year\'s fiscal year', function () {
    expect(NepaliCalendar::fiscalYearStartBsYear('2025-07-16'))->toBe(2081)
        ->and(NepaliCalendar::fiscalYearStartBsYear('2025-07-17'))->toBe(2082)
        ->and(NepaliCalendar::fiscalYearStartBsYear('2026-04-14'))->toBe(2082);
});
