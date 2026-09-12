<?php

namespace App\Support;

use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * Renders a rupee amount as English words in the Indian numbering system
 * (thousand, lakh, crore), which is what a Nepali invoice or credit note has
 * to carry alongside the figures (contract C9).
 *
 * "Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Fifty
 * Paisa Only"
 *
 * Pure PHP with no framework dependency beyond `Money`, so it can be smoke
 * checked and unit tested without booting Laravel. Every digit is taken from
 * `Money::toString()` (always exactly two decimals, never a float), so the
 * words can never disagree with the printed figure.
 *
 * Grouping above a crore keeps counting in crores rather than switching to
 * arab/kharab: 1,00,00,00,000 prints as "One Hundred Crore". That is the
 * reading Nepali businesses use in practice and it stays unambiguous at any
 * magnitude, which "One Arab" would not be for every reader.
 */
final class AmountInWords
{
    /**
     * Words for 0 to 19, where the teens are irregular and cannot be built
     * from a tens word plus a ones word. Index 0 is empty on purpose: it is
     * only ever reached as "no ones digit", and a bare zero is handled by
     * the caller.
     *
     * @var list<string>
     */
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    /**
     * Words for the multiples of ten from 20 up, keyed by the tens digit.
     *
     * @var array<int, string>
     */
    private const TENS = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
    ];

    /**
     * The Indian groups, largest first. Each entry is the group's value and
     * the word that follows it.
     *
     * @var list<array{0: int, 1: string}>
     */
    private const GROUPS = [
        [10000000, 'Crore'],
        [100000, 'Lakh'],
        [1000, 'Thousand'],
        [100, 'Hundred'],
    ];

    /**
     * Anything wider than this cannot come out of a DECIMAL(15,2) column, and
     * beyond it the integer arithmetic below would stop being exact.
     */
    private const MAX_RUPEE_DIGITS = 15;

    /**
     * The words for $amount, ready to print on a document.
     *
     * A whole amount drops the paisa half entirely ("Rupees One Thousand
     * Only"); zero prints "Rupees Zero Only"; a negative amount (a credit
     * note total, say) is prefixed "Minus".
     */
    public static function rupees(Money $amount): string
    {
        $isNegative = $amount->isNegative();

        // Money::toString() is always "digits.dd" - no grouping, no exponent,
        // and never "-0.00" - so splitting on the point is exact.
        [$rupeeDigits, $paisaDigits] = explode('.', $amount->abs()->toString());

        if (strlen($rupeeDigits) > self::MAX_RUPEE_DIGITS) {
            throw new InvalidArgumentException(
                'Amount '.$amount->toString().' is too large to spell out (more than '.self::MAX_RUPEE_DIGITS.' rupee digits).'
            );
        }

        $rupees = (int) $rupeeDigits;
        $paisa = (int) $paisaDigits;

        $words = 'Rupees '.($rupees === 0 ? 'Zero' : self::wholeNumberWords($rupees));

        if ($paisa > 0) {
            $words .= ' and '.self::wholeNumberWords($paisa).' Paisa';
        }

        $words .= ' Only';

        return $isNegative ? 'Minus '.$words : $words;
    }

    /**
     * Words for a positive whole number, Indian grouping.
     *
     * The crore group recurses through this same method, so 12,34,56,789
     * reads "Twelve Crore Thirty Four Lakh Fifty Six Thousand Seven Hundred
     * Eighty Nine" and a number larger than 99 crore keeps counting crores.
     */
    private static function wholeNumberWords(int $number): string
    {
        foreach (self::GROUPS as [$groupValue, $groupWord]) {
            if ($number < $groupValue) {
                continue;
            }

            $groupCount = intdiv($number, $groupValue);
            $remainder = $number % $groupValue;

            $words = self::wholeNumberWords($groupCount).' '.$groupWord;

            return $remainder === 0 ? $words : $words.' '.self::wholeNumberWords($remainder);
        }

        return self::belowHundredWords($number);
    }

    /**
     * Words for 1 to 99. Zero never reaches here: every caller either drops
     * an empty group or prints "Zero" itself.
     */
    private static function belowHundredWords(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        $tens = self::TENS[intdiv($number, 10)];
        $ones = $number % 10;

        return $ones === 0 ? $tens : $tens.' '.self::ONES[$ones];
    }
}
