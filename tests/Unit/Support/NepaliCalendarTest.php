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
