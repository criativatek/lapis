<?php

namespace App\Services\Ai\Gateway;

use App\Models\AiUsageEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Limits\Limits;

/**
 * How many AI requests are left, and to whom.
 *
 * TWO WINDOWS, AND A REQUEST HAS TO PASS BOTH — the same shape the rate limiter
 * already uses for the writing assistant, one step further out in time. Per USER
 * per day, so one teacher cannot spend the school's month in an afternoon. Per
 * ORGANIZATION per month, so thirty teachers each within their own daily ceiling
 * still cannot. The second is what actually protects the bill; the first is what
 * catches the accident.
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
 *   2. otherwise the platform default in `config('lapis.ai.quotas')`, which the
 *      backoffice writes into `platform_settings.ai_quotas`
 *
 * NO PLAN NAMES THE KEY TODAY, AND THAT IS THE HONEST STATE OF AN UNANSWERED
 * QUESTION. How many AI requests a Base/Pro/Institucional subscription includes
 * is a commercial decision, on the ask-first list in CLAUDE.md §31, and nobody
 * has taken it. Writing a number into `EntitlementsSeeder` would be taking it
 * quietly. So step 1 is fully implemented and reads a key that is currently
 * absent everywhere — the day somebody decides, it is a seeder change and
 * nothing else. See docs/ai-core-contract.md §«Decisões pendentes».
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
    public function __construct(protected Limits $limits) {}

    /**
     * @throws AiQuotaExceeded when either ceiling has been reached.
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
    }

    /**
     * The ceiling in force for one window, or null when there is none.
     *
     * @param  'user_daily'|'organization_monthly'  $window
     */
    public function limit(Organization $organization, AiCapability $capability, string $window): ?int
    {
        $fromPlan = $this->planLimit($organization, $capability, $window);

        if ($fromPlan !== null) {
            return $fromPlan;
        }

        $configured = config('lapis.ai.quotas.'.$capability->value.'.'.$window);

        return is_int($configured) ? $configured : null;
    }

    /** How many billable calls this user has made today. */
    public function userUsageToday(AiCapability $capability, User $user): int
    {
        return AiUsageEvent::query()
            ->where('user_id', $user->getKey())
            ->where('capability', $capability->value)
            ->billable()
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /** How many billable calls this organization has made this month. */
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
     * What the plan says, or null when it says nothing.
     *
     * THE KEY FORMAT IS `ai_quota.<capability>.<window>` inside the plan's
     * existing `limits` JSON — nested rather than flattened into
     * `ai_quota_help_assistant_user_daily`, so a plan's AI quotas read as one
     * block and `LimitKey`'s flat catalogue is not disturbed by keys that do not
     * belong to it. `Limits::parse()` is deliberately NOT used: it throws on a
     * key a plan does not define, and «the plan does not define this» is the
     * normal, expected answer here rather than a configuration error.
     *
     * @param  'user_daily'|'organization_monthly'  $window
     */
    protected function planLimit(Organization $organization, AiCapability $capability, string $window): ?int
    {
        $plan = $this->limits->planFor($organization);

        if ($plan === null) {
            return null;
        }

        $value = data_get($plan->limits ?? [], "ai_quota.{$capability->value}.{$window}");

        // A non-integer is treated as «not configured» rather than as an error:
        // this is an optional override, and an override nobody has set must not
        // be able to break a request path.
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
