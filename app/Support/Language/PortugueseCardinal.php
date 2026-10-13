<?php

namespace App\Support\Language;

/**
 * Cardinal numbers written out in European Portuguese, feminine — «uma»,
 * «duas», «vinte e duas», «duzentas» — for sentences whose noun is feminine
 * («faltas», «situações»).
 *
 * Phrase::spelled() (Reporting) stops at ten and is masculine by default; the
 * Inovar messages need the exact count however large it gets, never a «três»
 * standing in for four. Up to 999 999; above that the digits are returned, which
 * is still exact.
 */
final class PortugueseCardinal
{
    private const array UNITS = [
        0 => 'zero', 1 => 'uma', 2 => 'duas', 3 => 'três', 4 => 'quatro', 5 => 'cinco',
        6 => 'seis', 7 => 'sete', 8 => 'oito', 9 => 'nove', 10 => 'dez', 11 => 'onze',
        12 => 'doze', 13 => 'treze', 14 => 'catorze', 15 => 'quinze', 16 => 'dezasseis',
        17 => 'dezassete', 18 => 'dezoito', 19 => 'dezanove',
    ];

    private const array TENS = [
        2 => 'vinte', 3 => 'trinta', 4 => 'quarenta', 5 => 'cinquenta',
        6 => 'sessenta', 7 => 'setenta', 8 => 'oitenta', 9 => 'noventa',
    ];

    private const array HUNDREDS = [
        1 => 'cento', 2 => 'duzentas', 3 => 'trezentas', 4 => 'quatrocentas', 5 => 'quinhentas',
        6 => 'seiscentas', 7 => 'setecentas', 8 => 'oitocentas', 9 => 'novecentas',
    ];

    public static function feminine(int $number): string
    {
        if ($number < 0 || $number > 999_999) {
            return (string) $number;
        }

        if ($number < 1000) {
            return self::belowThousand($number);
        }

        $thousands = intdiv($number, 1000);
        $rest = $number % 1000;
        // «mil», never «uma mil»; above one the multiplier agrees with the
        // noun — «duas mil faltas», «duzentas mil».
        $head = $thousands === 1 ? 'mil' : self::belowThousand($thousands).' mil';

        if ($rest === 0) {
            return $head;
        }

        // «mil e duzentas», «mil e vinte», but «mil duzentas e vinte».
        $joiner = ($rest < 100 || $rest % 100 === 0) ? ' e ' : ' ';

        return $head.$joiner.self::belowThousand($rest);
    }

    private static function belowThousand(int $number): string
    {
        if ($number < 20) {
            return self::UNITS[$number];
        }

        if ($number < 100) {
            $tens = self::TENS[intdiv($number, 10)];

            return $number % 10 === 0 ? $tens : $tens.' e '.self::UNITS[$number % 10];
        }

        if ($number === 100) {
            return 'cem';
        }

        $hundreds = self::HUNDREDS[intdiv($number, 100)];
        $rest = $number % 100;

        return $rest === 0 ? $hundreds : $hundreds.' e '.self::belowThousand($rest);
    }
}
