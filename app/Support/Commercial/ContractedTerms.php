<?php

namespace App\Support\Commercial;

use App\Models\BillingPeriod;
use App\Models\CommercialCondition;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * WHAT WAS AGREED, as one value, at the instant it was agreed.
 *
 * The four snapshot columns of `organization_subscriptions` are immutable once
 * written (ADR-0008 §8), and until now nothing produced them: every flow that
 * created a subscription left all four NULL, so «gratuito no ano letivo
 * 2026/27» and «subscrição anual» were promises the landing made and the
 * database could not evidence. This is the shape those columns are written
 * from — built once by `CommercialTerms`, handed to the writer, and never
 * consulted again.
 *
 * IMMUTABLE, AND NOT A MODEL. It carries no id, belongs to no table and cannot
 * be saved: it is the *terms*, not the subscription. That separation is what
 * keeps `ChangeOrganizationPlan` free to move `starts_at`, `ends_at` and
 * `status` on a row whose commercial proof it may not touch.
 *
 * A PRICE ALWAYS CARRIES ITS CURRENCY. The database says the same thing
 * (`organization_subscriptions_contracted_currency_check`), and saying it here
 * too means a caller finds out at the point of the mistake rather than at the
 * INSERT.
 */
final class ContractedTerms
{
    /**
     * `commercial_condition_note` IS NOT HERE, and its absence is the point.
     * That column is documented — in the migration that created it — as «the
     * operator's own words», the same role `organization_module_overrides.reason`
     * plays. A system that writes into it turns a human remark into boilerplate
     * and leaves nobody able to tell which is which. The condition, the price,
     * the periodicity and the term already say everything this class knows.
     *
     * @param  int|null  $priceCents  NULL = no price was agreed; 0 = agreed to cost nothing. Never interchangeable.
     * @param  CarbonInterface|null  $termEndsAt  Until when the CONDITION holds — never until when the ACCESS runs.
     */
    public function __construct(
        public readonly ?CommercialCondition $condition = null,
        public readonly ?int $priceCents = null,
        public readonly ?string $currency = null,
        public readonly ?BillingPeriod $billingPeriod = null,
        public readonly ?CarbonInterface $termEndsAt = null,
    ) {
        if ($priceCents !== null && $currency === null) {
            throw new InvalidArgumentException('A contracted price needs its currency: '.$priceCents.' of what?');
        }

        if ($priceCents !== null && $priceCents < 0) {
            throw new InvalidArgumentException('A contracted price cannot be negative.');
        }
    }

    /**
     * The attribute bag a subscription is created with.
     *
     * Every key is always present, including the NULL ones: a writer that merges
     * this into a `create()` array must be able to see that «no term was agreed»
     * is a recorded decision rather than a forgotten key.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'commercial_condition' => $this->condition,
            'contracted_price_cents' => $this->priceCents,
            'contracted_currency' => $this->currency,
            'billing_period' => $this->billingPeriod,
            'commercial_term_ends_at' => $this->termEndsAt,
        ];
    }
}
