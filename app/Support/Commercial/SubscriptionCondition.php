<?php

namespace App\Support\Commercial;

use App\Models\CommercialCondition;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionStatus;

/**
 * The EFFECTIVE commercial condition of a subscription, as the backoffice
 * should state it — which is not always the column.
 *
 * Three sources, in this order, and the order is the whole point:
 *
 *  1. `status = Trial` wins over anything stored. A trial is a fact the
 *     subscription itself already records, immutably (`ChangeOrganizationPlan`
 *     never relabels a Trial away), so it is derived here rather than written
 *     into `commercial_condition` — one fact, one place.
 *  2. Otherwise the stored `commercial_condition`, if an operator set one.
 *  3. Otherwise UNKNOWN — "Origem não registada". Never a default, never
 *     "standard", and never inferred from the plan, the price or an amount
 *     paid. An account on Pro with no recorded condition might be a Fundador,
 *     a grant or a conversion, and the database has never held the evidence to
 *     say which.
 *
 * This is presentation and reporting only. Nothing here decides access.
 */
final class SubscriptionCondition
{
    /** Derived from `status`, never stored in `commercial_condition`. */
    public const TRIAL = 'trial';

    /** No condition was ever recorded. Distinct from `CommercialCondition::Other`. */
    public const UNKNOWN = 'unknown';

    public static function keyOf(?OrganizationSubscription $subscription): string
    {
        if ($subscription === null) {
            return self::UNKNOWN;
        }

        if ($subscription->status === SubscriptionStatus::Trial) {
            return self::TRIAL;
        }

        $condition = $subscription->commercial_condition;

        // Written as an explicit check rather than `?->value ?? UNKNOWN`: on the
        // left of `??` the nullsafe operator is redundant (`??` already has
        // isset semantics for a property fetch), and a redundant `?->` reads as
        // if it were doing something. This says the actual rule — no recorded
        // condition means unknown — in the one form that cannot be misread.
        return $condition === null ? self::UNKNOWN : $condition->value;
    }

    public static function labelOf(?OrganizationSubscription $subscription): string
    {
        return self::labelFor(self::keyOf($subscription));
    }

    public static function labelFor(string $key): string
    {
        return match ($key) {
            self::TRIAL => __('Experiência (trial)'),
            self::UNKNOWN => __('Origem não registada'),
            default => CommercialCondition::tryFrom($key)?->label() ?? __('Origem não registada'),
        };
    }

    /**
     * Everything the filter may be set to — the stored conditions plus the two
     * that are derived rather than stored. `trial` and `unknown` are real
     * answers to "que condição comercial?", so leaving them out of the filter
     * would make the two most common states of this database unfilterable.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function filterOptions(): array
    {
        return [
            ['value' => self::TRIAL, 'label' => self::labelFor(self::TRIAL)],
            ...CommercialCondition::options(),
            ['value' => self::UNKNOWN, 'label' => self::labelFor(self::UNKNOWN)],
        ];
    }

    /**
     * The conditions an operator may actually assign. `trial` is absent
     * because it is not assignable — a trial is started by
     * `ChangeOrganizationPlan::startProTrial()`, and typing it here would
     * claim a subscription is a trial when its status says otherwise.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function assignableOptions(): array
    {
        return CommercialCondition::options();
    }
}
