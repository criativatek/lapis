<?php

namespace App\Support\Limits;

use LogicException;

/**
 * How much of a quantitative capacity an organization may use: a finite
 * non-negative count, or unlimited. Its own type rather than `?int` on
 * purpose — a bare `?int` cannot say whether `null` means "unlimited" or
 * "not configured", and a sentinel like `PHP_INT_MAX` or `-1` is a magic
 * number that every caller has to remember to treat specially. `finite(0)`,
 * `finite(8)` and `unlimited()` are three different, unambiguous values;
 * there is no fourth "unset" state here on purpose — `Limits` throws instead
 * of manufacturing one when a plan's configuration is missing a key (§Lote 3).
 *
 * A final class rather than an enum: `AccessState` (Entitlements' own
 * three-state type) is an enum because its three cases carry no data of
 * their own. `finite(N)` needs to carry N, which a native enum case cannot
 * do, so this is a small immutable value object instead — the closest
 * idiomatic match given that constraint.
 */
final class LimitValue
{
    private function __construct(
        private readonly bool $unlimited,
        private readonly ?int $value,
    ) {}

    public static function finite(int $value): self
    {
        if ($value < 0) {
            throw new LogicException("LimitValue::finite() cannot be negative, got {$value}.");
        }

        return new self(false, $value);
    }

    public static function unlimited(): self
    {
        return new self(true, null);
    }

    public function isUnlimited(): bool
    {
        return $this->unlimited;
    }

    /**
     * The underlying count. Throws when called on `unlimited()` — a caller
     * that reaches this without checking `isUnlimited()` first has a bug to
     * fix, not a number to fall back to (never `PHP_INT_MAX`; see the class
     * doc above).
     */
    public function value(): int
    {
        return $this->value ?? throw new LogicException(
            'LimitValue::value() was called on an unlimited value — check isUnlimited() first.',
        );
    }

    /**
     * Whether $used may grow by $by without exceeding this limit. Unlimited
     * always allows it; a finite value compares used+by against itself. Used
     * by `Limits::canIncreaseFor()`/`assertCanIncreaseFor()` so the "above
     * the limit already" arithmetic lives in exactly one place.
     */
    public function accommodates(int $used, int $by = 1): bool
    {
        return $this->unlimited || ($used + $by) <= $this->value;
    }

    /**
     * How much is left after $used has been consumed. Unlimited stays
     * unlimited — never computed as a gigantic finite number — and a finite
     * value never goes below zero: usage already past the limit is a valid,
     * permanent state (§Lote 3, "dados acima do limite"), not a negative
     * remainder.
     */
    public function remainingAfter(int $used): self
    {
        if ($this->unlimited) {
            return self::unlimited();
        }

        return self::finite(max(0, $this->value - $used));
    }
}
