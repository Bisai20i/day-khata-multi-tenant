<?php

use App\Support\AmountInWords;
use App\Support\Money\Money;

/**
 * The words on an invoice are a compliance artefact: a Nepali bill has to
 * carry the amount in words, and if the words and the figures ever disagree
 * the figures are the ones that lose an argument with the IRD. So every
 * boundary the Indian numbering system has - the teens, the round tens, the
 * hundred/thousand/lakh/crore rollovers, the paisa half - gets a case here.
 */
test('zero prints as Rupees Zero Only', function () {
    expect(AmountInWords::rupees(Money::zero()))->toBe('Rupees Zero Only')
        ->and(AmountInWords::rupees(Money::of('0.00')))->toBe('Rupees Zero Only');
});

test('a paisa-only amount still names the zero rupees', function () {
    expect(AmountInWords::rupees(Money::of('0.50')))->toBe('Rupees Zero and Fifty Paisa Only')
        ->and(AmountInWords::rupees(Money::of('0.01')))->toBe('Rupees Zero and One Paisa Only')
        ->and(AmountInWords::rupees(Money::of('0.99')))->toBe('Rupees Zero and Ninety Nine Paisa Only');
});

test('a single rupee is singular in figures but not in the word Rupees', function () {
    expect(AmountInWords::rupees(Money::of('1')))->toBe('Rupees One Only')
        ->and(AmountInWords::rupees(Money::of('1.50')))->toBe('Rupees One and Fifty Paisa Only');
});

test('a whole amount drops the paisa half entirely', function () {
    expect(AmountInWords::rupees(Money::of('100')))->toBe('Rupees One Hundred Only')
        ->and(AmountInWords::rupees(Money::of('1000')))->toBe('Rupees One Thousand Only')
        ->and(AmountInWords::rupees(Money::of('100000')))->toBe('Rupees One Lakh Only')
        ->and(AmountInWords::rupees(Money::of('10000000')))->toBe('Rupees One Crore Only');
});

test('a zero paisa component is never spelled out', function () {
    expect(AmountInWords::rupees(Money::of('123.00')))->toBe('Rupees One Hundred Twenty Three Only')
        ->and(AmountInWords::rupees(Money::of('123')))->toBe('Rupees One Hundred Twenty Three Only');
});

test('the contract example spells out exactly as written in C9', function () {
    expect(AmountInWords::rupees(Money::of('123456.50')))
        ->toBe('Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Fifty Paisa Only');
});

test('ten crore is grouped the Indian way, not the Western way', function () {
    expect(AmountInWords::rupees(Money::of('100000000.00')))->toBe('Rupees Ten Crore Only');
});

test('the teens and the round tens are spelled correctly', function (string $amount, string $words) {
    expect(AmountInWords::rupees(Money::of($amount)))->toBe($words);
})->with([
    ['10', 'Rupees Ten Only'],
    ['11', 'Rupees Eleven Only'],
    ['13', 'Rupees Thirteen Only'],
    ['15', 'Rupees Fifteen Only'],
    ['18', 'Rupees Eighteen Only'],
    ['19', 'Rupees Nineteen Only'],
    ['20', 'Rupees Twenty Only'],
    ['21', 'Rupees Twenty One Only'],
    ['40', 'Rupees Forty Only'],
    ['50', 'Rupees Fifty Only'],
    ['90', 'Rupees Ninety Only'],
    ['99', 'Rupees Ninety Nine Only'],
]);

test('every Indian group boundary rolls over correctly', function (string $amount, string $words) {
    expect(AmountInWords::rupees(Money::of($amount)))->toBe($words);
})->with([
    ['99.99', 'Rupees Ninety Nine and Ninety Nine Paisa Only'],
    ['101', 'Rupees One Hundred One Only'],
    ['110', 'Rupees One Hundred Ten Only'],
    ['999', 'Rupees Nine Hundred Ninety Nine Only'],
    ['1001', 'Rupees One Thousand One Only'],
    ['10500', 'Rupees Ten Thousand Five Hundred Only'],
    ['99999', 'Rupees Ninety Nine Thousand Nine Hundred Ninety Nine Only'],
    ['100001', 'Rupees One Lakh One Only'],
    ['999999', 'Rupees Nine Lakh Ninety Nine Thousand Nine Hundred Ninety Nine Only'],
    ['1000000', 'Rupees Ten Lakh Only'],
    ['9999999', 'Rupees Ninety Nine Lakh Ninety Nine Thousand Nine Hundred Ninety Nine Only'],
    ['10000001', 'Rupees One Crore One Only'],
    ['123456789', 'Rupees Twelve Crore Thirty Four Lakh Fifty Six Thousand Seven Hundred Eighty Nine Only'],
]);

test('above ninety nine crore the words keep counting in crores', function () {
    // 1,00,00,00,000 (one arab). Counting on in crores stays unambiguous for
    // any reader, which "One Arab" would not be.
    expect(AmountInWords::rupees(Money::of('1000000000')))->toBe('Rupees One Hundred Crore Only')
        ->and(AmountInWords::rupees(Money::of('100000000000')))->toBe('Rupees Ten Thousand Crore Only');
});

test('a negative amount is prefixed Minus', function () {
    expect(AmountInWords::rupees(Money::of('-1234.75')))
        ->toBe('Minus Rupees One Thousand Two Hundred Thirty Four and Seventy Five Paisa Only')
        ->and(AmountInWords::rupees(Money::of('-1')))->toBe('Minus Rupees One Only')
        ->and(AmountInWords::rupees(Money::of('-0.05')))->toBe('Minus Rupees Zero and Five Paisa Only');
});

test('negative zero never leaks a Minus prefix', function () {
    // Money::toString() never emits "-0.00", so neither can this.
    expect(AmountInWords::rupees(Money::of('-0.00')))->toBe('Rupees Zero Only');
});

test('the words always agree with the figures Money would print', function (string $amount) {
    $money = Money::of($amount);
    $paisa = substr($money->abs()->toString(), -2);

    if ($paisa === '00') {
        expect(AmountInWords::rupees($money))->not->toContain('Paisa');
    } else {
        expect(AmountInWords::rupees($money))->toContain('Paisa');
    }
})->with(['0.00', '0.10', '7.00', '7.07', '1234.56', '99999.90']);

test('an amount too wide for exact integer arithmetic is refused, not silently wrong', function () {
    AmountInWords::rupees(Money::of('1234567890123456'));
})->throws(InvalidArgumentException::class);
