<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SubscriptionStatus;
use App\Support\Commercial\CommercialTerms;
use App\Support\Commercial\ContractedTerms;
use Illuminate\Support\Carbon;

/**
 * Puts a brand-new organization on a plan, outright.
 *
 * Shared by every action that creates an organization — personal or
 * institutional — so the FIRST subscription of an organization that did not
 * exist a line ago is written exactly once, in exactly one place. Deliberately
 * a direct write and not ChangeOrganizationPlan: there is nothing in force yet
 * to close and nothing to serialize against. Every LATER change goes through
 * that service instead.
 *
 * AND SO IS THE COMMERCIAL SNAPSHOT WRITTEN EXACTLY ONCE, HERE. Every account
 * this product has ever created came through this method with all four
 * snapshot columns NULL, which is why «Gratuito no ano letivo 2026/27» — a
 * promise the landing has been making on every visit — existed nowhere in the
 * database. The terms are resolved by `CommercialTerms` rather than decided
 * here, so registration, an operator provisioning an account and a downgrade
 * back to Base cannot end up disagreeing about what the same Base adhesion
 * means.
 *
 * ponytail: no payment provider in the MVP (§8.2), so the plan is granted
 * outright with no end date. When billing arrives, this is where a trial
 * window and a real ends_at come from.
 */
class SubscribeOrganization
{
    public function __construct(protected CommercialTerms $terms) {}

    /**
     * A `Plan` means the version that plan sells today — the same rule
     * `ChangeOrganizationPlan::to()` applies, and the right one for an
     * organization being created right now. A `PlanVersion` is used as given,
     * for the caller that already knows which offer it is provisioning.
     *
     * @param  ContractedTerms|null  $terms  What was agreed, when the caller
     *                                       knows something this class cannot see. Left out, the terms of a
     *                                       plain adhesion made right now are resolved — which is NULL for
     *                                       everything except the free Base, because Pro is sold by a payment
     *                                       and Institucional is «sob consulta».
     */
    public function subscribe(Organization $organization, Plan|PlanVersion|null $plan, ?ContractedTerms $terms = null): void
    {
        if ($plan === null) {
            return;
        }

        $version = $plan instanceof PlanVersion ? $plan : $plan->currentVersionOrFail();
        $terms ??= $this->terms->forNewAdhesion($version);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $version->plan_id,
            'plan_version_id' => $version->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
            ...($terms?->toAttributes() ?? []),
        ]);
    }
}
