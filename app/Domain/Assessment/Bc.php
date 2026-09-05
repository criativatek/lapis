<?php

namespace App\Domain\Assessment;

/**
 * BCMath over decimal strings — never float (§24.4).
 *
 * All engine arithmetic goes through here at scale 10, so no intermediate float
 * ever touches a grade. The engine truncates to 6 decimals only when it hands a
 * value out (the canonical internal unit), and rounds exactly once, at the
 * proposal stage, with the profile version's rule.
 */
final class Bc
{
    public const SCALE = 10;

    /**
     * Validate a value as a decimal string at the engine boundary. Doubles as a
     * runtime guard on a grade path — a non-numeric value never reaches BCMath.
     *
     * @return numeric-string
     */
    public static function of(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("Valor não numérico no cálculo: {$value}");
        }

        return $value;
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    public static function div(string $a, string $b): string
    {
        return bcdiv($a, $b, self::SCALE);
    }

    /**
     * @param  numeric-string  $a
     */
    public static function isZero(string $a): bool
    {
        return bccomp($a, '0', self::SCALE) === 0;
    }

    /**
     * -1, 0 or 1 — like the spaceship operator, at full scale.
     *
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     */
    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    /**
     * Truncate (not round) to n decimals — the persistence step for the canonical
     * value. The one real rounding lives in round().
     *
     * @param  numeric-string  $a
     * @return numeric-string
     */
    public static function truncate(string $a, int $decimals): string
    {
        return bcadd($a, '0', $decimals);
    }

    /**
     * Round a decimal string to n places. Implemented on strings so no float is
     * involved even here.
     *
     * @param  numeric-string  $value
     * @param  'half_up'|'half_down'|'half_even'|'ceil'|'floor'|'none'  $mode
     * @return numeric-string
     */
    public static function round(string $value, int $decimals, string $mode): string
    {
        if ($mode === 'none') {
            return self::truncate($value, $decimals);
        }

        $negative = str_starts_with($value, '-');
        $magnitude = self::of(ltrim($value, '-') ?: '0');

        $factor = self::of('1'.str_repeat('0', $decimals));
        $scaled = bcmul($magnitude, $factor, self::SCALE);          // shift decimals up
        $floor = bcadd($scaled, '0', 0);                            // truncate toward zero
        $fraction = bcsub($scaled, $floor, self::SCALE);            // 0 <= fraction < 1

        // ceil/floor are direction-bound (toward +infinity / -infinity), not
        // magnitude-bound — on a negative value they swap: ceil moves toward
        // zero (round the magnitude down) and floor moves away from zero
        // (round the magnitude up). half_up/half_down/half_even stay
        // magnitude-symmetric, so they are untouched by sign.
        $effectiveMode = match (true) {
            $negative && $mode === 'ceil' => 'floor',
            $negative && $mode === 'floor' => 'ceil',
            default => $mode,
        };

        $roundUp = match ($effectiveMode) {
            'ceil' => ! self::isZero($fraction),
            'half_up' => bccomp($fraction, '0.5', self::SCALE) >= 0,
            'half_down' => bccomp($fraction, '0.5', self::SCALE) > 0,
            'half_even' => self::halfEvenRoundsUp($fraction, $floor),
            'floor' => false,
        };

        $rounded = $roundUp ? bcadd($floor, '1', 0) : $floor;
        $result = bcdiv($rounded, $factor, $decimals);              // shift back down

        return $negative && ! self::isZero($result) ? self::of('-'.$result) : $result;
    }

    /**
     * @param  numeric-string  $fraction
     * @param  numeric-string  $flooredScaled
     */
    protected static function halfEvenRoundsUp(string $fraction, string $flooredScaled): bool
    {
        $comparison = bccomp($fraction, '0.5', self::SCALE);

        if ($comparison > 0) {
            return true;
        }

        if ($comparison < 0) {
            return false;
        }

        // Exactly .5 — round to the even neighbour.
        return bcmod($flooredScaled, '2') !== '0';
    }
}
