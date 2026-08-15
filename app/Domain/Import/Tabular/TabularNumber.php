<?php

namespace App\Domain\Import\Tabular;

/**
 * Turning what a cell says into a number, or admitting it is not one.
 *
 * Marks arrive written the way people write them, and Portugal writes «12,5».
 * The decimal comma has to survive, and it has to survive without colliding with
 * the comma that separates CSV fields — which it does, because by the time a
 * value reaches here the delimiter has already been decided and the field has
 * already been split (§25).
 *
 * Everything returns a STRING. A mark that becomes a float has already lost the
 * argument: 0.1 + 0.2 is how a reconciliation that should be exact ends up a
 * hundredth out, and the project settled that question in §24.4 of the spec.
 */
final class TabularNumber
{
    /**
     * The value as a decimal string, or null when it is not unambiguously a
     * number. «F», «NR», «—» and free text all return null, and are shown to the
     * teacher as something to resolve rather than guessed at (§30).
     */
    public static function normalise(string $text): ?string
    {
        // Ordinary spaces, non-breaking spaces (Excel exports them) and the
        // apostrophe some locales use for thousands.
        $value = str_replace([' ', "\u{00A0}", "\u{202F}", "'"], '', trim($text));
        $value = rtrim($value, '%');

        if ($value === '') {
            return null;
        }

        $hasDot = str_contains($value, '.');
        $hasComma = str_contains($value, ',');

        if ($hasDot && $hasComma) {
            // Both present: whichever comes last is the decimal separator, and
            // the other one was grouping. «1.234,5» and «1,234.5» are the same
            // number written by two conventions.
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace('.', '', $value)
                : str_replace(',', '', $value);
        }

        // A lone comma is a decimal separator. Grouping without a decimal part —
        // «1,234» meaning one thousand — is not a way anyone writes a mark, and
        // reading it as 1.234 is the reading that cannot silently inflate a
        // result by three orders of magnitude.
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? self::tidy($value) : null;
    }

    /**
     * Whether the source itself wrote this as a percentage. A trailing `%` is the
     * file saying so; a bare `0.75` is not (§24).
     */
    public static function looksLikeAPercentage(string $text): bool
    {
        return str_ends_with(rtrim($text), '%');
    }

    /**
     * A workbook hands out binary floats, and binary floats do not hold decimal
     * marks exactly: a cell showing 37.66 arrives as 37.659999999999997, and
     * 0.756 × 100 comes back as 75.60000000000001. Both are the same artefact,
     * and both are settled here — at the boundary, once — by fixing the value at
     * ten decimal places and trimming what that leaves. Nothing downstream ever
     * sees a float again.
     */
    public static function fromFloat(float $value): string
    {
        return self::tidy(number_format($value, 10, '.', ''));
    }

    /**
     * A decimal string without exponent notation or a trailing run of zeros, so
     * that what is stored reads the way the teacher wrote it.
     */
    public static function tidy(string $value): string
    {
        if (stripos($value, 'e') !== false) {
            // Excel hands out 37.659999999999997 and, for very small or large
            // values, exponent form. Neither is a thing to store as a mark.
            $value = rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');

            return $value === '' || $value === '-' ? '0' : $value;
        }

        if (! str_contains($value, '.')) {
            return $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' || $value === '-' ? '0' : $value;
    }
}
