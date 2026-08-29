<?php

namespace App\Services\Ai\Gateway;

use App\Models\AiUsageEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Limits\Limits;

/**
 * How many AI requests are left, and to whom.
 *
 * FOUR CEILINGS, AND A REQUEST HAS TO PASS ALL OF THE ONES THAT APPLY:
 *
 *   per user per day, per capability            «one teacher cannot spend the
 *                                                school's month in an afternoon»
 *   per organization per month, per capability  «thirty teachers each within
 *                                                their own daily ceiling still
 *                                                cannot»
 *   the organization POOL, per month, across    «the school bought this much AI
 *   every capability                             in total»
 *   the individual ceiling INSIDE the pool,     «and no single teacher may take
 *   per month                                    more than this share of it»
 *
 * The first two have existed since the AI Core. The last two are the
 * institutional pool, and they apply ONLY to an organization that holds
 * `ai_institutional_pool` — see `poolApplies()`.
 *
 * NOT THE SAME THING AS RATE LIMITING, and both exist. The rate limiter answers
 * «is this a runaway loop right now» in seconds and forgets; this answers «has
 * this account had what it is entitled to» in days and months and remembers.
 * Neither substitutes for the other: a rate limit alone lets ten requests a
 * minute run all night, and a monthly quota alone lets the whole month burn in
 * ten minutes.
 *
 * WHERE THE NUMBERS COME FROM, in order:
 *
 *   1. the organization's PLAN, if its `limits` JSON names the key
 *   2. otherwise the platform default in `config('lapis.ai.quotas')` /
 *      `config('lapis.ai.pool')`, which the backoffice writes into
 *      `platform_settings.ai_quotas`
 *
 * NO NUMBER HERE IS A COMMERCIAL RULE. Every default in `config/lapis.php` is a
 * TECHNICAL COST CEILING with an environment variable in front of it, and every
 * one of them is overridden the moment a plan names the key. What a Base or Pro
 * subscription actually includes is a commercial decision that belongs in
 * `plans.limits`, where it can change without a deploy — see §3 of the
 * AI-complete brief and docs/ai-core-contract.md.
 *
 * NULL MEANS NO CEILING, NOT ZERO. `Limits` uses the string `"unlimited"` for
 * the same idea and this deliberately does not reuse that vocabulary, because
 * these values also come from environment variables where an empty string is the
 * natural way to say «do not cap this» — see the note in `config/lapis.php`.
 *
 * OVER-QUOTA IS A VALID, TEMPORARY STATE AND NOTHING REACTS TO IT. Like
 * `Limits`, this class never deletes, downgrades or notifies. It answers a
 * question and stops.
 */
class AiQuota
{
    /**
     * The plan-limits key under which an organization's pool is configured.
     *
     * NESTED UNDER ITS OWN KEY rather than flattened into
     * `ai_pool_organization_monthly`, for the same reason `ai_quota` is: a
     * plan's AI ceilings read as one block, and `LimitKey`'s flat catalogue is
     * not disturbed by keys that do not belong to it.
     */
    public const POOL_LIMIT_KEY = 'ai_pool';

    /** The two pool windows, in the order they are checked. */
    public const POOL_WINDOWS = ['organization_monthly', 'user_monthly'];

    /** The two per-capability windows, in the order they are checked. */
    public const CAPABILITY_WINDOWS = ['user_daily', 'organization_monthly'];

    public function __construct(
        protected Limits $limits,
        protected Entitlements $entitlements,
    ) {}

    /**
     * @throws AiQuotaExceeded when any ceiling in force has been reached.
     */
    public function assertWithin(AiCapability $capability, Organization $organization, User $user): void
    {
        $userDaily = $this->limit($organization, $capability, 'user_daily');

        if ($userDaily !== null && $this->userUsageToday($capability, $user) >= $userDaily) {
            throw AiQuotaExceeded::forUser($capability, $userDaily);
        }

        $organizationMonthly = $this->limit($organization, $capability, 'organization_monthly');

        if ($organizationMonthly !== null && $this->organizationUsageThisMonth($capability, $organization) >= $organizationMonthly) {
            throw AiQuotaExceeded::forOrganization($capability, $organizationMonthly);
        }

        $this->assertWithinPool($organization, $user);
    }

