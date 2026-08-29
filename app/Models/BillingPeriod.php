<?php

namespace App\Models;

/**
 * How often the contracted condition is billed (ADR-0008 §8).
 *
 * THERE IS NO `monthly` CASE, and its absence is deliberate:
 * `resources/js/components/landing/commercial.ts` says Lapispro has no monthly
 * product, and adding the value here would be inventing the product in the one
 * place a future reader would take as authoritative.
 *
 * NULL in the column is a third, real state and is NOT this enum's `None`:
 * NULL means nobody recorded a periodicity — the honest answer for every row
 * created before the snapshot columns existed — while `None` means somebody
 * looked and there is no billing cycle at all, as with an operator's grant.
 * The same unknown-versus-known-to-be-nothing discipline `CommercialCondition`
 * already applies to NULL versus `Other`.
 */
enum BillingPeriod: string
{
    /** The one paid cycle that exists: a school year, paid once. */
    case Annual = 'annual';

    /** Nothing recurs — a grant, a promotional term, a free plan. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Annual => __('Anual'),
            self::None => __('Sem periodicidade'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $period): array => ['value' => $period->value, 'label' => $period->label()],
            self::cases(),
        );
    }
}