    /**
     * The organizational pool, checked last and only where it applies.
     *
     * LAST, BECAUSE IT IS THE WIDEST. A teacher who has exhausted their own
     * daily ceiling for one capability should be told that, not told that the
     * school has run out — the narrower, more actionable answer wins, and it is
     * also the cheaper query.
     *
     * ONLY FOR AN ORGANIZATION THAT HOLDS `ai_institutional_pool`. A Base or Pro
     * school has no pool, so nothing here can make its ceilings tighter than
     * they were before this class learned about pools. That is deliberate: the
     * pool is a governance instrument an Institucional contract buys, not a new
     * restriction applied to everybody.
     *
     * AND IT IS OFF UNTIL SOMEBODY SETS A NUMBER. Both defaults in
     * `config('lapis.ai.pool')` are null, so an Institucional organization is
     * unrestricted by the pool until a contract writes a figure into its plan's
     * `limits['ai_pool']`. «Prepared for» is not the same as «imposed», and §18
     * of the brief asks for the first.
     *
     * @throws AiQuotaExceeded
     */
    protected function assertWithinPool(Organization $organization, User $user): void
    {
        if (! $this->poolApplies($organization)) {
            return;
        }

        $organizationMonthly = $this->poolLimit($organization, 'organization_monthly');

        if ($organizationMonthly !== null && $this->organizationPoolUsageThisMonth($organization) >= $organizationMonthly) {
            throw AiQuotaExceeded::forPool($organizationMonthly);
        }

        $userMonthly = $this->poolLimit($organization, 'user_monthly');

        if ($userMonthly !== null && $this->userPoolUsageThisMonth($organization, $user) >= $userMonthly) {
            throw AiQuotaExceeded::forPoolShare($userMonthly);
        }
    }

    /** Whether this organization's consumption is governed by a pool at all. */
    public function poolApplies(Organization $organization): bool
    {
        return $this->entitlements->allowsFor($organization, AiCapability::InstitutionalPool->moduleKey());
    }

    /**
     * The ceiling in force for one per-capability window, or null when there is
     * none.
     *
     * @param  'user_daily'|'organization_monthly'  $window
     */
    public function limit(Organization $organization, AiCapability $capability, string $window): ?int
    {
        $fromPlan = $this->planLimit($organization, 'ai_quota.'.$capability->value.'.'.$window);

        if ($fromPlan !== null) {
            return $fromPlan;
        }

        $configured = config('lapis.ai.quotas.'.$capability->value.'.'.$window);

        return is_int($configured) ? $configured : null;
    }

    /**
     * The pool ceiling in force for one window, or null when there is none.
     *
     * @param  'organization_monthly'|'user_monthly'  $window
     */
    public function poolLimit(Organization $organization, string $window): ?int
    {
        $fromPlan = $this->planLimit($organization, self::POOL_LIMIT_KEY.'.'.$window);

        if ($fromPlan !== null) {
            return $fromPlan;
        }

        $configured = config('lapis.ai.pool.'.$window);

        return is_int($configured) ? $configured : null;
    }

    /** How many billable calls this user has made today, for one capability. */
    public function userUsageToday(AiCapability $capability, User $user): int
    {
        return AiUsageEvent::query()
            ->where('user_id', $user->getKey())
            ->where('capability', $capability->value)
            ->billable()
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /** How many billable calls this organization has made this month, for one capability. */
    public function organizationUsageThisMonth(AiCapability $capability, Organization $organization): int
    {
        return AiUsageEvent::query()
            ->forOrganization($organization->getKey())
            ->where('capability', $capability->value)
            ->billable()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Everything this organization has spent this month, across every
     * capability — what the pool actually measures.
     *
     * NOT FILTERED BY CAPABILITY, on purpose. A pool that counted each
     * capability separately would be a set of per-capability quotas with
     * another name; what an institution buys is a total.
     */
    public function organizationPoolUsageThisMonth(Organization $organization): int
    {
        return AiUsageEvent::query()
            ->forOrganization($organization->getKey())
            ->billable()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * One member's share of the pool this month.
     *
     * SCOPED TO THE ORGANIZATION, not just to the user. A teacher may belong to
     * a personal organization and to a school; what they spent in one is not
     * part of the other's pool.
     */
    public function userPoolUsageThisMonth(Organization $organization, User $user): int
    {
        return AiUsageEvent::query()
            ->forOrganization($organization->getKey())
            ->where('user_id', $user->getKey())
            ->billable()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * What the plan says at a dotted path inside its `limits` JSON, or null
     * when it says nothing.
     *
     * `Limits::parse()` is deliberately NOT used: it throws on a key a plan
     * does not define, and «the plan does not define this» is the normal,
     * expected answer here rather than a configuration error.
     */
    protected function planLimit(Organization $organization, string $path): ?int
    {
        $plan = $this->limits->planFor($organization);

        if ($plan === null) {
            return null;
        }

        $value = data_get($plan->limits ?? [], $path);

        // A non-integer is treated as «not configured» rather than as an error:
        // this is an optional override, and an override nobody has set must not
        // be able to break a request path.
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
